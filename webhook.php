<?php
// 設置 PHP 錯誤顯示，用於診斷 (測試完成後應移除或設為 0)
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
    $rateService = new ExchangeRateService();

    // ----------------------------------------------------
    // 4. 接收與驗證 LINE 傳送的資料 
    // ----------------------------------------------------
    if (!defined('LINE_BOT_CHANNEL_SECRET')) {
        throw new Exception("LINE_BOT_CHANNEL_SECRET is not defined in config.");
    }
    $channelSecret = LINE_BOT_CHANNEL_SECRET; 
    
    $httpRequestBody = file_get_contents('php://input'); 
    
    if (empty($httpRequestBody)) { http_response_code(200); exit("OK"); }
    $data = json_decode($httpRequestBody, true);

    // ----------------------------------------------------
    // 5. 處理每一個事件 (Event)
    // ----------------------------------------------------
    if (!empty($data['events'])) {
        foreach ($data['events'] as $event) {
            $replyToken = $event['replyToken'] ?? null;
            $lineUserId = $event['source']['userId'] ?? null;
            
            if (!$lineUserId || !$replyToken) continue;

            $dbUserId = $userService->findOrCreateUser($lineUserId);
            
            // 處理文字訊息
            if ($event['type'] === 'message' && $event['message']['type'] === 'text') {
                $text = trim($event['message']['text']);
                $lowerText = strtolower($text); 
                $replyText = "";
                $isProcessed = false; 

                // ====================================================
                // 【LIFF 儀表板指令 - 最高優先級】
                // ====================================================
                if (str_contains($lowerText, '儀表板') || str_contains($lowerText, 'dashboard')) {
                    if (!defined('LIFF_DASHBOARD_URL')) {
                         $lineService->replyMessage($replyToken, "❌ 錯誤：LIFF 儀表板 URL 尚未配置。");
                         $isProcessed = true;
                    } else {
                        $liffUrl = LIFF_DASHBOARD_URL; 
                        $flexPayload = [
                            'type' => 'bubble',
                            'body' => [
                                'type' => 'box', 'layout' => 'vertical',
                                'contents' => [
                                    ['type' => 'text', 'text' => '📊 財務儀表板', 'weight' => 'bold', 'size' => 'xl', 'color' => '#007AFF'],
                                    ['type' => 'text', 'text' => '點擊按鈕，即可開啟您的個人淨資產總覽與報表。', 'margin' => 'md', 'size' => 'sm', 'wrap' => true],
                                    ['type' => 'button', 'action' => ['type' => 'uri', 'label' => '開啟儀表板 (LIFF)', 'uri' => $liffUrl], 'style' => 'primary', 'color' => '#00B900', 'margin' => 'xl']
                                ]
                            ]
                        ];
                        $lineService->replyFlexMessage($replyToken, "開啟財務儀表板", $flexPayload);
                        $isProcessed = true;
                    }
                } 
                
                // ====================================================
                // 【資產設定指令】 
                // ====================================================
                if (!$isProcessed && preg_match('/^設定\s+([^\s]+)\s+([^\s]+)\s+([-\d\.,]+)(.*?)$/u', $text, $matches)) {
                    $name = trim($matches[1]);
                    $typeInput = trim($matches[2]);
                    $balanceInputRaw = trim($matches[3]);
                    $currencyUnitRaw = trim($matches[4]);

                    $balanceInput = str_replace([',', ' '], '', $balanceInputRaw); 
                    $currencyUnit = strtoupper(preg_replace('/[^A-Z]/i', '', $currencyUnitRaw)); 
                    if (empty($currencyUnit)) {
                        $currencyUnit = 'TWD';
                        $balanceInput = str_replace(['元', '塊', 'NT', 'NTD'], '', $balanceInput); 
                    }
                    $balance = (float)$balanceInput;

                    $success = $assetService->upsertAccountBalance($dbUserId, $name, $balance, $typeInput, $currencyUnit);
                    $type = $assetService->sanitizeAssetType($typeInput);

                    if ($success) {
                        $formattedBalance = number_format($balance, 8, '.', ''); 
                        $trimmedZeros = rtrim($formattedBalance, '0');
                        $displayBalance = rtrim($trimmedZeros, '.');

                        $flexPayload = [
                            'type' => 'bubble', 'size' => 'kilo',
                            'header' => ['type' => 'box', 'layout' => 'vertical', 'paddingAll' => 'lg', 'backgroundColor' => '#1DB446',
                                'contents' => [['type' => 'text', 'text' => "✅ 資產更新成功", 'weight' => 'bold', 'size' => 'md', 'color' => '#FFFFFF']]
                            ],
                            'body' => [
                                'type' => 'box', 'layout' => 'vertical', 'spacing' => 'md',
                                'contents' => [
                                    ['type' => 'text', 'text' => "帳戶名稱：{$name}", 'size' => 'sm', 'color' => '#555555'],
                                    ['type' => 'text', 'text' => "帳戶類型：{$type}", 'size' => 'sm', 'color' => '#555555'],
                                    ['type' => 'separator', 'margin' => 'md'],
                                    ['type' => 'text', 'text' => '最新餘額', 'size' => 'sm', 'color' => '#AAAAAA'],
                                    ['type' => 'text', 'text' => "{$currencyUnit} " . $displayBalance, 'weight' => 'bold', 'size' => 'xl', 'color' => '#111111'],
                                ]
                            ]
                        ];
                        $lineService->replyFlexMessage($replyToken, "資產更新成功", $flexPayload);
                    } else {
                        $lineService->replyMessage($replyToken, "❌ 資產更新失敗，請檢查格式或聯繫客服。");
                    }
                    $isProcessed = true;
                } 
                
                // ====================================================
                // 【資產查詢指令】
                // ====================================================
                elseif (!$isProcessed && in_array($text, ['查詢資產', '資產總覽', '淨值'])) {
                    $result = $assetService->getNetWorthSummary($dbUserId);
                    $summary = $result['breakdown'];
                    $globalNetWorthTWD = $result['global_twd_net_worth'];
                    $usdTwdRate = $result['usdTwdRate'];
                    
                    $assetBodyContents = [];
                    $rateContents = [];
                    
                    $globalNetWorthText = number_format($globalNetWorthTWD, 2);
                    $textLength = strlen($globalNetWorthText);
                    $heroSize = ($textLength > 16) ? 'xl' : (($textLength > 12) ? 'xxl' : '3xl');
                    $globalNetWorthColor = $globalNetWorthTWD >= 0 ? '#007AFF' : '#FF334B';
                    
                    if (!empty($summary)) {
                        foreach ($summary as $currency => $data) {
                            $assetsDisplay = rtrim(rtrim(number_format($data['assets'], 8), '0'), '.');
                            $liabilitiesDisplay = rtrim(rtrim(number_format($data['liabilities'], 8), '0'), '.');
                            $netWorthDisplay = rtrim(rtrim(number_format($data['net_worth'], 8), '0'), '.');
                            $twdTotal = number_format($data['twd_total'], 2);

                            $netWorthColor = $data['net_worth'] >= 0 ? '#1DB446' : '#FF334B';
                            $netWorthEmoji = $data['net_worth'] >= 0 ? '🟢' : '🔴';

                            $assetBodyContents[] = [
                                'type' => 'text', 'text' => "🏦 {$currency} 資產總覽", 'weight' => 'bold', 'color' => '#333333', 'size' => 'md', 'margin' => 'xl'
                            ];
                            
                            $assetBodyContents[] = [
                                'type' => 'box', 'layout' => 'vertical', 'spacing' => 'sm', 'margin' => 'md',
                                'contents' => [
                                    ['type' => 'box', 'layout' => 'horizontal', 'contents' => [
                                        ['type' => 'text', 'text' => '總資產', 'size' => 'sm', 'color' => '#555555', 'flex' => 1],
                                        ['type' => 'text', 'text' => "{$currency} {$assetsDisplay}", 'size' => 'sm', 'color' => '#1DB446', 'align' => 'end', 'flex' => 1]
                                    ]],
                                    ['type' => 'box', 'layout' => 'horizontal', 'contents' => [
                                        ['type' => 'text', 'text' => '總負債', 'size' => 'sm', 'color' => '#555555', 'flex' => 1],
                                        ['type' => 'text', 'text' => "{$currency} {$liabilitiesDisplay}", 'size' => 'sm', 'color' => '#FF334B', 'align' => 'end', 'flex' => 1]
                                    ]],
                                    ['type' => 'separator', 'margin' => 'md'],
                                    ['type' => 'box', 'layout' => 'horizontal', 'contents' => [
                                        ['type' => 'text', 'text' => '淨值', 'size' => 'md', 'weight' => 'bold', 'flex' => 1],
                                        ['type' => 'text', 'text' => "{$netWorthEmoji} {$netWorthDisplay}", 'size' => 'md', 'weight' => 'bold', 'color' => $netWorthColor, 'align' => 'end', 'flex' => 1]
                                    ]],
                                    ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'xs', 'contents' => [
                                        ['type' => 'text', 'text' => 'TWD 價值', 'size' => 'xs', 'color' => '#AAAAAA', 'flex' => 1],
                                        ['type' => 'text', 'text' => "NT$ {$twdTotal}", 'size' => 'xs', 'color' => '#555555', 'align' => 'end', 'flex' => 1]
                                    ]],
                                ]
                            ];
                            
                            if ($currency !== 'TWD') {
                                $rateToUSD = $rateService->getRateToUSD($currency); 
                                $isCrypto = isset(ExchangeRateService::COIN_ID_MAP[$currency]);
                                
                                if ($isCrypto) {
                                    $rateDisplayCurrency = 'USD';
                                    $rateToDisplay = $rateToUSD;
                                    $ratePrecision = 2; 
                                } else {
                                    $rateDisplayCurrency = 'NT$';
                                    $rateToDisplay = $rateToUSD * $usdTwdRate; 
                                    $ratePrecision = 4; 
                                }
                                $rateDisplay = number_format($rateToDisplay, $ratePrecision);

                                $rateContents[] = [
                                    'type' => 'box', 'layout' => 'horizontal', 'margin' => 'sm',
                                    'contents' => [
                                        ['type' => 'text', 'text' => "1 {$currency} =", 'size' => 'xs', 'color' => '#555555', 'flex' => 0],
                                        ['type' => 'text', 'text' => "{$rateDisplayCurrency} {$rateDisplay}", 'size' => 'xs', 'color' => '#111111', 'align' => 'end', 'flex' => 1]
                                    ]
                                ];
                            }
                        }

                        if (!empty($rateContents)) {
                            $assetBodyContents[] = ['type' => 'separator', 'margin' => 'xl'];
                            $assetBodyContents[] = ['type' => 'text', 'text' => '實時匯率參考', 'weight' => 'bold', 'size' => 'sm', 'margin' => 'lg'];
                            $assetBodyContents = array_merge($assetBodyContents, $rateContents);
                            $assetBodyContents[] = ['type' => 'separator', 'margin' => 'md'];
                            $assetBodyContents[] = [
                                'type' => 'box', 'layout' => 'horizontal', 'margin' => 'sm',
                                'contents' => [
                                    ['type' => 'text', 'text' => "1 USD =", 'size' => 'sm', 'color' => '#333333', 'weight' => 'bold', 'flex' => 0],
                                    ['type' => 'text', 'text' => "NT$ " . number_format($usdTwdRate, 4), 'size' => 'sm', 'color' => '#111111', 'align' => 'end', 'flex' => 1]
                                ]
                            ];
                        }
                    } else {
                        $assetBodyContents[] = ['type' => 'text', 'text' => '目前沒有任何資產記錄。請輸入「設定...」新增。', 'size' => 'sm', 'color' => '#AAAAAA', 'margin' => 'xl'];
                    }

                    $flexPayload = [
                        'type' => 'bubble', 'size' => 'mega',
                        'header' => ['type' => 'box', 'layout' => 'vertical', 'paddingAll' => 'lg', 'contents' => [['type' => 'text', 'text' => '淨資產總覽', 'weight' => 'bold', 'size' => 'xl']]],
                        'hero' => [
                            'type' => 'box', 'layout' => 'vertical', 'paddingAll' => 'xl', 'paddingBottom' => 'none',
                            'contents' => [
                                ['type' => 'text', 'text' => '全球淨值 (TWD)', 'color' => '#aaaaaa', 'size' => 'xs', 'align' => 'center'],
                                ['type' => 'text', 'text' => "NT$ {$globalNetWorthText}", 'weight' => 'bold', 'size' => $heroSize, 'color' => $globalNetWorthColor, 'align' => 'center', 'margin' => 'sm'],
                                ['type' => 'text', 'text' => '依據快照匯率計算', 'size' => 'xs', 'color' => '#aaaaaa', 'align' => 'center']
                            ]
                        ],
                        'body' => ['type' => 'box', 'layout' => 'vertical', 'contents' => $assetBodyContents],
                        'footer' => ['type' => 'box', 'layout' => 'vertical', 'contents' => [
                            ['type' => 'text', 'text' => '輸入「設定 帳戶名 類型 金額 幣種」更新。', 'color' => '#BBBBBB', 'size' => 'xxs', 'align' => 'center'],
                            ['type' => 'box', 'layout' => 'horizontal', 'contents' => [
                                ['type' => 'text', 'text' => 'Powered by CoinGecko', 'color' => '#AAAAAA', 'size' => 'xxs', 'align' => 'center', 'action' => ['type' => 'uri', 'label' => 'CoinGecko', 'uri' => 'https://www.coingecko.com'], 'flex' => 1]
                            ]]
                        ]]
                    ];

                    $lineService->replyFlexMessage($replyToken, "淨資產總覽", $flexPayload);
                    $isProcessed = true;
                }
                
                // ====================================================
                // 【記帳查詢 / 報表指令】
                // ====================================================
                elseif (!$isProcessed && in_array($text, ['查詢收支', '收支出', '報表', '總覽', '支出', '收入'])) {
                    $totalExpense = $transactionService->getTotalExpenseByMonth($dbUserId); 
                    $totalIncome = $transactionService->getTotalIncomeByMonth($dbUserId);
                    $netIncome = $totalIncome - $totalExpense;
                    $assetResult = $assetService->getNetWorthSummary($dbUserId);
                    $globalNetWorth = $assetResult['global_twd_net_worth'] ?? 0;

                    $fmtExpense = number_format($totalExpense);
                    $fmtIncome = number_format($totalIncome);
                    $fmtNet = number_format($netIncome);
                    $fmtAsset = number_format($globalNetWorth);
                    $balanceColor = $netIncome >= 0 ? '#1DB446' : '#FF334B';

                    $flexPayload = [
                        'type' => 'bubble', 'size' => 'kilo',
                        'header' => [
                            'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#f7f9fc', 'paddingAll' => 'lg',
                            'contents' => [['type' => 'text', 'text' => '📊 本月財務概況', 'weight' => 'bold', 'size' => 'lg', 'color' => '#555555']]
                        ],
                        'body' => [
                            'type' => 'box', 'layout' => 'vertical', 'spacing' => 'md',
                            'contents' => [
                                ['type' => 'box', 'layout' => 'horizontal', 'contents' => [
                                    ['type' => 'text', 'text' => '總收入', 'size' => 'sm', 'color' => '#555555', 'flex' => 1],
                                    ['type' => 'text', 'text' => "NT$ {$fmtIncome}", 'size' => 'sm', 'color' => '#1DB446', 'weight' => 'bold', 'align' => 'end', 'flex' => 2]
                                ]],
                                ['type' => 'box', 'layout' => 'horizontal', 'contents' => [
                                    ['type' => 'text', 'text' => '總支出', 'size' => 'sm', 'color' => '#555555', 'flex' => 1],
                                    ['type' => 'text', 'text' => "NT$ {$fmtExpense}", 'size' => 'sm', 'color' => '#FF334B', 'weight' => 'bold', 'align' => 'end', 'flex' => 2]
                                ]],
                                ['type' => 'separator', 'margin' => 'md'],
                                ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'md', 'contents' => [
                                    ['type' => 'text', 'text' => '本月結餘', 'size' => 'md', 'weight' => 'bold', 'color' => '#333333', 'flex' => 1, 'gravity' => 'center'],
                                    ['type' => 'text', 'text' => "NT$ {$fmtNet}", 'size' => 'xl', 'weight' => 'bold', 'color' => $balanceColor, 'align' => 'end', 'flex' => 2]
                                ]],
                            ]
                        ],
                        'footer' => [
                            'type' => 'box', 'layout' => 'vertical',
                            'contents' => [
                                ['type' => 'text', 'text' => "💰 目前總資產: NT$ {$fmtAsset}", 'size' => 'xs', 'color' => '#aaaaaa', 'align' => 'center', 'margin' => 'sm'],
                                ['type' => 'button', 'action' => ['type' => 'message', 'label' => '查看資產明細', 'text' => '查詢資產'], 'height' => 'sm', 'style' => 'link', 'margin' => 'sm']
                            ]
                        ]
                    ];
                    $lineService->replyFlexMessage($replyToken, "本月財務報表", $flexPayload);
                    $isProcessed = true;
                }
                
                // ====================================================
                // 【記帳與過濾器邏輯】(非指令時執行)
                // =====================================================
                if (!$isProcessed) {
                    $chinese_digits = '零一二三四五六七八九壹貳參肆伍陸柒捌玖拾佰仟萬億';
                    $regex = '/[\d' . $chinese_digits . ']/u'; 
                    $hasAmount = preg_match($regex, $text);
                    
                    if (!$hasAmount) {
                        $replyText = "❓ 我聽不懂...\n請輸入包含金額的記帳內容 (例如：午餐 120)，或輸入「查詢資產」查看淨值。";
                        $lineService->replyMessage($replyToken, $replyText); 
                    } else {
                        // =========== 🔴 新增限制檢查邏輯 START ===========
                        $isPremium = $userService->isPremium($dbUserId);
                        
                        if (!$isPremium) {
                            // 如果是免費會員，檢查今日用量
                            $dailyUsage = $userService->getDailyVoiceUsage($dbUserId);
                            
                            // 讀取 Config 常數，若未定義則給預設值 3
                            $limit = defined('LIMIT_VOICE_TX_DAILY') ? LIMIT_VOICE_TX_DAILY : 3;
                            
                            if ($dailyUsage >= $limit) {
                                // 超過限制，發送升級引導 Flex Message
                                $limitMsg = [
                                    'type' => 'bubble',
                                    'body' => [
                                        'type' => 'box', 'layout' => 'vertical', 'spacing' => 'md',
                                        'contents' => [
                                            ['type' => 'text', 'text' => '🔒 達到每日免費上限', 'weight' => 'bold', 'color' => '#FF334B', 'size' => 'md'],
                                            ['type' => 'text', 'text' => "您今日的 {$limit} 次免費 AI 記帳額度已用完。", 'size' => 'sm', 'color' => '#555555', 'wrap' => true],
                                            ['type' => 'text', 'text' => '升級 Premium 解鎖無限次使用，並獲得完整財務報表功能！', 'size' => 'sm', 'color' => '#555555', 'wrap' => true],
                                            ['type' => 'button', 'style' => 'primary', 'color' => '#D4A373', 'action' => ['type' => 'uri', 'label' => '了解 Premium 方案', 'uri' => defined('LIFF_DASHBOARD_URL') ? LIFF_DASHBOARD_URL : '#']]
                                        ]
                                    ]
                                ];
                                $lineService->replyFlexMessage($replyToken, "達到免費上限", $limitMsg);
                                $isProcessed = true; 
                                // 略過後續處理
                                goto end_of_loop; 
                            }
                        }
                        // =========== 🔴 新增限制檢查邏輯 END ===========

                        // --- 異步核心邏輯：將任務快速推入佇列 ---
                        try {
                            $stmt = $dbConn->prepare(
                                "INSERT INTO gemini_tasks (line_user_id, user_text, status) 
                                 VALUES (:lineUserId, :text, 'PENDING')"
                            );
                            $stmt->execute([':lineUserId' => $lineUserId, ':text' => $text]);

                            $flexPayload = [
                                'type' => 'bubble',
                                'body' => [
                                    'type' => 'box', 'layout' => 'vertical',
                                    'contents' => [
                                        ['type' => 'text', 'text' => '✅ 記帳已送出', 'weight' => 'bold', 'color' => '#1DB446', 'size' => 'md'],
                                        ['type' => 'text', 'text' => "內容： {$text}", 'margin' => 'sm', 'size' => 'xs', 'color' => '#555555'],
                                        ['type' => 'text', 'text' => 'AI 助手正在後台解析中，您可繼續操作功能，稍後通知您。', 'margin' => 'md', 'size' => 'sm', 'wrap' => true],
                                    ]
                                ]
                            ];
                            $lineService->replyFlexMessage($replyToken, "記帳已送出", $flexPayload);

                        } catch (Throwable $e) {
                            error_log("Failed to insert task for user {$lineUserId}: " . $e->getMessage());
                            $replyText = "系統忙碌，無法將您的記帳訊息加入處理佇列。請稍後再試。";
                            $lineService->replyMessage($replyToken, $replyText);
                        }
                    }
                }
            } 
            
            // 跳轉標籤
            end_of_loop:
            if ($isProcessed) continue; 
        }
    }
} catch (Throwable $e) {
    error_log("FATAL APPLICATION ERROR: " . $e->getMessage());
    http_response_code(200); 
    echo "Error";
    if (isset($lineService) && isset($replyToken)) {
        $lineService->replyMessage($replyToken, "系統發生錯誤，請稍後再試。");
    }
}