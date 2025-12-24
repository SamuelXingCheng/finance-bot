<?php
// update_stock_prices.php - 自動更新股票與債券帳戶餘額
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/AssetService.php';
require_once __DIR__ . '/src/StockService.php';

$pdo = Database::getInstance()->getConnection();
$assetService = new AssetService($pdo);
$stockService = new StockService();

echo "--- Starting Stock Price Sync: " . date('Y-m-d H:i:s') . " ---\n";

// 1. 找出所有具備 symbol 與數量，且類型為股票或債券的帳戶
// 🟢 [修正 1] SELECT 列表中一定要加入 cost_basis，不然下面抓不到
$sql = "SELECT id, user_id, ledger_id, name, type, symbol, quantity, currency_unit, cost_basis 
        FROM accounts 
        WHERE symbol IS NOT NULL AND quantity > 0 
        AND type IN ('Stock', 'Bond')";

$stmt = $pdo->query($sql);
$stockAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($stockAccounts) . " accounts to update.\n";

foreach ($stockAccounts as $acc) {
    $symbol = $acc['symbol'];
    echo "Updating [{$acc['name']}] ({$symbol})... ";

    // 2. 獲取最新價格 (這是市價)
    $currentPrice = $stockService->getPrice($symbol);

    if ($currentPrice !== null) {
        // 3. 計算新餘額 (市值)
        $newBalance = $currentPrice * (float)$acc['quantity'];
        
        // 🟢 [重要] 取得原本的成本，如果沒有則預設 0
        // 這樣在更新時，才不會把原本的成本洗掉
        $currentCostBasis = isset($acc['cost_basis']) ? (float)$acc['cost_basis'] : 0.0;
        
        // 4. 呼叫 AssetService 的 upsert 方法更新帳戶並產生今日快照
        $success = $assetService->upsertAccountBalance(
            (int)$acc['user_id'],
            $acc['name'],
            $newBalance,
            $acc['type'],
            $acc['currency_unit'],
            date('Y-m-d'), // 今日
            $acc['ledger_id'], 
            null,          // customRate
            $symbol,
            (float)$acc['quantity'],
            $currentCostBasis // 🟢 [修正 2] 務必把成本傳回去！
        );

        if ($success) {
            echo "Success! New Balance: {$newBalance}, Cost kept at: {$currentCostBasis}\n";
        } else {
            echo "Failed to save to database.\n";
        }
    } else {
        echo "Failed to fetch price from API.\n";
    }
    
    // 稍微延遲避免頻繁請求 API
    usleep(200000); // 0.2秒
}

echo "--- Stock Price Sync Finished ---\n";
?>