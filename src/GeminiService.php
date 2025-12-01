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
        
        // 修正後的 Schema：根類型為 Array
        $this->transactionSchema = [
            'type' => 'array', 
            'items' => [
                'type' => 'object',
                'properties' => [
                    'amount' => ['type' => 'number', 'description' => '交易金額，必須是正數'],
                    'category' => ['type' => 'string', 'description' => '交易類別，例如: Food, Transport, Salary, Bills'],
                    'description' => ['type' => 'string', 'description' => '詳細描述或備註'],
                    'type' => ['type' => 'string', 'enum' => ['expense', 'income'], 'description' => '交易類型：收入(income)或支出(expense)'],
                ],
                'required' => ['amount', 'category', 'type']
            ]
        ];
    }

    public function parseTransaction(string $text): ?array {
        // 強化版 System Instruction
        $systemInstruction = <<<EOD
--- 核心指令：專業結構化數據轉換引擎 ---

你的唯一職責是將用戶輸入的中文內容轉換為嚴格符合指定 JSON 結構的數據陣列。

**【指令優先級：最高】**
1. **必須強制輸出 JSON 陣列：** 你的輸出必須是包含多個交易物件的列表 `[{...}, {...}]`。
2. **必須完整拆分：** 用戶的一句話可能包含多個不同的消費或收入，請務必將它們拆分成獨立的項目。例如：「午餐100，晚餐200」必須變成兩個物件。
3. **必須有明確金額：** 如果輸入中沒有數字金額，請直接輸出空陣列 `[]`。

設定：你是一位熟悉台灣生活、年輕人用語的專業記帳助手。請嚴格遵循以下規則：

== EXAMPLE 1 (多筆拆分範例) ==
User Input: 買了全聯的菜 350，搭了 Uber 100，還有欠我的 200 塊老王剛還我
Output:
[
  {"amount": 350, "category": "Shopping", "description": "全聯的菜", "type": "expense"},
  {"amount": 100, "category": "Transport", "description": "搭 Uber", "type": "expense"},
  {"amount": 200, "category": "Allowance", "description": "老王還錢", "type": "income"}
]

== EXAMPLE 2 (單筆範例) ==
User Input: 昨天買了飲料70
Output:
[
  {"amount": 70, "category": "Food", "description": "飲料", "type": "expense"}
]
========================

規則 1 (Type 類型判斷):
- income: 薪水, 發薪, 領錢, 獎金, 股利, 發票中獎, 還錢, 轉帳給我.
- expense: 其他所有消費.

規則 2 (Category 類別判斷 - 台灣習慣):
- Food: 吃飯, 飲料, 聚餐.
- Transport: 交通, 加油, 停車.
- Entertainment: 娛樂, 訂閱, 遊戲.
- Shopping: 購物, 日用品.
- Bills: 帳單, 房租.
- Investment: 投資.
- Medical: 醫療.
- Education: 書, 課程.
- Miscellaneous: 其他.

規則 3: 請提取具體品項作為 description。
EOD;

        $mergedText = $systemInstruction . "\n\nUser input: " . $text;
        
        $data = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $mergedText]]
                ]
            ],
            'generationConfig' => [ 
                'responseMimeType' => 'application/json',
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
            $resultArray = json_decode($jsonText, true);
            if (is_array($resultArray)) {
                return $resultArray;
            }
        }
        
        return null;
    }
}
?>