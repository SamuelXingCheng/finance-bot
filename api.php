<?php
// api.php
header('Content-Type: application/json; charset=utf-8');
// 根據您的 LIFF 配置，可能需要修改允許的 Origin
header('Access-Control-Allow-Origin: *'); 
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// 處理 OPTIONS 請求
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
require_once 'src/GeminiService.php';
require_once 'src/CryptoService.php';

/**
 * LIFF 專用驗證函式
 */
function verifyLineIdToken(string $idToken): ?string {
    $url = 'https://api.line.me/oauth2/v2.1/verify';
    $ch = curl_init($url);
    
    $data = http_build_query([
        'id_token' => $idToken,
        'client_id' => LINE_CHANNEL_ID 
    ]);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => $data,
    ]);
    
    $rawResponse = curl_exec($ch); 
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $response = json_decode($rawResponse, true);

    if ($httpCode !== 200 || !isset($response['sub'])) {
        error_log("Token Verification Failed. HTTP Code: {$httpCode}. Raw Response: " . $rawResponse);
        return null;
    }
    
    if (isset($response['sub']) && $response['aud'] === LINE_CHANNEL_ID) {
        return $response['sub']; 
    }
    
    error_log("Token Verification Failed. Channel ID Mismatch.");
    return null;
}

try {
    // ----------------------------------------------------
    // 2. LIFF 身份驗證
    // ----------------------------------------------------
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $idToken = $matches[1];
    } else {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Missing or invalid token format.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $lineUserId = verifyLineIdToken($idToken);

    if (!$lineUserId) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Invalid ID Token.'], JSON_UNESCAPED_UNICODE); 
        exit;
    }

    $userService = new UserService();
    $dbUserId = $userService->findOrCreateUser($lineUserId);

    // ----------------------------------------------------
    // 3. 服務初始化
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
            // 🟢 必備：告訴前端這個人是不是會員
            $summary['is_premium'] = $userService->isPremium($dbUserId);
            $response = ['status' => 'success', 'data' => $summary];
            break;

        case 'get_accounts':
            $accounts = $assetService->getAccounts($dbUserId);
            $response = ['status' => 'success', 'data' => $accounts];
            break;

        case 'delete_account':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                $response = ['status' => 'error', 'message' => 'Method not allowed'];
                break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $name = $input['name'] ?? '';
            
            if (empty($name)) {
                $response = ['status' => 'error', 'message' => '缺少帳戶名稱'];
                break;
            }

            if ($assetService->deleteAccount($dbUserId, $name)) {
                $response = ['status' => 'success', 'message' => "帳戶 [{$name}] 已刪除"];
            } else {
                $response = ['status' => 'error', 'message' => '刪除失敗'];
            }
            break;
        
        case 'save_account':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405); break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            
            $name = trim($input['name'] ?? '');
            $type = $input['type'] ?? 'Cash';
            $balance = (float)($input['balance'] ?? 0);
            $currency = $input['currency'] ?? 'TWD';

            if (empty($name)) {
                $response = ['status' => 'error', 'message' => '帳戶名稱不能為空'];
                break;
            }

            $success = $assetService->upsertAccountBalance($dbUserId, $name, $balance, $type, $currency);

            if ($success) {
                $response = ['status' => 'success', 'message' => '帳戶儲存成功'];
            } else {
                $response = ['status' => 'error', 'message' => '儲存失敗'];
            }
            break;

        case 'monthly_expense_breakdown':
            $totalExpense = $transactionService->getTotalExpenseByMonth($dbUserId); 
            $totalIncome = $transactionService->getTotalIncomeByMonth($dbUserId);
            $expenseBreakdown = $transactionService->getMonthlyBreakdown($dbUserId, 'expense'); 
            $incomeBreakdown = $transactionService->getMonthlyBreakdown($dbUserId, 'income');

            $response = [
                'status' => 'success', 
                'data' => [
                    'total_expense' => $totalExpense,
                    'total_income' => $totalIncome,
                    'breakdown' => $expenseBreakdown,
                    'income_breakdown' => $incomeBreakdown
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

            $amount = filter_var($data['amount'] ?? 0, FILTER_VALIDATE_FLOAT);
            $type = $data['type'] ?? '';
            
            if ($amount === false || $amount <= 0 || !in_array($type, ['income', 'expense'])) {
                $response = ['status' => 'error', 'message' => '無效的金額或類型。'];
                http_response_code(400);
                break;
            }

            $success = $transactionService->addTransaction($dbUserId, $data);

            if ($success) {
                $response = ['status' => 'success', 'message' => '交易新增成功！'];
            } else {
                $response = ['status' => 'error', 'message' => '交易新增失敗'];
            }
            break;
        
        case 'analyze_portfolio':
            // 🔴 1. 權限檢查
            $isPremium = $userService->isPremium($dbUserId);
            
            if (!$isPremium) {
                // 免費會員檢查用量
                $limit = defined('LIMIT_HEALTH_CHECK_MONTHLY') ? LIMIT_HEALTH_CHECK_MONTHLY : 2;
                $monthlyUsage = $userService->getMonthlyHealthCheckUsage($dbUserId);
                
                if ($monthlyUsage >= $limit) {
                    $response = [
                        'status' => 'error', 
                        'message' => "🔒 免費版每月僅限 {$limit} 次 AI 健檢。\n請升級會員以解鎖無限次數。"
                    ];
                    break; // 中斷執行
                }
            }

            // 2. 執行分析
            $assetData = $assetService->getNetWorthSummary($dbUserId);
            $monthlyIncome = $transactionService->getTotalIncomeByMonth($dbUserId);
            $monthlyExpense = $transactionService->getTotalExpenseByMonth($dbUserId);
            
            $analysisData = [
                'assets' => $assetData,
                'flow' => [
                    'income' => $monthlyIncome,
                    'expense' => $monthlyExpense
                ]
            ];

            $geminiService = new GeminiService();
            $analysisText = $geminiService->analyzePortfolio($analysisData);
            
            // 🔴 3. 成功後記錄使用量
            $userService->logApiUsage($dbUserId, 'health_check');

            $response = ['status' => 'success', 'data' => $analysisText];
            break;
        
        case 'trend_data':
            $defaultStart = date('Y-m-01', strtotime('-1 year'));
            $defaultEnd = date('Y-m-t');

            $start = $_GET['start'] ?? $defaultStart;
            $end = $_GET['end'] ?? $defaultEnd;
            $mode = $_GET['mode'] ?? 'total';

            // 🟢 限制邏輯：免費版強制鎖定日期範圍
            $isPremium = $userService->isPremium($dbUserId);
            
            if (!$isPremium) {
                // 免費版：最早只能查到 "3個月前" 的 1 號
                $freeLimitDate = date('Y-m-01', strtotime('-3 months'));
                
                // 如果用戶請求的開始時間 "早於" 限制時間，強制覆寫
                if ($start < $freeLimitDate) {
                    $start = $freeLimitDate;
                }
            }

            if ($mode === 'category') {
                $trendData = $transactionService->getCategoryTrendData($dbUserId, $start, $end);
            } else {
                $trendData = $transactionService->getTrendData($dbUserId, $start, $end);
            }
            
            $response = ['status' => 'success', 'data' => $trendData];
            break;

        case 'get_transactions':
            $month = $_GET['month'] ?? date('Y-m'); 
            $list = $transactionService->getTransactions($dbUserId, $month);
            $response = ['status' => 'success', 'data' => $list];
            break;

        case 'delete_transaction':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405); break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $id = (int)($input['id'] ?? 0);
            
            if ($transactionService->deleteTransaction($dbUserId, $id)) {
                $response = ['status' => 'success', 'message' => '刪除成功'];
            } else {
                $response = ['status' => 'error', 'message' => '刪除失敗'];
            }
            break;

        case 'update_transaction':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405); break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $id = (int)($input['id'] ?? 0);
            
            if ($transactionService->updateTransaction($dbUserId, $id, $input)) {
                $response = ['status' => 'success', 'message' => '更新成功'];
            } else {
                $response = ['status' => 'error', 'message' => '更新失敗'];
            }
            break;
        
        case 'create_crypto_order':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405); break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $email = trim($input['email'] ?? '');
            
            // 1. 基本驗證
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response = ['status' => 'error', 'message' => 'Email 格式不正確'];
                break;
            }

            // 2. 檢查 API Key (從 config.php / .env 載入)
            $apiKey = defined('NOWPAYMENTS_API_KEY') ? NOWPAYMENTS_API_KEY : getenv('NOWPAYMENTS_API_KEY');
            if (!$apiKey) {
                error_log("❌ Error: NOWPAYMENTS_API_KEY not defined.");
                $response = ['status' => 'error', 'message' => '系統配置錯誤 (Missing API Key)'];
                break;
            }

            // 3. 準備訂單參數
            // 產生唯一訂單編號，避免重複
            $orderId = 'PREMIUM_' . $dbUserId . '_' . time();
            
            // 設定 Webhook 回調網址 (請確認此網域是否正確指向您的伺服器)
            // 這裡假設您的網域與 LIFF_DASHBOARD_URL 相同網域，或您可以直接寫死 'https://finbot.tw/crypto_webhook.php'
            $domain = 'https://finbot.tw'; // 🔴 請確認此網域
            $webhookUrl = $domain . '/crypto_webhook.php';
            $returnUrl = defined('LIFF_DASHBOARD_URL') ? LIFF_DASHBOARD_URL : 'https://line.me/';

            $payload = [
                'price_amount' => 3,        // 固定價格 3 USD
                'price_currency' => 'usd',  // 計價單位
                // 'pay_currency' => 'usdttrc20', // 可選：若不指定，使用者可在頁面上自選幣種 (推薦不指定)
                'order_id' => $orderId,
                'order_description' => $email, // 🔥 關鍵：將 Email 塞入訂單描述，Webhook 會回傳此欄位
                'ipn_callback_url' => $webhookUrl,
                'success_url' => $returnUrl,
                'cancel_url' => $returnUrl
            ];

            // 4. 呼叫 NOWPayments Create Invoice API
            $ch = curl_init('https://api.nowpayments.io/v1/invoice');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'x-api-key: ' . $apiKey,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $apiResponse = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $result = json_decode($apiResponse, true);

            // 5. 處理回應
            if ($httpCode === 200 && isset($result['invoice_url'])) {
                $response = [
                    'status' => 'success', 
                    'data' => [
                        'invoice_url' => $result['invoice_url'],
                        'id' => $result['id']
                    ]
                ];
            } else {
                error_log("❌ NOWPayments API Error: " . $apiResponse);
                $response = ['status' => 'error', 'message' => '建立加密貨幣訂單失敗，請稍後再試'];
            }
            break;
        case 'link_bmc':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405); break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $email = trim($input['email'] ?? '');
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response = ['status' => 'error', 'message' => 'Email 格式不正確'];
                break;
            }

            if ($userService->linkBmcEmail($dbUserId, $email)) {
                $response = ['status' => 'success', 'message' => '綁定成功，請前往付款'];
            } else {
                $response = ['status' => 'error', 'message' => '綁定失敗'];
            }
            break;
        // 🟢 1. 獲取加密貨幣儀表板數據
        case 'get_crypto_summary':
            $cryptoService = new CryptoService();
            $data = $cryptoService->getDashboardData($dbUserId);
            $response = ['status' => 'success', 'data' => $data];
            break;

        // 🟢 2. 新增加密貨幣交易流水
        case 'add_crypto_transaction':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                $response = ['status' => 'error', 'message' => 'Method not allowed'];
                break;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $cryptoService = new CryptoService();
            
            if ($cryptoService->addTransaction($dbUserId, $input)) {
                $response = ['status' => 'success', 'message' => '交易紀錄已新增'];
            } else {
                $response = ['status' => 'error', 'message' => '新增失敗，請檢查欄位'];
            }
            break;

        default:
            $response = ['status' => 'error', 'message' => 'Invalid action.'];
            break;
    }

} catch (Throwable $e) {
    error_log("API Error: " . $e->getMessage());
    $response = ['status' => 'error', 'message' => 'Server error occurred: ' . $e->getMessage()];
    http_response_code(500);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;