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
        
        // 定義標準記帳 Schema
        $this->transactionSchema = [
            'type' => 'array', 
            'items' => [
                'type' => 'object',
                'properties' => [
                    'amount' => ['type' => 'number', 'description' => '交易金額，必須是正數'],
                    'category' => ['type' => 'string', 'description' => '交易類別，例如: Food, Transport, Salary, Bills'],
                    'description' => ['type' => 'string', 'description' => '詳細描述或備註'],
                    'type' => ['type' => 'string', 'enum' => ['expense', 'income'], 'description' => '交易類型'],
                    'date' => ['type' => 'string', 'description' => '交易日期 (YYYY-MM-DD)'],
                    'currency' => ['type' => 'string', 'description' => '貨幣代碼 (TWD, USD...)'],
                ],
                'required' => ['amount', 'category', 'type', 'date', 'currency'] 
            ]
        ];
    }

    /**
     * [一般記帳] 處理生活記帳 (語音/文字/發票/信用卡帳單)
     * 使用 Schema 強制約束格式
     */
    public function parseTransaction(string $textOrPath): ?array {
        $today = date('Y-m-d');
        
        // (保留您的原始 Instruction 不動)
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
        
        // 傳入 true 表示使用 transactionSchema
        return $this->callGeminiAPI($systemInstruction, $textOrPath, true);
    }

    /**
     * [加密貨幣] 專門處理交易所截圖
     * 不使用 Schema，讓 Prompt 自由定義回傳欄位 (如 price, fee)
     */
    public function parseCryptoScreenshot(string $filePath): ?array {
        $today = date('Y-m-d');
        
        // (保留您的原始 Instruction 不動)
        $systemInstruction = <<<EOD
--- 角色設定 ---
你是一位專業的加密貨幣財務助理。你的任務是分析使用者上傳的「交易所截圖」或「合約 PNL 圖」，並提取結構化的交易數據。

--- 輸出規則 ---
1. **輸出格式**：JSON Array。
2. **必要欄位**：
   - `type`: buy, sell, deposit, withdraw, earn (獲利), loss (虧損)。
   - `baseCurrency`: 標的幣種 (如 BTC, ETH)。
   - `quoteCurrency`: 計價幣種 (通常是 USDT)。
   - `price`, `quantity`, `total`, `fee`。
   - `date`: 交易日期，若無則使用 {$today}。
   - `note`: 備註 (例如 "Binance 合約平倉")。

--- 辨識邏輯 ---
1. 若是現貨成交單：Buy ETH/USDT -> type="buy", base="ETH", quote="USDT"。
2. 若是合約 PNL 卡：Positive -> type="earn"; Negative -> type="loss"。Base 設為 USDT。
EOD;

        // 傳入 false 表示不使用 Schema，且明確標示 FILE: 前綴
        return $this->callGeminiAPI($systemInstruction, "FILE:" . $filePath, false);
    }

    /**
     * [核心] 共用的 Gemini API 呼叫邏輯
     * 負責處理檔案讀取、Base64 編碼、CURL 請求發送
     * @param mixed $useSchema boolean|array 若為 true 使用預設記帳 Schema；若為 array 則使用該自定義 Schema；若為 false 則不使用。
     */
    private function callGeminiAPI(string $systemInstruction, string $content, $useSchema = false): ?array {
        $parts = [];

        // 判斷是否為檔案路徑 (FILE:...)
        if (strncmp($content, 'FILE:', 5) === 0) {
            $filePath = trim(substr($content, 5));
            
            if (file_exists($filePath)) {
                $fileData = file_get_contents($filePath);
                $base64Data = base64_encode($fileData);
                $mimeType = mime_content_type($filePath);
                
                // 修正 m4a 誤判為 application/octet-stream 的問題
                if (str_ends_with($filePath, '.m4a')) {
                    $mimeType = 'audio/mp4';
                }

                $parts = [
                    ['text' => $systemInstruction . "\n\n[系統提示] 請分析此檔案。"],
                    [
                        'inline_data' => [
                            'mime_type' => $mimeType, 
                            'data' => $base64Data
                        ]
                    ]
                ];
            } else {
                error_log("GeminiService Error: File not found at {$filePath}");
                return null;
            }
        } else {
            // 純文字輸入
            if (empty($content)) {
                $mergedText = $systemInstruction;
            } else {
                $mergedText = $systemInstruction . "\n\nUser input: " . $content;
            }
            $parts = [['text' => $mergedText]];
        }

        // 設定生成參數
        $generationConfig = [
            'responseMimeType' => 'application/json'
        ];

        // 🟢 [修正] 支援 boolean 或 array 類型的 Schema 設定
        if ($useSchema === true) {
            $generationConfig['responseSchema'] = $this->transactionSchema;
        } elseif (is_array($useSchema)) {
            $generationConfig['responseSchema'] = $useSchema;
        }

        $data = [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => $generationConfig
        ];

        $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

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
     * 🌟 分析資產配置 (保持不變)
     */
    public function analyzePortfolio(array $data): string {
        $assetData = $data['assets'] ?? [];
        $charts = $assetData['charts'] ?? [];
        
        $netWorth = number_format($assetData['global_twd_net_worth'] ?? 0);
        $totalAssets = number_format($charts['total_assets'] ?? 0);
        $totalLiabilities = number_format($charts['total_liabilities'] ?? 0);
        $cash = number_format($charts['cash'] ?? 0);
        $invest = number_format($charts['investment'] ?? 0);

        $flow = $data['flow'] ?? [];
        $income = number_format($flow['income'] ?? 0);
        $expense = number_format($flow['expense'] ?? 0);
        $netFlow = number_format(($flow['income'] ?? 0) - ($flow['expense'] ?? 0));

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

    /**
     * [CSV 規則生成]
     * 分析 CSV 片段，回傳欄位對應表 (Mapping Schema)
     */
    /**
     * 🟢 [CSV 規則生成] (已修正：強制要求所有欄位)
     */
    public function generateCsvMapping(string $csvSnippet): ?array {
        $schema = [
            'type' => 'object',
            'properties' => [
                'exchange_name' => ['type' => 'string', 'description' => '交易所名稱推測'],
                'has_header' => ['type' => 'boolean', 'description' => '第一行是否為標題'],
                'date_col_index' => ['type' => 'integer', 'description' => '日期欄位索引(0起)'],
                
                // 幣種欄位 (必填，無則填-1)
                'pair_col_index' => ['type' => 'integer', 'description' => '交易對欄位索引 (若無則填 -1)'],
                'base_col_index' => ['type' => 'integer', 'description' => '基準幣欄位索引 (如 BTC)'],
                'quote_col_index' => ['type' => 'integer', 'description' => '計價幣欄位索引 (如 USDT/TWD)'],
                
                'side_col_index' => ['type' => 'integer', 'description' => '方向(Buy/Sell)欄位索引'],
                'price_col_index' => ['type' => 'integer', 'description' => '價格欄位索引'],
                'qty_col_index' => ['type' => 'integer', 'description' => '數量欄位索引'],
                'fee_col_index' => ['type' => 'integer', 'description' => '手續費欄位索引'],
                'total_col_index' => ['type' => 'integer', 'description' => '總金額欄位索引，若無填-1'],
                'date_format' => ['type' => 'string', 'description' => 'PHP日期格式，如 Y-m-d H:i:s'],
                'side_mapping' => [
                    'type' => 'object',
                    'properties' => [
                        'buy_keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'sell_keywords' => ['type' => 'array', 'items' => ['type' => 'string']]
                    ]
                ]
            ],
            // 🟢 關鍵修正：將所有欄位設為 required，強迫 AI 思考並填寫
            'required' => [
                'exchange_name', 'has_header', 'date_col_index', 
                'pair_col_index', 'base_col_index', 'quote_col_index',
                'side_col_index', 'price_col_index', 'qty_col_index', 'total_col_index', 'date_format'
            ]
        ];

        $prompt = <<<EOD
你是一個資料工程師。請分析以下 CSV 片段（含 Header），並告訴我關鍵欄位的 Index（從 0 開始）。

**規則與邏輯：**
1. **幣種處理**：
   - 若有單一欄位 "Pair" (如 BTCUSDT)，填 `pair_col_index`，其餘幣種欄位填 -1。
   - 若幣種分開 (如 "Base Currency" 和 "Quote Currency")，填 `base_col_index` 和 `quote_col_index`，並將 `pair_col_index` 填 -1。
2. **數值選擇**：
   - 請優先選擇 **「成交/已執行 (Executed)」** 的價格與數量。
   - 不要選擇「委託 (Order)」的數值，因為那可能未完全成交。
3. **日期格式**：
   - 請觀察範例，如果是 "2025-05-12 08:25:11" 請用 "Y-m-d H:i:s"。

CSV 片段：
```csv
{$csvSnippet}
EOD;
// 傳入自定義 Schema
    return $this->callGeminiAPI($prompt, "", $schema);
    }

}
?>