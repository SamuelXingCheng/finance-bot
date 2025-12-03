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
            error_log("Invalid transaction data for user $userId: " . json_encode($data, JSON_UNESCAPED_UNICODE));
            return false;
        }

        $cleanCategory = $this->sanitizeCategory($data['category'] ?? 'Miscellaneous');
        $transDate = $data['date'] ?? date('Y-m-d'); 
        $currency = $data['currency'] ?? 'TWD';
        $description = $data['description'] ?? '未分類';
        
        // 【修正點】：欄位名稱統一為 transaction_date 和 currency
        $sql = "INSERT INTO transactions (user_id, amount, category, description, type, transaction_date, currency, created_at) 
                VALUES (:userId, :amount, :category, :description, :type, :transDate, :currency, NOW())";

        try {
            $stmt = $this->pdo->prepare($sql);
            $amountValue = (float)($data['amount'] ?? 0); 
            
            return $stmt->execute([
                ':userId'      => $userId,
                ':amount'      => $amountValue,
                ':category'    => $cleanCategory,
                ':description' => $description,
                ':type'        => $data['type'],
                ':transDate'   => $transDate, // 使用 transaction_date
                ':currency'    => $currency
            ]);
        } catch (PDOException $e) {
            // 由於診斷已完成，這裡可以安全地記錄錯誤並返回 false
            error_log("Database INSERT failed for user $userId: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 【修正】getTotalExpenseByMonth：使用 transaction_date 欄位
     */
    public function getTotalExpenseByMonth(int $userId): float {
        $startOfMonth = date('Y-m-01');
        
        $sql = "SELECT SUM(amount) FROM transactions 
                WHERE user_id = :userId 
                  AND type = 'expense' 
                  AND transaction_date >= :startOfMonth"; // 【修正點】：使用 transaction_date
        // ... (以下略) ...
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':userId' => $userId, ':startOfMonth' => $startOfMonth]);
            $result = $stmt->fetchColumn();
            return (float) ($result ?? 0);
        } catch (PDOException $e) {
            error_log("Query Total Expense failed: " . $e->getMessage());
            return 0.0;
        }
    }

    /**
     * 🌟 新增：取得本月總收入
     */
    public function getTotalIncomeByMonth(int $userId): float {
        $startOfMonth = date('Y-m-01');
        
        $sql = "SELECT SUM(amount) FROM transactions 
                WHERE user_id = :userId 
                  AND type = 'income' 
                  AND transaction_date >= :startOfMonth";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':userId' => $userId, ':startOfMonth' => $startOfMonth]);
            $result = $stmt->fetchColumn();
            return (float) ($result ?? 0);
        } catch (PDOException $e) {
            error_log("Query Total Income failed: " . $e->getMessage());
            return 0.0;
        }
    }

    /**
     * 🌟 升級版：獲取指定時間範圍內的收支趨勢
     */
    public function getTrendData(int $userId, string $startDate, string $endDate): array {
        // 1. 產生完整的月份列表 (確保即使該月沒資料，圖表也不會斷掉)
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        // 調整結束日期以包含當月
        $end->modify('last day of this month'); 
        
        $interval = DateInterval::createFromDateString('1 month');
        $period = new DatePeriod($start, $interval, $end);

        $data = [];
        foreach ($period as $dt) {
            $data[$dt->format("Y-m")] = ['income' => 0, 'expense' => 0];
        }

        // 2. 資料庫查詢
        $sql = "SELECT 
                    DATE_FORMAT(transaction_date, '%Y-%m') as month, 
                    type, 
                    SUM(amount) as total 
                FROM transactions 
                WHERE user_id = :userId 
                  AND transaction_date BETWEEN :startDate AND :endDate
                GROUP BY month, type
                ORDER BY month ASC";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':userId' => $userId, 
                ':startDate' => $startDate, 
                ':endDate' => $endDate
            ]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 3. 填入數據
            foreach ($results as $row) {
                $m = $row['month'];
                $type = $row['type'];
                // 確保月份在我們生成的範圍內才填入
                if (isset($data[$m])) {
                    $data[$m][$type] = (float)$row['total'];
                }
            }

            return $data; 
        } catch (PDOException $e) {
            error_log("TransactionService getTrendData failed: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 取得本月指定類型(收入/支出)的分類統計
     */
    public function getMonthlyBreakdown(int $userId, string $type): array {
        $startOfMonth = date('Y-m-01');
        
        $sql = "SELECT category, SUM(amount) as total 
                FROM transactions 
                WHERE user_id = :userId 
                  AND type = :type 
                  AND transaction_date >= :startOfMonth
                GROUP BY category
                ORDER BY total DESC";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':userId' => $userId, 
                ':type' => $type,
                ':startOfMonth' => $startOfMonth
            ]);
            
            return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
        } catch (PDOException $e) {
            error_log("Query Breakdown ({$type}) failed: " . $e->getMessage());
            return [];
        }
    }
}