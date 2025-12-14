<?php
// daily_snapshot.php - 每日自動執行資產快照 (Crontab 用)
// 建議排程：每日 00:05 執行

// 1. 載入必要設定與服務
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/CryptoService.php';
require_once __DIR__ . '/src/AssetService.php'; // 確保載入

// 防止用戶過多導致超時 (設定 300 秒 = 5 分鐘)
set_time_limit(300); 

// 初始化服務
$pdo = Database::getInstance()->getConnection();
$cryptoService = new CryptoService();

// 記錄開始時間 (會輸出到 cron log)
echo "--- Starting Daily Snapshot: " . date('Y-m-d H:i:s') . " ---\n";

// 2. 取得所有用戶 ID
// 為了確保圖表連續性，建議對所有用戶執行
$sql = "SELECT id FROM users ORDER BY id ASC";
$stmt = $pdo->query($sql);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$count = 0;
$success = 0;
$fail = 0;

foreach ($users as $user) {
    $userId = (int)$user['id'];
    
    // 3. 執行快照
    // 這會同時處理：加密貨幣儀表板紀錄 & 總資產同步
    try {
        if ($cryptoService->captureSnapshot($userId)) {
            $success++;
            // echo "User {$userId}: Snapshot OK\n"; // 若想看詳細 log 可取消註解
            
        } else {
            $fail++;
            echo "User {$userId}: Snapshot Failed\n";
        }
    } catch (Exception $e) {
        $fail++;
        echo "User {$userId}: Exception - " . $e->getMessage() . "\n";
    }
    
    $count++;
    
    // 稍微暫停，避免瞬間 DB I/O 過高影響前台
    usleep(50000); // 0.05 秒
}

echo "--- Finished. Total: {$count}, Success: {$success}, Failed: {$fail} ---\n";