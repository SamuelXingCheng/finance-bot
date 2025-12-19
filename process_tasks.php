<?php
// process_tasks.php

// 1. 允許背景執行設定
ignore_user_abort(true);
set_time_limit(120); 

// 隨機微延遲，避免多個任務同時啟動撞擊 API 頻率限制
usleep(rand(100000, 800000)); 

// 2. 載入必要服務
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/GeminiService.php';
require_once __DIR__ . '/src/LineService.php';
require_once __DIR__ . '/src/TransactionService.php'; 
require_once __DIR__ . '/src/AssetService.php';
require_once __DIR__ . '/src/UserService.php'; 

$task = null;
$lineUserId = null; 

// 取得網頁介面連結 (由 config.php 定義)
$liffUrl = defined('LIFF_DASHBOARD_URL') ? LIFF_DASHBOARD_URL : 'https://line.me';

// 定義統一的頁尾組件：包含提示文字與連結按鈕
$commonFooterNotice = [
    ['type' => 'separator', 'margin' => 'xl'],
    [
        'type' => 'text', 
        'text' => 'AI 正在進步中，網頁介面提供更完整的資訊。', 
        'size' => 'xxs', 
        'color' => '#aaaaaa', 
        'margin' => 'md', 
        'align' => 'center',
        'wrap' => true
    ],
    [
        'type' => 'button',
        'action' => [
            'type' => 'uri',
            'label' => '開啟網頁介面',
            'uri' => $liffUrl
        ],
        'style' => 'link',
        'height' => 'sm',
        'color' => '#D4A373'
    ]
];

try {
    // 3. 服務初始化
    $db = Database::getInstance();
    $dbConn = $db->getConnection();
    $gemini = new GeminiService();
    $lineService = new LineService();
    $transactionService = new TransactionService();
    $assetService = new AssetService();
    $userService = new UserService();
} catch (Throwable $e) {
    error_log("Worker Initialization Failed: " . $e->getMessage());
    exit(1); 
}

// ----------------------------------------------------
// 4. 任務鎖定與取得
// ----------------------------------------------------
try {
    $dbConn->beginTransaction();

    $stmt = $dbConn->prepare("SELECT * FROM gemini_tasks WHERE status = 'PENDING' LIMIT 1 FOR UPDATE");
    $stmt->execute();
    $task = $stmt->fetch();

    if (!$task) {
        $dbConn->commit(); 
        exit("No pending tasks.");
    }
    
    $lineUserId = $task['line_user_id'];
    $userText = $task['user_text'];
    $taskId = $task['id'];
    $targetLedgerId = $task['ledger_id'] ?? null;
    
    $dbConn->prepare("UPDATE gemini_tasks SET status = 'PROCESSING', processed_at = NOW() WHERE id = :id")
           ->execute([':id' => $taskId]);
    
    $dbConn->commit();

    // ----------------------------------------------------
    // 5. AI 分析與意圖執行
    // ----------------------------------------------------
    $dbUserId = $userService->findOrCreateUser($lineUserId);
    if (!$dbUserId) throw new Exception("User verification failed.");

    $aiResult = $gemini->analyzeInput($userText); 
    
    if ($aiResult && isset($aiResult['intent'])) {
        $intent = $aiResult['intent'];

        // =================================================
        // ACTION 1: 記帳 (Transaction)
        // =================================================
        if ($intent === 'transaction' && !empty($aiResult['transaction_data'])) {
            $resultData = $aiResult['transaction_data'];
            $successCount = 0;
            
            foreach ($resultData as $transaction) {
                if (is_array($transaction) && isset($transaction['amount'])) {
                    if ($targetLedgerId) $transaction['ledger_id'] = $targetLedgerId;
                    if ($transactionService->addTransaction($dbUserId, $transaction)) {
                        $successCount++;
                    }
                }
            }

            $categoryMap = ['Food'=>'飲食', 'Transport'=>'交通', 'Entertainment'=>'娛樂', 'Shopping'=>'購物', 'Bills'=>'帳單', 'Investment'=>'投資', 'Medical'=>'醫療', 'Education'=>'教育', 'Miscellaneous'=>'雜項', 'Salary'=>'薪水', 'Allowance'=>'津貼'];
            $detailContents = [];
            foreach ($resultData as $tx) {
                $desc = $tx['description'] ?? '項目';
                $amt = number_format($tx['amount'] ?? 0);
                $cat = $categoryMap[$tx['category'] ?? 'Miscellaneous'] ?? $tx['category'];
                $color = ($tx['type'] ?? 'expense') === 'income' ? '#1DB446' : '#FF334B';
                
                $detailContents[] = [
                    'type' => 'box', 'layout' => 'vertical', 'margin' => 'md',
                    'contents' => [
                        ['type' => 'text', 'text' => "【{$cat}】 {$desc}", 'weight' => 'bold', 'size' => 'sm', 'color' => '#555555'],
                        ['type' => 'text', 'text' => "金額 NT$ {$amt}", 'size' => 'sm', 'color' => $color, 'align' => 'end']
                    ]
                ];
            }
            
            $flexPayload = [
                'type' => 'bubble', 'size' => 'kilo',
                'header' => ['type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#D4A373', 'paddingAll' => 'lg', 'contents' => [['type' => 'text', 'text' => "記帳成功 共 " . $successCount . " 筆", 'weight' => 'bold', 'color' => '#FFFFFF', 'size' => 'md']]],
                'body' => ['type' => 'box', 'layout' => 'vertical', 'spacing' => 'sm', 'contents' => $detailContents],
                'footer' => ['type' => 'box', 'layout' => 'vertical', 'contents' => $commonFooterNotice]
            ];
            $lineService->pushFlexMessage($lineUserId, "記帳完成", $flexPayload);
        }

        // =================================================
        // ACTION 2: 資產設定 (Asset Setup)
        // =================================================
        elseif ($intent === 'asset_setup' && !empty($aiResult['asset_data'])) {
            $asset = $aiResult['asset_data'];
            $name = $asset['name'] ?? '未命名帳戶';
            $amount = $asset['balance'] ?? 0;
            $type = $asset['type'] ?? 'Bank';
            
            $postbackData = http_build_query(['action' => 'confirm_asset', 'name' => $name, 'amount' => $amount, 'type' => $type]);

            $confirmFlex = [
                'type' => 'bubble', 'size' => 'kilo',
                'body' => [
                    'type' => 'box', 'layout' => 'vertical', 'paddingAll' => 'lg',
                    'contents' => [
                        ['type' => 'text', 'text' => '設定確認', 'weight' => 'bold', 'size' => 'lg', 'color' => '#D4A373'],
                        ['type' => 'text', 'text' => "您要將「{$name}」設定為 NT$ " . number_format($amount) . " 嗎？", 'wrap' => true, 'margin' => 'md', 'color' => '#555555'],
                        ['type' => 'box', 'layout' => 'horizontal', 'spacing' => 'sm', 'margin' => 'xl',
                            'contents' => [
                                ['type' => 'button', 'style' => 'secondary', 'action' => ['type' => 'message', 'label' => '取消', 'text' => '取消設定']],
                                ['type' => 'button', 'style' => 'primary', 'color' => '#D4A373', 'action' => ['type' => 'postback', 'label' => '確認', 'data' => $postbackData, 'displayText' => "確認設定 {$name}"]]
                            ]
                        ]
                    ]
                ],
                'footer' => ['type' => 'box', 'layout' => 'vertical', 'contents' => $commonFooterNotice]
            ];
            $lineService->pushFlexMessage($lineUserId, "資產設定確認", $confirmFlex);
        }

        // =================================================
        // ACTION 3 & 4: 查詢 (Query) 與 閒聊 (Chat)
        // =================================================
        else {
            $reply = $aiResult['reply_text'] ?? "已處理您的請求。";
            $title = ($intent === 'query') ? "財務查詢結果" : "AI 助手回覆";
            $bodyContents = []; // 用來存放 Flex Message 的內容組件

            if ($intent === 'query') {
                $target = $aiResult['query_params']['target'] ?? '';
                $category = $aiResult['query_params']['category'] ?? null;
                
                // 🟢 1. 強化版：同時顯示收入與支出 (Summary)
                if ($target === 'summary') {
                    $income = $transactionService->getTotalIncomeByMonth($dbUserId);
                    $expense = $transactionService->getTotalExpenseByMonth($dbUserId);
                    $balance = $income - $expense;
                    $title = "本月收支概況";

                    $bodyContents = [
                        ['type' => 'text', 'text' => $title, 'weight' => 'bold', 'size' => 'sm', 'color' => '#8C7B75'],
                        ['type' => 'box', 'layout' => 'vertical', 'margin' => 'lg', 'spacing' => 'sm', 'contents' => [
                            ['type' => 'box', 'layout' => 'horizontal', 'contents' => [
                                ['type' => 'text', 'text' => '總收入', 'size' => 'sm', 'color' => '#555555'],
                                ['type' => 'text', 'text' => 'NT$ ' . number_format($income), 'size' => 'sm', 'align' => 'end', 'color' => '#1DB446']
                            ]],
                            ['type' => 'box', 'layout' => 'horizontal', 'contents' => [
                                ['type' => 'text', 'text' => '總支出', 'size' => 'sm', 'color' => '#555555'],
                                ['type' => 'text', 'text' => 'NT$ ' . number_format($expense), 'size' => 'sm', 'align' => 'end', 'color' => '#FF334B']
                            ]],
                            ['type' => 'separator', 'margin' => 'md'],
                            ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'md', 'contents' => [
                                ['type' => 'text', 'text' => '本月結餘', 'size' => 'sm', 'weight' => 'bold', 'color' => '#555555'],
                                ['type' => 'text', 'text' => 'NT$ ' . number_format($balance), 'size' => 'sm', 'align' => 'end', 'weight' => 'bold', 'color' => ($balance >= 0 ? '#1DB446' : '#FF334B')]
                            ]]
                        ]]
                    ];
                } 
                // 🟡 2. 原有的單項查詢邏輯 (將結果存入 $reply)
                else {
                    if ($target === 'expense' && $category) {
                        $start = date('Y-m-01'); $end = date('Y-m-t');
                        $sql = "SELECT SUM(amount) FROM transactions WHERE user_id = ? AND type = 'expense' AND category = ? AND date BETWEEN ? AND ?";
                        $stmt = $dbConn->prepare($sql); $stmt->execute([$dbUserId, $category, $start, $end]);
                        $sum = $stmt->fetchColumn() ?: 0;
                        $catMap = ['Investment'=>'投資', 'Food'=>'飲食', 'Transport'=>'交通', 'Bills'=>'帳單'];
                        $catName = $catMap[$category] ?? $category;
                        $reply = "本月截至目前，總 " . $catName . " 支出為：NT$ " . number_format($sum);
                    } 
                    elseif ($target === 'expense') {
                        $reply = "本月總支出：NT$ " . number_format($transactionService->getTotalExpenseByMonth($dbUserId));
                    } 
                    elseif ($target === 'income') {
                        $reply = "本月總收入：NT$ " . number_format($transactionService->getTotalIncomeByMonth($dbUserId));
                    } 
                    elseif ($target === 'net_worth' || $target === 'asset') {
                        $summary = $assetService->getNetWorthSummary($dbUserId);
                        $reply = "目前總淨資產：NT$ " . number_format($summary['global_twd_net_worth']);
                    } 
                    elseif ($target === 'account_list') {
                        $sql = "SELECT name, balance FROM account_balances WHERE user_id = ? AND type != 'Subscription' ORDER BY balance DESC";
                        $stmt = $dbConn->prepare($sql); $stmt->execute([$dbUserId]);
                        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        if (empty($accounts)) { $reply = "您目前沒有設定任何帳戶。"; } 
                        else {
                            $reply = "您目前共有 " . count($accounts) . " 個帳戶：\n";
                            foreach ($accounts as $idx => $acc) { $reply .= ($idx + 1) . ". " . $acc['name'] . ": NT$ " . number_format($acc['balance']) . "\n"; }
                        }
                    } 
                    elseif ($target === 'subscription_list') {
                        $sql = "SELECT name, balance FROM account_balances WHERE user_id = ? AND type = 'Subscription' ORDER BY balance DESC";
                        $stmt = $dbConn->prepare($sql); $stmt->execute([$dbUserId]);
                        $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        if (empty($subs)) { $reply = "您目前沒有設定固定支出項目。"; } 
                        else {
                            $reply = "本月固定支出項目：\n"; $total = 0;
                            foreach ($subs as $sub) { $reply .= "- " . $sub['name'] . ": NT$ " . number_format($sub['balance']) . "\n"; $total += $sub['balance']; }
                            $reply .= "\n總計：NT$ " . number_format($total);
                        }
                    }

                    // 一般查詢的內容組件
                    $bodyContents = [
                        ['type' => 'text', 'text' => $title, 'weight' => 'bold', 'size' => 'sm', 'color' => '#8C7B75'],
                        ['type' => 'text', 'text' => $reply, 'wrap' => true, 'margin' => 'md', 'color' => '#555555', 'lineSpacing' => '4px', 'size' => 'sm']
                    ];
                }
            } 
            // 🔵 3. 處理一般對話 (Chat)
            else {
                $bodyContents = [
                    ['type' => 'text', 'text' => $title, 'weight' => 'bold', 'size' => 'sm', 'color' => '#8C7B75'],
                    ['type' => 'text', 'text' => $reply, 'wrap' => true, 'margin' => 'md', 'color' => '#555555', 'lineSpacing' => '4px', 'size' => 'sm']
                ];
            }

            // 最後統一封裝並發送
            $textFlex = [
                'type' => 'bubble', 'size' => 'kilo',
                'body' => [
                    'type' => 'box', 'layout' => 'vertical', 'paddingAll' => 'lg',
                    'contents' => $bodyContents
                ],
                'footer' => ['type' => 'box', 'layout' => 'vertical', 'contents' => $commonFooterNotice]
            ];
            $lineService->pushFlexMessage($lineUserId, $title, $textFlex);
        }

        // 成功結案
        $jsonString = json_encode($aiResult, JSON_UNESCAPED_UNICODE); 
        $dbConn->prepare("UPDATE gemini_tasks SET status = 'COMPLETED', result_json = :result WHERE id = :id")
           ->execute([':result' => $jsonString, ':id' => $taskId]);

    } else {
        // AI 無法解析
        $failFlex = [
            'type' => 'bubble', 'size' => 'kilo',
            'body' => [
                'type' => 'box', 'layout' => 'vertical', 'paddingAll' => 'lg',
                'contents' => [
                    ['type' => 'text', 'text' => "無法處理", 'weight' => 'bold', 'size' => 'md', 'color' => '#FF334B'],
                    ['type' => 'text', 'text' => "我目前還無法理解這項請求，可以換個方式說明嗎？", 'wrap' => true, 'margin' => 'md', 'size' => 'sm', 'color' => '#555555']
                ]
            ],
            'footer' => ['type' => 'box', 'layout' => 'vertical', 'contents' => $commonFooterNotice]
        ];
        $dbConn->prepare("UPDATE gemini_tasks SET status = 'FAILED' WHERE id = :id")->execute([':id' => $taskId]);
        $lineService->pushFlexMessage($lineUserId, "無法解析", $failFlex);
    }

} catch (Throwable $e) {
    if (isset($dbConn) && $dbConn->inTransaction()) $dbConn->rollBack();
    error_log("Worker Critical Error Task #{$task['id']}: " . $e->getMessage());
    
    if (isset($task)) {
        try { $dbConn->prepare("UPDATE gemini_tasks SET status = 'FAILED' WHERE id = ?")->execute([$task['id']]); } catch (Throwable $e_db) {}
    }

    if (isset($lineService) && isset($lineUserId)) {
        $errorFlex = [
            'type' => 'bubble', 'size' => 'kilo',
            'body' => [
                'type' => 'box', 'layout' => 'vertical', 'paddingAll' => 'lg',
                'contents' => [
                    ['type' => 'text', 'text' => "系統錯誤", 'weight' => 'bold', 'size' => 'md', 'color' => '#FF334B'],
                    ['type' => 'text', 'text' => "處理請求時發生技術錯誤，請稍後再試。", 'wrap' => true, 'margin' => 'md', 'size' => 'sm', 'color' => '#555555']
                ]
            ],
            'footer' => ['type' => 'box', 'layout' => 'vertical', 'contents' => $commonFooterNotice]
        ];
        $lineService->pushFlexMessage($lineUserId, "系統錯誤", $errorFlex);
    }
}

exit("Task processing finished.");