<?php
// src/ExchangeRateService.php (最終版，優化 Fiat 邏輯)

class ExchangeRateService {
    
    public const COIN_ID_MAP = [
        'BTC' => 'bitcoin', 'ETH' => 'ethereum', 'ADA' => 'cardano', 'USDT' => 'tether',
        'XMR' => 'monero'
    ];

    // 靜態 fallback 匯率 (1 單位 X 等於多少 USD)
    // 這裡只保留非 TWD 的法幣和加密貨幣
    private const RATES_TO_USD = [
        'EUR' => 1.08, 'GBP' => 1.25, 'CAD' => 0.74, 'AUD' => 0.65, 
        'CNY' => 0.14, 'HKD' => 0.128, 'SGD' => 0.74,
        'BTC' => 100000.0, 'ETH' => 3000.0, 'ADA' => 0.5, 'USDT' => 1.0, 
    ];
    
    // USD 兌換 TWD 的最終匯率
    private const USD_TWD_RATE = 32.0;

    /**
     * 獲取指定貨幣兌換 USD 的匯率 (USD 是計算的中繼基準)
     */
    public function getRateToUSD(string $fromCurrency): float {
        $currency = strtoupper($fromCurrency);

        // 1. 【優化點】：直接處理 TWD 和 USD 的匯率
        if ($currency === 'USD') {
            return 1.0;
        }
        if ($currency === 'TWD') {
            // 1 TWD 應該等於多少 USD (1/32)
            return 1.0 / self::USD_TWD_RATE; 
        }
        
        // 2. 檢查是否為加密貨幣 (呼叫 CoinGecko API)
        if (isset(self::COIN_ID_MAP[$currency])) {
            
            $coinId = self::COIN_ID_MAP[$currency];
            $vsCurrency = 'usd';
            $apiKey = getenv('COINGECKO_API_KEY'); 

            // 組裝 CoinGecko API URL
            $coinGeckoUrl = "https://api.coingecko.com/api/v3/simple/price?ids={$coinId}&vs_currencies={$vsCurrency}&x_cg_api_key={$apiKey}";

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $coinGeckoUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5); 
            $response = curl_exec($ch);
            curl_close($ch);
            
            $data = json_decode($response, true);

            if (isset($data[$coinId]['usd'])) {
                error_log("CoinGecko API SUCCESS for {$currency}.");
                return (float) $data[$coinId]['usd'];
            }
            
            error_log("CoinGecko API FAILED for {$currency}. Using static fallback.");
        }
        
        // 3. 檢查靜態法幣 (Fallback for EUR, JPY, etc.)
        if (isset(self::RATES_TO_USD[$currency])) {
            return self::RATES_TO_USD[$currency];
        }

        // 4. 最終 fallback
        error_log("ExchangeRateService: Rate for {$fromCurrency} not found. Defaulting to 1.0.");
        return 1.0; 
    }

    /**
     * 獲取 USD 兌換 TWD 的匯率 (不變)
     */
    public function getUsdTwdRate(): float {
        return self::USD_TWD_RATE; 
    }
}