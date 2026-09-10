<?php
/**
 * SavingsAccountHolderModel — ownership relationship for savings accounts.
 * Every account (individual, joint, or corporate) is owned exclusively
 * through this table, never a direct owner_member_id column on the
 * account itself.
 *
 * Enum values are explicitly whitelisted in PHP before every write:
 * this MariaDB instance's sql_mode does not include STRICT_TRANS_TABLES,
 * so an invalid ENUM value would otherwise silently coerce to '' rather
 * than error (confirmed empirically during Stage 1) — the DB ENUM is
 * not a reliable gate on its own here.
 */
class SavingsAccountHolderModel extends Model
{
    protected string $table      = 'savings_account_holders';
    protected string $primaryKey = 'id';

    public const ROLES = ['primary', 'joint', 'organization'];

    private MemberModel $memberModel;
    private OrganizationModel $organizationModel;

    public function __construct()
    {
        parent::__construct();
        $this->memberModel = new MemberModel();
        $this->organizationModel = new OrganizationModel();
    }

    /**
     * Validate and add a single holder row. Enforces, against the
     * account's own account_type (looked up fresh, never trusted from
     * the caller):
     *   - role is one of the whitelisted values
     *   - exactly one of member_id/organization_id supplied
     *   - the referenced member/organization actually exists
     *   - corporate accounts only ever receive an 'organization' holder
     *   - non-corporate accounts never receive an 'organization' holder
     *   - individual accounts never exceed one holder
     *   - joint accounts never receive more than one 'primary' holder
     *   - no duplicate holder (member or organization) on the same account
     */
    public function addHolder(int $accountId, ?int $memberId, ?int $organizationId, string $role): int
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException("Invalid holder role \"{$role}\".");
        }
        if (($memberId !== null) === ($organizationId !== null)) {
            throw new InvalidArgumentException('A holder must reference exactly one of member_id or organization_id.');
        }

        $accountStmt = $this->db->prepare("SELECT id, account_type FROM `member_savings_accounts` WHERE id = ?");
        $accountStmt->execute([$accountId]);
        $account = $accountStmt->fetch();
        if (!$account) {
            throw new InvalidArgumentException("Savings account id {$accountId} does not exist.");
        }

        if ($organizationId !== null) {
            if ($role !== 'organization') {
                throw new InvalidArgumentException('An organization holder must have role "organization".');
            }
            if ($account['account_type'] !== 'corporate') {
                throw new InvalidArgumentException('Only a corporate account may have an organization holder.');
            }
            if (!$this->organizationModel->getOrganization($organizationId)) {
                throw new InvalidArgumentException("Organization id {$organizationId} does not exist.");
            }
        }

        if ($memberId !== null) {
            if ($role === 'organization') {
                throw new InvalidArgumentException('A member holder cannot have role "organization".');
            }
            if ($account['account_type'] === 'corporate') {
                throw new InvalidArgumentException('A corporate account cannot have a member holder.');
            }
            if (!$this->memberModel->find($memberId)) {
                throw new InvalidArgumentException("Member id {$memberId} does not exist.");
            }
        }

        $existing = $this->getAccountHolders($accountId);

        if (in_array($account['account_type'], ['compulsory', 'voluntary'], true) && count($existing) >= 1) {
            throw new InvalidArgumentException('An individual (compulsory/voluntary) account may have exactly one holder.');
        }
        if ($role === 'primary' && count(array_filter($existing, fn($h) => $h['role'] === 'primary')) >= 1) {
            throw new InvalidArgumentException('This account already has a primary holder.');
        }
        foreach ($existing as $h) {
            if ($memberId !== null && (int)($h['member_id'] ?? 0) === $memberId) {
                throw new InvalidArgumentException('This member is already a holder of this account.');
            }
            if ($organizationId !== null && (int)($h['organization_id'] ?? 0) === $organizationId) {
                throw new InvalidArgumentException('This organization is already a holder of this account.');
            }
        }

        $stmt = $this->db->prepare(
            "INSERT INTO `savings_account_holders` (account_id, member_id, organization_id, role) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$accountId, $memberId, $organizationId, $role]);
        return (int)$this->db->lastInsertId();
    }

    public function removeHolder(int $holderId): bool
    {
        return $this->delete($holderId);
    }

    public function getAccountHolders(int $accountId): array
    {
        $stmt = $this->db->prepare(
            "SELECT h.*, m.member_number, m.first_name, m.last_name, o.name AS organization_name
             FROM `savings_account_holders` h
             LEFT JOIN `members` m ON m.id = h.member_id
             LEFT JOIN `organizations` o ON o.id = h.organization_id
             WHERE h.account_id = ?
             ORDER BY FIELD(h.role, 'primary', 'organization', 'joint'), h.added_at ASC"
        );
        $stmt->execute([$accountId]);
        return $stmt->fetchAll();
    }

    public function getMemberAccounts(int $memberId): array
    {
        $stmt = $this->db->prepare(
            "SELECT a.* FROM `member_savings_accounts` a
             JOIN `savings_account_holders` h ON h.account_id = a.id
             WHERE h.member_id = ?
             ORDER BY a.account_type, a.opened_date"
        );
        $stmt->execute([$memberId]);
        return $stmt->fetchAll();
    }

    /**
     * Members whose savings account_number matches $term (e.g. "SAV-001",
     * "CS-000012") — for search-as-you-type fields that should find a
     * member by their account number, not just their own name/member
     * number. Distinct by member, ordered by account_number.
     */
    public function searchMembersByAccountNumber(string $term, int $limit = 10): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT m.id, m.member_number, m.first_name, m.last_name, m.phone
            FROM `member_savings_accounts` sa
            JOIN `savings_account_holders` h ON h.account_id = sa.id
            JOIN `members` m ON m.id = h.member_id
            WHERE sa.account_number LIKE ? AND m.status = 'active'
            ORDER BY sa.account_number
            LIMIT ?
        ");
        $stmt->bindValue(1, '%' . $term . '%');
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getOrganizationAccount(int $organizationId): array|false
    {
        $stmt = $this->db->prepare(
            "SELECT a.* FROM `member_savings_accounts` a
             JOIN `savings_account_holders` h ON h.account_id = a.id
             WHERE h.organization_id = ? AND h.role = 'organization'
             LIMIT 1"
        );
        $stmt->execute([$organizationId]);
        return $stmt->fetch();
    }

    /**
     * Read-only summary used to decide whether an account's ownership is
     * currently well-formed per the invariants in the class docblock
     * (e.g. before treating a joint account as "finalized"). Does not
     * write anything.
     */
    public function validateAccountOwnership(int $accountId): array
    {
        $accountStmt = $this->db->prepare("SELECT account_type FROM `member_savings_accounts` WHERE id = ?");
        $accountStmt->execute([$accountId]);
        $account = $accountStmt->fetch();
        if (!$account) {
            throw new InvalidArgumentException("Savings account id {$accountId} does not exist.");
        }

        $holders = $this->getAccountHolders($accountId);
        $memberHolders = array_filter($holders, fn($h) => $h['member_id'] !== null);
        $orgHolders = array_filter($holders, fn($h) => $h['organization_id'] !== null);
        $primaryCount = count(array_filter($holders, fn($h) => $h['role'] === 'primary'));

        $valid = match ($account['account_type']) {
            'compulsory', 'voluntary' => count($memberHolders) === 1 && count($orgHolders) === 0 && $primaryCount === 1,
            'joint' => count($memberHolders) >= 2 && count($orgHolders) === 0 && $primaryCount <= 1,
            'corporate' => count($orgHolders) === 1 && count($memberHolders) === 0,
            default => false,
        };

        return [
            'account_type'   => $account['account_type'],
            'holder_count'   => count($holders),
            'member_holders' => count($memberHolders),
            'org_holders'    => count($orgHolders),
            'primary_count'  => $primaryCount,
            'valid'          => $valid,
        ];
    }
}
