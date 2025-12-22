<?php
// src/GeminiService.php
require_once __DIR__ . '/../config.php';

class GeminiService {
    private $apiKey;
    private $model;
    private $unifiedSchema;

    public function __construct() {
        $this->apiKey = GEMINI_API_KEY;
        $this->model = GEMINI_MODEL;
        
        // 🌟 定義通用的意圖 Schema (維持不變，因為這結構能涵蓋所有需求)
        $this->unifiedSchema = [
            'type' => 'object',
            'properties' => [
                'intent' => [
                    'type' => 'string', 
                    'enum' => ['transaction', 'asset_setup', 'query', 'chat'],
                    'description' => '用戶意圖判斷'
                ],
                // --- 1. 記帳資料 (對應您原本的輸出陣列) ---
                'transaction_data' => [
                    'type' => 'array',
                    'description' => '當 intent 為 transaction 時，填入此欄位。必須是交易物件的陣列。',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'amount' => ['type' => 'number', 'description' => '金額 (正數)'],
                            'category' => ['type' => 'string', 'description' => '類別 (Food, Transport...)'],
                            'description' => ['type' => 'string', 'description' => '品項描述'],
                            'type' => ['type' => 'string', 'enum' => ['expense', 'income']],
                            'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                            'currency' => ['type' => 'string', 'description' => 'TWD, USD...']
                        ],
                        'required' => ['amount', 'category', 'type', 'date', 'currency']
                    ]
                ],
                // --- 2. 資產設定資料 ---
                // --- 2. 資產/訂閱設定 ---
                'asset_data' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => '帳戶或訂閱名稱'],
                        'type' => ['type' => 'string', 'description' => 'Bank, Cash, CreditCard, Stock, Subscription'],
                        'balance' => ['type' => 'number', 'description' => '金額'],
                    ]
                ],
                // --- 3. 查詢參數 ---
                'query_params' => [
                    'type' => 'object',
                    'properties' => [
                        'target' => [
                            'type' => 'string', 
                            'enum' => ['expense', 'income', 'net_worth', 'account_list', 'subscription_list', 'summary'],
                            'description' => 'summary 代表同時查詢收入與支出'
                        ],
                        'category' => [
                            'type' => 'string',
                            'description' => '指定類別 (例如: Investment)'
                        ]
                    ]
                ],
                // --- 4. 閒聊回覆 ---
                'reply_text' => [
                    'type' => 'string', 
                    'description' => '給用戶的自然語言回覆'
                ]
            ],
            'required' => ['intent']
        ];
    }

    /**
     * 核心分析函式：處理所有文字/語音/圖片輸入
     * 將原本的記帳 Prompt 完美融合進 Intent 判斷中
     */
    public function analyzeInput(string $content, array $userCategories = []): ?array {
        $today = date('Y-m-d');
        
        // 🟢 [新增] 將用戶的自訂類別轉成 Prompt 字串
        $customCatStr = "";
        
        // 因為上面參數加了 $userCategories，這裡才不會報錯
        if (!empty($userCategories)) {
            $list = implode(', ', $userCategories);
            $customCatStr = "   - **用戶自訂類別 (優先匹配)**: {$list}";
        }
        // 🌟 這裡將您原本的指令整合進去
        $systemInstruction = <<<EOD
你是一位專業的個人財務 AI 助理。請先分析用戶輸入的「意圖 (Intent)」，並根據意圖輸出對應的 JSON 資料。

--- 意圖 1：記帳 (transaction) ---
如果用戶輸入包含消費、收入、轉帳等內容 (例如: "午餐 100", "薪水 50000")，請將 `intent` 設為 `transaction`，並將資料填入 `transaction_data` 陣列。

**【記帳核心規則 - 必須嚴格遵守】**
1. **強制拆分：** 一句話若包含多筆消費，務必拆成多個物件。
2. **日期推斷：** 根據 '昨天', '上週' 推斷日期。若無提及或圖片無日期，預設使用今天：{$today}。
3. **貨幣預設：** 預設 **TWD**。
4. **類別對照 (Category) - 請依照優先順序判斷：**
    
    **[優先權 1] 強制指定 (Hashtag)：** 若用戶輸入包含 #標籤 (例如 "買禮物 500 #公關費")，請直接將 "#" 後的文字 ("公關費") 填入 `category`，忽略下方預設分類。

{$customCatStr}

    **[優先權 3] 預設通用類別 (若無上述狀況則使用此對照)：**
    - Food: 吃飯, 飲料, 聚餐, 午餐, 晚餐
    - Transport: 交通, 加油, 停車, 計程車, 捷運
    - Entertainment: 娛樂, 訂閱, 遊戲, 電影
    - Shopping: 購物, 日用品, 衣服
    - Bills: 帳單, 房租, 水電, 電話費
    - Investment: 投資, 股票
    - Medical: 醫療, 看醫生
    - Education: 買書, 課程
    - Miscellaneous: 其他

5. **類型判斷 (Type)：**
    - income: 薪水, 發薪, 領錢, 獎金, 股利, 發票中獎, 還錢, 轉帳給我
    - expense: 其他所有消費

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

規則 3: 請提取具體品項作為 description。

--- 意圖 2：資產設定 (asset_setup) ---
建立帳戶或管理訂閱。例如："建立台新 5萬" 或 "設定 Netflix 390"。

--- 意圖 3：查詢 (query) ---
詢問財務狀況。
- "這個月花多少" -> target: expense
- "這個月賺多少" -> target: income
- "查詢支出" -> target: expense
- "查詢收出" -> target: income
- "投資花多少" -> target: expense, category: Investment
- "查詢收支", "收支概況", "本月統計" -> target: summary
- "我有幾個帳戶", "列出我的帳戶" -> target: account_list
- "固定支出有哪些", "訂閱有哪些" -> target: subscription_list
- "還有多少錢" -> target: net_worth
請在 reply_text 給予確認回覆。

--- 意圖 4：閒聊 (chat) ---
一般對話或無法辨識時，在 reply_text 親切回覆。

EOD;
        
        return $this->callGeminiAPI($systemInstruction, $content, $this->unifiedSchema);
    }

    /**
     * [加密貨幣] 專門處理交易所截圖
     * 不使用 Schema，讓 Prompt 自由定義回傳欄位 (如 price, fee)
     */
    public function parseCryptoScreenshot(string $filePath): ?array {
        $today = date('Y-m-d');
        
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
     * 支援純文字或 FILE:路徑
     */
    private function callGeminiAPI(string $systemInstruction, string $content, $schema): ?array {
        $parts = [];

        // 判斷是否為檔案 (圖片/語音)
        if (strncmp($content, 'FILE:', 5) === 0) {
            $filePath = trim(substr($content, 5));
            if (file_exists($filePath)) {
                $fileData = file_get_contents($filePath);
                $base64Data = base64_encode($fileData);
                $mimeType = mime_content_type($filePath);
                
                // 修正 m4a 類型
                if (str_ends_with($filePath, '.m4a')) $mimeType = 'audio/mp4';

                $parts = [
                    ['text' => $systemInstruction . "\n\n[系統提示] 請分析此檔案內容。"],
                    ['inline_data' => ['mime_type' => $mimeType, 'data' => $base64Data]]
                ];
            } else {
                error_log("GeminiService: File not found {$filePath}");
                return null;
            }
        } else {
            // 純文字
            $parts = [['text' => $systemInstruction . "\n\nUser Input: " . $content]];
        }

        $payload = [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => [
                'responseMimeType' => 'application/json'
            ]
        ];

        // 只有當傳入 Schema 時才加入設定，避免影響其他彈性輸出
        if ($schema !== false) {
            $payload['generationConfig']['responseSchema'] = $schema;
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            error_log("Gemini API Error: {$httpCode} - {$response}");
            return null;
        }

        $data = json_decode($response, true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        
        return $text ? json_decode($text, true) : null;
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
     * 🟢 [CSV 規則生成] (支援出入金)
     */
    public function generateCsvMapping(string $csvSnippet): ?array {
        $schema = [
            'type' => 'object',
            'properties' => [
                'exchange_name' => ['type' => 'string', 'description' => '交易所名稱推測'],
                'has_header' => ['type' => 'boolean', 'description' => '第一行是否為標題'],
                'date_col_index' => ['type' => 'integer', 'description' => '日期欄位索引(0起)'],
                
                'pair_col_index' => ['type' => 'integer', 'description' => '交易對欄位索引 (若無填-1)'],
                'base_col_index' => ['type' => 'integer', 'description' => '基準幣/幣種欄位索引'],
                'quote_col_index' => ['type' => 'integer', 'description' => '計價幣欄位索引 (若無填-1)'],
                
                'side_col_index' => ['type' => 'integer', 'description' => '類型/方向欄位索引'],
                'price_col_index' => ['type' => 'integer', 'description' => '價格欄位索引 (出入金填-1)'],
                'qty_col_index' => ['type' => 'integer', 'description' => '數量/金額欄位索引'],
                'fee_col_index' => ['type' => 'integer', 'description' => '手續費欄位索引'],
                'total_col_index' => ['type' => 'integer', 'description' => '總金額欄位索引 (若無填-1)'],
                'date_format' => ['type' => 'string', 'description' => 'PHP日期格式'],
                'side_mapping' => [
                    'type' => 'object',
                    'properties' => [
                        'buy_keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'sell_keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
                        // 🟢 新增：出入金關鍵字
                        'deposit_keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'withdraw_keywords' => ['type' => 'array', 'items' => ['type' => 'string']]
                    ]
                ]
            ],
            'required' => [
                'date_col_index', 'base_col_index', 'side_col_index', 'qty_col_index', 
                'price_col_index', 'side_mapping'
            ]
        ];

        $prompt = <<<EOD
你是一個資料工程師。請分析以下 CSV 片段（含 Header），並告訴我關鍵欄位的 Index（從 0 開始）。

**規則：**
1. **交易 (Trading)**：若有買賣，請填寫 Price, Qty, Pair/Base/Quote。
2. **出入金 (Funding)**：
   - 類型欄位填入 `side_col_index`。
   - `price_col_index` 填 -1。
   - 金額填入 `qty_col_index`。
   - 幣種填入 `base_col_index`。
3. **關鍵字**：請在 `side_mapping` 中列出識別 "Deposit"(入金/加值) 和 "Withdraw"(出金/提領) 的關鍵字。

CSV 片段：
```csv
{$csvSnippet}
EOD;
    // 傳入自定義 Schema
    return $this->callGeminiAPI($prompt, "", $schema);
    }
}
?>