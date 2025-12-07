<?php
// src/AssetService.php (最終修正版 - 歷史數據不覆蓋最新餘額)
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
     * 🌟 關鍵修正：只有當 $snapshotDate 是「今天或未來」時，才更新 accounts 主表
     *
     * @param int $userId
     * @param string $name
     * @param float $balance
     * @param string $type
     * @param string $currencyUnit
     * @param string|null $snapshotDate 歷史快照日期，如果為 null 則使用今日
     * @return bool
     */
    public function upsertAccountBalance(int $userId, string $name, float $balance, string $type, string $currencyUnit, ?string $snapshotDate = null, ?int $ledgerId = null): bool {
        $assetType = $this->sanitizeAssetType($type); 
        $date = $snapshotDate ?? date('Y-m-d');
        $today = date('Y-m-d');
        $shouldUpdateMainTable = ($date >= $today); 

        $currentTime = date('Y-m-d H:i:s');

        try {
            $this->pdo->beginTransaction();

            if ($shouldUpdateMainTable) {
                // [修正 2] 改用通用寫法：先檢查是否存在，再決定 Insert 或 Update
                // 這樣 SQLite (測試) 和 MySQL (正式) 都能運作
                
                $checkSql = "SELECT id FROM accounts WHERE user_id = :userId AND name = :name";
                $stmtCheck = $this->pdo->prepare($checkSql);
                $stmtCheck->execute([':userId' => $userId, ':name' => $name]);
                $existingId = $stmtCheck->fetchColumn();

                if ($existingId) {
                    // 如果存在 -> 更新 (Update)
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
                } else {
                    // 如果不存在 -> 新增 (Insert)
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
                }
            }

            // [修正 3] 歷史記錄部分保持不變，但記得參數要對應好
            $sqlDelHistory = "DELETE FROM account_balance_history  
                              WHERE user_id = :userId AND account_name = :name AND snapshot_date = :date AND (ledger_id = :ledgerId OR (ledger_id IS NULL AND :ledgerId IS NULL))";
            $stmtDel = $this->pdo->prepare($sqlDelHistory);
            $stmtDel->execute([':userId' => $userId, ':name' => $name, ':date' => $date, ':ledgerId' => $ledgerId]);

            $sqlHistory = "INSERT INTO account_balance_history (user_id, ledger_id, account_name, balance, currency_unit, snapshot_date)
                           VALUES (:userId, :ledgerId, :name, :balance, :unit, :date)";
            $stmtHist = $this->pdo->prepare($sqlHistory);
            $stmtHist->execute([
                ':userId' => $userId,
                ':ledgerId' => $ledgerId,
                ':name' => $name,
                ':balance' => $balance,
                ':unit' => strtoupper($currencyUnit),
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
     * 🟢 優化版：取得歷史淨值趨勢 (每日重播計算，但長週期只取每月 1/15 號顯示)
     */
    public function getAssetHistory(int $userId, string $range = '1y', ?int $ledgerId = null): array {
        // ... (日期計算保持不變) ...
        $now = new DateTime();
        $today = $now->format('Y-m-d');
        $intervalStr = ($range === '1m') ? '-1 month' : (($range === '6m') ? '-6 months' : '-1 year');
        $startDate = (new DateTime())->modify($intervalStr)->format('Y-m-d');

        // [修正] SQL 增加 ledger_id 判斷
        $sql = "SELECT snapshot_date, account_name, balance, currency_unit 
                FROM account_balance_history 
                WHERE user_id = :userId ";
        
        $params = [':userId' => $userId];
        if ($ledgerId) {
            $sql .= " AND ledger_id = :ledgerId ";
            $params[':ledgerId'] = $ledgerId;
        } else {
            // 如果沒指定 ledgerId，預設只撈 user_id 且 ledger_id 為 NULL (個人) 或者是全部？
            // 這裡建議：如果不指定，撈該用戶「所有」資產 (包含所有帳本)
            // 保持原樣即可 (WHERE user_id = :userId)
        }
        
        $sql .= " ORDER BY snapshot_date ASC, id ASC";

        // ... (後續處理邏輯保持不變) ...
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // ... (原本的資料處理邏輯，直接複製過來即可) ...
            if (empty($rows)) return ['labels' => [], 'data' => []];
            $rateService = new ExchangeRateService();
            $usdTwdRate = $rateService->getUsdTwdRate();
            $historyByDate = [];
            $firstDateInData = null;
            foreach ($rows as $row) {
                $d = $row['snapshot_date'];
                if (!$firstDateInData) $firstDateInData = $d;
                $historyByDate[$d][] = $row;
            }
            $replayStart = min($firstDateInData, $startDate);
            $period = new DatePeriod(new DateTime($replayStart), new DateInterval('P1D'), (new DateTime($today))->modify('+1 day'));
            $currentBalances = []; $chartLabels = []; $chartData = [];

            foreach ($period as $dt) {
                $currentDate = $dt->format('Y-m-d');
                $dayOfMonth = $dt->format('d');
                if (isset($historyByDate[$currentDate])) {
                    foreach ($historyByDate[$currentDate] as $record) {
                        $acc = $record['account_name'];
                        $currentBalances[$acc] = ['balance' => (float)$record['balance'], 'unit' => strtoupper($record['currency_unit'])];
                    }
                }
                if ($currentDate >= $startDate) {
                    $shouldRecord = true;
                    if ($range !== '1m') $shouldRecord = ($dayOfMonth === '01' || $dayOfMonth === '15' || $currentDate === $today);
                    if ($shouldRecord) {
                        $dailyTotalTwd = 0.0;
                        foreach ($currentBalances as $accData) {
                            $bal = $accData['balance']; $curr = $accData['unit'];
                            try {
                                $rateToUSD = $rateService->getRateToUSD($curr);
                                $dailyTotalTwd += $bal * $rateToUSD * $usdTwdRate;
                            } catch (Exception $e) {}
                        }
                        $chartLabels[] = $currentDate;
                        $chartData[] = round($dailyTotalTwd, 0);
                    }
                }
            }
            return ['labels' => $chartLabels, 'data' => $chartData];
        } catch (PDOException $e) { return ['labels' => [], 'data' => []]; }
    }
    
    public function getNetWorthSummary(int $userId, ?int $ledgerId = null): array {
        $rateService = new ExchangeRateService(); 
        
        // [修正] SQL 加入 ledger_id 判斷
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
            
            // ... (原本的計算邏輯完全保持不變) ...
            $summary = []; $globalNetWorthUSD = 0.0; $usdTwdRate = $rateService->getUsdTwdRate();
            $totalCash = 0.0; $totalInvest = 0.0; $totalAssets = 0.0; $totalLiabilities = 0.0;
            $totalStock = 0.0; $totalBond = 0.0; $totalTwInvest = 0.0; $totalOverseasInvest = 0.0; 
            
            foreach ($results as $row) {
                // ... (複製原本邏輯) ...
                $currency = $row['currency_unit']; $type = $row['type']; $total = (float)$row['total'];
                $rateToUSD = $rateService->getRateToUSD($currency);
                $usdValue = $total * $rateToUSD; $twdValue = $usdValue * $usdTwdRate;
                if (!isset($summary[$currency])) $summary[$currency] = ['assets' => 0.0, 'liabilities' => 0.0, 'net_worth' => 0.0, 'usd_total' => 0.0, 'twd_total' => 0.0];
                if ($type === 'Liability') {
                    $summary[$currency]['liabilities'] += $total; $summary[$currency]['net_worth'] -= $total;
                    $globalNetWorthUSD -= $usdValue; $totalLiabilities += $twdValue;
                } else {
                    $summary[$currency]['assets'] += $total; $summary[$currency]['net_worth'] += $total;
                    $globalNetWorthUSD += $usdValue; $totalAssets += $twdValue;
                    if ($type === 'Cash') $totalCash += $twdValue;
                    else {
                        $totalInvest += $twdValue;
                        if ($type === 'Stock' || $type === 'Investment') $totalStock += $twdValue; elseif ($type === 'Bond') $totalBond += $twdValue;
                        if ($currency === 'TWD') $totalTwInvest += $twdValue; else $totalOverseasInvest += $twdValue;
                    }
                }
                $summary[$currency]['usd_total'] += $usdValue; $summary[$currency]['twd_total'] += $twdValue;
            }
            $globalNetWorthTWD = $globalNetWorthUSD * $usdTwdRate;
            return [
                'breakdown' => $summary, 'global_twd_net_worth' => $globalNetWorthTWD, 'usdTwdRate' => $usdTwdRate,
                'charts' => ['cash' => $totalCash, 'investment' => $totalInvest, 'total_assets' => $totalAssets, 'total_liabilities' => $totalLiabilities, 'stock' => $totalStock, 'bond' => $totalBond, 'tw_invest' => $totalTwInvest, 'overseas_invest' => $totalOverseasInvest]
            ];
        } catch (PDOException $e) { return ['breakdown' => [], 'global_twd_net_worth' => 0.0, 'usdTwdRate' => 32.0, 'charts' => []]; }
    }


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

    public function deleteAccount(int $userId, string $name): bool {
        $sql = "DELETE FROM accounts WHERE user_id = :userId AND name = :name";
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([':userId' => $userId, ':name' => $name]);
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * 🟢 新增：取得單一帳戶的歷史快照列表
     */
    public function getAccountSnapshots(int $userId, string $accountName, int $limit = 50): array {
        // 限制只撈取最新的 50 筆記錄，按日期遞減排序
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
     * 🟢 新增：刪除單筆歷史快照
     */
    public function deleteSnapshot(int $userId, string $accountName, string $snapshotDate): bool {
        // 使用複合鍵 (user_id, account_name, snapshot_date) 來唯一識別並刪除快照
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