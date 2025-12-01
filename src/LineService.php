<?php
// src/LineService.php
require_once __DIR__ . '/../config.php';

class LineService {
    private $channelAccessToken;

    public function __construct() {
        $this->channelAccessToken = LINE_CHANNEL_ACCESS_TOKEN;
    }

    /**
     * 回應單筆或多筆訊息給 LINE 使用者 (使用 replyToken)。
     */
    public function replyMessage(string $replyToken, $text): void {
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
     * 這用於後台 Worker 完成任務後的主動通知。
     */
    public function pushMessage(string $userId, $text): bool {
        $messages = [];
        
        if (!is_array($text)) {
            $messages[] = ['type' => 'text', 'text' => $text];
        } else {
            foreach ($text as $t) {
                $messages[] = ['type' => 'text', 'text' => $t];
            }
        }

        $postData = [
            'to' => $userId, // 注意：這裡使用 userId 而不是 replyToken
            'messages' => $messages,
        ];

        $ch = curl_init('https://api.line.me/v2/bot/message/push'); // 注意：Push API 的端點不同
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
        $postData = [
            'replyToken' => $replyToken,
            'messages' => [
                [
                    'type' => 'flex',
                    'altText' => $altText, // 在聊天列表顯示的簡短文字
                    'contents' => $contents // Flex JSON 結構
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
     * @param string $userId 接收者的 Line User ID
     * @param string $altText 訊息替代文字
     * @param array $contents Flex Bubble 結構
     * @return bool 推送是否成功
     */
    public function pushFlexMessage(string $userId, string $altText, array $contents): bool {
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

        $ch = curl_init('https://api.line.me/v2/bot/message/push'); // 使用 PUSH 端點
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