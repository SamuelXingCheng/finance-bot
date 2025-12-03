<?php
require_once __DIR__ . '/Database.php';

class TransactionService {
    private $pdo;

    // 定義所有有效的類別列表
    private const VALID_CATEGORIES = [
        'Food', 'Transport', 'Entertainment', 'Shopping', 'Bills', 
        'Investment', 'Medical', 'Education', 'Allowance', 'Salary', 
        'Miscellaneous'
    ];

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }

    private function sanitizeCategory(string $category): string {
        $normalizedCategory = ucfirst(strtolower(trim($category))); 
        if (in_array($normalizedCategory, self::VALID_CATEGORIES)) {
            return $normalizedCategory;
        }
        error_log("Worker: Invalid category '{$category}' returned by Gemini. Defaulting to Miscellaneous.");
        return 'Miscellaneous';
    }

    public function addTransaction(int $userId, array $data): bool {
        if (!isset($data['amount']) || $data['amount'] <= 0 || !in_array($data['type'], ['income', 'expense'])) {
            return false;
        }

        $cleanCategory = $this->sanitizeCategory($data['category'] ?? 'Miscellaneous');
        $transDate = $data['date'] ?? date('Y-m-d'); 
        $currency = $data['currency'] ?? 'TWD';
        $description = $data['description'] ?? '未分類';
        
        $sql = "INSERT INTO transactions (user_id, amount, category, description, type, transaction_date, currency, created_at) 
                VALUES (:userId, :amount, :category, :description, :type, :transDate, :currency, NOW())";

        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':userId'      => $userId,
                ':amount'      => (float)$data['amount'],
                ':category'    => $cleanCategory,
                ':description' => $description,
                ':type'        => $data['type'],
                ':transDate'   => $transDate, 
                ':currency'    => $currency
            ]);
        } catch (PDOException $e) {
            error_log("Database INSERT failed: " . $e->getMessage());
            return false;
        }
    }

    public function getTotalExpenseByMonth(int $userId): float {
        $startOfMonth = date('Y-m-01');
        $sql = "SELECT SUM(amount) FROM transactions WHERE user_id = :userId AND type = 'expense' AND transaction_date >= :startOfMonth";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':userId' => $userId, ':startOfMonth' => $startOfMonth]);
            return (float) ($stmt->fetchColumn() ?? 0);
        } catch (PDOException $e) {
            return 0.0;
        }
    }

    public function getTotalIncomeByMonth(int $userId): float {
        $startOfMonth = date('Y-m-01');
        $sql = "SELECT SUM(amount) FROM transactions WHERE user_id = :userId AND type = 'income' AND transaction_date >= :startOfMonth";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':userId' => $userId, ':startOfMonth' => $startOfMonth]);
            return (float) ($stmt->fetchColumn() ?? 0);
        } catch (PDOException $e) {
            return 0.0;
        }
    }

    /**
     * 🟢 既有方法 (給帳戶頁面用)：只分「收入」與「支出」兩條線
     */
    public function getTrendData(int $userId, string $startDate, string $endDate): array {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $end->modify('last day of this month'); 
        $interval = DateInterval::createFromDateString('1 month');
        $period = new DatePeriod($start, $interval, $end);

        $data = [];
        foreach ($period as $dt) {
            $data[$dt->format("Y-m")] = ['income' => 0, 'expense' => 0];
        }

        $sql = "SELECT DATE_FORMAT(transaction_date, '%Y-%m') as month, type, SUM(amount) as total 
                FROM transactions 
                WHERE user_id = :userId AND transaction_date BETWEEN :startDate AND :endDate
                GROUP BY month, type ORDER BY month ASC";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':userId' => $userId, ':startDate' => $startDate, ':endDate' => $endDate]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($results as $row) {
                if (isset($data[$row['month']])) {
                    $data[$row['month']][$row['type']] = (float)$row['total'];
                }
            }
            return $data; 
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * 🌟 新增方法 (給總覽頁面用)：依據「分類 (Category)」統計多條線
     */
    public function getCategoryTrendData(int $userId, string $startDate, string $endDate): array {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $end->modify('last day of this month'); 
        $interval = DateInterval::createFromDateString('1 month');
        $period = new DatePeriod($start, $interval, $end);

        // 初始化結構: ['2023-01' => [], '2023-02' => [] ...]
        $data = [];
        foreach ($period as $dt) {
            $data[$dt->format("Y-m")] = [];
        }

        // 資料庫查詢：改為依 month 和 category 分組
        $sql = "SELECT DATE_FORMAT(transaction_date, '%Y-%m') as month, category, SUM(amount) as total 
                FROM transactions 
                WHERE user_id = :userId AND transaction_date BETWEEN :startDate AND :endDate
                GROUP BY month, category ORDER BY month ASC";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':userId' => $userId, ':startDate' => $startDate, ':endDate' => $endDate]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($results as $row) {
                $m = $row['month'];
                $cat = $row['category'];
                if (isset($data[$m])) {
                    $data[$m][$cat] = (float)$row['total'];
                }
            }
            return $data; 
        } catch (PDOException $e) {
            error_log("getCategoryTrendData failed: " . $e->getMessage());
            return [];
        }
    }
    
    public function getMonthlyBreakdown(int $userId, string $type): array {
        // ... (保持原樣)
        $startOfMonth = date('Y-m-01');
        $sql = "SELECT category, SUM(amount) as total FROM transactions WHERE user_id = :userId AND type = :type AND transaction_date >= :startOfMonth GROUP BY category ORDER BY total DESC";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':userId' => $userId, ':type' => $type, ':startOfMonth' => $startOfMonth]);
            return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException $e) { return []; }
    }
}