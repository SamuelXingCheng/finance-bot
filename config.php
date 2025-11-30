<?php
/**
 * config.php
 * 負責從 .env 檔案載入所有環境變數，並定義為全域常數。
 */

// ----------------------------------------------------
// 1. 載入 .env 檔案並解析為全域常數
// ----------------------------------------------------
function loadEnv($path = __DIR__ . '/.env') {
    if (!file_exists($path)) {
        error_log("FATAL: The .env file not found at: {$path}");
        die("Configuration failed. .env file missing.");
    }

    // 移除 FILE_IGNORE_EMPTY_LINES | FILE_SKIP_NEW_LINES 旗標，
    // 改為使用 file() 基礎讀取，並在迴圈中手動處理空行和註釋。
    $lines = file($path); 

    foreach ($lines as $line) {
        $line = trim($line);
        
        // 增加檢查：跳過空行
        if (empty($line)) {
            continue; 
        }

        if (strpos($line, '#') === 0) {
            continue; // 跳過註釋
        }
        
        // 簡單解析：NAME=VALUE
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        
        // 清理值：移除開頭和結尾的空格或引號
        $value = trim($value, " \n\r\t\v\x00\"'"); 

        if (!defined($name)) {
            // 將環境變數定義為全域常數
            define($name, $value);
        }
    }
}

loadEnv();

// ----------------------------------------------------
// 2. 應用程式常數 (現在這些常數都是從 .env 定義的)
// ----------------------------------------------------

// 確保所有必要的常數都已定義 (防呆)
if (!defined('DB_HOST') || !defined('LINE_CHANNEL_SECRET') || !defined('GEMINI_API_KEY')) {
    error_log("FATAL: Required environment variables are missing in .env.");
    die("Configuration error.");
}

// 由於所有設定都已在 loadEnv() 中定義為常數，這裡只需確保它們存在即可。
// 範例：如果 .env 中沒有定義，則給予預設值 (但我們建議在 .env 中定義所有內容)
if (!defined('GEMINI_MODEL')) define('GEMINI_MODEL', 'gemini-2.5-flash');
if (!defined('BUDGET_WARNING_RATIO')) define('BUDGET_WARNING_RATIO', 0.9);

?>