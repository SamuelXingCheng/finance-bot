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
     * 修正：加入合約過濾邏輯，只顯示現貨資產
     */
    public function getDashboardData(int $userId): array {
        // 1. 撈取所有交易
        $sql = "SELECT * FROM crypto_transactions WHERE user_id = :uid ORDER BY transaction_date ASC, id ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        $txs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $portfolio = [];
        $totalInvestedTwd = 0; 

        // 動態取得 USD/TWD 匯率
        $usdTwdRate = $this->rateService->getUsdTwdRate();

        foreach ($txs as $tx) {
            $type = $tx['type'];
            $base = strtoupper($tx['base_currency'] ?? '');
            $quote = strtoupper($tx['quote_currency'] ?? 'USDT');
            
            // 🔴 [新增] 過濾合約交易：如果幣種包含 _PERP 或 -PERP，則跳過，不計入現貨資產
            if (str_contains($base, '_PERP') || str_contains($base, '-PERP')) {
                continue;
            }

            $qty = (float)$tx['quantity'];
            $total = (float)$tx['total'];
            $fee = (float)$tx['fee'];

            if ($base && !isset($portfolio[$base])) {
                $portfolio[$base] = ['qty' => 0, 'cost' => 0, 'realized' => 0];
            }
            if ($quote === 'USDT' && !isset($portfolio['USDT'])) {
                $portfolio['USDT'] = ['qty' => 0, 'cost' => 0, 'realized' => 0];
            }

            // 匯率換算使用動態匯率
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

        $totalHoldingCost = 0;
        foreach($finalList as $item) $totalHoldingCost += $item['costUsd'];
        
        $roiDenominator = 0;
        // 如果有入金紀錄，優先以總入金(換算成USD)為分母
        if ($totalInvestedTwd > 0) {
            $roiDenominator = $totalInvestedTwd / $usdTwdRate;
        } else {
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
            'usdTwdRate' => $usdTwdRate,
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

        $sql = "UPDATE crypto_transactions 
                SET type = :type, 
                    base_currency = :base, 
                    quote_currency = :quote, 
                    price = :price, 
                    quantity = :qty, 
                    total = :total, 
                    fee = :fee, 
                    transaction_date = :date, 
                    note = :note 
                WHERE id = :id AND user_id = :uid";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':id' => $id,
                ':uid' => $userId,
                ':type' => $data['type'],
                ':base' => strtoupper($data['baseCurrency'] ?? ''),
                ':quote' => strtoupper($data['quoteCurrency'] ?? 'USDT'),
                ':price' => (float)($data['price'] ?? 0),
                ':qty' => (float)$data['quantity'],
                ':total' => (float)($data['total'] ?? 0),
                ':fee' => (float)($data['fee'] ?? 0),
                ':date' => $data['date'],
                ':note' => $data['note'] ?? ''
            ]);
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

    /**
     * 🟢 [強力升級] CSV 批次處理
     * 支援 Spot (BTC_USDT) 與 Futures (USDT_ETH_PERP) 的智慧解析
     */
    public function processCsvBulk(int $userId, string $filePath, array $mapping): array {
        $handle = fopen($filePath, "r");
        if (!$handle) return ['count' => 0];

        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);

        $count = 0;
        $lineIndex = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if ($mapping['has_header'] && $lineIndex === 0) {
                $lineIndex++;
                continue;
            }

            $rawDate = $row[$mapping['date_col_index']] ?? null;
            
            // --- 🟢 智慧幣種解析邏輯 ---
            $base = '';
            $quote = 'USDT'; // 預設

            // 情況 A: 單一欄位 Pair (如 "BTC_USDT" 或 "USDT_ETH_PERP")
            if (isset($mapping['pair_col_index']) && $mapping['pair_col_index'] > -1) {
                $rawPair = $row[$mapping['pair_col_index']] ?? '';
                if (!$rawPair) continue; 
                
                // 1. 轉大寫並去除多餘空格，但保留底線以利辨識
                $pairClean = strtoupper(trim($rawPair));
                
                // 2. 判斷邏輯
                if (preg_match('/^USDT_([A-Z]+)_PERP$/', $pairClean, $matches)) {
                    // ★ 命中幣本位合約 (如 USDT_ETH_PERP)
                    // 這類合約通常 "價格" 是倒數或以幣計價，所以 Quote 設為中間幣種 (ETH)
                    $base = $pairClean; // 保留完整名稱作為資產名
                    $quote = $matches[1]; // ETH
                } 
                elseif (str_contains($pairClean, '_')) {
                    // ★ 標準分隔 (如 BTC_USDT)
                    $parts = explode('_', $pairClean);
                    if (count($parts) === 2) {
                        $base = $parts[0];
                        $quote = $parts[1];
                    } else {
                        // 複雜組合，保留原樣，嘗試猜測 Quote
                        $base = $pairClean;
                        if (str_ends_with($pairClean, 'USDT')) $quote = 'USDT';
                        elseif (str_ends_with($pairClean, 'USDC')) $quote = 'USDC';
                    }
                } 
                else {
                    // ★ 無分隔 (如 BTCUSDT)
                    $base = str_replace(['USDT', 'USDC', 'BUSD', 'TWD'], '', $pairClean);
                    if (str_ends_with($pairClean, 'TWD')) $quote = 'TWD';
                    elseif (str_ends_with($pairClean, 'USDC')) $quote = 'USDC';
                    elseif (str_ends_with($pairClean, 'BUSD')) $quote = 'BUSD';
                    else $quote = 'USDT'; // 預設
                }
            } 
            // 情況 B: 分離欄位 (Base/Quote)
            elseif (isset($mapping['base_col_index']) && isset($mapping['quote_col_index'])) {
                if ($mapping['base_col_index'] > -1) {
                    $base = strtoupper($row[$mapping['base_col_index']] ?? '');
                }
                if ($mapping['quote_col_index'] > -1) {
                    $quote = strtoupper($row[$mapping['quote_col_index']] ?? 'USDT');
                }
            }

            if (!$rawDate || !$base) continue;

            // --- 2. 其他數據提取 ---
            $rawSide  = isset($mapping['side_col_index']) && $mapping['side_col_index'] > -1 ? ($row[$mapping['side_col_index']] ?? '') : '';
            $rawPrice = isset($mapping['price_col_index']) && $mapping['price_col_index'] > -1 ? ($row[$mapping['price_col_index']] ?? 0) : 0;
            $rawQty   = isset($mapping['qty_col_index']) && $mapping['qty_col_index'] > -1 ? ($row[$mapping['qty_col_index']] ?? 0) : 0;
            $rawFee   = isset($mapping['fee_col_index']) && $mapping['fee_col_index'] > -1 ? ($row[$mapping['fee_col_index']] ?? 0) : 0;
            $rawTotal = isset($mapping['total_col_index']) && $mapping['total_col_index'] > -1 ? ($row[$mapping['total_col_index']] ?? 0) : 0;

            // --- 3. 資料正規化 ---
            try {
                $dateObj = DateTime::createFromFormat($mapping['date_format'], $rawDate);
                $transDate = $dateObj ? $dateObj->format('Y-m-d H:i:s') : date('Y-m-d H:i:s', strtotime($rawDate));
            } catch (Exception $e) {
                $transDate = date('Y-m-d H:i:s');
            }

            $type = 'buy'; 
            $rawSideLower = strtolower($rawSide);
            if (isset($mapping['side_mapping']['sell_keywords'])) {
                foreach ($mapping['side_mapping']['sell_keywords'] as $kw) {
                    if (strpos($rawSideLower, strtolower($kw)) !== false) {
                        $type = 'sell'; break;
                    }
                }
            }
            if (strpos($rawSideLower, 'sell') !== false || strpos($rawSideLower, 'short') !== false) $type = 'sell';

            $price = (float)str_replace(',', '', (string)$rawPrice);
            $qty   = (float)str_replace(',', '', (string)$rawQty);
            $total = (float)str_replace(',', '', (string)$rawTotal);
            $fee   = (float)str_replace(',', '', (string)$rawFee);

            if ($total == 0 && $price > 0 && $qty > 0) {
                $total = $price * $qty;
            }

            // --- 4. 寫入資料庫 ---
            $note = "CSV匯入 ({$mapping['exchange_name']})";
            $success = $this->addTransaction($userId, [
                'type' => $type,
                'baseCurrency' => $base,
                'quoteCurrency' => $quote,
                'price' => $price,
                'quantity' => $qty,
                'total' => $total,
                'fee' => $fee,
                'date' => $transDate,
                'note' => $note
            ]);

            if ($success) $count++;
            $lineIndex++;
        }
        
        fclose($handle);
        return ['count' => $count];
    }
}
?>