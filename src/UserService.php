<?php
// src/UserService.php
require_once __DIR__ . '/Database.php';

class UserService {
    private $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * 檢查用戶是否存在，如果不存在則註冊新用戶。
     */
    public function findOrCreateUser(string $lineUserId): int {
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE line_user_id = ?");
        $stmt->execute([$lineUserId]);
        $user = $stmt->fetch();

        if ($user) {
            return (int)$user['id']; 
        }

        $stmt = $this->pdo->prepare("INSERT INTO users (line_user_id) VALUES (?)");
        $stmt->execute([$lineUserId]);
        
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * 🌟 新增：綁定 BMC Email (前端呼叫)
     */
    public function linkBmcEmail(int $userId, string $email): bool {
        // 先檢查此 Email 是否已被其他帳號綁定 (可選)
        // $check = $this->pdo->prepare("SELECT id FROM users WHERE bmc_email = ? AND id != ?");
        // ...

        $stmt = $this->pdo->prepare("UPDATE users SET bmc_email = ? WHERE id = ?");
        return $stmt->execute([$email, $userId]);
    }

    /**
     * 🌟 新增：透過 Email 查找用戶
     */
    public function getUserByBmcEmail(string $email): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE bmc_email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }

    /**
     * 🌟 新增：透過 Email 開通會員 (Webhook 呼叫)
     * @param string $email 付款人的 Email
     * @param int $days 增加的會員天數
     */
    public function activatePremiumByEmail(string $email, int $days = 30): bool {
        // 1. 先找到用戶
        $user = $this->getUserByBmcEmail($email);

        if (!$user) {
            error_log("BMC Webhook Error: User not found for email {$email}");
            return false;
        }

        // 2. 計算新的到期日 (如果還沒過期，就從舊日期往後加；若已過期，從現在算)
        $currentExpire = !empty($user['premium_expire_date']) ? strtotime($user['premium_expire_date']) : 0;
        $now = time();
        
        if ($currentExpire < $now) {
            $baseTime = $now; // 已過期，從現在開始算
        } else {
            $baseTime = $currentExpire; // 還沒過期，續期
        }
        
        $newExpire = date('Y-m-d H:i:s', strtotime("+{$days} days", $baseTime));

        // 3. 更新狀態
        $update = $this->pdo->prepare("UPDATE users SET is_premium = 1, premium_expire_date = ? WHERE id = ?");
        return $update->execute([$newExpire, $user['id']]);
    }
}
?>