<?php
// send_reminders.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/LineService.php';

// 設定時區，確保與用戶設定的時間一致
date_default_timezone_set('Asia/Taipei');

// 取得當前時間 (格式 HH:MM)
$currentTime = date('H:i');

// 連線資料庫
$db = Database::getInstance()->getConnection();

// 1. 查詢所有設定為當前時間提醒，且有綁定 LINE 的用戶
// 注意：line_user_id 不為空才代表有綁定
$sql = "SELECT id, line_user_id, financial_goal FROM users 
        WHERE reminder_time = :time 
        AND line_user_id IS NOT NULL 
        AND line_user_id != ''";

$stmt = $db->prepare($sql);
$stmt->execute([':time' => $currentTime]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($users)) {
    echo "[$currentTime] No reminders to send.\n";
    exit;
}

$lineService = new LineService();
$count = 0;

foreach ($users as $user) {
    // 2. 根據用戶目標，客製化提醒文案 (增加親切感)
    $msg = "記帳提醒\n";
    
    switch ($user['financial_goal']) {
        case 'control': // 提早退休
            $msg .= "距離財富自由又過了一天，今天資產有變化嗎？記得記錄下來喔！";
            break;
        case 'analyze': // 消費分析
            $msg .= "今天的錢都花去哪了呢？花 30 秒記帳，讓財務更清晰！🧐";
            break;
        default: // 生活樂趣 / 其他
            $msg .= "忙碌了一天，別忘了關心一下今天的錢包君喔～ ";
            break;
    }

    // 3. 發送推播
    // 注意：Push Message 是收費功能 (免費帳號每月 200 則)
    // 如果用戶量大，建議未來改用 LINE Notify 或其他方式
    if ($lineService->pushMessage($user['line_user_id'], $msg)) {
        echo "Sent to User ID: {$user['id']}\n";
        $count++;
    } else {
        echo "Failed to send to User ID: {$user['id']}\n";
    }
}

echo "[$currentTime] Sent $count reminders.\n";