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
     * 綁定 BMC Email (前端呼叫)
     */
    public function linkBmcEmail(int $userId, string $email): bool {
        $stmt = $this->pdo->prepare("UPDATE users SET bmc_email = ? WHERE id = ?");
        return $stmt->execute([$email, $userId]);
    }

    /**
     * 透過 Email 查找用戶
     */
    public function getUserByBmcEmail(string $email): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE bmc_email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }

    /**
     * 透過 Email 開通會員 (Webhook 呼叫)
     */
    public function activatePremiumByEmail(string $email, int $days = 30): bool {
        $user = $this->getUserByBmcEmail($email);

        if (!$user) {
            error_log("BMC Webhook Error: User not found for email {$email}");
            return false;
        }

        $currentExpire = !empty($user['premium_expire_date']) ? strtotime($user['premium_expire_date']) : 0;
        $now = time();
        
        if ($currentExpire < $now) {
            $baseTime = $now; 
        } else {
            $baseTime = $currentExpire; 
        }
        
        $newExpire = date('Y-m-d H:i:s', strtotime("+{$days} days", $baseTime));

        $update = $this->pdo->prepare("UPDATE users SET is_premium = 1, premium_expire_date = ? WHERE id = ?");
        return $update->execute([$newExpire, $user['id']]);
    }

    /**
     * 🟢 新增：檢查用戶是否為有效的高級會員
     */
    public function isPremium(int $userId): bool {
        $stmt = $this->pdo->prepare("SELECT is_premium, premium_expire_date FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) return false;

        // 檢查標記是否為 1 且 日期未過期
        if ($user['is_premium'] == 1) {
            if (!empty($user['premium_expire_date'])) {
                return strtotime($user['premium_expire_date']) > time();
            }
            return false; // 有 is_premium 但沒日期，視為異常或過期
        }
        return false;
    }

    /**
     * 🟢 新增：檢查本日口語記帳使用次數 (查詢 gemini_tasks)
     */
    public function getDailyVoiceUsage(int $userId): int {
        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd = date('Y-m-d 23:59:59');
        
        $sql = "SELECT COUNT(*) FROM gemini_tasks 
                WHERE line_user_id = (SELECT line_user_id FROM users WHERE id = :uid) 
                AND created_at BETWEEN :start AND :end";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uid' => $userId, ':start' => $todayStart, ':end' => $todayEnd]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * 🟢 新增：檢查本月 AI 健檢使用次數 (查詢 api_usage_logs)
     */
    public function getMonthlyHealthCheckUsage(int $userId): int {
        $monthStart = date('Y-m-01 00:00:00');
        
        $sql = "SELECT COUNT(*) FROM api_usage_logs 
                WHERE user_id = :uid 
                AND action_type = 'health_check'
                AND created_at >= :start";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uid' => $userId, ':start' => $monthStart]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * 🟢 新增：記錄 API 使用量
     */
    public function logApiUsage(int $userId, string $actionType): bool {
        $stmt = $this->pdo->prepare("INSERT INTO api_usage_logs (user_id, action_type) VALUES (?, ?)");
        return $stmt->execute([$userId, $actionType]);
    }
}
?>