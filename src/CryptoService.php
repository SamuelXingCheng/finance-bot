<?php
// src/CryptoService.php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ExchangeRateService.php';

class CryptoService {
    private $pdo;
    private $rateService;

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
        $rateService = new ExchangeRateService($this->pdo);
    }

    /**
     * 🟢 修正：校正餘額 (支援指定日期，模擬「快照」行為)
     */
    public function adjustBalance(int $userId, string $symbol, float $targetBalance, string $date = null): bool {
        // 1. 取得該幣種目前的總餘額
        // (為了簡化計算，我們計算當下的差額，並補入一筆交易。若要更嚴謹應計算該日期當下的餘額，但對於補登場景，這通常足夠)
        $dashboard = $this->getDashboardData($userId);
        $currentBalance = 0.0;
        foreach ($dashboard['holdings'] as $h) {
            if ($h['symbol'] === $symbol) {
                $currentBalance = $h['balance'];
                break;
            }
        }

        // 2. 計算差額
        $diff = $targetBalance - $currentBalance;
        if (abs($diff) < 0.00000001) return true; // 數字相同，無需變更

        // 3. 判斷類型 (增加用 earn，減少用 withdraw，不影響成本)
        $type = $diff > 0 ? 'earn' : 'withdraw'; 
        
        // 4. 使用傳入的日期，若無則用現在
        $txDate = $date ?? date('Y-m-d H:i:s'); 

        $sql = "INSERT INTO crypto_transactions 
                (user_id, type, base_currency, quote_currency, price, quantity, total, fee, transaction_date, note, created_at)
                VALUES (:uid, :type, :base, 'USDT', 0, :qty, 0, 0, :date, '快照更新', NOW())";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':uid' => $userId,
                ':type' => $type,
                ':base' => strtoupper($symbol),
                ':qty' => abs($diff),
                ':date' => $txDate
            ]);
        } catch (PDOException $e) {
            error_log("Snapshot Update Failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 🟢 新增：取得歷史資產趨勢 (每日淨值)
     */
    public function getHistoryChartData(int $userId, string $range = '1y'): array {
        // 1. 設定時間範圍
        $interval = '-1 year';
        if ($range === '1m') $interval = '-1 month';
        if ($range === '6m') $interval = '-6 months';
        
        $startDate = date('Y-m-d', strtotime($interval));
        $endDate = date('Y-m-d');

        // 2. 撈取所有交易
        $sql = "SELECT transaction_date, type, base_currency, quote_currency, quantity, total, fee 
                FROM crypto_transactions 
                WHERE user_id = :uid AND transaction_date <= :end
                ORDER BY transaction_date ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uid' => $userId, ':end' => $endDate . ' 23:59:59']);
        $txs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. 建立日期區間
        $period = new DatePeriod(
            new DateTime($startDate),
            new DateInterval('P1D'),
            (new DateTime($endDate))->modify('+1 day')
        );

        $dailyData = [];
        $tempHoldings = []; // 暫存每日持倉數量 {'BTC': 0.5, 'USDT': 100}
        $txIndex = 0;
        $totalTxs = count($txs);

        // 4. 每日重播
        foreach ($period as $date) {
            $currentDateStr = $date->format('Y-m-d');
            
            // 處理當天之前的所有交易
            while ($txIndex < $totalTxs && substr($txs[$txIndex]['transaction_date'], 0, 10) <= $currentDateStr) {
                $tx = $txs[$txIndex];
                $this->applyTxToHoldings($tempHoldings, $tx); // 呼叫輔助函式更新持倉
                $txIndex++;
            }

            // 計算當天總市值 (以當前匯率估算，若要精確需歷史匯率，這裡採簡化策略：用最新匯率回推)
            // 優化：只計算有餘額的幣種
            $totalUsdValue = 0;
            foreach ($tempHoldings as $symbol => $balance) {
                if ($balance > 0) {
                    $price = $this->rateService->getRateToUSD($symbol); // 注意：這是"現在"的價格
                    $totalUsdValue += $balance * $price;
                }
            }
            
            $dailyData[$currentDateStr] = $totalUsdValue;
        }

        return [
            'labels' => array_keys($dailyData),
            'data' => array_values($dailyData)
        ];
    }

    // 輔助：更新持倉陣列 (邏輯與 getDashboardData 類似但簡化)
    private function applyTxToHoldings(array &$holdings, array $tx) {
        $type = $tx['type'];
        $symbol = strtoupper($tx['base_currency']);
        $quote = strtoupper($tx['quote_currency']);
        $qty = (float)$tx['quantity'];
        $total = (float)$tx['total'];
        $fee = (float)$tx['fee'];

        if (!isset($holdings[$symbol])) $holdings[$symbol] = 0;
        if (!isset($holdings['USDT'])) $holdings['USDT'] = 0;

        switch ($type) {
            case 'deposit':
                if ($symbol === 'USDT') $holdings['USDT'] += $qty;
                else if ($symbol) $holdings[$symbol] += $qty;
                break;
            case 'withdraw':
                if ($symbol === 'USDT') $holdings['USDT'] -= $qty;
                else if ($symbol) $holdings[$symbol] -= $qty;
                break;
            case 'buy':
                $holdings[$symbol] += $qty;
                if ($quote === 'USDT') $holdings['USDT'] -= $total;
                break;
            case 'sell':
                $holdings[$symbol] -= $qty;
                if ($quote === 'USDT') $holdings['USDT'] += ($total - $fee);
                break;
            case 'earn':
            case 'adjustment': // 支援校正類型
                $holdings[$symbol] += $qty;
                break;
        }
    }

    // ... addTransaction 保持不變 ...
    public function addTransaction(int $userId, array $data): bool {
        // (保持原有的 addTransaction 程式碼不變)
        // ... 
        if (empty($data['type']) || !isset($data['quantity'])) { return false; }
        $type = $data['type'];
        $base = strtoupper($data['baseCurrency'] ?? '');
        $quote = strtoupper($data['quoteCurrency'] ?? 'USDT');
        $price = (float)($data['price'] ?? 0);
        $qty = (float)$data['quantity'];
        $total = (float)($data['total'] ?? ($price * $qty));
        $fee = (float)($data['fee'] ?? 0);
        $date = $data['date'] ?? date('Y-m-d H:i:s');
        $note = $data['note'] ?? '';

        $sql = "INSERT INTO crypto_transactions 
                (user_id, type, base_currency, quote_currency, price, quantity, total, fee, transaction_date, note, created_at)
                VALUES (:uid, :type, :base, :quote, :price, :qty, :total, :fee, :date, :note, NOW())";
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([':uid'=>$userId, ':type'=>$type, ':base'=>$base, ':quote'=>$quote, ':price'=>$price, ':qty'=>$qty, ':total'=>$total, ':fee'=>$fee, ':date'=>$date, ':note'=>$note]);
        } catch (PDOException $e) {
            error_log("Crypto Insert Failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 🟢 [除錯版] getDashboardData
     */
    public function getDashboardData(int $userId): array {
        // 1. 撈取該用戶所有交易
        $sql = "SELECT * FROM crypto_transactions WHERE user_id = :uid ORDER BY transaction_date ASC, id ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        $txs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // [Debug] 記錄原始筆數
        $rawTxCount = count($txs);

        // 2. 初始化
        $holdings = []; 
        $totalInvestedTwd = 0; 

        // 3. 交易重播
        foreach ($txs as $tx) {
            $type = $tx['type'];
            $symbol = strtoupper($tx['base_currency'] ?? ''); // 確保大寫
            $quote = strtoupper($tx['quote_currency'] ?? 'USDT');
            
            $qty = (float)$tx['quantity'];
            $total = (float)$tx['total'];
            $fee = (float)$tx['fee'];

            // 初始化 Symbol
            if ($symbol && !isset($holdings[$symbol])) {
                $holdings[$symbol] = ['balance' => 0, 'cost_usd' => 0];
            }
            // USDT 特殊處理
            if ($quote === 'USDT' && !isset($holdings['USDT'])) {
                $holdings['USDT'] = ['balance' => 0, 'cost_usd' => 0];
            }

            switch ($type) {
                case 'deposit': 
                    if ($quote === 'TWD') $totalInvestedTwd += $total;
                    if ($symbol === 'USDT') {
                        $holdings['USDT']['balance'] += $qty;
                        $holdings['USDT']['cost_usd'] += $qty; 
                    }
                    // 🟢 修正：如果是直接入金其他幣種 (如 BTC) 也要加餘額
                    else if ($symbol && $symbol !== 'USDT') {
                        $holdings[$symbol]['balance'] += $qty;
                    }
                    break;

                case 'withdraw':
                    if ($quote === 'TWD') $totalInvestedTwd -= $total;
                    if ($symbol === 'USDT') {
                        $holdings['USDT']['balance'] -= $qty;
                        $holdings['USDT']['cost_usd'] -= $qty;
                    } else if ($symbol) {
                        $holdings[$symbol]['balance'] -= $qty;
                    }
                    break;

                case 'buy':
                    if ($symbol) {
                        $holdings[$symbol]['balance'] += $qty;
                        $holdings[$symbol]['cost_usd'] += $total + $fee; 
                    }
                    if ($quote === 'USDT') {
                        $holdings['USDT']['balance'] -= $total;
                        $holdings['USDT']['cost_usd'] -= $total; 
                    }
                    break;

                case 'sell':
                    if ($symbol) {
                        $currentBal = $holdings[$symbol]['balance'];
                        $currentCost = $holdings[$symbol]['cost_usd'];
                        $avgPrice = $currentBal > 0 ? ($currentCost / $currentBal) : 0;
                        $soldCost = $avgPrice * $qty;

                        $holdings[$symbol]['balance'] -= $qty;
                        $holdings[$symbol]['cost_usd'] -= $soldCost;
                    }
                    if ($quote === 'USDT') {
                        $holdings['USDT']['balance'] += ($total - $fee);
                        $holdings['USDT']['cost_usd'] += ($total - $fee);
                    }
                    break;

                case 'earn':
                    if ($symbol) $holdings[$symbol]['balance'] += $qty;
                    break;
            }
        }

        // 4. 計算現值
        $finalList = [];
        $totalValUsd = 0;
        $totalUnrealizedPnl = 0;

        foreach ($holdings as $sym => $data) {
            $bal = $data['balance'];
            
            // 🟢 [Debug] 暫時註解掉這個過濾器，看看是不是因為餘額太小
            // if ($bal <= 0.000001) continue; 

            // 避免 API 錯誤導致崩潰，加個 try
            try {
                $currentPrice = $this->rateService->getRateToUSD($sym);
            } catch (Exception $e) {
                $currentPrice = 0; // API 失敗時歸零
            }
            
            $currentVal = $bal * $currentPrice;
            $cost = $data['cost_usd'];
            $avgPrice = $bal > 0 ? ($cost / $bal) : 0; // 防止除以零
            
            $pnl = $currentVal - $cost;
            $roi = $cost > 0 ? ($pnl / $cost) * 100 : 0;

            $totalValUsd += $currentVal;
            $totalUnrealizedPnl += $pnl;

            $finalList[] = [
                'symbol' => $sym,
                'balance' => $bal,
                'valueUsd' => $currentVal,
                'costUsd' => $cost,
                'avgPrice' => $avgPrice,
                'currentPrice' => $currentPrice,
                'pnl' => $pnl,
                'pnlPercent' => $roi
            ];
        }

        $totalHoldingsCostUsd = $totalValUsd - $totalUnrealizedPnl;
        $totalRoiPercent = $totalHoldingsCostUsd > 0 ? ($totalUnrealizedPnl / $totalHoldingsCostUsd) * 100 : 0;

        return [
            'dashboard' => [
                'totalUsd' => $totalValUsd,
                'totalInvestedTwd' => $totalInvestedTwd,
                'pnl' => $totalUnrealizedPnl,
                'pnlPercent' => $totalRoiPercent
            ],
            'holdings' => $finalList,
            'usdTwdRate' => 32.0, // 簡化
            // 🟢 回傳除錯資訊，請在 Network Tab -> Response 查看
            'debug' => [
                'user_id_resolved' => $userId,
                'transactions_found_in_db' => $rawTxCount,
                'holdings_calculated_count' => count($finalList)
            ]
        ];
    }
    /**
     * [新增] 需求二：機械式再平衡建議
     */
    public function getRebalancingAdvice(int $userId): array {
        // 1. 取得用戶設定的目標比例
        $stmt = $this->pdo->prepare("SELECT target_usdt_ratio FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $targetRatio = (float)$stmt->fetchColumn(); // 例如 10.0

        // 2. 取得當前資產分佈 (呼叫既有的 getDashboardData)
        $dashboard = $this->getDashboardData($userId);
        $totalAssetsUsd = $dashboard['dashboard']['totalUsd']; // 總資產 (含 USDT + Crypto)
        
        // 找出目前的 USDT 餘額
        $currentUsdt = 0;
        foreach ($dashboard['holdings'] as $h) {
            if ($h['symbol'] === 'USDT') {
                $currentUsdt = $h['balance'];
                break;
            }
        }

        // 3. 計算目標與差額
        $targetUsdt = $totalAssetsUsd * ($targetRatio / 100);
        $diff = $currentUsdt - $targetUsdt; // 正數代表現金太多(該買)，負數代表現金太少(該賣)

        // 4. 生成建議
        $advice = [];
        $action = '';
        
        // 設定一個容忍區間 (例如偏差 < 1% 不動作，避免過度頻繁交易)
        $threshold = $totalAssetsUsd * 0.01; 

        if (abs($diff) < $threshold) {
            $action = 'HOLD';
            $message = "目前配置平衡，無需操作。";
        } elseif ($diff > 0) {
            // 現金太多 -> 買入其他幣種
            $action = 'BUY';
            $amountToInvest = abs($diff);
            $message = "現金比例過高 ({$targetRatio}%)。建議投入 $ " . number_format($amountToInvest, 2) . " USDT 到加密資產。";
        } else {
            // 現金太少 -> 賣出部分幣種
            $action = 'SELL';
            $amountToSell = abs($diff);
            $message = "現金水位不足。建議賣出價值 $ " . number_format($amountToSell, 2) . " 的加密資產回補 USDT。";
        }

        return [
            'target_ratio' => $targetRatio,
            'current_usdt' => $currentUsdt,
            'target_usdt' => $targetUsdt,
            'action' => $action,
            'message' => $message
        ];
    }

    /**
     * [新增] 需求三：合約/短線交易統計 (勝率、ROI)
     */
    public function getFuturesStats(int $userId): array {
        // 1. 撈取所有「已平倉」的交易
        $sql = "SELECT * FROM crypto_futures WHERE user_id = :uid AND status = 'CLOSED'";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        $trades = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalTrades = count($trades);
        if ($totalTrades === 0) {
            return ['win_rate' => 0, 'total_pnl' => 0, 'avg_roi' => 0, 'trades' => []];
        }

        $wins = 0;
        $totalPnl = 0;
        $totalRoi = 0;

        foreach ($trades as $t) {
            if ($t['pnl'] > 0) $wins++;
            $totalPnl += $t['pnl'];
            $totalRoi += $t['roi_percent'];
        }

        return [
            'win_rate' => round(($wins / $totalTrades) * 100, 1), // 勝率 %
            'total_trades' => $totalTrades,
            'total_pnl' => $totalPnl,
            'avg_roi' => round($totalRoi / $totalTrades, 2), // 平均 ROI
            'history' => array_slice($trades, 0, 10) // 只回傳最近 10 筆供顯示
        ];
    }

    /**
     * [新增] 開倉/平倉操作
     */
    public function handleFuturesTrade(int $userId, array $data): bool {
        // ... (實作開倉 INSERT 或平倉 UPDATE 的邏輯)
        // 平倉時需自動計算 PnL: (Exit - Entry) * Size * Leverage (視做多做空而定)
        return true; 
    }
    /**
     * 獲取歷史資產趨勢 (圖表用)
     */
    public function getHistoryData($userId, $range = '1y')
    {
        $db = new Database();
        $conn = $db->getConnection();

        // 設定時間範圍
        $interval = '1 YEAR';
        if ($range === '1m') $interval = '1 MONTH';
        if ($range === '6m') $interval = '6 MONTH';
        
        // 抓取每日資產快照 (假設您有 daily_asset_snapshots 表格)
        // 如果您是從交易紀錄即時計算，邏輯會比較複雜，這裡預設使用快照表
        $sql = "SELECT snapshot_date, total_usd_value 
                FROM daily_asset_snapshots 
                WHERE user_id = :uid 
                  AND snapshot_date >= DATE_SUB(NOW(), INTERVAL $interval)
                ORDER BY snapshot_date ASC";

        $stmt = $conn->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $labels = [];
        $data = [];

        foreach ($rows as $row) {
            $labels[] = substr($row['snapshot_date'], 5, 5); // 只取 MM-DD
            $data[] = (float)$row['total_usd_value'];
        }

        // 如果完全沒資料 (剛開始使用)，手動補一筆當下的資料以免圖表壞掉
        if (empty($data)) {
            $currentSummary = $this->getDashboardData($userId); // 取得當前資產
            $labels[] = date('m-d');
            $data[] = (float)$currentSummary['dashboard']['totalUsd'];
        }

        return ['labels' => $labels, 'data' => $data];
    }
    
}
?>