<?php
// broadcast_feature.php
// 用途：群發「自訂類別」與「帳戶升級」公告 (無表情符號版)

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/LineService.php';

set_time_limit(0); 
ignore_user_abort(true);

echo "--- 開始準備群發 ---\n";

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    $lineService = new LineService();

    // 取得網頁版連結
    $dashboardUrl = defined('LIFF_DASHBOARD_URL') ? LIFF_DASHBOARD_URL : 'https://line.me';

    // 🌟 定義 Carousel (輪播) - 純文字專業版
    $carouselFlex = [
        'type' => 'carousel',
        'contents' => [
            // =================================================
            // 第一張卡片：自訂類別功能
            // =================================================
            [
                'type' => 'bubble',
                'size' => 'kilo',
                'header' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'backgroundColor' => '#D4A373', // 品牌色
                    'paddingAll' => 'lg',
                    'contents' => [
                        ['type' => 'text', 'text' => 'AI 記帳大升級', 'weight' => 'bold', 'color' => '#FFFFFF', 'size' => 'sm'],
                        ['type' => 'text', 'text' => '自訂類別 & 自動記憶', 'weight' => 'bold', 'color' => '#FFFFFF', 'size' => 'xl', 'margin' => 'sm', 'wrap' => true]
                    ]
                ],
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'spacing' => 'md',
                    'contents' => [
                        ['type' => 'text', 'text' => '覺得預設分類不夠用嗎？現在您可以自由創建專屬分類，AI 會自動學習您的記帳習慣。', 'size' => 'sm', 'color' => '#666666', 'wrap' => true, 'lineSpacing' => '4px'],
                        ['type' => 'separator', 'margin' => 'lg'],
                        [
                            'type' => 'box', 'layout' => 'vertical', 'margin' => 'lg', 'spacing' => 'sm',
                            'contents' => [
                                ['type' => 'text', 'text' => '1. 第一次：加 #Hashtag', 'weight' => 'bold', 'size' => 'sm', 'color' => '#8C7B75'],
                                ['type' => 'text', 'text' => '範例：「買禮盒 1200 #公關費」', 'size' => 'xs', 'color' => '#555555', 'wrap' => true]
                            ]
                        ],
                        [
                            'type' => 'box', 'layout' => 'vertical', 'margin' => 'md', 'spacing' => 'sm',
                            'contents' => [
                                ['type' => 'text', 'text' => '2. 第二次：AI 自動判斷', 'weight' => 'bold', 'size' => 'sm', 'color' => '#8C7B75'],
                                ['type' => 'text', 'text' => '下次只要輸入相關內容，不用打 #，AI 也會自動幫您歸類。', 'size' => 'xs', 'color' => '#555555', 'wrap' => true]
                            ]
                        ]
                    ]
                ],
                'footer' => [
                    'type' => 'box', 'layout' => 'vertical', 'contents' => [
                        ['type' => 'button', 'style' => 'primary', 'color' => '#D4A373', 'height' => 'sm', 'action' => ['type' => 'message', 'label' => '試用：飼料 500 #貓咪', 'text' => '飼料 500 #貓咪']]
                    ]
                ]
            ],
            // =================================================
            // 第二張卡片：帳戶/股票功能
            // =================================================
            [
                'type' => 'bubble',
                'size' => 'kilo',
                'header' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'backgroundColor' => '#8C7B75', // 副色調
                    'paddingAll' => 'lg',
                    'contents' => [
                        ['type' => 'text', 'text' => '資產管理更新', 'weight' => 'bold', 'color' => '#FFFFFF', 'size' => 'sm'],
                        ['type' => 'text', 'text' => '股票股數 & 卡片優化', 'weight' => 'bold', 'color' => '#FFFFFF', 'size' => 'xl', 'margin' => 'sm', 'wrap' => true]
                    ]
                ],
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'spacing' => 'md',
                    'contents' => [
                        ['type' => 'text', 'text' => '帳戶管理頁面同步進行了重大更新，提供更精確的資產追蹤功能：', 'size' => 'sm', 'color' => '#666666', 'wrap' => true, 'lineSpacing' => '4px'],
                        ['type' => 'separator', 'margin' => 'lg'],
                        [
                            'type' => 'box', 'layout' => 'vertical', 'margin' => 'lg', 'spacing' => 'sm',
                            'contents' => [
                                ['type' => 'text', 'text' => '支援輸入「股數」與「代碼」', 'size' => 'sm', 'color' => '#555555', 'weight' => 'bold'],
                                ['type' => 'text', 'text' => '現在可以精確紀錄 2330 台積電 1000 股，即時掌握市值。', 'size' => 'xs', 'color' => '#888888', 'wrap' => true]
                            ]
                        ],
                        [
                            'type' => 'box', 'layout' => 'vertical', 'margin' => 'md', 'spacing' => 'sm',
                            'contents' => [
                                ['type' => 'text', 'text' => '全新「持股卡片」顯示', 'size' => 'sm', 'color' => '#555555', 'weight' => 'bold'],
                                ['type' => 'text', 'text' => '視覺優化，讓您的投資組合分佈一目瞭然。', 'size' => 'xs', 'color' => '#888888', 'wrap' => true]
                            ]
                        ]
                    ]
                ],
                'footer' => [
                    'type' => 'box', 'layout' => 'vertical', 'contents' => [
                        ['type' => 'button', 'style' => 'primary', 'color' => '#8C7B75', 'height' => 'sm', 'action' => ['type' => 'uri', 'label' => '前往體驗新功能', 'uri' => $dashboardUrl]]
                    ]
                ]
            ]
        ]
    ];

    // 4. 撈取使用者並發送
    $sql = "SELECT DISTINCT line_user_id FROM users WHERE line_user_id IS NOT NULL AND line_user_id != ''";
    $stmt = $pdo->query($sql);
    $users = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $totalUsers = count($users);
    echo "找到 {$totalUsers} 位使用者，開始發送...\n";

    $count = 0;
    foreach ($users as $userId) {
        $count++;
        // 替代文字也移除表情符號
        $lineService->pushFlexMessage($userId, "系統公告：自訂類別與股票功能上線", $carouselFlex);
        echo "[{$count}/{$totalUsers}] 已發送給: " . substr($userId, 0, 10) . "...\n";
        usleep(100000); // 0.1秒
    }

    echo "--- 群發完成！共發送給 {$count} 位使用者 ---\n";

} catch (Throwable $e) {
    echo "發生錯誤: " . $e->getMessage() . "\n";
}