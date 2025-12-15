<?php
// src/CryptoService.php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ExchangeRateService.php';
require_once __DIR__ . '/AssetService.php'; // 確保載入 AssetService

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

        // 2. 準備加密貨幣白名單 (只畫這些幣)
        $cryptoList = array_keys(ExchangeRateService::COIN_ID_MAP);
        $cryptoList[] = 'USDT'; 

        // 3. 撈取資料 (SQL 確保按插入順序排序)
        // 🟢 [修正] 移除名稱限制，只靠幣種白名單過濾
        $sql = "SELECT snapshot_date, account_name, balance, currency_unit, exchange_rate 
            FROM account_balance_history 
            WHERE user_id = :uid AND snapshot_date >= :start 
            ORDER BY snapshot_date ASC, id ASC"; // id ASC 確保後面的紀錄是較新的
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uid' => $userId, ':start' => $startDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 4. 資料整理 (關鍵修正：解決當天多筆問題)
        $historyByDate = [];
        $firstDateInData = null;
        
        foreach ($rows as $row) {
            // 只處理白名單內的幣種
            if (in_array(strtoupper($row['currency_unit']), $cryptoList)) {
                
                // 🟢 [關鍵修正] 強制格式化日期為 Y-m-d (忽略時間)
                // 這樣同一天如果有兩筆 (e.g. 10:00 和 14:00)，都會被歸類到同一個 Key
                $d = date('Y-m-d', strtotime($row['snapshot_date']));
                
                if (!$firstDateInData) $firstDateInData = $d;
                
                // 將資料放入該日期的陣列中
                // 因為我們之後會用 foreach 跑這個陣列，且 SQL 已排序，
                // 所以同一帳戶後面的資料會覆蓋前面的，確保只取「最新」。
                $historyByDate[$d][] = $row;
            }
        }

        // 5. 每日重播 (Replay) 計算總資產
        $replayStart = $firstDateInData ? min($firstDateInData, $startDate) : $startDate;
        
        $period = new DatePeriod(
            new DateTime($replayStart),
            new DateInterval('P1D'),
            (new DateTime($endDate))->modify('+1 day')
        );

        $currentBalances = []; 
        $chartLabels = [];
        $chartData = [];

        // 取得即時價格 (為了畫今天那個點)
        $currentRates = [];
        foreach ($cryptoList as $sym) {
            $currentRates[$sym] = $this->rateService->getRateToUSD($sym);
        }
        $currentRates['USDT'] = 1.0;

        foreach ($period as $dt) {
            $currentDate = $dt->format('Y-m-d');
            $dayOfMonth = $dt->format('d');
            $isToday = ($currentDate === date('Y-m-d'));

            // 檢查這一天有沒有歷史快照
            if (isset($historyByDate[$currentDate])) {
                // 有快照 -> 更新餘額
                // 這裡會遍歷該日期的所有紀錄。因為是按 id 排序，
                // 同一個 account_name 如果出現兩次，第二次的 balance 會覆蓋第一次的。
                // 這就完美解決了「當天多筆」的問題！
                foreach ($historyByDate[$currentDate] as $record) {
                    $accName = $record['account_name'];
                    $currentBalances[$accName] = [
                        'balance' => (float)$record['balance'],
                        'unit' => strtoupper($record['currency_unit']),
                        'hist_rate' => !empty($record['exchange_rate']) ? (float)$record['exchange_rate'] : null
                    ];
                }
            }

            // 產生圖表數據
            if ($currentDate >= $startDate) {
                // 決定是否紀錄該日 (減少點數密度，優化效能)
                $shouldRecord = true;
                if ($range !== '1m') {
                    // 如果不是看 1 個月，則只取 每月1號、15號、有快照那天、以及今天
                    $shouldRecord = ($dayOfMonth === '01' || $dayOfMonth === '15' || $currentDate === $endDate || isset($historyByDate[$currentDate]));
                }

                if ($shouldRecord) {
                    $dailyTotalUsd = 0.0;
                    foreach ($currentBalances as $acc) {
                        $bal = $acc['balance'];
                        $unit = $acc['unit'];
                        
                        // 🟢 [修正] 價格選擇邏輯：今天用即時價，過去用歷史價
                        $rate = 0;
                        if ($unit === 'USDT') {
                            $rate = 1.0;
                        } 
                        elseif ($isToday && isset($currentRates[$unit])) {
                            // 如果是今天，優先用 API 抓到的最新即時價 (圖表最右邊會跳動)
                            $rate = $currentRates[$unit];
                        }
                        elseif (!empty($acc['hist_rate'])) {
                            // 過去日期，使用當時快照存下來的價格
                            $rate = $acc['hist_rate'];
                        }
                        else {
                            // 萬一沒有歷史價格，只好用現在價格回推 (備案)
                            $rate = $currentRates[$unit] ?? 0;
                        }

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
     * 包含完整的交易鎖定 (Locking) 與 SQL 參數修正
     */
    public function addTransaction(int $userId, array $data): bool {
        // 1. 基本資料整理與防呆
        $type = strtolower($data['type'] ?? ''); // buy, sell, deposit, withdraw
        $base = strtoupper($data['baseCurrency'] ?? ''); // BTC, ETH
        $quote = strtoupper($data['quoteCurrency'] ?? 'USDT');
        $price = (float)($data['price'] ?? 0);
        $qty = abs((float)($data['quantity'] ?? 0)); // 強制轉正數，避免負負得正
        $fee = (float)($data['fee'] ?? 0);
        $date = $data['date'] ?? date('Y-m-d H:i:s');
        $note = $data['note'] ?? '';
        
        // 匯率處理 (若非 USDT 交易，需換算成 USD 成本)
        $exchangeRateUsd = array_key_exists('exchange_rate_usd', $data) ? (float)$data['exchange_rate_usd'] : 1.0;
        
        // 計算總金額 (Total) - 如果前端沒傳 total，就自己算
        $total = (float)($data['total'] ?? ($price * $qty));

        // 簡單防呆
        if ($qty <= 0) {
            error_log("Transaction Error: Quantity must be greater than 0");
            return false;
        }

        try {
            // 🔥 開啟交易 (Transaction Start)
            $this->pdo->beginTransaction();

            // 2. 獲取當前持倉狀態 (Inventory) 
            // 🔥 關鍵修正：加上 FOR UPDATE 鎖定這行資料，防止併發寫入時算錯
            $sqlGet = "SELECT quantity, avg_cost FROM crypto_holdings 
                       WHERE user_id = :uid AND currency = :base FOR UPDATE";
            $stmtGet = $this->pdo->prepare($sqlGet);
            $stmtGet->execute([':uid' => $userId, ':base' => $base]);
            $holding = $stmtGet->fetch(PDO::FETCH_ASSOC);

            $currentQty = (float)($holding['quantity'] ?? 0);
            $currentAvgCost = (float)($holding['avg_cost'] ?? 0);

            $realizedPnl = 0; // 只有賣出會有值
            $newQty = $currentQty;
            $newAvgCost = $currentAvgCost;

            // 3. 根據類型執行 FIFO/WAC 邏輯
            // 計算這次交易的「美金總成本/價值」
            $costBasisUsd = ($total * $exchangeRateUsd); 

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
                    // === 賣出：計算損益，成本單價不變 ===
                    // 防呆：不能賣超過持有的數量
                    if ($currentQty < $qty) {
                        throw new Exception("Insufficient balance to sell. Have: $currentQty, Try to sell: $qty");
                    }

                    $newQty = $currentQty - $qty;
                    
                    // 計算已實現損益 (USD)
                    // 獲利 = (賣出總回收價值USD - (賣出數量 * 平均成本))
                    $revenueUsd = $costBasisUsd; 
                    $costOfSold = $qty * $currentAvgCost;
                    $realizedPnl = $revenueUsd - $costOfSold;
                    
                    // 賣出不影響剩餘幣的「單位成本」，只減少數量
                    break;

                case 'deposit':
                    // === 入金/轉入 ===
                    // 排除法幣 (TWD/USD)，只處理加密貨幣庫存
                    if ($base && $base !== 'TWD' && $base !== 'USD') {
                        $newQty = $currentQty + $qty;
                        
                        // 若使用者有輸入 Price (例如從別處買入轉過來)，則更新成本
                        // 若 Price=0 (例如空投)，則只加數量，平均成本會被稀釋
                        if ($costBasisUsd > 0) {
                            $oldTotalCost = $currentQty * $currentAvgCost;
                            $newAvgCost = ($oldTotalCost + $costBasisUsd) / $newQty;
                        } else {
                            // 成本不變，數量變多 -> 均價下降 (稀釋)
                            if ($newQty > 0) {
                                $oldTotalCost = $currentQty * $currentAvgCost;
                                $newAvgCost = $oldTotalCost / $newQty;
                            }
                        }
                    }
                    break;
                
                case 'withdraw':
                    // === 提領/轉出 ===
                    if ($base && $base !== 'TWD' && $base !== 'USD') {
                        // 檢查餘額
                        if ($currentQty < $qty) {
                             // 這裡看你要報錯還是允許變成負數，通常建議報錯
                             // throw new Exception("Insufficient balance to withdraw");
                        }
                        $newQty = $currentQty - $qty;
                        // 轉出視為資產移動，不產生損益，單位成本維持不變
                    }
                    break;
            }

            // 4. 寫入交易紀錄 (crypto_transactions)
            // 🔥 修正：SQL 參數與 Execute 陣列完全對應
            $sqlTx = "INSERT INTO crypto_transactions 
                      (user_id, type, base_currency, quote_currency, price, quantity, total, fee, realized_pnl, transaction_date, note, exchange_rate_usd, created_at)
                      VALUES (:uid, :type, :base, :quote, :price, :qty, :total, :fee, :pnl, :date, :note, :rate, NOW())";
            
            $stmtTx = $this->pdo->prepare($sqlTx);
            $stmtTx->execute([
                ':uid' => $userId, 
                ':type' => $type, 
                ':base' => $base, 
                ':quote' => $quote,
                ':price' => $price, 
                ':qty' => $qty, 
                ':total' => $total, 
                ':fee' => $fee,
                ':pnl' => $realizedPnl,
                ':date' => $date, // 修正：這裡對應 SQL 的 :transaction_date (變數名改 :date 比較一致)
                ':note' => $note, 
                ':rate' => $exchangeRateUsd
            ]);

            // 5. 更新持倉表 (Upsert: 有就更新，沒有就新增)
            // 排除法幣，確保只更新 Crypto 資產
            // if ($base && $base !== 'TWD' && $base !== 'USD') {
            //     // 如果賣光了 (數量接近 0)，為了美觀可以把成本歸零，或者刪除該行
            //     if ($newQty <= 0.00000001) {
            //         $newQty = 0;
            //         $newAvgCost = 0;
            //     }

            //     $sqlUpsert = "INSERT INTO crypto_holdings (user_id, currency, quantity, avg_cost, updated_at)
            //                   VALUES (:uid, :base, :qty, :cost, NOW())
            //                   ON DUPLICATE KEY UPDATE 
            //                   quantity = VALUES(quantity), 
            //                   avg_cost = VALUES(avg_cost), 
            //                   updated_at = NOW()";
                
            //     $stmtUpsert = $this->pdo->prepare($sqlUpsert);
            //     $stmtUpsert->execute([
            //         ':uid' => $userId,
            //         ':base' => $base,
            //         ':qty' => $newQty,
            //         ':cost' => $newAvgCost
            //     ]);
            // }

            // 全部成功，提交！
            $this->pdo->commit();
            $this->captureSnapshot($userId);
            return true;

        } catch (Exception $e) {
            // 發生錯誤，回滾所有操作
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log("Add Transaction Failed: " . $e->getMessage());
            // 建議：開發階段可以 throw $e 出來看詳細錯誤，上線後再 return false
             throw $e; 
            // return false;
        }
    }

    /**
     * 🟢 [最終隔離版] 儀表板數據：
     * 1. 交易績效 (Trading PnL): 純粹依賴 BUY/SELL 交易紀錄 (淨流出法)。
     * 2. 資產盈餘 (Asset Surplus): 純粹依賴 Holdings 餘額快照 (與交易獨立)。
     *
     * *** 此版本新增 FIFO 成本核算，以計算精確的 Realized/Unrealized PnL ***
     */
    public function getDashboardData(int $userId): array {
        
        error_log("🚀 [Debug] 開始計算使用者 {$userId} 的 Dashboard 數據 (資產/交易隔離模式)...");

        // ==========================================
        // 1. [資產面] 取得持倉 (用於計算總現值)
        // ==========================================
        $sqlHoldings = "SELECT * FROM crypto_holdings WHERE user_id = :uid AND quantity > 0";
        $stmt = $this->pdo->prepare($sqlHoldings);
        $stmt->execute([':uid' => $userId]);
        $holdings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ==========================================
        // 2. [資金面] 取得淨入金 (用於計算資產盈餘)
        // ==========================================
        // ... (保持不變)
        $sqlNetInvest = "SELECT 
            SUM(CASE WHEN type = 'deposit' AND base_currency = 'TWD' THEN quantity ELSE 0 END) -
            SUM(CASE WHEN type = 'withdraw' AND base_currency = 'TWD' THEN quantity ELSE 0 END) as net_twd_invested
            FROM crypto_transactions WHERE user_id = :uid";
        
        $stmtInvest = $this->pdo->prepare($sqlNetInvest);
        $stmtInvest->execute([':uid' => $userId]);
        $netInvestedTwd = (float)($stmtInvest->fetchColumn() ?? 0);
        
        $usdTwdRate = $this->rateService->getUsdTwdRate();
        $netInvestedUsd = ($usdTwdRate > 0) ? ($netInvestedTwd / $usdTwdRate) : 0;

        error_log("💰 [資金] 淨入金(TWD): " . number_format($netInvestedTwd) . " / (USD): " . number_format($netInvestedUsd));

        // ==========================================
        // 3. [交易面] 取得交易流水 (用於 PHP 進行 FIFO 計算)
        //    *** 只取得 BUY/SELL 交易，並依時間排序 (FIFO) ***
        // ==========================================
        $sqlTradeDetails = "SELECT 
            base_currency, 
            quote_currency,
            type, 
            quantity, 
            price, 
            total,
            created_at 
            FROM crypto_transactions 
            WHERE user_id = :uid 
              AND type IN ('buy', 'sell')
            ORDER BY created_at ASC"; // 確保是 FIFO 順序
            
        $stmtTrade = $this->pdo->prepare($sqlTradeDetails);
        $stmtTrade->execute([':uid' => $userId]);
        $transactions = $stmtTrade->fetchAll(PDO::FETCH_ASSOC);

        // ==========================================
        // 3.1. [PHP 成本核算] 執行 FIFO 成本法計算 PnL
        // ==========================================
        $totalRealizedPnL = 0; // 追蹤已實現損益
        $inventory = [];       // 庫存堆疊，key 為 base_currency，value 為 FIFO 成本紀錄
        $legalTenderQuotes = ['USDT', 'USD', 'TWD']; // 法幣/穩定幣報價

        foreach ($transactions as $tx) {
            $base = $tx['base_currency'];
            $type = $tx['type'];
            $qty = (float)$tx['quantity'];
            $total = (float)$tx['total'];
            $quote = $tx['quote_currency'];
            
            // ⭐️ 僅處理法幣/穩定幣報價的交易 (排除幣本位)
            if (!in_array($quote, $legalTenderQuotes)) {
                continue; 
            }

            // 將 total 轉換為 USD (假設 USDT/USD 為 1:1)
            $cost_usd_or_revenue_usd = $total;
            if ($quote === 'TWD' && $usdTwdRate > 0) {
                $cost_usd_or_revenue_usd = $total / $usdTwdRate;
            }
            
            if ($type === 'buy') {
                // 買入：將新庫存推入堆疊
                if (!isset($inventory[$base])) {
                    $inventory[$base] = [];
                }
                $unit_cost_usd = ($qty > 0) ? $cost_usd_or_revenue_usd / $qty : 0; 
                // 儲存 [數量, 單位成本(USD)]
                $inventory[$base][] = ['qty' => $qty, 'cost' => $unit_cost_usd];

            } elseif ($type === 'sell') {
                // 賣出：從堆疊中執行 FIFO 清算
                $remaining_qty = $qty;
                $revenue_usd = $cost_usd_or_revenue_usd;
                $cost_of_goods_sold = 0;
                
                if (isset($inventory[$base])) {
                    // FIFO 邏輯：從最舊的庫存開始消耗
                    foreach ($inventory[$base] as $i => &$stock) {
                        if ($remaining_qty <= 0) break;

                        $use_qty = min($remaining_qty, $stock['qty']);
                        
                        $cost_of_goods_sold += $use_qty * $stock['cost']; // 計算賣出部分的成本
                        
                        $stock['qty'] -= $use_qty;
                        $remaining_qty -= $use_qty;

                        // PHP：如果庫存用完，標記為移除，但直到迴圈結束才真正移除 (避免索引問題)
                        if ($stock['qty'] < 1e-8) { // 使用微小數字避免浮點數誤差
                            $stock['qty'] = 0;
                        }
                    }
                    // 清除數量為 0 的庫存
                    $inventory[$base] = array_filter($inventory[$base], function($stock) {
                        return $stock['qty'] > 1e-8;
                    });
                    $inventory[$base] = array_values($inventory[$base]);
                }
                
                // 計算並累加已實現損益 (Realized PnL)
                $realized_pnl = $revenue_usd - $cost_of_goods_sold;
                $totalRealizedPnL += $realized_pnl;
            }
        }

        // ==========================================
        // 3.2. [結果計算] 根據 FIFO 庫存計算總未實現損益
        // ==========================================
        $totalUnrealizedPnL = 0;
        $fifoInventoryStats = [];
        
        foreach ($inventory as $sym => $stocks) {
            $total_qty = 0;
            $total_cost_usd = 0;
            
            // 計算剩餘庫存的總數量和總成本 (USD)
            foreach ($stocks as $stock) {
                $total_qty += $stock['qty'];
                $total_cost_usd += $stock['qty'] * $stock['cost'];
            }

            $currentPrice = ($sym === 'USDT') ? 1.0 : $this->rateService->getRateToUSD($sym);
            $marketValue = $total_qty * $currentPrice;
            
            // 未實現損益 = 市值 - FIFO 成本
            $unrealized_pnl = $marketValue - $total_cost_usd;
            $totalUnrealizedPnL += $unrealized_pnl;

            $avgCostPerUnit = ($total_qty > 0) ? $total_cost_usd / $total_qty : 0;
            
            // 儲存結果供後續迴圈使用
            $fifoInventoryStats[$sym] = [
                'net_qty' => $total_qty, 
                'fifo_total_cost' => $total_cost_usd,
                'fifo_avg_cost' => $avgCostPerUnit,
            ];
        }
        
        $totalTradingPnL = $totalRealizedPnL + $totalUnrealizedPnL; // 總 PnL
        
        // ==========================================
        // 4. 迴圈計算 (資產與 portfolio 列表)
        //    *** PnL 部分使用 FIFO 計算結果 ***
        // ==========================================
        $portfolio = [];
        $totalAssetsUsd = 0;
        
        // 確保涵蓋所有持倉和所有交易過的幣種
        $allSymbols = array_unique(array_merge(
            array_column($holdings, 'currency'), 
            array_keys($fifoInventoryStats)
        ));

        error_log("--------------------------------------------------");
        error_log("📊 [交易] 開始逐幣計算 PnL (FIFO 成本法):");

        foreach ($allSymbols as $sym) {
            
            $currentPrice = ($sym === 'USDT') ? 1.0 : $this->rateService->getRateToUSD($sym);

            // A. 資產面數據 (使用 Holdings 快照)
            $hKey = array_search($sym, array_column($holdings, 'currency'));
            $holdingQty = ($hKey !== false) ? (float)$holdings[$hKey]['quantity'] : 0;
            
            // 資產現值
            $marketValue = $holdingQty * $currentPrice;
            $totalAssetsUsd += $marketValue;

            // B. 從 FIFO 結果中獲取成本
            $fifoStats = $fifoInventoryStats[$sym] ?? ['net_qty'=>0, 'fifo_total_cost'=>0, 'fifo_avg_cost'=>0];
            $netTradeQty = (float)$fifoStats['net_qty'];
            $fifoTotalCost = (float)$fifoStats['fifo_total_cost'];
            $fifoAvgCost = (float)$fifoStats['fifo_avg_cost'];

            // 列表顯示用的個別數據 (使用 Holdings 數量和 FIFO 平均成本)
            if ($holdingQty > 0) { 
                $totalCost = $holdingQty * $fifoAvgCost; // 使用 FIFO 成本
                $unrealizedPnl = $marketValue - $totalCost; 
                $roi = ($totalCost > 0) ? ($unrealizedPnl / $totalCost) * 100 : 0;

                $portfolio[] = [
                    'symbol' => $sym,
                    'name' => $sym,
                    'type' => 'trade',
                    'balance' => $holdingQty,
                    'avgPrice' => $fifoAvgCost, // 顯示 FIFO 平均成本
                    'currentPrice' => $currentPrice,
                    'valueUsd' => $marketValue,
                    'costUsd' => $totalCost,
                    'pnl' => $unrealizedPnl,      // 該幣種的 FIFO 未實現損益
                    'pnlPercent' => $roi
                ];
            }
        }
        
        error_log("--------------------------------------------------");
        error_log("🏁 交易總績效 (Trading PnL): " . number_format($totalTradingPnL, 2));
        error_log("🏁 總已實現損益 (Realized PnL): " . number_format($totalRealizedPnL, 2));
        error_log("🏁 總未實現損益 (Unrealized PnL): " . number_format($totalUnrealizedPnL, 2)); 
        error_log("🏁 資產總現值 (Asset): " . number_format($totalAssetsUsd, 2));

        // ==========================================
        // 5. 最終指標 (完全獨立) - 使用 FIFO 結果
        // ==========================================
        
        $assetSurplus = $totalAssetsUsd - $netInvestedUsd;
        $tradingPnl = $totalTradingPnL; 
        $realizedPnl = $totalRealizedPnL;
        $unrealizedPnl = $totalUnrealizedPnL;
        $totalRoi = ($netInvestedUsd > 0) ? ($assetSurplus / $netInvestedUsd) * 100 : 0;

        return [
            'dashboard' => [
                'totalUsd' => $totalAssetsUsd,
                'netInvestedTwd' => $netInvestedTwd,
                'netInvestedUsd' => $netInvestedUsd,
                
                // 🟢 兩個獨立指標
                'assetSurplus' => $assetSurplus, 
                'tradingPnl' => $tradingPnl,      
                
                // 返回精確的 FIFO 分離結果
                'unrealizedPnl' => $unrealizedPnl, 
                'realizedPnl' => $realizedPnl, 
                'pnlPercent' => $totalRoi,
                
                'breakdown' => ['realizedSpot' => $realizedPnl, 'realizedCoin' => 0]
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
     * 🟢 [修正版] 執行資產快照 (Capture Snapshot)
     * 修正 ArgumentCountError 並確保資料寫入正確
     */
    public function captureSnapshot(int $userId, array $holdingsSnapshot, float $usdTwdRate, float $totalCostTwd): bool {
        
        // 1. 數據計算 (總覽部分)
        $totalValueUsd = 0.0;
        
        // 遍歷快照數據，計算總價值
        foreach ($holdingsSnapshot as &$h) {
            // 確保單價存在
            if (!isset($h['price_usd'])) {
                $h['price_usd'] = 0.0; // 防止未定義錯誤
            }
            
            // 如果沒有提供 TWD 價格，則自動換算
            if (!isset($h['price_twd'])) {
                $h['price_twd'] = (float)$h['price_usd'] * $usdTwdRate;
            }

            $h['value_usd'] = (float)$h['qty'] * (float)$h['price_usd'];
            $h['value_twd'] = (float)$h['qty'] * (float)$h['price_twd'];
            
            $totalValueUsd += $h['value_usd'];
        }
        unset($h); 

        // 總價值 (TWD)
        $totalValueTwd = $totalValueUsd * $usdTwdRate; 
        $pnlTwd = $totalValueTwd - $totalCostTwd;

        // 2. 準備明細 JSON (備查用)
        $details = [
            'rate_usd_twd' => $usdTwdRate,
            'total_usd' => $totalValueUsd,
            'holdings' => array_map(function($h) {
                return [
                    'symbol' => $h['symbol'],
                    'qty' => $h['qty'],
                    'price_usd' => $h['price_usd'],
                    'value_usd' => $h['value_usd']
                ];
            }, $holdingsSnapshot)
        ];

        try {
            $this->pdo->beginTransaction(); 

            // A. 寫入 crypto_snapshots (總資產快照表)
            $sql = "INSERT INTO crypto_snapshots 
                    (user_id, total_value_twd, total_cost_twd, pnl, details_json, created_at)
                    VALUES (:uid, :val, :cost, :pnl, :json, NOW())";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':uid' => $userId,
                ':val' => $totalValueTwd,
                ':cost' => $totalCostTwd,
                ':pnl' => $pnlTwd,
                ':json' => json_encode($details, JSON_UNESCAPED_UNICODE)
            ]);

            // B. 同步寫入 account_balance_history (通用資產歷史表)
            // 確保這裡載入正確
            if (!class_exists('AssetService')) {
                require_once __DIR__ . '/AssetService.php';
            }
            $assetService = new AssetService($this->pdo);
            $snapshotDate = date('Y-m-d');

            foreach ($holdingsSnapshot as $h) {
                if ((float)$h['qty'] > 0) {
                    
                    // 取得正確帳戶名稱
                    $sqlAccount = "SELECT name FROM accounts WHERE user_id = :userId AND currency_unit = :symbol AND type = 'Investment' ORDER BY name LIMIT 1";
                    $stmtAccount = $this->pdo->prepare($sqlAccount);
                    $stmtAccount->execute([':userId' => $userId, ':symbol' => $h['symbol']]);
                    $existingAccountName = $stmtAccount->fetchColumn();
                    $accountName = $existingAccountName ? $existingAccountName : "Crypto-" . $h['symbol'];

                    // 🔥 [修正重點] 補上第 5 個參數 (symbol) 及第 8 個參數 (customRate)
                    $assetService->upsertAccountBalance(
                        $userId,
                        $accountName,              // 2. 帳戶名稱
                        (float)$h['qty'],          // 3. 餘額
                        'Investment',              // 4. 類型
                        $h['symbol'],              // 🟢 5. 幣別單位 (修正 ArgumentCountError)
                        $snapshotDate,             // 6. 日期
                        null,                      // 7. Ledger ID
                        (float)$h['price_twd']     // 8. 自訂匯率 (傳入 TWD 單價，確保折線圖價值正確)
                    );
                }
            }

            $this->pdo->commit();
            return true;

        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
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
                $price = 0; 
                
                // 🟢 [修正] 如果 Quantity 沒抓到 (0)，但 Total 有值，把 Total 當作 Quantity
                if ($qty == 0 && $total > 0) {
                    $qty = $total;
                }
                
                $total = $qty; // 兩者同步
            } else { 
                // 一般買賣：如果沒 Total，自己算
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
    /**
     * 🟢 [新增] 主動更新市場價格和匯率
     * 呼叫 ExchangeRateService 邏輯，強制 API 更新並存入 exchange_rates 表格。
     */
    public function updateMarketPrices(): bool {
        // 1. 更新加密貨幣價格 (BTC, ETH, ...)
        $updatedCryptoCount = 0;
        // 遍歷 ExchangeRateService 中定義的所有追蹤幣種
        foreach (ExchangeRateService::COIN_ID_MAP as $symbol => $id) {
            // 呼叫 getRateToUSD 會觸發 ExchangeRateService 內部邏輯：
            // 嘗試從 API 獲取最新價格，並自動存入 exchange_rates 表格 (saveToDb)。
            $rate = $this->rateService->getRateToUSD($symbol);
            if ($rate > 0) {
                $updatedCryptoCount++;
            }
        }
        
        // 2. 更新法幣匯率 (主要目標是 TWD/USD)
        // 呼叫 getRateToUSD('TWD') 會觸發 getFiatRate，它會一次性更新 ExchangeRateService::FIAT_LIST 內所有法幣到 DB。
        $twdRate = $this->rateService->getRateToUSD('TWD');
        
        // 簡單檢查
        if ($updatedCryptoCount > 0 || $twdRate > 0) {
            error_log("Price Update Success. Crypto: {$updatedCryptoCount} coins, TWD Rate: " . (1/$twdRate));
            return true;
        }

        error_log("Price Update Failed: No rates updated.");
        return false;
    }

}
?>