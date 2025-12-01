<?php
// src/AssetService.php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ExchangeRateService.php';

class AssetService {
    private $pdo;

    private const VALID_TYPES = ['Cash', 'Investment', 'Liability'];

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * 檢查並標準化資產類型 (將中文轉為英文代碼)
     */
    public function sanitizeAssetType(string $input): string {
        $map = [
            '現金' => 'Cash', '活存' => 'Cash', '銀行' => 'Cash',
            '投資' => 'Investment', '股票' => 'Investment', '基金' => 'Investment',
            '負債' => 'Liability', '房貸' => 'Liability', '車貸' => 'Liability',
            '卡債' => 'Liability', '借款' => 'Liability',
        ];
        $standardized = $map[trim($input)] ?? 'Cash';
        return in_array($standardized, self::VALID_TYPES) ? $standardized : 'Cash';
    }

    /**
     * 新增或更新帳戶餘額 (使用 UPSERT 邏輯)
     */
    public function upsertAccountBalance(int $userId, string $name, float $balance, string $type, string $currencyUnit): bool {
        
        $assetType = $this->sanitizeAssetType($type); 
        
        // 【修正點】：在 SQL 中加入 currency_unit
        $sql = "INSERT INTO accounts (user_id, name, type, balance, currency_unit)
                VALUES (:userId, :name, :type, :balance, :unit)
                ON DUPLICATE KEY UPDATE 
                balance = VALUES(balance), last_updated_at = NOW(), type = VALUES(type), currency_unit = VALUES(currency_unit)";
    
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':userId' => $userId,
                ':name' => $name,
                ':type' => $assetType,
                ':balance' => $balance,
                ':unit' => strtoupper($currencyUnit) // 確保存入大寫 (e.g., BTC, USD)
            ]);
        } catch (PDOException $e) {
            error_log("AssetService UPSERT failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 獲取淨資產總覽 (分幣種)
     * @return array 包含分組數據的陣列
     */
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
            $globalNetWorthUSD = 0.0; // 新增追蹤 USD 總值
            $usdTwdRate = $rateService->getUsdTwdRate(); // 獲取 USD/TWD 匯率
    
            foreach ($results as $row) {
                $currency = $row['currency_unit'];
                $type = $row['type'];
                $total = (float)$row['total'];
    
                // 1. 計算該幣種兌換 USD 的價值
                $rateToUSD = $rateService->getRateToUSD($currency);
                $usdValue = $total * $rateToUSD;
    
                // 2. 計算 TWD 價值
                $twdValue = $usdValue * $usdTwdRate;
    
                if (!isset($summary[$currency])) {
                    $summary[$currency] = [
                        'assets' => 0.0, 'liabilities' => 0.0, 'net_worth' => 0.0, 
                        'usd_total' => 0.0, // 【新增】
                        'twd_total' => 0.0  // 【新增】
                    ];
                }
    
                if ($type === 'Liability') {
                    $summary[$currency]['liabilities'] += $total;
                    $summary[$currency]['net_worth'] -= $total;
                    $globalNetWorthUSD -= $usdValue;
                } else {
                    $summary[$currency]['assets'] += $total;
                    $summary[$currency]['net_worth'] += $total;
                    $globalNetWorthUSD += $usdValue;
                }
                
                $summary[$currency]['usd_total'] += $usdValue;
                $summary[$currency]['twd_total'] += $twdValue;
            }
    
            // 3. 最終計算全球淨值 (TWD)
            $globalNetWorthTWD = $globalNetWorthUSD * $usdTwdRate;
    
            // 返回結果中新增全球淨值和 TWD/USD 匯率
            return [
                'breakdown' => $summary, 
                'global_twd_net_worth' => $globalNetWorthTWD,
                'usdTwdRate' => $usdTwdRate // 傳遞匯率給前端顯示
            ];
        } catch (PDOException $e) {
            error_log("AssetService query failed: " . $e->getMessage());
            return ['breakdown' => [], 'global_twd_net_worth' => 0.0, 'usdTwdRate' => 32.0];
        }
    }
}