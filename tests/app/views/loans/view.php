<?php
/**
 * Full-width "financial record" layout (2026-09), matching the pattern
 * already applied to Expenses and Repayments -- the previous col-lg-3/
 * col-lg-9 split left a short left sidebar (Member card, Loan Info,
 * Accounting, Actions) that ran out of content long before the right
 * column's 30+ row schedule finished, producing a large dead-space
 * gutter on scroll. All of that content now stacks full width instead.
 *
 * The separate "Payment Sheet" (full schedule) and "Payment History"
 * (actual repayment receipts) tables -- previously two long tables
 * stacked one after another with overlapping date/amount/balance
 * columns -- are now two tabs of the same card, since they're two views
 * of the same underlying cashflow (planned vs actual), not two
 * different things. No query/logic changes anywhere in this file --
 * every existing conditional, form, and modal below is unchanged from
 * before, only relocated.
 */
$base = APP_URL . '/index.php';
$currentUserId = (int)Session::get('user_id');

// Stage 8: a loan under review (pending_approval/approved) is read-only
// even to admin/treasurer/loans_officer -- matches LoanController::edit()'s
// own guard exactly. Every other status keeps today's exact behavior.
// Stage 13-F3: a posted (disbursement journal-linked) loan is immutable at
// the model layer regardless of this UI check -- this is defense-in-depth
// only, so Edit/Delete don't invite an action the backend will now reject.
$isPostedLoan = !empty($loan['journal_entry_id']);
$canEditLoan = Session::hasRole(['admin', 'treasurer', 'loans_officer'])
    && !in_array($loan['status'], ['pending_approval', 'approved'], true)
    && !$isPostedLoan;
$canDeleteLoan = Session::hasRole(['admin']) && !$isPostedLoan;

// Stage 8 workflow flags -- match LoanModel::submit()/approve()/reject()/disburse()'s own status checks exactly.
$canSubmitLoan   = Session::hasRole(['admin', 'treasurer', 'loans_officer'])
    && in_array($loan['status'], ['draft', 'rejected'], true);
// Stage 23: Vice Chairman added as deputy/alternate approver, matching
// LoanRoleAccessTrait::requireApproverAccess() exactly. Secretary is NOT
// included here -- Secretary's Stage 23 authority covers loan APPLICATION
// approval only (see loan-applications/view.php's own $canApprove), never
// loan approval/disbursement, which is what this flag gates.
$isApproverRole  = Session::hasRole(['admin', 'chairman', 'vice_chairman']);
$isOwnLoan       = (int)($loan['recorded_by'] ?? 0) === $currentUserId;
$canApproveLoan  = $isApproverRole && $loan['status'] === 'pending_approval' && !$isOwnLoan;
$canRejectLoan   = $isApproverRole && $loan['status'] === 'pending_approval';
$canDisburseLoan = Session::hasRole(['admin', 'chairman', 'vice_chairman', 'loans_officer']) && $loan['status'] === 'approved';
// Legacy accounting-retry button: only for loans that predate/sit outside
// the Stage 8 workflow (never 'approved' -- that must use Disburse instead;
// LoanController::postDisbursementAction() enforces this same rule server-side).
// Loans Officer role-refinement (2026-09): matches
// LoanController::requireDisbursementPostingAccess() exactly -- loans_officer
// deliberately excluded (posting the GL disbursement entry is an accounting
// consequence, not an operational edit).
$canRetryPosting = Session::hasRole(['admin', 'treasurer', 'chairman'])
    && $loan['status'] !== 'approved';

$isActive    = $loan['status'] === 'active';
$isOverdue   = $loan['status'] === 'overdue';
$isCompleted = $loan['status'] === 'completed';

$daysRemaining = $loan['due_date'] ? (int)(new DateTime())->diff(new DateTime($loan['due_date']))->days : 0;
if (new DateTime($loan['due_date']) < new DateTime()) $daysRemaining = -$daysRemaining;

// Calculate schedule stats
$paidCount = 0; $overdueCount = 0; $pendingCount = 0; $totalInterestPaid = 0;
$interestPhase = []; $recoveryPhase = [];
foreach ($installments as $inst) {
    if ($inst['status'] === 'paid') $paidCount++;
    elseif ($inst['status'] === 'overdue') $overdueCount++;
    else $pendingCount++;
    $totalInterestPaid += (float)($inst['amount_paid'] ?? 0);
    if (($inst['payment_type'] ?? '') === 'interest_only') $interestPhase[] = $inst;
    else $recoveryPhase[] = $inst;
}
$totalScheduled = array_sum(array_column($installments, 'amount_due'));
$totalPaidSchedule = array_sum(array_column($installments, 'amount_paid'));
$progressPct = $totalScheduled > 0 ? min(100, round(($totalPaidSchedule / $totalScheduled) * 100)) : 0;

// Next payment due
$nextDue = null;
foreach ($installments as $inst) {
    if (in_array($inst['status'], ['pending', 'overdue', 'partial'])) { $nextDue = $inst; break; }
}

function lBadge(string $s): string {
    return match($s){
        'active'=>'bg-success','completed'=>'bg-primary','overdue'=>'bg-danger',
        'draft'=>'bg-secondary','pending_approval'=>'bg-warning text-dark',
        'approved'=>'bg-info text-dark','rejected'=>'bg-danger',
        'defaulted'=>'bg-danger',
        default=>'bg-secondary'
    };
}
function lStatusLabel(string $s): string {
    return match($s){
        'pending_approval'=>'Pending Approval',
        default=>ucfirst($s),
    };
}

ob_start(); ?>
<a href="<?= $base ?>?page=loan-statement&id=<?= $loan['id'] ?>" class="btn btn-primary btn-sm fw-semibold" target="_blank">
    <i class="bi bi-file-earmark-text me-1"></i>Loan Statement
</a>
<a href="<?= $base ?>?page=loan-card&id=<?= $loan['id'] ?>" class="btn btn-warning text-white btn-sm fw-semibold" target="_blank">
    <i class="bi bi-card-checklist me-1"></i>Repayment Card
</a>
<?php if (!$isCompleted): ?>
<a href="<?= $base ?>?page=repayment-add&loan_id=<?= $loan['id'] ?>" class="btn btn-success btn-sm fw-semibold">
    <i class="bi bi-plus-circle me-1"></i>Record Payment
</a>
<?php endif; ?>
<?php if ($canEditLoan): ?>
<a href="<?= $base ?>?page=loan-edit&id=<?= $loan['id'] ?>" class="btn btn-warning text-white btn-sm">
    <i class="bi bi-pencil-fill me-1"></i>Edit
</a>
<?php if (!$isCompleted): ?>
<form method="POST" action="<?= $base ?>?page=loan-complete" style="display:inline"
      onsubmit="return confirm('Mark this loan as completed?')">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="id" value="<?= $loan['id'] ?>">
    <button type="submit" class="btn btn-success btn-sm">
        <i class="bi bi-check-circle-fill me-1"></i>Mark Completed
    </button>
</form>
<?php endif; ?>
<?php endif; ?>
<button onclick="shareWhatsApp()" class="btn btn-sm" style="background:#25D366;color:#fff;border:none;">
    <i class="bi bi-whatsapp me-1"></i>WhatsApp Schedule
</button>
<a href="<?= $base ?>?page=loan-schedule&id=<?= $loan['id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-printer me-1"></i>Print Schedule
</a>
<?php if ($canDeleteLoan): ?>
<button type="button" id="openDeleteBtn" class="btn btn-outline-danger btn-sm">
    <i class="bi bi-trash me-1"></i>Delete
</button>
<?php endif; ?>
<a href="<?= $base ?>?page=loans" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Back
</a>
<?php
$cta      = ob_get_clean();
$icon     = 'bi-bank2 text-warning';
$title    = htmlspecialchars($loan['loan_number']);
$subtitle = '<a href="' . $base . '?page=member-view&id=' . $loan['member_id'] . '" class="text-decoration-none">'
          . htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']) . '</a>'
          . ' &middot; ' . htmlspecialchars($loan['member_number'])
          . ' &middot; <a href="' . $base . '?page=loan-member&member_id=' . $loan['member_id'] . '" class="text-decoration-none">Member History</a>';
?>

<style>
.loan-stat { text-align:center; padding:.75rem .5rem; }
.loan-stat .stat-value { font-size:1.25rem; font-weight:700; line-height:1.2; }
.loan-stat .stat-label { font-size:.7rem; color:#6b7280; text-transform:uppercase; letter-spacing:.03em; margin-top:.2rem; }
.phase-header { background:#f8f9fa; padding:.5rem 1rem; font-size:.72rem; font-weight:700; text-transform:uppercase;
    letter-spacing:.05em; color:#6b7280; border-top:2px solid #e5e7f0; border-bottom:1px solid #e5e7f0; }
.phase-header .phase-badge { font-size:.65rem; padding:.2rem .5rem; border-radius:99px; margin-left:.5rem; }
.schedule-row { transition: background .15s; }
.schedule-row:hover { background:#f9fafb; }
.schedule-row.row-paid { background:#f0fdf4; }
.schedule-row.row-overdue { background:#fef2f2; }
.schedule-row.row-partial { background:#fffbeb; }
.next-due-card { border-left:4px solid var(--bs-warning); background:#fffbeb; }
.detail-grid { display:grid; grid-template-columns: repeat(3, 1fr); gap:1rem; }
.detail-item .detail-label { font-size:.68rem; color:#6b7280; text-transform:uppercase; letter-spacing:.03em; margin-bottom:.15rem; }
.detail-item .detail-value { font-size:.88rem; font-weight:600; color:#1f2937; }
@media (max-width:768px) { .detail-grid { grid-template-columns: repeat(2, 1fr); } }
</style>

<?php require VIEW_PATH . '/layouts/page-title.php'; ?>

<?php if (!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3 py-2">
    <i class="bi bi-check-circle-fill flex-shrink-0"></i>
    <div class="small"><?= htmlspecialchars($success) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-3 py-2">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
    <div class="small"><?= htmlspecialchars($error) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ═══════════════ TOP STATS BAR ═══════════════ -->
<div class="card mb-3">
    <div class="card-body p-0">
        <div class="row g-0 text-center">
            <div class="col border-end py-3">
                <div class="loan-stat">
                    <div class="stat-value" style="color:var(--brand-navy)">Shs <?= number_format($loan['loan_amount'],0) ?></div>
                    <div class="stat-label">Loan Amount</div>
                </div>
            </div>
            <div class="col border-end py-3">
                <div class="loan-stat">
                    <div class="stat-value text-danger">Shs <?= number_format($loan['outstanding'],0) ?></div>
                    <div class="stat-label">Outstanding</div>
                </div>
            </div>
            <div class="col border-end py-3">
                <div class="loan-stat">
                    <div class="stat-value text-success">Shs <?= number_format($totalPaid,0) ?></div>
                    <div class="stat-label">Total Paid</div>
                </div>
            </div>
            <div class="col border-end py-3">
                <div class="loan-stat">
                    <div class="stat-value" style="color:var(--brand-orange)">Shs <?= number_format($loan['total_payable'],0) ?></div>
                    <div class="stat-label">Total Payable</div>
                </div>
            </div>
            <div class="col py-3">
                <div class="loan-stat">
                    <div class="stat-value">
                        <span class="badge <?= lBadge($loan['status']) ?> px-3 py-2" style="font-size:.85rem">
                            <?= lStatusLabel($loan['status']) ?>
                        </span>
                    </div>
                    <div class="stat-label mt-1"><?= $loan['loan_period'] ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Key Facts -->
<div class="card mb-3">
    <div class="card-header py-2"><h6 class="mb-0 fw-semibold small">Loan Info</h6></div>
    <div class="card-body p-3">
        <div class="detail-grid">
            <div class="detail-item">
                <div class="detail-label">Interest Rate</div>
                <div class="detail-value">
                    <?php if (($loan['interest_mode'] ?? '') === 'fixed'): ?>
                        Shs <?= number_format((float)($loan['fixed_interest_amount'] ?? 0), 0) ?>/wk
                    <?php else: ?>
                        <?= number_format($loan['interest_rate'],1) ?>%/mo
                    <?php endif; ?>
                </div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Interest Total</div>
                <div class="detail-value">Shs <?= number_format($loan['interest_amount'],0) ?></div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Issue Date</div>
                <div class="detail-value"><?= date('d M Y', strtotime($loan['issue_date'])) ?></div>
            </div>
            <?php if (!empty($loan['disbursement_date'])): ?>
            <div class="detail-item">
                <div class="detail-label">Disbursed On</div>
                <div class="detail-value"><?= date('d M Y', strtotime($loan['disbursement_date'])) ?></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($loan['disbursement_method'])): ?>
            <div class="detail-item">
                <div class="detail-label">Funded Via</div>
                <div class="detail-value">
                    <?php
                    $icon = match($loan['disbursement_method']) {
                        'Cash'                          => 'bi-cash-coin text-success',
                        'MTN Mobile Money', 'Airtel Money' => 'bi-phone text-warning',
                        'Bank Transfer', 'Cheque'       => 'bi-bank text-primary',
                        default                         => 'bi-arrow-right-circle text-secondary',
                    };
                    ?>
                    <i class="bi <?= $icon ?> me-1"></i><?= htmlspecialchars($loan['disbursement_method']) ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="detail-item">
                <div class="detail-label">Due Date</div>
                <div class="detail-value <?= ($daysRemaining < 0 && !$isCompleted)?'text-danger':'' ?>">
                    <?= date('d M Y', strtotime($loan['due_date'])) ?>
                </div>
            </div>
            <?php if ($loan['purpose']): ?>
            <div class="detail-item">
                <div class="detail-label">Purpose</div>
                <div class="detail-value"><?= htmlspecialchars($loan['purpose']) ?></div>
            </div>
            <?php endif; ?>
            <div class="detail-item">
                <div class="detail-label">Recorded</div>
                <div class="detail-value"><?= date('d M Y', strtotime($loan['created_at'])) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Approval Workflow (Stage 8) -->
<?php if ($canSubmitLoan || $loan['status'] === 'pending_approval' || $canApproveLoan || $canRejectLoan || $canDisburseLoan): ?>
<div class="card mb-3">
    <div class="card-header py-2"><h6 class="mb-0 fw-semibold small">Approval Workflow</h6></div>
    <div class="card-body p-3" style="font-size:.78rem;">
        <?php if ($loan['status'] === 'pending_approval'): ?>
            <div class="text-muted mb-2">Submitted <?= $loan['submitted_at'] ? date('d M Y', strtotime($loan['submitted_at'])) : '' ?></div>
            <?php if ($isOwnLoan && $isApproverRole): ?>
            <div class="alert alert-warning py-2 px-2 mb-2 small">You prepared this loan and cannot approve it yourself. Ask another approver to review it.</div>
            <?php endif; ?>
        <?php elseif ($loan['status'] === 'rejected'): ?>
            <div class="text-danger mb-2">Rejected <?= $loan['rejected_at'] ? date('d M Y', strtotime($loan['rejected_at'])) : '' ?></div>
            <?php if (!empty($loan['rejection_reason'])): ?>
            <div class="text-muted mb-2">Reason: <?= htmlspecialchars($loan['rejection_reason']) ?></div>
            <?php endif; ?>
        <?php elseif ($loan['status'] === 'approved'): ?>
            <div class="text-info mb-2">Approved <?= $loan['approved_at'] ? date('d M Y', strtotime($loan['approved_at'])) : '' ?> — awaiting disbursement</div>
        <?php endif; ?>

        <div class="d-flex flex-wrap gap-2">
            <?php if ($canSubmitLoan): ?>
            <form method="POST" action="<?= $base ?>?page=loan-submit" onsubmit="return confirm('Submit this loan for approval?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="loan_id" value="<?= $loan['id'] ?>">
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-send-fill me-1"></i>Submit for Approval
                </button>
            </form>
            <?php endif; ?>

            <?php if ($canApproveLoan): ?>
            <form method="POST" action="<?= $base ?>?page=loan-approve" onsubmit="return confirm('Approve this loan for disbursement?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="loan_id" value="<?= $loan['id'] ?>">
                <button type="submit" class="btn btn-sm btn-success">
                    <i class="bi bi-check-circle-fill me-1"></i>Approve
                </button>
            </form>
            <?php endif; ?>

            <?php if ($canRejectLoan): ?>
            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectLoanModal">
                <i class="bi bi-x-circle me-1"></i>Reject
            </button>
            <?php endif; ?>

            <?php if ($canDisburseLoan): ?>
            <a href="<?= $base ?>?page=loan-disburse-form&id=<?= $loan['id'] ?>" class="btn btn-sm btn-success">
                <i class="bi bi-cash-coin me-1"></i>Disburse
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canRejectLoan): ?>
<div class="modal fade" id="rejectLoanModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="<?= $base ?>?page=loan-reject" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="loan_id" value="<?= $loan['id'] ?>">
            <div class="modal-header">
                <h6 class="modal-title">Reject Loan <?= htmlspecialchars($loan['loan_number']) ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label small fw-semibold">Reason</label>
                <textarea name="rejection_reason" class="form-control" rows="3" required placeholder="Why is this loan being rejected?"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger btn-sm">Reject Loan</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Accounting -->
<div class="card mb-3">
    <div class="card-header py-2"><h6 class="mb-0 fw-semibold small">Accounting</h6></div>
    <div class="card-body p-3" style="font-size:.78rem;">
        <?php if (!empty($loan['entry_number'])): ?>
            <span class="text-muted me-2">Journal Entry</span>
            <a href="<?= $base ?>?page=report-general-ledger&account_id=14" class="badge bg-success-subtle text-success text-decoration-none">
                <?= htmlspecialchars($loan['entry_number']) ?>
            </a>
            <span class="text-muted ms-2">Status: Posted</span>
        <?php elseif ($loan['status'] === 'approved'): ?>
            <div class="text-muted">Awaiting disbursement — use the Approval Workflow section above.</div>
        <?php elseif (in_array($loan['status'], ['draft', 'pending_approval', 'rejected'], true)): ?>
            <div class="text-muted">Not yet approved for disbursement.</div>
        <?php elseif ($canRetryPosting): ?>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="text-muted">Not yet posted</span>
                <form method="POST" action="<?= $base ?>?page=loan-post-disbursement">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="loan_id" value="<?= $loan['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-success">
                        <i class="bi bi-journal-check me-1"></i>Post to Accounting
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="text-muted">Not yet posted.</div>
        <?php endif; ?>
    </div>
</div>

<!-- Next Payment Due + Progress -->
<?php if ($nextDue && !$isCompleted): ?>
<div class="card mb-3 next-due-card">
    <div class="card-body p-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
            <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.04em;">Next Payment Due</div>
            <div class="fw-bold"><?= date('d M Y', strtotime($nextDue['due_date'])) ?>
                <span class="text-muted small ms-1">(<?= htmlspecialchars($nextDue['month_covered'] ?? '') ?>)</span>
            </div>
        </div>
        <div class="text-end">
            <div class="fw-bold fs-5" style="color:var(--brand-orange)">Shs <?= number_format((float)$nextDue['amount_due'], 0) ?></div>
            <div class="text-muted" style="font-size:.7rem">
                <?= ($nextDue['payment_type'] ?? '') === 'principal_interest' ? 'Principal + Interest' : 'Interest Only' ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Schedule Progress Bar -->
<?php if (!empty($installments)): ?>
<div class="card mb-3">
    <div class="card-body p-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="d-flex gap-3">
                <span class="badge bg-success px-2 py-1" style="font-size:.72rem"><?= $paidCount ?> Paid</span>
                <?php if ($overdueCount > 0): ?>
                <span class="badge bg-danger px-2 py-1" style="font-size:.72rem"><?= $overdueCount ?> Missed</span>
                <?php endif; ?>
                <span class="badge bg-secondary px-2 py-1" style="font-size:.72rem"><?= $pendingCount ?> Pending</span>
            </div>
            <span class="fw-bold small"><?= $progressPct ?>%</span>
        </div>
        <div class="progress" style="height:8px;border-radius:99px;">
            <div class="progress-bar bg-success" style="width:<?= $progressPct ?>%"></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════ SCHEDULE / HISTORY (tabbed) ═══════════════ -->
<div class="card">
    <div class="card-header p-0">
        <ul class="nav nav-tabs card-header-tabs px-3 pt-2" role="tablist">
            <li class="nav-item">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#scheduleTab" type="button" role="tab">
                    <i class="bi bi-calendar-check me-1 text-success"></i>Schedule
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#historyTab" type="button" role="tab">
                    <i class="bi bi-receipt me-1" style="color:var(--brand-orange)"></i>Payment History
                    <?php if (!empty($repayments)): ?><span class="badge bg-secondary ms-1"><?= count($repayments) ?></span><?php endif; ?>
                </button>
            </li>
        </ul>
    </div>
    <div class="tab-content">

        <!-- Schedule tab -->
        <div class="tab-pane fade show active p-0" id="scheduleTab" role="tabpanel">
            <div class="d-flex justify-content-end p-2 border-bottom">
                <a href="<?= $base ?>?page=loan-schedule&id=<?= $loan['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" style="font-size:.7rem">
                    <i class="bi bi-printer me-1"></i>Print
                </a>
            </div>
            <?php if (empty($installments)): ?>
            <div class="text-center py-4 text-muted small">No schedule generated for this loan.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" style="font-size:.78rem;">
                    <thead>
                        <tr style="background:#f8f9fa;">
                            <th class="ps-3 text-muted" style="width:40px;font-size:.65rem">#</th>
                            <th class="text-muted" style="font-size:.65rem">DUE DATE</th>
                            <th class="text-muted text-end" style="font-size:.65rem">PRINCIPAL</th>
                            <th class="text-muted text-end" style="font-size:.65rem">INTEREST</th>
                            <th class="text-muted text-end" style="font-size:.65rem">TOTAL DUE</th>
                            <th class="text-muted text-end" style="font-size:.65rem">PAID</th>
                            <th class="text-muted text-center" style="font-size:.65rem">STATUS</th>
                            <th class="text-muted pe-3" style="font-size:.65rem">PAID DATE</th>
                        </tr>
                    </thead>
                    <tbody>
<?php
$prevType = null;
foreach ($installments as $inst):
    $payType = $inst['payment_type'] ?? 'interest_only';
    // Phase separator
    if ($prevType !== null && $prevType !== $payType):
?>
                        <tr>
                            <td colspan="8" class="phase-header">
                                <i class="bi bi-arrow-down-circle me-1"></i>Principal Recovery Phase
                                <span class="phase-badge bg-primary text-white">Weekly Principal + Interest</span>
                            </td>
                        </tr>
<?php endif; $prevType = $payType; ?>
                        <tr class="schedule-row row-<?= $inst['status'] ?>">
                            <td class="ps-3 text-muted"><?= $inst['installment_no'] ?></td>
                            <td class="fw-semibold"><?= date('d M Y', strtotime($inst['due_date'])) ?></td>
                            <td class="text-end"><?= (float)($inst['principal_due'] ?? 0) > 0 ? number_format((float)$inst['principal_due'], 0) : '<span class="text-muted">—</span>' ?></td>
                            <td class="text-end"><?= number_format((float)($inst['interest_due'] ?? $inst['amount_due']), 0) ?></td>
                            <td class="text-end fw-semibold"><?= number_format((float)$inst['amount_due'], 0) ?></td>
                            <td class="text-end <?= (float)$inst['amount_paid'] > 0 ? 'text-success fw-bold' : '' ?>">
                                <?= (float)$inst['amount_paid'] > 0 ? number_format((float)$inst['amount_paid'], 0) : '<span class="text-muted">—</span>' ?>
                            </td>
                            <td class="text-center">
                                <?php if ($inst['status'] === 'paid'): ?>
                                    <span class="badge bg-success" style="font-size:.65rem">Paid</span>
                                <?php elseif ($inst['status'] === 'partial'): ?>
                                    <span class="badge bg-warning text-dark" style="font-size:.65rem">Partial</span>
                                <?php elseif ($inst['status'] === 'overdue'): ?>
                                    <span class="badge bg-danger" style="font-size:.65rem">Missed</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted" style="font-size:.65rem">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td class="pe-3 text-muted"><?= !empty($inst['paid_date']) ? date('d M', strtotime($inst['paid_date'])) : '' ?></td>
                        </tr>
<?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:#f8f9fa;border-top:2px solid #dee2e6;">
                            <td colspan="2" class="ps-3 fw-bold small">TOTAL</td>
                            <td class="text-end fw-bold small"><?= number_format(array_sum(array_column($installments, 'principal_due')), 0) ?></td>
                            <td class="text-end fw-bold small"><?= number_format(array_sum(array_map(fn($i) => (float)($i['interest_due'] ?? $i['amount_due']), $installments)), 0) ?></td>
                            <td class="text-end fw-bold small"><?= number_format($totalScheduled, 0) ?></td>
                            <td class="text-end fw-bold small text-success"><?= number_format($totalPaidSchedule, 0) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Payment History tab -->
        <div class="tab-pane fade p-0" id="historyTab" role="tabpanel">
            <div class="d-flex justify-content-end p-2 border-bottom">
                <a href="<?= $base ?>?page=repayment-loan&loan_id=<?= $loan['id'] ?>" class="btn btn-sm btn-outline-secondary" style="font-size:.7rem">
                    View All <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
            <?php if (empty($repayments)): ?>
            <div class="text-center py-4 text-muted small">
                No payments recorded yet.
                <?php if (!$isCompleted): ?>
                <a href="<?= $base ?>?page=repayment-add&loan_id=<?= $loan['id'] ?>" class="ms-1">Record first payment</a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0" style="font-size:.78rem;">
                    <thead>
                        <tr style="background:#f8f9fa;">
                            <th class="ps-3 text-muted" style="font-size:.65rem">RECEIPT</th>
                            <th class="text-muted" style="font-size:.65rem">DATE</th>
                            <th class="text-muted text-end" style="font-size:.65rem">AMOUNT</th>
                            <th class="text-muted" style="font-size:.65rem">METHOD</th>
                            <th class="text-muted" style="font-size:.65rem">TYPE</th>
                            <th class="text-muted text-end pe-3" style="font-size:.65rem">BALANCE</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($repayments as $r): ?>
                        <tr>
                            <td class="ps-3">
                                <a href="<?= $base ?>?page=repayment-view&id=<?= $r['id'] ?>" class="text-decoration-none fw-semibold" style="color:var(--ink);font-size:.78rem">
                                    <?= htmlspecialchars($r['repayment_number']) ?>
                                </a>
                            </td>
                            <td><?= date('d M Y', strtotime($r['payment_date'])) ?></td>
                            <td class="text-end fw-bold text-success">Shs <?= number_format((float)$r['amount_paid'], 0) ?></td>
                            <td><span class="badge bg-light text-dark border" style="font-size:.65rem"><?= htmlspecialchars($r['payment_method'] ?? '—') ?></span></td>
                            <td>
                                <?php
                                $typeColor = match($r['payment_type'] ?? 'installment') {
                                    'interest' => 'bg-info', 'principal' => 'bg-primary',
                                    'weekly_savings' => 'bg-info', 'settlement' => 'bg-success',
                                    default => 'bg-secondary'
                                };
                                ?>
                                <span class="badge <?= $typeColor ?>" style="font-size:.62rem"><?= ucfirst(str_replace('_',' ',$r['payment_type'] ?? 'installment')) ?></span>
                            </td>
                            <td class="text-end pe-3 fw-semibold <?= (float)$r['balance_after'] <= 0 ? 'text-success' : 'text-danger' ?>">
                                Shs <?= number_format((float)$r['balance_after'], 0) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php if ($canDeleteLoan): ?>
<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-danger text-white border-0 py-2">
                <h6 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-2"></i>Delete Loan</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-3">
                <p class="mb-1 text-muted small">Permanently delete:</p>
                <p class="fw-bold mb-0"><?= htmlspecialchars($loan['loan_number']) ?> — Shs <?= number_format($loan['loan_amount'],0) ?></p>
                <p class="text-danger small mt-2 mb-0"><i class="bi bi-info-circle me-1"></i>Cannot be undone.</p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="<?= $base ?>?page=loan-delete" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="id" value="<?= $loan['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="bi bi-trash me-1"></i>Delete
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.getElementById('openDeleteBtn')?.addEventListener('click', function(){
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
});

function shareWhatsApp() {
    fetch('<?= APP_URL ?>/index.php?page=loan-whatsapp-schedule&id=<?= $loan['id'] ?>')
        .then(r => r.json())
        .then(data => {
            if (data.url) window.open(data.url, '_blank');
            else alert('Could not generate schedule.');
        })
        .catch(() => alert('Error generating schedule.'));
}
</script>
