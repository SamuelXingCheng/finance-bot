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
     * 🟢 [完全重寫] 取得歷史資產趨勢 (基於帳戶快照)
     * 邏輯：讀取 account_balance_history -> 篩選加密貨幣 -> 每日重播補值 -> 顯示特定日期
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

            // 判斷今天是否有實際的快照紀錄
            $isSnapshotDay = isset($historyByDate[$currentDate]);

            // A. 更新餘額
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

            // B. 決定是否記錄點位
            if ($currentDate >= $startDate) {
                $shouldRecord = true;
                if ($range !== '1m') {
                    // 規則：1號、15號、今天，或者「這一天有快照紀錄」
                    $shouldRecord = ($dayOfMonth === '01' || $dayOfMonth === '15' || $currentDate === $endDate || $isSnapshotDay);
                }

                if ($shouldRecord) {
                    $dailyTotalUsd = 0.0;

                    foreach ($currentBalances as $acc) {
                        $bal = $acc['balance'];
                        $unit = $acc['unit'];
                        
                        $rate = 0;
                        if ($unit === 'USDT') {
                            $rate = 1.0;
                        } elseif ($acc['hist_rate']) {
                            $rate = $acc['hist_rate'];
                        } else {
                            $rate = $currentRates[$unit] ?? 0;
                        }

                        $dailyTotalUsd += ($bal * $rate);
                    }

                    $chartLabels[] = $currentDate;
                    // 🟢 [修改]：保留 1 位小數
                    $chartData[] = round($dailyTotalUsd, 1);
                }
            }
        }

        return [
            'labels' => $chartLabels,
            'data' => $chartData
        ];
    }

    // ... (以下方法保持不變) ...

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
        } catch (PDOException $e) {
            error_log("Crypto Insert Failed: " . $e->getMessage());
            return false;
        }
    }

    public function getDashboardData(int $userId): array {
        // ... (這部分維持原樣，用於顯示當前持倉列表) ...
        $sql = "SELECT * FROM crypto_transactions WHERE user_id = :uid ORDER BY transaction_date ASC, id ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        $txs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $holdings = []; 
        $totalInvestedTwd = 0; 

        foreach ($txs as $tx) {
            $type = $tx['type'];
            $symbol = strtoupper($tx['base_currency'] ?? ''); 
            $quote = strtoupper($tx['quote_currency'] ?? 'USDT');
            $qty = (float)$tx['quantity'];
            $total = (float)$tx['total'];
            $fee = (float)$tx['fee'];

            if ($symbol && !isset($holdings[$symbol])) {
                $holdings[$symbol] = ['balance' => 0, 'cost_usd' => 0, 'source' => 'trade']; 
            }
            if ($quote === 'USDT' && !isset($holdings['USDT'])) {
                $holdings['USDT'] = ['balance' => 0, 'cost_usd' => 0, 'source' => 'trade'];
            }

            switch ($type) {
                case 'deposit': 
                    if ($quote === 'TWD') $totalInvestedTwd += $total;
                    if ($symbol === 'USDT') { $holdings['USDT']['balance'] += $qty; $holdings['USDT']['cost_usd'] += $qty; }
                    else if ($symbol) { $holdings[$symbol]['balance'] += $qty; }
                    break;
                case 'withdraw':
                    if ($quote === 'TWD') $totalInvestedTwd -= $total;
                    if ($symbol === 'USDT') { $holdings['USDT']['balance'] -= $qty; $holdings['USDT']['cost_usd'] -= $qty; }
                    else if ($symbol) { $holdings[$symbol]['balance'] -= $qty; }
                    break;
                case 'buy':
                    if ($symbol) { $holdings[$symbol]['balance'] += $qty; $holdings[$symbol]['cost_usd'] += $total + $fee; }
                    if ($quote === 'USDT') { $holdings['USDT']['balance'] -= $total; $holdings['USDT']['cost_usd'] -= $total; }
                    break;
                case 'sell':
                    if ($symbol) {
                        $currentBal = $holdings[$symbol]['balance'];
                        $currentCost = $holdings[$symbol]['cost_usd'];
                        $avgPrice = $currentBal > 0 ? ($currentCost / $currentBal) : 0;
                        $holdings[$symbol]['balance'] -= $qty;
                        $holdings[$symbol]['cost_usd'] -= ($avgPrice * $qty);
                    }
                    if ($quote === 'USDT') { $holdings['USDT']['balance'] += ($total - $fee); $holdings['USDT']['cost_usd'] += ($total - $fee); }
                    break;
                case 'earn':
                case 'adjustment':
                    if ($symbol) $holdings[$symbol]['balance'] += $qty;
                    break;
            }
        }

        $cryptoList = array_keys(ExchangeRateService::COIN_ID_MAP);
        $cryptoList[] = 'USDT';
        
        $placeholders = implode(',', array_fill(0, count($cryptoList), '?'));
        
        $accSql = "SELECT name, balance, currency_unit, type 
                   FROM accounts 
                   WHERE user_id = ? AND currency_unit IN ($placeholders)";
        
        $params = array_merge([$userId], $cryptoList);
        
        $stmtAcc = $this->pdo->prepare($accSql);
        $stmtAcc->execute($params);
        $accounts = $stmtAcc->fetchAll(PDO::FETCH_ASSOC);

        $finalList = [];
        $totalValUsd = 0;
        $totalUnrealizedPnl = 0;

        foreach ($holdings as $sym => $data) {
            $bal = $data['balance'];
            if ($bal <= 0.000001) continue; 

            $price = $this->rateService->getRateToUSD($sym) ?: 0;
            $currentVal = $bal * $price;
            $cost = $data['cost_usd'];
            $pnl = $currentVal - $cost;
            $roi = $cost > 0 ? ($pnl / $cost) * 100 : 0;

            $totalValUsd += $currentVal;
            $totalUnrealizedPnl += $pnl;

            $finalList[] = [
                'type' => 'trade', 
                'name' => 'Trading Wallet', 
                'symbol' => $sym,
                'balance' => $bal,
                'valueUsd' => $currentVal,
                'costUsd' => $cost,
                'avgPrice' => $bal > 0 ? ($cost / $bal) : 0,
                'currentPrice' => $price,
                'pnl' => $pnl,
                'pnlPercent' => $roi
            ];
        }

        foreach ($accounts as $acc) {
            $sym = strtoupper($acc['currency_unit']);
            $bal = (float)$acc['balance'];
            if ($bal <= 0) continue;

            $price = $this->rateService->getRateToUSD($sym) ?: 0;
            $currentVal = $bal * $price;
            $totalValUsd += $currentVal;

            $finalList[] = [
                'type' => 'account', 
                'name' => $acc['name'], 
                'symbol' => $sym,
                'balance' => $bal,
                'valueUsd' => $currentVal,
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

        $totalInvestedTrade = 0;
        foreach($holdings as $h) $totalInvestedTrade += $h['cost_usd'];
        $totalRoiPercent = $totalInvestedTrade > 0 ? ($totalUnrealizedPnl / $totalInvestedTrade) * 100 : 0;

        return [
            'dashboard' => [
                'totalUsd' => $totalValUsd,
                'totalInvestedTwd' => $totalInvestedTwd, 
                'pnl' => $totalUnrealizedPnl,
                'pnlPercent' => $totalRoiPercent
            ],
            'holdings' => $finalList,
            'usdTwdRate' => 32.0, 
        ];
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