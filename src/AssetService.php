<?php
// src/AssetService.php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ExchangeRateService.php';

class AssetService {
    private $pdo;
    // 擴充允許的類型
    private const VALID_TYPES = ['Cash', 'Investment', 'Liability', 'Stock', 'Bond'];

    public function __construct($pdo = null) {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    public function sanitizeAssetType(string $input): string {
        $input = trim($input);

        if (in_array($input, self::VALID_TYPES)) {
            return $input;
        }

        // 更新中文映射
        $map = [
            '現金' => 'Cash', '活存' => 'Cash', '銀行' => 'Cash',
            '投資' => 'Investment',
            '股票' => 'Stock', '證券' => 'Stock', '美股' => 'Stock', '台股' => 'Stock',
            '債券' => 'Bond', '債' => 'Bond',
            '負債' => 'Liability', '房貸' => 'Liability', '車貸' => 'Liability',
            '卡債' => 'Liability', '借款' => 'Liability',
        ];
        
        $standardized = $map[$input] ?? 'Cash';
        return in_array($standardized, self::VALID_TYPES) ? $standardized : 'Cash';
    }

    /**
     * 更新或新增帳戶餘額 (快照) - 支援股票與債券欄位
     * * @param string|null $symbol 股票代碼 (例如 AAPL, 2330.TW)
     * @param float|null $quantity 持股數量
     */
    /**
     * 更新或新增帳戶餘額 (快照) - 修正版：支援 Symbol, Quantity 與 LedgerID 防呆
     */
    public function upsertAccountBalance(
        int $userId, 
        string $name, 
        float $balance, 
        string $type, 
        string $currencyUnit, 
        ?string $snapshotDate = null, 
        ?int $ledgerId = null, 
        ?float $customRate = null,
        ?string $symbol = null,    // 🟢 修正1: 新增參數
        ?float $quantity = null    // 🟢 修正1: 新增參數
    ): bool {
        
        $assetType = $this->sanitizeAssetType($type); 
        $date = $snapshotDate ?? date('Y-m-d');
        $currentTime = date('Y-m-d H:i:s');
    
        // 判斷是否已經在交易中 (避免巢狀交易錯誤)
        $shouldStartTransaction = !$this->pdo->inTransaction();

        if ($shouldStartTransaction) {
            $this->pdo->beginTransaction();
        }
    
        try {
            // 1. 查詢該帳戶目前紀錄的最新日期
            $maxDateSql = "SELECT MAX(snapshot_date) FROM account_balance_history WHERE user_id = :userId AND account_name = :name";
            $stmtMax = $this->pdo->prepare($maxDateSql);
            $stmtMax->execute([':userId' => $userId, ':name' => $name]);
            $latestHistoryDate = $stmtMax->fetchColumn() ?: '0000-00-00';
            
            // 如果 傳入日期 >= 目前最新日期，代表這是最新的狀態，需要更新 accounts 表
            $shouldUpdateMainAccount = ($date >= $latestHistoryDate);
    
            // 2. 檢查帳戶是否存在 (🟢 修正2: 多撈 ledger_id 來做防呆)
            $checkSql = "SELECT id, ledger_id FROM accounts WHERE user_id = :userId AND name = :name";
            $stmtCheck = $this->pdo->prepare($checkSql);
            $stmtCheck->execute([':userId' => $userId, ':name' => $name]);
            $existingAccount = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            
            // 🟢 修正2: Ledger ID 防呆邏輯
            // 如果傳入的是 null，但資料庫原本就有值，就沿用原本的 ID
            $finalLedgerId = $ledgerId;
            if ($existingAccount && $ledgerId === null) {
                $finalLedgerId = $existingAccount['ledger_id'];
            }
    
            if (!$existingAccount) {
                // 若帳戶不存在，建立新帳戶 (🟢 修正3: 寫入 symbol 和 quantity)
                $insertSql = "INSERT INTO accounts (user_id, ledger_id, name, type, balance, currency_unit, symbol, quantity, last_updated_at)
                              VALUES (:userId, :ledgerId, :name, :type, :balance, :unit, :symbol, :quantity, :time)";
                $stmtInsert = $this->pdo->prepare($insertSql);
                $stmtInsert->execute([
                    ':userId' => $userId, ':ledgerId' => $finalLedgerId, ':name' => $name,
                    ':type' => $assetType, ':balance' => $balance, ':unit' => strtoupper($currencyUnit), 
                    ':symbol' => $symbol, ':quantity' => $quantity, // 綁定參數
                    ':time' => $currentTime
                ]);
            } else {
                // 若帳戶存在，且這次輸入的日期比較新 (或等於)，就更新主表
                if ($shouldUpdateMainAccount) {
                    // (🟢 修正3: 更新 symbol 和 quantity)
                    $updateSql = "UPDATE accounts SET ledger_id = :ledgerId, type = :type, balance = :balance, 
                                  currency_unit = :unit, symbol = :symbol, quantity = :quantity, last_updated_at = :time WHERE id = :id";
                    $stmtUpdate = $this->pdo->prepare($updateSql);
                    $stmtUpdate->execute([
                        ':ledgerId' => $finalLedgerId, ':type' => $assetType, ':balance' => $balance,
                        ':unit' => strtoupper($currencyUnit), 
                        ':symbol' => $symbol, ':quantity' => $quantity, // 綁定參數
                        ':time' => $currentTime, ':id' => $existingAccount['id']
                    ]);
                }
            }
    
            // 3. 寫入 account_balance_history (歷史快照)
            // (🟢 修正3: 歷史紀錄也寫入 symbol 和 quantity)
            $sqlHistory = "INSERT INTO account_balance_history 
                            (user_id, ledger_id, account_name, balance, currency_unit, exchange_rate, symbol, quantity, snapshot_date)
                           VALUES 
                            (:userId, :ledgerId, :name, :balance, :unit, :rate, :symbol, :quantity, :date)
                           ON DUPLICATE KEY UPDATE
                            balance = VALUES(balance),
                            currency_unit = VALUES(currency_unit),
                            exchange_rate = VALUES(exchange_rate),
                            symbol = VALUES(symbol),
                            quantity = VALUES(quantity),
                            ledger_id = VALUES(ledger_id)";
                            
            $stmtHist = $this->pdo->prepare($sqlHistory);
            $stmtHist->execute([
                ':userId' => $userId, 
                ':ledgerId' => $finalLedgerId, // 使用防呆後的 ID
                ':name' => $name,
                ':balance' => $balance, 
                ':unit' => strtoupper($currencyUnit), 
                ':rate' => $customRate, 
                ':symbol' => $symbol,     // 綁定參數
                ':quantity' => $quantity, // 綁定參數
                ':date' => $date
            ]);
    
            if ($shouldStartTransaction) {
                $this->pdo->commit();
            }
            return true;
    
        } catch (PDOException $e) {
            if ($shouldStartTransaction) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            }
            
            // 將錯誤拋出，以便 API 層級能捕捉或記錄
            if (!$shouldStartTransaction) {
                throw $e; 
            }
            error_log("AssetService UPSERT failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * [最終生產版] 取得歷史淨值趨勢
     * 修正重點：
     * 1. 以 accounts 主表為白名單 (White-list)。
     * 2. 無歷史紀錄的帳戶 -> 自動使用當前餘額 (Fallback)。
     * 3. 自訂匯率 (Custom Rate) -> 直接視為台幣價格，不重複乘匯率。
     */
    public function getAssetHistory(int $userId, string $range = '1y', ?int $ledgerId = null): array {
        // --- 1. 設定日期範圍 ---
        $now = new DateTime();
        $today = $now->format('Y-m-d');
        $intervalStr = ($range === '1m') ? '-1 month' : (($range === '6m') ? '-6 months' : '-1 year');
        $startDate = (new DateTime())->modify($intervalStr)->format('Y-m-d');

        // --- 2. 抓出所有「現存帳戶」 (Account White List) ---
        // 這是計算的基準，確保不會遺漏任何帳戶
        $accSql = "SELECT name, type, balance, currency_unit, symbol, quantity FROM accounts WHERE user_id = :userId";
        $paramsAcc = [':userId' => $userId];
        if ($ledgerId) {
            $accSql .= " AND ledger_id = :ledgerId ";
            $paramsAcc[':ledgerId'] = $ledgerId;
        }
        $stmtAcc = $this->pdo->prepare($accSql);
        $stmtAcc->execute($paramsAcc);
        $allAccounts = $stmtAcc->fetchAll(PDO::FETCH_ASSOC);

        // 建立帳戶名冊，並預設標記 has_history 為 false
        $accountMap = [];
        foreach ($allAccounts as $acc) {
            $accountMap[$acc['name']] = [
                'type' => $acc['type'],
                'current_balance' => (float)$acc['balance'],
                'current_unit' => strtoupper($acc['currency_unit']),
                'has_history' => false 
            ];
        }

        // --- 3. 撈取歷史紀錄 ---
        $sql = "SELECT snapshot_date, account_name, balance, currency_unit, exchange_rate 
                FROM account_balance_history 
                WHERE user_id = :userId ";
        $paramsHist = [':userId' => $userId];
        if ($ledgerId) {
            $sql .= " AND ledger_id = :ledgerId ";
            $paramsHist[':ledgerId'] = $ledgerId;
        }
        // 依照日期排序，確保同一天取到最後一筆
        $sql .= " ORDER BY snapshot_date ASC, id ASC";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($paramsHist);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 標記哪些帳戶是真的有歷史資料的
            foreach ($rows as $row) {
                if (isset($accountMap[$row['account_name']])) {
                    $accountMap[$row['account_name']]['has_history'] = true;
                }
            }

            $rateService = new ExchangeRateService($this->pdo);
            $usdTwdRate = $rateService->getUsdTwdRate();
            
            // 將歷史資料按日期分組
            $historyByDate = [];
            $firstDateInData = null;
            foreach ($rows as $row) {
                $d = $row['snapshot_date'];
                if (!$firstDateInData) $firstDateInData = $d;
                $historyByDate[$d][] = $row;
            }

            // 設定回放起始點 (若完全無歷史，就從範圍起始日開始)
            $replayStart = $firstDateInData ? min($firstDateInData, $startDate) : $startDate;
            
            $period = new DatePeriod(
                new DateTime($replayStart), 
                new DateInterval('P1D'), 
                (new DateTime($today))->modify('+1 day')
            );

            // 用來暫存回放過程中的餘額狀態
            $replayBalances = []; 
            
            $chartLabels = []; 
            $chartData = [];

            // --- 4. 開始每日重播 (Replay) ---
            foreach ($period as $dt) {
                $currentDate = $dt->format('Y-m-d');
                $dayOfMonth = $dt->format('d');

                // A. 更新當日歷史餘額
                // 只有「有歷史紀錄」的帳戶會在這裡被更新
                if (isset($historyByDate[$currentDate])) {
                    foreach ($historyByDate[$currentDate] as $record) {
                        $name = $record['account_name'];
                        // 只處理還活著的帳戶 (排除已刪除的幽靈帳戶)
                        if (isset($accountMap[$name])) {
                            $replayBalances[$name] = [
                                'balance' => (float)$record['balance'], 
                                'unit' => strtoupper($record['currency_unit']),
                                'custom_rate' => !empty($record['exchange_rate']) ? (float)$record['exchange_rate'] : null
                            ];
                        }
                    }
                }

                // B. 結算當日總資產
                if ($currentDate >= $startDate) {
                    $shouldRecord = true;
                    // 若範圍不是 1m，則只記錄特定日期以節省效能
                    if ($range !== '1m') {
                        $shouldRecord = ($dayOfMonth === '01' || $dayOfMonth === '15' || $currentDate === $today);
                    }

                    if ($shouldRecord) {
                        $dailyTotalTwd = 0.0;
                        
                        // 遍歷所有「現存帳戶」進行加總
                        foreach ($accountMap as $name => $info) {
                            $bal = 0; $unit = 'TWD'; $customRate = null;
                            
                            // 決策：使用歷史回放值？還是當前餘額補貼？
                            if ($info['has_history']) {
                                // 情況 1：有歷史紀錄 -> 使用 replayBalances
                                if (isset($replayBalances[$name])) {
                                    $bal = $replayBalances[$name]['balance'];
                                    $unit = $replayBalances[$name]['unit'];
                                    $customRate = $replayBalances[$name]['custom_rate'];
                                }
                                // 如果有歷史但還沒走到第一筆紀錄，預設 bal 為 0 (代表尚未開戶)
                            } else {
                                // 情況 2：完全無歷史 (如: 倚恩國泰) -> Fallback: 使用當前餘額
                                // 讓它在圖表上呈現一條平線，而不是 0
                                $bal = $info['current_balance'];
                                $unit = $info['current_unit'];
                            }

                            // 計算價值 (TWD)
                            $val = 0.0;
                            
                            // 🟢 修正重點：自訂匯率計算邏輯
                            if ($customRate && $customRate > 0) {
                                // 若有自訂匯率 (如 BTC 價格)，直接相乘，不需再乘匯率
                                $val = $bal * $customRate;
                            } else {
                                // 自動匯率模式
                                if ($unit === 'TWD') {
                                    $val = $bal;
                                } else {
                                    try {
                                        // 嘗試透過 API 取得對 USD 匯率，再轉回 TWD
                                        $rateToUSD = $rateService->getRateToUSD($unit);
                                        $val = $bal * $rateToUSD * $usdTwdRate;
                                    } catch (Exception $e) {
                                        // 若抓不到匯率，保守起見算 0 或保留原值 (這裡設為 0 避免虛增)
                                        $val = 0; 
                                    }
                                }
                            }

                            // 負債要扣除
                            if ($info['type'] === 'Liability') {
                                $dailyTotalTwd -= $val;
                            } else {
                                $dailyTotalTwd += $val;
                            }
                        }
                        
                        $chartLabels[] = $currentDate;
                        $chartData[] = round($dailyTotalTwd, 0);
                    }
                }
            }
            return ['labels' => $chartLabels, 'data' => $chartData];

        } catch (PDOException $e) { 
            error_log("getAssetHistory Error: " . $e->getMessage());
            return ['labels' => [], 'data' => []]; 
        }
    }
    
    /**
     * [最終生產版] 取得資產摘要 (卡片顯示用)
     * 邏輯與 getAssetHistory 保持一致，確保圖表與卡片金額吻合
     */
    public function getNetWorthSummary(int $userId, ?int $ledgerId = null): array {
        $rateService = new ExchangeRateService($this->pdo);
        $usdTwdRate = $rateService->getUsdTwdRate();
        
        // 抓取帳戶資料，並嘗試獲取該帳戶「最新」的歷史匯率 (Custom Rate)
        // 這樣可以確保若您曾經手動設定過價格 (如 BTC 價格)，卡片會採用該價格
        $sql = "SELECT a.name, a.balance, a.currency_unit, a.type, 
                       (SELECT exchange_rate 
                        FROM account_balance_history h 
                        WHERE h.user_id = a.user_id AND h.account_name = a.name 
                        ORDER BY h.snapshot_date DESC LIMIT 1) as custom_rate
                FROM accounts a 
                WHERE a.user_id = :userId ";
        
        $params = [':userId' => $userId];
        if ($ledgerId) {
            $sql .= " AND a.ledger_id = :ledgerId ";
            $params[':ledgerId'] = $ledgerId;
        }
        
        $sql .= " ORDER BY a.currency_unit, a.type";
    
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 初始化統計變數
            $summary = []; 
            $totalCash = 0.0; 
            $totalInvest = 0.0; 
            $totalAssets = 0.0; 
            $totalLiabilities = 0.0;
            $totalStock = 0.0; 
            $totalBond = 0.0; 
            $totalTwInvest = 0.0; 
            $totalOverseasInvest = 0.0; 
            
            // 用來計算總淨值 (全部換算成台幣累加)
            $globalNetWorthTWD = 0.0;

            foreach ($accounts as $row) {
                $currency = strtoupper($row['currency_unit']); 
                $type = $row['type']; 
                $balance = (float)$row['balance'];
                $customRate = !empty($row['custom_rate']) ? (float)$row['custom_rate'] : null;
                
                // --- 核心價值計算 (與圖表邏輯一致) ---
                $twdValue = 0.0;
                $usdValue = 0.0; // 僅供參考用

                if ($customRate && $customRate > 0) {
                    // 🟢 修正重點：自訂匯率視為 TWD 價格，直接相乘
                    $twdValue = $balance * $customRate;
                    
                    // 反推美金價值 (僅供 UI 顯示參考，不影響總值計算)
                    $usdValue = ($usdTwdRate > 0) ? ($twdValue / $usdTwdRate) : 0;
                } else {
                    // 自動匯率模式
                    if ($currency === 'TWD') {
                        $twdValue = $balance;
                        $usdValue = ($usdTwdRate > 0) ? ($balance / $usdTwdRate) : 0;
                    } else {
                        try {
                            $rateToUSD = $rateService->getRateToUSD($currency);
                            $usdValue = $balance * $rateToUSD; 
                            $twdValue = $usdValue * $usdTwdRate;
                        } catch (Exception $e) {
                            // 若抓不到匯率，忽略此資產價值 (避免虛增)
                            $twdValue = 0;
                            $usdValue = 0;
                        }
                    }
                }

                // --- 初始化幣別統計陣列 ---
                if (!isset($summary[$currency])) {
                    $summary[$currency] = [
                        'assets' => 0.0, 
                        'liabilities' => 0.0, 
                        'net_worth' => 0.0, 
                        'usd_total' => 0.0, 
                        'twd_total' => 0.0
                    ];
                }

                // --- 累加各項指標 ---
                if ($type === 'Liability') {
                    // 負債類
                    $summary[$currency]['liabilities'] += $balance; // 原幣餘額
                    $summary[$currency]['net_worth'] -= $balance;   // 淨值扣除
                    
                    $globalNetWorthTWD -= $twdValue;
                    $totalLiabilities += $twdValue;
                } else {
                    // 資產類 (Cash, Stock, Investment, Bond)
                    $summary[$currency]['assets'] += $balance; 
                    $summary[$currency]['net_worth'] += $balance;
                    
                    $globalNetWorthTWD += $twdValue;
                    $totalAssets += $twdValue;
                    
                    // 細項分類統計
                    if ($type === 'Cash') {
                        $totalCash += $twdValue;
                    } else {
                        $totalInvest += $twdValue;
                        
                        if ($type === 'Stock') $totalStock += $twdValue; 
                        elseif ($type === 'Bond') $totalBond += $twdValue;
                        elseif ($type === 'Investment') {
                            // 將 Investment (加密貨幣等) 暫歸類於廣義投資或股票區塊
                            // 您也可以新增 $totalCrypto 變數來獨立顯示
                            $totalStock += $twdValue; 
                        }
                        
                        // 地區/市場分類
                        if ($currency === 'TWD') {
                            $totalTwInvest += $twdValue; 
                        } else {
                            $totalOverseasInvest += $twdValue;
                        }
                    }
                }
                
                // 幣別匯總 (估值)
                $summary[$currency]['usd_total'] += $usdValue; 
                $summary[$currency]['twd_total'] += $twdValue;
            }
            
            return [
                'breakdown' => $summary, 
                'global_twd_net_worth' => $globalNetWorthTWD, 
                'usdTwdRate' => $usdTwdRate,
                'charts' => [
                    'cash' => $totalCash, 
                    'investment' => $totalInvest, 
                    'total_assets' => $totalAssets, 
                    'total_liabilities' => $totalLiabilities, 
                    'stock' => $totalStock, 
                    'bond' => $totalBond, 
                    'tw_invest' => $totalTwInvest, 
                    'overseas_invest' => $totalOverseasInvest
                ]
            ];
        } catch (PDOException $e) { 
            error_log("getNetWorthSummary Failed: " . $e->getMessage());
            // 發生錯誤時回傳空結構，避免前端報錯
            return [
                'breakdown' => [], 
                'global_twd_net_worth' => 0.0, 
                'usdTwdRate' => 32.0, 
                'charts' => []
            ]; 
        }
    }

    /**
     * 取得帳戶列表
     */
    public function getAccounts(int $userId, ?int $ledgerId = null): array {
        $sql = "SELECT name, type, symbol, balance, quantity, currency_unit, last_updated_at 
            FROM accounts 
            WHERE user_id = :userId ";
        $params = [':userId' => $userId];
        if ($ledgerId) {
            $sql .= " AND ledger_id = :ledgerId ";
            $params[':ledgerId'] = $ledgerId;
        }
        $sql .= " ORDER BY type ASC, balance DESC";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { return []; }
    }

    /**
     * 🟢 [關鍵修正] 刪除帳戶及其歷史紀錄
     * 說明：當刪除帳戶時，必須同步刪除 account_balance_history 中的資料。
     */
    public function deleteAccount(int $userId, string $name): bool {
        try {
            $this->pdo->beginTransaction();

            // 1. 刪除主帳戶 (accounts 表)
            $sqlMain = "DELETE FROM accounts WHERE user_id = :userId AND name = :name";
            $stmtMain = $this->pdo->prepare($sqlMain);
            $stmtMain->execute([':userId' => $userId, ':name' => $name]);

            // 2. 同步刪除該帳戶的所有歷史快照 (account_balance_history 表)
            $sqlHist = "DELETE FROM account_balance_history WHERE user_id = :userId AND account_name = :name";
            $stmtHist = $this->pdo->prepare($sqlHist);
            $stmtHist->execute([':userId' => $userId, ':name' => $name]);

            $this->pdo->commit();
            return true;
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log("Delete Account Failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 取得單一帳戶的歷史快照列表 (詳細頁面用)
     */
    public function getAccountSnapshots(int $userId, string $accountName, int $limit = 50): array {
        $sql = "SELECT account_name, balance, currency_unit, snapshot_date 
                FROM account_balance_history 
                WHERE user_id = :userId AND account_name = :name 
                ORDER BY snapshot_date DESC 
                LIMIT :limit";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':name', $accountName, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("getAccountSnapshots Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 刪除單筆歷史快照
     */
    public function deleteSnapshot(int $userId, string $accountName, string $snapshotDate): bool {
        $sql = "DELETE FROM account_balance_history 
                WHERE user_id = :userId AND account_name = :name AND snapshot_date = :date";
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([':userId' => $userId, ':name' => $accountName, ':date' => $snapshotDate]);
        } catch (PDOException $e) {
            error_log("deleteSnapshot failed: " . $e->getMessage());
            return false;
        }
    }
}
?>