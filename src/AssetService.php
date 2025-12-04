<?php
// src/AssetService.php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ExchangeRateService.php';

class AssetService {
    private $pdo;
    // 1. 🟢 修改：擴充允許的類型，加入 Stock 和 Bond
    private const VALID_TYPES = ['Cash', 'Investment', 'Liability', 'Stock', 'Bond'];

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }

    public function sanitizeAssetType(string $input): string {
        $input = trim($input);

        if (in_array($input, self::VALID_TYPES)) {
            return $input;
        }

        // 2. 🟢 修改：更新中文映射，讓「股票」與「債券」能正確歸類
        $map = [
            '現金' => 'Cash', '活存' => 'Cash', '銀行' => 'Cash',
            '投資' => 'Investment', // 舊有資料保留為 Investment
            '股票' => 'Stock', '證券' => 'Stock', '美股' => 'Stock', '台股' => 'Stock',
            '債券' => 'Bond', '債' => 'Bond',
            '負債' => 'Liability', '房貸' => 'Liability', '車貸' => 'Liability',
            '卡債' => 'Liability', '借款' => 'Liability',
        ];
        
        $standardized = $map[$input] ?? 'Cash';
        return in_array($standardized, self::VALID_TYPES) ? $standardized : 'Cash';
    }

    public function upsertAccountBalance(int $userId, string $name, float $balance, string $type, string $currencyUnit): bool {
        // ... (保持原樣)
        $assetType = $this->sanitizeAssetType($type); 
        $sql = "INSERT INTO accounts (user_id, name, type, balance, currency_unit)
                VALUES (:userId, :name, :type, :balance, :unit)
                ON DUPLICATE KEY UPDATE 
                balance = VALUES(balance), last_updated_at = NOW(), type = VALUES(type), currency_unit = VALUES(currency_unit)";
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':userId' => $userId, ':name' => $name, ':type' => $assetType, ':balance' => $balance, ':unit' => strtoupper($currencyUnit)
            ]);
        } catch (PDOException $e) {
            error_log("AssetService UPSERT failed: " . $e->getMessage());
            return false;
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
            $totalInvest = 0.0; // 廣義投資 (含 Stock, Bond, Investment)
            $totalAssets = 0.0;
            $totalLiabilities = 0.0;

            // 3. 🟢 新增：新圖表需要的統計變數
            $totalStock = 0.0;
            $totalBond = 0.0;
            $totalTwInvest = 0.0; // 台股 (TWD 計價的投資)
            $totalUsInvest = 0.0; // 美股 (USD 計價的投資)
    
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
                        // 廣義投資 (Investment, Stock, Bond)
                        $totalInvest += $twdValue;

                        // 統計股債 (將舊的 Investment 暫時歸類為 Stock，或根據需求調整)
                        if ($type === 'Stock' || $type === 'Investment') {
                            $totalStock += $twdValue;
                        } elseif ($type === 'Bond') {
                            $totalBond += $twdValue;
                        }

                        // 統計地區 (僅計算投資類資產)
                        if ($currency === 'TWD') {
                            $totalTwInvest += $twdValue;
                        } elseif ($currency === 'USD') {
                            $totalUsInvest += $twdValue;
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
                    // 4. 🟢 新增回傳欄位
                    'stock' => $totalStock,
                    'bond' => $totalBond,
                    'tw_invest' => $totalTwInvest,
                    'us_invest' => $totalUsInvest
                ]
            ];
        } catch (PDOException $e) {
            error_log("AssetService query failed: " . $e->getMessage());
            return ['breakdown' => [], 'global_twd_net_worth' => 0.0, 'usdTwdRate' => 32.0, 'charts' => []];
        }
    }

    // 🌟 新增方法 1：獲取單一用戶的所有帳戶列表
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
            error_log("AssetService getAccounts failed: " . $e->getMessage());
            return [];
        }
    }

    // 🌟 新增方法 2：刪除帳戶
    public function deleteAccount(int $userId, string $name): bool {
        $sql = "DELETE FROM accounts WHERE user_id = :userId AND name = :name";
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([':userId' => $userId, ':name' => $name]);
        } catch (PDOException $e) {
            error_log("AssetService deleteAccount failed: " . $e->getMessage());
            return false;
        }
    }
}
?>