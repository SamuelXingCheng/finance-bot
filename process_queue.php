<?php
// process_queue.php - 寫入優先 -> 背景補全 -> 自動校正
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/ExchangeRateService.php';
require_once __DIR__ . '/src/CryptoService.php';

// 設定執行時間上限 (120秒)
set_time_limit(120); 

$pdo = Database::getInstance()->getConnection();
$rateService = new ExchangeRateService($pdo);
$cryptoService = new CryptoService();

// --- 設定 ---
$importBatchSize = 1000; 
$backfillLimit = 60;     
$skipRates = ['USDT', 'USDC', 'BUSD', 'DAI', 'FDUSD', 'TWD']; 
$startTime = time();

// 用來記錄哪些用戶的資料被「補全」了，最後需要校正成本
$affectedUserIds = [];

// ==========================================
// 🚀 階段一：極速匯入 (Ingest)
// 目標：把 Queue 清空，先寫入 DB (匯率暫填 0)
// ==========================================

$sql = "SELECT * FROM crypto_import_queue WHERE status = 'PENDING' ORDER BY id ASC LIMIT {$importBatchSize}";
$stmt = $pdo->query($sql);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($jobs)) {
    echo "--- [Phase 1] Importing " . count($jobs) . " transactions... ---\n";
    
    $usdTwdRate = $rateService->getUsdTwdRate();

    foreach ($jobs as $job) {
        $jobId = $job['id'];
        $userId = $job['user_id'];
        $data = json_decode($job['data_payload'], true);
        
        $pdo->prepare("UPDATE crypto_import_queue SET status = 'PROCESSING' WHERE id = ?")->execute([$jobId]);

        try {
            $quote = $data['quoteCurrency'];
            $exchangeRateUsd = 0.0000000000; 

            if (in_array($quote, $skipRates)) {
                if ($quote === 'TWD') {
                    $exchangeRateUsd = (1 / $usdTwdRate); 
                } else {
                    $exchangeRateUsd = 1.0; 
                }
            }

            $data['exchange_rate_usd'] = $exchangeRateUsd;
            
            // 寫入交易 (此時若 rate=0，CryptoHoldings 的成本會暫時錯誤，待 Phase 3 修正)
            $success = $cryptoService->addTransaction($userId, $data);

            if ($success) {
                $pdo->prepare("UPDATE crypto_import_queue SET status = 'COMPLETED', error_msg = NULL WHERE id = ?")->execute([$jobId]);
            } else {
                $pdo->prepare("UPDATE crypto_import_queue SET status = 'COMPLETED', error_msg = 'Skipped/Duplicate' WHERE id = ?")->execute([$jobId]);
            }

        } catch (Exception $e) {
            $msg = $e->getMessage();
            $pdo->prepare("UPDATE crypto_import_queue SET status = 'FAILED', error_msg = ? WHERE id = ?")->execute([$msg, $jobId]);
            echo "Job {$jobId} Failed: {$msg}\n";
        }
    }
} else {
    echo "--- [Phase 1] No pending imports.\n";
}


// ==========================================
// 🛠️ 階段二：背景補全 (Backfill)
// 目標：找出 exchange_rate_usd = 0 的交易，查 API 補上
// ==========================================

if ((time() - $startTime) < 110) {
    
    // 🟢 [修改] 多撈取 user_id，以便後續校正
    $sqlBackfill = "SELECT id, user_id, quote_currency, transaction_date 
                    FROM crypto_transactions 
                    WHERE exchange_rate_usd = 0 
                    AND quote_currency NOT IN ('" . implode("','", $skipRates) . "')
                    ORDER BY id DESC 
                    LIMIT {$backfillLimit}";
    
    $stmtBF = $pdo->query($sqlBackfill);
    $pendingRates = $stmtBF->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($pendingRates)) {
        echo "--- [Phase 2] Backfilling rates for " . count($pendingRates) . " transactions... ---\n";

        foreach ($pendingRates as $tx) {
            if ((time() - $startTime) >= 105) { // 稍微保留更多緩衝時間給 Phase 3
                echo "⚠️ Time limit reached. Stopping backfill.\n";
                break;
            }

            $txId = $tx['id'];
            $userId = $tx['user_id']; // 🟢 取得 User ID
            $quote = $tx['quote_currency'];
            $date = $tx['transaction_date'];

            echo "Updating Tx {$txId} ({$quote})... ";

            try {
                $rate = $rateService->getHistoricalRateToUSD($quote, $date);

                if ($rate > 0) {
                    $updateSql = "UPDATE crypto_transactions SET exchange_rate_usd = :rate WHERE id = :id";
                    $stmtUpdate = $pdo->prepare($updateSql);
                    $stmtUpdate->execute([':rate' => $rate, ':id' => $txId]);
                    echo "Done ($rate)\n";

                    // 🟢 [新增] 標記該用戶需要校正成本
                    $affectedUserIds[] = $userId;
                } else {
                    echo "Failed (Rate 0)\n";
                }

                usleep(1500000); 

            } catch (Exception $e) {
                echo "Error: " . $e->getMessage() . "\n";
            }
        }
    } else {
        echo "--- [Phase 2] No rates to backfill.\n";
    }
}

// ==========================================
// 🔄 階段三：成本校正 (Recalculate)
// 目標：針對 Phase 2 更新過匯率的用戶，重算平均成本
// ==========================================

if (!empty($affectedUserIds)) {
    // 去除重複，避免同一個 User 算多次
    $uniqueUsers = array_unique($affectedUserIds);
    echo "--- [Phase 3] Recalculating holdings for " . count($uniqueUsers) . " users... ---\n";

    foreach ($uniqueUsers as $uid) {
        if ((time() - $startTime) >= 118) { // 最後防線
            echo "⚠️ Critical time limit. Stopping recalculation.\n";
            break;
        }

        try {
            echo "Recalculating User {$uid}... ";
            $cryptoService->recalculateHoldings($uid); // 🟢 呼叫你在 CryptoService 寫好的重算函式
            echo "OK\n";
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
        }
    }
}

echo "Cycle Finished.\n";
?>