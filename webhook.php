<?php
// 設置 PHP 錯誤顯示，用於診斷 (測試完成後應移除或設為 0)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ----------------------------------------------------
// 1. 載入服務與環境 (確保路徑正確)
// ----------------------------------------------------
require_once 'config.php';
require_once 'src/Database.php';
require_once 'src/UserService.php';
require_once 'src/LineService.php';
require_once 'src/TransactionService.php'; // 【新增】需要載入交易服務來查詢數據

// ----------------------------------------------------
// 2. 核心邏輯 Try-Catch 保護 (防止 Bot 靜默崩潰)
// ----------------------------------------------------
$replyToken = null; 
$lineService = null;

try {
    // ----------------------------------------------------
    // 3. 服務初始化
    // ----------------------------------------------------
    $db = Database::getInstance(); 
    $dbConn = $db->getConnection(); 
    
    $userService = new UserService();
    $lineService = new LineService(); 
    $transactionService = new TransactionService(); // 【新增】實例化

    // ----------------------------------------------------
    // 4. 接收與驗證 LINE 傳送的資料
    // ----------------------------------------------------
    $channelSecret = LINE_CHANNEL_SECRET;
    $httpRequestBody = file_get_contents('php://input'); 
    
    if (empty($httpRequestBody)) {
        http_response_code(200);
        exit("OK");
    }

    // 執行簽章驗證
    $hash = hash_hmac('sha256', $httpRequestBody, $channelSecret, true);
    $signature = base64_encode($hash);
    $receivedSignature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';

    if ($receivedSignature !== $signature) {
        error_log("Security Alert: Invalid LINE signature received.");
        http_response_code(200); 
        exit("OK");
    }

    $data = json_decode($httpRequestBody, true);

    // ----------------------------------------------------
    // 5. 處理每一個事件 (Event)
    // ----------------------------------------------------
    if (!empty($data['events'])) {
        foreach ($data['events'] as $event) {
            $replyToken = $event['replyToken'] ?? null;
            $lineUserId = $event['source']['userId'] ?? null;
            
            if (!$lineUserId || !$replyToken) continue;

            // 確保用戶已在資料庫中註冊
            $dbUserId = $userService->findOrCreateUser($lineUserId);
            
            // 處理文字訊息
            if ($event['type'] === 'message' && $event['message']['type'] === 'text') {
                $text = trim($event['message']['text']);
                $replyText = "";

                // ----------------------------------------------------
                // 【調整：Flex Message 視覺化報表】
                // ----------------------------------------------------
                if (in_array($text, ['查詢', '本月支出', '報表', '總覽', '支出', '收入'])) {
                    
                    // 1. 獲取數據
                    $incomeData  = $transactionService->getMonthlyBreakdown($dbUserId, 'income');
                    $expenseData = $transactionService->getMonthlyBreakdown($dbUserId, 'expense');
                    
                    $totalIncome  = array_sum($incomeData);
                    $totalExpense = array_sum($expenseData);
                    $balance      = $totalIncome - $totalExpense;
                    
                    $month = date('n');
                    $currency = defined('DEFAULT_CURRENCY_SYMBOL') ? DEFAULT_CURRENCY_SYMBOL : '元';

                    // 2. 定義中文對照表
                    $categoryMap = [
                        'Food' => '🍱 飲食', 'Transport' => '🚗 交通', 'Entertainment' => '🎮 娛樂',
                        'Shopping' => '🛍️ 購物', 'Bills' => '🧾 帳單', 'Medical' => '💊 醫療',
                        'Education' => '📚 教育', 'Salary' => '💰 薪水', 'Allowance' => '🧧 獎金',
                        'Investment' => '📈 投資', 'Miscellaneous' => '🔹 雜項','Sales' => '💰 賣物',
                    ];

                    // 3. 建構 Flex Message 的內容區塊 (Body)
                    // 我們需要動態產生「行 (Box)」
                    $bodyContents = [];

                    // --- A. 收入區塊 ---
                    if ($totalIncome > 0) {
                        $bodyContents[] = [
                            'type' => 'text', 'text' => '📥 本月收入', 'weight' => 'bold', 'color' => '#1DB446', 'size' => 'sm'
                        ];
                        foreach ($incomeData as $cat => $amt) {
                            $name = $categoryMap[$cat] ?? $cat;
                            $bodyContents[] = [
                                'type' => 'box', 'layout' => 'baseline', 'margin' => 'md',
                                'contents' => [
                                    ['type' => 'text', 'text' => $name, 'size' => 'sm', 'color' => '#555555', 'flex' => 0],
                                    ['type' => 'text', 'text' => number_format($amt), 'size' => 'sm', 'color' => '#111111', 'align' => 'end']
                                ]
                            ];
                        }
                        // 加個分隔線
                        $bodyContents[] = ['type' => 'separator', 'margin' => 'lg'];
                    }

                    // --- B. 支出區塊 ---
                    // 加一點間距
                    $bodyContents[] = ['type' => 'box', 'layout' => 'vertical', 'margin' => 'lg', 'contents' => []]; 
                    
                    $bodyContents[] = [
                        'type' => 'text', 'text' => '💸 本月支出', 'weight' => 'bold', 'color' => '#FF334B', 'size' => 'sm'
                    ];

                    if ($totalExpense > 0) {
                        foreach ($expenseData as $cat => $amt) {
                            $name = $categoryMap[$cat] ?? $cat;
                            $bodyContents[] = [
                                'type' => 'box', 'layout' => 'baseline', 'margin' => 'md',
                                'contents' => [
                                    ['type' => 'text', 'text' => $name, 'size' => 'sm', 'color' => '#555555', 'flex' => 0],
                                    ['type' => 'text', 'text' => number_format($amt), 'size' => 'sm', 'color' => '#111111', 'align' => 'end']
                                ]
                            ];
                        }
                    } else {
                        $bodyContents[] = ['type' => 'text', 'text' => '無支出記錄', 'size' => 'xs', 'color' => '#aaaaaa', 'margin' => 'md'];
                    }

                    // 4. 組裝完整的 Flex Bubble 結構
                    // 根據結餘決定顏色 (正: 藍色, 負: 紅色)
                    $balanceColor = $balance >= 0 ? '#007AFF' : '#FF334B';
                    $balanceText  = ($balance >= 0 ? '+' : '') . number_format($balance);

                    $flexPayload = [
                        'type' => 'bubble',
                        'size' => 'mega',
                        // --- 頭部：標題 ---
                        'header' => [
                            'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#f8f9fa',
                            'contents' => [
                                ['type' => 'text', 'text' => "{$month}月財務報表", 'weight' => 'bold', 'size' => 'xl', 'color' => '#333333']
                            ]
                        ],
                        // --- 英雄區：大大的結餘 ---
                        'hero' => [
                            'type' => 'box', 'layout' => 'vertical', 'paddingAll' => 'xl', 'paddingBottom' => 'none',
                            'contents' => [
                                ['type' => 'text', 'text' => '本月結餘', 'color' => '#aaaaaa', 'size' => 'xs', 'align' => 'center'],
                                ['type' => 'text', 'text' => "$balanceText", 'weight' => 'bold', 'size' => '4xl', 'color' => $balanceColor, 'align' => 'center', 'margin' => 'sm'],
                                ['type' => 'text', 'text' => $currency, 'size' => 'xs', 'color' => '#aaaaaa', 'align' => 'center']
                            ]
                        ],
                        // --- 內容區：收入與支出列表 ---
                        'body' => [
                            'type' => 'box', 'layout' => 'vertical',
                            'contents' => $bodyContents
                        ],
                        // --- 底部：小字 ---
                        'footer' => [
                            'type' => 'box', 'layout' => 'vertical',
                            'contents' => [
                                ['type' => 'text', 'text' => 'AI 記帳助手', 'color' => '#cccccc', 'align' => 'center', 'size' => 'xxs']
                            ]
                        ]
                    ];
                    
                    // 5. 發送 Flex Message
                    $lineService->replyFlexMessage($replyToken, "{$month}月財務報表", $flexPayload);
                    break; 
                }

                // ----------------------------------------------------
                // 【前端過濾器】檢查記帳內容 (數字檢查)
                // ----------------------------------------------------
                // 包含：0-9, 零, 一... 萬, 億
                $chinese_digits = '零一二三四五六七八九壹貳參肆伍陸柒捌玖拾佰仟萬億';
                $regex = '/[\d' . $chinese_digits . ']/u'; 

                $hasAmount = preg_match($regex, $text);
                
                if (!$hasAmount) {
                    // 偵測不到金額，也不是查詢指令 -> 回覆提示
                    $replyText = "❓ 我聽不懂...\n請輸入包含金額的記帳內容 (例如：午餐 120)，或輸入「查詢」查看本月支出。";
                } else {
                    // --- 異步核心邏輯：將任務快速推入佇列 ---
                    try {
                        $stmt = $dbConn->prepare(
                            "INSERT INTO gemini_tasks (line_user_id, user_text, status) 
                             VALUES (:lineUserId, :text, 'PENDING')"
                        );
                        $stmt->execute([':lineUserId' => $lineUserId, ':text' => $text]);

                        // 【修改點】：使用 Flex Message 替換純文字回覆
                        $flexPayload = [
                            'type' => 'bubble',
                            'body' => [
                                'type' => 'box',
                                'layout' => 'vertical',
                                'contents' => [
                                    ['type' => 'text', 'text' => '✅ 記帳已送出', 'weight' => 'bold', 'color' => '#1DB446', 'size' => 'md'],
                                    ['type' => 'text', 'text' => "內容： {$text}", 'margin' => 'sm', 'size' => 'xs', 'color' => '#555555'],
                                    ['type' => 'text', 'text' => 'AI 助手正在後台解析中，您可繼續操作功能，稍後通知您。', 'margin' => 'md', 'size' => 'sm', 'wrap' => true],
                                ]
                            ]
                        ];
                        
                        // 立即回覆 Line，避免 Webhook 超時
                        $lineService->replyFlexMessage($replyToken, "記帳已送出", $flexPayload);

                        // 由於使用了 Flex 專屬方法，我們在成功時不需要再執行後面的 $lineService->replyMessage
                        break; 

                    } catch (Throwable $e) {
                        error_log("Failed to insert task for user {$lineUserId}: " . $e->getMessage());
                        $replyText = "系統忙碌，無法將您的記帳訊息加入處理佇列。請稍後再試。";
                        // 失敗時，退回使用純文字回覆
                        $lineService->replyMessage($replyToken, $replyText);
                    }
                }
                
                // 由於成功的路徑已經 break，這裡只剩下失敗或無效指令的路徑
                if (!isset($flexPayload)) {
                    $lineService->replyMessage($replyToken, $replyText);
                }
                
            } elseif ($event['type'] === 'follow' && $replyToken) {
                 // 處理追蹤事件
                 $welcomeMessage = "歡迎使用！\n直接輸入：買咖啡 80元。\n或輸入「查詢」看報表。";
                 $lineService->replyMessage($replyToken, $welcomeMessage);
            }

            break; // 每次只處理一個事件
        }
    }

    // ----------------------------------------------------
    // 6. 成功結束
    // ----------------------------------------------------
    http_response_code(200);
    echo "OK";

} catch (Throwable $e) {
    // ----------------------------------------------------
    // 7. 錯誤處理
    // ----------------------------------------------------
    error_log("FATAL APPLICATION ERROR: " . $e->getMessage());
    http_response_code(200); 
    echo "Error";

    if (isset($lineService) && isset($replyToken)) {
        $lineService->replyMessage($replyToken, "系統發生錯誤，請稍後再試。");
    }
}