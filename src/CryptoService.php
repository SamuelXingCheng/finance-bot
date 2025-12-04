<?php
// src/CryptoService.php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ExchangeRateService.php';

class CryptoService {
    private $pdo;
    private $rateService;

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
        $this->rateService = new ExchangeRateService();
    }

    /**
     * 新增一筆交易
     */
    public function addTransaction(int $userId, array $data): bool {
        // 1. 確保基本欄位存在
        if (empty($data['type']) || !isset($data['quantity'])) {
            return false;
        }

        // 2. 處理資料格式
        $type = $data['type']; // deposit, withdraw, buy, sell, earn
        $base = strtoupper($data['baseCurrency'] ?? '');  // 主幣 (BTC)
        $quote = strtoupper($data['quoteCurrency'] ?? 'USDT'); // 計價幣 (USDT, TWD)
        
        $price = (float)($data['price'] ?? 0);
        $qty = (float)$data['quantity'];
        // 如果前端沒傳 total，我們自己算
        $total = (float)($data['total'] ?? ($price * $qty));
        $fee = (float)($data['fee'] ?? 0);
        $date = $data['date'] ?? date('Y-m-d H:i:s');
        $note = $data['note'] ?? '';

        // 3. 寫入資料庫
        $sql = "INSERT INTO crypto_transactions 
                (user_id, type, base_currency, quote_currency, price, quantity, total, fee, transaction_date, note, created_at)
                VALUES (:uid, :type, :base, :quote, :price, :qty, :total, :fee, :date, :note, NOW())";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':uid' => $userId,
                ':type' => $type,
                ':base' => $base,
                ':quote' => $quote,
                ':price' => $price,
                ':qty' => $qty,
                ':total' => $total,
                ':fee' => $fee,
                ':date' => $date,
                ':note' => $note
            ]);
        } catch (PDOException $e) {
            error_log("Crypto Transaction Insert Failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 核心演算法：重播交易歷史，計算當下持倉與損益
     */
    public function getDashboardData(int $userId): array {
        // 1. 撈取該用戶所有交易 (按時間舊到新排序，這對計算均價很重要)
        $sql = "SELECT * FROM crypto_transactions WHERE user_id = :uid ORDER BY transaction_date ASC, id ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        $txs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. 初始化統計變數
        // holdings 結構: 'BTC' => ['balance'=>0, 'cost_usd'=>0]
        $holdings = []; 
        $totalInvestedTwd = 0; // 真正從銀行轉進來的台幣本金

        // 3. 交易重播 (Replay History)
        foreach ($txs as $tx) {
            $type = $tx['type'];
            $symbol = $tx['base_currency']; // 幣種 (BTC, ETH, USDT)
            $quote = $tx['quote_currency']; // 計價 (USDT, TWD)
            
            $qty = (float)$tx['quantity'];
            $total = (float)$tx['total']; // 總金額 (TWD 或 USDT)
            $fee = (float)$tx['fee'];

            // 確保容器存在
            if ($symbol && !isset($holdings[$symbol])) {
                $holdings[$symbol] = ['balance' => 0, 'cost_usd' => 0];
            }
            // USDT 也是一種資產，需初始化
            if ($quote === 'USDT' && !isset($holdings['USDT'])) {
                $holdings['USDT'] = ['balance' => 0, 'cost_usd' => 0];
            }

            switch ($type) {
                case 'deposit': 
                    // 入金 (TWD -> USDT)
                    if ($quote === 'TWD') {
                        $totalInvestedTwd += $total; // 累計台幣本金
                    }
                    if ($symbol === 'USDT') {
                        $holdings['USDT']['balance'] += $qty;
                        // USDT 的成本暫時視為 1:1 USD (簡化計算)
                        $holdings['USDT']['cost_usd'] += $qty; 
                    }
                    break;

                case 'withdraw':
                    // 出金 (USDT -> TWD)
                    if ($quote === 'TWD') {
                        $totalInvestedTwd -= $total; // 本金回收
                    }
                    if ($symbol === 'USDT') {
                        $holdings['USDT']['balance'] -= $qty;
                        $holdings['USDT']['cost_usd'] -= $qty;
                    }
                    break;

                case 'buy':
                    // 買幣 (用 USDT 買 BTC)
                    // 1. 增加 BTC
                    $holdings[$symbol]['balance'] += $qty;
                    // 新成本 = 舊成本 + 本次花費(USDT) + 手續費
                    $holdings[$symbol]['cost_usd'] += $total + $fee; 
                    
                    // 2. 扣除 USDT (如果是用 U 買)
                    if ($quote === 'USDT') {
                        $holdings['USDT']['balance'] -= $total;
                        $holdings['USDT']['cost_usd'] -= $total; 
                    }
                    break;

                case 'sell':
                    // 賣幣 (賣 BTC 換 USDT)
                    // 1. 計算賣出部分的成本 (依比例扣除)
                    $currentBal = $holdings[$symbol]['balance'];
                    $currentCost = $holdings[$symbol]['cost_usd'];
                    $avgPrice = $currentBal > 0 ? ($currentCost / $currentBal) : 0;
                    $soldCost = $avgPrice * $qty;

                    // 2. 減少 BTC
                    $holdings[$symbol]['balance'] -= $qty;
                    $holdings[$symbol]['cost_usd'] -= $soldCost;

                    // 3. 增加 USDT
                    if ($quote === 'USDT') {
                        $holdings['USDT']['balance'] += ($total - $fee);
                        $holdings['USDT']['cost_usd'] += ($total - $fee);
                    }
                    break;

                case 'earn':
                    // 理財/空投 (零成本增加)
                    $holdings[$symbol]['balance'] += $qty;
                    // 成本不變 -> 均價自然降低
                    break;
            }
        }

        // 4. 計算現值與損益 (整合 ExchangeRateService)
        $finalList = [];
        $totalValUsd = 0;
        $totalUnrealizedPnl = 0;

        foreach ($holdings as $sym => $data) {
            $bal = $data['balance'];
            if ($bal <= 0.000001) continue; // 忽略極小餘額

            // 取得現價 (USD)
            $currentPrice = $this->rateService->getRateToUSD($sym);
            
            $currentVal = $bal * $currentPrice;
            $cost = $data['cost_usd'];
            $avgPrice = $cost > 0 ? ($cost / $bal) : 0;
            
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

        // 5. 匯總
        // 總 ROI = (總現值 - 總成本USD) / 總成本USD
        // 注意：這裡的總成本是所有持倉的當下成本加總，而非 totalInvestedTwd
        $totalHoldingsCostUsd = $totalValUsd - $totalUnrealizedPnl;
        $totalRoiPercent = $totalHoldingsCostUsd > 0 ? ($totalUnrealizedPnl / $totalHoldingsCostUsd) * 100 : 0;

        return [
            'dashboard' => [
                'totalUsd' => $totalValUsd,
                'totalInvestedTwd' => $totalInvestedTwd, // 用戶實際入金的台幣
                'pnl' => $totalUnrealizedPnl,
                'pnlPercent' => $totalRoiPercent
            ],
            'holdings' => $finalList,
            'usdTwdRate' => $this->rateService->getUsdTwdRate() // 回傳匯率供前端換算
        ];
    }
}
?>