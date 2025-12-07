<?php
// src/LedgerService.php
require_once __DIR__ . '/Database.php';

class LedgerService {
    private $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }

    public function createLedger(int $userId, string $name, string $type = 'shared'): int {
        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("INSERT INTO ledgers (name, type, owner_id, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$name, $type, $userId]);
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

        $stmt = $this->pdo->prepare("INSERT INTO ledger_members (ledger_id, user_id, role, joined_at) VALUES (?, ?, ?, NOW())");
        return $stmt->execute([$ledgerId, $userId, $role]);
    }

    public function checkAccess(int $userId, int $ledgerId): bool {
        $stmt = $this->pdo->prepare("SELECT 1 FROM ledger_members WHERE user_id = ? AND ledger_id = ?");
        $stmt->execute([$userId, $ledgerId]);
        return (bool)$stmt->fetchColumn();
    }
}
?>