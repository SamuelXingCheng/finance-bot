<?php
// patch_history.php - 補抓缺失的歷史匯率
// 用途：掃描 account_balance_history 中匯率為 0 的紀錄，並呼叫 CoinGecko 歷史 API 補齊

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/ExchangeRateService.php';

// 防止執行過久被中斷
set_time_limit(300);

echo "--- 開始執行歷史匯率補抓任務 ---\n";

$pdo = Database::getInstance()->getConnection();
$rateService = new ExchangeRateService($pdo);

// 1. 找出所有匯率異常 (0 或 NULL) 的紀錄
// 排除 USDT (通常是 1) 和 TWD (法幣)
$sql = "SELECT id, user_id, currency_unit, snapshot_date 
        FROM account_balance_history 
        WHERE (exchange_rate IS NULL OR exchange_rate = 0) 
          AND currency_unit NOT IN ('USDT', 'TWD', 'USD')
        ORDER BY snapshot_date DESC"; // 從最近的開始補

$stmt = $pdo->query($sql);
$missingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "🔍 發現 " . count($missingRows) . " 筆缺失匯率的紀錄。\n";

$count = 0;
$updated = 0;

foreach ($missingRows as $row) {
    $id = $row['id'];
    $symbol = $row['currency_unit'];
    $date = $row['snapshot_date'];

    echo "正在補抓 [{$date}] {$symbol} ... ";

    // 2. 呼叫 API 查詢該日期的歷史匯率
    // 注意：ExchangeRateService 必須要有 getHistoricalRateToUSD 方法
    $historicalRate = $rateService->getHistoricalRateToUSD($symbol, $date);

    if ($historicalRate > 0) {
        // 3. 更新資料庫
        $updateSql = "UPDATE account_balance_history SET exchange_rate = :rate WHERE id = :id";
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute([':rate' => $historicalRate, ':id' => $id]);
        
        echo "✅ 成功! 匯率: {$historicalRate}\n";
        $updated++;
    } else {
        echo "❌ 失敗 (API 未回傳或無此幣種資料)\n";
    }

    $count++;
    
    // CoinGecko 免費版 API 限制約每分鐘 10-30 次，稍微休息一下避免被鎖 IP
    usleep(1500000); // 暫停 1.5 秒
}

echo "--- 任務結束 ---\n";
echo "共處理: {$count} 筆，成功修復: {$updated} 筆。\n";