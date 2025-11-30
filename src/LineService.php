<?php
require_once __DIR__ . '/../config.php';

class LineService {
    private $channelAccessToken;

    public function __construct() {
        $this->channelAccessToken = LINE_CHANNEL_ACCESS_TOKEN;
    }

    /**
     * 回應單筆或多筆訊息給 LINE 使用者。
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
}
?>