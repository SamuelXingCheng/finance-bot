<?php
// src/AssetService.php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ExchangeRateService.php';

class AssetService {
    private $pdo;
    // 擴充允許的類型
    private const VALID_TYPES = ['Cash', 'Investment', 'Liability', 'Stock', 'Bond'];

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
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
     * 更新帳戶餘額 (包含寫入歷史快照)
     * @param string|null $snapshotDate 如果未提供，預設為今日 (YYYY-MM-DD)
     */
    public function upsertAccountBalance(int $userId, string $name, float $balance, string $type, string $currencyUnit, ?string $snapshotDate = null): bool {
        $assetType = $this->sanitizeAssetType($type); 
        $date = $snapshotDate ?? date('Y-m-d'); // 預設今天

        try {
            $this->pdo->beginTransaction();

            // 1. 更新主表 accounts (保持當前最新狀態)
            $sqlMain = "INSERT INTO accounts (user_id, name, type, balance, currency_unit, last_updated_at)
                        VALUES (:userId, :name, :type, :balance, :unit, NOW())
                        ON DUPLICATE KEY UPDATE 
                        balance = VALUES(balance), 
                        type = VALUES(type), 
                        currency_unit = VALUES(currency_unit),
                        last_updated_at = NOW()";
            
            $stmtMain = $this->pdo->prepare($sqlMain);
            $stmtMain->execute([
                ':userId' => $userId, 
                ':name' => $name, 
                ':type' => $assetType, 
                ':balance' => $balance, 
                ':unit' => strtoupper($currencyUnit)
            ]);

            // 2. 寫入歷史表 account_balance_history (記錄時間序列)
            // 策略：刪除該用戶、該帳戶、該日期的舊紀錄，寫入新的 (覆蓋當日舊快照)
            $sqlDelHistory = "DELETE FROM account_balance_history 
                              WHERE user_id = :userId AND account_name = :name AND snapshot_date = :date";
            $stmtDel = $this->pdo->prepare($sqlDelHistory);
            $stmtDel->execute([':userId' => $userId, ':name' => $name, ':date' => $date]);

            $sqlHistory = "INSERT INTO account_balance_history (user_id, account_name, balance, currency_unit, snapshot_date)
                           VALUES (:userId, :name, :balance, :unit, :date)";
            $stmtHist = $this->pdo->prepare($sqlHistory);
            $stmtHist->execute([
                ':userId' => $userId,
                ':name' => $name,
                ':balance' => $balance,
                ':unit' => strtoupper($currencyUnit),
                ':date' => $date
            ]);

            $this->pdo->commit();
            return true;

        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("AssetService UPSERT failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 取得資產歷史趨勢 (依日期加總)
     */
    public function getAssetTrend(int $userId, string $start, string $end): array {
        // 1. 撈取範圍內的所有歷史紀錄
        $sql = "SELECT snapshot_date, balance, currency_unit 
                FROM account_balance_history 
                WHERE user_id = :uid AND snapshot_date BETWEEN :start AND :end
                ORDER BY snapshot_date ASC";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':uid' => $userId, ':start' => $start, ':end' => $end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 2. 依日期分組並換算匯率
            $dailyTotals = [];
            $rateService = new ExchangeRateService();
            $usdTwdRate = $rateService->getUsdTwdRate();

            foreach ($rows as $row) {
                $date = $row['snapshot_date'];
                $currency = strtoupper($row['currency_unit']);
                $balance = (float)$row['balance'];

                // 匯率換算 (使用當前匯率作為估算)
                $rateToUSD = $rateService->getRateToUSD($currency);
                $twdValue = $balance * $rateToUSD * $usdTwdRate;

                if (!isset($dailyTotals[$date])) {
                    $dailyTotals[$date] = 0;
                }
                $dailyTotals[$date] += $twdValue;
            }

            // 3. 格式化輸出
            $result = [];
            foreach ($dailyTotals as $date => $total) {
                $result[] = ['date' => $date, 'total' => $total];
            }
            return $result;

        } catch (PDOException $e) {
            error_log("getAssetTrend Error: " . $e->getMessage());
            return [];
        }
    }
    
    public function getNetWorthSummary(int $userId): array {
        $rateService = new ExchangeRateService(); 
        $sql = "SELECT type, currency_unit, SUM(balance) as total 
                FROM accounts 
                WHERE user_id = :userId 
                GROUP BY type, currency_unit 
                ORDER BY currency_unit, type";
    
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':userId' => $userId]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
            $summary = [];
            $globalNetWorthUSD = 0.0;
            $usdTwdRate = $rateService->getUsdTwdRate();
            
            // 統計變數
            $totalCash = 0.0;
            $totalInvest = 0.0; 
            $totalAssets = 0.0;
            $totalLiabilities = 0.0;

            // 新圖表需要的統計變數
            $totalStock = 0.0;
            $totalBond = 0.0;
            $totalTwInvest = 0.0; // 台股 (TWD 計價的投資)
            $totalOverseasInvest = 0.0; // 海外 (非 TWD 計價的投資)
    
            foreach ($results as $row) {
                $currency = $row['currency_unit'];
                $type = $row['type'];
                $total = (float)$row['total'];
                
                $rateToUSD = $rateService->getRateToUSD($currency);
                $usdValue = $total * $rateToUSD;
                $twdValue = $usdValue * $usdTwdRate;
    
                if (!isset($summary[$currency])) {
                    $summary[$currency] = [
                        'assets' => 0.0, 'liabilities' => 0.0, 'net_worth' => 0.0, 
                        'usd_total' => 0.0, 'twd_total' => 0.0
                    ];
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

                    // 分類統計
                    if ($type === 'Cash') {
                        $totalCash += $twdValue;
                    } else {
                        // 廣義投資
                        $totalInvest += $twdValue;

                        if ($type === 'Stock' || $type === 'Investment') {
                            $totalStock += $twdValue;
                        } elseif ($type === 'Bond') {
                            $totalBond += $twdValue;
                        }

                        // 🟢 統計地區：非 TWD 皆視為海外投資
                        if ($currency === 'TWD') {
                            $totalTwInvest += $twdValue;
                        } else {
                            $totalOverseasInvest += $twdValue;
                        }
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
                    'overseas_invest' => $totalOverseasInvest // 🟢 新增回傳欄位
                ]
            ];
        } catch (PDOException $e) {
            error_log("AssetService query failed: " . $e->getMessage());
            return ['breakdown' => [], 'global_twd_net_worth' => 0.0, 'usdTwdRate' => 32.0, 'charts' => []];
        }
    }

    public function getAccounts(int $userId): array {
        $sql = "SELECT name, type, balance, currency_unit, last_updated_at 
                FROM accounts 
                WHERE user_id = :userId 
                ORDER BY type ASC, balance DESC";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':userId' => $userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
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
}
?>