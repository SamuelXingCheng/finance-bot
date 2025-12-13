<?php
// src/CryptoService.php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ExchangeRateService.php';
require_once __DIR__ . '/GeminiService.php';

class CryptoService {
    private $pdo;
    private $rateService;
    private $geminiService;

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
        $this->rateService = new ExchangeRateService($this->pdo);
        $this->geminiService = new GeminiService();
    }

    /**
     * 🟢 [核心] 智慧匯入 CSV 交易記錄
     * 自動辨識 BitoPro / Binance / 或呼叫 AI 解析未知格式
     */
    public function importTransactionsFromCsv(int $userId, string $filePath): array {
        if (!file_exists($filePath)) {
            return ['success' => 0, 'failed' => 0, 'errors' => ['File not found']];
        }

        // 1. 讀取檔案內容 (預讀前幾行用於辨識)
        $fileContent = file_get_contents($filePath);
        $lines = explode(PHP_EOL, $fileContent);
        $lines = array_values(array_filter($lines, fn($l) => trim($l) !== '')); // 去除空行
        
        if (empty($lines)) return ['success' => 0, 'failed' => 0, 'errors' => ['Empty CSV']];

        // 讀取標頭 (Header)
        $header = str_getcsv($lines[0]);
        // 移除 BOM (避免 Excel 格式亂碼)
        if (!empty($header)) $header[0] = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $header[0]);

        // 2. 辨識交易所
        $exchange = $this->detectExchange($header);
        $mappingRule = null;

        // 若無法辨識，嘗試使用 Gemini AI 生成規則
        if (!$exchange) {
            $exchange = 'ai_auto';
            // 取前 4 行給 AI 參考
            $previewData = implode("\n", array_slice($lines, 0, 4));
            try {
                $mappingRule = $this->generateMappingRuleWithGemini($previewData);
            } catch (Exception $e) {
                return ['success' => 0, 'failed' => 0, 'errors' => ['AI Analysis Failed: ' . $e->getMessage()]];
            }
        }

        // 3. 逐行匯入
        $successCount = 0;
        $failCount = 0;
        $errors = [];

        // 重新開啟檔案以節省記憶體
        $handle = fopen($filePath, 'r');
        fgetcsv($handle); // 跳過標題行

        $lineNum = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $lineNum++;
            if (empty($row) || is_null($row[0])) continue;

            try {
                $txData = null;

                if ($exchange === 'bitopro') {
                    $txData = $this->parseBitoProRow($header, $row);
                } elseif ($exchange === 'binance') {
                    $txData = $this->parseBinanceRow($header, $row);
                } elseif ($exchange === 'ai_auto' && $mappingRule) {
                    $txData = $this->parseRowWithMapping($header, $row, $mappingRule);
                }

                if ($txData) {
                    // 執行寫入資料庫
                    if ($this->addTransaction($userId, $txData)) {
                        $successCount++;
                    } else {
                        $failCount++;
                        $errors[] = "Line $lineNum: DB Insert Failed";
                    }
                }
            } catch (Exception $e) {
                $failCount++;
                $errors[] = "Line $lineNum: " . $e->getMessage();
            }
        }
        fclose($handle);

        return [
            'exchange' => $exchange,
            'success' => $successCount,
            'failed' => $failCount,
            'errors' => array_slice($errors, 0, 10) // 只回傳前10個錯誤
        ];
    }

    // --- ⬇️ 輔助方法區 ⬇️ ---

    /**
     * [辨識] 根據標頭特徵判斷交易所
     */
    private function detectExchange(array $header): ?string {
        $headerString = strtolower(implode(',', $header));
        // BitoPro 特徵: "Order ID" 且 "Transaction Time"
        if (strpos($headerString, 'order id') !== false && strpos($headerString, 'transaction time') !== false) {
            return 'bitopro';
        }
        // Binance 特徵: "executed_qty"
        if (strpos($headerString, 'executed_qty') !== false) {
            return 'binance';
        }
        return null;
    }

    /**
     * [解析] BitoPro 格式
     */
    private function parseBitoProRow(array $header, array $row): ?array {
        $map = array_flip($header);
        
        // 篩選狀態 (只匯入已完成)
        $status = $row[$map['Status'] ?? -1] ?? '';
        if (strtolower($status) !== 'completed') return null;

        // 解析類型
        $rawType = strtolower($row[$map['Order Type'] ?? -1] ?? '');
        $type = '';
        if (strpos($rawType, 'buy') !== false) $type = 'buy';
        elseif (strpos($rawType, 'sell') !== false) $type = 'sell';
        else return null;

        // 費用 (優先取 Quote Fee 或 TWD Fee)
        $fee = (float)($row[$map['Total Fees (Converted to TWD)'] ?? 0]);
        if ($fee == 0) $fee = (float)($row[$map['Quote Currency Fee']] ?? 0);

        return [
            'type' => $type,
            'baseCurrency' => $row[$map['Base Currency']] ?? '',
            'quoteCurrency' => $row[$map['Quote Currency']] ?? 'TWD',
            'price' => (float)($row[$map['Executed Price']] ?? 0),
            'quantity' => (float)($row[$map['Executed Quantity']] ?? 0),
            'total' => (float)($row[$map['Executed Amount']] ?? 0),
            'fee' => $fee,
            'date' => $row[$map['Transaction Time']] ?? date('Y-m-d H:i:s'),
            'note' => 'BitoPro: ' . ($row[$map['Order ID']] ?? '')
        ];
    }

    /**
     * [解析] Binance 格式
     */
    private function parseBinanceRow(array $header, array $row): ?array {
        $map = array_flip($header);

        $side = strtoupper($row[$map['side'] ?? -1] ?? '');
        $type = ($side === 'BUY') ? 'buy' : (($side === 'SELL') ? 'sell' : '');
        if (!$type) return null;

        // 拆解交易對 (e.g. BTC_USDT)
        $symbol = $row[$map['symbol'] ?? -1] ?? '';
        $symbolClean = str_replace(['_PERP', '/'], '', $symbol);
        
        // 簡易判斷：若結尾是 USDT，則 Base 是前面的部分
        $base = $symbolClean; 
        $quote = 'USDT';
        if (substr($symbolClean, -4) === 'USDT') {
            $base = substr($symbolClean, 0, -4);
        }

        return [
            'type' => $type,
            'baseCurrency' => $base,
            'quoteCurrency' => $quote,
            'price' => (float)($row[$map['price'] ?? 0]),
            'quantity' => (float)($row[$map['executed_qty'] ?? 0]),
            'total' => (float)($row[$map['amount'] ?? 0]),
            'fee' => (float)($row[$map['fee'] ?? 0]),
            'date' => $row[$map['date(UTC+0)'] ?? 0] ?? date('Y-m-d H:i:s'),
            'note' => 'Binance: ' . $symbol
        ];
    }

    /**
     * [AI] 呼叫 Gemini 生成解析規則
     */
    private function generateMappingRuleWithGemini(string $csvPreview): array {
        $prompt = "你是一個資料工程師。請分析以下 CSV 範例(含標題)，回傳一個純 JSON 物件(不要 Markdown)，格式為：
        {
            \"columns\": {
                \"date\": \"時間欄位名\", \"type\": \"交易方向欄位名\", \"symbol\": \"幣種欄位名\",
                \"price\": \"價格欄位名\", \"quantity\": \"數量欄位名\", \"total\": \"總金額欄位名\", \"fee\": \"手續費欄位名\"
            },
            \"values\": {
                \"buy_keyword\": [\"買入\", \"Buy\", \"BID\"], \"sell_keyword\": [\"賣出\", \"Sell\", \"ASK\"]
            },
            \"symbol_format\": \"merged\"
        }
        CSV 資料：\n" . $csvPreview;

        $response = $this->geminiService->generateText($prompt);
        $jsonStr = preg_replace('/^```json\s*|\s*```$/', '', trim($response));
        
        $rule = json_decode($jsonStr, true);
        if (!$rule || !isset($rule['columns'])) {
            throw new Exception("AI Rule Gen Failed");
        }
        return $rule;
    }

    /**
     * [AI] 使用規則解析單行
     */
    private function parseRowWithMapping(array $header, array $row, array $rule): ?array {
        $colMap = array_flip($header);
        $getVal = fn($key) => isset($rule['columns'][$key], $colMap[$rule['columns'][$key]]) ? $row[$colMap[$rule['columns'][$key]]] : null;

        // 解析 Type
        $rawType = strtolower($getVal('type') ?? '');
        $type = '';
        foreach ($rule['values']['buy_keyword'] as $k) if (strpos($rawType, strtolower($k)) !== false) $type = 'buy';
        foreach ($rule['values']['sell_keyword'] as $k) if (strpos($rawType, strtolower($k)) !== false) $type = 'sell';
        if (!$type) return null;

        // 解析 Symbol (簡易版)
        $rawSym = strtoupper($getVal('symbol') ?? '');
        $base = $rawSym; 
        $quote = 'USDT';
        if (str_ends_with($rawSym, 'USDT')) $base = substr($rawSym, 0, -4);
        if (str_ends_with($rawSym, 'TWD')) { $base = substr($rawSym, 0, -3); $quote = 'TWD'; }

        return [
            'type' => $type,
            'baseCurrency' => $base,
            'quoteCurrency' => $quote,
            'price' => (float)$getVal('price'),
            'quantity' => (float)$getVal('quantity'),
            'total' => (float)$getVal('total'),
            'fee' => (float)$getVal('fee'),
            'date' => $getVal('date') ?? date('Y-m-d H:i:s'),
            'note' => 'AI Import'
        ];
    }

    /**
     * 新增單筆交易 (資料庫寫入)
     */
    public function addTransaction(int $userId, array $data): bool {
        if (empty($data['type']) || !isset($data['quantity'])) { return false; }
        
        $sql = "INSERT INTO crypto_transactions 
                (user_id, type, base_currency, quote_currency, price, quantity, total, fee, transaction_date, note, created_at)
                VALUES (:uid, :type, :base, :quote, :price, :qty, :total, :fee, :date, :note, NOW())";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':uid' => $userId,
                ':type' => $data['type'],
                ':base' => strtoupper($data['baseCurrency'] ?? ''),
                ':quote' => strtoupper($data['quoteCurrency'] ?? 'USDT'),
                ':price' => (float)($data['price'] ?? 0),
                ':qty' => (float)$data['quantity'],
                ':total' => (float)($data['total'] ?? 0),
                ':fee' => (float)($data['fee'] ?? 0),
                ':date' => $data['date'] ?? date('Y-m-d H:i:s'),
                ':note' => $data['note'] ?? ''
            ]);
        } catch (PDOException $e) {
            error_log("Add Tx Failed: " . $e->getMessage());
            return false;
        }
    }

    // --- ⬇️ 保留原有的其他方法 (請確保不要刪除) ⬇️ ---

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

        // 呼叫 addTransaction 或是手動寫入皆可，這裡簡化邏輯
        return $this->addTransaction($userId, [
            'type' => $type,
            'baseCurrency' => $symbol,
            'quantity' => abs($diff),
            'date' => $txDate,
            'note' => '快照更新'
        ]);
    }

    public function getDashboardData(int $userId): array {
        // ... (保留您原本的 Dashboard 邏輯，這裡省略以節省篇幅) ...
        // 若您原檔此方法很長，請務必複製貼上回來
        
        // 為避免錯誤，這裡提供一個最小可運作版本 (建議用您原本的覆蓋)
        $sql = "SELECT * FROM crypto_transactions WHERE user_id = :uid";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        $txs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 簡單計算餘額回傳
        $holdings = [];
        foreach ($txs as $tx) {
            $sym = $tx['base_currency'];
            if (!isset($holdings[$sym])) $holdings[$sym] = ['symbol'=>$sym, 'balance'=>0, 'type'=>'trade', 'name'=>'Wallet', 'valueUsd'=>0, 'pnl'=>0, 'avgPrice'=>0];
            
            if ($tx['type'] == 'buy' || $tx['type'] == 'deposit' || $tx['type'] == 'earn') 
                $holdings[$sym]['balance'] += $tx['quantity'];
            else 
                $holdings[$sym]['balance'] -= $tx['quantity'];
        }
        
        return [
            'dashboard' => ['totalUsd' => 0, 'unrealizedPnl' => 0, 'realizedPnl' => 0, 'pnlPercent' => 0],
            'holdings' => array_values($holdings),
            'usdTwdRate' => 32.5
        ];
    }
    
    public function getHistoryChartData(int $userId, string $range = '1y'): array {
        // ... (請保留您原本的圖表邏輯) ...
        return ['labels' => [], 'data' => []];
    }

    public function getRebalancingAdvice(int $userId): array {
         // ... (請保留您原本的再平衡邏輯) ...
         return ['current_usdt_ratio' => 0, 'target_ratio' => 10, 'action' => 'HOLD', 'message' => 'No Data'];
    }

    public function getFuturesStats(int $userId): array {
        // ... (請保留您原本的合約邏輯) ...
        return ['win_rate' => 0, 'total_pnl' => 0, 'avg_roi' => 0, 'total_trades' => 0, 'history' => []];
    }
}
?>