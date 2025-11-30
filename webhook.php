<?php
// 設置 PHP 錯誤顯示，用於診斷 (測試完成後應移除或設為 0)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ----------------------------------------------------
// 1. 載入服務與環境 (這裡如果發生錯誤，Bot 會靜默)
// ----------------------------------------------------
require_once 'config.php';
require_once 'src/Database.php';
require_once 'src/UserService.php';
require_once 'src/LineService.php';
require_once 'src/GeminiService.php';
require_once 'src/TransactionService.php'; // <-- 確保這個檔案已存在於 src/

// ----------------------------------------------------
// 2. 核心邏輯 Try-Catch 保護 (防止 Bot 靜默崩潰)
// ----------------------------------------------------
$replyToken = null; // 初始化變數，確保錯誤處理區塊可以存取
$lineService = null;

try {
    // ----------------------------------------------------
    // 3. 服務初始化 (必須在 try 區塊內，因為這會進行 DB 連線)
    // ----------------------------------------------------
    $db = Database::getInstance(); 
    $userService = new UserService();
    $lineService = new LineService(); // 實例化 LineService，供錯誤回覆使用
    $geminiService = new GeminiService();
    $transactionService = new TransactionService();

    // ----------------------------------------------------
    // 4. 接收與驗證 LINE 傳送的資料
    // ----------------------------------------------------
    $channelSecret = LINE_CHANNEL_SECRET;
    $httpRequestBody = file_get_contents('php://input'); 
    
    if (empty($httpRequestBody)) {
        http_response_code(200);
        exit("OK");
    }

    // 執行簽章驗證
    $hash = hash_hmac('sha256', $httpRequestBody, $channelSecret, true);
    $signature = base64_encode($hash);
    $receivedSignature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';

    if ($receivedSignature !== $signature) {
        error_log("Security Alert: Invalid LINE signature received.");
        http_response_code(200); 
        exit("OK");
    }

    $data = json_decode($httpRequestBody, true);

    // ----------------------------------------------------
    // 5. 處理每一個事件 (Event)
    // ----------------------------------------------------
    if (!empty($data['events'])) {
        foreach ($data['events'] as $event) {
            $replyToken = $event['replyToken'] ?? null;
            $lineUserId = $event['source']['userId'] ?? null;
            
            if (!$lineUserId || !$replyToken) continue;

            // 確保用戶已在資料庫中註冊
            $dbUserId = $userService->findOrCreateUser($lineUserId);
            
            // 處理文字訊息
            if ($event['type'] === 'message' && $event['message']['type'] === 'text') {
                $text = trim($event['message']['text']);
                
                // 核心邏輯：呼叫 Gemini 進行自然語言解析
                $transactionData = $geminiService->parseTransaction($text);

                if ($transactionData && !empty($transactionData['amount'])) {
                    
                    // 將資料寫入資料庫
                    $success = $transactionService->addTransaction($dbUserId, $transactionData); 
                    
                    if ($success) {
                        $currency = defined('DEFAULT_CURRENCY_SYMBOL') ? DEFAULT_CURRENCY_SYMBOL : '元';
                        $replyText = "✅ 記帳成功！\n" .
                                     "類型: " . ($transactionData['type'] == 'expense' ? '支出' : '收入') . "\n" .
                                     "金額: " . $transactionData['amount'] . " {$currency}\n" .
                                     "類別: {$transactionData['category']}\n" .
                                     "備註: " . ($transactionData['description'] ?? '無');
                                     
                        // TODO: 檢查預算警示 (BudgetService)
                    } else {
                        $replyText = "❌ 抱歉，資料庫寫入失敗。";
                    }
                    
                } else {
                    $replyText = "不好意思，我無法解析您的記帳內容「{$text}」，或內容不包含金額。";
                }
                
                $lineService->replyMessage($replyToken, $replyText);
                
            } elseif ($event['type'] === 'follow' && $replyToken) {
                 // 處理追蹤事件
                 $welcomeMessage = "歡迎使用！您的內部 ID 是 #{$dbUserId}。\n您可以直接輸入：買咖啡 80元。";
                 $lineService->replyMessage($replyToken, $welcomeMessage);
            }

            break; // 每次只處理一個事件
        }
    }

    // ----------------------------------------------------
    // 6. 成功結束
    // ----------------------------------------------------
    http_response_code(200);
    echo "OK";

} catch (Throwable $e) {
    // ----------------------------------------------------
    // 7. 錯誤處理 (在任何致命錯誤時，確保返回 200)
    // ----------------------------------------------------
    error_log("FATAL APPLICATION ERROR: " . $e->getMessage() . " on line " . $e->getLine() . " in file " . $e->getFile());
    
    // 必須返回 200 狀態碼給 LINE 平台
    http_response_code(200); 
    echo "Error processing request. Check server logs.";

    // 嘗試向用戶回覆一個錯誤訊息
    if (isset($lineService) && isset($replyToken)) {
        // 如果 LINE 服務已初始化，且 replyToken 有效，就回覆錯誤訊息
        $lineService->replyMessage($replyToken, "系統發生致命錯誤，請稍後再試或聯繫客服。錯誤代碼: #SERVER_E");
    }
}
?>