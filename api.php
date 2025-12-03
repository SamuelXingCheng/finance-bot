<?php
// api.php
// 設置回應標頭為 JSON 格式
header('Content-Type: application/json; charset=utf-8');
// 根據您的 LIFF 配置，您可能需要新增您的自訂域名
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
require_once 'src/GeminiService.php';

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
            
            $response = ['status' => 'success', 'data' => $analysisText];
            break;
        
        case 'trend_data':
            $defaultStart = date('Y-m-01', strtotime('-1 year'));
            $defaultEnd = date('Y-m-t');

            $start = $_GET['start'] ?? $defaultStart;
            $end = $_GET['end'] ?? $defaultEnd;
            $mode = $_GET['mode'] ?? 'total';

            if ($mode === 'category') {
                $trendData = $transactionService->getCategoryTrendData($dbUserId, $start, $end);
            } else {
                $trendData = $transactionService->getTrendData($dbUserId, $start, $end);
            }
            
            $response = ['status' => 'success', 'data' => $trendData];
            break;

        // ==========================================
        // 🌟 新增：交易列表 CRUD 路由
        // ==========================================
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
        
        // 🌟 新增：綁定 BMC Email
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