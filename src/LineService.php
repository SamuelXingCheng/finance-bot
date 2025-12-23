<?php
// src/LineService.php
require_once __DIR__ . '/../config.php';

class LineService {
    private $channelAccessToken;

    public function __construct() {
        // <<< 修正點：使用新的 Messaging API Access Token 常數 >>>
        if (!defined('LINE_BOT_ACCESS_TOKEN')) {
            error_log("FATAL: LINE_BOT_ACCESS_TOKEN is not defined in config.");
            // 拋出例外，避免服務初始化失敗
            throw new Exception("LineService configuration error: Missing LINE_BOT_ACCESS_TOKEN."); 
        }
        $this->channelAccessToken = LINE_BOT_ACCESS_TOKEN;
    }

    /**
     * 回應單筆或多筆訊息給 LINE 使用者 (使用 replyToken)。
     */
    public function replyMessage(string $replyToken, $text): void {
        // ... (內容保持不變，已在先前步驟提供)
        $messages = [];
        
        if (!is_array($text)) {
            $messages[] = ['type' => 'text', 'text' => $text];
        } else {
            foreach ($text as $t) {
                $messages[] = ['type' => 'text', 'text' => $t];
            }
        }

        $postData = [
            'replyToken' => $replyToken,
            'messages' => $messages,
        ];

        $ch = curl_init('https://api.line.me/v2/bot/message/reply');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->channelAccessToken,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("LINE API Reply Error: HTTP $httpCode, Response: $response");
        }
    }

    /**
     * [新功能] 智慧回覆 (Smart Reply)
     * 優先嘗試 Reply，若 Token 過期 (HTTP 400) 則自動轉為 Push
     */
    public function smartReply(string $replyToken, string $userId, array $messages) {
        // 1. 嘗試 Reply
        $data = [
            'replyToken' => $replyToken,
            'messages' => $messages
        ];
        
        $ch = curl_init('https://api.line.me/v2/bot/message/reply');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->channelAccessToken
        ]);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 2. 失敗處理：如果是 Token 無效 (400)，改用 Push
        if ($httpCode === 400) {
            $response = json_decode($result, true);
            // 只要是 400 錯誤，通常都是 Token 問題 (Invalid reply token)，直接轉 Push 最保險
            error_log("⚠️ Reply Token 失效 (User: $userId)，自動轉為 Push 推播補救。");
            
            // 呼叫上方升級過的 pushMessage
            return $this->pushMessage($userId, $messages);
        }
        
        return $result;
    }

    /**
     * 【新增】主動推送訊息給 LINE 使用者 (使用 userId)。
     */
    /**
     * [升級版] 主動推播
     * 修正：能夠自動判斷傳入的是「純文字」還是「已格式化的訊息物件 (Flex/Image...)」
     */
    public function pushMessage(string $userId, $content): bool {
        $messages = [];

        // 🟢 判斷邏輯 A：如果傳入的是已經格式化好的訊息陣列 (例如從 smartReply 來的 Flex Message)
        // 特徵：是陣列，且第一個元素裡面有 'type' 欄位
        if (is_array($content) && isset($content[0]['type'])) {
            $messages = $content;
        }
        // 🟢 判斷邏輯 B：如果是單純的字串 -> 包裝成文字訊息
        else if (!is_array($content)) {
            $messages[] = ['type' => 'text', 'text' => (string)$content];
        }
        // 🟢 判斷邏輯 C：如果是字串陣列 (舊有邏輯) -> 全部包成文字訊息
        else {
            foreach ($content as $msg) {
                if (is_string($msg)) {
                    $messages[] = ['type' => 'text', 'text' => $msg];
                }
            }
        }

        // 防呆：如果沒東西就不送
        if (empty($messages)) return false;

        $postData = [
            'to' => $userId,
            'messages' => $messages,
        ];

        $ch = curl_init('https://api.line.me/v2/bot/message/push');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->channelAccessToken,
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("LINE API Push Error: HTTP $httpCode, Response: $result");
            return false;
        }

        return true;
    }

    /**
     * 【新增】發送 Flex Message (複雜排版訊息)
     */
    public function replyFlexMessage(string $replyToken, string $altText, array $contents): void {
        // ... (內容保持不變，已在先前步驟提供)
        $postData = [
            'replyToken' => $replyToken,
            'messages' => [
                [
                    'type' => 'flex',
                    'altText' => $altText, 
                    'contents' => $contents 
                ]
            ],
        ];

        $ch = curl_init('https://api.line.me/v2/bot/message/reply');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->channelAccessToken,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("LINE Flex Reply Error: HTTP $httpCode, Response: $response");
        }
    }
    
    /**
     * 【新增】主動推送 Flex Message
     */
    public function pushFlexMessage(string $userId, string $altText, array $contents): bool {
        // ... (內容保持不變，已在先前步驟提供)
        $postData = [
            'to' => $userId,
            'messages' => [
                [
                    'type' => 'flex',
                    'altText' => $altText,
                    'contents' => $contents
                ]
            ],
        ];

        $ch = curl_init('https://api.line.me/v2/bot/message/push'); 
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->channelAccessToken,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("LINE Flex Push Error: HTTP $httpCode, Response: $response");
            return false;
        }
        return true;
    }

    /**
     * 【新增】取得訊息內容 (圖片、影片、音訊)
     * @param string $messageId
     * @return string|false 二進位檔案內容
     */
    public function getMessageContent(string $messageId) {
        // LINE 取得內容的 API 端點
        $url = "https://api-data.line.me/v2/bot/message/{$messageId}/content";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->channelAccessToken,
        ]);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return $result;
        }
        
        error_log("LINE Get Content Error: HTTP $httpCode");
        return false;
    }
    
}
?>