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
        $exchangeRateUsd = (float)($data['exchange_rate_usd'] ?? 1.0);
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
                (user_id, type, base_currency, quote_currency, price, quantity, total, fee, transaction_date, note, exchange_rate_usd, created_at)
                VALUES (:uid, :type, :base, :quote, :price, :qty, :total, :fee, :date, :note, :rate, NOW())";
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':uid'=>$userId, ':type'=>$type, ':base'=>$base, ':quote'=>$quote, 
                ':price'=>$price, ':qty'=>$qty, ':total'=>$total, ':fee'=>$fee, 
                ':date'=>$date, ':note'=>$note, 
                ':rate' => $exchangeRateUsd // 🟢 綁定參數
            ]);
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
     * 🟢 [台灣專用版] CSV 批次處理 (整合歷史匯率查詢)
     */
    public function processCsvBulk(int $userId, string $filePath, array $mapping): array {
        // 1. 讀取整個檔案內容
        $content = file_get_contents($filePath);
        if ($content === false) return ['count' => 0];

        // 2. 偵測並轉換編碼 (防止中文亂碼)
        if (!preg_match('//u', $content)) {
            $content = mb_convert_encoding($content, 'UTF-8', 'BIG-5');
        }

        // 3. 將內容切割成行
        $lines = explode("\n", $content);
        $count = 0;
        
        // 🟢 [新增] 匯率快取與設定
        $rateCache = []; // 暫存已查詢過的匯率 (Key: Symbol_Date)
        $skipRates = ['USDT', 'USDC', 'BUSD', 'DAI', 'TWD', 'FDUSD']; // 這些幣種視為 1:1，不查匯率

        foreach ($lines as $index => $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $row = str_getcsv($line);

            // 跳過標頭
            if ($mapping['has_header'] && $index === 0) {
                continue;
            }

            // --- A. 解析日期 ---
            $rawDate = $row[$mapping['date_col_index']] ?? null;
            if (!$rawDate) continue;

            try {
                $dateObj = DateTime::createFromFormat($mapping['date_format'], $rawDate);
                $transDate = $dateObj ? $dateObj->format('Y-m-d H:i:s') : date('Y-m-d H:i:s', strtotime($rawDate));
            } catch (Exception $e) { 
                $transDate = date('Y-m-d H:i:s'); 
            }

            // --- B. 解析交易方向 (Type) ---
            $rawSide = isset($mapping['side_col_index']) && $mapping['side_col_index'] > -1 ? ($row[$mapping['side_col_index']] ?? '') : '';
            $rawSideLower = mb_strtolower($rawSide, 'UTF-8'); 
            $type = 'buy'; 
            $isTransfer = false;

            // 優先檢查 Mapping 設定的關鍵字
            if (isset($mapping['side_mapping']['deposit_keywords'])) { 
                foreach ($mapping['side_mapping']['deposit_keywords'] as $kw) { 
                    if (str_contains($rawSideLower, mb_strtolower($kw, 'UTF-8'))) { $type = 'deposit'; $isTransfer = true; break; } 
                } 
            }
            if (!$isTransfer && isset($mapping['side_mapping']['withdraw_keywords'])) { 
                foreach ($mapping['side_mapping']['withdraw_keywords'] as $kw) { 
                    if (str_contains($rawSideLower, mb_strtolower($kw, 'UTF-8'))) { $type = 'withdraw'; $isTransfer = true; break; } 
                } 
            }
            if (!$isTransfer && isset($mapping['side_mapping']['sell_keywords'])) { 
                foreach ($mapping['side_mapping']['sell_keywords'] as $kw) { 
                    if (str_contains($rawSideLower, mb_strtolower($kw, 'UTF-8'))) { $type = 'sell'; break; } 
                } 
            }
            // 預設關鍵字檢查
            if (!$isTransfer) {
                if (str_contains($rawSideLower, '加值') || str_contains($rawSideLower, 'deposit') || str_contains($rawSideLower, 'in')) $type = 'deposit';
                elseif (str_contains($rawSideLower, '提領') || str_contains($rawSideLower, 'withdraw') || str_contains($rawSideLower, 'out')) $type = 'withdraw';
                elseif (str_contains($rawSideLower, '賣') || str_contains($rawSideLower, 'sell') || str_contains($rawSideLower, 'short')) $type = 'sell';
            }

            // --- C. 解析幣種 (Base/Quote) ---
            $base = ''; $quote = 'USDT';
            if (isset($mapping['pair_col_index']) && $mapping['pair_col_index'] > -1) {
                // 模式 1: 單一欄位 (如 ETH_BTC)
                $rawPair = $row[$mapping['pair_col_index']] ?? '';
                if ($rawPair) {
                    $pairClean = strtoupper(trim($rawPair));
                    if (preg_match('/^USDT_([A-Z]+)_PERP$/', $pairClean, $matches)) { 
                        $base = $pairClean; $quote = $matches[1]; 
                    } elseif (str_contains($pairClean, '_')) { 
                        $parts = explode('_', $pairClean); 
                        if (count($parts) === 2) { $base = $parts[0]; $quote = $parts[1]; } 
                    } else { 
                        // 簡單推測
                        $base = str_replace(['USDT', 'USDC', 'BUSD', 'TWD'], '', $pairClean); 
                        if (str_ends_with($pairClean, 'TWD')) $quote = 'TWD'; else $quote = 'USDT'; 
                    }
                }
            } elseif (isset($mapping['base_col_index'])) {
                // 模式 2: 分開欄位
                if ($mapping['base_col_index'] > -1) $base = strtoupper($row[$mapping['base_col_index']] ?? '');
                if (isset($mapping['quote_col_index']) && $mapping['quote_col_index'] > -1) $quote = strtoupper($row[$mapping['quote_col_index']] ?? 'USDT');
                else if ($base === 'TWD') $quote = 'TWD';
            }
            if (!$base) continue; // 沒幣種就跳過

            // --- D. 解析數值 (Price, Qty, Fee, Total) ---
            $rawPrice = isset($mapping['price_col_index']) && $mapping['price_col_index'] > -1 ? ($row[$mapping['price_col_index']] ?? 0) : 0;
            $rawQty   = isset($mapping['qty_col_index']) && $mapping['qty_col_index'] > -1 ? ($row[$mapping['qty_col_index']] ?? 0) : 0;
            $rawFee   = isset($mapping['fee_col_index']) && $mapping['fee_col_index'] > -1 ? ($row[$mapping['fee_col_index']] ?? 0) : 0;
            $rawTotal = isset($mapping['total_col_index']) && $mapping['total_col_index'] > -1 ? ($row[$mapping['total_col_index']] ?? 0) : 0;

            // 去除千分位逗號並轉浮點數
            $price = (float)str_replace(',', '', (string)$rawPrice);
            $qty   = (float)str_replace(',', '', (string)$rawQty);
            $total = (float)str_replace(',', '', (string)$rawTotal);
            $fee   = (float)str_replace(',', '', (string)$rawFee);

            // 數值校正
            if ($type === 'deposit' || $type === 'withdraw') { 
                $price = 0; $total = $qty; 
            } else { 
                if ($total == 0 && $price > 0 && $qty > 0) $total = $price * $qty; 
            }

            $payload = [
                'type' => $type,
                'baseCurrency' => $base,
                'quoteCurrency' => $quote,
                'price' => $price,
                'quantity' => $qty,
                'total' => $total,
                'fee' => $fee,
                'date' => $transDate,
                'note' => $note ?? "CSV匯入",
                'exchange_name' => $mapping['exchange_name'] ?? 'Unknown'
            ];
    
            // 寫入佇列資料表
            $sql = "INSERT INTO crypto_import_queue (user_id, data_payload, status, created_at) 
                    VALUES (:uid, :data, 'PENDING', NOW())";
            
            try {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    ':uid' => $userId,
                    ':data' => json_encode($payload, JSON_UNESCAPED_UNICODE)
                ]);
                $count++;
            } catch (Exception $e) {
                error_log("Queue Insert Failed: " . $e->getMessage());
            }
        }
        
        return ['count' => $count, 'message' => '已加入排程佇列，系統將在背景陸續處理。'];
    }
}
?>