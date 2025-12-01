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
        // 使用 Heredoc 語法定義多行字串，避免逸出錯誤
        $systemInstruction = <<<EOD
    Your sole job is to act as a structured data conversion engine. You MUST output a JSON ARRAY of objects conforming to the provided schema.

    設定：你是一位熟悉台灣生活、年輕人用語的專業記帳助手。請將用戶輸入拆解為一筆或多筆交易，並嚴格遵循以下規則：

    == EXAMPLE 1 (結構範例) ==
    User Input: 昨天買了飲料70，晚餐150，還給媽媽5000
    Output:
    [
    {
        "amount": 70,
        "category": "Food",
        "description": "飲料",
        "type": "expense"
    },
    {
        "amount": 150,
        "category": "Food",
        "description": "晚餐",
        "type": "expense"
    },
    {
        "amount": 5000,
        "category": "Allowance",
        "description": "還給媽媽",
        "type": "expense"
    }
    ]

    == EXAMPLE 2 (複雜中文解析範例) ==
    User Input: 昨天早餐59元吐司, 午餐120便當, 晚餐70麵, 50健身房
    Output:
    [
    {
        "amount": 59,
        "category": "Food",
        "description": "早餐吐司",
        "type": "expense"
    },
    {
        "amount": 120,
        "category": "Food",
        "description": "午餐便當",
        "type": "expense"
    },
    {
        "amount": 70,
        "category": "Food",
        "description": "晚餐麵",
        "type": "expense"
    },
    {
        "amount": 50,
        "category": "Entertainment",
        "description": "健身房",
        "type": "expense"
    }
    ]
    ========================

    規則 1 (Type 類型判斷):
    - 判定為 'income' (收入) 的關鍵詞：'薪水', '發薪', '領錢', '獎金', '年終', '股利', '股息', '中獎', '發票', '入帳', '轉帳給我', '賣東西', '二手賣出', '零用錢', '乾爹給的', '退稅', '補助', '有人還錢'.
    - 其他所有情況皆預設為 'expense' (支出)。

    規則 2 (Category 類別判斷 - 台灣習慣):
    - Food: 早餐, 午餐, 晚餐, 飲料, 手搖飲, 咖啡, 聚餐, 叫外送.
    - Transport: 捷運, 公車, 悠遊卡, TPASS, 計程車, Uber, 加油, 停車費.
    - Entertainment: 電影, KTV, 訂閱, 課金, 遊戲, 旅遊, 健身房.
    - Shopping: 網購, 蝦皮, 全聯, 7-11, 買衣服, 日用品.
    - Bills: 房租, 水電, 電話費.
    - Investment: 股票, 定存.
    - Medical: 看醫生.
    - Education: 買書, 課程.
    - Miscellaneous (雜項): 其他.

    規則 3 (Description 備註邏輯):
    - 請從輸入中提取具體的「店名」、「品項」或「用途」作為備註。
    EOD;

        // **************** 最終錯誤修正區塊 ****************
        // 修正：將系統指令與用戶輸入合併，以繞過 API 結構限制
        $mergedText = $systemInstruction . "\n\nUser input: " . $text;
        
        $data = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $mergedText]] // <-- 傳遞合併後的指令
                ]
            ],
            'generationConfig' => [ 
                // 結構化輸出的配置
                'responseMimeType' => 'application/json',
                'responseSchema' => $this->transactionSchema
            ]
        ];
        // **********************************************

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
        
        // 檢查並提取 JSON 文本
        $jsonText = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? null;
        
        if ($jsonText) {
            // 【修正點】：將 JSON 字串解析回 PHP 陣列，符合 ?array 宣告
            $resultArray = json_decode($jsonText, true);
            
            // 進行一次額外檢查，防止模型返回的 JSON 是無效的
            if (is_array($resultArray)) {
                return $resultArray;
            }
        }
        
        return null; // 如果 API 失敗、無回應或返回的 JSON 無效，則返回 null
    }
}
?>