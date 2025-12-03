<?php
// src/TransactionService.php
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

    public function getMonthlyBreakdown(int $userId, string $type): array {
        $startOfMonth = date('Y-m-01');
        $sql = "SELECT category, SUM(amount) as total FROM transactions WHERE user_id = :userId AND type = :type AND transaction_date >= :startOfMonth GROUP BY category ORDER BY total DESC";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':userId' => $userId, ':type' => $type, ':startOfMonth' => $startOfMonth]);
            return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException $e) { return []; }
    }

    /**
     * 🟢 舊方法：給「帳戶管理頁面」使用 (只分收入/支出)
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
     * 🌟 新方法：給「總覽頁面」使用 (依分類統計)
     */
    public function getCategoryTrendData(int $userId, string $startDate, string $endDate): array {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $end->modify('last day of this month'); 
        $interval = DateInterval::createFromDateString('1 month');
        $period = new DatePeriod($start, $interval, $end);

        $data = [];
        foreach ($period as $dt) {
            $data[$dt->format("Y-m")] = [];
        }

        $sql = "SELECT DATE_FORMAT(transaction_date, '%Y-%m') as month, category, SUM(amount) as total 
                FROM transactions 
                WHERE user_id = :userId AND transaction_date BETWEEN :startDate AND :endDate
                GROUP BY DATE_FORMAT(transaction_date, '%Y-%m'), category 
                ORDER BY month ASC";

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
            error_log("getCategoryTrendData Error: " . $e->getMessage());
            return [];
        }
    }

    // ================================================================
    // 🌟 新增的三個方法：取得列表、更新、刪除
    // ================================================================

    /**
     * 取得交易列表 (預設抓本月，可指定月份)
     */
    public function getTransactions(int $userId, string $month = null): array {
        $targetMonth = $month ?? date('Y-m');
        $sql = "SELECT * FROM transactions 
                WHERE user_id = :userId 
                  AND DATE_FORMAT(transaction_date, '%Y-%m') = :month
                ORDER BY transaction_date DESC, created_at DESC";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':userId' => $userId, ':month' => $targetMonth]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("getTransactions failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 更新交易
     */
    public function updateTransaction(int $userId, int $id, array $data): bool {
        $cleanCategory = $this->sanitizeCategory($data['category'] ?? 'Miscellaneous');
        
        $sql = "UPDATE transactions 
                SET amount = :amount, 
                    category = :category, 
                    description = :description, 
                    type = :type, 
                    transaction_date = :transDate,
                    currency = :currency
                WHERE id = :id AND user_id = :userId";
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':id' => $id,
                ':userId' => $userId,
                ':amount' => (float)$data['amount'],
                ':category' => $cleanCategory,
                ':description' => $data['description'],
                ':type' => $data['type'],
                ':transDate' => $data['date'],
                ':currency' => $data['currency'] ?? 'TWD'
            ]);
        } catch (PDOException $e) {
            error_log("updateTransaction failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 刪除交易
     */
    public function deleteTransaction(int $userId, int $id): bool {
        $sql = "DELETE FROM transactions WHERE id = :id AND user_id = :userId";
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([':id' => $id, ':userId' => $userId]);
        } catch (PDOException $e) {
            error_log("deleteTransaction failed: " . $e->getMessage());
            return false;
        }
    }
}
?>