<?php
// process_queue.php - 寫入優先，背景補全模式
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
$importBatchSize = 1000; // 階段一：每次匯入筆數 (因為不查API，可以設很大)
$backfillLimit = 60;     // 階段二：每次補全筆數 (受限於 API 頻率)
$skipRates = ['USDT', 'USDC', 'BUSD', 'DAI', 'FDUSD', 'TWD']; 
$startTime = time();

// ==========================================
// 🚀 階段一：極速匯入 (Ingest)
// 目標：把 Queue 清空，先寫入 DB (匯率暫填 0)
// ==========================================

$sql = "SELECT * FROM crypto_import_queue WHERE status = 'PENDING' ORDER BY id ASC LIMIT {$importBatchSize}";
$stmt = $pdo->query($sql);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($jobs)) {
    echo "--- [Phase 1] Importing " . count($jobs) . " transactions... ---\n";
    
    // 預先取得 TWD 匯率
    $usdTwdRate = $rateService->getUsdTwdRate();

    foreach ($jobs as $job) {
        $jobId = $job['id'];
        $userId = $job['user_id'];
        $data = json_decode($job['data_payload'], true);
        
        // 標記處理中
        $pdo->prepare("UPDATE crypto_import_queue SET status = 'PROCESSING' WHERE id = ?")->execute([$jobId]);

        try {
            $quote = $data['quoteCurrency'];
            $exchangeRateUsd = 0.0000000000; // 🟢 預設為 0 (表示待補全)

            if (in_array($quote, $skipRates)) {
                // 穩定幣/法幣：直接算好，不用補
                if ($quote === 'TWD') {
                    $exchangeRateUsd = (1 / $usdTwdRate); 
                } else {
                    $exchangeRateUsd = 1.0; 
                }
            }
            // ⚠️ 幣本位：這裡直接跳過查詢，保持 0.0，讓資料先進 DB

            $data['exchange_rate_usd'] = $exchangeRateUsd;
            
            // 寫入交易表
            // (注意：您的 addTransaction 必須允許傳入 0)
            $success = $cryptoService->addTransaction($userId, $data);

            if ($success) {
                $pdo->prepare("UPDATE crypto_import_queue SET status = 'COMPLETED', error_msg = NULL WHERE id = ?")->execute([$jobId]);
            } else {
                // 可能是重複資料，也視為完成
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

// 檢查剩餘時間 (預留 10 秒)
if ((time() - $startTime) < 110) {
    
    // 找出匯率為 0 且不是穩定幣的交易
    // 這裡我們只抓取 exchange_rate_usd = 0 (或極小值) 的紀錄
    // 同時排除已經被修正過的 (rate > 0)
    $sqlBackfill = "SELECT id, quote_currency, transaction_date 
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
            // 再次檢查時間
            if ((time() - $startTime) >= 110) {
                echo "⚠️ Time limit reached. Stopping backfill.\n";
                break;
            }

            $txId = $tx['id'];
            $quote = $tx['quote_currency'];
            $date = $tx['transaction_date'];

            echo "Updating Tx {$txId} ({$quote})... ";

            try {
                // 呼叫 API 查詢
                $rate = $rateService->getHistoricalRateToUSD($quote, $date);

                // 驗證
                if ($rate > 0) {
                    // 更新資料庫
                    $updateSql = "UPDATE crypto_transactions SET exchange_rate_usd = :rate WHERE id = :id";
                    $stmtUpdate = $pdo->prepare($updateSql);
                    $stmtUpdate->execute([':rate' => $rate, ':id' => $txId]);
                    echo "Done ($rate)\n";
                } else {
                    echo "Failed (Rate 0)\n";
                }

                // 延遲保護 (關鍵！)
                usleep(1500000); // 1.5 秒

            } catch (Exception $e) {
                echo "Error: " . $e->getMessage() . "\n";
            }
        }
    } else {
        echo "--- [Phase 2] No rates to backfill.\n";
    }
}

echo "Cycle Finished.\n";