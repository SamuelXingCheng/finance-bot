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
     * @param array $data 來自 Gemini 的交易資料 ({amount, category, description, type})
     * @return bool 寫入是否成功
     */
    public function addTransaction(int $userId, array $data): bool {
        // 確保金額為正數，並確保類型是 'income' 或 'expense'
        if ($data['amount'] <= 0 || !in_array($data['type'], ['income', 'expense'])) {
            error_log("Invalid transaction data for user $userId: " . json_encode($data));
            return false;
        }

        $sql = "INSERT INTO transactions (user_id, amount, category, description, type, date) 
                VALUES (?, ?, ?, ?, ?, NOW())";

        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                $userId,
                $data['amount'],
                $data['category'],
                $data['description'] ?? 'No description provided', // 使用預設值避免 NULL 錯誤
                $data['type']
            ]);
        } catch (PDOException $e) {
            error_log("Database INSERT failed for user $userId: " . $e->getMessage());
            return false;
        }
    }
}
?>