<?php
// 設置環境
require_once 'config.php';
require_once 'src/Database.php';
require_once 'src/UserService.php';
require_once 'src/LineService.php';
require_once 'src/GeminiService.php'; // <-- 引入 Gemini 服務

// ----------------------------------------------------
// 1. 服務初始化
// ----------------------------------------------------
$db = Database::getInstance(); 
$userService = new UserService();
$lineService = new LineService();
$geminiService = new GeminiService(); // <-- 實例化 Gemini 服務

// ... (省略接收與驗證 LINE 傳送的資料) ...

// ----------------------------------------------------
// 3. 處理每一個事件 (Event)
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
            
            // --- 核心邏輯：呼叫 Gemini 進行自然語言解析 ---
            $transactionData = $geminiService->parseTransaction($text);

            if ($transactionData) {
                // 如果解析成功
                $amount = $transactionData['amount'];
                $category = $transactionData['category'];
                $type = $transactionData['type'];

                $replyText = "💰 AI 解析成功！\n類型: {$type}\n金額: {$amount}\n類別: {$category}";
                $replyText .= "\n✅ 下一步將把資料寫入資料庫。";
                
                // TODO: 5. 實作 TransactionService::addTransaction($dbUserId, $transactionData);
                
            } else {
                // 如果解析失敗或使用者輸入非記帳內容
                $replyText = "不好意思，我無法解析您的記帳內容「{$text}」。請嘗試更清晰的格式，例如：午餐 150元。";
            }
            
            $lineService->replyMessage($replyToken, $replyText);
            
        } 
        // ... (省略追蹤/加入事件邏輯)

        break; 
    }
}

// 結束請求
http_response_code(200);
echo "OK";
?>