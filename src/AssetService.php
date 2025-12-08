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
     * 更新或新增帳戶餘額 (快照)
     * 修正重點 1: 解決 SQL 參數重複錯誤 (ledgerId1, ledgerId2)
     * 修正重點 2: 確保新帳戶即使是補登過去日期的資料，也會被建立
     */
    public function upsertAccountBalance(int $userId, string $name, float $balance, string $type, string $currencyUnit, ?string $snapshotDate = null, ?int $ledgerId = null, ?float $customRate = null): bool {
        error_log("🔍 Debug AssetService: Name={$name}, RateInput=" . var_export($customRate, true));
        $assetType = $this->sanitizeAssetType($type); 
        $date = $snapshotDate ?? date('Y-m-d');
        $today = date('Y-m-d');
        
        // 判斷是否為「今天或未來」的數據
        $isCurrentOrFuture = ($date >= $today); 
    
        $currentTime = date('Y-m-d H:i:s');
    
        try {
            $this->pdo->beginTransaction();
    
            // 1. 先檢查帳戶是否存在 (accounts 表維持原樣，只記錄最新餘額)
            $checkSql = "SELECT id FROM accounts WHERE user_id = :userId AND name = :name";
            $stmtCheck = $this->pdo->prepare($checkSql);
            $stmtCheck->execute([':userId' => $userId, ':name' => $name]);
            $existingId = $stmtCheck->fetchColumn();
    
            if (!$existingId) {
                // 新帳戶
                $insertSql = "INSERT INTO accounts (user_id, ledger_id, name, type, balance, currency_unit, last_updated_at)
                              VALUES (:userId, :ledgerId, :name, :type, :balance, :unit, :time)";
                $stmtInsert = $this->pdo->prepare($insertSql);
                $stmtInsert->execute([
                    ':userId' => $userId,
                    ':ledgerId' => $ledgerId,
                    ':name' => $name,
                    ':type' => $assetType,
                    ':balance' => $balance,
                    ':unit' => strtoupper($currencyUnit),
                    ':time' => $currentTime
                ]);
            } else {
                // 更新舊帳戶
                if ($isCurrentOrFuture) {
                    $updateSql = "UPDATE accounts SET 
                                  ledger_id = :ledgerId, 
                                  type = :type, 
                                  balance = :balance, 
                                  currency_unit = :unit, 
                                  last_updated_at = :time 
                                  WHERE id = :id";
                    $stmtUpdate = $this->pdo->prepare($updateSql);
                    $stmtUpdate->execute([
                        ':ledgerId' => $ledgerId,
                        ':type' => $assetType,
                        ':balance' => $balance,
                        ':unit' => strtoupper($currencyUnit),
                        ':time' => $currentTime,
                        ':id' => $existingId
                    ]);
                }
            }
    
            // 2. 寫入歷史紀錄 (這裡是關鍵修改處！)
            
            // 先刪除當天舊紀錄
            $sqlDelHistory = "DELETE FROM account_balance_history  
                              WHERE user_id = :userId 
                              AND account_name = :name 
                              AND snapshot_date = :date 
                              AND (ledger_id = :ledgerId1 OR (ledger_id IS NULL AND :ledgerId2 IS NULL))";
            
            $stmtDel = $this->pdo->prepare($sqlDelHistory);
            $stmtDel->execute([
                ':userId' => $userId, 
                ':name' => $name, 
                ':date' => $date, 
                ':ledgerId1' => $ledgerId,
                ':ledgerId2' => $ledgerId
            ]);
    
            // 🟢 [修正] 插入新紀錄時，寫入 exchange_rate
            $sqlHistory = "INSERT INTO account_balance_history (user_id, ledger_id, account_name, balance, currency_unit, exchange_rate, snapshot_date)
                           VALUES (:userId, :ledgerId, :name, :balance, :unit, :rate, :date)";
            $stmtHist = $this->pdo->prepare($sqlHistory);
            $stmtHist->execute([
                ':userId' => $userId,
                ':ledgerId' => $ledgerId,
                ':name' => $name,
                ':balance' => $balance,
                ':unit' => strtoupper($currencyUnit),
                ':rate' => $customRate, // 這裡將 API 傳來的匯率寫入資料庫
                ':date' => $date
            ]);
    
            $this->pdo->commit();
            return true;
    
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log("AssetService UPSERT failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 取得歷史淨值趨勢 (折線圖數據)
     */
    public function getAssetHistory(int $userId, string $range = '1y', ?int $ledgerId = null): array {
        $now = new DateTime();
        $today = $now->format('Y-m-d');
        $intervalStr = ($range === '1m') ? '-1 month' : (($range === '6m') ? '-6 months' : '-1 year');
        $startDate = (new DateTime())->modify($intervalStr)->format('Y-m-d');

        // 🟢 1. 修改 SQL：增加選取 exchange_rate
        $sql = "SELECT snapshot_date, account_name, balance, currency_unit, exchange_rate 
                FROM account_balance_history 
                WHERE user_id = :userId ";
        
        $params = [':userId' => $userId];
        if ($ledgerId) {
            $sql .= " AND ledger_id = :ledgerId ";
            $params[':ledgerId'] = $ledgerId;
        }
        
        $sql .= " ORDER BY snapshot_date ASC, id ASC";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($rows)) return ['labels' => [], 'data' => []];

            $rateService = new ExchangeRateService($this->pdo);
            $usdTwdRate = $rateService->getUsdTwdRate();
            
            // 整理資料：按日期分組
            $historyByDate = [];
            $firstDateInData = null;
            foreach ($rows as $row) {
                $d = $row['snapshot_date'];
                if (!$firstDateInData) $firstDateInData = $d;
                $historyByDate[$d][] = $row;
            }

            // 計算回放起點
            $replayStart = min($firstDateInData, $startDate);
            $period = new DatePeriod(
                new DateTime($replayStart), 
                new DateInterval('P1D'), 
                (new DateTime($today))->modify('+1 day')
            );

            $currentBalances = []; 
            $chartLabels = []; 
            $chartData = [];

            // 每日重播 (Replay) 計算淨值
            foreach ($period as $dt) {
                $currentDate = $dt->format('Y-m-d');
                $dayOfMonth = $dt->format('d');

                // 如果當天有新的快照紀錄，更新目前的餘額表
                if (isset($historyByDate[$currentDate])) {
                    foreach ($historyByDate[$currentDate] as $record) {
                        $acc = $record['account_name'];
                        
                        // 🟢 2. 暫存邏輯：將 exchange_rate 也存入狀態中
                        $currentBalances[$acc] = [
                            'balance' => (float)$record['balance'], 
                            'unit' => strtoupper($record['currency_unit']),
                            'custom_rate' => !empty($record['exchange_rate']) ? (float)$record['exchange_rate'] : null
                        ];
                    }
                }

                // 只有在範圍內的日期才產生圖表數據
                if ($currentDate >= $startDate) {
                    $shouldRecord = true;
                    if ($range !== '1m') {
                        $shouldRecord = ($dayOfMonth === '01' || $dayOfMonth === '15' || $currentDate === $today);
                    }

                    if ($shouldRecord) {
                        $dailyTotalTwd = 0.0;
                        foreach ($currentBalances as $accData) {
                            $bal = $accData['balance']; 
                            $curr = $accData['unit'];
                            $customRate = $accData['custom_rate'];

                            // 🟢 3. 計算邏輯：優先使用自訂匯率
                            if ($customRate && $customRate > 0) {
                                // 如果有自訂匯率，直接乘 (假設 custom_rate 是 "該幣別對台幣" 的匯率)
                                $dailyTotalTwd += $bal * $customRate;
                            } else {
                                // 如果沒有自訂匯率，才使用系統 API 匯率
                                if ($curr === 'TWD') {
                                    $dailyTotalTwd += $bal;
                                } else {
                                    try {
                                        $rateToUSD = $rateService->getRateToUSD($curr);
                                        $dailyTotalTwd += $bal * $rateToUSD * $usdTwdRate;
                                    } catch (Exception $e) {}
                                }
                            }
                        }
                        $chartLabels[] = $currentDate;
                        $chartData[] = round($dailyTotalTwd, 0);
                    }
                }
            }
            return ['labels' => $chartLabels, 'data' => $chartData];
        } catch (PDOException $e) { 
            return ['labels' => [], 'data' => []]; 
        }
    }
    
    /**
     * 取得目前資產配置摘要 (圓餅圖與總淨值用)
     */
    public function getNetWorthSummary(int $userId, ?int $ledgerId = null): array {
        $rateService = new ExchangeRateService($this->pdo);
        
        $sql = "SELECT type, currency_unit, SUM(balance) as total 
                FROM accounts 
                WHERE user_id = :userId ";
        
        $params = [':userId' => $userId];
        if ($ledgerId) {
            $sql .= " AND ledger_id = :ledgerId ";
            $params[':ledgerId'] = $ledgerId;
        }
        
        $sql .= " GROUP BY type, currency_unit ORDER BY currency_unit, type";
    
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $summary = []; 
            $globalNetWorthUSD = 0.0; 
            $usdTwdRate = $rateService->getUsdTwdRate();
            
            $totalCash = 0.0; 
            $totalInvest = 0.0; 
            $totalAssets = 0.0; 
            $totalLiabilities = 0.0;
            $totalStock = 0.0; 
            $totalBond = 0.0; 
            $totalTwInvest = 0.0; 
            $totalOverseasInvest = 0.0; 
            
            foreach ($results as $row) {
                $currency = $row['currency_unit']; 
                $type = $row['type']; 
                $total = (float)$row['total'];
                
                $rateToUSD = $rateService->getRateToUSD($currency);
                $usdValue = $total * $rateToUSD; 
                $twdValue = $usdValue * $usdTwdRate;

                if (!isset($summary[$currency])) {
                    $summary[$currency] = ['assets' => 0.0, 'liabilities' => 0.0, 'net_worth' => 0.0, 'usd_total' => 0.0, 'twd_total' => 0.0];
                }

                if ($type === 'Liability') {
                    $summary[$currency]['liabilities'] += $total; 
                    $summary[$currency]['net_worth'] -= $total;
                    $globalNetWorthUSD -= $usdValue; 
                    $totalLiabilities += $twdValue;
                } else {
                    $summary[$currency]['assets'] += $total; 
                    $summary[$currency]['net_worth'] += $total;
                    $globalNetWorthUSD += $usdValue; 
                    $totalAssets += $twdValue;
                    
                    if ($type === 'Cash') {
                        $totalCash += $twdValue;
                    } else {
                        $totalInvest += $twdValue;
                        if ($type === 'Stock' || $type === 'Investment') $totalStock += $twdValue; 
                        elseif ($type === 'Bond') $totalBond += $twdValue;
                        
                        if ($currency === 'TWD') $totalTwInvest += $twdValue; 
                        else $totalOverseasInvest += $twdValue;
                    }
                }
                $summary[$currency]['usd_total'] += $usdValue; 
                $summary[$currency]['twd_total'] += $twdValue;
            }
            $globalNetWorthTWD = $globalNetWorthUSD * $usdTwdRate;
            
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
            return ['breakdown' => [], 'global_twd_net_worth' => 0.0, 'usdTwdRate' => 32.0, 'charts' => []]; 
        }
    }

    /**
     * 取得帳戶列表
     */
    public function getAccounts(int $userId, ?int $ledgerId = null): array {
        $sql = "SELECT name, type, balance, currency_unit, last_updated_at 
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