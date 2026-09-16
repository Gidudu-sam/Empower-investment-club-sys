<?php
/**
 * Approval Service — V2.1 Multi-Level Approval Engine
 * 
 * Single authoritative approval system for all transactions requiring multi-level
 * approval. Enforces locked approval matrix, officer loan governance, maker-checker
 * separation, and immutable audit trails.
 * 
 * Stage 3 — ApprovalService Implementation
 * Date: September 14, 2026
 */

class ApprovalService
{
    private PDO $db;
    
    /**
     * Locked approval tier boundaries (Stage 2.6 governance)
     */
    private const TIER_BOUNDARIES = [
        1 => ['min' => 0, 'max' => 999999.99],
        2 => ['min' => 1000000, 'max' => 4999999.99],
        3 => ['min' => 5000000, 'max' => 9999999.99],
        4 => ['min' => 10000000, 'max' => null], // null = unlimited
    ];
    
    /**
     * Officer roles (verified from production database, Stage 3 evidence)
     */
    private const OFFICER_ROLES = ['chairman', 'vice_chairman', 'secretary', 'treasurer'];
    
    public function __construct(PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }
    
    // ============================================================
    //  POLICY DETERMINATION
    // ============================================================
    
    /**
     * Determine the applicable approval policy and tier for a loan.
     * 
     * This is the ONLY authoritative tier determination method. Never trust
     * client-supplied tier numbers or officer flags. Always determine from
     * actual transaction data.
     * 
     * @param int $memberId Loan borrower member ID
     * @param float $amount Loan amount
     * @return array ['policy_id' => int, 'tier_id' => int, 'tier_number' => int, 'is_officer_loan' => bool]
     * @throws RuntimeException If policy cannot be determined or officer loan is blocked
     */
    public function determineApprovalPolicy(int $memberId, float $amount): array
    {
        // Step 1: Check if this is an officer loan
        $isOfficerLoan = $this->detectOfficerLoan($memberId);
        
        if ($isOfficerLoan) {
            // Step 2A: Officer loan - check if borrower is one of the four required approvers
            $borrowerRole = $this->getMemberRole($memberId);
            if ($borrowerRole !== null && in_array($borrowerRole, self::OFFICER_ROLES, true)) {
                throw new RuntimeException(
                    'Officer loans to Chairman, Vice Chairman, Secretary, or Treasurer are currently blocked. ' .
                    'The required four independent mandatory approvals cannot be assembled because the borrower ' .
                    'is excluded from approving. No substitute approver is currently designated. ' .
                    'Contact club governance for policy review.'
                );
            }
            
            // Officer loan - use Tier 5
            return $this->getPolicyForOfficerLoan();
        }
        
        // Step 2B: Normal loan - determine tier by amount
        return $this->getPolicyForAmount($amount);
    }
    
    /**
     * Get policy/tier for an officer loan (Tier 5)
     */
    private function getPolicyForOfficerLoan(): array
    {
        $stmt = $this->db->prepare("
            SELECT p.id AS policy_id, t.id AS tier_id, t.tier_number
            FROM approval_policies p
            JOIN approval_tiers t ON t.policy_id = p.id
            WHERE p.transaction_type = 'loan'
              AND p.effective_to IS NULL
              AND t.special_rule = 'officer_loan'
            LIMIT 1
        ");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            throw new RuntimeException('Officer loan approval policy (Tier 5) not found. Run Stage 3 migration.');
        }
        
        return [
            'policy_id' => (int)$row['policy_id'],
            'tier_id' => (int)$row['tier_id'],
            'tier_number' => (int)$row['tier_number'],
            'is_officer_loan' => true,
        ];
    }
    
    /**
     * Get policy/tier for a normal loan based on amount
     */
    private function getPolicyForAmount(float $amount): array
    {
        // Determine tier number from locked boundaries
        $tierNumber = null;
        foreach (self::TIER_BOUNDARIES as $tier => $bounds) {
            if ($amount >= $bounds['min'] && ($bounds['max'] === null || $amount <= $bounds['max'])) {
                $tierNumber = $tier;
                break;
            }
        }
        
        if ($tierNumber === null) {
            throw new RuntimeException("Cannot determine approval tier for amount UGX " . number_format($amount, 2));
        }
        
        // Retrieve policy and tier from database
        $stmt = $this->db->prepare("
            SELECT p.id AS policy_id, t.id AS tier_id, t.tier_number
            FROM approval_policies p
            JOIN approval_tiers t ON t.policy_id = p.id
            WHERE p.transaction_type = 'loan'
              AND p.effective_to IS NULL
              AND t.tier_number = ?
            LIMIT 1
        ");
        $stmt->execute([$tierNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            throw new RuntimeException("Approval tier {$tierNumber} not found. Run Stage 3 migration.");
        }
        
        return [
            'policy_id' => (int)$row['policy_id'],
            'tier_id' => (int)$row['tier_id'],
            'tier_number' => (int)$row['tier_number'],
            'is_officer_loan' => false,
        ];
    }
    
    // ============================================================
    //  OFFICER DETECTION
    // ============================================================
    
    /**
     * Detect if a loan is an "officer loan" requiring Tier 5 approval.
     * 
     * An officer loan is a loan where the borrower (member) has a linked user account
     * that holds one of the four officer roles: chairman, vice_chairman, secretary, treasurer.
     * 
     * Evidence: Stage 3 Officer Detection Evidence document, verified from production database.
     * 
     * @param int $memberId The borrowing member's ID
     * @return bool True if this is an officer loan
     */
    public function detectOfficerLoan(int $memberId): bool
    {
        $role = $this->getMemberRole($memberId);
        return $role !== null && in_array($role, self::OFFICER_ROLES, true);
    }
    
    /**
     * Get the role of the user account linked to a member.
     * 
     * @param int $memberId
     * @return string|null Role name (e.g. 'chairman', 'treasurer'), or null if no linked active user
     */
    private function getMemberRole(int $memberId): ?string
    {
        $stmt = $this->db->prepare("
            SELECT r.name AS role_name
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE u.member_id = ? AND u.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$memberId]);
        $result = $stmt->fetchColumn();
        return $result !== false ? $result : null;
    }
    
    /**
     * Check if a user holds one of the four officer positions.
     * 
     * @param int $userId The user to check
     * @return bool True if user is chairman, vice_chairman, secretary, or treasurer
     */
    public function isOfficer(int $userId): bool
    {
        $stmt = $this->db->prepare("
            SELECT r.name
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE u.id = ? AND u.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $role = $stmt->fetchColumn();
        
        return $role !== false && in_array($role, self::OFFICER_ROLES, true);
    }
    
    /**
     * Get a user's current role name.
     * 
     * @param int $userId
     * @return string|null Role name, or null if user not found or inactive
     */
    private function getUserRole(int $userId): ?string
    {
        $stmt = $this->db->prepare("
            SELECT r.name
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE u.id = ? AND u.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetchColumn();
        return $result !== false ? $result : null;
    }
    
    // ============================================================
    //  SUBMISSION
    // ============================================================
    
    /**
     * Submit a loan for approval.
     * 
     * Creates a new approval round, determines the applicable policy/tier,
     * creates slot instances, and enforces officer-borrower governance.
     * 
     * @param int $loanId Loan ID
     * @param int $submittedBy User submitting the loan
     * @return int The approval round ID
     * @throws RuntimeException If submission fails validation
     */
    public function submitForApproval(int $loanId, int $submittedBy): int
    {
        // Retrieve loan details
        $loan = $this->getLoan($loanId);
        if (!$loan) {
            throw new RuntimeException("Loan ID {$loanId} not found.");
        }
        
        // Determine applicable policy
        $policy = $this->determineApprovalPolicy((int)$loan['member_id'], (float)$loan['loan_amount']);
        
        // Determine round number (1 for first submission, increment for resubmission)
        $roundNumber = $this->getNextRoundNumber('loan', $loanId);
        
        // Prepare excluded users (for officer loans, exclude the borrower)
        $excludedUserIds = [];
        if ($policy['is_officer_loan']) {
            $borrowerUserId = $this->getMemberUserId((int)$loan['member_id']);
            if ($borrowerUserId !== null) {
                $excludedUserIds[] = $borrowerUserId;
            }
        }
        
        // Begin transaction
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        
        try {
            // Create approval round
            $stmt = $this->db->prepare("
                INSERT INTO transaction_approval_rounds 
                (transaction_type, transaction_id, round_number, policy_id, tier_id, tier_number,
                 snapshot_amount, snapshot_member_id, approval_status, submitted_by, excluded_user_ids)
                VALUES ('loan', ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)
            ");
            $stmt->execute([
                $loanId,
                $roundNumber,
                $policy['policy_id'],
                $policy['tier_id'],
                $policy['tier_number'],
                $loan['loan_amount'],
                $loan['member_id'],
                $submittedBy,
                !empty($excludedUserIds) ? json_encode($excludedUserIds) : null,
            ]);
            
            $roundId = (int)$this->db->lastInsertId();
            
            // Create slot instances for this round
            $this->createSlotInstances($roundId, $policy['tier_id']);
            
            if ($ownTransaction) {
                $this->db->commit();
            }
            
            return $roundId;
            
        } catch (Exception $e) {
            if ($ownTransaction) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
    
    /**
     * Create slot instances for an approval round.
     * 
     * Copies the tier's slot definitions into transaction_approval_slot_instances
     * for this specific round.
     */
    private function createSlotInstances(int $roundId, int $tierId): void
    {
        $stmt = $this->db->prepare("
            SELECT id, slot_number, slot_type, slot_group, required_role, display_label
            FROM approval_tier_slots
            WHERE tier_id = ?
            ORDER BY slot_number
        ");
        $stmt->execute([$tierId]);
        $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $insertStmt = $this->db->prepare("
            INSERT INTO transaction_approval_slot_instances
            (approval_round_id, slot_id, slot_number, slot_type, slot_group, required_role, display_label, slot_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        
        foreach ($slots as $slot) {
            $insertStmt->execute([
                $roundId,
                $slot['id'],
                $slot['slot_number'],
                $slot['slot_type'],
                $slot['slot_group'],
                $slot['required_role'],
                $slot['display_label'],
            ]);
        }
    }
    
    /**
     * Get the next round number for a transaction.
     */
    private function getNextRoundNumber(string $transactionType, int $transactionId): int
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(MAX(round_number), 0) + 1
            FROM transaction_approval_rounds
            WHERE transaction_type = ? AND transaction_id = ?
        ");
        $stmt->execute([$transactionType, $transactionId]);
        return (int)$stmt->fetchColumn();
    }
    
    // ============================================================
    //  APPROVAL
    // ============================================================
    
    /**
     * Record an approval action by a user.
     * 
     * Enforces maker-checker, role matching, one-person-one-slot, and duplicate protection.
     * 
     * @param int $loanId Loan ID
     * @param int $userId User approving
     * @return array ['approval_complete' => bool, 'round_id' => int]
     * @throws RuntimeException If approval fails validation
     */
    public function recordApproval(int $loanId, int $userId): array
    {
        // Get active approval round
        $round = $this->getActiveRound('loan', $loanId);
        if (!$round) {
            throw new RuntimeException("No active approval round found for loan ID {$loanId}.");
        }
        
        $roundId = (int)$round['id'];
        
        // Maker-checker: user cannot approve their own submission
        if ((int)$round['submitted_by'] === $userId) {
            throw new RuntimeException('You cannot approve a loan you submitted. Maker-checker separation is required.');
        }
        
        // Check if user is excluded (officer loan borrower)
        if ($this->isUserExcluded($roundId, $userId)) {
            throw new RuntimeException('You are excluded from approving this transaction (officer-borrower conflict).');
        }
        
        // Get user's role
        $userRole = $this->getUserRole($userId);
        if ($userRole === null) {
            throw new RuntimeException('User not found or inactive.');
        }
        
        // Check if user has already approved this round
        if ($this->hasUserApproved($roundId, $userId)) {
            throw new RuntimeException('You have already approved this transaction.');
        }
        
        // Find a matching pending slot
        $slot = $this->findMatchingSlot($roundId, $userRole);
        if (!$slot) {
            throw new RuntimeException(
                'Your role does not match any pending required approval slot, or all your eligible slots are already satisfied.'
            );
        }
        
        // Begin transaction
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        
        try {
            // Record approval action
            $stmt = $this->db->prepare("
                INSERT INTO approval_actions
                (approval_round_id, slot_instance_id, user_id, user_role, action_type)
                VALUES (?, ?, ?, ?, 'approved')
            ");
            $stmt->execute([$roundId, $slot['id'], $userId, $userRole]);
            
            // Satisfy the slot
            $stmt = $this->db->prepare("
                UPDATE transaction_approval_slot_instances
                SET slot_status = 'satisfied', satisfied_by_user_id = ?, satisfied_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$userId, $slot['id']]);
            
            // Handle alternative slots: mark other alternatives in the same group as not_required
            if ($slot['slot_type'] === 'alternative' && $slot['slot_group'] !== null) {
                $stmt = $this->db->prepare("
                    UPDATE transaction_approval_slot_instances
                    SET slot_status = 'not_required'
                    WHERE approval_round_id = ?
                      AND slot_group = ?
                      AND id != ?
                      AND slot_status = 'pending'
                ");
                $stmt->execute([$roundId, $slot['slot_group'], $slot['id']]);
            }
            
            // Check if approval is complete
            $isComplete = $this->isApprovalComplete($roundId);
            
            if ($isComplete) {
                // Mark round as approved
                $stmt = $this->db->prepare("
                    UPDATE transaction_approval_rounds
                    SET approval_status = 'approved', completed_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$roundId]);
            }
            
            if ($ownTransaction) {
                $this->db->commit();
            }
            
            return [
                'approval_complete' => $isComplete,
                'round_id' => $roundId,
            ];
            
        } catch (Exception $e) {
            if ($ownTransaction) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
    
    /**
     * Find a matching pending slot for a user's role.
     * 
     * Returns the first pending slot that matches the user's role.
     * For alternative slots, returns the first alternative in the group.
     */
    private function findMatchingSlot(int $roundId, string $userRole): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, slot_number, slot_type, slot_group, required_role
            FROM transaction_approval_slot_instances
            WHERE approval_round_id = ?
              AND required_role = ?
              AND slot_status = 'pending'
            ORDER BY slot_number
            LIMIT 1
        ");
        $stmt->execute([$roundId, $userRole]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result !== false ? $result : null;
    }
    
    /**
     * Check if a user has already approved a round.
     */
    private function hasUserApproved(int $roundId, int $userId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM approval_actions
            WHERE approval_round_id = ? AND user_id = ? AND action_type = 'approved'
        ");
        $stmt->execute([$roundId, $userId]);
        return (int)$stmt->fetchColumn() > 0;
    }
    
    /**
     * Check if a user is excluded from approving (officer-borrower conflict).
     */
    private function isUserExcluded(int $roundId, int $userId): bool
    {
        $stmt = $this->db->prepare("
            SELECT excluded_user_ids
            FROM transaction_approval_rounds
            WHERE id = ?
        ");
        $stmt->execute([$roundId]);
        $json = $stmt->fetchColumn();
        
        if (!$json) {
            return false;
        }
        
        $excludedIds = json_decode($json, true);
        return is_array($excludedIds) && in_array($userId, $excludedIds, true);
    }
    
    /**
     * Check if all required slots for a round are satisfied.
     */
    public function isApprovalComplete(int $roundId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM transaction_approval_slot_instances
            WHERE approval_round_id = ?
              AND slot_status = 'pending'
              AND slot_type != 'alternative'
        ");
        $stmt->execute([$roundId]);
        $pendingMandatory = (int)$stmt->fetchColumn();
        
        if ($pendingMandatory > 0) {
            return false;
        }
        
        // Check alternative groups - at least one in each group must be satisfied
        $stmt = $this->db->prepare("
            SELECT DISTINCT slot_group
            FROM transaction_approval_slot_instances
            WHERE approval_round_id = ?
              AND slot_type = 'alternative'
              AND slot_group IS NOT NULL
        ");
        $stmt->execute([$roundId]);
        $groups = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($groups as $group) {
            $stmt = $this->db->prepare("
                SELECT COUNT(*)
                FROM transaction_approval_slot_instances
                WHERE approval_round_id = ?
                  AND slot_group = ?
                  AND slot_status = 'satisfied'
            ");
            $stmt->execute([$roundId, $group]);
            $satisfiedInGroup = (int)$stmt->fetchColumn();
            
            if ($satisfiedInGroup === 0) {
                return false; // This alternative group has no satisfied slot
            }
        }
        
        return true;
    }
    
    // ============================================================
    //  REJECTION
    // ============================================================
    
    /**
     * Record a rejection action by a user.
     * 
     * @param int $loanId Loan ID
     * @param int $userId User rejecting
     * @param string $reason Rejection reason (required)
     * @return int The approval round ID
     * @throws RuntimeException If rejection fails validation
     */
    public function recordRejection(int $loanId, int $userId, string $reason): int
    {
        if (trim($reason) === '') {
            throw new RuntimeException('A rejection reason is required.');
        }
        
        // Get active approval round
        $round = $this->getActiveRound('loan', $loanId);
        if (!$round) {
            throw new RuntimeException("No active approval round found for loan ID {$loanId}.");
        }
        
        $roundId = (int)$round['id'];
        
        // Maker-checker: user cannot reject their own submission
        if ((int)$round['submitted_by'] === $userId) {
            throw new RuntimeException('You cannot reject a loan you submitted.');
        }
        
        // Get user's role
        $userRole = $this->getUserRole($userId);
        if ($userRole === null) {
            throw new RuntimeException('User not found or inactive.');
        }
        
        // Begin transaction
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        
        try {
            // Record rejection action (slot_instance_id is NULL for rejections)
            $stmt = $this->db->prepare("
                INSERT INTO approval_actions
                (approval_round_id, slot_instance_id, user_id, user_role, action_type, action_reason)
                VALUES (?, NULL, ?, ?, 'rejected', ?)
            ");
            $stmt->execute([$roundId, $userId, $userRole, $reason]);
            
            // Mark round as rejected
            $stmt = $this->db->prepare("
                UPDATE transaction_approval_rounds
                SET approval_status = 'rejected', completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$roundId]);
            
            if ($ownTransaction) {
                $this->db->commit();
            }
            
            return $roundId;
            
        } catch (Exception $e) {
            if ($ownTransaction) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
    
    // ============================================================
    //  QUERY HELPERS
    // ============================================================
    
    /**
     * Get the active (pending) approval round for a transaction.
     */
    private function getActiveRound(string $transactionType, int $transactionId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM transaction_approval_rounds
            WHERE transaction_type = ?
              AND transaction_id = ?
              AND approval_status = 'pending'
            ORDER BY round_number DESC
            LIMIT 1
        ");
        $stmt->execute([$transactionType, $transactionId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result !== false ? $result : null;
    }
    
    /**
     * Get pending approval rounds (for listing pending approvals).
     */
    public function getPendingApprovals(string $transactionType = 'loan', int $limit = 50): array
    {
        $stmt = $this->db->prepare("
            SELECT r.*, t.tier_name, t.tier_number
            FROM transaction_approval_rounds r
            JOIN approval_tiers t ON t.id = r.tier_id
            WHERE r.transaction_type = ?
              AND r.approval_status = 'pending'
            ORDER BY r.submitted_at ASC
            LIMIT ?
        ");
        $stmt->execute([$transactionType, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Check if a user can approve a specific transaction.
     * 
     * Returns true if the user:
     * - Is not the maker
     * - Is not excluded
     * - Has not already approved
     * - Has a matching pending slot
     */
    public function canUserApprove(int $loanId, int $userId): bool
    {
        $round = $this->getActiveRound('loan', $loanId);
        if (!$round) {
            return false;
        }
        
        $roundId = (int)$round['id'];
        
        // Check maker-checker
        if ((int)$round['submitted_by'] === $userId) {
            return false;
        }
        
        // Check exclusion
        if ($this->isUserExcluded($roundId, $userId)) {
            return false;
        }
        
        // Check if already approved
        if ($this->hasUserApproved($roundId, $userId)) {
            return false;
        }
        
        // Check if user has a matching slot
        $userRole = $this->getUserRole($userId);
        if ($userRole === null) {
            return false;
        }
        
        $slot = $this->findMatchingSlot($roundId, $userRole);
        return $slot !== null;
    }
    
    /**
     * Get loan details.
     */
    private function getLoan(int $loanId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM loans WHERE id = ? LIMIT 1");
        $stmt->execute([$loanId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result !== false ? $result : null;
    }
    
    /**
     * Get the user ID linked to a member.
     */
    private function getMemberUserId(int $memberId): ?int
    {
        $stmt = $this->db->prepare("
            SELECT id FROM users WHERE member_id = ? AND is_active = 1 LIMIT 1
        ");
        $stmt->execute([$memberId]);
        $result = $stmt->fetchColumn();
        return $result !== false ? (int)$result : null;
    }
    
    // ============================================================
    //  MATERIAL CHANGE DETECTION
    // ============================================================
    
    /**
     * Validate that a loan has not materially changed since approval was started.
     * 
     * Material changes invalidate approval and require resubmission.
     * 
     * @param int $loanId Loan ID
     * @return bool True if loan data still matches the approval round snapshot
     * @throws RuntimeException If no active round exists
     */
    public function validateMaterialChange(int $loanId): bool
    {
        $round = $this->getActiveRound('loan', $loanId);
        if (!$round) {
            throw new RuntimeException("No active approval round found for loan ID {$loanId}.");
        }
        
        $loan = $this->getLoan($loanId);
        if (!$loan) {
            throw new RuntimeException("Loan ID {$loanId} not found.");
        }
        
        // Check amount
        if ((float)$loan['loan_amount'] !== (float)$round['snapshot_amount']) {
            return false;
        }
        
        // Check member
        if ((int)$loan['member_id'] !== (int)$round['snapshot_member_id']) {
            return false;
        }
        
        // Additional material fields can be added here or stored in snapshot_data JSON
        
        return true;
    }
}
