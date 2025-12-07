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
        $normalizedCategory = ucfirst(strtolower(trim($category))); 
        if (in_array($normalizedCategory, self::VALID_CATEGORIES)) {
            return $normalizedCategory;
        }
        return 'Miscellaneous';
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
}
?>