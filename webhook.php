<?php
// webhook.php
// 設置 PHP 錯誤顯示，用於診斷 (上線後建議移除或設為 0)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ----------------------------------------------------
// 1. 載入服務與環境
// ----------------------------------------------------
require_once 'config.php';
require_once 'src/Database.php';
require_once 'src/UserService.php';
require_once 'src/LineService.php';
require_once 'src/TransactionService.php';
require_once 'src/AssetService.php'; 
require_once 'src/ExchangeRateService.php';

// ----------------------------------------------------
// 2. 核心邏輯 Try-Catch 保護
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
    $transactionService = new TransactionService(); 
    $assetService = new AssetService(); 
    $rateService = new ExchangeRateService($dbConn);

    // ----------------------------------------------------
    // 4. 接收與驗證 LINE 傳送的資料 
    // ----------------------------------------------------
    if (!defined('LINE_BOT_CHANNEL_SECRET')) {
        throw new Exception("LINE_BOT_CHANNEL_SECRET is not defined in config.");
    }
    
    $httpRequestBody = file_get_contents('php://input'); 
    
    if (empty($httpRequestBody)) { http_response_code(200); exit("OK"); }
    $data = json_decode($httpRequestBody, true);

    // 🟢 標記是否有新任務需要觸發 Runner
    $hasNewTask = false; 

    // ----------------------------------------------------
    // 5. 處理每一個事件 (Event)
    // ----------------------------------------------------
    if (!empty($data['events'])) {
        foreach ($data['events'] as $event) {
            $replyToken = $event['replyToken'] ?? null;
            $lineUserId = $event['source']['userId'] ?? null;
            $msgType = $event['message']['type'] ?? null;
            $lineMsgId = $event['message']['id'] ?? null;
            
            // 初始化流程控制變數
            $isProcessed = false; 
            $taskContent = null; // 待處理的 AI 任務內容 (文字 或 FILE:路徑)
            $taskType = 'text';  // 任務類型標記 (text / audio / image)

            if (!$lineUserId || !$replyToken) continue;

            $dbUserId = $userService->findOrCreateUser($lineUserId);
            
            // ====================================================
            // 🟢 CASE D: 處理 Postback (按鈕點擊事件 - 資產確認)
            // ====================================================
            if ($event['type'] === 'postback') {
                $postbackData = $event['postback']['data'];
                parse_str($postbackData, $params); // 解析 data 字串 (例如: action=confirm_asset&name=...)

                if (isset($params['action']) && $params['action'] === 'confirm_asset') {
                    // 取出資料
                    $name = $params['name'];
                    $amount = floatval($params['amount']);
                    $type = $params['type'];
                    
                    // 執行寫入資產資料庫
                    $assetService->upsertAccountBalance($dbUserId, $name, $amount, $type, 'TWD');
                    
                    $lineService->replyMessage($replyToken, "✅ 已成功更新：{$name} (NT$ " . number_format($amount) . ")");
                    $isProcessed = true;
                }
            }

            // ====================================================
            // CASE C: 處理新增好友事件 (Follow Event)
            // ====================================================
            elseif ($event['type'] === 'follow') {
                $liffUrl = defined('LIFF_DASHBOARD_URL') ? LIFF_DASHBOARD_URL : 'https://line.me'; 
                
                $welcomeFlex = [
                    'type' => 'bubble',
                    'header' => [
                        'type' => 'box', 'layout' => 'vertical', 'paddingAll' => 'lg', 'backgroundColor' => '#D4A373',
                        'contents' => [['type' => 'text', 'text' => '歡迎使用 FinBot！', 'weight' => 'bold', 'size' => 'lg', 'color' => '#FFFFFF']]
                    ],
                    'body' => [
                        'type' => 'box', 'layout' => 'vertical', 'spacing' => 'md',
                        'contents' => [
                            ['type' => 'text', 'text' => '我是您的 AI 記帳與資產管理助手。', 'wrap' => true, 'color' => '#555555'],
                            ['type' => 'text', 'text' => '您可以直接輸入「午餐 100」記帳，或是「查詢資產」來管理財富。', 'size' => 'sm', 'color' => '#888888', 'wrap' => true]
                        ]
                    ],
                    'footer' => [
                        'type' => 'box', 'layout' => 'vertical', 
                        'contents' => [
                            ['type' => 'button', 'action' => ['type' => 'uri', 'label' => '開啟網頁介面', 'uri' => $liffUrl], 'style' => 'primary', 'color' => '#D4A373']
                        ]
                    ]
                ];
                
                $lineService->replyFlexMessage($replyToken, "歡迎使用 FinBot！", $welcomeFlex);
                $isProcessed = true;
            }

            // ====================================================
            // CASE A: 處理文字訊息 (指令 + AI 任務)
            // ====================================================
            elseif ($event['type'] === 'message' && $msgType === 'text') {
                $text = trim($event['message']['text']);
                $lowerText = strtolower($text); 
                
                // --- 1. 特殊關鍵字指令 (優先處理) ---
                if (str_contains($lowerText, '儀表板') || str_contains($lowerText, 'dashboard')) {
                    if (!defined('LIFF_DASHBOARD_URL')) {
                         $lineService->replyMessage($replyToken, "❌ 錯誤：LIFF 儀表板 URL 尚未配置。");
                    } else {
                        $liffUrl = LIFF_DASHBOARD_URL; 
                        
                        // 🟢 [修改開始]：更新為大地色系風格
                        $flexPayload = [
                            'type' => 'bubble',
                            'size' => 'kilo', // 設定標準寬度
                            'header' => [
                                'type' => 'box',
                                'layout' => 'vertical',
                                'paddingAll' => 'lg',
                                'backgroundColor' => '#D4A373', // 品牌主色 (暖棕色)
                                'contents' => [
                                    [
                                        'type' => 'text', 
                                        'text' => '財務儀表板', 
                                        'weight' => 'bold', 
                                        'size' => 'lg', 
                                        'color' => '#FFFFFF' // 白字
                                    ]
                                ]
                            ],
                            'body' => [
                                'type' => 'box', 
                                'layout' => 'vertical',
                                'contents' => [
                                    [
                                        'type' => 'text', 
                                        'text' => '個人資產管家', 
                                        'weight' => 'bold', 
                                        'size' => 'md', 
                                        'color' => '#8C7B75' // 深棕色副標
                                    ],
                                    [
                                        'type' => 'text', 
                                        'text' => '點擊下方按鈕，即可開啟您的個人淨資產總覽、收支報表與 Crypto 投資組合。', 
                                        'margin' => 'md', 
                                        'size' => 'sm', 
                                        'color' => '#666666', // 柔和深灰內文
                                        'wrap' => true,
                                        'lineSpacing' => '4px'
                                    ]
                                ]
                            ],
                            'footer' => [
                                'type' => 'box',
                                'layout' => 'vertical',
                                'contents' => [
                                    [
                                        'type' => 'button', 
                                        'action' => [
                                            'type' => 'uri', 
                                            'label' => '開啟網頁介面', 
                                            'uri' => $liffUrl
                                        ], 
                                        'style' => 'primary', 
                                        'color' => '#D4A373', // 按鈕改為品牌色
                                        'height' => 'sm'
                                    ]
                                ]
                            ]
                        ];
                        // 🟢 [修改結束]

                        $lineService->replyFlexMessage($replyToken, "開啟財務儀表板", $flexPayload);
                    }
                    $isProcessed = true;
                }
                elseif ($text === '記帳教學' || $text === '教學' || $text === 'help') {
                    
                    $tutorialFlex = [
                        'type' => 'bubble',
                        'size' => 'giga',
                        'header' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'backgroundColor' => '#D4A373',
                            'paddingAll' => 'lg',
                            'contents' => [
                                [
                                    'type' => 'text',
                                    'text' => 'FinBot 使用教學', // 移除 🎓
                                    'weight' => 'bold',
                                    'color' => '#FFFFFF',
                                    'size' => 'xl'
                                ]
                            ]
                        ],
                        'body' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'spacing' => 'md',
                            'contents' => [
                                // 第一區塊：文字記帳
                                [
                                    'type' => 'text',
                                    'text' => '1. 文字記帳', // 移除 1️⃣
                                    'weight' => 'bold',
                                    'color' => '#8C7B75',
                                    'size' => 'md'
                                ],
                                [
                                    'type' => 'text',
                                    'text' => '直接輸入「品項 + 金額」，AI 助手會自動幫您歸類！',
                                    'size' => 'xs',
                                    'color' => '#666666',
                                    'wrap' => true
                                ],
                                [
                                    'type' => 'button',
                                    'style' => 'secondary',
                                    'height' => 'sm',
                                    'color' => '#f7f5f0',
                                    'action' => [
                                        'type' => 'message',
                                        'label' => '試試看：早餐蛋餅 45',
                                        'text' => '早餐蛋餅 45'
                                    ]
                                ],
                                
                                ['type' => 'separator', 'margin' => 'lg'],

                                // 第二區塊：語音記帳
                                [
                                    'type' => 'box',
                                    'layout' => 'horizontal',
                                    'alignItems' => 'center',
                                    'contents' => [
                                        [
                                            'type' => 'text',
                                            'text' => '2. 語音記帳', // 移除 2️⃣
                                            'weight' => 'bold',
                                            'color' => '#8C7B75',
                                            'size' => 'md',
                                            'flex' => 1
                                        ],
                                        [
                                            'type' => 'text',
                                            'text' => '超方便！', // 移除 🎤
                                            'size' => 'xxs',
                                            'color' => '#1DB446',
                                            'weight' => 'bold',
                                            'align' => 'end'
                                        ]
                                    ]
                                ],
                                [
                                    'type' => 'text',
                                    'text' => '不用打字！按住麥克風，像跟朋友聊天一樣說出來即可。',
                                    'size' => 'xs',
                                    'color' => '#666666',
                                    'wrap' => true
                                ],
                                [
                                    'type' => 'box',
                                    'layout' => 'vertical',
                                    'backgroundColor' => '#f0f7f0',
                                    'cornerRadius' => 'md',
                                    'paddingAll' => 'md',
                                    'margin' => 'sm',
                                    'contents' => [
                                        [
                                            'type' => 'text',
                                            'text' => '您可以這樣說：', // 移除 🗣️
                                            'size' => 'xxs',
                                            'color' => '#1DB446',
                                            'weight' => 'bold'
                                        ],
                                        [
                                            'type' => 'text',
                                            'text' => '「昨天晚餐吃火鍋 500元，還有去全聯買菜 300」',
                                            'size' => 'xs',
                                            'color' => '#555555',
                                            'wrap' => true,
                                            'margin' => 'xs'
                                        ]
                                    ]
                                ],

                                ['type' => 'separator', 'margin' => 'lg'],

                                // 第三區塊：查詢報表
                                [
                                    'type' => 'text',
                                    'text' => '3. 查詢資產與收支', // 移除 3️⃣
                                    'weight' => 'bold',
                                    'color' => '#8C7B75',
                                    'size' => 'md'
                                ],
                                [
                                    'type' => 'button',
                                    'style' => 'secondary',
                                    'height' => 'sm',
                                    'action' => [
                                        'type' => 'message',
                                        'label' => '查詢本月收支', // 移除 📊
                                        'text' => '查詢收支'
                                    ]
                                ],
                                [
                                    'type' => 'button',
                                    'style' => 'link',
                                    'height' => 'sm',
                                    'color' => '#D4A373',
                                    'action' => [
                                        'type' => 'message',
                                        'label' => '查詢淨資產', // 移除 💰
                                        'text' => '查詢資產'
                                    ]
                                ]
                            ]
                        ],
                        'footer' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'contents' => [
                                [
                                    'type' => 'text',
                                    'text' => '小撇步：輸入「儀表板」可開啟圖表', // 移除 💡
                                    'size' => 'xs',
                                    'color' => '#aaaaaa',
                                    'align' => 'center'
                                ]
                            ]
                        ]
                    ];

                    $lineService->replyFlexMessage($replyToken, "FinBot 記帳教學", $tutorialFlex);
                    $isProcessed = true;
                }

                elseif ($text === '隱私權政策' || $text === '使用條款' || $text === 'terms') {
                    
                    $termsFlex = [
                        'type' => 'bubble',
                        'size' => 'giga', 
                        'header' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'backgroundColor' => '#5A483C', // 深棕色，傳遞穩重與信任感
                            'paddingAll' => 'lg',
                            'contents' => [
                                [
                                    'type' => 'text',
                                    'text' => '使用條款與隱私權重點',
                                    'weight' => 'bold',
                                    'color' => '#FFFFFF',
                                    'size' => 'lg'
                                ]
                            ]
                        ],
                        'body' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'spacing' => 'md',
                            'contents' => [
                                // 引言
                                [
                                    'type' => 'text',
                                    'text' => 'FinBot 致力於保護您的隱私。以下為我們的服務承諾摘要：',
                                    'size' => 'xs',
                                    'color' => '#888888',
                                    'wrap' => true
                                ],
                                ['type' => 'separator', 'margin' => 'md'],

                                // --- 重點 1：資料收集與用途 ---
                                [
                                    'type' => 'text',
                                    'text' => '1. 資料收集與用途',
                                    'weight' => 'bold',
                                    'color' => '#D4A373', // 品牌強調色
                                    'size' => 'sm',
                                    'margin' => 'lg'
                                ],
                                [
                                    'type' => 'text',
                                    'text' => '我們僅收集必要的 LINE ID、暱稱與您輸入的財務數據，用於提供記帳、資產管理及 AI 分析服務。我們絕不將您的財務數據出售給第三方。',
                                    'size' => 'xs',
                                    'color' => '#555555',
                                    'wrap' => true,
                                    'margin' => 'sm',
                                    'lineSpacing' => '4px' // 增加行距提升閱讀舒適度
                                ],

                                // --- 重點 2：安全與權利 ---
                                [
                                    'type' => 'text',
                                    'text' => '2. 資料安全與用戶權利',
                                    'weight' => 'bold',
                                    'color' => '#D4A373',
                                    'size' => 'sm',
                                    'margin' => 'lg'
                                ],
                                [
                                    'type' => 'text',
                                    'text' => '您的資料傳輸過程採用加密技術 (SSL/TLS) 保護。您擁有隨時查詢、匯出備份及要求刪除帳號（被遺忘權）的完整權利。',
                                    'size' => 'xs',
                                    'color' => '#555555',
                                    'wrap' => true,
                                    'margin' => 'sm',
                                    'lineSpacing' => '4px'
                                ],

                                // --- 重點 3：AI 免責 ---
                                [
                                    'type' => 'text',
                                    'text' => '3. AI 分析免責聲明',
                                    'weight' => 'bold',
                                    'color' => '#D4A373',
                                    'size' => 'sm',
                                    'margin' => 'lg'
                                ],
                                [
                                    'type' => 'text',
                                    'text' => 'AI 生成的理財建議僅供參考，不構成專業投資顧問意見。在做出重大財務決策前，請務必諮詢專業人士。',
                                    'size' => 'xs',
                                    'color' => '#555555',
                                    'wrap' => true,
                                    'margin' => 'sm',
                                    'lineSpacing' => '4px'
                                ],

                                ['type' => 'separator', 'margin' => 'lg'],

                                // --- 聯絡資訊 ---
                                [
                                    'type' => 'box',
                                    'layout' => 'vertical',
                                    'margin' => 'lg',
                                    'spacing' => 'sm',
                                    'contents' => [
                                        [
                                            'type' => 'text',
                                            'text' => '如有任何疑問，歡迎聯繫我們：',
                                            'size' => 'xxs',
                                            'color' => '#aaaaaa',
                                            'align' => 'center'
                                        ],
                                        [
                                            'type' => 'text',
                                            'text' => 'support@finbot.tw',
                                            'size' => 'sm',
                                            'color' => '#264653', // 深色連結感
                                            'weight' => 'bold',
                                            'align' => 'center',
                                            'action' => [
                                                'type' => 'uri',
                                                'label' => 'Email',
                                                'uri' => 'mailto:support@finbot.tw'
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ];

                    $lineService->replyFlexMessage($replyToken, "使用條款與隱私權重點", $termsFlex);
                    $isProcessed = true;
                }

                // --- 2. AI 任務分流 ---
                // 🟢 [修改]：只要不是上述指令，全部視為潛在的 AI 任務 (記帳、查詢、資產設定、閒聊)
                if (!$isProcessed) {
                    $taskContent = $text;
                    $taskType = 'text';
                    
                    // 🟢 回覆一個通用的 AI 思考中卡片，讓用戶知道有收到
                    $flexPayload = [
                        'type' => 'bubble',
                        'size' => 'nano',
                        'body' => [
                            'type' => 'box', 'layout' => 'vertical', 'paddingAll' => 'md',
                            'contents' => [
                                ['type' => 'text', 'text' => '🤔 思考中...', 'color' => '#888888', 'size' => 'xs']
                            ]
                        ]
                    ];
                    $lineService->replyFlexMessage($replyToken, "AI 思考中...", $flexPayload);
                }
            } 
            
            // ====================================================
            // CASE B: 處理語音訊息
            // ====================================================
            elseif ($event['type'] === 'message' && $msgType === 'audio') {
                $audioData = $lineService->getMessageContent($lineMsgId);
                
                if ($audioData) {
                    $tempDir = __DIR__ . '/temp';
                    if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);
                    
                    $fileName = "voice_{$lineMsgId}.m4a";
                    $filePath = $tempDir . '/' . $fileName;
                    
                    if (file_put_contents($filePath, $audioData) !== false) {
                        $taskContent = "FILE:{$filePath}";
                        $taskType = 'audio';
                        // 語音也可以回覆思考中卡片或特定文字
                        $lineService->replyMessage($replyToken, "🎤 收到語音，AI 正在聆聽分析...");
                    } else {
                        $lineService->replyMessage($replyToken, "❌ 系統錯誤：無法儲存語音檔案。");
                        $isProcessed = true;
                    }
                } else {
                    $lineService->replyMessage($replyToken, "❌ 下載語音失敗，請再試一次。");
                    $isProcessed = true;
                }
            }

            // ====================================================
            // CASE B-2: 處理圖片訊息
            // ====================================================
            elseif ($event['type'] === 'message' && $msgType === 'image') {
                $imageData = $lineService->getMessageContent($lineMsgId);
                
                if ($imageData) {
                    $tempDir = __DIR__ . '/temp';
                    if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);
                    
                    $fileName = "image_{$lineMsgId}.jpg";
                    $filePath = $tempDir . '/' . $fileName;
                    
                    if (file_put_contents($filePath, $imageData) !== false) {
                        $taskContent = "FILE:{$filePath}";
                        $taskType = 'image'; 
                        $lineService->replyMessage($replyToken, "📸 收到圖片，AI 正在辨識內容...");
                    } else {
                        $lineService->replyMessage($replyToken, "❌ 系統錯誤：無法儲存圖片檔案。");
                        $isProcessed = true;
                    }
                } else {
                    $lineService->replyMessage($replyToken, "❌ 下載圖片失敗，請再試一次。");
                    $isProcessed = true;
                }
            }

            
            // ====================================================
            // 統一處理 AI 任務 (寫入資料庫)
            // ====================================================
            if (!$isProcessed && $taskContent) {
                
                // --- 1. 權限與額度檢查 ---
                $isPremium = $userService->isPremium($dbUserId);
                
                if (!$isPremium) {
                    $dailyUsage = $userService->getDailyVoiceUsage($dbUserId);
                    $limit = defined('LIMIT_VOICE_TX_DAILY') ? LIMIT_VOICE_TX_DAILY : 30; // 文字通常額度較高
                    
                    if ($dailyUsage >= $limit) {
                        $limitMsg = [
                            'type' => 'bubble',
                            'body' => [
                                'type' => 'box', 'layout' => 'vertical', 'spacing' => 'md',
                                'contents' => [
                                    ['type' => 'text', 'text' => '達到每日額度上限', 'weight' => 'bold', 'color' => '#FF334B', 'size' => 'md'],
                                    ['type' => 'text', 'text' => "您今日的 {$limit} 次免費 AI 記帳額度已用完。", 'size' => 'sm', 'color' => '#555555', 'wrap' => true],
                                    ['type' => 'text', 'text' => '升級 Premium 解鎖無限次使用，並獲得完整財務報表功能！', 'size' => 'sm', 'color' => '#555555', 'wrap' => true],
                                    ['type' => 'button', 'style' => 'primary', 'color' => '#D4A373', 'action' => ['type' => 'uri', 'label' => '了解 Premium 方案', 'uri' => defined('LIFF_DASHBOARD_URL') ? LIFF_DASHBOARD_URL : '#']]
                                ]
                            ]
                        ];
                         $lineService->replyMessage($replyToken, "達到每日額度上限"); // 簡化顯示
                        $isProcessed = true;
                        goto end_of_loop; 
                    }
                }

                // --- 2. 寫入資料庫佇列 ---
                try {
                    $currentLedgerId = $userService->getActiveLedgerId($dbUserId);

                    $stmt = $dbConn->prepare(
                        "INSERT INTO gemini_tasks (line_user_id, ledger_id, user_text, status, created_at) 
                            VALUES (:lineUserId, :ledgerId, :content, 'PENDING', NOW())"
                    );
                    $stmt->execute([
                        ':lineUserId' => $lineUserId, 
                        ':ledgerId'   => $currentLedgerId,
                        ':content'    => $taskContent
                    ]);

                    // 🟢 [關鍵] 標記有新任務，稍後觸發 Runner
                    $hasNewTask = true; 
                    
                    // 3. 根據類型給予回饋 (這裡完全保留您的 Flex Message 設計)
                    if ($taskType === 'audio') {
                        $flexPayload = [
                            'type' => 'bubble',
                            'size' => 'kilo',
                            'body' => [
                                'type' => 'box', 'layout' => 'vertical',
                                'contents' => [
                                    ['type' => 'text', 'text' => '🎤 收到語音', 'weight' => 'bold', 'color' => '#1DB446', 'size' => 'md'],
                                    ['type' => 'text', 'text' => 'AI 正在聆聽並整理您的消費內容，請稍候...', 'margin' => 'md', 'size' => 'sm', 'color' => '#555555', 'wrap' => true],
                                ]
                            ]
                        ];
                        $lineService->replyFlexMessage($replyToken, "收到語音記帳", $flexPayload);
                    
                    } elseif ($taskType === 'image') { 
                        $flexPayload = [
                            'type' => 'bubble',
                            'size' => 'kilo',
                            'body' => [
                                'type' => 'box', 'layout' => 'vertical',
                                'contents' => [
                                    ['type' => 'text', 'text' => '收到圖片', 'weight' => 'bold', 'color' => '#1DB446', 'size' => 'md'],
                                    ['type' => 'text', 'text' => 'AI 正在辨識收據內容，請稍候...', 'margin' => 'md', 'size' => 'sm', 'color' => '#555555', 'wrap' => true],
                                ]
                            ]
                        ];
                        $lineService->replyFlexMessage($replyToken, "收到圖片記帳", $flexPayload);

                    } else {
                        // 文字記帳的回饋
                        $flexPayload = [
                            'type' => 'bubble',
                            'size' => 'kilo',
                            'header' => [
                                'type' => 'box',
                                'layout' => 'vertical',
                                'backgroundColor' => '#D4A373', 
                                'paddingAll' => 'lg',
                                'contents' => [
                                    ['type' => 'text', 'text' => '記帳已送出', 'weight' => 'bold', 'color' => '#FFFFFF', 'size' => 'lg']
                                ]
                            ],
                            'body' => [
                                'type' => 'box', 
                                'layout' => 'vertical',
                                'contents' => [
                                    ['type' => 'text', 'text' => "內容： {$text}", 'color' => '#555555', 'size' => 'sm', 'wrap' => true],
                                    ['type' => 'separator', 'margin' => 'md'],
                                    ['type' => 'text', 'text' => 'AI 助手正在分析中，可繼續其他操作...', 'margin' => 'md', 'size' => 'xs', 'color' => '#aaaaaa']
                                ]
                            ]
                        ];
                        $lineService->replyFlexMessage($replyToken, "記帳已送出", $flexPayload);
                    }

                } catch (Throwable $e) {
                    error_log("Failed to insert task: " . $e->getMessage());
                    $lineService->replyMessage($replyToken, "❌ 系統忙碌，請稍後再試。");
                }
            }
            
            end_of_loop:
            if ($isProcessed) continue; 
        }
    }

    // 🟢 [核心機制] 如果有新任務，非同步觸發 process_tasks.php
    if ($hasNewTask) {
        triggerRunner();
    }

} catch (Throwable $e) {
    error_log("FATAL APPLICATION ERROR: " . $e->getMessage());
    http_response_code(200); 
    echo "Error";
    if (isset($lineService) && isset($replyToken)) {
        // $lineService->replyMessage($replyToken, "系統發生錯誤，請稍後再試。"); 
        // 避免在背景觸發時報錯給用戶
    }
}

// 4. 立即回覆 LINE OK (解除主機資源佔用)
echo "OK";
exit;

// ====================================================
// 🟢 非阻塞觸發函式
// ====================================================
function triggerRunner() {
    // 自動抓取當前網域
    $host = $_SERVER['HTTP_HOST'];
    
    // ⚠️ 假設 process_tasks.php 與 webhook.php 在同一層目錄
    $currentDir = dirname($_SERVER['REQUEST_URI']);
    // Windows/Linux 路徑相容處理
    $path = rtrim($currentDir, '/\\') . "/process_tasks.php";
    
    // 判斷是否為 HTTPS
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
    $scheme = $isHttps ? 'ssl://' : '';
    $port = $isHttps ? 443 : 80;

    $fp = @fsockopen("{$scheme}{$host}", $port, $errno, $errstr, 1);

    if ($fp) {
        // 發送非阻塞請求，不等待回應直接關閉連線
        $out = "GET {$path} HTTP/1.1\r\n";
        $out .= "Host: {$host}\r\n";
        $out .= "Connection: Close\r\n\r\n";
        fwrite($fp, $out);
        fclose($fp);
        error_log("Runner triggered at {$path}");
    } else {
        error_log("Trigger Runner Failed: $errstr ($errno)");
    }
}
?>