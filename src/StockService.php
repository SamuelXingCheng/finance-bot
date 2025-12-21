<?php
// src/StockService.php

require_once __DIR__ . '/../config.php';

class StockService {
    private $finnhubApiKey;

    public function __construct() {
        // 從 config.php 載入的全域常數中取得金鑰
        $this->finnhubApiKey = defined('FINNHUB_API_KEY') ? FINNHUB_API_KEY : null;
    }

    /**
     * 取得股票或債券的現價
     * @param string $symbol 標的代碼 (例如 AAPL, 2330.TW, 0050.TW)
     * @return float|null 傳回價格，若抓取失敗則傳回 null
     */
    public function getPrice(string $symbol): ?float {
        $symbol = strtoupper(trim($symbol));
        if (empty($symbol)) return null;
    
        // 🟢 優化自動補全邏輯：
        // 如果代碼「以數字開頭」且「沒有點號」，視為台股標的
        if (preg_match('/^\d/', $symbol) && strpos($symbol, '.') === false) {
            $symbol .= '.TW'; 
        }
    
        // 分流判斷 (維持原狀，但現在 symbol 已經被標準化了)
        if (strpos($symbol, '.TW') !== false || strpos($symbol, '.TWO') !== false) {
            return $this->getTwPrice($symbol);
        } else {
            return $this->getUsPrice($symbol);
        }
    }

    /**
     * 獲取美股價格 (Finnhub API)
     */
    private function getUsPrice(string $symbol): ?float {
        if (!$this->finnhubApiKey) {
            error_log("StockService Error: FINNHUB_API_KEY is not defined.");
            return null;
        }

        $url = "https://finnhub.io/api/v1/quote?symbol={$symbol}&token={$this->finnhubApiKey}";
        $response = $this->fetchUrl($url);
        
        if (!$response) return null;

        $data = json_decode($response, true);
        
        // Finnhub 回傳的 'c' 是 Current Price
        if (isset($data['c']) && $data['c'] > 0) {
            return (float)$data['c'];
        }

        error_log("StockService: Failed to get US price for {$symbol}. Response: " . $response);
        return null;
    }

    /**
     * 獲取台股價格 (Yahoo Finance API)
     */
    private function getTwPrice(string $symbol): ?float {
        // Yahoo Finance v8 API
        $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$symbol}";
        
        // Yahoo 需要模擬瀏覽器 User-Agent 否則會擋
        $response = $this->fetchUrl($url, true);
        
        if (!$response) return null;

        $data = json_decode($response, true);
        
        try {
            if (isset($data['chart']['result'][0]['meta']['regularMarketPrice'])) {
                return (float)$data['chart']['result'][0]['meta']['regularMarketPrice'];
            }
        } catch (Exception $e) {
            error_log("StockService: Exception parsing TW price for {$symbol}: " . $e->getMessage());
        }

        error_log("StockService: Failed to get TW price for {$symbol}.");
        return null;
    }

    /**
     * 統一的 Curl 請求工具
     */
    private function fetchUrl(string $url, bool $useUserAgent = false) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        // 處理 SSL 憑證問題 (若在本地開發環境報錯可開啟，正式環境建議保持 true)
        // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        if ($useUserAgent) {
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
        }

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("StockService Curl Error: HTTP {$httpCode} for URL: {$url}");
            return null;
        }

        return $result;
    }
}