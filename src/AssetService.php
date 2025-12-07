<?php
// src/AssetService.php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ExchangeRateService.php';

class AssetService {
    private $pdo;
    private const VALID_TYPES = ['Cash', 'Investment', 'Liability', 'Stock', 'Bond'];

    public function __construct($pdo = null) {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    public function sanitizeAssetType(string $input): string {
        $input = trim($input);
        if (in_array($input, self::VALID_TYPES)) {
            return $input;
        }
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
     * 寫入資產快照 (支援自訂匯率凍結價值)
     */
    public function upsertAccountBalance(int $userId, string $name, float $balance, string $type, string $currencyUnit, ?string $snapshotDate = null, ?int $ledgerId = null, ?float $customRate = null): bool {
        // 決定匯率來源
        if ($customRate && $customRate > 0) {
            $currentRate = $customRate;
        } else {
            // 加上 try-catch 或檢查，防止 ExchangeRateService 初始化失敗導致崩潰
            try {
                $rateService = new ExchangeRateService($this->pdo);
                $currentRate = $rateService->getRateToUSD($currencyUnit);
            } catch (Exception $e) {
                error_log("匯率服務錯誤: " . $e->getMessage());
                $currentRate = 1.0; // 發生錯誤時的預設值，避免程式完全死掉
            }
        }
        
        $assetType = $this->sanitizeAssetType($type); 
        $date = $snapshotDate ?? date('Y-m-d');
        $today = date('Y-m-d');
        $shouldUpdateMainTable = ($date >= $today); 

        $currentTime = date('Y-m-d H:i:s');
        $currencyUnit = strtoupper($currencyUnit);

        // 決定匯率來源：優先使用前端傳入的自訂匯率，否則查 API
        if ($customRate && $customRate > 0) {
            $currentRate = $customRate;
        } else {
            $rateService = new ExchangeRateService($this->pdo);
            $currentRate = $rateService->getRateToUSD($currencyUnit);
        }

        try {
            $this->pdo->beginTransaction();

            if ($shouldUpdateMainTable) {
                $checkSql = "SELECT id FROM accounts WHERE user_id = :userId AND name = :name";
                $stmtCheck = $this->pdo->prepare($checkSql);
                $stmtCheck->execute([':userId' => $userId, ':name' => $name]);
                $existingId = $stmtCheck->fetchColumn();

                if ($existingId) {
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
                        ':unit' => $currencyUnit,
                        ':time' => $currentTime,
                        ':id' => $existingId
                    ]);
                } else {
                    $insertSql = "INSERT INTO accounts (user_id, ledger_id, name, type, balance, currency_unit, last_updated_at)
                                  VALUES (:userId, :ledgerId, :name, :type, :balance, :unit, :time)";
                    $stmtInsert = $this->pdo->prepare($insertSql);
                    $stmtInsert->execute([
                        ':userId' => $userId,
                        ':ledgerId' => $ledgerId,
                        ':name' => $name,
                        ':type' => $assetType,
                        ':balance' => $balance,
                        ':unit' => $currencyUnit,
                        ':time' => $currentTime
                    ]);
                }
            }

            // 刪除舊快照 (避免重複)
            $sqlDelHistory = "DELETE FROM account_balance_history  
                              WHERE user_id = :userId 
                              AND account_name = :name 
                              AND snapshot_date = :date 
                              AND (ledger_id = :ledgerId OR (ledger_id IS NULL AND :ledgerId_check IS NULL))";
            
            $stmtDel = $this->pdo->prepare($sqlDelHistory);
            
            $stmtDel->execute([
                ':userId'   => $userId, 
                ':name'     => $name, 
                ':date'     => $date, 
                ':ledgerId' => $ledgerId,
                ':ledgerId_check' => $ledgerId // 這裡要多補上這一個，值跟 ledgerId 一樣
            ]);

            // 寫入新快照 (包含匯率)
            $sqlHistory = "INSERT INTO account_balance_history (user_id, ledger_id, account_name, balance, currency_unit, snapshot_date, exchange_rate)
                           VALUES (:userId, :ledgerId, :name, :balance, :unit, :date, :rate)";
            $stmtHist = $this->pdo->prepare($sqlHistory);
            $stmtHist->execute([
                ':userId' => $userId,
                ':ledgerId' => $ledgerId,
                ':name' => $name,
                ':balance' => $balance,
                ':unit' => $currencyUnit,
                ':date' => $date,
                ':rate' => $currentRate
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
     * 取得歷史淨值趨勢 (優先使用歷史記錄的匯率)
     */
    public function getAssetHistory(int $userId, string $range = '1y', ?int $ledgerId = null): array {
        $now = new DateTime();
        $today = $now->format('Y-m-d');
        $intervalStr = ($range === '1m') ? '-1 month' : (($range === '6m') ? '-6 months' : '-1 year');
        $startDate = (new DateTime())->modify($intervalStr)->format('Y-m-d');

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
            
            $historyByDate = [];
            $firstDateInData = null;
            
            foreach ($rows as $row) {
                $d = $row['snapshot_date'];
                if (!$firstDateInData) $firstDateInData = $d;
                $historyByDate[$d][] = $row;
            }

            $replayStart = min($firstDateInData, $startDate);
            $period = new DatePeriod(new DateTime($replayStart), new DateInterval('P1D'), (new DateTime($today))->modify('+1 day'));
            
            $currentBalances = []; 
            $chartLabels = []; 
            $chartData = [];

            foreach ($period as $dt) {
                $currentDate = $dt->format('Y-m-d');
                $dayOfMonth = $dt->format('d');

                if (isset($historyByDate[$currentDate])) {
                    foreach ($historyByDate[$currentDate] as $record) {
                        $acc = $record['account_name'];
                        $currentBalances[$acc] = [
                            'balance' => (float)$record['balance'], 
                            'unit' => strtoupper($record['currency_unit']),
                            'rate' => (float)($record['exchange_rate'] ?? 0)
                        ];
                    }
                }

                if ($currentDate >= $startDate) {
                    $shouldRecord = true;
                    if ($range !== '1m') $shouldRecord = ($dayOfMonth === '01' || $dayOfMonth === '15' || $currentDate === $today);
                    
                    if ($shouldRecord) {
                        $dailyTotalTwd = 0.0;
                        foreach ($currentBalances as $accData) {
                            $bal = $accData['balance']; 
                            $curr = $accData['unit'];
                            $storedRate = $accData['rate'];

                            if ($storedRate > 0) {
                                $rateToUSD = $storedRate;
                            } else {
                                try {
                                    $rateToUSD = $rateService->getRateToUSD($curr);
                                } catch (Exception $e) {
                                    $rateToUSD = 0;
                                }
                            }
                            $dailyTotalTwd += $bal * $rateToUSD * $usdTwdRate;
                        }
                        $chartLabels[] = $currentDate;
                        $chartData[] = round($dailyTotalTwd, 0);
                    }
                }
            }
            return ['labels' => $chartLabels, 'data' => $chartData];
        } catch (PDOException $e) { return ['labels' => [], 'data' => []]; }
    }
    
    /**
     * 取得淨值總覽 (圓餅圖與卡片數據)
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
            
            $summary = []; $globalNetWorthUSD = 0.0; $usdTwdRate = $rateService->getUsdTwdRate();
            $totalCash = 0.0; $totalInvest = 0.0; $totalAssets = 0.0; $totalLiabilities = 0.0;
            $totalStock = 0.0; $totalBond = 0.0; $totalTwInvest = 0.0; $totalOverseasInvest = 0.0; 
            
            foreach ($results as $row) {
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
     * 取得單一帳戶的歷史快照列表 (包含當時匯率)
     */
    public function getAccountSnapshots(int $userId, string $accountName, int $limit = 50): array {
        $sql = "SELECT account_name, balance, currency_unit, snapshot_date, exchange_rate 
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