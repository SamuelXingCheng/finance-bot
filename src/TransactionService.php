<?php
require_once __DIR__ . '/Database.php';

class TransactionService {
    private $pdo;

    // 【新增】定義所有有效的類別列表
    private const VALID_CATEGORIES = [
        'Food', 'Transport', 'Entertainment', 'Shopping', 'Bills', 
        'Investment', 'Medical', 'Education', 'Allowance', 'Salary', 
        'Miscellaneous'
    ];

    public function __construct() {
        // 確保連線到資料庫
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * 檢查並清理分類名稱。如果不在有效列表中，則強制映射到 'Miscellaneous'。
     */
    private function sanitizeCategory(string $category): string {
        $normalizedCategory = ucfirst(strtolower(trim($category))); 
        
        // 如果 Category 包含在有效列表中 (例如 'food' 會被轉成 'Food' 匹配)
        if (in_array($normalizedCategory, self::VALID_CATEGORIES)) {
            return $normalizedCategory;
        }

        // 如果是無效的分類，記錄並使用 'Miscellaneous' 作為預設值
        error_log("Worker: Invalid category '{$category}' returned by Gemini. Defaulting to Miscellaneous.");
        return 'Miscellaneous';
    }

    /**
     * 將單筆交易寫入資料庫。
     */
    public function addTransaction(int $userId, array $data): bool {
        // 1. 驗證資料有效性
        if (!isset($data['amount']) || $data['amount'] <= 0 || !in_array($data['type'], ['income', 'expense'])) {
            error_log("Invalid transaction data for user $userId: " . json_encode($data, JSON_UNESCAPED_UNICODE));
            return false;
        }

        // 【修正點】：在寫入資料庫前，清理 Category
        $cleanCategory = $this->sanitizeCategory($data['category'] ?? 'Miscellaneous');

        // 2. 準備數據 (處理日期與貨幣的預設值)
        $transDate = $data['date'] ?? date('Y-m-d'); 
        $currency = $data['currency'] ?? 'TWD';
        $description = $data['description'] ?? '未分類';

        // 3. 準備 SQL 
        $sql = "INSERT INTO transactions (user_id, amount, category, description, type, date, currency, created_at) 
                VALUES (:userId, :amount, :category, :description, :type, :transDate, :currency, NOW())";

        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':userId'      => $userId,
                ':amount'      => $data['amount'],
                ':category'    => $cleanCategory, // 使用清理後的 Category
                ':description' => $description,
                ':type'        => $data['type'],
                ':transDate'   => $transDate, 
                ':currency'    => $currency
            ]);
        } catch (PDOException $e) {
            error_log("Database INSERT failed for user $userId: " . $e->getMessage());
            return false;
        }
    }

    // ... (getMonthlyBreakdown 方法不變) ...

    /**
     * 取得本月指定類型(收入/支出)的分類統計
     */
    public function getMonthlyBreakdown(int $userId, string $type): array {
        $startOfMonth = date('Y-m-01');
        
        $sql = "SELECT category, SUM(amount) as total 
                FROM transactions 
                WHERE user_id = :userId 
                  AND type = :type 
                  AND date >= :startOfMonth
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