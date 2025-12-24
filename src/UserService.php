<?php
// src/UserService.php
require_once __DIR__ . '/Database.php';

class UserService {
    private $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }

    public function findOrCreateUser(string $lineUserId): int {
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE line_user_id = ?");
        $stmt->execute([$lineUserId]);
        $user = $stmt->fetch();
        if ($user) return (int)$user['id'];
        $stmt = $this->pdo->prepare("INSERT INTO users (line_user_id) VALUES (?)");
        $stmt->execute([$lineUserId]);
        return (int)$this->pdo->lastInsertId();
    }

    // 🟢 [新增]Google 登入專用方法
    public function findOrCreateUserByGoogle(string $googleId, string $email): int {
        // 1. 嘗試透過 Google ID 查找
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE google_id = ?");
        $stmt->execute([$googleId]);
        $user = $stmt->fetch();
        
        if ($user) {
            return (int)$user['id'];
        }

        // 2. 如果沒找到，建立新用戶
        // 注意：這裡假設您的 DB 已經有 google_id 欄位
        $stmt = $this->pdo->prepare("INSERT INTO users (google_id, email, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$googleId, $email]);
        
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

    public function getUserStatus(int $userId): array {
        // 🟢 [修改] 多查 line_user_id
        $stmt = $this->pdo->prepare("SELECT is_onboarded, is_premium, monthly_budget, reminder_time, line_user_id FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $data = $result ?: ['is_onboarded' => 0, 'is_premium' => 0, 'monthly_budget' => 0, 'reminder_time' => '21:00'];
        
        // 轉換為布林值傳給前端
        $data['has_line_linked'] = !empty($data['line_user_id']);
        unset($data['line_user_id']); // 隱藏原始 ID
        
        return $data;
    }

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
        
        $fields[] = "is_onboarded = 1";

        if (empty($fields)) return false;

        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public function activateTrial(int $userId, int $days = 7): bool {
        if ($this->isPremium($userId)) {
            return true; 
        }

        $newExpire = date('Y-m-d H:i:s', strtotime("+{$days} days"));
        $stmt = $this->pdo->prepare("UPDATE users SET is_premium = 1, premium_expire_date = ? WHERE id = ?");
        return $stmt->execute([$newExpire, $userId]);
    }

    public function getActiveLedgerId(int $userId): ?int {
        $stmt = $this->pdo->prepare("SELECT active_ledger_id FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetchColumn();
        return $result ? (int)$result : null;
    }

    public function setActiveLedgerId(int $userId, int $ledgerId): bool {
        $stmt = $this->pdo->prepare("UPDATE users SET active_ledger_id = ? WHERE id = ?");
        return $stmt->execute([$ledgerId, $userId]);
    }
    // 🟢 [新增] 檢查 LINE ID 是否已被其他帳號使用 (排除自己)
    public function isLineIdTaken(string $lineUserId, int $currentUserId): bool {
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE line_user_id = ? AND id != ?");
        $stmt->execute([$lineUserId, $currentUserId]);
        return (bool)$stmt->fetch();
    }

    // 🟢 [新增] 將 LINE ID 綁定到指定用戶
    public function linkLineUser(int $userId, string $lineUserId): bool {
        $stmt = $this->pdo->prepare("UPDATE users SET line_user_id = ? WHERE id = ?");
        return $stmt->execute([$lineUserId, $userId]);
    }

    /**
     * 儲存或更新使用者的財務策略
     */
    public function saveStrategy($userId, $type, $data) {
        $sql = "INSERT INTO user_strategies 
                (user_id, type, start_date, initial_capital, monthly_invest_target, params)
                VALUES (:uid, :type, :start_date, :initial_capital, :monthly_invest, :params)
                ON DUPLICATE KEY UPDATE
                start_date = VALUES(start_date),
                initial_capital = VALUES(initial_capital),
                monthly_invest_target = VALUES(monthly_invest_target),
                params = VALUES(params)";
        
        // 🟢 [修正] 這裡是 $this->pdo，不是 $this->db
        $stmt = $this->pdo->prepare($sql);
        
        $paramsJson = is_array($data['params']) ? json_encode($data['params'], JSON_UNESCAPED_UNICODE) : $data['params'];

        return $stmt->execute([
            ':uid' => $userId,
            ':type' => $type,
            ':start_date' => $data['start_date'],
            ':initial_capital' => $data['initial_capital'],
            ':monthly_invest' => $data['monthly_invest_target'],
            ':params' => $paramsJson
        ]);
    }

    /**
     * 取得使用者的策略設定
     */
    public function getStrategy($userId, $type) {
        // 🟢 [修正] 這裡是 $this->pdo
        $stmt = $this->pdo->prepare("SELECT * FROM user_strategies WHERE user_id = :uid AND type = :type");
        $stmt->execute([':uid' => $userId, ':type' => $type]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row && !empty($row['params'])) {
            $row['params'] = json_decode($row['params'], true);
        }
        return $row;
    }

}
?>