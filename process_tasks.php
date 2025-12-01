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


        // ----------------------------------------------------
        // 4.6. 【核心】推送 Flex Message 成功通知
        // ----------------------------------------------------
        
        // --- 1. 定義中文對照表 (確保與 webhook.php 一致) ---
        $categoryMap = [
            'Food' => '飲食', 'Transport' => '交通', 'Entertainment' => '娛樂', 
            'Shopping' => '購物', 'Bills' => '帳單', 'Investment' => '投資', 
            'Medical' => '醫療', 'Education' => '教育', 'Miscellaneous' => '雜項', 
            'Allowance' => '津貼', 'Salary' => '薪水'
        ];
        
        // --- 2. 動態生成交易明細列表 ---
        $detailContents = [];
        
        foreach ($resultData as $idx => $tx) {
            $desc = $tx['description'] ?? '未分類項目';
            // 確保金額格式化
            $amt = number_format($tx['amount'] ?? 0); 
            $catKey = $tx['category'] ?? 'Miscellaneous';
            $date = $tx['date'] ?? 'N/A'; 
            $currency = $tx['currency'] ?? 'TWD';
            
            // 獲取中文名稱 (Category Sanitization 確保了 $catKey 是有效的英文 Key)
            $cleanCategoryName = $categoryMap[$catKey] ?? $catKey; 
            
            // 根據類型決定顏色
            $amountColor = ($tx['type'] ?? 'expense') === 'income' ? '#1DB446' : '#FF334B';

            // 添加一筆交易的 Box 結構
            $detailContents[] = [
                'type' => 'box', 
                'layout' => 'vertical', 
                'margin' => 'md',
                'contents' => [
                    // 第一行: 類別與品項名稱
                    ['type' => 'text', 'text' => "【{$cleanCategoryName}】 {$desc}", 'weight' => 'bold', 'size' => 'sm'],
                    // 第二行: 金額與日期 (確認 AI 推斷的資訊)
                    ['type' => 'box', 'layout' => 'baseline', 'margin' => 'xs',
                        'contents' => [
                            ['type' => 'text', 'text' => "💵 \${$amt} {$currency}", 'size' => 'sm', 'color' => $amountColor, 'flex' => 0],
                            ['type' => 'text', 'text' => "📅 {$date}", 'size' => 'xs', 'color' => '#AAAAAA', 'align' => 'end']
                        ]
                    ],
                    ['type' => 'separator', 'margin' => 'md']
                ]
            ];
        }
        
        // --- 3. 組裝完整的 Flex Bubble ---
        $flexPayload = [
            'type' => 'bubble',
            'size' => 'kilo',
            // Header: 標題與筆數 (綠色成功背景)
            'header' => [
                'type' => 'box', 'layout' => 'vertical', 'paddingAll' => 'lg', 'backgroundColor' => '#27AE60',
                'contents' => [
                    ['type' => 'text', 'text' => "🎉 記帳成功 ({$successCount}筆)", 'weight' => 'bold', 'size' => 'md', 'color' => '#FFFFFF'],
                ]
            ],
            // Body: 明細列表
            'body' => [
                'type' => 'box', 'layout' => 'vertical', 'spacing' => 'sm',
                'contents' => $detailContents
            ],
            // Footer: 確認訊息
            'footer' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => '數據已存入資料庫，感謝您的使用。', 'color' => '#AAAAAA', 'align' => 'center', 'size' => 'xs']
                ]
            ]
        ];

        // 4. 發送 Flex Message
        $altText = "🎉 成功記錄 {$successCount} 筆交易";
        $lineService->pushFlexMessage($lineUserId, $altText, $flexPayload);
        
    } else {
        // 4.7. 解析失敗或返回空結果 (使用純文字推送失敗通知)
        $dbConn->prepare("UPDATE gemini_tasks SET status = 'FAILED' WHERE id = :id")
           ->execute([':id' => $taskId]);
           
        $lineService->pushMessage($lineUserId, "❌ 記帳失敗！AI 助手無法解析您的訊息。");
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