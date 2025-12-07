<?php
// src/LedgerService.php
require_once __DIR__ . '/Database.php';

class LedgerService {
    private $pdo;

    public function __construct($pdo = null) {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    public function createLedger(int $userId, string $name, string $type = 'shared'): int {
        try {
            $this->pdo->beginTransaction();

            // [修正 1] 改用 PHP 產生時間，取代 SQL 的 NOW()
            $createdAt = date('Y-m-d H:i:s');

            $stmt = $this->pdo->prepare("INSERT INTO ledgers (name, type, owner_id, created_at) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $type, $userId, $createdAt]);
            $ledgerId = (int)$this->pdo->lastInsertId();

            $stmtMember = $this->pdo->prepare("INSERT INTO ledger_members (ledger_id, user_id, role) VALUES (?, ?, 'admin')");
            $stmtMember->execute([$ledgerId, $userId]);

            $this->pdo->commit();
            return $ledgerId;
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Create Ledger Failed: " . $e->getMessage());
            return 0;
        }
    }

    public function getUserLedgers(int $userId): array {
        $this->ensurePersonalLedgerExists($userId);

        $sql = "SELECT l.*, lm.role 
                FROM ledgers l 
                JOIN ledger_members lm ON l.id = lm.ledger_id 
                WHERE lm.user_id = ? 
                ORDER BY l.type ASC, l.created_at DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function ensurePersonalLedgerExists(int $userId): int {
        $stmt = $this->pdo->prepare("SELECT id FROM ledgers WHERE owner_id = ? AND type = 'personal' LIMIT 1");
        $stmt->execute([$userId]);
        $ledgerId = $stmt->fetchColumn();

        if ($ledgerId) {
            return (int)$ledgerId;
        }

        return $this->createLedger($userId, '個人帳本', 'personal');
    }

    public function joinLedger(int $userId, int $ledgerId, string $role = 'editor'): bool {
        $check = $this->pdo->prepare("SELECT 1 FROM ledger_members WHERE ledger_id = ? AND user_id = ?");
        $check->execute([$ledgerId, $userId]);
        if ($check->fetchColumn()) {
            return true;
        }

        // [修正 2] 改用 PHP 產生時間
        $joinedAt = date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare("INSERT INTO ledger_members (ledger_id, user_id, role, joined_at) VALUES (?, ?, ?, ?)");
        return $stmt->execute([$ledgerId, $userId, $role, $joinedAt]);
    }

    public function checkAccess(int $userId, int $ledgerId): bool {
        $stmt = $this->pdo->prepare("SELECT 1 FROM ledger_members WHERE user_id = ? AND ledger_id = ?");
        $stmt->execute([$userId, $ledgerId]);
        return (bool)$stmt->fetchColumn();
    }
    /**
     * 產生邀請 Token
     */
    public function createInvitation(int $inviterId, int $ledgerId): string {
        // 1. 確認邀請人是否有權限 (必須是該帳本成員)
        if (!$this->checkAccess($inviterId, $ledgerId)) {
            throw new Exception("您沒有權限邀請成員加入此帳本");
        }

        // 2. 產生亂數 Token
        $token = bin2hex(random_bytes(16)); // 32 chars
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours')); // 24小時後過期

        // 3. 寫入資料庫
        $sql = "INSERT INTO ledger_invitations (ledger_id, inviter_id, token, expires_at) VALUES (?, ?, ?, ?)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$ledgerId, $inviterId, $token, $expiresAt]);

        return $token;
    }

    /**
     * 處理邀請：驗證 Token 並將使用者加入帳本
     * 回傳：加入的帳本名稱
     */
    public function processInvitation(int $userId, string $token): string {
        // 1. 查詢 Token 是否有效
        $sql = "SELECT i.*, l.name as ledger_name 
                FROM ledger_invitations i
                JOIN ledgers l ON i.ledger_id = l.id
                WHERE i.token = ? AND i.status = 'pending' AND i.expires_at > NOW()";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$token]);
        $invite = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invite) {
            throw new Exception("邀請連結無效或已過期");
        }

        $ledgerId = $invite['ledger_id'];

        // 2. 檢查使用者是否已經在帳本內
        if ($this->checkAccess($userId, $ledgerId)) {
            return $invite['ledger_name']; // 已經在裡面了，直接回傳成功
        }

        try {
            $this->pdo->beginTransaction();

            // 3. 加入成員
            $this->joinLedger($userId, $ledgerId, 'editor');

            // 4. (可選) 標記 Token 為已使用 
            // 如果你希望一個連結可以多人使用，這行註解掉；如果是一次性連結，請保留。
            // $upd = $this->pdo->prepare("UPDATE ledger_invitations SET status = 'used' WHERE id = ?");
            // $upd->execute([$invite['id']]);

            $this->pdo->commit();
            return $invite['ledger_name'];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
?>