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
     * 【新增】主動推送訊息給 LINE 使用者 (使用 userId)。
     */
    public function pushMessage(string $userId, $text): bool {
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

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("LINE API Push Error: HTTP $httpCode, Response: $response");
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
    
}
?>