<?php
// sync_history_to_holdings.php
// 用途：從 account_balance_history (資產歷史) 抓取最新餘額，填入 crypto_holdings (持倉表)
// 適用情境：沒有交易紀錄，只有手動輸入的餘額歷史

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';

$pdo = Database::getInstance()->getConnection();

echo "--- 開始從資產歷史同步持倉 ---\n";

// 1. 清空目前的持倉表 (重置狀態)
// $pdo->exec("TRUNCATE TABLE crypto_holdings");
// echo "✅ 已清空 crypto_holdings\n";

// 2. 找出每個用戶、每個幣種的「最新」餘額
// 邏輯：利用子查詢找出每個 (user_id, currency_unit) 最大的 id
$sql = "
    SELECT t1.user_id, t1.currency_unit, t1.balance
    FROM account_balance_history t1
    INNER JOIN (
        SELECT user_id, currency_unit, MAX(id) as max_id
        FROM account_balance_history
        WHERE currency_unit NOT IN ('TWD', 'USD') -- 排除法幣，只抓加密貨幣
        GROUP BY user_id, currency_unit
    ) t2 ON t1.id = t2.max_id
    WHERE t1.balance > 0
";

$stmt = $pdo->query($sql);
$balances = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "🔍 找到 " . count($balances) . " 筆加密貨幣資產紀錄...\n";

// 3. 寫入 crypto_holdings
$insertSql = "INSERT INTO crypto_holdings (user_id, currency, quantity, avg_cost, updated_at) VALUES (?, ?, ?, ?, NOW())";
$insertStmt = $pdo->prepare($insertSql);

$count = 0;
foreach ($balances as $row) {
    // 因為不知道成本，暫時設為 0 (這會導致 ROI 顯示無限大，但總資產金額是對的)
    // 如果你有預設成本想填，可以在這裡改
    $avgCost = 0; 
    
    $insertStmt->execute([
        $row['user_id'],
        $row['currency_unit'], // 對應 currency
        $row['balance'],       // 對應 quantity
        $avgCost
    ]);
    
    echo "User {$row['user_id']}: 同步 {$row['currency_unit']} -> 數量: {$row['balance']}\n";
    $count++;
}

echo "--- 同步完成，共建立 $count 筆持倉資料 ---\n";
echo "💡 提示：現在你可以執行 'php daily_snapshot.php' 了\n";