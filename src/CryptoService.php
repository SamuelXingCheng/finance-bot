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

    /**
     * 🟢 [核心] 處理交易並更新庫存狀態 (WAC 平均成本法)
     * 這會取代原本單純的 insert，加入邏輯運算
     */
    public function addTransaction(int $userId, array $data): bool {
        // 1. 基本資料整理
        $type = $data['type'] ?? ''; // buy, sell, deposit, withdraw
        $base = strtoupper($data['baseCurrency'] ?? '');
        $quote = strtoupper($data['quoteCurrency'] ?? 'USDT');
        $price = (float)($data['price'] ?? 0);
        $qty = (float)($data['quantity'] ?? 0);
        $fee = (float)($data['fee'] ?? 0);
        $date = $data['date'] ?? date('Y-m-d H:i:s');
        $note = $data['note'] ?? '';
        
        // 匯率處理 (若非 USDT 交易，需換算成 USD 成本)
        $exchangeRateUsd = array_key_exists('exchange_rate_usd', $data) ? (float)$data['exchange_rate_usd'] : 1.0;
        
        // 計算總金額 (Total)
        $total = (float)($data['total'] ?? ($price * $qty));

        // 防重複檢查 (保留你原本的邏輯)
        if ($this->checkDuplicate($userId, $type, $base, $quote, $qty, $date)) {
            return true;
        }

        try {
            $this->pdo->beginTransaction();

            // 2. 獲取當前持倉狀態 (Inventory)
            $holding = $this->getHolding($userId, $base);
            $currentQty = (float)($holding['quantity'] ?? 0);
            $currentAvgCost = (float)($holding['avg_cost'] ?? 0);

            $realizedPnl = 0; // 只有賣出會有值
            $newQty = $currentQty;
            $newAvgCost = $currentAvgCost;

            // 3. 根據類型執行 FIFO/WAC 邏輯
            $costBasisUsd = ($total * $exchangeRateUsd); // 這次交易的總成本 (USD)

            switch ($type) {
                case 'buy':
                    // === 買入：更新平均成本 ===
                    $newQty = $currentQty + $qty;
                    if ($newQty > 0) {
                        // 公式：(舊總成本 + 新投入成本) / 新總數量
                        $oldTotalCost = $currentQty * $currentAvgCost;
                        $newAvgCost = ($oldTotalCost + $costBasisUsd) / $newQty;
                    }
                    break;

                case 'sell':
                    // === 賣出：計算損益，成本不變 ===
                    $newQty = $currentQty - $qty;
                    
                    // 計算已實現損益 (USD)
                    // 獲利 = (賣出總價USD - (賣出數量 * 平均成本))
                    $revenueUsd = $costBasisUsd; // 這裡的 costBasis 其實是賣出的回收金額
                    $costOfSold = $qty * $currentAvgCost;
                    $realizedPnl = $revenueUsd - $costOfSold;
                    
                    // 賣出不影響剩餘幣的單位成本，只減少數量
                    break;

                case 'deposit':
                    // === 入金/轉入 ===
                    // 若是法幣(TWD/USD)，不影響 crypto_holdings，只影響淨入金計算
                    // 若是加密貨幣轉入 (如從冷錢包)，視為庫存增加
                    if ($base && $base !== 'TWD' && $base !== 'USD') {
                        $newQty = $currentQty + $qty;
                        // 策略 A：冷錢包轉入視為成本不變 (稀釋均價? 還是繼承?)
                        // 這裡採用簡單做法：若使用者有輸入 Price，則視為買入更新成本；若 Price=0，則僅增加數量 (均價會被稀釋，類似空投)
                        if ($costBasisUsd > 0) {
                            $oldTotalCost = $currentQty * $currentAvgCost;
                            $newAvgCost = ($oldTotalCost + $costBasisUsd) / $newQty;
                        }
                    }
                    break;
                
                case 'withdraw':
                    // === 提領/轉出 ===
                    if ($base && $base !== 'TWD' && $base !== 'USD') {
                        $newQty = $currentQty - $qty;
                        // 轉出視為資產移動，不產生損益，成本維持不變
                    }
                    break;
            }

            // 4. 寫入交易紀錄 (包含算好的 realized_pnl)
            $sqlTx = "INSERT INTO crypto_transactions 
                      (user_id, type, base_currency, quote_currency, price, quantity, total, fee, realized_pnl, transaction_date, note, exchange_rate_usd, created_at)
                      VALUES (:uid, :type, :base, :quote, :price, :qty, :total, :fee, :pnl, :note, :rate, NOW())";
            
            $stmtTx = $this->pdo->prepare($sqlTx);
            $stmtTx->execute([
                ':uid' => $userId, ':type' => $type, ':base' => $base, ':quote' => $quote,
                ':price' => $price, ':qty' => $qty, ':total' => $total, ':fee' => $fee,
                ':pnl' => $realizedPnl, ':note' => $note, ':rate' => $exchangeRateUsd
            ]);

            // 5. 更新持倉表 (Upsert)
            if ($base && $base !== 'TWD' && $base !== 'USD') {
                $this->updateHolding($userId, $base, $newQty, $newAvgCost);
            }

            $this->pdo->commit();
            return true;

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log("Add Transaction Failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 🟢 [新版] 極速儀表板 (直接讀取狀態表)
     */
    public function getDashboardData(int $userId): array {
        // 1. 取得所有持倉 (來自 crypto_holdings)
        $sqlHoldings = "SELECT * FROM crypto_holdings WHERE user_id = :uid AND quantity > 0";
        $stmt = $this->pdo->prepare($sqlHoldings);
        $stmt->execute([':uid' => $userId]);
        $holdings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. 取得統計數據 (總入金、總已實現損益) - 這邊改用 SQL 聚合查詢，飛快！
        $sqlStats = "SELECT 
            SUM(CASE WHEN type = 'deposit' AND base_currency = 'TWD' THEN quantity ELSE 0 END) -
            SUM(CASE WHEN type = 'withdraw' AND base_currency = 'TWD' THEN quantity ELSE 0 END) as net_twd_invested,
            SUM(realized_pnl) as total_realized_pnl
            FROM crypto_transactions WHERE user_id = :uid";
        
        $stmtStats = $this->pdo->prepare($sqlStats);
        $stmtStats->execute([':uid' => $userId]);
        $stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

        $netInvestedTwd = (float)($stats['net_twd_invested'] ?? 0);
        $totalRealizedPnlUsd = (float)($stats['total_realized_pnl'] ?? 0);
        
        $usdTwdRate = $this->rateService->getUsdTwdRate();
        $netInvestedUsd = ($usdTwdRate > 0) ? ($netInvestedTwd / $usdTwdRate) : 0;

        $portfolio = [];
        $totalAssetsUsd = 0;

        // 3. 組合數據
        foreach ($holdings as $h) {
            $sym = $h['currency'];
            $qty = (float)$h['quantity'];
            $avgCost = (float)$h['avg_cost']; // 平均成本 (USD)

            // 取得現價
            $currentPrice = ($sym === 'USDT') ? 1.0 : $this->rateService->getRateToUSD($sym);
            
            $marketValue = $qty * $currentPrice;
            $totalCost = $qty * $avgCost;
            $unrealizedPnl = $marketValue - $totalCost;
            $roi = ($totalCost > 0) ? ($unrealizedPnl / $totalCost) * 100 : 0;

            $totalAssetsUsd += $marketValue;

            $portfolio[] = [
                'symbol' => $sym,
                'balance' => $qty,
                'avgPrice' => $avgCost,
                'currentPrice' => $currentPrice,
                'valueUsd' => $marketValue,
                'costUsd' => $totalCost,
                'pnl' => $unrealizedPnl, // 未實現
                'pnlPercent' => $roi
            ];
        }

        // 4. 計算總績效
        // 總損益 = (總資產現值 + 總已實現損益) - 總投入本金
        // 或者更直觀：總資產現值 - 淨投入(還留在場內的錢)
        // 這裡採用: 帳戶總權益 (Equity) = 現值
        // 總ROI計算: (總現值 + 已提領現金) - 總投入現金 ? 
        // 簡單版: (現值 - 淨投入)
        
        $totalProfit = $totalAssetsUsd - $netInvestedUsd;
        $totalRoi = ($netInvestedUsd > 0) ? ($totalProfit / $netInvestedUsd) * 100 : 0;

        return [
            'dashboard' => [
                'totalUsd' => $totalAssetsUsd,
                'netInvestedTwd' => $netInvestedTwd,
                'netInvestedUsd' => $netInvestedUsd,
                'totalPnl' => $totalProfit, // 包含未實現+已實現(因為現值已經反映了獲利保留)
                'realizedPnl' => $totalRealizedPnlUsd, // 參考用
                'pnlPercent' => $totalRoi
            ],
            'holdings' => $portfolio,
            'usdTwdRate' => $usdTwdRate
        ];
    }

    /**
     * 🟢 [救命功能] 重建庫存狀態
     * 當歷史資料被亂改，或 CSV 匯入順序錯誤時，呼叫此函式重跑一遍
     */
    public function recalculateHoldings(int $userId) {
        // 1. 清空該用戶的 holdings
        $this->pdo->prepare("DELETE FROM crypto_holdings WHERE user_id = ?")->execute([$userId]);
        
        // 2. 撈出所有交易 (依照時間正序！)
        $sql = "SELECT * FROM crypto_transactions WHERE user_id = ? ORDER BY transaction_date ASC, id ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId]);
        $txs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. 暫時關閉外層 Transaction (避免 nested transaction)
        // 這裡我們直接模擬 addTransaction 的邏輯，但不寫入 crypto_transactions，只更新 holdings
        
        $tempHoldings = []; // [ 'BTC' => ['qty'=>0, 'cost'=>0] ]

        foreach ($txs as $tx) {
            $type = $tx['type'];
            $base = strtoupper($tx['base_currency']);
            $qty = (float)$tx['quantity'];
            $total = (float)$tx['total'];
            $rate = (float)($tx['exchange_rate_usd'] ?? 1.0);
            $totalUsd = $total * $rate;
            
            if (!$base || $base === 'TWD') continue;
            if (!isset($tempHoldings[$base])) $tempHoldings[$base] = ['qty' => 0, 'cost' => 0];

            $h = &$tempHoldings[$base]; // 傳址引用

            if ($type === 'buy' || ($type === 'deposit' && $totalUsd > 0)) {
                $newQty = $h['qty'] + $qty;
                if ($newQty > 0) {
                    $oldCost = $h['qty'] * $h['cost']; // cost 存的是 avg_cost
                    $h['cost'] = ($oldCost + $totalUsd) / $newQty;
                }
                $h['qty'] = $newQty;
            } 
            elseif ($type === 'sell') {
                $h['qty'] -= $qty;
                // 賣出不影響平均成本
            }
            elseif ($type === 'withdraw') {
                $h['qty'] -= $qty;
            }
            
            // 計算並補寫 realized_pnl 到這筆交易 (Optional: 如果你想修復歷史損益數據)
            /* if ($type === 'sell') {
                $pnl = ($totalUsd) - ($qty * $h['cost']);
                $this->updateTxPnl($tx['id'], $pnl);
            }
            */
        }

        // 4. 寫回 DB
        foreach ($tempHoldings as $sym => $data) {
            if ($data['qty'] > 0) {
                $this->updateHolding($userId, $sym, $data['qty'], $data['cost']);
            }
        }
        
        return "Rebuild Complete.";
    }

    /**
     * 🟢 [新增] 執行資產快照 (Capture Snapshot)
     * 將當下的總資產價值、投入成本、損益存入 crypto_snapshots 表
     */
    public function captureSnapshot(int $userId): bool {
        // 1. 取得當前儀表板數據 (這是最準確的當下狀態)
        $data = $this->getDashboardData($userId);
        
        $dashboard = $data['dashboard'];
        $usdTwdRate = $data['usdTwdRate']; // 匯率
        
        // 2. 數據整理 (統一換算成 TWD 儲存，方便畫圖)
        // 注意：getDashboardData 回傳的 totalUsd 是美金，totalInvestedTwd 是台幣
        $totalValueUsd = $dashboard['totalUsd'];
        $totalCostTwd = $dashboard['totalInvestedTwd'];
        
        // 換算總市值為 TWD
        $totalValueTwd = $totalValueUsd * $usdTwdRate;
        
        // 計算 TWD 損益
        $pnlTwd = $totalValueTwd - $totalCostTwd;

        // 準備明細 JSON (備查用)
        $details = [
            'rate_usd_twd' => $usdTwdRate,
            'total_usd' => $totalValueUsd,
            'holdings' => array_map(function($h) {
                return [
                    'symbol' => $h['symbol'],
                    'qty' => $h['balance'],
                    'value_usd' => $h['valueUsd']
                ];
            }, $data['holdings'])
        ];

        // 3. 寫入資料庫
        $sql = "INSERT INTO crypto_snapshots 
                (user_id, total_value_twd, total_cost_twd, pnl, details_json, created_at)
                VALUES (:uid, :val, :cost, :pnl, :json, NOW())";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':uid' => $userId,
                ':val' => $totalValueTwd,
                ':cost' => $totalCostTwd,
                ':pnl' => $pnlTwd,
                ':json' => json_encode($details, JSON_UNESCAPED_UNICODE)
            ]);
        } catch (PDOException $e) {
            error_log("Snapshot Failed: " . $e->getMessage());
            return false;
        }
    }
    
    // --- 輔助函式 ---

    private function getHolding($userId, $currency) {
        $sql = "SELECT * FROM crypto_holdings WHERE user_id = :uid AND currency = :curr";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uid' => $userId, ':curr' => $currency]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function updateHolding($userId, $currency, $qty, $avgCost) {
        $sql = "INSERT INTO crypto_holdings (user_id, currency, quantity, avg_cost, updated_at)
                VALUES (:uid, :curr, :qty, :cost, NOW())
                ON DUPLICATE KEY UPDATE quantity = :qty, avg_cost = :cost, updated_at = NOW()";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uid' => $userId, ':curr' => $currency, ':qty' => $qty, ':cost' => $avgCost]);
    }
    
    private function checkDuplicate($userId, $type, $base, $quote, $qty, $date) {
        // ... (保持你原有的重複檢查邏輯) ...
        return false;
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