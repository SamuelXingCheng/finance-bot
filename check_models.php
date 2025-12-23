<?php
// check_models.php - 查詢可用的 Gemini 模型列表

// 1. 載入設定檔以取得 API KEY
// 假設 config.php 在同一層目錄
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    die("錯誤: 找不到 config.php，請確認路徑正確。\n");
}

// 檢查 API KEY 是否存在
if (!defined('GEMINI_API_KEY') || empty(GEMINI_API_KEY)) {
    die("錯誤: 找不到 GEMINI_API_KEY 常數，請檢查 config.php 設定。\n");
}

$apiKey = GEMINI_API_KEY;
$url = "https://generativelanguage.googleapis.com/v1beta/models?key={$apiKey}";

// 2. 初始化 cURL
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
// 設定連線超時，避免卡住
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

// 3. 執行請求
echo "正在連線到 Google Gemini API 查詢模型列表...\n";
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// 4. 檢查結果
if ($httpCode !== 200) {
    echo "❌ 請求失敗 (HTTP {$httpCode})\n";
    if ($curlError) echo "連線錯誤: {$curlError}\n";
    echo "回應內容: {$response}\n";
    exit(1);
}

$data = json_decode($response, true);

if (!isset($data['models'])) {
    echo "❌ 無法解析回應資料。\n";
    print_r($data);
    exit(1);
}

// 5. 格式化輸出
echo "\n✅ 查詢成功！您的 API Key 可使用以下模型：\n";
echo str_repeat("=", 80) . "\n";
printf("%-25s | %-15s | %-10s | %-20s\n", "Model ID", "Version", "Limit", "Description");
echo str_repeat("-", 80) . "\n";

$foundFlash = false;
$foundPro = false;

foreach ($data['models'] as $model) {
    // 我們只關心能「生成內容」的模型
    if (isset($model['supportedGenerationMethods']) && in_array("generateContent", $model['supportedGenerationMethods'])) {
        
        $name = str_replace('models/', '', $model['name']); // 去掉前綴
        $version = $model['version'] ?? 'N/A';
        $limit = $model['inputTokenLimit'] ?? 'N/A';
        $desc = $model['displayName'] ?? '';

        // 標記我們常用的模型
        $mark = "";
        if ($name === 'gemini-1.5-flash') {
            $mark = "🚀 (推薦)";
            $foundFlash = true;
        }
        if ($name === 'gemini-1.5-pro') {
            $mark = "🧠 (強大)";
            $foundPro = true;
        }

        printf("%-25s | %-15s | %-10s | %s %s\n", $name, $version, number_format((float)$limit), $desc, $mark);
    }
}
echo str_repeat("=", 80) . "\n";

// 6. 總結建議
echo "\n📋 總結：\n";
if ($foundFlash) {
    echo "✅ 您的帳號支援 'gemini-1.5-flash' (速度快、適合記帳、大圖分析)。\n";
} else {
    echo "⚠️ 未發現 'gemini-1.5-flash'，請確認您的 API Key 權限或 Google Cloud 專案設定。\n";
}

if ($foundPro) {
    echo "✅ 您的帳號支援 'gemini-1.5-pro' (邏輯強、適合複雜推論)。\n";
}
echo "\n";
?>