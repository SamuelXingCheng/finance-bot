<?php
// process_queue.php - 異步佇列背景處理腳本
// 確保路徑正確
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/ExchangeRateService.php';
require_once __DIR__ . '/src/CryptoService.php';

// 設定執行時間限制 (例如 55秒，避免 Cron Job 重疊執行)
// 確保您的伺服器允許更高的執行時間
set_time_limit(55); 

$pdo = Database::getInstance()->getConnection();
// 💡 注意：ExchangeRateService 需要 PDO 連線才能運作
$rateService = new ExchangeRateService($pdo);
$cryptoService = new CryptoService();

// 定義哪些幣種不需要查歷史匯率 (視為穩定幣或由內部邏輯處理)
$skipRates = ['USDT', 'USDC', 'BUSD', 'DAI', 'FDUSD']; 
$maxJobsToProcess = 70; // 建議調整到 30 筆，兼顧速度與穩定

// 1. 抓取 PENDING 的任務 (一次只處理 $maxJobsToProcess 筆)
$sql = "SELECT * FROM crypto_import_queue WHERE status = 'PENDING' ORDER BY id ASC LIMIT {$maxJobsToProcess}";
$stmt = $pdo->query($sql);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($jobs)) {
    echo "No pending jobs.\n";
    exit;
}

// 預先取得 USD/TWD 匯率，TWD 交易會用到
$usdTwdRate = $rateService->getUsdTwdRate();


foreach ($jobs as $job) {
    $jobId = $job['id'];
    $userId = $job['user_id'];
    $data = json_decode($job['data_payload'], true);
    
    // 標記為處理中，防止重複執行
    $pdo->prepare("UPDATE crypto_import_queue SET status = 'PROCESSING' WHERE id = ?")->execute([$jobId]);

    try {
        $quote = $data['quoteCurrency'];
        $transDate = $data['date'];
        $exchangeRateUsd = 1.0;

        // 🟢 核心判斷邏輯：只對幣本位交易執行慢速查詢
        if (in_array($quote, $skipRates)) {
            // 情況 1: 穩定幣或法幣 -> 快速處理，無延遲
            if ($quote === 'TWD') {
                // TWD 兌 USD 匯率
                $exchangeRateUsd = (1 / $usdTwdRate); 
            } else {
                // USDT, USDC 等穩定幣
                $exchangeRateUsd = 1.0; 
            }
        } else {
            // 情況 2: 幣本位交易 (Quote 是 BTC, ETH, BNB 等) -> 必須查歷史匯率
            echo "Processing Job {$jobId}: Fetching historical rate for {$quote} on {$transDate}\n";

            // 執行歷史匯率查詢
            // ⚠️ 這裡會觸發 CoinGecko API 呼叫
            $exchangeRateUsd = $rateService->getHistoricalRateToUSD($quote, $transDate);
            
            // 執行延遲，保護 API 頻率
            usleep(1500000); // 1.5 秒延遲
        }

        // 寫入正式帳本
        $data['exchange_rate_usd'] = $exchangeRateUsd;
        $success = $cryptoService->addTransaction($userId, $data);

        if ($success) {
            $pdo->prepare("UPDATE crypto_import_queue SET status = 'COMPLETED' WHERE id = ?")->execute([$jobId]);
        } else {
            throw new Exception("Add transaction failed or returned false from CryptoService::addTransaction");
        }

    } catch (Exception $e) {
        $msg = $e->getMessage();
        // 處理失敗，更新狀態
        $pdo->prepare("UPDATE crypto_import_queue SET status = 'FAILED', error_msg = ? WHERE id = ?")->execute([$msg, $jobId]);
        error_log("Job {$jobId} failed: $msg");
    }
}