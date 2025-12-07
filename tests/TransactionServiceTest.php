<?php
use PHPUnit\Framework\TestCase;
// 引入需要測試的檔案
require_once __DIR__ . '/../src/TransactionService.php';
require_once __DIR__ . '/../src/LedgerService.php';

class TransactionServiceTest extends TestCase {
    private $pdo;
    private $service;

    protected function setUp(): void {
        // 1. 使用 SQLite 記憶體資料庫模擬 MySQL
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // 2. 建立必要的資料表 (transactions, ledgers, ledger_members)
        // 注意：為了讓 TransactionService 運作，我們需要模擬關聯表
        $this->pdo->exec("CREATE TABLE transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            ledger_id INTEGER,
            amount REAL,
            category TEXT,
            description TEXT,
            type TEXT,
            transaction_date DATE,
            currency TEXT,
            created_at DATETIME
        )");

        $this->pdo->exec("CREATE TABLE ledgers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            type TEXT,
            owner_id INTEGER,
            created_at DATETIME
        )");

        $this->pdo->exec("CREATE TABLE ledger_members (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ledger_id INTEGER,
            user_id INTEGER,
            role TEXT,
            joined_at DATETIME
        )");

        // 3. 預先建立一個個人帳本 (因為 addTransaction 會檢查帳本權限)
        // 這裡我們手動塞入資料，繞過 LedgerService 的邏輯，單純測試 TransactionService
        $this->pdo->exec("INSERT INTO ledgers (id, name, type, owner_id) VALUES (1, '個人帳本', 'personal', 1)");
        $this->pdo->exec("INSERT INTO ledger_members (ledger_id, user_id, role) VALUES (1, 1, 'admin')");

        // 4. 注入 Mock 的 PDO
        $this->service = new TransactionService($this->pdo);
    }

    public function testAddTransaction() {
        $data = [
            'amount' => 100,
            'type' => 'expense',
            'category' => 'Food',
            'description' => '午餐',
            'date' => date('Y-m-d'),
            'ledger_id' => 1 // 指定帳本 ID
        ];

        // 測試新增成功
        $result = $this->service->addTransaction(1, $data);
        $this->assertTrue($result, '應該要成功新增一筆支出');

        // 驗證資料庫
        $stmt = $this->pdo->query("SELECT * FROM transactions WHERE description = '午餐'");
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals(100, $tx['amount']);
        $this->assertEquals('Food', $tx['category']);
    }

    public function testGetTotalExpenseByMonth() {
        // 準備測試數據：插入兩筆支出，一筆收入
        // 1. 本月支出 500
        $this->service->addTransaction(1, ['amount' => 500, 'type' => 'expense', 'date' => date('Y-m-d'), 'ledger_id' => 1]);
        // 2. 本月支出 300
        $this->service->addTransaction(1, ['amount' => 300, 'type' => 'expense', 'date' => date('Y-m-d'), 'ledger_id' => 1]);
        // 3. 本月收入 1000 (不應計入支出)
        $this->service->addTransaction(1, ['amount' => 1000, 'type' => 'income', 'date' => date('Y-m-d'), 'ledger_id' => 1]);
        
        // 執行測試
        $total = $this->service->getTotalExpenseByMonth(1, 1); // userId=1, ledgerId=1
        
        // 驗證 (500 + 300 應該等於 800)
        $this->assertEquals(800, $total);
    }
}