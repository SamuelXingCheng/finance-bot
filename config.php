<?php
/**
 * config.php
 * 負責從 .env 檔案載入所有環境變數，並定義為全域常數和環境變數。
 */

// ----------------------------------------------------
// 1. 載入 .env 檔案並解析
// ----------------------------------------------------
function loadEnv($path = __DIR__ . '/.env') {
    if (!file_exists($path)) {
        error_log("FATAL: The .env file not found at: {$path}");
        die("Configuration failed. .env file missing.");
    }

    $lines = file($path); 

    foreach ($lines as $line) {
        $line = trim($line);
        
        // 跳過空行或註釋
        if (empty($line) || strpos($line, '#') === 0) {
            continue; 
        }
        
        // 簡單解析：NAME=VALUE
        if (strpos($line, '=') === false) {
             continue; // 確保有等號
        }

        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        
        // 清理值：移除開頭和結尾的空格或引號
        $value = trim($value, " \n\r\t\v\x00\"'"); 

        if (!defined($name)) {
            // 將環境變數定義為全域常數
            define($name, $value);
        }
        
        // 同時載入到環境變數中 (供 getenv() 使用)
        putenv("{$name}={$value}"); 
        $_ENV[$name] = $value; // 同步到 $_ENV (可選，但建議保持一致)
    }
}

loadEnv();

// ----------------------------------------------------
// 2. 應用程式常數 (檢查確保)
// ----------------------------------------------------

// 確保所有**必要**的常數都已定義 (防呆，確保 LIFF 和 Bot 都能運作)
if (!defined('DB_HOST') || 
    !defined('LINE_CHANNEL_SECRET') ||       // LINE Login Secret (用於 LIFF Token 驗證)
    !defined('LINE_BOT_CHANNEL_SECRET') ||   // Messaging API Secret (用於 Webhook Signature)
    !defined('LINE_BOT_ACCESS_TOKEN') ||     // Messaging API Token (用於 LineService)
    !defined('LINE_LIFF_ID') ||              // LIFF App ID (用於前端初始化)
    !defined('LIFF_DASHBOARD_URL') ||        // LIFF URL (用於 Webhook 回覆)
    !defined('GEMINI_API_KEY')) { 

    error_log("FATAL: Required environment variables are missing in .env.");
    die("Configuration error. Missing one or more critical LINE/DB/GEMINI environment variables.");
}

// 確保其他常數存在 (檢查 CoinGecko Key 載入成功)
// 此處保留原有邏輯，如果非必要金鑰遺失，只記錄錯誤但不中止應用程式。
if (!defined('COINGECKO_API_KEY') && !getenv('COINGECKO_API_KEY')) {
    error_log("WARNING: COINGECKO_API_KEY is missing. Currency rates will use static fallback.");
}

if (!defined('GEMINI_MODEL')) define('GEMINI_MODEL', 'gemini-2.5-flash');
if (!defined('BUDGET_WARNING_RATIO')) define('BUDGET_WARNING_RATIO', 0.9);

// --- 會員限制設定 ---
// 免費版：每日口語記帳次數限制
if (!defined('LIMIT_VOICE_tx_ZOOM_DAILY')) define('LIMIT_VOICE_TX_DAILY', 3);

// 免費版：每月/每年 AI 財務健檢次數限制 (這裡設為每月 2 次為例)
if (!defined('LIMIT_HEALTH_CHECK_MONTHLY')) define('LIMIT_HEALTH_CHECK_MONTHLY', 2);

?>