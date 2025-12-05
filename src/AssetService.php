<?php
// src/AssetService.php (最終完整版 - 包含歷史資產功能)
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
     * 🌟 修正：包含 $snapshotDate 參數，並執行事務寫入主表和歷史表
     *
     * @param int $userId
     * @param string $name
     * @param float $balance
     * @param string $type
     * @param string $currencyUnit
     * @param string|null $snapshotDate 歷史快照日期，如果為 null 則使用今日
     * @return bool
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

            // 2. 寫入歷史表 account_balance_history (覆蓋當日舊快照)
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
     * 🟢 新增：取得歷史淨值趨勢 (統一使用 $range 參數，回傳 labels/data 陣列)
     * 對應前端 DashboardView.vue 的 fetchAssetHistory 呼叫
     */
    public function getAssetHistory(int $userId, string $range = '1y'): array {
        // 1. 計算日期範圍
        $interval = '-1 year';
        if ($range === '1m') $interval = '-1 month';
        if ($range === '6m') $interval = '-6 months';
        
        $startDate = date('Y-m-d', strtotime($interval));

        // 2. 撈取歷史資料 (依日期與幣種分組)
        $sql = "SELECT snapshot_date, currency_unit, SUM(balance) as total_balance 
                FROM account_balance_history 
                WHERE user_id = :userId 
                  AND snapshot_date >= :startDate
                GROUP BY snapshot_date, currency_unit 
                ORDER BY snapshot_date ASC";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':userId' => $userId, ':startDate' => $startDate]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 3. 整合計算：將同一天的多種貨幣換算成 TWD 加總
            $rateService = new ExchangeRateService();
            $usdTwdRate = $rateService->getUsdTwdRate();
            $dailyNetWorth = [];

            foreach ($rows as $row) {
                $date = $row['snapshot_date'];
                $currency = strtoupper($row['currency_unit']);
                $balance = (float)$row['total_balance']; 

                // 匯率換算邏輯
                $rateToUSD = $rateService->getRateToUSD($currency);
                $twdValue = $balance * $rateToUSD * $usdTwdRate;
                
                if (!isset($dailyNetWorth[$date])) {
                    $dailyNetWorth[$date] = 0.0;
                }
                $dailyNetWorth[$date] += $twdValue;
            }

            // 4. 格式化輸出給前端圖表 (Labels 和 Data)
            $result = [
                'labels' => array_keys($dailyNetWorth),
                'data' => array_values($dailyNetWorth)
            ];

            return $result;

        } catch (PDOException $e) {
            error_log("getAssetHistory Error: " . $e->getMessage() . " for User ID: {$userId}");
            return ['labels' => [], 'data' => []];
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