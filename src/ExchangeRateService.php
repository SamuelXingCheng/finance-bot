<?php

class ExchangeRateService {
    
    private PDO $pdo;
    
    // ==========================================
    // 1. 設定：前 20 大加密貨幣 Map (Symbol => CoinGecko ID)
    // ==========================================
    public const COIN_ID_MAP = [
        'BTC'  => 'bitcoin',
        'ETH'  => 'ethereum',
        'USDT' => 'tether',
        'XRP'  => 'ripple',
        'BNB'  => 'binancecoin',
        'SOL'  => 'solana',
        'USDC' => 'usd-coin',
        'DOGE' => 'dogecoin',
        'ADA'  => 'cardano',
        'TRX'  => 'tron',
        'AVAX' => 'avalanche-2',
        'SHIB' => 'shiba-inu',
        'TON'  => 'the-open-network',
        'DOT'  => 'polkadot',
        'LINK' => 'chainlink',
        'BCH'  => 'bitcoin-cash',
        'NEAR' => 'near',
        'LTC'  => 'litecoin',
        'SUI'  => 'sui',
        'UNI'  => 'uniswap'
    ];

    // ==========================================
    // 2. 設定：30 國法幣清單
    // ==========================================
    public const FIAT_LIST = [
        'USD', 'TWD', 'EUR', 'JPY', 'GBP', 'CNY', 'HKD', 'KRW', 'AUD', 'CAD',
        'SGD', 'CHF', 'NZD', 'THB', 'VND', 'PHP', 'IDR', 'MYR', 'INR', 'SEK',
        'ZAR', 'MXN', 'BRL', 'RUB', 'TRY', 'SAR', 'AED', 'PLN', 'NOK', 'DKK'
    ];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * 主入口：獲取指定貨幣兌換 USD 的匯率 (1 Unit = ? USD)
     */
    public function getRateToUSD(?string $fromCurrency): float {
        if (empty($fromCurrency)) return 0.0;

        $currency = strtoupper(trim($fromCurrency));

        // 1. 基礎法幣：USD
        if ($currency === 'USD') return 1.0;

        // 2. 判斷是否為加密貨幣
        if (isset(self::COIN_ID_MAP[$currency])) {
            return $this->getCryptoRate($currency);
        }

        // 3. 判斷是否為法幣 (包含 TWD)
        if (in_array($currency, self::FIAT_LIST)) {
            return $this->getFiatRate($currency);
        }
        
        // 4. 未知貨幣
        error_log("ExchangeRateService: Unknown currency {$currency}");
        return 0.0; 
    }

    // ==========================================
    // 邏輯區：加密貨幣處理
    // ==========================================
    private function getCryptoRate(string $symbol): float {
        $coinId = self::COIN_ID_MAP[$symbol];
        
        // A. 嘗試呼叫 API
        $rate = $this->fetchFromCoinGecko($coinId);

        if ($rate !== null) {
            $this->saveToDb($symbol, $rate); // API 成功：更新 DB
            return $rate;
        }

        // B. API 失敗：讀 DB
        $dbRate = $this->getFromDb($symbol);
        if ($dbRate !== null) {
            return $dbRate;
        }

        return 0.0; // 真的抓不到
    }

    // ==========================================
    // 邏輯區：法幣處理 (新增)
    // ==========================================
    private function getFiatRate(string $code): float {
        // 法幣 API 通常一次給全部，所以我們先查 DB 看看是不是最近更新過
        // 如果 DB 資料太舊(例如超過1小時)，或者是 0，才去 Call API
        
        $dbRate = $this->getFromDb($code);
        
        // 這裡可以加入時間判斷，簡單起見：如果 DB 有值且大於 0，暫時先用 DB (減少 API 呼叫)
        // 實務上建議加一個 updated_at 判斷，例如每小時強制更新一次
        if ($dbRate && $dbRate > 0) {
             // 隨機數 1/10 機率強制更新，或者你可以寫死判斷時間
            if (rand(1, 100) > 5) return $dbRate; 
        }

        // 呼叫法幣 API
        $allRates = $this->fetchFiatFromApi();
        
        if ($allRates && isset($allRates[$code])) {
            // API 回傳的是 1 USD = X Foreign (例如 JPY 150)
            // 我們需要的是 1 Foreign = ? USD (例如 1/150 = 0.0066)
            $rateToUsd = ($allRates[$code] > 0) ? (1 / $allRates[$code]) : 0;
            
            // 順便把所有法幣都存進 DB，下次查別的幣就不用再 Call API
            foreach ($allRates as $c => $r) {
                if (in_array($c, self::FIAT_LIST) && $r > 0) {
                    $this->saveToDb($c, 1 / $r);
                }
            }
            
            return $rateToUsd;
        }

        // API 失敗，回傳舊資料
        return $dbRate ?? 0.0;
    }

    // ==========================================
    // API 區：CoinGecko (Crypto)
    // ==========================================
    private function fetchFromCoinGecko(string $coinId): ?float {
        // 增加錯誤抑制，避免 API 頻繁失敗炸 log
        $apiKey = getenv('COINGECKO_API_KEY'); 
        $url = "https://api.coingecko.com/api/v3/simple/price?ids={$coinId}&vs_currencies=usd";

        $data = $this->makeRequest($url, $apiKey ? ['x-cg-demo-api-key: ' . $apiKey] : []);

        if (isset($data[$coinId]['usd'])) {
            return (float) $data[$coinId]['usd'];
        }
        return null;
    }

    // ==========================================
    // API 區：ExchangeRate-API (Fiat)
    // ==========================================
    private function fetchFiatFromApi(): ?array {
        // 免費公開 API，不需要 Key，基準貨幣 USD
        $url = "https://open.er-api.com/v6/latest/USD";
        $data = $this->makeRequest($url);

        if (isset($data['rates'])) {
            return $data['rates']; // 回傳 ['TWD' => 32.5, 'JPY' => 150, ...]
        }
        return null;
    }

    // ==========================================
    // 工具區：通用 cURL
    // ==========================================
    private function makeRequest(string $url, array $headers = []): ?array {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        
        // 模擬瀏覽器 User Agent 避免被擋
        $headers[] = 'User-Agent: Finbot/2.0';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            return json_decode($response, true);
        }
        return null;
    }
    /**
     * [相容性修正] 專門回傳 USD 對 TWD 匯率 (例如 32.5)
     * 舊的程式碼依賴這個方法
     */
    public function getUsdTwdRate(): float {
        // 利用現有的通用方法取得 1 TWD = ? USD (例如 0.0307)
        $twdValueInUsd = $this->getRateToUSD('TWD');
        
        // 數學換算：如果 1 TWD = 0.0307 USD，那 1 USD = 1 / 0.0307 TWD
        if ($twdValueInUsd > 0) {
            return 1 / $twdValueInUsd;
        }
        
        return 32.5; // 萬一資料庫跟API都死掉的最後防線
    }

    // ==========================================
    // 資料庫操作
    // ==========================================
    private function getFromDb(string $currency): ?float {
        try {
            $stmt = $this->pdo->prepare("SELECT rate_to_usd FROM exchange_rates WHERE currency_code = ? LIMIT 1");
            $stmt->execute([$currency]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? (float) $result['rate_to_usd'] : null;
        } catch (Exception $e) {
            error_log("DB Read Error: " . $e->getMessage());
            return null;
        }
    }

    private function saveToDb(string $currency, float $rate): void {
        try {
            // 使用 REPLACE INTO 或 ON DUPLICATE KEY UPDATE
            $sql = "INSERT INTO exchange_rates (currency_code, rate_to_usd, updated_at) VALUES (?, ?, NOW()) 
                    ON DUPLICATE KEY UPDATE rate_to_usd = VALUES(rate_to_usd), updated_at = NOW()";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$currency, $rate]);
        } catch (Exception $e) {
            error_log("DB Write Error: " . $e->getMessage());
        }
    }
}