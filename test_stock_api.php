<?php
/**
 * 測試美股與台股 API 連線
 */
require_once __DIR__ . '/config.php';
// 🟢 改為從全域常數讀取，若 .env 沒設定則給 null
$finnhub_api_key = defined('FINNHUB_API_KEY') ? FINNHUB_API_KEY : null;

/**
 * 獲取美股價格 (使用 Finnhub)
 */
function getUsStockPrice($symbol, $apiKey) {
    $url = "https://finnhub.io/api/v1/quote?symbol={$symbol}&token={$apiKey}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); // 🟢 獲取 HTTP 狀態碼
    curl_close($ch);
    
    if ($httpCode !== 200) {
        echo "API 錯誤：HTTP 代碼 $httpCode, 回傳內容: $response \n";
        return null;
    }
    
    $data = json_decode($response, true);
    // 'c' 代表 Current Price (現價)
    return $data['c'] ?? null;
}

/**
 * 獲取台股價格 (使用 Yahoo Finance 簡單抓取)
 * 注意：台股上市請加 .TW，上櫃請加 .TWO (例如：2330.TW)
 */
function getTwStockPrice($symbol) {
    // 這裡使用 Yahoo Finance 的公開 API
    $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$symbol}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0'); // Yahoo 需要 User-Agent
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    try {
        $meta = $data['chart']['result'][0]['meta'];
        return $meta['regularMarketPrice'] ?? null;
    } catch (Exception $e) {
        return null;
    }
}

// --- 執行測試 ---

echo "--- 美股測試 --- \n";
$usSymbols = ['AAPL', 'TSLA', 'VOO'];
foreach ($usSymbols as $s) {
    $price = getUsStockPrice($s, $finnhub_api_key);
    echo "標的: {$s}, 價格: " . ($price ?: '抓取失敗') . " USD\n";
}

echo "\n--- 台股測試 --- \n";
$twSymbols = ['2330.TW', '0050.TW', '2454.TW'];
foreach ($twSymbols as $s) {
    $price = getTwStockPrice($s);
    echo "標的: {$s}, 價格: " . ($price ?: '抓取失敗') . " TWD\n";
}