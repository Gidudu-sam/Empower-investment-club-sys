# Empower Investment Club — Multi-Level Approval Architecture V2
**Purpose:** Corrected architectural design addressing identified weaknesses  
**Date:** 2026-09-14  
**Status:** DESIGN PHASE — No implementation yet

---

## 🎯 Design Corrections Summary

### Issues Identified in V1 Audit:
1. ❌ Missing `required_roles` column in `transaction_approvals`
2. ❌ Cannot represent "Chairman + (Vice OR Secretary)" with simple role list
3. ❌ JSON-based approval logic not enforceable
4. ❌ CASCADE DELETE on approval actions (destroys audit trail)
5. ❌ No support for re-submission after rejection (UNIQUE constraint blocks)
6. ❌ `current_approvals` treated as authoritative (should be derived)
7. ❌ "Same role = same approval" ambiguity
8. ❌ Admin role bypass not resolved
9. ❌ "Parallel system" creates competing approval mechanisms
10. ❌ No approval snapshots (policy changes affect pending approvals)
11. ❌ No amount-at-submission protection (can edit to different tier)
12. ❌ No distinction between "approval required" vs "approval action"
13. ❌ UI shows numbers (1/2) not requirements (Chairman + Vice/Secretary)
14. ❌ No policy versioning

### V2 Corrections:
✅ All 14 issues addressed in this design

---

## 📊 Core Architectural Principles

### Principle 1: **Approval Slots, Not Counts**
```
❌ BAD (V1):
   Required: 2 approvals
   Current: 1 approval
   Who? Unknown!

✅ GOOD (V2):
   Slot 1: Chairman (mandatory) → APPROVED ✓
   Slot 2: Vice Chairman OR Secretary (one required) → PENDING ⏳
```

### Principle 2: **Immutable Audit Trail**
- Approval records are NEVER deleted
- Approval actions are NEVER modified after creation
- Re-submission creates NEW approval round (preserves old round)

### Principle 3: **Approval Snapshot**
- When transaction submitted, capture approval policy at that moment
- Policy changes do NOT affect pending approvals
- Each approval round has its own immutable requirements

### Principle 4: **Derived Truth**
- Approval status is DERIVED from approval actions, not cached counters
- System reconciles status from underlying actions
- No "current_approvals" counter as source of truth

### Principle 5: **Single Approval Engine**
- New approval engine is THE authoritative approval mechanism
- Old `approved_by` fields kept for compatibility only
- No competing approval systems

### Principle 6: **Material Change Invalidation**
- Editing amount/member/accounts after submission → invalidates approvals
- Must re-submit for approval (creates new round)
- Prevents tier-jumping exploits

---

## 🗄️ Database Schema V2

### 1. **approval_policies** (Configuration)
```sql
CREATE TABLE `approval_policies` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `policy_version` INT NOT NULL COMMENT 'Policy version number (1, 2, 3...)',
    `transaction_type` ENUM('loan','investment','internal_voucher','member_adjustment','opening_balance','withdrawal','closure') NOT NULL,
    `effective_from` DATE NOT NULL,
    `effective_to` DATE NULL COMMENT 'NULL = currently active',
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_policy_version` (`transaction_type`, `policy_version`),
    KEY `idx_policy_active` (`transaction_type`, `effective_to`),
    CONSTRAINT `fk_policy_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Purpose:** Version approval policies over time. When policy changes, create new version.

---

### 2. **approval_tiers** (Tier Definitions)
```sql
CREATE TABLE `approval_tiers` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `policy_id` INT UNSIGNED NOT NULL,
    `tier_number` INT NOT NULL COMMENT '1, 2, 3, 4',
    `tier_name` VARCHAR(50) NOT NULL COMMENT 'e.g. Small Loan, Medium Loan',
    `min_amount` DECIMAL(15,2) NOT NULL,
    `max_amount` DECIMAL(15,2) NULL COMMENT 'NULL = unlimited',
    `special_rule` VARCHAR(50) NULL COMMENT 'e.g. officer_loan, large_voucher',
    `description` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_tier_policy_number` (`policy_id`, `tier_number`),
    KEY `idx_tier_amount` (`policy_id`, `min_amount`, `max_amount`),
    CONSTRAINT `fk_tier_policy` FOREIGN KEY (`policy_id`) REFERENCES `approval_policies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Purpose:** Define amount thresholds for each tier within a policy version.

**Example:**
```
Policy ID: 1 (Loans v1)
├─ Tier 1: <1M
├─ Tier 2: 1M-5M
├─ Tier 3: 5M-10M
└─ Tier 4: >10M
```

---

### 3. **approval_tier_slots** (Slot Definitions)
```sql
CREATE TABLE `approval_tier_slots` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tier_id` INT UNSIGNED NOT NULL,
    `slot_number` INT NOT NULL COMMENT '1, 2, 3, 4 (display order)',
    `slot_type` ENUM('mandatory','alternative','sequential') NOT NULL COMMENT 'mandatory=must approve, alternative=one of group, sequential=order matters',
    `slot_group` INT NULL COMMENT 'For alternatives: slots with same group number are alternatives',
    `required_role` VARCHAR(50) NOT NULL COMMENT 'chairman, vice_chairman, secretary, treasurer',
    `display_label` VARCHAR(100) NULL COMMENT 'e.g. "Chairman + Vice Chairman", "Vice Chairman OR Secretary"',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_slot_tier_number` (`tier_id`, `slot_number`),
    KEY `idx_slot_group` (`tier_id`, `slot_group`),
    CONSTRAINT `fk_slot_tier` FOREIGN KEY (`tier_id`) REFERENCES `approval_tiers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Purpose:** Define EXACT approval requirements (slots) for each tier.

**Example — Tier 2 (2 approvers: Chairman + Vice/Secretary):**
```
Tier ID: 2
├─ Slot 1: mandatory, required_role='chairman', group=NULL
└─ Slot 2: alternative, required_role='vice_chairman', group=1
    Slot 2: alternative, required_role='secretary', group=1
    (one of group 1 required)
```

**Example — Tier 3 (3 approvers: Chairman + Vice + Secretary):**
```
Tier ID: 3
├─ Slot 1: mandatory, required_role='chairman', group=NULL
├─ Slot 2: mandatory, required_role='vice_chairman', group=NULL
└─ Slot 3: mandatory, required_role='secretary', group=NULL
```

---

### 4. **transaction_approval_rounds** (Approval Instances)
```sql
CREATE TABLE `transaction_approval_rounds` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `transaction_type` ENUM('loan','investment','internal_voucher','member_adjustment','opening_balance','withdrawal','closure') NOT NULL,
    `transaction_id` INT UNSIGNED NOT NULL,
    `round_number` INT NOT NULL DEFAULT 1 COMMENT 'Submission attempt (1=first, 2=after rejection, etc.)',
    
    -- Snapshot of policy at submission time
    `policy_id` INT UNSIGNED NOT NULL COMMENT 'Approval policy version at submission',
    `tier_id` INT UNSIGNED NOT NULL COMMENT 'Tier determined at submission',
    `tier_number` INT NOT NULL,
    `snapshot_amount` DECIMAL(15,2) NOT NULL COMMENT 'Transaction amount at submission (immutable)',
    `snapshot_member_id` INT UNSIGNED NULL COMMENT 'For loans/adjustments: member at submission',
    `snapshot_data` JSON NULL COMMENT 'Other immutable data: accounts, dates, etc.',
    
    -- Approval status
    `approval_status` ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    `submitted_by` INT UNSIGNED NOT NULL,
    `submitted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` TIMESTAMP NULL COMMENT 'When approved/rejected',
    
    -- Special rules
    `excluded_user_ids` JSON NULL COMMENT 'Users excluded from approval (e.g. borrower for officer loans)',
    
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY `uk_trans_round` (`transaction_type`, `transaction_id`, `round_number`),
    KEY `idx_approval_status` (`approval_status`),
    KEY `idx_trans_type_id` (`transaction_type`, `transaction_id`),
    KEY `idx_pending` (`approval_status`, `submitted_at`),
    
    CONSTRAINT `fk_trans_round_policy` FOREIGN KEY (`policy_id`) REFERENCES `approval_policies`(`id`),
    CONSTRAINT `fk_trans_round_tier` FOREIGN KEY (`tier_id`) REFERENCES `approval_tiers`(`id`),
    CONSTRAINT `fk_trans_round_submitted_by` FOREIGN KEY (`submitted_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Purpose:** 
- ONE row per submission attempt (supports re-submission after rejection)
- Captures immutable snapshot of policy and transaction data at submission time
- Prevents policy changes from affecting pending approvals
- Prevents amount edits from bypassing approval tier

**Example:**
```
Loan #15
├─ Round 1: Submitted 2026-09-10, Amount 7M, Tier 3 → Rejected
├─ Round 2: Submitted 2026-09-12, Amount 6M, Tier 3 → Approved
└─ Current active round: 2
```

---

### 5. **transaction_approval_slot_instances** (Required Slots for This Round)
```sql
CREATE TABLE `transaction_approval_slot_instances` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `approval_round_id` INT UNSIGNED NOT NULL,
    `slot_id` INT UNSIGNED NOT NULL COMMENT 'References approval_tier_slots',
    `slot_number` INT NOT NULL,
    `slot_type` ENUM('mandatory','alternative','sequential') NOT NULL,
    `slot_group` INT NULL,
    `required_role` VARCHAR(50) NOT NULL,
    `display_label` VARCHAR(100) NULL,
    `slot_status` ENUM('pending','satisfied','not_required') NOT NULL DEFAULT 'pending',
    `satisfied_by_user_id` INT UNSIGNED NULL COMMENT 'User who satisfied this slot',
    `satisfied_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY `uk_slot_instance` (`approval_round_id`, `slot_number`),
    KEY `idx_slot_status` (`approval_round_id`, `slot_status`),
    
    CONSTRAINT `fk_slot_inst_round` FOREIGN KEY (`approval_round_id`) REFERENCES `transaction_approval_rounds`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_slot_inst_slot` FOREIGN KEY (`slot_id`) REFERENCES `approval_tier_slots`(`id`),
    CONSTRAINT `fk_slot_inst_satisfied_by` FOREIGN KEY (`satisfied_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Purpose:** 
- Copy of approval slots for THIS specific approval round
- Tracks which slots are satisfied
- Immutable snapshot (policy changes don't affect this)

**Example — Loan Round 2, Tier 3:**
```
Round ID: 2
├─ Slot Instance 1: Chairman, mandatory → SATISFIED (by User 5)
├─ Slot Instance 2: Vice Chairman, mandatory → SATISFIED (by User 8)
└─ Slot Instance 3: Secretary, mandatory → PENDING
```

---

### 6. **approval_actions** (Individual Approval Events)
```sql
CREATE TABLE `approval_actions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `approval_round_id` INT UNSIGNED NOT NULL,
    `slot_instance_id` INT UNSIGNED NULL COMMENT 'Which slot this action satisfied (NULL for rejections)',
    `user_id` INT UNSIGNED NOT NULL,
    `user_role` VARCHAR(50) NOT NULL COMMENT 'User role at time of action (chairman, vice_chairman, etc.)',
    `action_type` ENUM('approved','rejected') NOT NULL,
    `action_reason` TEXT NULL COMMENT 'Optional for approve, required for reject',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    KEY `idx_action_round` (`approval_round_id`, `created_at`),
    KEY `idx_action_user` (`user_id`),
    KEY `idx_action_slot` (`slot_instance_id`),
    
    -- NO CASCADE DELETE - approval actions are immutable audit evidence
    CONSTRAINT `fk_action_round` FOREIGN KEY (`approval_round_id`) REFERENCES `transaction_approval_rounds`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_action_slot` FOREIGN KEY (`slot_instance_id`) REFERENCES `transaction_approval_slot_instances`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_action_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Purpose:**
- Immutable log of every approval/rejection action
- CANNOT be deleted (audit trail protection)
- Contains user role at time of action (handles role changes)

**Example:**
```
Round 2 actions:
├─ Action 1: User 5 (chairman) approved, satisfied slot 1, 2026-09-14 10:30
├─ Action 2: User 8 (vice_chairman) approved, satisfied slot 2, 2026-09-14 11:15
└─ Action 3: User 12 (secretary) approved, satisfied slot 3, 2026-09-14 14:00
```

---

### 7. **Existing Transaction Tables** (Keep Compatibility Fields)
```sql
-- Example: loans table
-- KEEP these fields for backward compatibility:
`status` ENUM('draft','pending_approval','approved','rejected',...)
`approved_by` INT UNSIGNED NULL -- Last approver (for compatibility)
`approved_at` TIMESTAMP NULL
`rejected_by` INT UNSIGNED NULL
`rejected_at` TIMESTAMP NULL
`rejection_reason` TEXT NULL

-- KEEP maker fields:
`recorded_by` INT UNSIGNED NOT NULL
`submitted_at` TIMESTAMP NULL
```

**Important:** These fields are NO LONGER authoritative for approval.

**Approval status is determined by:**
```sql
SELECT approval_status 
FROM transaction_approval_rounds 
WHERE transaction_type = 'loan' 
  AND transaction_id = ?
  AND round_number = (
      SELECT MAX(round_number) 
      FROM transaction_approval_rounds 
      WHERE transaction_type = 'loan' AND transaction_id = ?
  )
```

---

## 🔧 Core Logic Implementation

### Submission Logic

```php
public function submit(int $loanId, int $userId): void
{
    $loan = $this->find($loanId);
    
    // 1. Validation
    if ($loan['status'] !== 'draft') {
        throw new InvalidArgumentException("Only draft loans can be submitted.");
    }
    $this->validate($loan);
    
    // 2. Get current active policy
    $policy = $this->getCurrentPolicy('loan');
    
    // 3. Determine tier based on amount
    $tier = $this->determineTier($policy['id'], $loan['loan_amount']);
    
    // 4. Check special rules (officer loan detection)
    $excludedUserIds = [];
    if ($this->isOfficerLoan($loan['member_id'])) {
        $excludedUserIds[] = $this->getUserIdForMember($loan['member_id']);
    }
    
    // 5. Get next round number
    $roundNumber = $this->getNextRoundNumber('loan', $loanId);
    
    $this->db->beginTransaction();
    try {
        // 6. Create approval round (snapshot)
        $roundId = $this->createApprovalRound([
            'transaction_type' => 'loan',
            'transaction_id' => $loanId,
            'round_number' => $roundNumber,
            'policy_id' => $policy['id'],
            'tier_id' => $tier['id'],
            'tier_number' => $tier['tier_number'],
            'snapshot_amount' => $loan['loan_amount'],
            'snapshot_member_id' => $loan['member_id'],
            'snapshot_data' => json_encode([
                'loan_officer' => $loan['loan_officer'],
                'issue_date' => $loan['issue_date'],
                'due_date' => $loan['due_date']
            ]),
            'excluded_user_ids' => json_encode($excludedUserIds),
            'submitted_by' => $userId
        ]);
        
        // 7. Copy slot definitions to slot instances
        $slots = $this->getTierSlots($tier['id']);
        foreach ($slots as $slot) {
            $this->createSlotInstance([
                'approval_round_id' => $roundId,
                'slot_id' => $slot['id'],
                'slot_number' => $slot['slot_number'],
                'slot_type' => $slot['slot_type'],
                'slot_group' => $slot['slot_group'],
                'required_role' => $slot['required_role'],
                'display_label' => $slot['display_label']
            ]);
        }
        
        // 8. Update loan status
        $this->db->prepare("
            UPDATE loans 
            SET status = 'pending_approval', submitted_at = NOW() 
            WHERE id = ?
        ")->execute([$loanId]);
        
        // 9. Audit trail
        $this->writeAudit($userId, 'submitted', $loanId, [
            'round' => $roundNumber,
            'tier' => $tier['tier_number'],
            'amount' => $loan['loan_amount']
        ]);
        
        // 10. Notify required approvers
        $requiredRoles = $this->getRequiredRoles($roundId);
        $this->notifyApprovers($loanId, $requiredRoles, $excludedUserIds);
        
        $this->db->commit();
        
    } catch (Throwable $e) {
        $this->db->rollBack();
        throw $e;
    }
}
```

---

### Approval Logic

```php
public function approve(int $loanId, int $userId, string $userRole): void
{
    $loan = $this->find($loanId);
    
    // 1. Validation
    if ($loan['status'] !== 'pending_approval') {
        throw new InvalidArgumentException("Only pending loans can be approved.");
    }
    if ((int)$loan['recorded_by'] === $userId) {
        throw new InvalidArgumentException('Cannot approve your own loan.');
    }
    
    // 2. Get current active approval round
    $round = $this->getCurrentApprovalRound('loan', $loanId);
    if (!$round || $round['approval_status'] !== 'pending') {
        throw new InvalidArgumentException("No pending approval round found.");
    }
    
    // 3. Check if user is excluded (officer loan)
    $excludedUserIds = json_decode($round['excluded_user_ids'] ?? '[]', true);
    if (in_array($userId, $excludedUserIds, true)) {
        throw new InvalidArgumentException('You cannot approve your own officer loan.');
    }
    
    // 4. Check if user already approved this round
    if ($this->hasUserApprovedRound($round['id'], $userId)) {
        throw new InvalidArgumentException('You have already approved this transaction.');
    }
    
    // 5. Find slot that user can satisfy
    $eligibleSlot = $this->findEligibleSlot($round['id'], $userRole);
    if (!$eligibleSlot) {
        throw new InvalidArgumentException("Your role ({$userRole}) cannot satisfy any pending approval slot.");
    }
    
    $this->db->beginTransaction();
    try {
        // 6. Record approval action
        $actionId = $this->createApprovalAction([
            'approval_round_id' => $round['id'],
            'slot_instance_id' => $eligibleSlot['id'],
            'user_id' => $userId,
            'user_role' => $userRole,
            'action_type' => 'approved'
        ]);
        
        // 7. Mark slot as satisfied
        $this->db->prepare("
            UPDATE transaction_approval_slot_instances 
            SET slot_status = 'satisfied', 
                satisfied_by_user_id = ?, 
                satisfied_at = NOW() 
            WHERE id = ?
        ")->execute([$userId, $eligibleSlot['id']]);
        
        // 8. Check if slot is part of alternative group
        if ($eligibleSlot['slot_type'] === 'alternative' && $eligibleSlot['slot_group']) {
            // Mark other slots in group as not_required
            $this->db->prepare("
                UPDATE transaction_approval_slot_instances 
                SET slot_status = 'not_required' 
                WHERE approval_round_id = ? 
                  AND slot_group = ? 
                  AND id != ? 
                  AND slot_status = 'pending'
            ")->execute([$round['id'], $eligibleSlot['slot_group'], $eligibleSlot['id']]);
        }
        
        // 9. Check if all required slots satisfied
        $allSatisfied = $this->areAllSlotsSatisfied($round['id']);
        
        if ($allSatisfied) {
            // 10. Mark round as approved
            $this->db->prepare("
                UPDATE transaction_approval_rounds 
                SET approval_status = 'approved', completed_at = NOW() 
                WHERE id = ?
            ")->execute([$round['id']]);
            
            // 11. Update loan status (keep last approver for compatibility)
            $this->db->prepare("
                UPDATE loans 
                SET status = 'approved', approved_by = ?, approved_at = NOW() 
                WHERE id = ?
            ")->execute([$userId, $loanId]);
            
            // 12. Audit: Fully approved
            $this->writeAudit($userId, 'fully_approved', $loanId, [
                'round' => $round['round_number'],
                'final_approver' => $userRole
            ]);
            
        } else {
            // 13. Partial approval
            $remaining = $this->getPendingSlots($round['id']);
            $this->writeAudit($userId, 'partial_approved', $loanId, [
                'round' => $round['round_number'],
                'approver_role' => $userRole,
                'slots_remaining' => count($remaining)
            ]);
        }
        
        $this->db->commit();
        
    } catch (Throwable $e) {
        $this->db->rollBack();
        throw $e;
    }
}
```

---

### Slot Eligibility Logic

```php
private function findEligibleSlot(int $roundId, string $userRole): ?array
{
    // Find pending slots that match user's role
    $stmt = $this->db->prepare("
        SELECT * FROM transaction_approval_slot_instances 
        WHERE approval_round_id = ? 
          AND slot_status = 'pending' 
          AND required_role = ? 
        ORDER BY slot_number ASC 
        LIMIT 1
    ");
    $stmt->execute([$roundId, $userRole]);
    return $stmt->fetch() ?: null;
}

private function areAllSlotsSatisfied(int $roundId): bool
{
    $stmt = $this->db->prepare("
        SELECT COUNT(*) FROM transaction_approval_slot_instances 
        WHERE approval_round_id = ? 
          AND slot_status = 'pending'
    ");
    $stmt->execute([$roundId]);
    return $stmt->fetchColumn() == 0;
}

private function hasUserApprovedRound(int $roundId, int $userId): bool
{
    $stmt = $this->db->prepare("
        SELECT COUNT(*) FROM approval_actions 
        WHERE approval_round_id = ? 
          AND user_id = ? 
          AND action_type = 'approved'
    ");
    $stmt->execute([$roundId, $userId]);
    return $stmt->fetchColumn() > 0;
}
```

---

### Rejection Logic

```php
public function reject(int $loanId, int $userId, string $userRole, string $reason): void
{
    $loan = $this->find($loanId);
    
    // 1. Validation
    if ($loan['status'] !== 'pending_approval') {
        throw new InvalidArgumentException("Only pending loans can be rejected.");
    }
    
    // 2. Get current active approval round
    $round = $this->getCurrentApprovalRound('loan', $loanId);
    if (!$round || $round['approval_status'] !== 'pending') {
        throw new InvalidArgumentException("No pending approval round found.");
    }
    
    // 3. Check exclusions
    $excludedUserIds = json_decode($round['excluded_user_ids'] ?? '[]', true);
    if (in_array($userId, $excludedUserIds, true)) {
        throw new InvalidArgumentException('You cannot reject your own officer loan.');
    }
    
    // 4. Verify user is an authorized approver for this tier
    $canApprove = $this->canUserApproveRound($round['id'], $userRole);
    if (!$canApprove) {
        throw new InvalidArgumentException("Your role ({$userRole}) is not authorized for this approval tier.");
    }
    
    $this->db->beginTransaction();
    try {
        // 5. Record rejection action (no slot_instance_id - rejections don't satisfy slots)
        $this->createApprovalAction([
            'approval_round_id' => $round['id'],
            'slot_instance_id' => null,
            'user_id' => $userId,
            'user_role' => $userRole,
            'action_type' => 'rejected',
            'action_reason' => $reason
        ]);
        
        // 6. Mark round as rejected (preserve previous approvals for audit)
        $this->db->prepare("
            UPDATE transaction_approval_rounds 
            SET approval_status = 'rejected', completed_at = NOW() 
            WHERE id = ?
        ")->execute([$round['id']]);
        
        // 7. Update loan status (keep for compatibility)
        $this->db->prepare("
            UPDATE loans 
            SET status = 'rejected', rejected_by = ?, rejected_at = NOW(), rejection_reason = ? 
            WHERE id = ?
        ")->execute([$userId, $reason, $loanId]);
        
        // 8. Audit trail (preserve who had already approved)
        $previousApprovals = $this->getApprovalHistory($round['id']);
        $this->writeAudit($userId, 'rejected', $loanId, [
            'round' => $round['round_number'],
            'rejector_role' => $userRole,
            'reason' => $reason,
            'previous_approvals' => $previousApprovals
        ]);
        
        $this->db->commit();
        
    } catch (Throwable $e) {
        $this->db->rollBack();
        throw $e;
    }
}
```

---

### Material Change Detection

```php
public function update(int $loanId, array $data, int $userId): void
{
    $loan = $this->find($loanId);
    
    // 1. Check if loan has pending approval
    $round = $this->getCurrentApprovalRound('loan', $loanId);
    if ($round && $round['approval_status'] === 'pending') {
        // 2. Check for material changes
        $materialChanges = [];
        
        if (isset($data['loan_amount']) && $data['loan_amount'] != $round['snapshot_amount']) {
            $materialChanges[] = "amount changed from {$round['snapshot_amount']} to {$data['loan_amount']}";
        }
        
        if (isset($data['member_id']) && $data['member_id'] != $round['snapshot_member_id']) {
            $materialChanges[] = "member changed";
        }
        
        if (!empty($materialChanges)) {
            // 3. Material change detected - invalidate approvals
            $this->db->beginTransaction();
            try {
                // Cancel current round
                $this->db->prepare("
                    UPDATE transaction_approval_rounds 
                    SET approval_status = 'cancelled', completed_at = NOW() 
                    WHERE id = ?
                ")->execute([$round['id']]);
                
                // Reset loan to draft
                $this->db->prepare("
                    UPDATE loans 
                    SET status = 'draft', submitted_at = NULL 
                    WHERE id = ?
                ")->execute([$loanId]);
                
                // Audit
                $this->writeAudit($userId, 'approval_invalidated', $loanId, [
                    'round' => $round['round_number'],
                    'reason' => 'material_change',
                    'changes' => $materialChanges
                ]);
                
                $this->db->commit();
                
                throw new InvalidArgumentException(
                    "Material changes detected: " . implode(', ', $materialChanges) . 
                    ". Existing approvals invalidated. Please re-submit for approval after making changes."
                );
                
            } catch (Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        }
    }
    
    // 4. If no material changes (or not pending approval), proceed with update
    $this->updateLoanRecord($loanId, $data);
}
```

---

## 🎨 UI Design

### Approval Status Display

```php
<!-- Loan Detail Page -->
<div class="approval-status-card">
    <h3>UGX 7,000,000</h3>
    <p class="loan-number">Loan LNS-001234</p>
    
    <div class="approval-tier-info">
        <span class="badge badge-warning">Tier 3</span>
        <span>3 approvals required</span>
    </div>
    
    <div class="approval-slots">
        <!-- Slot 1: Chairman (mandatory) -->
        <div class="slot satisfied">
            <div class="slot-header">
                <span class="slot-label">Chairman</span>
                <span class="slot-badge">Mandatory</span>
            </div>
            <div class="slot-status">
                <span class="icon">✓</span>
                <span class="approver">Approved by John Doe</span>
                <span class="timestamp">2026-09-14 10:30 AM</span>
            </div>
        </div>
        
        <!-- Slot 2: Vice Chairman (mandatory) -->
        <div class="slot satisfied">
            <div class="slot-header">
                <span class="slot-label">Vice Chairman</span>
                <span class="slot-badge">Mandatory</span>
            </div>
            <div class="slot-status">
                <span class="icon">✓</span>
                <span class="approver">Approved by Mary Smith</span>
                <span class="timestamp">2026-09-14 11:15 AM</span>
            </div>
        </div>
        
        <!-- Slot 3: Secretary (mandatory) -->
        <div class="slot pending">
            <div class="slot-header">
                <span class="slot-label">Secretary</span>
                <span class="slot-badge">Mandatory</span>
            </div>
            <div class="slot-status">
                <span class="icon">⏳</span>
                <span class="status-text">Pending approval</span>
            </div>
        </div>
    </div>
    
    <div class="approval-summary">
        <div class="progress-bar">
            <div class="progress-fill" style="width: 66.67%">2 / 3</div>
        </div>
        <p class="status-text">
            <strong>Status:</strong> PENDING APPROVAL (2/3)
        </p>
        <p class="disbursement-status">
            <span class="icon-lock">🔒</span>
            <strong>Disbursement: LOCKED</strong>
        </p>
    </div>
</div>
```

---

### Tier 2 Example (Alternative Slots)

```php
<div class="approval-slots">
    <!-- Slot 1: Chairman (mandatory) -->
    <div class="slot satisfied">
        <div class="slot-header">
            <span class="slot-label">Chairman</span>
            <span class="slot-badge">Mandatory</span>
        </div>
        <div class="slot-status">
            <span class="icon">✓</span>
            <span class="approver">Approved by John Doe</span>
        </div>
    </div>
    
    <!-- Slot 2: Vice Chairman OR Secretary (one required) -->
    <div class="slot pending slot-alternative">
        <div class="slot-header">
            <span class="slot-label">Vice Chairman <strong>OR</strong> Secretary</span>
            <span class="slot-badge">One Required</span>
        </div>
        <div class="slot-status">
            <span class="icon">⏳</span>
            <span class="status-text">Awaiting approval from Vice Chairman or Secretary</span>
        </div>
    </div>
</div>
```

---

### Approval Queue (for Approvers)

```php
<table class="approval-queue">
    <thead>
        <tr>
            <th>Transaction</th>
            <th>Amount</th>
            <th>Tier</th>
            <th>Approval Status</th>
            <th>Your Slot</th>
            <th>Days Waiting</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>
                <strong>Loan LNS-001234</strong><br>
                <span class="text-muted">Member: John Kamau</span>
            </td>
            <td>UGX 7,000,000</td>
            <td><span class="badge badge-warning">Tier 3</span></td>
            <td>
                <div class="approval-progress">
                    <div class="progress-mini">
                        <div class="fill" style="width: 66.67%"></div>
                    </div>
                    <span>2 / 3</span>
                </div>
                <div class="approval-details">
                    ✓ Chairman<br>
                    ✓ Vice Chairman<br>
                    ⏳ Secretary (YOU)
                </div>
            </td>
            <td>
                <span class="slot-badge pending">Secretary</span><br>
                <span class="text-small">Mandatory</span>
            </td>
            <td>2 days</td>
            <td>
                <button class="btn btn-sm btn-success" onclick="approveTransaction('loan', 1234)">
                    Approve
                </button>
                <button class="btn btn-sm btn-danger" onclick="rejectTransaction('loan', 1234)">
                    Reject
                </button>
            </td>
        </tr>
    </tbody>
</table>
```

---

## 📊 Seed Data Example

### Create Policy Version 1

```sql
-- 1. Create policy
INSERT INTO approval_policies (policy_version, transaction_type, effective_from, created_by) 
VALUES (1, 'loan', '2026-09-15', 1);

SET @policy_id = LAST_INSERT_ID();

-- 2. Create tiers
INSERT INTO approval_tiers (policy_id, tier_number, tier_name, min_amount, max_amount) VALUES
(@policy_id, 1, 'Small Loan', 0, 999999.99),
(@policy_id, 2, 'Medium Loan', 1000000, 4999999.99),
(@policy_id, 3, 'Large Loan', 5000000, 9999999.99),
(@policy_id, 4, 'Exceptional Loan', 10000000, NULL);

-- 3. Create tier slots

-- Tier 1: 1 approver (Chairman OR Vice Chairman)
INSERT INTO approval_tier_slots (tier_id, slot_number, slot_type, slot_group, required_role, display_label) VALUES
((SELECT id FROM approval_tiers WHERE policy_id = @policy_id AND tier_number = 1), 1, 'alternative', 1, 'chairman', 'Chairman OR Vice Chairman'),
((SELECT id FROM approval_tiers WHERE policy_id = @policy_id AND tier_number = 1), 1, 'alternative', 1, 'vice_chairman', 'Chairman OR Vice Chairman');

-- Tier 2: 2 approvers (Chairman + Vice/Secretary)
SET @tier2_id = (SELECT id FROM approval_tiers WHERE policy_id = @policy_id AND tier_number = 2);
INSERT INTO approval_tier_slots (tier_id, slot_number, slot_type, slot_group, required_role, display_label) VALUES
(@tier2_id, 1, 'mandatory', NULL, 'chairman', 'Chairman'),
(@tier2_id, 2, 'alternative', 2, 'vice_chairman', 'Vice Chairman OR Secretary'),
(@tier2_id, 2, 'alternative', 2, 'secretary', 'Vice Chairman OR Secretary');

-- Tier 3: 3 approvers (Chairman + Vice + Secretary)
SET @tier3_id = (SELECT id FROM approval_tiers WHERE policy_id = @policy_id AND tier_number = 3);
INSERT INTO approval_tier_slots (tier_id, slot_number, slot_type, slot_group, required_role, display_label) VALUES
(@tier3_id, 1, 'mandatory', NULL, 'chairman', 'Chairman'),
(@tier3_id, 2, 'mandatory', NULL, 'vice_chairman', 'Vice Chairman'),
(@tier3_id, 3, 'mandatory', NULL, 'secretary', 'Secretary');

-- Tier 4: 4 approvers (Chairman + Vice + Secretary + Treasurer)
SET @tier4_id = (SELECT id FROM approval_tiers WHERE policy_id = @policy_id AND tier_number = 4);
INSERT INTO approval_tier_slots (tier_id, slot_number, slot_type, slot_group, required_role, display_label) VALUES
(@tier4_id, 1, 'mandatory', NULL, 'chairman', 'Chairman'),
(@tier4_id, 2, 'mandatory', NULL, 'vice_chairman', 'Vice Chairman'),
(@tier4_id, 3, 'mandatory', NULL, 'secretary', 'Secretary'),
(@tier4_id, 4, 'mandatory', NULL, 'treasurer', 'Treasurer');
```

---

## ✅ Issues Resolved

### Issue #1: Missing `required_roles` column
✅ **FIXED:** Removed JSON approach entirely. Slots now explicitly define required roles.

### Issue #2: Cannot represent "Chairman + (Vice OR Secretary)"
✅ **FIXED:** `approval_tier_slots` with `slot_type='alternative'` and `slot_group` handles this perfectly.

### Issue #3: JSON-based approval logic not enforceable
✅ **FIXED:** Relational slot structure is fully enforceable.

### Issue #4: CASCADE DELETE destroys audit trail
✅ **FIXED:** `approval_actions` uses `ON DELETE RESTRICT` — cannot be deleted.

### Issue #5: No re-submission support
✅ **FIXED:** `round_number` allows multiple submission attempts. Each round is independent.

### Issue #6: `current_approvals` as authoritative truth
✅ **FIXED:** Removed counter entirely. Status derived from `slot_status` and `approval_actions`.

### Issue #7: "Same role = same approval" ambiguity
✅ **FIXED:** Slots track which user satisfied them. Same role cannot satisfy same slot twice.

### Issue #8: Admin role bypass
✅ **FIXED:** Approval logic checks `required_role` from slots. Admin not in the role list unless explicitly configured.

### Issue #9: "Parallel system" competing
✅ **FIXED:** New approval engine is THE authoritative source. Old fields kept for compatibility only.

### Issue #10: No approval snapshots
✅ **FIXED:** `transaction_approval_rounds` captures policy_id, tier_id, snapshot_amount at submission. Immutable.

### Issue #11: Amount-at-submission protection
✅ **FIXED:** `update()` logic detects material changes, cancels round, returns to draft.

### Issue #12: No distinction between required vs action
✅ **FIXED:** `transaction_approval_slot_instances` (required) separate from `approval_actions` (actions taken).

### Issue #13: UI shows numbers not requirements
✅ **FIXED:** UI mockups show slots with labels ("Chairman", "Vice OR Secretary").

### Issue #14: No policy versioning
✅ **FIXED:** `approval_policies.policy_version` tracks versions. Each round references its policy version.

---

## 🚀 Implementation Roadmap

### Stage 1: Database Schema (REVIEW ONLY — NO WRITES YET)
- [ ] Review proposed schema with team
- [ ] Validate slot structure handles all tier cases
- [ ] Confirm immutability requirements
- [ ] Approve schema design

### Stage 2: Seed Configuration (TEST DATABASE ONLY)
- [ ] Create policy version 1
- [ ] Seed all loan tiers (1-4)
- [ ] Seed investment tiers
- [ ] Seed voucher tiers
- [ ] Test: Query tiers, verify logic

### Stage 3: Model Layer (LOANS ONLY)
- [ ] Implement submission logic
- [ ] Implement approval logic
- [ ] Implement rejection logic
- [ ] Implement material change detection
- [ ] Test: Full workflow (submit → approve → disburse)

### Stage 4: UI (LOANS ONLY)
- [ ] Approval status display
- [ ] Approval queue
- [ ] Approve/reject actions
- [ ] Test: All tiers, all roles

### Stage 5: Production Rollout (LOANS ONLY)
- [ ] Deploy to production
- [ ] Monitor first 10 loans
- [ ] Fix any issues
- [ ] Document lessons learned

### Stage 6+: Other Modules
- [ ] Investments
- [ ] Internal Vouchers
- [ ] Member Adjustments
- [ ] Opening Balances
- [ ] Withdrawals
- [ ] Closures

---

## ⚠️ Critical Rules Before Coding

1. ✅ **NO production database writes** until schema review complete
2. ✅ **NO multi-module rollout** — loans first, others later
3. ✅ **NO admin bypass** — role-based approval only
4. ✅ **NO approval action deletion** — immutable audit trail
5. ✅ **NO policy changes affecting pending approvals** — snapshots mandatory
6. ✅ **NO material changes without approval invalidation** — tier integrity mandatory

---

**Document Version:** 2.0 (CORRECTED ARCHITECTURE)  
**Last Updated:** 2026-09-14  
**Status:** DESIGN COMPLETE — Awaiting review before implementation  
**Next Step:** Review this design, then implement Stage 1 (schema) in test environment only
