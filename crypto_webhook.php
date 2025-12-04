<?php
// crypto_webhook.php (API 反查增強版)

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/crypto_debug.log');

require_once 'config.php';
require_once 'src/Database.php';
require_once 'src/UserService.php';
require_once 'src/LineService.php';

// ====================================================
// 1. 驗證與接收
// ====================================================
$payload = file_get_contents('php://input');
$received_signature = $_SERVER['HTTP_X_NOWPAYMENTS_SIG'] ?? '';

error_log("💰 Webhook Received: " . $payload);

if (defined('NOWPAYMENTS_IPN_KEY') && !empty($received_signature)) {
    $calculated_signature = hash_hmac('sha512', $payload, NOWPAYMENTS_IPN_KEY);
    if ($received_signature !== $calculated_signature) {
        http_response_code(403); exit('Invalid Signature');
    }
}

$data = json_decode($payload, true);
if (empty($data)) { http_response_code(400); exit('Empty Data'); }

$status = $data['payment_status'] ?? 'unknown';
// 這裡放寬限制，如果是 confirming 也可以先查查看資料，但通常 finished 才開通
if ($status !== 'finished' && $status !== 'confirmed') {
    error_log("⏳ Status is {$status}, waiting.");
    echo 'OK'; exit;
}

// ====================================================
// 2. 尋找 Email (三階段搜尋：欄位 -> Regex -> API 反查)
// ====================================================

// 輔助函式：從陣列或字串中找 Email
function extractEmail($source) {
    if (is_array($source)) $source = json_encode($source);
    if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $source, $matches)) {
        return $matches[0];
    }
    return null;
}

// 第一階段：找 Webhook 內既有資料
$email = extractEmail($payload);

// 🔥 第二階段：如果找不到，且有 API Key，發動 API 反查
if (empty($email) && defined('NOWPAYMENTS_API_KEY') && !empty($data['payment_id'])) {
    $paymentId = $data['payment_id'];
    error_log("🔄 Email missing in Webhook. Fetching details from API for Payment ID: {$paymentId}...");

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.nowpayments.io/v1/payment/{$paymentId}",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . NOWPAYMENTS_API_KEY
        ],
    ]);
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($httpCode === 200) {
        error_log("📥 API Response: " . $response); // 記錄 API 回傳的詳細資料
        // 從 API 回傳的完整資料中再找一次 Email
        $apiEmail = extractEmail($response);
        
        if (!empty($apiEmail)) {
            $email = $apiEmail;
            error_log("✅ Found Email via API: {$email}");
        } else {
            error_log("⚠️ API returned data but no email found inside.");
        }
    } else {
        error_log("❌ API Request failed. HTTP Code: {$httpCode}");
    }
}

// ====================================================
// 3. 業務邏輯 (計算與開通)
// ====================================================
$amount = $data['price_amount'] ?? $data['pay_amount'] ?? 0;
$currency = $data['pay_currency'] ?? 'Crypto';
$priceCurrency = $data['price_currency'] ?? 'USD';

$isSuccess = false;
$activatedEmail = null;
$pricePerMonth = 3.0; 
$daysPerMonth = 30;
$safeAmount = max((float)$amount, 0);

$calculatedDays = floor(($safeAmount / $pricePerMonth) * $daysPerMonth);
if ($safeAmount > 0 && $calculatedDays < 1) $calculatedDays = 1;
$days = (int)$calculatedDays;

if (!empty($email) && $days > 0) {
    $userService = new UserService();
    if ($userService->activatePremiumByEmail($email, $days)) {
        $isSuccess = true;
        $activatedEmail = $email;
        error_log("✅ Premium activated for {$email}");
    } else {
        error_log("❌ User not found in DB: {$email}");
    }
} else {
    error_log("⚠️ Failed: Still no email after API check, or amount is zero.");
}

// ====================================================
// 4. 通知
// ====================================================
if ($isSuccess && $activatedEmail) {
    try {
        $user = $userService->getUserByBmcEmail($activatedEmail);
        if ($user && !empty($user['line_user_id'])) {
            $lineService = new LineService();
            $rawExpireDate = $user['premium_expire_date']; 
            $displayDate = $rawExpireDate ? date('Y/m/d', strtotime($rawExpireDate)) : "N/A";
            $currencyUpper = strtoupper($currency);

            $flexPayload = [
                'type' => 'bubble',
                'size' => 'kilo',
                'header' => [
                    'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#272727',
                    'paddingAll' => 'lg',
                    'contents' => [['type' => 'text', 'text' => '💎 加密貨幣支付成功', 'weight' => 'bold', 'color' => '#00D1FF', 'size' => 'lg']]
                ],
                'body' => [
                    'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#333333',
                    'contents' => [
                        ['type' => 'text', 'text' => '區塊鏈交易已確認', 'weight' => 'bold', 'size' => 'md', 'color' => '#FFFFFF'],
                        ['type' => 'separator', 'margin' => 'lg', 'color' => '#555555'],
                        [
                            'type' => 'box', 'layout' => 'vertical', 'margin' => 'lg', 'spacing' => 'sm', 
                            'contents' => [
                                ['type' => 'box', 'layout' => 'baseline', 'contents' => [['type' => 'text', 'text' => '金額', 'color' => '#aaaaaa', 'flex' => 2], ['type' => 'text', 'text' => "{$priceCurrency} \${$safeAmount}", 'color' => '#FFFFFF', 'flex' => 4]]],
                                ['type' => 'box', 'layout' => 'baseline', 'contents' => [['type' => 'text', 'text' => '效期', 'color' => '#aaaaaa', 'flex' => 2], ['type' => 'text', 'text' => "至 {$displayDate}", 'color' => '#FFD700', 'weight' => 'bold', 'flex' => 4]]]
                            ]
                        ]
                    ]
                ]
            ];
            
            if (method_exists($lineService, 'pushFlexMessage')) {
                $lineService->pushFlexMessage($user['line_user_id'], "💎 Crypto 支付成功通知", $flexPayload);
            } else {
                $lineService->pushMessage($user['line_user_id'], "💎 支付成功！已開通 Premium 至 {$displayDate}。");
            }
        }
    } catch (Exception $e) { error_log("⚠️ Notify Failed: " . $e->getMessage()); }
}

echo 'OK';