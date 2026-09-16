# Empower Investment Club — Approval Architecture Audit
**Purpose:** Document existing approval infrastructure before implementing multi-level approvals  
**Date:** 2026-09-14  
**Status:** Pre-implementation audit

---

## 🎯 Executive Summary

### Current State
The system has a **single-approval workflow** implemented consistently across all approval-enabled modules:
- ✅ Maker-checker separation enforced
- ✅ Status-based workflow (draft → pending_approval → approved → posted)
- ✅ Single `approved_by` field (tracks ONE approver)
- ✅ Rejection workflow exists
- ✅ Audit trails present

### Gap Analysis
To implement multi-level approvals, we need:
- ❌ **New table:** `transaction_approvals` (tracks multiple approval actions)
- ❌ **New table:** `approval_requirements` (defines tier rules)
- ❌ **Modified logic:** Check approval count vs required count
- ❌ **New UI:** Approval dashboard showing 1/3, 2/3, etc.
- ❌ **Modified notifications:** Notify all required approvers

---

## 📊 Current Database Schema Analysis

### Tables with Approval Workflows

#### 1. **loans**
```sql
Current approval fields:
├─ status ENUM('draft','pending_approval','approved','rejected',...) 
├─ approved_by INT UNSIGNED NULL
├─ approved_at TIMESTAMP NULL
├─ rejected_by INT UNSIGNED NULL
├─ rejected_at TIMESTAMP NULL
├─ rejection_reason TEXT NULL
└─ recorded_by INT UNSIGNED NOT NULL (maker)

Current workflow:
draft → pending_approval → approved → (disbursed → active)
                        └→ rejected → back to draft
```

**Observation:** Single approver tracked. Need to extend for multi-approval.

---

#### 2. **investments**
```sql
Current approval fields:
├─ status ENUM('draft','pending_approval','approved','rejected','posted',...)
├─ recorded_by INT UNSIGNED NOT NULL
├─ submitted_at TIMESTAMP NULL
├─ approved_by INT UNSIGNED NULL
├─ approved_at TIMESTAMP NULL
├─ rejected_by INT UNSIGNED NULL
├─ rejected_at TIMESTAMP NULL
├─ rejection_reason TEXT NULL
├─ posted_at TIMESTAMP NULL
└─ journal_entry_id INT UNSIGNED NULL

Current workflow:
draft → pending_approval → approved → posted
                        └→ rejected → back to draft
```

**Observation:** Identical pattern to loans. Same extension needed.

---

#### 3. **internal_vouchers**
```sql
Current approval fields:
├─ status ENUM('draft','pending_approval','approved','rejected','posted')
├─ recorded_by INT UNSIGNED NOT NULL
├─ submitted_at TIMESTAMP NULL
├─ approved_by INT UNSIGNED NULL
├─ approved_at TIMESTAMP NULL
├─ rejected_by INT UNSIGNED NULL
├─ rejected_at TIMESTAMP NULL
├─ rejection_reason TEXT NULL
├─ posted_at TIMESTAMP NULL
└─ journal_entry_id INT UNSIGNED NULL

Current workflow:
draft → pending_approval → approved → posted
                        └→ rejected → back to draft
```

**Observation:** Consistent pattern.

---

#### 4. **member_account_adjustments**
```sql
Current approval fields:
├─ status ENUM('draft','pending_approval','approved','rejected','posted')
├─ recorded_by INT UNSIGNED NOT NULL
├─ submitted_at TIMESTAMP NULL
├─ approved_by INT UNSIGNED NULL
├─ approved_at TIMESTAMP NULL
├─ rejected_by INT UNSIGNED NULL
├─ rejected_at TIMESTAMP NULL
├─ rejection_reason TEXT NULL
├─ posted_at TIMESTAMP NULL
└─ journal_entry_id INT UNSIGNED NULL

Current workflow:
draft → pending_approval → approved → posted → (can be reversed)
                        └→ rejected → back to draft
```

**Observation:** Consistent pattern + reversal capability.

---

#### 5. **opening_balance_batches**
```sql
Current approval fields:
├─ status ENUM('draft','pending_approval','approved','posted','rejected')
├─ entered_by INT UNSIGNED NOT NULL
├─ submitted_at TIMESTAMP NULL
├─ approved_by INT UNSIGNED NULL
├─ approved_at TIMESTAMP NULL
├─ rejected_by INT UNSIGNED NULL
├─ rejected_at TIMESTAMP NULL
├─ rejection_reason TEXT NULL
├─ posted_at TIMESTAMP NULL
└─ journal_entry_id INT UNSIGNED NULL

Current workflow:
draft → pending_approval → approved → posted
                        └→ rejected → back to draft
```

**Observation:** Consistent pattern.

---

#### 6. **savings_account_closures**
```sql
Current approval fields:
├─ status ENUM('pending','approved','rejected','cancelled','paid')
├─ requested_by INT UNSIGNED NOT NULL
├─ requested_at TIMESTAMP NOT NULL
├─ approved_by INT UNSIGNED NULL
├─ approved_at TIMESTAMP NULL
├─ rejected_by INT UNSIGNED NULL
├─ rejected_at TIMESTAMP NULL
└─ rejection_reason TEXT NULL

Current workflow:
pending → approved → paid
       └→ rejected → (can re-request)
```

**Observation:** Slightly different naming (requested_by vs recorded_by) but same pattern.

---

### Pattern Consistency

**All approval-enabled tables follow the same structure:**
```sql
-- Maker fields
recorded_by INT UNSIGNED NOT NULL
submitted_at TIMESTAMP NULL

-- Single approver fields
approved_by INT UNSIGNED NULL
approved_at TIMESTAMP NULL

-- Rejection fields
rejected_by INT UNSIGNED NULL
rejected_at TIMESTAMP NULL
rejection_reason TEXT NULL

-- Status field
status ENUM('draft','pending_approval','approved','rejected','posted',...)

-- Optional posting fields
posted_at TIMESTAMP NULL
journal_entry_id INT UNSIGNED NULL
```

**This consistency is GOOD — we can extend them uniformly.**

---

## 🔧 Current Model Layer Analysis

### Approval Method Pattern (All Models)

All models implement identical `approve()` logic:

```php
public function approve(int $id, int $userId): void
{
    // 1. Fetch record
    $record = $this->find($id);
    if (!$record) {
        throw new InvalidArgumentException("Record does not exist.");
    }
    
    // 2. Check status
    if ($record['status'] !== 'pending_approval') {
        throw new InvalidArgumentException("Only pending records can be approved.");
    }
    
    // 3. Maker-checker separation
    if ((int)$record['recorded_by'] === $userId) {
        throw new InvalidArgumentException('Cannot approve your own transaction.');
    }
    
    // 4. Update single approver
    $this->db->prepare(
        "UPDATE table SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?"
    )->execute([$userId, $id]);
    
    // 5. Audit trail
    $this->writeAudit($userId, 'approved', $id, ['status' => 'approved']);
}
```

**Key Observations:**
1. ✅ Maker-checker enforced (recorded_by ≠ approved_by)
2. ✅ Status validation present
3. ❌ Only ONE approver tracked
4. ❌ No concept of "required approval count"
5. ❌ Status immediately becomes 'approved' after first approval

---

### Rejection Method Pattern

```php
public function reject(int $id, int $userId, string $reason): void
{
    // 1. Fetch record
    $record = $this->find($id);
    
    // 2. Check status
    if ($record['status'] !== 'pending_approval') {
        throw new InvalidArgumentException("Only pending records can be rejected.");
    }
    
    // 3. Update rejection
    $this->db->prepare(
        "UPDATE table SET status = 'rejected', rejected_by = ?, rejected_at = NOW(), rejection_reason = ? WHERE id = ?"
    )->execute([$userId, $reason, $id]);
    
    // 4. Audit trail
    $this->writeAudit($userId, 'rejected', $id, ['status' => 'rejected', 'reason' => $reason]);
}
```

**Key Observations:**
1. ✅ Single rejection immediately marks entire transaction rejected
2. ✅ Reason required
3. ✅ Audit trail present
4. ✅ Returns to 'draft' status (users can re-edit and resubmit)

**This behavior is CORRECT for multi-approval — one rejection = entire transaction rejected.**

---

### Submit Method Pattern

```php
public function submit(int $id, int $userId): void
{
    // 1. Fetch record
    $record = $this->find($id);
    
    // 2. Check status
    if ($record['status'] !== 'draft') {
        throw new InvalidArgumentException("Only draft records can be submitted.");
    }
    
    // 3. Validate business rules (amounts balance, required fields, etc.)
    $this->validate($record);
    
    // 4. Update status
    $this->db->prepare(
        "UPDATE table SET status = 'pending_approval', submitted_at = NOW() WHERE id = ?"
    )->execute([$id]);
    
    // 5. Audit trail
    $this->writeAudit($userId, 'submitted', $id, ['status' => 'pending_approval']);
    
    // 6. Send notifications (to approvers)
    $this->notifyApprovers($id);
}
```

**Key Observations:**
1. ✅ Only drafts can be submitted
2. ✅ Business validation before submission
3. ✅ Notification system exists
4. ❌ Notifies "approvers" generically — need to notify SPECIFIC required approvers based on tier

---

## 🎯 Controller Layer Analysis

### Access Control Pattern

Controllers use role-based access control:

```php
// Example from LoanController
protected function requireApproverAccess(): void
{
    Session::requireAuth();
    if (!Session::hasRole(['admin', 'chairman', 'vice_chairman'])) {
        throw new UnauthorizedException('Only Chairman or Vice Chairman can approve loans.');
    }
}

public function approve(): void
{
    $this->requireApproverAccess(); // Check role first
    
    $loanId = (int)($_POST['loan_id'] ?? 0);
    $userId = Session::getUserId();
    
    try {
        $this->model->approve($loanId, $userId);
        Response::json(['success' => true, 'message' => 'Loan approved successfully']);
    } catch (Exception $e) {
        Response::json(['success' => false, 'message' => $e->getMessage()], 400);
    }
}
```

**Key Observations:**
1. ✅ Role-based access control enforced
2. ✅ Roles hardcoded in trait/controller
3. ❌ No concept of "approver 1 vs approver 2" — all approvers equivalent
4. ❌ No concept of "this specific loan needs Chairman + Vice Chairman" — just "any approver"

---

### Current Approver Roles (by Transaction Type)

| Transaction Type | Who Can Approve (Current) |
|-----------------|--------------------------|
| **Loans** | Chairman, Vice Chairman |
| **Loan Applications** | Chairman, Vice Chairman, Secretary |
| **Internal Vouchers** | Chairman ONLY |
| **Investments** | Chairman, Vice Chairman, Secretary |
| **Member Adjustments** | Chairman, Vice Chairman |
| **Opening Balances** | Chairman, Vice Chairman |
| **FD Closures** | Admin, Treasurer |
| **Savings Closures** | Admin, Treasurer |

**Observation:** Role checks are transaction-specific but don't consider:
- Transaction amount
- How many approvers required
- Which specific approvers required

---

## 🚧 What Needs to Change

### 1. **Database Schema Changes**

#### New Table: `approval_requirements`
```sql
CREATE TABLE `approval_requirements` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `transaction_type` ENUM('loan','investment','internal_voucher','member_adjustment','opening_balance','withdrawal','closure') NOT NULL,
    `tier` INT NOT NULL COMMENT '1, 2, 3, 4',
    `min_amount` DECIMAL(15,2) NOT NULL,
    `max_amount` DECIMAL(15,2) NULL COMMENT 'NULL means unlimited',
    `approvals_required` INT NOT NULL COMMENT 'Number of approvals needed',
    `required_roles` JSON NOT NULL COMMENT '["chairman","vice_chairman"] or ["chairman","vice_chairman","secretary"]',
    `special_rule` VARCHAR(50) NULL COMMENT 'e.g. officer_loan, large_voucher',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_approval_req` (`transaction_type`, `tier`),
    KEY `idx_approval_type` (`transaction_type`)
);

-- Example seed data
INSERT INTO `approval_requirements` (`transaction_type`, `tier`, `min_amount`, `max_amount`, `approvals_required`, `required_roles`) VALUES
('loan', 1, 0, 999999.99, 1, '["chairman","vice_chairman"]'),
('loan', 2, 1000000, 4999999.99, 2, '["chairman","vice_chairman","secretary"]'),
('loan', 3, 5000000, 9999999.99, 3, '["chairman","vice_chairman","secretary"]'),
('loan', 4, 10000000, NULL, 4, '["chairman","vice_chairman","secretary","treasurer"]');
```

---

#### New Table: `transaction_approvals`
```sql
CREATE TABLE `transaction_approvals` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `transaction_type` ENUM('loan','investment','internal_voucher','member_adjustment','opening_balance','withdrawal','closure') NOT NULL,
    `transaction_id` INT UNSIGNED NOT NULL,
    `tier` INT NOT NULL,
    `required_approvals` INT NOT NULL COMMENT 'How many approvals needed',
    `current_approvals` INT NOT NULL DEFAULT 0 COMMENT 'How many approvals received',
    `approval_status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `submitted_by` INT UNSIGNED NOT NULL,
    `submitted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_trans_approval` (`transaction_type`, `transaction_id`),
    KEY `idx_approval_status` (`approval_status`),
    KEY `idx_trans_type_id` (`transaction_type`, `transaction_id`),
    CONSTRAINT `fk_trans_appr_submitted_by` FOREIGN KEY (`submitted_by`) REFERENCES `users`(`id`)
);
```

---

#### New Table: `approval_actions`
```sql
CREATE TABLE `approval_actions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `transaction_approval_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `user_role` VARCHAR(50) NOT NULL COMMENT 'chairman, vice_chairman, secretary, treasurer at time of approval',
    `action` ENUM('approved','rejected') NOT NULL,
    `action_reason` TEXT NULL COMMENT 'Optional for approve, required for reject',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_appr_action_trans` (`transaction_approval_id`),
    KEY `idx_appr_action_user` (`user_id`),
    CONSTRAINT `fk_appr_action_trans` FOREIGN KEY (`transaction_approval_id`) REFERENCES `transaction_approvals`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_appr_action_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
);
```

---

### 2. **Modified Model Methods**

#### New: `determineApprovalTier()`
```php
/**
 * Determine which approval tier applies based on amount and transaction type
 */
private function determineApprovalTier(string $transactionType, float $amount): array
{
    $stmt = $this->db->prepare("
        SELECT * FROM approval_requirements 
        WHERE transaction_type = ? 
        AND (min_amount <= ? AND (max_amount IS NULL OR max_amount >= ?))
        AND is_active = 1
        ORDER BY tier DESC LIMIT 1
    ");
    $stmt->execute([$transactionType, $amount, $amount]);
    $tier = $stmt->fetch();
    
    if (!$tier) {
        throw new RuntimeException("No approval tier found for {$transactionType} amount {$amount}");
    }
    
    return $tier;
}
```

---

#### Modified: `submit()`
```php
public function submit(int $id, int $userId): void
{
    $record = $this->find($id);
    if (!$record) {
        throw new InvalidArgumentException("Record does not exist.");
    }
    if ($record['status'] !== 'draft') {
        throw new InvalidArgumentException("Only draft records can be submitted.");
    }
    
    // Validate business rules
    $this->validate($record);
    
    // Determine approval tier
    $tier = $this->determineApprovalTier('loan', $record['loan_amount']);
    
    $this->db->beginTransaction();
    try {
        // Update record status
        $this->db->prepare(
            "UPDATE loans SET status = 'pending_approval', submitted_at = NOW() WHERE id = ?"
        )->execute([$id]);
        
        // Create approval tracking record
        $this->db->prepare("
            INSERT INTO transaction_approvals 
            (transaction_type, transaction_id, tier, required_approvals, submitted_by) 
            VALUES (?, ?, ?, ?, ?)
        ")->execute(['loan', $id, $tier['tier'], $tier['approvals_required'], $userId]);
        
        // Audit trail
        $this->writeAudit($userId, 'submitted', $id, [
            'status' => 'pending_approval',
            'tier' => $tier['tier'],
            'required_approvals' => $tier['approvals_required']
        ]);
        
        // Notify all required approvers
        $requiredRoles = json_decode($tier['required_roles'], true);
        $this->notifyApprovers($id, $requiredRoles);
        
        $this->db->commit();
    } catch (Throwable $e) {
        $this->db->rollBack();
        throw $e;
    }
}
```

---

#### Modified: `approve()`
```php
public function approve(int $id, int $userId, string $userRole): void
{
    $record = $this->find($id);
    if (!$record) {
        throw new InvalidArgumentException("Record does not exist.");
    }
    if ($record['status'] !== 'pending_approval') {
        throw new InvalidArgumentException("Only pending records can be approved.");
    }
    if ((int)$record['recorded_by'] === $userId) {
        throw new InvalidArgumentException('Cannot approve your own transaction.');
    }
    
    // Get approval tracking record
    $approvalStmt = $this->db->prepare("
        SELECT * FROM transaction_approvals 
        WHERE transaction_type = 'loan' AND transaction_id = ? AND approval_status = 'pending'
    ");
    $approvalStmt->execute([$id]);
    $approval = $approvalStmt->fetch();
    
    if (!$approval) {
        throw new InvalidArgumentException("No pending approval found for this loan.");
    }
    
    // Check if user already approved
    $existingStmt = $this->db->prepare("
        SELECT COUNT(*) FROM approval_actions 
        WHERE transaction_approval_id = ? AND user_id = ?
    ");
    $existingStmt->execute([$approval['id'], $userId]);
    if ($existingStmt->fetchColumn() > 0) {
        throw new InvalidArgumentException('You have already approved this transaction.');
    }
    
    // Check if user's role is required for this tier
    $requiredRoles = json_decode($approval['required_roles'], true);
    if (!in_array($userRole, $requiredRoles, true)) {
        throw new InvalidArgumentException("Your role ({$userRole}) is not authorized to approve this tier.");
    }
    
    $this->db->beginTransaction();
    try {
        // Record this approval action
        $this->db->prepare("
            INSERT INTO approval_actions 
            (transaction_approval_id, user_id, user_role, action) 
            VALUES (?, ?, ?, 'approved')
        ")->execute([$approval['id'], $userId, $userRole]);
        
        // Increment approval count
        $this->db->prepare("
            UPDATE transaction_approvals 
            SET current_approvals = current_approvals + 1 
            WHERE id = ?
        ")->execute([$approval['id']]);
        
        // Check if all required approvals received
        $updatedStmt = $this->db->prepare("
            SELECT * FROM transaction_approvals WHERE id = ?
        ");
        $updatedStmt->execute([$approval['id']]);
        $updated = $updatedStmt->fetch();
        
        if ($updated['current_approvals'] >= $updated['required_approvals']) {
            // All approvals received — mark as fully approved
            $this->db->prepare("
                UPDATE transaction_approvals 
                SET approval_status = 'approved', completed_at = NOW() 
                WHERE id = ?
            ")->execute([$approval['id']]);
            
            // Update loan record (keep last approver for backward compatibility)
            $this->db->prepare("
                UPDATE loans 
                SET status = 'approved', approved_by = ?, approved_at = NOW() 
                WHERE id = ?
            ")->execute([$userId, $id]);
            
            // Audit: Fully approved
            $this->writeAudit($userId, 'fully_approved', $id, [
                'status' => 'approved',
                'approvals_received' => $updated['current_approvals']
            ]);
        } else {
            // Partial approval — still pending
            $remaining = $updated['required_approvals'] - $updated['current_approvals'];
            $this->writeAudit($userId, 'partial_approved', $id, [
                'approvals_received' => $updated['current_approvals'],
                'approvals_remaining' => $remaining
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

#### Modified: `reject()`
```php
public function reject(int $id, int $userId, string $userRole, string $reason): void
{
    $record = $this->find($id);
    if (!$record) {
        throw new InvalidArgumentException("Record does not exist.");
    }
    if ($record['status'] !== 'pending_approval') {
        throw new InvalidArgumentException("Only pending records can be rejected.");
    }
    
    // Get approval tracking record
    $approvalStmt = $this->db->prepare("
        SELECT * FROM transaction_approvals 
        WHERE transaction_type = 'loan' AND transaction_id = ? AND approval_status = 'pending'
    ");
    $approvalStmt->execute([$id]);
    $approval = $approvalStmt->fetch();
    
    if (!$approval) {
        throw new InvalidArgumentException("No pending approval found.");
    }
    
    $this->db->beginTransaction();
    try {
        // Record rejection action
        $this->db->prepare("
            INSERT INTO approval_actions 
            (transaction_approval_id, user_id, user_role, action, action_reason) 
            VALUES (?, ?, ?, 'rejected', ?)
        ")->execute([$approval['id'], $userId, $userRole, $reason]);
        
        // Mark approval tracking as rejected
        $this->db->prepare("
            UPDATE transaction_approvals 
            SET approval_status = 'rejected', completed_at = NOW() 
            WHERE id = ?
        ")->execute([$approval['id']]);
        
        // Update loan record
        $this->db->prepare("
            UPDATE loans 
            SET status = 'rejected', rejected_by = ?, rejected_at = NOW(), rejection_reason = ? 
            WHERE id = ?
        ")->execute([$userId, $reason, $id]);
        
        // Audit trail
        $this->writeAudit($userId, 'rejected', $id, [
            'status' => 'rejected',
            'reason' => $reason,
            'previous_approvals' => $approval['current_approvals']
        ]);
        
        $this->db->commit();
    } catch (Throwable $e) {
        $this->db->rollBack();
        throw $e;
    }
}
```

---

### 3. **UI Changes Needed**

#### Approval Dashboard
```php
// Show real-time approval status
<div class="approval-status">
    <h4>UGX 7,000,000 LOAN — LNS-001234</h4>
    <p>Required: 3 approvals</p>
    <ul>
        <li>Chairman: <span class="approved">✓ APPROVED</span> (2026-09-14 10:30)</li>
        <li>Vice Chairman: <span class="approved">✓ APPROVED</span> (2026-09-14 11:15)</li>
        <li>Secretary: <span class="pending">⏳ PENDING</span></li>
    </ul>
    <p>Status: PENDING (2/3 approvals)</p>
    <p>Disbursement: <span class="locked">🔒 LOCKED</span></p>
</div>
```

#### Approval Queue
```php
// Show each approver's pending items
<table class="approval-queue">
    <thead>
        <tr>
            <th>Transaction</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>Loan LNS-001234</td>
            <td>UGX 7,000,000</td>
            <td>
                Tier 3: Needs 3 approvals<br>
                Current: 2/3<br>
                Chairman ✓, Vice Chairman ✓, Secretary ⏳
            </td>
            <td>
                <button>Approve</button>
                <button>Reject</button>
            </td>
        </tr>
    </tbody>
</table>
```

---

## ✅ Implementation Checklist

### Phase 1: Database (2-3 hours)
- [ ] Create `approval_requirements` table
- [ ] Create `transaction_approvals` table
- [ ] Create `approval_actions` table
- [ ] Seed `approval_requirements` with finalized tiers
- [ ] Test: Insert, update, query approval records

### Phase 2: Model Layer (8-10 hours)
- [ ] Add `determineApprovalTier()` to each model
- [ ] Modify `submit()` to create approval tracking
- [ ] Modify `approve()` to handle multi-approval
- [ ] Modify `reject()` to preserve previous approvals
- [ ] Add `getApprovalStatus()` method (shows who approved, who pending)
- [ ] Add `getPendingApprovers()` method
- [ ] Test: Submit, approve (partial), approve (full), reject

### Phase 3: Controller Layer (4-6 hours)
- [ ] Modify approval controllers to pass user role
- [ ] Add approval dashboard route
- [ ] Add approval status API endpoint
- [ ] Test: API returns correct approval status

### Phase 4: UI Layer (10-12 hours)
- [ ] Create approval dashboard view
- [ ] Update existing approval pages (show multi-status)
- [ ] Add approval progress indicators (1/3, 2/3, etc.)
- [ ] Update notifications (notify all required approvers)
- [ ] Test: Dashboard shows correct status, approvals work

### Phase 5: Testing (6-8 hours)
- [ ] Test Tier 1 (1 approver)
- [ ] Test Tier 2 (2 approvers)
- [ ] Test Tier 3 (3 approvers)
- [ ] Test Tier 4 (4 approvers)
- [ ] Test officer loan (recipient excluded)
- [ ] Test rejection (immediate rejection)
- [ ] Test maker-checker (maker cannot approve)
- [ ] Test double-approval prevention (same person cannot approve twice)
- [ ] Test large vouchers (>5M requires 4 approvers)
- [ ] Test edge cases

### Phase 6: Documentation & Training (3-4 hours)
- [ ] Update user manual
- [ ] Create training materials
- [ ] Document approval workflows
- [ ] Create troubleshooting guide

---

## 📊 Estimated Implementation Time

| Phase | Time | Cumulative |
|-------|------|------------|
| Database | 2-3 hours | 3 hours |
| Model Layer | 8-10 hours | 13 hours |
| Controller Layer | 4-6 hours | 19 hours |
| UI Layer | 10-12 hours | 31 hours |
| Testing | 6-8 hours | 39 hours |
| Documentation | 3-4 hours | **43 hours** |

**Total: ~43 hours (approximately 5-6 working days for one developer)**

---

## ⚠️ Risks & Mitigation

### Risk 1: Breaking Existing Workflows
**Mitigation:** Keep existing `approved_by` field for backward compatibility. Multi-approval system runs in parallel.

### Risk 2: Performance Impact
**Mitigation:** Add indexes on `transaction_approvals` and `approval_actions`. Use efficient queries.

### Risk 3: User Confusion
**Mitigation:** Clear UI showing approval progress. Training materials. Gradual rollout.

### Risk 4: Data Migration
**Mitigation:** System will apply new rules to NEW transactions only. Existing approved transactions unchanged.

---

## ✅ Summary

### Current System Strengths
- ✅ Consistent approval pattern across all modules
- ✅ Maker-checker separation enforced
- ✅ Status-based workflow solid
- ✅ Audit trails present
- ✅ Rejection workflow exists

### What We Need to Add
- ❌ Multi-approval tracking (new tables)
- ❌ Tier determination logic (amount-based)
- ❌ Approval counting logic (1/3, 2/3, etc.)
- ❌ Parallel approval UI (dashboard)
- ❌ Role-specific approval validation

### Implementation Approach
1. **Extend, don't replace:** Keep existing fields for backward compatibility
2. **Parallel system:** Multi-approval runs alongside single-approval
3. **Gradual rollout:** Apply to new transactions, not retroactive
4. **Test thoroughly:** All tiers, all edge cases

---

**Document Version:** 1.0  
**Last Updated:** 2026-09-14  
**Status:** Architecture audit complete — ready for implementation
