<?php
// api.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); 
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
// 🟢 [修正] 加入 X-Auth-Provider 以允許前端傳送此 Header
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Provider');

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

/**
 * 新增：Google Token 驗證函式 (使用 CURL)
 */
function verifyGoogleIdToken($idToken) {
    // Google Token Info API
    $url = "https://oauth2.googleapis.com/tokeninfo?id_token=" . $idToken;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // 若在本地開發遇到 SSL 錯誤，可暫時開啟下行，但正式環境建議關閉
    // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $rawResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($rawResponse, true);

    // 檢查 HTTP 狀態碼與 aud 是否匹配
    if ($httpCode === 200 && isset($data['aud']) && $data['aud'] === GOOGLE_CLIENT_ID && isset($data['sub'])) {
        return $data; // 回傳包含 sub, email, name, picture 的陣列
    }
    
    error_log("Google Token Verification Failed. Response: " . $rawResponse);
    return null;
}

try {
    // ----------------------------------------------------
    // 2. 統一身份驗證 (支援 LINE 與 Google)
    // ----------------------------------------------------
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    // 讀取前端傳來的 Provider，預設為 line
    $authProvider = $_SERVER['HTTP_X_AUTH_PROVIDER'] ?? 'line'; 
    $dbUserId = 0;

    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $token = $matches[1];

        if ($authProvider === 'google') {
            // --- Google 登入流程 ---
            $payload = verifyGoogleIdToken($token);
            
            if ($payload) {
                $userService = new UserService();
                // 使用 Google ID 查找或建立用戶
                $dbUserId = $userService->findOrCreateUserByGoogle(
                    $payload['sub'], 
                    $payload['email'] ?? ''
                );
            } else {
                http_response_code(401);
                echo json_encode(['status' => 'error', 'message' => 'Invalid Google Token'], JSON_UNESCAPED_UNICODE);
                exit;
            }

        } else {
            // --- LINE 登入流程 ---
            // 🟢 [修正] 這裡原本錯誤使用了 $idToken，已修正為 $token
            $lineUserId = verifyLineIdToken($token);

            if (!$lineUserId) {
                http_response_code(401);
                echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Invalid ID Token.'], JSON_UNESCAPED_UNICODE); 
                exit;
            }

            $userService = new UserService();
            $dbUserId = $userService->findOrCreateUser($lineUserId);
        }

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
                $targetLedgerId = isset($_GET['ledger_id']) ? (int)$_GET['ledger_id'] : null;
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
                $targetLedgerId = isset($_GET['ledger_id']) ? (int)$_GET['ledger_id'] : null;
                $historyData = $assetService->getAssetHistory($dbUserId, $range, $targetLedgerId);
                
                $historyData['debug_info'] = [
                    'resolved_user_id' => $dbUserId,
                    'ledger_id' => $targetLedgerId, 
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
                
                if ($transactionService->addTransaction($dbUserId, $input)) {
                    $response = ['status' => 'success', 'message' => '交易新增成功'];
                } else {
                    $response = ['status' => 'error', 'message' => '交易新增失敗'];
                }
                break;
            
            case 'analyze_portfolio':
                $isPremium = $userService->isPremium($dbUserId);
                
                if (!$isPremium) {
                    $limit = defined('LIMIT_HEALTH_CHECK_MONTHLY') ? LIMIT_HEALTH_CHECK_MONTHLY : 2;
                    $monthlyUsage = $userService->getMonthlyHealthCheckUsage($dbUserId);
                    
                    if ($monthlyUsage >= $limit) {
                        $response = [
                            'status' => 'error', 
                            'message' => "🔒 免費版每月僅限 {$limit} 次 AI 健檢。\n請升級會員以解鎖無限次數。"
                        ];
                        break; 
                    }
                }

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
                
                $userService->logApiUsage($dbUserId, 'health_check');

                $response = ['status' => 'success', 'data' => $analysisText];
                break;
            
            case 'trend_data':
                $defaultStart = date('Y-m-01', strtotime('-1 year'));
                $defaultEnd = date('Y-m-t');
                $start = $_GET['start'] ?? $defaultStart;
                $end = $_GET['end'] ?? $defaultEnd;
                $mode = $_GET['mode'] ?? 'total';
                
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
                $targetLedgerId = isset($_GET['ledger_id']) ? (int)$_GET['ledger_id'] : null;
                
                if (!$targetLedgerId) {
                    $response = ['status' => 'error', 'message' => '未指定帳本'];
                    break;
                }
        
                try {
                    $token = $ledgerService->createInvitation($dbUserId, $targetLedgerId);
                    
                    $liffBase = defined('LIFF_DASHBOARD_URL') ? LIFF_DASHBOARD_URL : 'https://liff.line.me/YOUR_LIFF_ID';
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
                
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $response = ['status' => 'error', 'message' => 'Email 格式不正確'];
                    break;
                }

                $apiKey = defined('NOWPAYMENTS_API_KEY') ? NOWPAYMENTS_API_KEY : getenv('NOWPAYMENTS_API_KEY');
                if (!$apiKey) {
                    error_log("❌ Error: NOWPAYMENTS_API_KEY not defined.");
                    $response = ['status' => 'error', 'message' => '系統配置錯誤 (Missing API Key)'];
                    break;
                }

                $orderId = 'PREMIUM_' . $dbUserId . '_' . time();
                $domain = 'https://finbot.tw'; 
                $webhookUrl = $domain . '/crypto_webhook.php';
                $returnUrl = defined('LIFF_DASHBOARD_URL') ? LIFF_DASHBOARD_URL : 'https://line.me/';

                $payload = [
                    'price_amount' => 3,
                    'price_currency' => 'usd',
                    'order_id' => $orderId,
                    'order_description' => $email,
                    'ipn_callback_url' => $webhookUrl,
                    'success_url' => $returnUrl,
                    'cancel_url' => $returnUrl
                ];

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

            case 'get_crypto_summary':
                $cryptoService = new CryptoService();
                $data = $cryptoService->getDashboardData($dbUserId);
                $response = ['status' => 'success', 'data' => $data];
                break;

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

            case 'adjust_crypto_balance':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    http_response_code(405); break;
                }
                $input = json_decode(file_get_contents('php://input'), true);
                $symbol = $input['symbol'] ?? '';
                $newBalance = $input['new_balance'] ?? null;
                $date = $input['date'] ?? date('Y-m-d H:i:s'); 

                if (empty($symbol) || $newBalance === null) {
                    $response = ['status' => 'error', 'message' => '參數錯誤'];
                    break;
                }

                $cryptoService = new CryptoService();
                if ($cryptoService->adjustBalance($dbUserId, $symbol, (float)$newBalance, $date)) {
                    $response = ['status' => 'success', 'message' => '快照已更新'];
                } else {
                    $response = ['status' => 'error', 'message' => '更新失敗'];
                }
                break;

            case 'get_crypto_history':
                $range = isset($_GET['range']) ? $_GET['range'] : '1y';
                
                try {
                    $cryptoService = new CryptoService();
                    $chartData = $cryptoService->getHistoryChartData($dbUserId, $range);
                    $response = ['status' => 'success', 'data' => $chartData];
                } catch (Exception $e) {
                    error_log("Get Crypto History Error: " . $e->getMessage());
                    $response = [
                        'status' => 'success', 
                        'data' => ['labels' => [], 'data' => []]
                    ];
                }
                break;
            
            case 'delete_crypto_transaction':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    http_response_code(405); break;
                }
                $input = json_decode(file_get_contents('php://input'), true);
                $id = (int)($input['id'] ?? 0);
                
                $cryptoService = new CryptoService();
                if ($cryptoService->deleteTransaction($dbUserId, $id)) {
                    $response = ['status' => 'success', 'message' => '刪除成功'];
                } else {
                    $response = ['status' => 'error', 'message' => '刪除失敗'];
                }
                break;

            case 'analyze_file':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); break; }
        
                // 1. 檔案處理
                if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                    $code = isset($_FILES['file']) ? $_FILES['file']['error'] : 'No File';
                    $response = ['status' => 'error', 'message' => '檔案上傳失敗 (錯誤代碼: ' . $code . ')'];
                    break;
                }
                
                // --- 目錄檢查與檔名產生 ---
                $tempDir = __DIR__ . '/temp';
                if (!is_dir($tempDir)) {
                    mkdir($tempDir, 0777, true);
                }

                $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
                if (empty($ext)) $ext = 'jpg'; 
                
                $fileName = uniqid('upload_') . '.' . $ext;
                $tempPath = $tempDir . '/' . $fileName;

                if (!move_uploaded_file($_FILES['file']['tmp_name'], $tempPath)) {
                    $response = ['status' => 'error', 'message' => '系統錯誤: 無法儲存暫存檔'];
                    break;
                }
        
                // 2. 核心分流
                $mode = $_POST['mode'] ?? 'general';
                $geminiService = new GeminiService();
                $resultData = [];
        
                if ($mode === 'crypto') {
                    // A. 加密貨幣模式
                    $resultData = $geminiService->parseCryptoScreenshot($tempPath);
                    $message = "Crypto 截圖辨識成功";
                } else {
                    // B. 一般記帳模式
                    $resultData = $geminiService->parseTransaction("FILE:" . $tempPath);
                    
                    // 自動寫入資料庫
                    if (!empty($resultData) && is_array($resultData)) {
                        $savedCount = 0;
                        $targetLedgerId = $_POST['ledger_id'] ?? null;

                        foreach ($resultData as $tx) {
                            if ($targetLedgerId) {
                                $tx['ledger_id'] = $targetLedgerId;
                            }
                            
                            if ($transactionService->addTransaction($dbUserId, $tx)) {
                                $savedCount++;
                            }
                        }
                        $message = "單據辨識成功，已自動新增 {$savedCount} 筆紀錄";
                    } else {
                        $message = "單據辨識完成，但無有效資料";
                    }
                }
        
                unlink($tempPath); 
        
                if ($resultData) {
                    $response = [
                        'status' => 'success',
                        'message' => $message,
                        'data' => $resultData,
                        'mode' => $mode
                    ];
                } else {
                    $response = ['status' => 'error', 'message' => 'AI 無法辨識內容'];
                }
                break;
                
            case 'update_crypto_transaction':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    http_response_code(405); break;
                }
                $input = json_decode(file_get_contents('php://input'), true);
                $id = (int)($input['id'] ?? 0);
                
                $cryptoService = new CryptoService();
                if ($cryptoService->updateTransaction($dbUserId, $id, $input)) {
                    $response = ['status' => 'success', 'message' => '更新成功'];
                } else {
                    $response = ['status' => 'error', 'message' => '更新失敗'];
                }
                break;

            case 'get_user_status':
                $status = $userService->getUserStatus($dbUserId);
                $response = ['status' => 'success', 'data' => $status];
                break;

            case 'submit_onboarding':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    http_response_code(405); 
                    $response = ['status' => 'error', 'message' => 'Method not allowed'];
                    break;
                }
                $input = json_decode(file_get_contents('php://input'), true);
                
                $userService->updateUserProfile($dbUserId, [
                    'financial_goal' => $input['goal'] ?? '',
                    'monthly_budget' => $input['budget'] ?? 0,
                    'reminder_time'  => $input['reminder_time'] ?? null
                ]);

                $userService->activateTrial($dbUserId, 7);

                $response = ['status' => 'success', 'message' => '歡迎加入 FinBot！試用已開通。'];
                break;
            
            case 'get_ledgers':
                $ledgers = $ledgerService->getUserLedgers($dbUserId);
                $response = ['status' => 'success', 'data' => $ledgers];
                break;

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

            case 'save_account':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); break; }
                $input = json_decode(file_get_contents('php://input'), true);
                
                $name = trim($input['name'] ?? '');
                $type = $input['type'] ?? 'Cash';
                $balance = (float)($input['balance'] ?? 0);
                $currency = $input['currency'] ?? 'TWD';
                $date = $input['date'] ?? date('Y-m-d'); 
                $ledgerId = isset($input['ledger_id']) ? (int)$input['ledger_id'] : null;
                $customRate = isset($input['custom_rate']) && $input['custom_rate'] !== '' ? (float)$input['custom_rate'] : null;
            
                // 🟢 新增：從 API 輸入中獲取標的與數量
                $symbol = !empty($input['symbol']) ? strtoupper(trim($input['symbol'])) : null;

                if ($symbol !== null) {
                    // 🟢 如果代碼以數字開頭且沒點號，儲存時自動補上 .TW
                    // 這樣可以同時處理 2330 -> 2330.TW 和 00631L -> 00631L.TW
                    if (preg_match('/^\d/', $symbol) && strpos($symbol, '.') === false) {
                        $symbol .= '.TW';
                    }
                }
                $quantity = isset($input['quantity']) && $input['quantity'] !== '' ? (float)$input['quantity'] : null;
            
                if (empty($name)) {
                    $response = ['status' => 'error', 'message' => '帳戶名稱不能為空'];
                    break;
                }
            
                // 🟢 呼叫更新後的 Service 方法
                $success = $assetService->upsertAccountBalance(
                    $dbUserId, $name, $balance, $type, $currency, $date, $ledgerId, $customRate, $symbol, $quantity
                );
            
                if ($success) {
                    $response = ['status' => 'success', 'message' => '帳戶資料已儲存'];
                } else {
                    $response = ['status' => 'error', 'message' => '儲存失敗'];
                }
                break;
            
            case 'get_subscriptions':
                $targetLedgerId = isset($_GET['ledger_id']) ? (int)$_GET['ledger_id'] : null;
                $rules = $transactionService->getRecurringRules($dbUserId, $targetLedgerId);
                $response = ['status' => 'success', 'data' => $rules];
                break;

            case 'add_subscription':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); break; }
                $input = json_decode(file_get_contents('php://input'), true);
                
                $targetLedgerId = isset($_GET['ledger_id']) ? (int)$_GET['ledger_id'] : ($input['ledger_id'] ?? null);
                $input['ledger_id'] = $targetLedgerId;

                if ($transactionService->addRecurringRule($dbUserId, $input)) {
                    $response = ['status' => 'success', 'message' => '訂閱已設定'];
                } else {
                    $response = ['status' => 'error', 'message' => '設定失敗'];
                }
                break;

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

            case 'check_recurring':
                $count = $transactionService->processRecurring($dbUserId);
                $response = ['status' => 'success', 'processed_count' => $count];
                break;

            case 'update_crypto_target':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); break; }
                $input = json_decode(file_get_contents('php://input'), true);
                $ratio = isset($input['ratio']) ? (float)$input['ratio'] : null;

                if ($ratio === null || $ratio < 0 || $ratio > 100) {
                    $response = ['status' => 'error', 'message' => '比例必須在 0 ~ 100 之間'];
                    break;
                }

                try {
                    $conn = $db->getConnection(); 
                    $stmt = $conn->prepare("UPDATE users SET target_usdt_ratio = ? WHERE id = ?");
                    $stmt->execute([$ratio, $dbUserId]);
                    $response = ['status' => 'success', 'message' => '目標比例已更新'];
                } catch (Exception $e) {
                    error_log("Update Target Error: " . $e->getMessage());
                    $response = ['status' => 'error', 'message' => '更新失敗'];
                }
                break;

            case 'get_crypto_transactions':
                $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
                
                try {
                    $conn = $db->getConnection();
                    $sql = "SELECT * FROM crypto_transactions 
                            WHERE user_id = :uid 
                            ORDER BY transaction_date DESC, id DESC 
                            LIMIT :limit";
                            
                    $stmt = $conn->prepare($sql);
                    $stmt->bindValue(':uid', $dbUserId, PDO::PARAM_INT);
                    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                    $stmt->execute();
                    
                    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $response = ['status' => 'success', 'data' => $list];
                } catch (Exception $e) {
                    error_log("Get Crypto Tx Error: " . $e->getMessage());
                    $response = ['status' => 'error', 'message' => '讀取失敗'];
                }
                break;
                
            case 'get_rebalancing_advice':
                $cryptoService = new CryptoService();
                $advice = $cryptoService->getRebalancingAdvice($dbUserId);
                $response = ['status' => 'success', 'data' => $advice];
                break;

            case 'import_crypto_csv':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); break; }
                
                if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                    $errorMsg = isset($_FILES['file']) ? ('Code: ' . $_FILES['file']['error']) : 'Empty File';
                    $response = ['status' => 'error', 'message' => '檔案上傳失敗 (' . $errorMsg . ')'];
                    break;
                }
        
                $filePath = $_FILES['file']['tmp_name'];
        
                // 讀取前 5 行
                $csvSnippet = "";
                $handle = fopen($filePath, "r");
                $lineCount = 0;
                if ($handle) {
                    $bom = fread($handle, 3);
                    if ($bom !== "\xEF\xBB\xBF") {
                        rewind($handle); 
                    }
                    
                    while (($row = fgetcsv($handle)) !== false && $lineCount < 5) {
                        $csvSnippet .= implode(",", $row) . "\n";
                        $lineCount++;
                    }
                    fclose($handle);
                }
        
                $geminiService = new GeminiService();
                $mappingRule = $geminiService->generateCsvMapping($csvSnippet);
        
                if (!$mappingRule) {
                    $response = ['status' => 'error', 'message' => 'AI 無法識別此 CSV 格式'];
                    break;
                }
        
                $cryptoService = new CryptoService();
                $result = $cryptoService->processCsvBulk($dbUserId, $filePath, $mappingRule);
        
                $response = [
                    'status' => 'success',
                    'data' => [
                        'count' => $result['count'],
                        'exchange_guess' => $mappingRule['exchange_name'] ?? 'Unknown'
                    ]
                ];
                break;

                // 🟢 [新增] 更新用戶設定 (預算、提醒時間)
            case 'update_settings':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    http_response_code(405); break;
                }
                $input = json_decode(file_get_contents('php://input'), true);
                
                // 呼叫原本的 updateUserProfile (它只更新欄位，不會重置試用)
                $success = $userService->updateUserProfile($dbUserId, [
                    'monthly_budget' => $input['budget'] ?? 0,
                    'reminder_time'  => $input['reminder_time'] ?? null
                ]);

                if ($success) {
                    $response = ['status' => 'success', 'message' => '設定已更新'];
                } else {
                    $response = ['status' => 'error', 'message' => '更新失敗'];
                }
                break;
            // 🟢 [新增] 綁定 LINE 帳號
            case 'link_line':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    http_response_code(405); break;
                }
                $input = json_decode(file_get_contents('php://input'), true);
                $lineToken = $input['line_token'] ?? '';

                if (empty($lineToken)) {
                    $response = ['status' => 'error', 'message' => '缺少 LINE Token'];
                    break;
                }

                // 1. 驗證 LINE Token
                $lineUserId = verifyLineIdToken($lineToken);
                if (!$lineUserId) {
                    $response = ['status' => 'error', 'message' => 'LINE Token 無效或過期'];
                    break;
                }

                // 2. 檢查是否已被佔用
                if ($userService->isLineIdTaken($lineUserId, $dbUserId)) {
                    $response = ['status' => 'error', 'message' => '此 LINE 帳號已綁定其他 FinBot 帳號，無法重複綁定。'];
                    break;
                }

                // 3. 執行綁定
                if ($userService->linkLineUser($dbUserId, $lineUserId)) {
                    $response = ['status' => 'success', 'message' => 'LINE 帳號綁定成功！'];
                } else {
                    $response = ['status' => 'error', 'message' => '綁定失敗'];
                }
                break;
            case 'financial_snapshot':
                // 1. 取得流動資產 (作為頭期款參考)
                // 排除房產，只算 現金(cash), 股票(stock), 加密貨幣(crypto)
                $assets = $assetService->getAssets($dbUserId);
                $liquidAssets = 0;
                
                foreach ($assets as $asset) {
                    if (in_array($asset['type'], ['cash', 'stock', 'crypto'])) {
                        $liquidAssets += $asset['value_twd'] ?? 0;
                    }
                }

                // 2. 計算月平均結餘 (作為負擔能力參考)
                // 取過去 6 個月
                $monthlyStats = $transactionService->getMonthlyStats($dbUserId, 6);
                $avgSavings = 0;
                $avgIncome = 0;
                
                if (!empty($monthlyStats)) {
                    $totalIncome = 0;
                    $totalExpense = 0;
                    foreach ($monthlyStats as $stat) {
                        $totalIncome += $stat['income'];
                        $totalExpense += $stat['expense'];
                    }
                    $months = count($monthlyStats);
                    if ($months > 0) {
                        $avgSavings = ($totalIncome - $totalExpense) / $months;
                        $avgIncome = $totalIncome / $months;
                    }
                }

                $response = [
                    'status' => 'success', 
                    'data' => [
                        'liquid_assets' => round($liquidAssets),
                        'avg_monthly_savings' => round($avgSavings),
                        'avg_monthly_income' => round($avgIncome)
                    ]
                ];
                break;

            default:
                $response = ['status' => 'error', 'message' => 'Invalid action.'];
                break;
        }

    } else {
        http_response_code(401);
        $response = ['status' => 'error', 'message' => 'Unauthorized'];
    }

} catch (Throwable $e) {
    error_log("API Error: " . $e->getMessage());
    $response = ['status' => 'error', 'message' => 'Server error occurred: ' . $e->getMessage()];
    http_response_code(500);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;