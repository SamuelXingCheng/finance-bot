<?php
// src/GeminiService.php
require_once __DIR__ . '/../config.php';

class GeminiService {
    private $apiKey;
    private $model;
    private $transactionSchema;

    public function __construct() {
        $this->apiKey = GEMINI_API_KEY;
        $this->model = GEMINI_MODEL;
        
        // 定義我們希望 Gemini 輸出的交易數據結構 (JSON Schema)
        $this->transactionSchema = [
            'type' => 'object',
            'properties' => [
                'amount' => ['type' => 'number', 'description' => '交易金額，必須是正數'],
                'category' => ['type' => 'string', 'description' => '交易類別，例如: Food, Transport, Salary, Bills'],
                'description' => ['type' => 'string', 'description' => '詳細描述或備註'],
                'type' => ['type' => 'string', 'enum' => ['expense', 'income'], 'description' => '交易類型：收入(income)或支出(expense)'],
            ],
            'required' => ['amount', 'category', 'type']
        ];
    }

    public function parseTransaction(string $text): ?array {
        $systemInstruction = "You are a professional financial assistant. Your task is to accurately parse the user's spending or income text and convert it into a valid JSON object based ONLY on the provided schema. Infer the 'type' (expense or income) based on the context. If the text clearly indicates income (e.g., 'received salary'), use 'income'. Otherwise, assume 'expense'. If no category is clearly stated, use 'Miscellaneous'.";

        $data = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $text]]
                ]
            ],
            'config' => [
                'systemInstruction' => $systemInstruction,
                'responseMimeType' => 'application/json', // 設定輸出為 JSON
                'responseSchema' => $this->transactionSchema
            ]
        ];

        $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            error_log("Gemini API Error: HTTP $httpCode, Response: $response");
            return null;
        }

        $responseData = json_decode($response, true);
        
        $jsonText = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? null;
        
        if ($jsonText) {
            return json_decode($jsonText, true);
        }
        
        return null;
    }
}
?>