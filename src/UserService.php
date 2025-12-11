<?php
// src/UserService.php
require_once __DIR__ . '/Database.php';

class UserService {
    private $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }

    // ... (保留原有的 findOrCreateUser, linkBmcEmail, getUserByBmcEmail, activatePremiumByEmail) ...
    public function findOrCreateUser(string $lineUserId): int {
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE line_user_id = ?");
        $stmt->execute([$lineUserId]);
        $user = $stmt->fetch();
        if ($user) return (int)$user['id'];
        $stmt = $this->pdo->prepare("INSERT INTO users (line_user_id) VALUES (?)");
        $stmt->execute([$lineUserId]);
        return (int)$this->pdo->lastInsertId();
    }

    public function linkBmcEmail(int $userId, string $email): bool {
        $stmt = $this->pdo->prepare("UPDATE users SET bmc_email = ? WHERE id = ?");
        return $stmt->execute([$email, $userId]);
    }

    public function getUserByBmcEmail(string $email): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE bmc_email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }

    public function activatePremiumByEmail(string $email, int $days = 30): bool {
        $user = $this->getUserByBmcEmail($email);
        if (!$user) {
            error_log("BMC Webhook Error: User not found for email {$email}");
            return false;
        }
        $currentExpire = !empty($user['premium_expire_date']) ? strtotime($user['premium_expire_date']) : 0;
        $now = time();
        $baseTime = ($currentExpire < $now) ? $now : $currentExpire;
        $newExpire = date('Y-m-d H:i:s', strtotime("+{$days} days", $baseTime));
        $update = $this->pdo->prepare("UPDATE users SET is_premium = 1, premium_expire_date = ? WHERE id = ?");
        return $update->execute([$newExpire, $user['id']]);
    }

    public function isPremium(int $userId): bool {
        $stmt = $this->pdo->prepare("SELECT is_premium, premium_expire_date FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) return false;
        if ($user['is_premium'] == 1) {
            if (!empty($user['premium_expire_date'])) {
                return strtotime($user['premium_expire_date']) > time();
            }
            return false;
        }
        return false;
    }

    public function getDailyVoiceUsage(int $userId): int {
        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd = date('Y-m-d 23:59:59');
        $sql = "SELECT COUNT(*) FROM gemini_tasks WHERE line_user_id = (SELECT line_user_id FROM users WHERE id = :uid) AND created_at BETWEEN :start AND :end";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uid' => $userId, ':start' => $todayStart, ':end' => $todayEnd]);
        return (int)$stmt->fetchColumn();
    }

    public function getMonthlyHealthCheckUsage(int $userId): int {
        $monthStart = date('Y-m-01 00:00:00');
        $sql = "SELECT COUNT(*) FROM api_usage_logs WHERE user_id = :uid AND action_type = 'health_check' AND created_at >= :start";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uid' => $userId, ':start' => $monthStart]);
        return (int)$stmt->fetchColumn();
    }

    public function logApiUsage(int $userId, string $actionType): bool {
        $stmt = $this->pdo->prepare("INSERT INTO api_usage_logs (user_id, action_type) VALUES (?, ?)");
        return $stmt->execute([$userId, $actionType]);
    }

    // ==========================================
    // 🟢 新增：Onboarding 引導流程相關方法
    // ==========================================

    /**
     * 獲取用戶目前的狀態 (用於前端判斷是否顯示引導)
     */
    public function getUserStatus(int $userId): array {
        // 🟢 [修改] 增加查詢 monthly_budget
        $stmt = $this->pdo->prepare("SELECT is_onboarded, is_premium, monthly_budget FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 設定預設值
        return $result ?: ['is_onboarded' => 0, 'is_premium' => 0, 'monthly_budget' => 0];
    }

    /**
     * 更新用戶的設定檔 (引導流程用)
     */
    public function updateUserProfile(int $userId, array $data): bool {
        $fields = [];
        $params = [':id' => $userId];

        if (isset($data['financial_goal'])) {
            $fields[] = "financial_goal = :goal";
            $params[':goal'] = $data['financial_goal'];
        }
        if (isset($data['monthly_budget'])) {
            $fields[] = "monthly_budget = :budget";
            $params[':budget'] = (float)$data['monthly_budget'];
        }
        if (isset($data['reminder_time'])) {
            $fields[] = "reminder_time = :time";
            $params[':time'] = $data['reminder_time'];
        }
        
        // 標記已完成引導
        $fields[] = "is_onboarded = 1";

        if (empty($fields)) return false; // 沒有要更新的欄位

        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * 直接給予試用天數 (用於引導完成獎勵)
     */
    public function activateTrial(int $userId, int $days = 7): bool {
        // 檢查是否已經是付費會員，避免覆蓋原本的長約
        if ($this->isPremium($userId)) {
            return true; 
        }

        $newExpire = date('Y-m-d H:i:s', strtotime("+{$days} days"));
        $stmt = $this->pdo->prepare("UPDATE users SET is_premium = 1, premium_expire_date = ? WHERE id = ?");
        return $stmt->execute([$newExpire, $userId]);
    }

    // [新增] 獲取用戶當前鎖定的帳本 ID
    public function getActiveLedgerId(int $userId): ?int {
        $stmt = $this->pdo->prepare("SELECT active_ledger_id FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetchColumn();
        return $result ? (int)$result : null;
    }

    // [新增] 設定用戶當前鎖定的帳本
    public function setActiveLedgerId(int $userId, int $ledgerId): bool {
        $stmt = $this->pdo->prepare("UPDATE users SET active_ledger_id = ? WHERE id = ?");
        return $stmt->execute([$ledgerId, $userId]);
    }
    
}
?>