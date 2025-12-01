<?php
// process_tasks.php
// 這是由 Crontab 定期執行的後台 Worker 腳本 (Consumer)

// ----------------------------------------------------
// 1. 載入必要的服務 (請確保路徑正確)
// ----------------------------------------------------
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/GeminiService.php';
require_once __DIR__ . '/src/LineService.php';
require_once __DIR__ . '/src/TransactionService.php'; 
require_once __DIR__ . '/src/UserService.php'; 

// ----------------------------------------------------
// 2. 服務初始化
// ----------------------------------------------------
$task = null;
$lineUserId = null; 
try {
    $db = Database::getInstance();
    $dbConn = $db->getConnection();
    $gemini = new GeminiService();
    $lineService = new LineService();
    $transactionService = new TransactionService();
    $userService = new UserService();
} catch (Throwable $e) {
    error_log("Worker Initialization Failed: " . $e->getMessage());
    exit(1); 
}

// ----------------------------------------------------
// 3. 任務處理核心邏輯
// ----------------------------------------------------
try {
    // 3.1. 開始事務：鎖定任務
    $dbConn->beginTransaction();

    // 查找並鎖定 PENDING 任務 (FOR UPDATE 鎖定行)
    $stmt = $dbConn->prepare("SELECT * FROM gemini_tasks WHERE status = 'PENDING' LIMIT 1 FOR UPDATE");
    $stmt->execute();
    $task = $stmt->fetch();

    if (!$task) {
        $dbConn->commit(); 
        exit("No pending tasks to process.");
    }
    
    // 設置任務關鍵變數
    $lineUserId = $task['line_user_id'];
    $userText = $task['user_text'];
    $taskId = $task['id'];
    
    // 標記為 PROCESSING
    $dbConn->prepare("UPDATE gemini_tasks SET status = 'PROCESSING', processed_at = NOW() WHERE id = :id")
           ->execute([':id' => $taskId]);
    
    $dbConn->commit(); // 釋放鎖定，任務已標記，可以繼續處理

    // ----------------------------------------------------
    // 4. 執行 Gemini API 呼叫與數據處理
    // ----------------------------------------------------
    
    // 4.1. 獲取內部用戶 ID (只執行一次)
    $dbUserId = $userService->findOrCreateUser($lineUserId);
    if (!$dbUserId) {
         throw new Exception("Cannot find or create internal user ID for Line ID: {$lineUserId}");
    }

    // 4.2. 呼叫 Gemini (返回 PHP 陣列或 null)
    $resultData = $gemini->parseTransaction($userText); 
    
    // 4.3. 檢查和處理結果
    if (is_array($resultData) && !empty($resultData)) {
        
        // 【容錯處理】：檢查是否為單筆交易物件，如果是則包裝成陣列
        // 判斷依據：如果第一個索引不是 0 (或不存在)，則可能是單個物件 (associative array)
        if (!isset($resultData[0]) || !is_array($resultData[0])) {
            $resultData = [$resultData];
            // 記錄一下，方便診斷是單筆交易還是多筆
            error_log("Task ID {$taskId}: Wrapped single transaction object into array.");
        }
        
        // 4.4. 寫入主交易表
        $successCount = 0;
        
        foreach ($resultData as $transaction) {
            
            // 嚴格檢查：確保是陣列且包含關鍵欄位 (Amount, Category)
            if (is_array($transaction) && isset($transaction['amount']) && isset($transaction['category'])) {
                
                if ($transactionService->addTransaction($dbUserId, $transaction)) {
                    $successCount++;
                } else {
                    error_log("Failed to add transaction for user {$dbUserId}. Data: " . json_encode($transaction, JSON_UNESCAPED_UNICODE));
                }
            }
        }
        
        // 4.5. 更新任務狀態 (將陣列轉回 JSON 字串存入 DB)
        $jsonString = json_encode($resultData, JSON_UNESCAPED_UNICODE); 
        
        $dbConn->prepare("UPDATE gemini_tasks SET status = 'COMPLETED', result_json = :result WHERE id = :id")
           ->execute([':result' => $jsonString, ':id' => $taskId]);

        // 4.6. 推送成功通知
        // $lineService->pushMessage($lineUserId, 
        //     "🎉 記帳完成！成功記錄 {$successCount} 筆交易 (任務ID: {$taskId})。\n請查看您的記帳明細。"
        // );
        
    } else {
        // 4.7. 解析失敗或返回空結果
        $dbConn->prepare("UPDATE gemini_tasks SET status = 'FAILED' WHERE id = :id")
           ->execute([':id' => $taskId]);
           
        // $lineService->pushMessage($lineUserId, 
        //     "❌ 記帳失敗！AI 助手無法解析您的訊息。請試著用簡單的「目的 金額」格式。"
        // );
    }

} catch (Throwable $e) {
    // ----------------------------------------------------
    // 5. 錯誤處理 (如果 Worker 在處理過程中遇到致命錯誤)
    // ----------------------------------------------------
    if ($dbConn->inTransaction()) {
        $dbConn->rollBack();
    }
    error_log("Worker Error Task #{$task['id']}: " . $e->getMessage() . " on line " . $e->getLine());
    
    // 嘗試將任務標記為失敗 (如果狀態允許)
    if (isset($task) && $task['status'] === 'PROCESSING') {
        try {
            $dbConn->prepare("UPDATE gemini_tasks SET status = 'FAILED' WHERE id = :id")
                   ->execute([':id' => $task['id']]);
        } catch (\Throwable $e_db) {
            error_log("Failed to mark task FAILED during critical error: " . $e_db->getMessage());
        }
    }

    // 推送一般錯誤通知給用戶
    if (isset($lineService) && isset($lineUserId)) {
        $lineService->pushMessage($lineUserId, "系統發生嚴重錯誤，請稍後再試。");
    }
}

exit("Task processing finished.");