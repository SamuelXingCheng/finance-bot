<?php
// sync_history_cost.php - 依據 accounts 表的成本，回填歷史紀錄
// 邏輯：計算目前的「平均成本單價」，然後套用到該帳戶所有的歷史快照中

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';

// 設定執行時間上限，避免資料太多跑不完
set_time_limit(300);

echo "<pre>"; // 讓瀏覽器顯示換行
echo "--- 開始執行歷史成本同步 (Sync Cost Basis) ---\n";

$pdo = Database::getInstance()->getConnection();

try {
    // 1. 抓出所有「有設定成本」且「有股數」的帳戶
    // 我們只處理 Stock, Bond, Investment 這些會有數量的類型
    $sql = "SELECT id, user_id, name, type, symbol, quantity, cost_basis, currency_unit 
            FROM accounts 
            WHERE cost_basis > 0 
              AND quantity > 0 
              AND type IN ('Stock', 'Bond', 'Investment')";
    
    $stmt = $pdo->query($sql);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "📊 找到 " . count($accounts) . " 個有成本紀錄的帳戶，準備開始同步...\n\n";

    $totalUpdated = 0;

    foreach ($accounts as $acc) {
        $name = $acc['name'];
        $currentQty = (float)$acc['quantity'];
        $currentTotalCost = (float)$acc['cost_basis'];
        
        // 2. 計算平均單位成本 (Unit Cost)
        // 例如：總成本 10,000 / 100 股 = 100 元/股
        $unitCost = $currentTotalCost / $currentQty;

        echo "👉 處理帳戶: [{$name}] ({$acc['symbol']})\n";
        echo "   目前狀態: 股數 {$currentQty}, 總成本 {$currentTotalCost} => 平均單價: " . number_format($unitCost, 4) . "\n";

        // 3. 更新該帳戶在 account_balance_history 的所有紀錄
        // 邏輯：歷史成本 = 歷史股數 * 平均單價
        // 條件：只更新該使用者的該帳戶，且歷史紀錄必須有股數 (quantity > 0)
        $updateSql = "UPDATE account_balance_history 
                      SET cost_basis = quantity * :unitCost 
                      WHERE user_id = :userId 
                        AND account_name = :name 
                        AND quantity > 0";
        
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute([
            ':unitCost' => $unitCost,
            ':userId'   => $acc['user_id'],
            ':name'     => $name
        ]);

        $affected = $updateStmt->rowCount();
        echo "   ✅ 已更新 {$affected} 筆歷史快照。\n";
        echo "--------------------------------------------------\n";
        
        $totalUpdated += $affected;
    }

    echo "\n🎉 同步完成！共更新了 {$totalUpdated} 筆歷史紀錄。\n";

} catch (PDOException $e) {
    echo "❌ 資料庫錯誤: " . $e->getMessage();
}

echo "</pre>";
?>