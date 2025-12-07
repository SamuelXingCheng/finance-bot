<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../src/AssetService.php';

class AssetServiceTest extends TestCase {
    private $pdo;
    private $service;

    protected function setUp(): void {
        // 1. 建立一個 "記憶體中" 的虛擬資料庫
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // 2. 建立測試所需的資料表結構 (簡化版，只要欄位對即可)
        $this->pdo->exec("CREATE TABLE accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            ledger_id INTEGER,
            name TEXT,
            type TEXT,
            balance REAL,
            currency_unit TEXT,
            last_updated_at DATETIME
        )");
        
        $this->pdo->exec("CREATE TABLE account_balance_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            ledger_id INTEGER,
            account_name TEXT,
            balance REAL,
            currency_unit TEXT,
            snapshot_date DATE
        )");

        // 3. 注入這個虛擬資料庫給 AssetService
        $this->service = new AssetService($this->pdo);
    }

    public function testSanitizeAssetType() {
        // 測試中文轉英文的邏輯是否正確
        $this->assertEquals('Stock', $this->service->sanitizeAssetType('股票'));
        $this->assertEquals('Liability', $this->service->sanitizeAssetType('房貸'));
        $this->assertEquals('Cash', $this->service->sanitizeAssetType('未知的東西')); // 預設值
    }

    public function testUpsertAccountBalance() {
        $userId = 1;
        $name = '測試錢包';
        $balance = 5000;
        
        // 1. 執行新增資產
        $result = $this->service->upsertAccountBalance($userId, $name, $balance, 'Cash', 'TWD');
        $this->assertTrue($result, '資產應該要儲存成功');

        // 2. 驗證是否真的寫入虛擬資料庫
        $stmt = $this->pdo->query("SELECT * FROM accounts WHERE name = '測試錢包'");
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertNotEmpty($account);
        $this->assertEquals(5000, $account['balance']);
        $this->assertEquals('Cash', $account['type']);
    }
}