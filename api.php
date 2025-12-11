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
require_once 'src/LedgerService.php';
require_once 'src/LedgerService.php';

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
    $ledgerService = new LedgerService();

    // ----------------------------------------------------
    // 4. API 路由與分發
    // ----------------------------------------------------
    $action = $_GET['action'] ?? '';
    $response = ['status' => 'error', 'message' => 'Invalid action.'];

    switch ($action) {
        
        case 'asset_summary':
            // [修正] 接收 ledger_id
            $targetLedgerId = isset($_GET['ledger_id']) ? (int)$_GET['ledger_id'] : null;
            
            // 傳入 ledger_id 給 Service
            $summary = $assetService->getNetWorthSummary($dbUserId, $targetLedgerId); 
            
            $summary['is_premium'] = $userService->isPremium($dbUserId);
            $response = ['status' => 'success', 'data' => $summary];
            break;

        case 'get_accounts':
            $targetLedgerId = isset($_GET['ledger_id']) ? (int)$_GET['ledger_id'] : null;
            $accounts = $assetService->getAccounts($dbUserId, $targetLedgerId);
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
        
        case 'asset_history':
            $range = $_GET['range'] ?? '1y';
            // [修正] 接收 ledger_id
            $targetLedgerId = isset($_GET['ledger_id']) ? (int)$_GET['ledger_id'] : null;
            
            // [修正] 傳入 ledgerId
            $historyData = $assetService->getAssetHistory($dbUserId, $range, $targetLedgerId);
            
            $historyData['debug_info'] = [
                'resolved_user_id' => $dbUserId,
                'ledger_id' => $targetLedgerId, // Debug 用
                'data_count' => count($historyData['labels'] ?? []),
                'server_time' => date('Y-m-d H:i:s')
            ];
            
            $response = ['status' => 'success', 'data' => $historyData];
            break;
            
        case 'monthly_expense_breakdown':
            $targetLedgerId = isset($_GET['ledger_id']) ? (int)$_GET['ledger_id'] : null;
            if ($targetLedgerId && !$ledgerService->checkAccess($dbUserId, $targetLedgerId)) {
                $response = ['status' => 'error', 'message' => '無權存取'];
                break;
            }

            $totalExpense = $transactionService->getTotalExpenseByMonth($dbUserId, $targetLedgerId); 
            $totalIncome = $transactionService->getTotalIncomeByMonth($dbUserId, $targetLedgerId);
            $expenseBreakdown = $transactionService->getMonthlyBreakdown($dbUserId, 'expense', $targetLedgerId); 
            $incomeBreakdown = $transactionService->getMonthlyBreakdown($dbUserId, 'income', $targetLedgerId);

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
                http_response_code(405); break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            
            // [修正] 確保 input 中包含 ledger_id (DashboardView 已經會傳送它了)
            // TransactionService::addTransaction 已經更新為會讀取 $input['ledger_id']

            if ($transactionService->addTransaction($dbUserId, $input)) {
                $response = ['status' => 'success', 'message' => '交易新增成功'];
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
            
            // [修正] 接收並傳遞 ledger_id
            $targetLedgerId = isset($_GET['ledger_id']) ? (int)$_GET['ledger_id'] : null;
            if ($targetLedgerId && !$ledgerService->checkAccess($dbUserId, $targetLedgerId)) {
                $response = ['status' => 'error', 'message' => '無權存取'];
                break;
            }

            if ($mode === 'category') {
                $trendData = $transactionService->getCategoryTrendData($dbUserId, $start, $end, $targetLedgerId);
            } else {
                $trendData = $transactionService->getTrendData($dbUserId, $start, $end, $targetLedgerId);
            }
            $response = ['status' => 'success', 'data' => $trendData];
            break;

        case 'get_transactions':
            $month = $_GET['month'] ?? date('Y-m'); 
            // [修正] 接收並傳遞 ledger_id
            $targetLedgerId = isset($_GET['ledger_id']) ? (int)$_GET['ledger_id'] : null;

            if ($targetLedgerId && !$ledgerService->checkAccess($dbUserId, $targetLedgerId)) {
                $response = ['status' => 'error', 'message' => '無權存取'];
                break;
            }

            $list = $transactionService->getTransactions($dbUserId, $month, $targetLedgerId);
            $response = ['status' => 'success', 'data' => $list];
            break;
        
        case 'generate_invite_link':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); break; }
            // 前端需在 URL 帶上 ?ledger_id=XXX
            $targetLedgerId = isset($_GET['ledger_id']) ? (int)$_GET['ledger_id'] : null;
            
            if (!$targetLedgerId) {
                $response = ['status' => 'error', 'message' => '未指定帳本'];
                break;
            }
    
            try {
                $token = $ledgerService->createInvitation($dbUserId, $targetLedgerId);
                
                // 組合 LIFF 連結，這裡假設你的 LIFF URL 是透過 .env 設定的
                // 格式：https://liff.line.me/{LIFF_ID}?action=join_ledger&token={TOKEN}
                $liffBase = defined('LIFF_DASHBOARD_URL') ? LIFF_DASHBOARD_URL : 'https://liff.line.me/YOUR_LIFF_ID';
                // 確保 LIFF URL 乾淨
                $liffBase = strtok($liffBase, '?'); 
                
                $inviteUrl = "{$liffBase}?action=join_ledger&token={$token}";
                
                $response = ['status' => 'success', 'data' => ['invite_url' => $inviteUrl]];
            } catch (Exception $e) {
                $response = ['status' => 'error', 'message' => $e->getMessage()];
            }
            break;
    
        case 'join_ledger':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); break; }
            $input = json_decode(file_get_contents('php://input'), true);
            $token = $input['token'] ?? '';
    
            if (empty($token)) {
                $response = ['status' => 'error', 'message' => '缺少邀請碼'];
                break;
            }
    
            try {
                $ledgerName = $ledgerService->processInvitation($dbUserId, $token);
                $response = [
                    'status' => 'success', 
                    'message' => "成功加入帳本", 
                    'data' => ['ledger_name' => $ledgerName]
                ];
            } catch (Exception $e) {
                $response = ['status' => 'error', 'message' => $e->getMessage()];
            }
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
        
        case 'get_account_history':
            $accountName = $_GET['name'] ?? '';
            if (empty($accountName)) {
                $response = ['status' => 'error', 'message' => '缺少帳戶名稱'];
                break;
            }
            $history = $assetService->getAccountSnapshots($dbUserId, $accountName);
            $response = ['status' => 'success', 'data' => $history];
            break;
        
        case 'delete_snapshot':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405); break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $accountName = $input['account_name'] ?? '';
            $snapshotDate = $input['snapshot_date'] ?? '';
            
            if (empty($accountName) || empty($snapshotDate)) {
                $response = ['status' => 'error', 'message' => '缺少帳戶名稱或快照日期'];
                break;
            }
            
            if ($assetService->deleteSnapshot($dbUserId, $accountName, $snapshotDate)) {
                $response = ['status' => 'success', 'message' => '歷史快照已刪除'];
            } else {
                $response = ['status' => 'error', 'message' => '刪除失敗'];
            }
            break;

        // 🟢 3. 校正加密貨幣餘額
        case 'adjust_crypto_balance':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405); break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $symbol = $input['symbol'] ?? '';
            $newBalance = $input['new_balance'] ?? null;
            $date = $input['date'] ?? date('Y-m-d H:i:s'); // 🟢 接收日期參數

            if (empty($symbol) || $newBalance === null) {
                $response = ['status' => 'error', 'message' => '參數錯誤'];
                break;
            }

            $cryptoService = new CryptoService();
            // 🟢 傳入 date
            if ($cryptoService->adjustBalance($dbUserId, $symbol, (float)$newBalance, $date)) {
                $response = ['status' => 'success', 'message' => '快照已更新'];
            } else {
                $response = ['status' => 'error', 'message' => '更新失敗'];
            }
            break;

        // 🟢 4. 獲取加密貨幣歷史趨勢
        case 'get_crypto_history':
            $range = $_GET['range'] ?? '1y';
            $cryptoService = new CryptoService();
            $chartData = $cryptoService->getHistoryChartData($dbUserId, $range);
            $response = ['status' => 'success', 'data' => $chartData];
            break;
        
        // 🟢 1. 新增：獲取用戶狀態 (用於前端判斷是否顯示引導頁)
        case 'get_user_status':
            // 注意：請確保 UserService.php 已新增 getUserStatus 方法
            $status = $userService->getUserStatus($dbUserId);
            $response = ['status' => 'success', 'data' => $status];
            break;

        // 🟢 2. 新增：提交引導資料並開通試用
        case 'submit_onboarding':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405); 
                $response = ['status' => 'error', 'message' => 'Method not allowed'];
                break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            
            // A. 儲存用戶偏好 (目標、預算、提醒時間)
            // 注意：請確保 UserService.php 已新增 updateUserProfile 方法
            $userService->updateUserProfile($dbUserId, [
                'financial_goal' => $input['goal'] ?? '',
                'monthly_budget' => $input['budget'] ?? 0,
                'reminder_time'  => $input['reminder_time'] ?? null
            ]);

            // B. 開通 7 天試用獎勵
            // 注意：請確保 UserService.php 已新增 activateTrial 方法
            $userService->activateTrial($dbUserId, 7);

            $response = ['status' => 'success', 'message' => '歡迎加入 FinBot！試用已開通。'];
            break;
        
        // 1. [新增] 獲取用戶的所有帳本列表
        case 'get_ledgers':
            $ledgers = $ledgerService->getUserLedgers($dbUserId);
            $response = ['status' => 'success', 'data' => $ledgers];
            break;

        // 2. [新增] 建立新帳本
        case 'create_ledger':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); break; }
            $input = json_decode(file_get_contents('php://input'), true);
            $name = trim($input['name'] ?? '');
            if (empty($name)) {
                $response = ['status' => 'error', 'message' => '請輸入帳本名稱'];
                break;
            }
            $newId = $ledgerService->createLedger($dbUserId, $name, 'shared');
            if ($newId) {
                $response = ['status' => 'success', 'message' => '帳本建立成功', 'data' => ['id' => $newId]];
            } else {
                $response = ['status' => 'error', 'message' => '建立失敗'];
            }
            break;

        // 3. [修改] 查詢交易列表 (支援 ledger_id)
        case 'get_transactions':
            $month = $_GET['month'] ?? date('Y-m');
            // 接收前端傳來的 ledger_id (如果有)
            $targetLedgerId = isset($_GET['ledger_id']) ? (int)$_GET['ledger_id'] : null;
            
            // 驗證權限：如果想查特定帳本，必須先確認是不是成員
            if ($targetLedgerId && !$ledgerService->checkAccess($dbUserId, $targetLedgerId)) {
                $response = ['status' => 'error', 'message' => '無權存取此帳本'];
                break;
            }

            // 傳入 ledger_id 給 Service
            $list = $transactionService->getTransactions($dbUserId, $month, $targetLedgerId);
            $response = ['status' => 'success', 'data' => $list];
            break;

        // 4. [修改] 查詢收支統計 (支援 ledger_id)
        case 'monthly_expense_breakdown':
            $targetLedgerId = isset($_GET['ledger_id']) ? (int)$_GET['ledger_id'] : null;
            if ($targetLedgerId && !$ledgerService->checkAccess($dbUserId, $targetLedgerId)) {
                $response = ['status' => 'error', 'message' => '無權存取'];
                break;
            }

            $totalExpense = $transactionService->getTotalExpenseByMonth($dbUserId, $targetLedgerId); 
            $totalIncome = $transactionService->getTotalIncomeByMonth($dbUserId, $targetLedgerId);
            $expenseBreakdown = $transactionService->getMonthlyBreakdown($dbUserId, 'expense', $targetLedgerId); 
            $incomeBreakdown = $transactionService->getMonthlyBreakdown($dbUserId, 'income', $targetLedgerId);

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
        
        case 'save_account':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); break; }
            // 🔍 [新增這行] 印出前端傳來的完整 JSON，看看有沒有 custom_rate
            $rawInput = file_get_contents('php://input');
            error_log("🔍 API Debug Raw Input: " . $rawInput);

            $input = json_decode(file_get_contents('php://input'), true);
            
            $name = trim($input['name'] ?? '');
            $type = $input['type'] ?? 'Cash';
            $balance = (float)($input['balance'] ?? 0);
            $currency = $input['currency'] ?? 'TWD';
            $date = $input['date'] ?? date('Y-m-d'); 
            $ledgerId = isset($input['ledger_id']) ? (int)$input['ledger_id'] : null;
            
            // 🟢 [新增] 接收 custom_rate
            $customRate = isset($input['custom_rate']) && $input['custom_rate'] !== '' ? (float)$input['custom_rate'] : null;

            if (empty($name)) {
                $response = ['status' => 'error', 'message' => '帳戶名稱不能為空'];
                break;
            }

            // 🟢 [修改] 傳入 customRate
            $success = $assetService->upsertAccountBalance($dbUserId, $name, $balance, $type, $currency, $date, $ledgerId, $customRate);

            if ($success) {
                $response = ['status' => 'success', 'message' => '帳戶快照已儲存'];
            } else {
                $response = ['status' => 'error', 'message' => '儲存失敗'];
            }
            break;
        
        case 'check_recurring':
            // 檢查是否有到期但尚未執行的週期性交易
            // 簡單邏輯：查詢 recurring_rules WHERE next_run_date <= TODAY AND is_active = 1
            // 遍歷結果，呼叫 $transactionService->addTransaction()
            // 更新 next_run_date 到下個月
            
            // (這裡為了簡潔省略詳細 SQL，建議在 TransactionService 新增 processRecurring($userId) 方法)
            $count = $transactionService->processRecurring($userId);
            $response = ['status' => 'success', 'processed_count' => $count];
            break;
        
        // 🟢 1. 獲取訂閱列表
        case 'get_subscriptions':
            $targetLedgerId = isset($_GET['ledger_id']) ? (int)$_GET['ledger_id'] : null;
            $rules = $transactionService->getRecurringRules($dbUserId, $targetLedgerId);
            $response = ['status' => 'success', 'data' => $rules];
            break;

        // 🟢 2. 新增訂閱
        case 'add_subscription':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); break; }
            $input = json_decode(file_get_contents('php://input'), true);
            
            // 若前端有傳 ledger_id，記得塞進去
            $targetLedgerId = isset($_GET['ledger_id']) ? (int)$_GET['ledger_id'] : ($input['ledger_id'] ?? null);
            $input['ledger_id'] = $targetLedgerId;

            if ($transactionService->addRecurringRule($dbUserId, $input)) {
                $response = ['status' => 'success', 'message' => '訂閱已設定'];
            } else {
                $response = ['status' => 'error', 'message' => '設定失敗'];
            }
            break;

        // 🟢 3. 刪除訂閱
        case 'delete_subscription':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); break; }
            $input = json_decode(file_get_contents('php://input'), true);
            $ruleId = (int)($input['id'] ?? 0);
            
            if ($transactionService->deleteRecurringRule($dbUserId, $ruleId)) {
                $response = ['status' => 'success', 'message' => '訂閱已刪除'];
            } else {
                $response = ['status' => 'error', 'message' => '刪除失敗'];
            }
            break;

        // 🟢 4. 觸發自動補帳 (前端於背景呼叫)
        case 'check_recurring':
            // 執行檢查與補帳
            $count = $transactionService->processRecurring($dbUserId);
            $response = ['status' => 'success', 'processed_count' => $count];
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