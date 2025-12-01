<?php
require_once __DIR__ . '/Database.php';

class TransactionService {
    private $pdo;

    public function __construct() {
        // 確保連線到資料庫
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * 將單筆交易寫入資料庫。
     * @param int $userId 內部資料庫的 user_id
     * @param array $data 來自 Gemini 的交易資料 ({amount, category, description, type, date, currency})
     * @return bool 寫入是否成功
     */
    public function addTransaction(int $userId, array $data): bool {
        // 1. 驗證資料有效性
        if (!isset($data['amount']) || $data['amount'] <= 0 || !in_array($data['type'], ['income', 'expense'])) {
            error_log("Invalid transaction data for user $userId: " . json_encode($data, JSON_UNESCAPED_UNICODE));
            return false;
        }

        // 2. 準備數據 (處理日期與貨幣的預設值)
        // Gemini 解析出的日期格式為 YYYY-MM-DD
        // 如果 $data['date'] 為空，則使用當前日期
        $transDate = $data['date'] ?? date('Y-m-d'); 
        $currency = $data['currency'] ?? 'TWD';
        $description = $data['description'] ?? '未分類';

        // 3. 準備 SQL 
        // 【重要】：請確認您的資料表日期欄位名稱是 `date` 還是 `transaction_date`
        // 根據您之前的截圖，這裡假設是 `date`
        $sql = "INSERT INTO transactions (user_id, amount, category, description, type, date, currency, created_at) 
                VALUES (:userId, :amount, :category, :description, :type, :transDate, :currency, NOW())";

        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':userId'      => $userId,
                ':amount'      => $data['amount'],
                ':category'    => $data['category'],
                ':description' => $description,
                ':type'        => $data['type'],
                ':transDate'   => $transDate, // 寫入 AI 推斷的日期
                ':currency'    => $currency
            ]);
        } catch (PDOException $e) {
            error_log("Database INSERT failed for user $userId: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 【升級版】取得本月指定類型(收入/支出)的分類統計
     * @param int $userId
     * @param string $type 'income' 或 'expense'
     * @return array [ 'Food' => 1200, ... ]
     */
    public function getMonthlyBreakdown(int $userId, string $type): array {
        $startOfMonth = date('Y-m-01');
        
        // 使用 GROUP BY 將同類別的金額加總，並按金額由大到小排序
        // 同樣注意：這裡使用 `date` 欄位
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
            
            // FetchAll 取得所有列，格式為 [ ['category'=>'Food', 'total'=>100], ... ]
            // 我們可以用 PDO::FETCH_KEY_PAIR 直接轉成 [ 'Food' => 100 ] 的格式
            return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
        } catch (PDOException $e) {
            error_log("Query Breakdown ({$type}) failed: " . $e->getMessage());
            return [];
        }
    }
}
?>