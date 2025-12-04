<?php
// bmc_webhook.php
// 設置錯誤記錄到檔案 (可選，方便您在 hosting 根目錄查看 debug.log)
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/bmc_debug.log');

require_once 'config.php';
require_once 'src/Database.php';
require_once 'src/UserService.php';
require_once 'src/LineService.php';

// 1. 獲取原始資料
$payload = file_get_contents('php://input');
$data = json_decode($payload, true);

// 🔍【關鍵 Debug】記錄收到的原始資料，確認 Zapier 傳了什麼結構
error_log("BMC Webhook Received Payload: " . $payload);

// 如果解析失敗或為空
if (empty($data)) {
    error_log("BMC Webhook Error: Empty or Invalid JSON");
    http_response_code(400);
    exit('Empty Data');
}

// 2. 資料結構兼容處理 (適配 Zapier 與 官方 Webhook)
// 優先嘗試從 ['data'] 取值 (官方格式)，如果沒有則嘗試直接取值 (Zapier 格式)
$email = $data['data']['supporter_email'] ?? $data['supporter_email'] ?? $data['email'] ?? '';
$name  = $data['data']['supporter_name'] ?? $data['supporter_name'] ?? $data['name'] ?? '';
$amount = $data['data']['amount'] ?? $data['amount'] ?? 0;

// 🔥【Email Fallback】如果 Email 欄位是空的，檢查 Name 欄位是不是 Email
if (empty($email)) {
    $nameCheck = trim($name);
    if (filter_var($nameCheck, FILTER_VALIDATE_EMAIL)) {
        $email = $nameCheck;
        error_log("BMC Webhook: Used name field as email: {$email}");
    }
}

// ====================================================
// 3. 核心業務邏輯：多欄位掃描 & 金額自動換算
// ====================================================

// 定義候選名單 (Candidates)
$candidates = [];
if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) $candidates[] = trim($email);
$nameClean = trim($name);
if (!empty($nameClean) && filter_var($nameClean, FILTER_VALIDATE_EMAIL)) $candidates[] = $nameClean;
$candidates = array_unique($candidates);

error_log("🔍 Email Candidates to check: " . implode(', ', $candidates));

$isSuccess = false;
$activatedEmail = null;

// 💰【自動換算邏輯 START】💰
// 設定費率：每 3 美元 = 30 天
$pricePerMonth = 3.0; 
$daysPerMonth = 30;

// 防止金額為 0 或負數
$safeAmount = max((float)$amount, 0);

// 計算天數 (無條件捨去取整數，或者使用 round 四雪五入)
// 公式： (金額 / 3) * 30
$calculatedDays = floor(($safeAmount / $pricePerMonth) * $daysPerMonth);

// 確保最少給 1 天 (如果有付款的話)，或設個低消門檻
if ($safeAmount > 0 && $calculatedDays < 1) {
    $calculatedDays = 1; 
}
$days = (int)$calculatedDays;

error_log("💰 Calculation: Amount \${$safeAmount} / \${$pricePerMonth} * {$daysPerMonth} days = {$days} days");
// 💰【自動換算邏輯 END】💰


if (!empty($candidates) && $days > 0) {
    $userService = new UserService();

    foreach ($candidates as $candidateEmail) {
        // 嘗試開通
        if ($userService->activatePremiumByEmail($candidateEmail, $days)) {
            $isSuccess = true;
            $activatedEmail = $candidateEmail;
            error_log("✅ Match Found! Premium activated using email: {$candidateEmail} for {$days} days.");
            break; 
        } else {
            error_log("⚠️ Attempt failed for: {$candidateEmail} (User not found)");
        }
    }
} else {
    if ($days <= 0) {
        error_log("❌ Amount too low or zero (\${$amount}), skipping activation.");
    } else {
        error_log("❌ No valid email format found in payload.");
    }
}

// ====================================================
// 4. 後續處理：通知與回應 (Flex Message 更新)
// ====================================================

if ($isSuccess && $activatedEmail) {
    try {
        $user = $userService->getUserByBmcEmail($activatedEmail);
        if ($user && !empty($user['line_user_id'])) {
            $lineService = new LineService();
            
            $flexPayload = [
                'type' => 'bubble',
                'size' => 'kilo',
                'header' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'backgroundColor' => '#D4A373',
                    'paddingAll' => 'lg',
                    'contents' => [
                        ['type' => 'text', 'text' => '🎉 會員開通成功', 'weight' => 'bold', 'color' => '#FFFFFF', 'size' => 'lg']
                    ]
                ],
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'text', 'text' => '感謝您的熱情贊助！', 'weight' => 'bold', 'size' => 'md', 'color' => '#333333'],
                        ['type' => 'text', 'text' => '系統已依據您的贊助金額自動換算天數。', 'size' => 'xs', 'color' => '#aaaaaa', 'margin' => 'sm'],
                        ['type' => 'separator', 'margin' => 'lg'],
                        [
                            'type' => 'box', 'layout' => 'vertical', 'margin' => 'lg', 'spacing' => 'sm', 
                            'contents' => [
                                [
                                    'type' => 'box', 'layout' => 'baseline', 
                                    'contents' => [
                                        ['type' => 'text', 'text' => '贊助金額', 'color' => '#888888', 'size' => 'sm', 'flex' => 2],
                                        ['type' => 'text', 'text' => "USD \${$safeAmount}", 'color' => '#333333', 'size' => 'sm', 'flex' => 4]
                                    ]
                                ],
                                [
                                    'type' => 'box', 'layout' => 'baseline', 
                                    'contents' => [
                                        ['type' => 'text', 'text' => '增加天數', 'color' => '#888888', 'size' => 'sm', 'flex' => 2],
                                        ['type' => 'text', 'text' => "+ {$days} 天", 'color' => '#D4A373', 'weight' => 'bold', 'size' => 'md', 'flex' => 4]
                                    ]
                                ],
                                [
                                    'type' => 'box', 'layout' => 'baseline', 
                                    'contents' => [
                                        ['type' => 'text', 'text' => '綁定帳號', 'color' => '#888888', 'size' => 'sm', 'flex' => 2],
                                        ['type' => 'text', 'text' => $activatedEmail, 'color' => '#333333', 'size' => 'xs', 'flex' => 4, 'wrap' => true]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                'footer' => [
                    'type' => 'box', 'layout' => 'vertical', 
                    'contents' => [
                        ['type' => 'button', 'action' => ['type' => 'uri', 'label' => '開啟儀表板查看', 'uri' => defined('LIFF_DASHBOARD_URL') ? LIFF_DASHBOARD_URL : 'https://line.me/'], 'style' => 'primary', 'color' => '#D4A373']
                    ]
                ]
            ];

            if (method_exists($lineService, 'pushFlexMessage')) {
                $lineService->pushFlexMessage($user['line_user_id'], "🎉 Premium 會員開通成功！", $flexPayload);
            } else {
                $lineService->pushMessage($user['line_user_id'], "🎉 感謝支持！贊助 \${$safeAmount}，已開通 {$days} 天 Premium。");
            }
        }
    } catch (Exception $e) {
        error_log("⚠️ Notification Failed: " . $e->getMessage());
    }
}

// ====================================================
// 4. 後續處理：通知與回應 (顯示具體到期日)
// ====================================================

if ($isSuccess && $activatedEmail) {
    try {
        // 重新撈取用戶資料 (此時已經包含更新後的到期日)
        $user = $userService->getUserByBmcEmail($activatedEmail);
        
        if ($user && !empty($user['line_user_id'])) {
            $lineService = new LineService();
            
            // 📅 取得並格式化到期日
            $rawExpireDate = $user['premium_expire_date']; 
            // 如果讀不到日期，就顯示 "N/A" (防呆)
            $displayDate = $rawExpireDate ? date('Y/m/d', strtotime($rawExpireDate)) : "N/A";

            $flexPayload = [
                'type' => 'bubble',
                'size' => 'kilo',
                'header' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'backgroundColor' => '#D4A373',
                    'paddingAll' => 'lg',
                    'contents' => [
                        ['type' => 'text', 'text' => '🎉 會員開通成功', 'weight' => 'bold', 'color' => '#FFFFFF', 'size' => 'lg']
                    ]
                ],
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'text', 'text' => '感謝您的熱情贊助！', 'weight' => 'bold', 'size' => 'md', 'color' => '#333333'],
                        ['type' => 'text', 'text' => '您的 Premium 權益已即時生效。', 'size' => 'xs', 'color' => '#aaaaaa', 'margin' => 'sm'],
                        ['type' => 'separator', 'margin' => 'lg'],
                        [
                            'type' => 'box', 'layout' => 'vertical', 'margin' => 'lg', 'spacing' => 'sm', 
                            'contents' => [
                                // 第一行：贊助金額
                                [
                                    'type' => 'box', 'layout' => 'baseline', 
                                    'contents' => [
                                        ['type' => 'text', 'text' => '贊助金額', 'color' => '#888888', 'size' => 'sm', 'flex' => 2],
                                        ['type' => 'text', 'text' => "USD \${$safeAmount}", 'color' => '#333333', 'size' => 'sm', 'flex' => 4]
                                    ]
                                ],
                                // 第二行：增加天數
                                [
                                    'type' => 'box', 'layout' => 'baseline', 
                                    'contents' => [
                                        ['type' => 'text', 'text' => '增加天數', 'color' => '#888888', 'size' => 'sm', 'flex' => 2],
                                        ['type' => 'text', 'text' => "+ {$days} 天", 'color' => '#333333', 'size' => 'sm', 'flex' => 4]
                                    ]
                                ],
                                // 🆕 第三行：會員效期 (新增這段)
                                [
                                    'type' => 'box', 'layout' => 'baseline', 
                                    'contents' => [
                                        ['type' => 'text', 'text' => '會員效期', 'color' => '#888888', 'size' => 'sm', 'flex' => 2],
                                        ['type' => 'text', 'text' => "至 {$displayDate}", 'color' => '#D4A373', 'weight' => 'bold', 'size' => 'md', 'flex' => 4]
                                    ]
                                ],
                                // 第四行：綁定帳號
                                [
                                    'type' => 'box', 'layout' => 'baseline', 
                                    'contents' => [
                                        ['type' => 'text', 'text' => '綁定帳號', 'color' => '#888888', 'size' => 'sm', 'flex' => 2],
                                        ['type' => 'text', 'text' => $activatedEmail, 'color' => '#cccccc', 'size' => 'xxs', 'flex' => 4, 'wrap' => true]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                'footer' => [
                    'type' => 'box', 'layout' => 'vertical', 
                    'contents' => [
                        ['type' => 'button', 'action' => ['type' => 'uri', 'label' => '開啟儀表板查看', 'uri' => defined('LIFF_DASHBOARD_URL') ? LIFF_DASHBOARD_URL : 'https://line.me/'], 'style' => 'primary', 'color' => '#D4A373']
                    ]
                ]
            ];

            if (method_exists($lineService, 'pushFlexMessage')) {
                $lineService->pushFlexMessage($user['line_user_id'], "🎉 Premium 會員效期更新通知", $flexPayload);
            } else {
                $lineService->pushMessage($user['line_user_id'], "🎉 感謝支持！已開通 Premium 至 {$displayDate}。");
            }
            
            error_log("📤 Notification sent. New Expiry: {$displayDate}");
        }
    } catch (Exception $e) {
        error_log("⚠️ Notification Failed: " . $e->getMessage());
    }
}