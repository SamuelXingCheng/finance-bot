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
        $_ENV[$name] = $value; 
    }
}

loadEnv();

// ----------------------------------------------------
// 2. 補定義額外常數 (如果 .env 沒寫，可以在這裡補)
// ----------------------------------------------------

// ✅ 修正：請在此處定義您的 NOWPayments Key，而不是在 if 判斷式裡面
if (!defined('NOWPAYMENTS_IPN_KEY')) {
    // ⚠️ 請記得將下方的 'ZcyX...9t7N' 換成您真實的 IPN Secret Key
    define('NOWPAYMENTS_IPN_KEY', 'ZcyX...9t7N'); 
}

// ----------------------------------------------------
// 3. 應用程式常數檢查 (確保必要參數存在)
// ----------------------------------------------------

// 確保所有**必要**的常數都已定義 (防呆，確保 LIFF 和 Bot 都能運作)
if (!defined('DB_HOST') || 
    !defined('LINE_CHANNEL_SECRET') ||       // LINE Login Secret (用於 LIFF Token 驗證)
    !defined('LINE_BOT_CHANNEL_SECRET') ||   // Messaging API Secret (用於 Webhook Signature)
    !defined('LINE_BOT_ACCESS_TOKEN') ||     // Messaging API Token (用於 LineService)
    !defined('LINE_LIFF_ID') ||              // LIFF App ID (用於前端初始化)
    !defined('LIFF_DASHBOARD_URL') ||        // LIFF URL (用於 Webhook 回覆)
    !defined('GEMINI_API_KEY') ||
    !defined('NOWPAYMENTS_IPN_KEY') ||       // ✅ 修正：這裡只檢查是否定義 (defined)
    !defined('NOWPAYMENTS_API_KEY') ||
    !defined('BMC_WEBHOOK_SECRET')           // ✅ 修正：語法已修復
) {                                          
    error_log("FATAL: Required environment variables are missing in .env.");
    die("Configuration error. Missing one or more critical LINE/DB/GEMINI/BMC/CRYPTO environment variables.");
}

// 確保其他常數存在 (檢查 CoinGecko Key 載入成功)
// 此處保留原有邏輯，如果非必要金鑰遺失，只記錄錯誤但不中止應用程式。
if (!defined('COINGECKO_API_KEY') && !getenv('COINGECKO_API_KEY')) {
    error_log("WARNING: COINGECKO_API_KEY is missing. Currency rates will use static fallback.");
}

// 設定預設值
if (!defined('GEMINI_MODEL')) define('GEMINI_MODEL', 'gemini-2.5-flash');
if (!defined('BUDGET_WARNING_RATIO')) define('BUDGET_WARNING_RATIO', 0.9);

// --- 會員限制設定 ---
// 免費版：每日口語記帳次數限制
if (!defined('LIMIT_VOICE_TX_DAILY')) define('LIMIT_VOICE_TX_DAILY', 3);

// 免費版：每月/每年 AI 財務健檢次數限制 (這裡設為每月 2 次為例)
if (!defined('LIMIT_HEALTH_CHECK_MONTHLY')) define('LIMIT_HEALTH_CHECK_MONTHLY', 2);

if (!defined('GOOGLE_CLIENT_ID')) {
    define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '251064690633-qgktj8rrpjf3fiqbtqntou7hk32q9e8t.apps.googleusercontent.com');
}
?>