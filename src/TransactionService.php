<?php
// src/TransactionService.php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/LedgerService.php';

class TransactionService {
    private $pdo;
    private $ledgerService;
    private const VALID_CATEGORIES = [
        'Food', 'Transport', 'Entertainment', 'Shopping', 'Bills', 
        'Investment', 'Medical', 'Education', 'Allowance', 'Salary', 
        'Bonus', 'Miscellaneous'
    ];

    public function __construct($pdo = null, $ledgerService = null) {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        $this->ledgerService = $ledgerService ?? new LedgerService($this->pdo);
    }

    private function sanitizeCategory(string $category): string {
        // 去除前後空白
        $cleanCategory = trim($category);
        
        // 如果使用者沒填，預設給 Miscellaneous；否則直接回傳使用者輸入的文字
        return !empty($cleanCategory) ? $cleanCategory : 'Miscellaneous';
    }

    // [輔助方法] 根據資料庫類型取得日期格式化 SQL (解決 SQLite 不支援 DATE_FORMAT 的問題)
    private function getMonthSql(string $column): string {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            return "strftime('%Y-%m', $column)"; // SQLite 語法
        }
        return "DATE_FORMAT($column, '%Y-%m')"; // MySQL 語法
    }

    public function addTransaction(int $userId, array $data): bool {
        if (!isset($data['amount']) || $data['amount'] <= 0 || !in_array($data['type'], ['income', 'expense'])) {
            return false;
        }

        $ledgerId = 0;

        if (isset($data['ledger_id']) && !empty($data['ledger_id'])) {
            $ledgerId = (int)$data['ledger_id'];
            if (!$this->ledgerService->checkAccess($userId, $ledgerId)) { 
                return false; 
            }
        } else {
            $ledgerId = $this->ledgerService->ensurePersonalLedgerExists($userId);
        }

        $cleanCategory = $this->sanitizeCategory($data['category'] ?? 'Miscellaneous');
        $transDate = $data['date'] ?? date('Y-m-d'); 
        $currency = $data['currency'] ?? 'TWD';
        $description = $data['description'] ?? '未分類';
        
        // [修正 1] 使用 PHP 產生時間，替換 NOW()
        $createdAt = date('Y-m-d H:i:s');

        $sql = "INSERT INTO transactions (user_id, ledger_id, amount, category, description, type, transaction_date, currency, created_at) 
                VALUES (:userId, :ledgerId, :amount, :category, :description, :type, :transDate, :currency, :createdAt)";

        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':userId'      => $userId,
                ':ledgerId'    => $ledgerId,
                ':amount'      => (float)$data['amount'],
                ':category'    => $cleanCategory,
                ':description' => $description,
                ':type'        => $data['type'],
                ':transDate'   => $transDate, 
                ':currency'    => $currency,
                ':createdAt'   => $createdAt
            ]);
        } catch (PDOException $e) {
            error_log("Database INSERT failed: " . $e->getMessage());
            return false;
        }
    }

    public function getTotalExpenseByMonth(int $userId, ?int $ledgerId = null): float {
        $startOfMonth = date('Y-m-01');
        $params = [':startOfMonth' => $startOfMonth];
        
        $sql = "SELECT SUM(amount) FROM transactions WHERE type = 'expense' AND transaction_date >= :startOfMonth";
        
        if ($ledgerId) {
            $sql .= " AND ledger_id = :ledgerId";
            $params[':ledgerId'] = $ledgerId;
        } else {
            $sql .= " AND user_id = :userId";
            $params[':userId'] = $userId;
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (float) ($stmt->fetchColumn() ?? 0);
        } catch (PDOException $e) { return 0.0; }
    }

    public function getTotalIncomeByMonth(int $userId, ?int $ledgerId = null): float {
        $startOfMonth = date('Y-m-01');
        $params = [':startOfMonth' => $startOfMonth];

        $sql = "SELECT SUM(amount) FROM transactions WHERE type = 'income' AND transaction_date >= :startOfMonth";
        
        if ($ledgerId) {
            $sql .= " AND ledger_id = :ledgerId";
            $params[':ledgerId'] = $ledgerId;
        } else {
            $sql .= " AND user_id = :userId";
            $params[':userId'] = $userId;
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (float) ($stmt->fetchColumn() ?? 0);
        } catch (PDOException $e) { return 0.0; }
    }

    public function getMonthlyBreakdown(int $userId, string $type, ?int $ledgerId = null): array {
        $startOfMonth = date('Y-m-01');
        $params = [':type' => $type, ':startOfMonth' => $startOfMonth];

        $sql = "SELECT category, SUM(amount) as total FROM transactions WHERE type = :type AND transaction_date >= :startOfMonth";
        
        if ($ledgerId) {
            $sql .= " AND ledger_id = :ledgerId";
            $params[':ledgerId'] = $ledgerId;
        } else {
            $sql .= " AND user_id = :userId";
            $params[':userId'] = $userId;
        }
        
        $sql .= " GROUP BY category ORDER BY total DESC";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException $e) { return []; }
    }

    public function getTrendData(int $userId, string $startDate, string $endDate, ?int $ledgerId = null): array {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $end->modify('last day of this month'); 
        $interval = DateInterval::createFromDateString('1 month');
        $period = new DatePeriod($start, $interval, $end);

        $data = [];
        foreach ($period as $dt) {
            $data[$dt->format("Y-m")] = ['income' => 0, 'expense' => 0];
        }

        $params = [':startDate' => $startDate, ':endDate' => $endDate];
        
        // [修正 2] 動態產生 SQL 日期格式語法
        $monthExpr = $this->getMonthSql('transaction_date');

        $sql = "SELECT $monthExpr as month, type, SUM(amount) as total 
                FROM transactions 
                WHERE transaction_date BETWEEN :startDate AND :endDate";

        if ($ledgerId) {
            $sql .= " AND ledger_id = :ledgerId";
            $params[':ledgerId'] = $ledgerId;
        } else {
            $sql .= " AND user_id = :userId";
            $params[':userId'] = $userId;
        }

        $sql .= " GROUP BY month, type ORDER BY month ASC";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($results as $row) {
                if (isset($data[$row['month']])) {
                    $data[$row['month']][$row['type']] = (float)$row['total'];
                }
            }
            return $data; 
        } catch (PDOException $e) { return []; }
    }

    public function getCategoryTrendData(int $userId, string $startDate, string $endDate, ?int $ledgerId = null): array {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $end->modify('last day of this month'); 
        $interval = DateInterval::createFromDateString('1 month');
        $period = new DatePeriod($start, $interval, $end);

        $data = [];
        foreach ($period as $dt) {
            $data[$dt->format("Y-m")] = [];
        }

        $params = [':startDate' => $startDate, ':endDate' => $endDate];
        
        // [修正 3] 動態產生 SQL 日期格式語法
        $monthExpr = $this->getMonthSql('transaction_date');

        $sql = "SELECT $monthExpr as month, category, SUM(amount) as total 
                FROM transactions 
                WHERE transaction_date BETWEEN :startDate AND :endDate";

        if ($ledgerId) {
            $sql .= " AND ledger_id = :ledgerId";
            $params[':ledgerId'] = $ledgerId;
        } else {
            $sql .= " AND user_id = :userId";
            $params[':userId'] = $userId;
        }

        $sql .= " GROUP BY month, category ORDER BY month ASC";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($results as $row) {
                $m = $row['month'];
                $cat = $row['category'];
                if (isset($data[$m])) {
                    $data[$m][$cat] = (float)$row['total'];
                }
            }
            return $data; 
        } catch (PDOException $e) { return []; }
    }

    public function getTransactions(int $userId, string $month = null, ?int $ledgerId = null): array {
        $targetMonth = $month ?? date('Y-m');
        $params = [':month' => $targetMonth];
        
        // [修正 4] 動態產生 SQL 日期格式語法
        $monthExpr = $this->getMonthSql('transaction_date');

        $sql = "SELECT * FROM transactions WHERE $monthExpr = :month";

        if ($ledgerId) {
            $sql .= " AND ledger_id = :ledgerId";
            $params[':ledgerId'] = $ledgerId;
        } else {
            $sql .= " AND user_id = :userId";
            $params[':userId'] = $userId;
        }

        $sql .= " ORDER BY transaction_date DESC, created_at DESC";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { return []; }
    }
    /**
     * [新增] 新增訂閱規則
     */
    public function addRecurringRule(int $userId, array $data): bool {
        // 簡單驗證
        if (empty($data['amount']) || empty($data['next_date'])) return false;

        $sql = "INSERT INTO recurring_rules 
                (user_id, ledger_id, type, amount, currency, category, description, frequency_type, next_run_date)
                VALUES (:uid, :lid, :type, :amount, :curr, :cat, :desc, :freq, :next)";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':uid' => $userId,
                ':lid' => $data['ledger_id'] ?? null,
                ':type' => $data['type'] ?? 'expense',
                ':amount' => (float)$data['amount'],
                ':curr' => $data['currency'] ?? 'TWD',
                ':cat' => $this->sanitizeCategory($data['category'] ?? 'Miscellaneous'),
                ':desc' => $data['description'] ?? '訂閱服務',
                ':freq' => $data['frequency'] ?? 'monthly',
                ':next' => $data['next_date']
            ]);
        } catch (PDOException $e) {
            error_log("Add Recurring Error: " . $e->getMessage());
            return false; 
        }
    }

    /**
     * [新增] 刪除訂閱規則
     */
    public function deleteRecurringRule(int $userId, int $ruleId): bool {
        $sql = "DELETE FROM recurring_rules WHERE id = :id AND user_id = :uid";
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([':id' => $ruleId, ':uid' => $userId]);
        } catch (PDOException $e) { return false; }
    }

    /**
     * [新增] 取得用戶的訂閱規則列表
     */
    public function getRecurringRules(int $userId, ?int $ledgerId = null): array {
        $sql = "SELECT * FROM recurring_rules WHERE user_id = :uid";
        $params = [':uid' => $userId];
        
        if ($ledgerId) {
            $sql .= " AND ledger_id = :lid";
            $params[':lid'] = $ledgerId;
        }
        
        $sql .= " ORDER BY is_active DESC, next_run_date ASC";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { return []; }
    }

    /**
     * [新增] 核心：檢查並執行到期的訂閱
     * (這個方法會由前端在背景呼叫，或者由系統 Cron 觸發)
     */
    public function processRecurring(int $userId): int {
        $today = date('Y-m-d');
        
        // 1. 找出所有「啟用中」且「執行日期 <= 今天」的規則
        $sql = "SELECT * FROM recurring_rules WHERE user_id = ? AND next_run_date <= ? AND is_active = 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId, $today]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $count = 0;
        foreach ($rules as $rule) {
            // A. 執行記帳 (呼叫現有的 addTransaction)
            $success = $this->addTransaction($userId, [
                'amount' => $rule['amount'],
                'type' => $rule['type'],
                'category' => $rule['category'],
                'description' => $rule['description'] . ' (自動扣款)',
                'date' => $rule['next_run_date'], // 補帳日期為原定扣款日
                'currency' => $rule['currency'],
                'ledger_id' => $rule['ledger_id']
            ]);

            if ($success) {
                // B. 計算下一次執行日期
                $nextDate = $rule['next_run_date'];
                switch ($rule['frequency_type']) {
                    case 'weekly':
                        $nextDate = date('Y-m-d', strtotime('+1 week', strtotime($nextDate)));
                        break;
                    case 'yearly':
                        $nextDate = date('Y-m-d', strtotime('+1 year', strtotime($nextDate)));
                        break;
                    case 'monthly':
                    default:
                        $nextDate = date('Y-m-d', strtotime('+1 month', strtotime($nextDate)));
                        break;
                }

                // C. 更新規則的下一次執行時間
                $upd = $this->pdo->prepare("UPDATE recurring_rules SET next_run_date = ? WHERE id = ?");
                $upd->execute([$nextDate, $rule['id']]);
                $count++;
            }
        }
        return $count;
    }

    public function updateTransaction(int $userId, int $id, array $data): bool {
        $cleanCategory = $this->sanitizeCategory($data['category'] ?? 'Miscellaneous');
        $sql = "UPDATE transactions 
                SET amount = :amount, category = :category, description = :description, type = :type, transaction_date = :transDate, currency = :currency
                WHERE id = :id AND user_id = :userId";
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':id' => $id, ':userId' => $userId, ':amount' => (float)$data['amount'],
                ':category' => $cleanCategory, ':description' => $data['description'],
                ':type' => $data['type'], ':transDate' => $data['date'], ':currency' => $data['currency'] ?? 'TWD'
            ]);
        } catch (PDOException $e) { return false; }
    }

    public function deleteTransaction(int $userId, int $id): bool {
        $sql = "DELETE FROM transactions WHERE id = :id AND user_id = :userId";
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([':id' => $id, ':userId' => $userId]);
        } catch (PDOException $e) { return false; }
    }

    /**
     * 計算某個日期之後，特定分類(投資相關)的支出總和
     * 修正版：根據截圖資料表結構，移除不存在的欄位，僅透過 category 判斷
     */
    public function getInvestmentSumSince($userId, $startDate) {
        // [Debug]
        error_log("🔍 [Debug] getInvestmentSumSince Start");
        error_log("   User ID: " . $userId);
        error_log("   Start Date: " . $startDate);

        // SQL 邏輯：
        // 1. 必須是該使用者的 (user_id)
        // 2. 日期在策略開始之後 (transaction_date)
        // 3. 類型必須是支出 (expense) 或是 轉帳 (transfer) 
        //    (有些記帳習慣會把投資記成轉帳，所以我們兩個都抓，重點看 category)
        // 4. 分類名稱必須包含「投資、存股、股票、證券」等關鍵字
        
        $sql = "SELECT SUM(amount) as total 
                FROM transactions 
                WHERE user_id = :uid 
                  AND transaction_date >= :date
                  AND (type = 'expense' OR type = 'transfer') 
                  AND (
                      category LIKE '%投資%' OR 
                      category LIKE '%存股%' OR 
                      category LIKE '%證券%' OR 
                      category LIKE '%基金%' OR
                      LOWER(category) LIKE '%investment%' OR 
                      LOWER(category) LIKE '%stock%' OR 
                      LOWER(category) LIKE '%fund%' OR
                      LOWER(category) LIKE '%etf%'
                  )";
                  
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':uid' => $userId, 
                ':date' => $startDate
            ]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $total = (float) ($result['total'] ?? 0);

            error_log("   Returning Total: " . $total);

            return $total;
        } catch (PDOException $e) {
            error_log("❌ Get Investment Sum Error: " . $e->getMessage());
            return 0.0;
        }
    }
}
?>