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
    
    // 🟢 步驟 3: 準備數據並執行快照 (修正 ArgumentCountError 的關鍵)
    try {
        // A. 先獲取當前儀表板數據 (這裡會抓到最新的價格和資料庫的持倉)
        $dashboardData = $cryptoService->getDashboardData($userId);
        
        $dashboard = $dashboardData['dashboard'];
        $rawHoldings = $dashboardData['holdings']; // 取得持倉列表
        $usdTwdRate = (float)$dashboardData['usdTwdRate'];
        
        // 注意：有些版本 getDashboardData 回傳的是 netInvestedTwd，有些是 totalCostTwd，這邊做個防呆
        $totalCostTwd = (float)($dashboard['netInvestedTwd'] ?? $dashboard['totalCostTwd'] ?? 0);

        // B. 轉換持倉格式，符合 captureSnapshot 的參數要求
        $snapshotHoldings = [];
        foreach ($rawHoldings as $h) {
            // 將 getDashboardData 的資料轉為 captureSnapshot 需要的格式
            $snapshotHoldings[] = [
                'symbol' => $h['symbol'],
                'qty' => (float)$h['balance'],
                'price_usd' => (float)($h['currentPrice'] ?? 0),
                // price_twd 會在 captureSnapshot 內部自動計算，這裡不用傳
            ];
        }

        // C. 呼叫 captureSnapshot (正確傳入 4 個參數)
        // 參數順序: userId, holdingsSnapshot, usdTwdRate, totalCostTwd
        if ($cryptoService->captureSnapshot($userId, $snapshotHoldings, $usdTwdRate, $totalCostTwd)) {
            $success++;
            echo "User {$userId}: Snapshot OK\n"; 
        } else {
            $fail++;
            echo "User {$userId}: Snapshot Failed (Func returned false)\n";
        }

    } catch (Exception $e) {
        $fail++;
        echo "User {$userId}: Exception - " . $e->getMessage() . "\n";
    }
    
    $count++;
    
    // 稍微暫停，避免瞬間 DB I/O 過高
    usleep(50000); // 0.05 秒
}

echo "--- Finished. Total: {$count}, Success: {$success}, Failed: {$fail} ---\n";