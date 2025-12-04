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
        
        // 修正後的 Schema：根類型為 Array，並擴增 date, currency 欄位
        $this->transactionSchema = [
            'type' => 'array', 
            'items' => [
                'type' => 'object',
                'properties' => [
                    'amount' => ['type' => 'number', 'description' => '交易金額，必須是正數'],
                    'category' => ['type' => 'string', 'description' => '交易類別，例如: Food, Transport, Salary, Bills'],
                    'description' => ['type' => 'string', 'description' => '詳細描述或備註'],
                    'type' => ['type' => 'string', 'enum' => ['expense', 'income'], 'description' => '交易類型：收入(income)或支出(expense)'],
                    
                    'date' => ['type' => 'string', 'description' => '交易日期，必須是 YYYY-MM-DD 格式，從輸入中推斷。若無時間提示，請使用今日日期。'],
                    'currency' => ['type' => 'string', 'description' => '貨幣代碼，例如 TWD, USD, JPY。若未提及，預設為 TWD。'],
                ],
                // 擴增 'required' 列表
                'required' => ['amount', 'category', 'type', 'date', 'currency'] 
            ]
        ];
    }

    public function parseTransaction(string $textOrPath): ?array {
        // 取得當前日期，用於 AI 推斷日期的預設值
        $today = date('Y-m-d');
        
        // 🟢 [微調] System Instruction：加入圖片處理規則，其他保持原樣
        $systemInstruction = <<<EOD
--- 核心指令：專業結構化數據轉換引擎 ---

你的唯一職責是將用戶輸入的「文字」、「語音」或「圖片（收據/發票/菜單）」轉換為嚴格符合指定 JSON 結構的數據陣列。

**【指令優先級：最高】**
1. **必須強制輸出 JSON 陣列：** 你的輸出必須是包含多個交易物件的列表 `[{...}, {...}]`。
2. **必須完整拆分：** 用戶的一句話可能包含多個不同的消費或收入，請務必將它們拆分成獨立的項目。
3. **必須有明確金額：** 如果輸入中沒有數字金額，請直接輸出空陣列 `[]`。
4. **必須推斷日期：** 根據輸入中的時間指示 (例如 '昨天', '上週')，將交易日期轉換為 **YYYY-MM-DD** 格式。**如果圖片上有日期，以圖片為準；否則請使用今天的日期：{$today}。**
5. **必須指定貨幣：** 如果用戶沒有提及貨幣種類，請預設使用 **TWD** 作為貨幣代碼。
6. **圖片處理規則：** 若輸入為圖片，請辨識上面的總金額與品項。若有多個品項但無法一一對應金額，可合併為一筆「總計」。

設定：你是一位熟悉台灣生活、年輕人用語的專業記帳助手。請嚴格遵循以下規則：

== EXAMPLE 1 (多筆拆分範例，包含日期/貨幣) ==
User Input: 昨天買了飲料70，晚餐150，還給媽媽5000
Output:
[
  {"amount": 70, "category": "Food", "description": "飲料", "type": "expense", "date": "2025-11-30", "currency": "TWD"},
  {"amount": 150, "category": "Food", "description": "晚餐", "type": "expense", "date": "2025-11-30", "currency": "TWD"},
  {"amount": 5000, "category": "Allowance", "description": "還給媽媽", "type": "expense", "date": "2025-11-30", "currency": "TWD"}
]

== EXAMPLE 2 (單筆範例，今日日期) ==
User Input: 今天買了飲料70
Output:
[
  {"amount": 70, "category": "Food", "description": "飲料", "type": "expense", "date": "{$today}", "currency": "TWD"}
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
- Education: 買書, 課程.
- Miscellaneous: 其他.

規則 3: 請提取具體品項作為 description。
EOD;
        
        $parts = [];

        // 🟢 檢查字串是否以 'FILE:' 開頭
        if (strncmp($textOrPath, 'FILE:', 5) === 0) {
            // === 處理檔案 (音訊 或 圖片) ===
            $filePath = trim(substr($textOrPath, 5)); // 去掉前綴取得路徑
            
            if (file_exists($filePath)) {
                // 讀取檔案並轉為 Base64
                $fileData = file_get_contents($filePath);
                $base64Data = base64_encode($fileData);

                // 🟢 自動偵測檔案類型 (關鍵修改)
                $mimeType = mime_content_type($filePath);
                
                // 修正：PHP 有時會將 .m4a 誤判，手動強制修正為 Gemini 支援的格式
                if (str_ends_with($filePath, '.m4a')) {
                    $mimeType = 'audio/mp4';
                }

                // 建構多模態請求 (Prompt + File Data)
                $parts = [
                    ['text' => $systemInstruction . "\n\n[系統提示] 請分析此檔案（語音或圖片）並提取記帳資訊。"],
                    [
                        'inline_data' => [
                            'mime_type' => $mimeType, // 動態傳入正確的 MIME Type (image/jpeg 或 audio/mp4)
                            'data' => $base64Data
                        ]
                    ]
                ];
                
                // (可選) 處理完後刪除暫存檔以節省空間
                // unlink($filePath); 
            } else {
                error_log("GeminiService Error: File not found at {$filePath}");
                return null;
            }
        } else {
            // === 處理純文字 (原本的邏輯) ===
            $mergedText = $systemInstruction . "\n\nUser input: " . $textOrPath;
            $parts = [['text' => $mergedText]];
        }

        // 組裝最終 API 請求資料
        $data = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => $parts
                ]
            ],
            'generationConfig' => [ 
                'responseMimeType' => 'application/json',
                'responseSchema' => $this->transactionSchema
            ]
        ];

        // ... (以下 API 呼叫與錯誤處理邏輯保持不變)
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

    /**
     * 🌟 分析資產配置並提供建議 (保持不變)
     */
    public function analyzePortfolio(array $data): string {
        // ... (請保留原本的代碼，這裡為了節省篇幅省略) ...
        // 1. 解構資產數據
        $assetData = $data['assets'] ?? [];
        $charts = $assetData['charts'] ?? [];
        
        $netWorth = number_format($assetData['global_twd_net_worth'] ?? 0);
        $totalAssets = number_format($charts['total_assets'] ?? 0);
        $totalLiabilities = number_format($charts['total_liabilities'] ?? 0);
        $cash = number_format($charts['cash'] ?? 0);
        $invest = number_format($charts['investment'] ?? 0);

        // 2. 解構收支數據
        $flow = $data['flow'] ?? [];
        $income = number_format($flow['income'] ?? 0);
        $expense = number_format($flow['expense'] ?? 0);
        
        $rawNetFlow = ($flow['income'] ?? 0) - ($flow['expense'] ?? 0);
        $netFlow = number_format($rawNetFlow);

        // 3. 構建超級 Prompt
        $prompt = <<<EOD
你是一位專業且貼心的個人財務顧問。請根據以下使用者的「資產負債」與「本月收支」數據，進行綜合財務健檢（300字以內）：

【資產負債表 (存量)】
- 總資產: {$totalAssets}
- 總負債: {$totalLiabilities}
- 淨值: {$netWorth}
- 現金部位: {$cash}
- 投資部位: {$invest}

【本月收支表 (流量)】
- 總收入: {$income}
- 總支出: {$expense}
- 本月結餘: {$netFlow}

【分析任務】
1. **現金流診斷**：評估本月是否透支？儲蓄率是否理想？
2. **結構與風險**：現金是否足夠覆蓋短期支出？負債比是否過高？
3. **綜合建議**：結合「存量」與「流量」，給出一個具體且可執行的理財建議（例如：增加投資、削減非必要開支、或優先還債）。
4. **語氣**：請用溫暖、鼓勵且專業的口吻，使用繁體中文，重點請用條列式呈現。
【備註】
1.最後請務必加上這句話：（以上為AI分析，僅供教育參考，非提供投資建議。）
2.不要加上任何表情符號。

EOD;

        $payload = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]]
        ];

        $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response, true);
        return $result['candidates'][0]['content']['parts'][0]['text'] ?? 'AI 目前無法進行分析，請稍後再試。';
    }
}
?>