<?php
// src/CryptoService.php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ExchangeRateService.php';

class CryptoService {
    private $pdo;
    private $rateService;

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
        $this->rateService = new ExchangeRateService($this->pdo);
    }

    /**
     * 校正餘額 (支援指定日期，模擬「快照」行為)
     * 維持原樣，讓使用者可以補登交易
     */
    public function adjustBalance(int $userId, string $symbol, float $targetBalance, string $date = null): bool {
        $dashboard = $this->getDashboardData($userId);
        $currentBalance = 0.0;
        foreach ($dashboard['holdings'] as $h) {
            if ($h['symbol'] === $symbol) {
                $currentBalance = $h['balance'];
                break;
            }
        }

        $diff = $targetBalance - $currentBalance;
        if (abs($diff) < 0.00000001) return true;

        $type = $diff > 0 ? 'earn' : 'withdraw'; 
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
     * 🟢 [修正] 取得歷史資產趨勢 (基於帳戶快照)
     * 1. 篩選：只抓加密貨幣帳戶
     * 2. 顯示：長週期只顯示特定日期，短週期顯示每天
     * 3. 數值：保留一位小數
     */
    public function getHistoryChartData(int $userId, string $range = '1y'): array {
        // 1. 設定時間範圍
        $interval = '-1 year';
        if ($range === '1m') $interval = '-1 month';
        if ($range === '6m') $interval = '-6 months';
        
        $startDate = date('Y-m-d', strtotime($interval));
        $endDate = date('Y-m-d'); // 今天

        // 2. 準備加密貨幣白名單
        $cryptoList = array_keys(ExchangeRateService::COIN_ID_MAP);
        $cryptoList[] = 'USDT'; 

        // 3. 從「帳戶歷史快照表」撈取資料
        $sql = "SELECT snapshot_date, account_name, balance, currency_unit, exchange_rate 
                FROM account_balance_history 
                WHERE user_id = :uid AND snapshot_date >= :start
                ORDER BY snapshot_date ASC, id ASC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uid' => $userId, ':start' => $startDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 4. 資料整理
        $historyByDate = [];
        $firstDateInData = null;
        
        foreach ($rows as $row) {
            if (in_array(strtoupper($row['currency_unit']), $cryptoList)) {
                $d = $row['snapshot_date'];
                if (!$firstDateInData) $firstDateInData = $d;
                $historyByDate[$d][] = $row;
            }
        }

        // 5. 每日重播
        $replayStart = $firstDateInData ? min($firstDateInData, $startDate) : $startDate;
        
        $period = new DatePeriod(
            new DateTime($replayStart),
            new DateInterval('P1D'),
            (new DateTime($endDate))->modify('+1 day')
        );

        $currentBalances = []; 
        $chartLabels = [];
        $chartData = [];

        $currentRates = [];
        foreach ($cryptoList as $sym) {
            $currentRates[$sym] = $this->rateService->getRateToUSD($sym);
        }
        $currentRates['USDT'] = 1.0;

        foreach ($period as $dt) {
            $currentDate = $dt->format('Y-m-d');
            $dayOfMonth = $dt->format('d');
            $isSnapshotDay = isset($historyByDate[$currentDate]);

            if ($isSnapshotDay) {
                foreach ($historyByDate[$currentDate] as $record) {
                    $accName = $record['account_name'];
                    $currentBalances[$accName] = [
                        'balance' => (float)$record['balance'],
                        'unit' => strtoupper($record['currency_unit']),
                        'hist_rate' => !empty($record['exchange_rate']) ? (float)$record['exchange_rate'] : null
                    ];
                }
            }

            if ($currentDate >= $startDate) {
                $shouldRecord = true;
                if ($range !== '1m') {
                    $shouldRecord = ($dayOfMonth === '01' || $dayOfMonth === '15' || $currentDate === $endDate || $isSnapshotDay);
                }

                if ($shouldRecord) {
                    $dailyTotalUsd = 0.0;
                    foreach ($currentBalances as $acc) {
                        $bal = $acc['balance'];
                        $unit = $acc['unit'];
                        $rate = 0;
                        if ($unit === 'USDT') $rate = 1.0;
                        elseif ($acc['hist_rate']) $rate = $acc['hist_rate'];
                        else $rate = $currentRates[$unit] ?? 0;
                        $dailyTotalUsd += ($bal * $rate);
                    }
                    $chartLabels[] = $currentDate;
                    $chartData[] = round($dailyTotalUsd, 1);
                }
            }
        }
        return ['labels' => $chartLabels, 'data' => $chartData];
    }

    public function addTransaction(int $userId, array $data): bool {
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
        } catch (PDOException $e) { return false; }
    }

    /**
     * 🟢 [重寫] 儀表板數據 (區分 已實現/未實現 損益)
     * 修正：使用動態匯率 (usdTwdRate) 取代寫死的 32.0
     */
    public function getDashboardData(int $userId): array {
        // 1. 撈取所有交易
        $sql = "SELECT * FROM crypto_transactions WHERE user_id = :uid ORDER BY transaction_date ASC, id ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        $txs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $portfolio = [];
        $totalInvestedTwd = 0; 

        // 🟢 [修正] 動態取得 USD/TWD 匯率 (例如 32.5)
        $usdTwdRate = $this->rateService->getUsdTwdRate();

        foreach ($txs as $tx) {
            $type = $tx['type'];
            $base = strtoupper($tx['base_currency'] ?? '');
            $quote = strtoupper($tx['quote_currency'] ?? 'USDT');
            $qty = (float)$tx['quantity'];
            $total = (float)$tx['total'];
            $fee = (float)$tx['fee'];

            if ($base && !isset($portfolio[$base])) {
                $portfolio[$base] = ['qty' => 0, 'cost' => 0, 'realized' => 0];
            }
            if ($quote === 'USDT' && !isset($portfolio['USDT'])) {
                $portfolio['USDT'] = ['qty' => 0, 'cost' => 0, 'realized' => 0];
            }

            // 🟢 [修正] 匯率換算使用動態匯率
            // 如果交易是用 TWD 計價，轉為 USD
            $rateToUsd = ($quote === 'TWD') ? (1 / $usdTwdRate) : 1.0;
            $totalUsd = $total * $rateToUsd;
            $feeUsd = $fee * $rateToUsd;

            switch ($type) {
                case 'deposit':
                    if ($quote === 'TWD') $totalInvestedTwd += $total;
                    
                    if ($base === 'USDT') $portfolio['USDT']['qty'] += $qty;
                    else if ($base) $portfolio[$base]['qty'] += $qty;
                    break;

                case 'withdraw':
                    if ($quote === 'TWD') $totalInvestedTwd -= $total;

                    $target = ($base === 'USDT') ? 'USDT' : $base;
                    if (isset($portfolio[$target]) && $portfolio[$target]['qty'] > 0) {
                        $avgCost = $portfolio[$target]['cost'] / $portfolio[$target]['qty'];
                        $costPart = $avgCost * $qty;
                        $portfolio[$target]['qty'] -= $qty;
                        $portfolio[$target]['cost'] -= $costPart;
                    }
                    break;

                case 'buy':
                    if ($base) {
                        $portfolio[$base]['qty'] += $qty;
                        $portfolio[$base]['cost'] += ($totalUsd + $feeUsd); 
                    }
                    if ($quote === 'USDT') {
                        $portfolio['USDT']['qty'] -= $total;
                    }
                    break;

                case 'sell':
                    if ($base) {
                        $currentQty = $portfolio[$base]['qty'];
                        $currentCost = $portfolio[$base]['cost'];
                        
                        $avgCost = ($currentQty > 0) ? ($currentCost / $currentQty) : 0;
                        $costOfSold = $avgCost * $qty;
                        $revenue = $totalUsd - $feeUsd;

                        $realized = $revenue - $costOfSold;
                        $portfolio[$base]['realized'] += $realized;

                        $portfolio[$base]['qty'] -= $qty;
                        $portfolio[$base]['cost'] -= $costOfSold;
                    }
                    if ($quote === 'USDT') {
                        $portfolio['USDT']['qty'] += ($total - $fee);
                    }
                    break;

                case 'earn':
                case 'adjustment':
                    if ($base) {
                        $portfolio[$base]['qty'] += $qty;
                    }
                    break;
            }
        }

        // 3. 計算當前市值與未實現損益
        $finalList = [];
        $globalTotalUsd = 0;
        $globalUnrealizedPnl = 0;
        $globalRealizedPnl = 0;

        // A. 處理交易推算帳戶
        foreach ($portfolio as $sym => $data) {
            $qty = $data['qty'];
            if ($qty < 0.00000001 && $qty > -0.00000001) $qty = 0; 
            
            $globalRealizedPnl += $data['realized'];

            if ($qty > 0) {
                $price = $this->rateService->getRateToUSD($sym);
                if ($sym === 'USDT') $price = 1.0;

                $marketValue = $qty * $price;
                $costBasis = $data['cost'];
                
                $unrealized = $marketValue - $costBasis;
                $avgPrice = $costBasis / $qty;
                $roi = ($costBasis > 0) ? ($unrealized / $costBasis) * 100 : 0;

                $globalTotalUsd += $marketValue;
                $globalUnrealizedPnl += $unrealized;

                $finalList[] = [
                    'type' => 'trade',
                    'name' => 'Trading Wallet',
                    'symbol' => $sym,
                    'balance' => $qty,
                    'valueUsd' => $marketValue,
                    'costUsd' => $costBasis,
                    'avgPrice' => $avgPrice,
                    'currentPrice' => $price,
                    'pnl' => $unrealized,
                    'realized_pnl' => $data['realized'],
                    'pnlPercent' => $roi
                ];
            }
        }

        // B. 融合靜態帳戶
        $cryptoList = array_keys(ExchangeRateService::COIN_ID_MAP);
        $cryptoList[] = 'USDT';
        $placeholders = implode(',', array_fill(0, count($cryptoList), '?'));
        
        $accSql = "SELECT name, balance, currency_unit FROM accounts WHERE user_id = ? AND currency_unit IN ($placeholders)";
        $params = array_merge([$userId], $cryptoList);
        $stmtAcc = $this->pdo->prepare($accSql);
        $stmtAcc->execute($params);
        $accounts = $stmtAcc->fetchAll(PDO::FETCH_ASSOC);

        foreach ($accounts as $acc) {
            $sym = strtoupper($acc['currency_unit']);
            $bal = (float)$acc['balance'];
            if ($bal <= 0) continue;

            $price = ($sym === 'USDT') ? 1.0 : ($this->rateService->getRateToUSD($sym) ?: 0);
            $val = $bal * $price;
            
            $globalTotalUsd += $val;
            
            $finalList[] = [
                'type' => 'account',
                'name' => $acc['name'],
                'symbol' => $sym,
                'balance' => $bal,
                'valueUsd' => $val,
                'costUsd' => 0,
                'avgPrice' => 0,
                'currentPrice' => $price,
                'pnl' => 0,
                'pnlPercent' => 0
            ];
        }

        usort($finalList, function($a, $b) {
            return $b['valueUsd'] <=> $a['valueUsd'];
        });

        // 🟢 [修正] ROI 計算使用總本金 (若有) 作為分母，並使用動態匯率換算
        $totalHoldingCost = 0;
        foreach($finalList as $item) $totalHoldingCost += $item['costUsd'];
        
        $roiDenominator = 0;
        // 如果有入金紀錄，優先以總入金(換算成USD)為分母
        if ($totalInvestedTwd > 0) {
            $roiDenominator = $totalInvestedTwd / $usdTwdRate;
        } else {
            // 否則退回使用交易持倉成本 (若只用快照，這可能是 0)
            $roiDenominator = $totalHoldingCost;
        }

        $pnlPercent = ($roiDenominator > 0) ? ($globalUnrealizedPnl / $roiDenominator) * 100 : 0;

        return [
            'dashboard' => [
                'totalUsd' => $globalTotalUsd,
                'totalInvestedTwd' => $totalInvestedTwd, 
                'unrealizedPnl' => $globalUnrealizedPnl, 
                'realizedPnl' => $globalRealizedPnl,     
                'pnlPercent' => $pnlPercent
            ],
            'holdings' => $finalList,
            'usdTwdRate' => $usdTwdRate, // 回傳動態匯率
        ];
    }

    public function deleteTransaction(int $userId, int $id): bool {
        $sql = "DELETE FROM crypto_transactions WHERE id = :id AND user_id = :uid";
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([':id' => $id, ':uid' => $userId]);
        } catch (PDOException $e) { return false; }
    }

    public function updateTransaction(int $userId, int $id, array $data): bool {
        if (empty($data['type']) || !isset($data['quantity'])) return false;
        $sql = "UPDATE crypto_transactions SET type=:type, base_currency=:base, quote_currency=:quote, price=:price, quantity=:qty, total=:total, fee=:fee, transaction_date=:date, note=:note WHERE id=:id AND user_id=:uid";
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([':id'=>$id, ':uid'=>$userId, ':type'=>$data['type'], ':base'=>strtoupper($data['baseCurrency']??''), ':quote'=>strtoupper($data['quoteCurrency']??'USDT'), ':price'=>(float)($data['price']??0), ':qty'=>(float)$data['quantity'], ':total'=>(float)($data['total']??0), ':fee'=>(float)($data['fee']??0), ':date'=>$data['date'], ':note'=>$data['note']??'']);
        } catch (PDOException $e) { return false; }
    }
    
    public function getRebalancingAdvice(int $userId): array {
        $stmt = $this->pdo->prepare("SELECT target_usdt_ratio FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $targetRatio = (float)$stmt->fetchColumn(); 

        $dashboard = $this->getDashboardData($userId);
        $totalAssetsUsd = $dashboard['dashboard']['totalUsd']; 
        
        $currentUsdt = 0;
        foreach ($dashboard['holdings'] as $h) {
            if ($h['symbol'] === 'USDT') {
                $currentUsdt = $h['balance'];
                break;
            }
        }

        $targetUsdt = $totalAssetsUsd * ($targetRatio / 100);
        $diff = $currentUsdt - $targetUsdt; 

        $advice = [];
        $action = '';
        $message = "目前配置平衡，無需操作。";
        $threshold = $totalAssetsUsd * 0.01; 

        if (abs($diff) < $threshold) {
            $action = 'HOLD';
        } elseif ($diff > 0) {
            $action = 'BUY';
            $amountToInvest = abs($diff);
            $message = "現金比例過高 ({$targetRatio}%)。建議投入 $ " . number_format($amountToInvest, 2) . " USDT 到加密資產。";
        } else {
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

    public function getFuturesStats(int $userId): array {
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
            'win_rate' => round(($wins / $totalTrades) * 100, 1), 
            'total_trades' => $totalTrades,
            'total_pnl' => $totalPnl,
            'avg_roi' => round($totalRoi / $totalTrades, 2), 
            'history' => array_slice($trades, 0, 10) 
        ];
    }

    public function handleFuturesTrade(int $userId, array $data): bool {
        return true; 
    }
}
?>