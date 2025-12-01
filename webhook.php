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
    // 4. 接收與驗證 LINE 傳送的資料 (略)
    // ----------------------------------------------------
    $channelSecret = LINE_CHANNEL_SECRET;
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
                $replyText = "";
                $isProcessed = false; 

                // ====================================================
                // 【資產設定指令 - 最高優先級】
                // ====================================================
                if (preg_match('/^設定\s+([^\s]+)\s+([^\s]+)\s+([-\d\.,]+)(.*?)$/u', $text, $matches)) {
                    
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
                        // 1. 格式化並移除尾隨零 (Flex 顯示優化)
                        $formattedBalance = number_format($balance, 8, '.', ''); 
                        $trimmedZeros = rtrim($formattedBalance, '0');
                        $displayBalance = rtrim($trimmedZeros, '.');

                        // 2. 建構 Flex 成功回覆
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
                        // 失敗時，使用純文字回覆
                        $replyText = "❌ 資產更新失敗，請檢查格式或聯繫客服。";
                        $lineService->replyMessage($replyToken, $replyText);
                    }
                    
                    $isProcessed = true;
                } 
                
                // ====================================================
                // 【資產查詢指令】
                // ====================================================
                elseif (in_array($text, ['查詢資產', '資產總覽', '淨值'])) {
                    
                    // 1. 獲取數據
                    $result = $assetService->getNetWorthSummary($dbUserId);
                    $summary = $result['breakdown'];
                    $globalNetWorthTWD = $result['global_twd_net_worth'];
                    $usdTwdRate = $result['usdTwdRate'];
                    
                    // 2. 建構 Flex Message 的 Body 內容 (分幣種)
                    $assetBodyContents = [];
                    $rateContents = [];
                    
                    // --- Hero Size Logic ---
                    $globalNetWorthText = number_format($globalNetWorthTWD, 2);
                    $textLength = strlen($globalNetWorthText);
                    $heroSize = '3xl';
                    if ($textLength > 16) { $heroSize = 'xl'; } elseif ($textLength > 12) { $heroSize = 'xxl'; }
                    $globalNetWorthColor = $globalNetWorthTWD >= 0 ? '#007AFF' : '#FF334B';
                    
                    
                    if (!empty($summary)) {
                        foreach ($summary as $currency => $data) {
                            $assets = number_format($data['assets'], 8);
                            $liabilities = number_format($data['liabilities'], 8);
                            $netWorth = number_format($data['net_worth'], 8);
                            $twdTotal = number_format($data['twd_total'], 2);

                            // 移除資產明細中的尾隨零 (顯示優化)
                            $assetsDisplay = rtrim(rtrim($assets, '0'), '.');
                            $liabilitiesDisplay = rtrim(rtrim($liabilities, '0'), '.');
                            $netWorthDisplay = rtrim(rtrim($netWorth, '0'), '.');

                            $netWorthColor = $data['net_worth'] >= 0 ? '#1DB446' : '#FF334B';
                            $netWorthEmoji = $data['net_worth'] >= 0 ? '🟢' : '🔴';

                            // 幣種標題
                            $assetBodyContents[] = [
                                'type' => 'text', 'text' => "🏦 {$currency} 資產總覽", 'weight' => 'bold', 'color' => '#333333', 'size' => 'md', 'margin' => 'xl'
                            ];
                            
                            // 詳情列表
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
                            
                            // ----------------------------------------------------
                            // 3. 【修正】建構匯率清單：根據幣種類型顯示 TWD 或 USD 匯率
                            // ----------------------------------------------------
                            if ($currency !== 'TWD') {
                                // 獲取 X 兌 USD 的匯率 (USD 是中繼基準)
                                $rateToUSD = $rateService->getRateToUSD($currency); 
                                
                                // 檢查是否為加密貨幣 (透過 ExchangeRateService 提供的公開常數檢查)
                                $isCrypto = isset(ExchangeRateService::COIN_ID_MAP[$currency]);
                                
                                if ($isCrypto) {
                                    // 加密貨幣：顯示 X 兌 USD
                                    $rateDisplayCurrency = 'USD';
                                    $rateToDisplay = $rateToUSD;
                                    $ratePrecision = 2; // BTC, ETH 等顯示 2 位小數
                                } else {
                                    // 法幣 (Fiat)：顯示 X 兌 TWD
                                    $rateDisplayCurrency = 'NT$';
                                    // 計算 X/TWD = (X/USD) * (USD/TWD)
                                    $rateToDisplay = $rateToUSD * $usdTwdRate; 
                                    $ratePrecision = 4; // 法幣顯示 4 位小數
                                }
                                
                                $rateDisplay = number_format($rateToDisplay, $ratePrecision);

                                $rateContents[] = [
                                    'type' => 'box', 'layout' => 'horizontal', 'margin' => 'sm',
                                    'contents' => [
                                        ['type' => 'text', 'text' => "1 {$currency} =", 'size' => 'xs', 'color' => '#555555', 'flex' => 0],
                                        // 顯示修正後的幣種和匯率
                                        ['type' => 'text', 'text' => "{$rateDisplayCurrency} {$rateDisplay}", 'size' => 'xs', 'color' => '#111111', 'align' => 'end', 'flex' => 1]
                                    ]
                                ];
                            }
                        } // 關閉 foreach ($summary as $currency => $data)

                        // 將匯率清單 Box 加入到 Body 的最下方
                        if (!empty($rateContents)) {
                            $assetBodyContents[] = ['type' => 'separator', 'margin' => 'xl'];
                            // 更新標題：說明幣種計價的差異
                            $assetBodyContents[] = ['type' => 'text', 'text' => '實時匯率參考 (法幣兌 TWD / 加密貨幣兌 USD)', 'weight' => 'bold', 'size' => 'sm', 'margin' => 'lg'];
                            $assetBodyContents = array_merge($assetBodyContents, $rateContents);
                            
                            // ----------------------------------------------------
                            // 【關鍵新增】：USD/TWD 最終匯率
                            // ----------------------------------------------------
                            $assetBodyContents[] = ['type' => 'separator', 'margin' => 'md'];
                            $assetBodyContents[] = [
                                'type' => 'box', 'layout' => 'horizontal', 'margin' => 'sm',
                                'contents' => [
                                    ['type' => 'text', 'text' => "1 USD =", 'size' => 'sm', 'color' => '#333333', 'weight' => 'bold', 'flex' => 0],
                                    // 顯示 USD/TWD 匯率 (使用 AssetService 獲取的 $usdTwdRate)
                                    ['type' => 'text', 'text' => "NT$ " . number_format($usdTwdRate, 4), 'size' => 'sm', 'color' => '#111111', 'align' => 'end', 'flex' => 1]
                                ]
                            ];
                        }

                    } else {
                        $assetBodyContents[] = ['type' => 'text', 'text' => '目前沒有任何資產記錄。請輸入「設定...」新增。', 'size' => 'sm', 'color' => '#AAAAAA', 'margin' => 'xl'];
                    }

                    // 4. 組裝 Flex Bubble (Hero 區塊新增全球淨值)
                    $globalNetWorthText = number_format($globalNetWorthTWD, 2);
                    $globalNetWorthColor = $globalNetWorthTWD >= 0 ? '#007AFF' : '#FF334B';
                    
                    $flexPayload = [
                        'type' => 'bubble', 'size' => 'mega',
                        'header' => ['type' => 'box', 'layout' => 'vertical', 'paddingAll' => 'lg', 'contents' => [
                            ['type' => 'text', 'text' => '淨資產總覽', 'weight' => 'bold', 'size' => 'xl']
                        ]],
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
                                ['type' => 'text', 'text' => 'Powered by CoinGecko', 'color' => '#AAAAAA', 'size' => 'xxs', 'align' => 'center', 'action' => [
                                    'type' => 'uri',
                                    'label' => 'CoinGecko',
                                    'uri' => 'https://www.coingecko.com'
                                ], 'flex' => 1] // 讓它居中
                            ]]
                        ]]
                    ];

                    $lineService->replyFlexMessage($replyToken, "淨資產總覽", $flexPayload);
                    $isProcessed = true;
                }
                
                // ====================================================
                // 【記帳查詢 / 報表指令】
                // ====================================================
                elseif (in_array($text, ['查詢', '本月支出', '報表', '總覽', '支出', '收入'])) {
                    
                    // 假設這裡有完整的 Flex 報表邏輯
                    // $lineService->replyFlexMessage($replyToken, ...);
                    
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
                        $lineService->replyMessage($replyToken, $replyText); // 純文字回覆
                    } else {
                        // --- 異步核心邏輯：將任務快速推入佇列 ---
                        try {
                            $stmt = $dbConn->prepare(
                                "INSERT INTO gemini_tasks (line_user_id, user_text, status) 
                                 VALUES (:lineUserId, :text, 'PENDING')"
                            );
                            $stmt->execute([':lineUserId' => $lineUserId, ':text' => $text]);

                            // 成功推入並回覆 Flex Message
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
                            
                            $lineService->replyFlexMessage($replyToken, "記帳已送出", $flexPayload);

                            break; // 成功推入並回覆後，跳出迴圈

                        } catch (Throwable $e) {
                            error_log("Failed to insert task for user {$lineUserId}: " . $e->getMessage());
                            $replyText = "系統忙碌，無法將您的記帳訊息加入處理佇列。請稍後再試。";
                            // 失敗時，會執行後續的純文字回覆
                            $lineService->replyMessage($replyToken, $replyText);
                        }
                    }
                    
                }
                
            } elseif ($event['type'] === 'follow' && $replyToken) {
                 // 處理追蹤事件 (略)
            }

            // 確保每次只處理一個事件
            if ($isProcessed) break; 
        }
    }

    // ----------------------------------------------------
    // 6. 成功結束 (略)
    // ----------------------------------------------------

} catch (Throwable $e) {
    // ----------------------------------------------------
    // 7. 錯誤處理 (略)
    // ----------------------------------------------------
    error_log("FATAL APPLICATION ERROR: " . $e->getMessage());
    http_response_code(200); 
    echo "Error";

    if (isset($lineService) && isset($replyToken)) {
        $lineService->replyMessage($replyToken, "系統發生錯誤，請稍後再試。");
    }
}