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
        
        // 【修正點】：同時載入到環境變數中 (供 getenv() 使用)
        putenv("{$name}={$value}"); 
        $_ENV[$name] = $value; // 同步到 $_ENV (可選，但建議保持一致)
    }
}

loadEnv();

// ----------------------------------------------------
// 2. 應用程式常數 (檢查確保)
// ----------------------------------------------------

// 確保所有必要的常數都已定義 (防呆)
if (!defined('DB_HOST') || !defined('LINE_CHANNEL_SECRET') || !defined('GEMINI_API_KEY')) {
    error_log("FATAL: Required environment variables are missing in .env.");
    die("Configuration error.");
}

// 確保其他常數存在 (檢查 CoinGecko Key 載入成功)
if (!defined('COINGECKO_API_KEY') && !getenv('COINGECKO_API_KEY')) {
    error_log("FATAL: COINGECKO_API_KEY is missing.");
    // 這裡不需要 die，因為如果它在 .env 中，它會被載入。
}


if (!defined('GEMINI_MODEL')) define('GEMINI_MODEL', 'gemini-2.5-flash');
if (!defined('BUDGET_WARNING_RATIO')) define('BUDGET_WARNING_RATIO', 0.9);

?>