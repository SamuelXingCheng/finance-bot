<?php
// 設置回應標頭為 JSON 格式
header('Content-Type: application/json; charset=utf-8');
// 根據您的 LIFF 配置，您可能需要新增您的自訂域名，例如: https://yourdomain.com
header('Access-Control-Allow-Origin: https://liff.line.me'); 
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// 處理 OPTIONS 請求 (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ----------------------------------------------------
// 1. 載入服務與環境
// ----------------------------------------------------
require_once 'config.php';
require_once 'src/Database.php'; 
require_once 'src/UserService.php'; 
require_once 'src/AssetService.php'; 
require_once 'src/TransactionService.php'; 
require_once 'src/ExchangeRateService.php'; 

/**
 * LIFF 專用驗證函式：使用 ID Token 遠端驗證 Line User ID
 * @param string $idToken 從前端 header 傳入的 ID Token
 * @return string|null 驗證成功則回傳 Line User ID，否則回傳 null
 */
function verifyLineIdToken(string $idToken): ?string {
    // 呼叫 LINE 的 token 驗證端點
    $url = 'https://api.line.me/oauth2/v2.1/verify';
    $ch = curl_init($url);
    
    // 傳入 ID Token 和您的 Channel ID 進行驗證
    $data = http_build_query([
        'id_token' => $idToken,
        'client_id' => LINE_CHANNEL_ID // 使用 LINE Channel ID (必須與 LIFF App 綁定)
    ]);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => $data,
    ]);
    
    $rawResponse = curl_exec($ch); // 獲取原始回覆
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $response = json_decode($rawResponse, true);

    // ------------------------------------------------------------------
    // 1. 檢查 HTTP 狀態碼和基本欄位
    // ------------------------------------------------------------------
    if ($httpCode !== 200 || !isset($response['sub'])) {
        // 如果 LINE 伺服器回傳非 200 錯誤，記錄詳細訊息
        error_log("Token Verification Failed. HTTP Code: {$httpCode}. Raw Response: " . $rawResponse);
        return null;
    }
    
    // ------------------------------------------------------------------
    // 2. 【關鍵修正】檢查 'aud' (Audience) 是否與我們的 Channel ID 匹配
    // ------------------------------------------------------------------
    if (isset($response['sub']) && $response['aud'] === LINE_CHANNEL_ID) {
        // 'sub' 即為 Line User ID
        return $response['sub']; 
    }
    
    // 最終檢查失敗，這不應該發生在成功的驗證後，除非 Channel ID 不匹配
    error_log("Token Verification Failed. Channel ID Mismatch. Aud: {$response['aud']}. Expected: ".LINE_CHANNEL_ID);
    return null;
}

try {
    // ----------------------------------------------------
    // 2. LIFF 身份驗證 (取代寫死 ID)
    // ----------------------------------------------------
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $idToken = $matches[1];
    } else {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Missing or invalid token format.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 驗證 Token 並取得 Line User ID
    $lineUserId = verifyLineIdToken($idToken);

    if (!$lineUserId) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Invalid ID Token.'], JSON_UNESCAPED_UNICODE); 
        exit;
    }

    // 獲取內部 DB User ID
    $userService = new UserService();
    $dbUserId = $userService->findOrCreateUser($lineUserId);


    // ----------------------------------------------------
    // 3. 服務初始化 (移到驗證成功後)
    // ----------------------------------------------------
    $db = Database::getInstance(); 
    $assetService = new AssetService();
    $transactionService = new TransactionService();


    // ----------------------------------------------------
    // 4. API 路由與分發
    // ----------------------------------------------------
    $action = $_GET['action'] ?? '';
    $response = ['status' => 'error', 'message' => 'Invalid action.'];

    switch ($action) {
        
        case 'asset_summary':
            $summary = $assetService->getNetWorthSummary($dbUserId); 
            $response = ['status' => 'success', 'data' => $summary];
            break;

        case 'monthly_expense_breakdown':
            $breakdown = $transactionService->getMonthlyBreakdown($dbUserId, 'expense'); 
            $totalExpense = $transactionService->getTotalExpenseByMonth($dbUserId); 
            $response = [
                'status' => 'success', 
                'data' => [
                    'total_expense' => $totalExpense,
                    'breakdown' => $breakdown
                ]
            ];
            break;
            
        case 'add_transaction':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $response = ['status' => 'error', 'message' => 'Method not allowed.'];
                http_response_code(405); 
                break;
            }

            $json_data = file_get_contents('php://input');
            $data = json_decode($json_data, true);

            // 嚴格輸入驗證
            $amount = filter_var($data['amount'] ?? 0, FILTER_VALIDATE_FLOAT);
            $type = $data['type'] ?? '';
            $category = $data['category'] ?? '';
            
            if ($amount === false || $amount <= 0 || !in_array($type, ['income', 'expense'])) {
                $response = ['status' => 'error', 'message' => '無效的金額或類型。'];
                http_response_code(400);
                break;
            }

            // 將驗證通過的數據傳遞給 Service
            $success = $transactionService->addTransaction($dbUserId, $data);

            if ($success) {
                $response = ['status' => 'success', 'message' => '交易新增成功！'];
            } else {
                $response = ['status' => 'error', 'message' => '交易新增失敗，請檢查類別或資料庫連線。'];
            }
            break;

        default:
            // 保持預設的錯誤訊息
            break;
    }

} catch (Throwable $e) {
    // 記錄錯誤並回傳通用錯誤訊息
    error_log("API Error: " . $e->getMessage());
    $response = ['status' => 'error', 'message' => 'Server error occurred: ' . $e->getMessage()];
    http_response_code(500);
}

// 輸出 JSON 結果
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;