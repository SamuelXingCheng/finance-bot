<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../src/ExchangeRateService.php';

class ExchangeRateServiceTest extends TestCase {
    
    public function testUsdToTwdRateIsConstant() {
        $service = new ExchangeRateService();
        // 驗證常數匯率是否為 32.0 (根據你的代碼設定)
        $this->assertEquals(32.0, $service->getUsdTwdRate());
    }

    public function testGetRateToUSD() {
        $service = new ExchangeRateService();

        // 1. 測試 USD 本身 (應該是 1.0)
        $this->assertEquals(1.0, $service->getRateToUSD('USD'));

        // 2. 測試 TWD (應該是 1 / 32)
        // 使用 assertEqualsWithDelta 來比較浮點數，允許微小誤差
        $expected = 1.0 / 32.0;
        $this->assertEqualsWithDelta($expected, $service->getRateToUSD('TWD'), 0.0001);
    }
}