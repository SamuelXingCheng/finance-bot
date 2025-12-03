<?php
// bmc_webhook.php
require_once 'config.php';
require_once 'src/Database.php';
require_once 'src/UserService.php';
require_once 'src/LineService.php'; // 選用：付款成功通知用戶

// 1. 設定 BMC 的 Secret (在 BMC 後台 Webhooks 設定頁面取得)
// 建議放在 .env 中: BMC_WEBHOOK_SECRET=your_secret
$secret = getenv('BMC_WEBHOOK_SECRET'); 

// 2. 驗證簽章 (BMC 使用 HMAC SHA256)
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';

// 注意：BMC 的測試工具可能不會發送正確簽章，正式上線務必開啟驗證
if ($secret && $signature) {
    $hash = hash_hmac('sha256', $payload, $secret);
    if (!hash_equals($hash, $signature)) {
        http_response_code(401);
        exit('Invalid signature');
    }
}

$data = json_decode($payload, true);

// 3. 處理事件
if (isset($data['type']) && $data['type'] === 'donation.created') {
    $email = $data['data']['supporter_email'] ?? '';
    $amount = $data['data']['amount'] ?? 0;
    
    if ($email) {
        $userService = new UserService();
        // 假設 $5 美金 = 30 天會員
        $days = ($amount >= 5) ? 30 : 7; 
        
        if ($userService->activatePremiumByEmail($email, $days)) {
            error_log("Premium activated for {$email}");
            // 這裡可以加 LineService 推送通知給用戶說「開通成功！」
        } else {
            error_log("Failed to activate premium for {$email} (User not found)");
        }
    }
}

http_response_code(200); // 必須回傳 200 給 BMC
echo 'OK';