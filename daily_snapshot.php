<?php
// daily_snapshot.php - 每日自動執行資產快照 (Crontab 用)
// 建議排程：每日 00:05 執行

// 1. 載入必要設定與服務
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/CryptoService.php';
require_once __DIR__ . '/src/AssetService.php'; 

// 防止用戶過多導致超時 (設定 300 秒 = 5 分鐘)
set_time_limit(300); 

// 初始化服務
$pdo = Database::getInstance()->getConnection();
$cryptoService = new CryptoService();

// 記錄開始時間 (會輸出到 cron log)
echo "--- Starting Daily Snapshot: " . date('Y-m-d H:i:s') . " ---\n";

// 🟢 步驟 1: 強制更新市場價格 (確保快照用的是最新幣價)
echo "--- Step 1: Updating Market Prices (Crypto & Fiat) ---\n";
try {
    // 檢查是否有 updateMarketPrices 方法 (這是我們之前新增的)
    if (method_exists($cryptoService, 'updateMarketPrices')) {
        $cryptoService->updateMarketPrices();
        echo "Market Prices updated.\n";
    } else {
        echo "Note: updateMarketPrices function not found, skipping explicit update.\n";
    }
} catch (Exception $e) {
    echo "Market Price update Exception: " . $e->getMessage() . "\n";
}

// 2. 取得所有用戶 ID
$sql = "SELECT id FROM users ORDER BY id ASC";
$stmt = $pdo->query($sql);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$count = 0;
$success = 0;
$fail = 0;

foreach ($users as $user) {
    $userId = (int)$user['id'];
    
    try {
        // A. 獲取儀表板數據 (這裡回傳的是 TWD 計價的數據)
        $dashboardData = $cryptoService->getDashboardData($userId);
        
        $dashboard = $dashboardData['dashboard'];
        $rawHoldings = $dashboardData['holdings']; 
        $usdTwdRate = (float)$dashboardData['usdTwdRate'];
        
        // 防呆：避免匯率為 0 導致除法錯誤
        if ($usdTwdRate <= 0) $usdTwdRate = 32.0; 

        $totalCostTwd = (float)($dashboard['netInvestedTwd'] ?? $dashboard['totalCostTwd'] ?? 0);

        // B. 轉換持倉格式
        $snapshotHoldings = [];
        // 🟢 定義不需轉美金的幣種 (與 AssetService 保持一致)
        $directTwdCurrencies = ['USD', 'USDT', 'USDC', 'BUSD', 'DAI'];

        foreach ($rawHoldings as $h) {
            // 取得當前價格 (這是 getDashboardData 回傳的台幣價格)
            $priceTwd = (float)($h['currentPrice'] ?? 0);
            $symbol = strtoupper($h['symbol']); // 轉大寫以防萬一
            
            // 🟢 [修正邏輯] 分流處理
            if (in_array($symbol, $directTwdCurrencies)) {
                // A. 穩定幣 (USDT)：直接存 TWD 價格 (即匯率，如 32.5)
                // AssetService 讀到時會直接乘，所以這樣存才對
                $storeRate = $priceTwd; 
            } else {
                // B. 其他加密貨幣 (BTC)：除以匯率，還原成 USD 價格 (如 96000)
                // AssetService 讀到時會幫你乘匯率，所以這裡要先除掉
                if ($usdTwdRate > 0) {
                    $storeRate = $priceTwd / $usdTwdRate;
                } else {
                    $storeRate = 0;
                }
            }

            $snapshotHoldings[] = [
                'symbol' => $h['symbol'],
                'qty' => (float)$h['balance'],
                'price_usd' => $storeRate, // 雖然變數叫 price_usd，但這裡存的是「符合邏輯的混合匯率」
            ];
        }

        // C. 呼叫 captureSnapshot
        if ($cryptoService->captureSnapshot($userId, $snapshotHoldings, $usdTwdRate, $totalCostTwd)) {
            $success++;
            echo "User {$userId}: Snapshot OK\n"; 
        } else {
            $fail++;
            echo "User {$userId}: Snapshot Failed\n";
        }

    } catch (Exception $e) {
        $fail++;
        echo "User {$userId}: Exception - " . $e->getMessage() . "\n";
    }
    
    $count++;
    usleep(50000); 
}

echo "--- Finished. Total: {$count}, Success: {$success}, Failed: {$fail} ---\n";