<?php
require_once __DIR__ . '/Database.php';

class UserService {
    private $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * 檢查用戶是否存在，如果不存在則註冊新用戶。
     * @param string $lineUserId LINE 使用者的唯一 ID
     * @return int 內部資料庫的 user_id
     */
    public function findOrCreateUser(string $lineUserId): int {
        // 1. 嘗試尋找現有用戶
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE line_user_id = ?");
        $stmt->execute([$lineUserId]);
        $user = $stmt->fetch();

        if ($user) {
            return (int)$user['id']; 
        }

        // 2. 如果用戶不存在，則創建新用戶
        $stmt = $this->pdo->prepare("INSERT INTO users (line_user_id) VALUES (?)");
        $stmt->execute([$lineUserId]);
        
        return (int)$this->pdo->lastInsertId();
    }
}
?>