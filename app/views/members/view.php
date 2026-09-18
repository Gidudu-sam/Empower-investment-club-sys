<?php
/**
 * Member Profile View
 */
$memberStatus = $member['status']; // 'active' | 'dormant' | 'inactive'
$isFemale     = $member['gender'] === 'Female';
$fullName = htmlspecialchars($member['first_name'] . ' ' . $member['last_name']);
$initial  = strtoupper(substr($member['first_name'], 0, 1));

// Age
$age = null;
if (!empty($member['date_of_birth'])) {
    $dob = new DateTime($member['date_of_birth']);
    $age = (int) $dob->diff(new DateTime())->y;
}

// Savings summary
$savingsBalance = 0;
$savingsCount   = 0;
$latestDeposit  = false;
$savingsHistory = [];
try {
    require_once APP_PATH . '/models/SavingsModel.php';
    $savModel       = new SavingsModel();
    $savingsBalance = $savModel->memberBalance((int)$member['id']);
    $savingsCount   = $savModel->memberDepositCount((int)$member['id']);
    $latestDeposit  = $savModel->memberLatestDeposit((int)$member['id']);
    $savingsHistory = $savModel->memberHistory((int)$member['id'], 5);
} catch (Exception $e) { /* savings table may not exist yet */ }

$base = APP_URL . '/index.php';

// Generate CSRF token for forms
if (!Session::has('csrf_token')) Session::set('csrf_token', bin2hex(random_bytes(32)));
$csrfToken = Session::get('csrf_token');
?>

<!-- Header -->
<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-person-circle me-2 text-primary"></i>Member Profile
        </h1>
        <p class="text-muted mb-0 small">
            <?= htmlspecialchars($member['member_number']) ?> &nbsp;·&nbsp; <?= $fullName ?>
        </p>
    </div>
    <a href="<?= APP_URL ?>/index.php?page=members" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to Members
    </a>
</div>

<!-- Alerts -->
<?php if (!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3" role="alert">
    <i class="bi bi-check-circle-fill flex-shrink-0"></i>
    <div><?= htmlspecialchars($success) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
    <div><?= htmlspecialchars($error) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- ── LEFT PANEL ──────────────────────────────────────── -->
    <div class="col-xl-3 col-lg-4">
        <div class="card text-center">
            <div class="card-body py-4 px-3">

                <!-- Avatar (initials only — no photo) -->
                <div class="member-avatar-lg mx-auto mb-3 <?= $isFemale ? 'bg-pink' : 'bg-blue' ?>">
                    <?= $initial ?>
                </div>

                <h5 class="fw-bold mb-0"><?= $fullName ?></h5>
                <p class="text-muted small mb-1"><?= htmlspecialchars($member['member_number']) ?></p>
                <?php if (!empty($member['account_number'])): ?>
                <p class="small mb-2" style="color:var(--brand-navy)">
                    <i class="bi bi-hash me-1"></i>Acc: <strong><?= htmlspecialchars($member['account_number']) ?></strong>
                </p>
                <?php else: ?>
                <p class="small mb-2 text-muted fst-italic">No account number</p>
                <?php endif; ?>

                <?php if ($memberStatus === 'active'): ?>
                <span class="badge bg-success rounded-pill px-3 py-2 mb-3">
                    <i class="bi bi-check-circle me-1"></i>Active
                </span>
                <?php elseif ($memberStatus === 'dormant'): ?>
                <span class="badge rounded-pill px-3 py-2 mb-3" style="background:#fd7e14;color:#fff;">
                    <i class="bi bi-moon-stars me-1"></i>Dormant
                </span>
                <?php else: ?>
                <span class="badge bg-secondary rounded-pill px-3 py-2 mb-3">
                    <i class="bi bi-x-circle me-1"></i>Inactive
                </span>
                <?php endif; ?>

                <hr class="my-3">

                <!-- Quick Info -->
                <ul class="list-unstyled text-start small mb-3">
                    <li class="d-flex gap-2 mb-2">
                        <i class="bi bi-telephone-fill text-primary mt-1 flex-shrink-0"></i>
                        <a href="tel:<?= htmlspecialchars($member['phone']) ?>" class="text-decoration-none">
                            <?= htmlspecialchars($member['phone']) ?>
                        </a>
                    </li>
                    <?php if (!empty($member['email'])): ?>
                    <li class="d-flex gap-2 mb-2">
                        <i class="bi bi-envelope-fill text-primary mt-1 flex-shrink-0"></i>
                        <a href="mailto:<?= htmlspecialchars($member['email']) ?>"
                           class="text-decoration-none text-break">
                            <?= htmlspecialchars($member['email']) ?>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (!empty($member['address'])): ?>
                    <li class="d-flex gap-2 mb-2">
                        <i class="bi bi-geo-alt-fill text-primary mt-1 flex-shrink-0"></i>
                        <span><?= nl2br(htmlspecialchars($member['address'])) ?></span>
                    </li>
                    <?php endif; ?>
                    <li class="d-flex gap-2 mb-2">
                        <i class="bi bi-calendar-check-fill text-primary mt-1 flex-shrink-0"></i>
                        <span>Joined <?= date('d M Y', strtotime($member['join_date'])) ?></span>
                    </li>
                </ul>

                <hr class="my-3">

                <!-- Action Buttons -->
                <?php
                // Matches MemberController::requireEditAccess() / requireAdmin() exactly.
                $canEditMember = Session::hasRole(['admin', 'office_admin']); // MemberController::requireEditAccess exact -- treasurer is view-only
                $canAddLoan = Session::hasRole(['admin', 'loans_officer']); // LoanController::requireOriginateAccess exact
                $canAdminMember = Session::hasRole(['admin']);
                ?>
                <?php if ($canEditMember || $canAdminMember): ?>
                <div class="d-flex flex-column gap-2">
                    <?php if ($canEditMember): ?>
                    <a href="<?= APP_URL ?>/index.php?page=member-edit&id=<?= $member['id'] ?>"
                       class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-pencil-fill me-1"></i>Edit Profile
                    </a>
                    <?php endif; ?>
                    <?php if ($canAdminMember): ?>
                    <button type="button" class="btn btn-outline-primary btn-sm w-100" id="openChangeStatusBtn">
                        <i class="bi bi-arrow-repeat me-1"></i>Change Status
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm w-100"
                            id="openDeleteBtn">
                        <i class="bi bi-trash me-1"></i>Delete Member
                    </button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <!-- ── RIGHT DETAIL PANELS ─────────────────────────────── -->
    <div class="col-xl-9 col-lg-8">

        <!-- Personal Details -->
        <div class="card mb-4">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-person-badge text-primary"></i>
                <h6 class="mb-0 fw-semibold">Personal Details</h6>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-sm-4">
                        <div class="detail-label">First Name</div>
                        <div class="detail-value"><?= htmlspecialchars($member['first_name']) ?></div>
                    </div>
                    <div class="col-sm-4">
                        <div class="detail-label">Last Name</div>
                        <div class="detail-value"><?= htmlspecialchars($member['last_name']) ?></div>
                    </div>
                    <div class="col-sm-4">
                        <div class="detail-label">Gender</div>
                        <div class="detail-value"><?= htmlspecialchars($member['gender']) ?></div>
                    </div>
                    <div class="col-sm-4">
                        <div class="detail-label">Date of Birth</div>
                        <div class="detail-value">
                            <?php if (!empty($member['date_of_birth'])): ?>
                                <?= date('d M Y', strtotime($member['date_of_birth'])) ?>
                                <?php if ($age !== null): ?>
                                <span class="text-muted small">(Age <?= $age ?>)</span>
                                <?php endif; ?>
                            <?php else: ?>—<?php endif; ?>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="detail-label">National ID</div>
                        <div class="detail-value"><?= htmlspecialchars($member['national_id']) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Contact Details -->
        <div class="card mb-4">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-telephone text-primary"></i>
                <h6 class="mb-0 fw-semibold">Contact Details</h6>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-sm-4">
                        <div class="detail-label">Phone</div>
                        <div class="detail-value">
                            <a href="tel:<?= htmlspecialchars($member['phone']) ?>" class="text-decoration-none">
                                <?= htmlspecialchars($member['phone']) ?>
                            </a>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="detail-label">Email</div>
                        <div class="detail-value">
                            <?php if (!empty($member['email'])): ?>
                            <a href="mailto:<?= htmlspecialchars($member['email']) ?>" class="text-decoration-none">
                                <?= htmlspecialchars($member['email']) ?>
                            </a>
                            <?php else: ?>—<?php endif; ?>
                        </div>
                    </div>
                    <div class="col-sm-12">
                        <div class="detail-label">Address</div>
                        <div class="detail-value">
                            <?= !empty($member['address']) ? nl2br(htmlspecialchars($member['address'])) : '—' ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Next of Kin -->
        <div class="card mb-4">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-people text-primary"></i>
                <h6 class="mb-0 fw-semibold">Next of Kin</h6>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-sm-6">
                        <div class="detail-label">Name</div>
                        <div class="detail-value">
                            <?= !empty($member['next_of_kin_name']) ? htmlspecialchars($member['next_of_kin_name']) : '—' ?>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="detail-label">Phone</div>
                        <div class="detail-value">
                            <?= !empty($member['next_of_kin_phone']) ? htmlspecialchars($member['next_of_kin_phone']) : '—' ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Membership Info -->
        <div class="card mb-4">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-card-checklist text-primary"></i>
                <h6 class="mb-0 fw-semibold">Membership Information</h6>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-sm-4">
                        <div class="detail-label">Member Number</div>
                        <div class="detail-value fw-bold text-primary">
                            <?= htmlspecialchars($member['member_number']) ?>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="detail-label">Account Number</div>
                        <div class="detail-value fw-bold" style="color:var(--brand-navy)">
                            <?php if (!empty($member['account_number'])): ?>
                                <?= htmlspecialchars($member['account_number']) ?>
                            <?php else: ?>
                                <span class="text-muted fst-italic small">Not assigned</span>
                                <?php if ($canEditMember): ?>
                                <a href="<?= APP_URL ?>/index.php?page=member-edit&id=<?= $member['id'] ?>"
                                   class="btn btn-xs btn-outline-primary ms-2 py-0 px-1" style="font-size:.68rem;">
                                    <i class="bi bi-plus"></i> Assign
                                </a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="detail-label">Date Joined</div>
                        <div class="detail-value"><?= date('d M Y', strtotime($member['join_date'])) ?></div>
                    </div>
                    <div class="col-sm-4">
                        <div class="detail-label">Status</div>
                        <div class="detail-value">
                            <?php if ($memberStatus === 'active'): ?>
                            <span class="badge bg-success-subtle text-success">Active</span>
                            <?php elseif ($memberStatus === 'dormant'): ?>
                            <span class="badge" style="background:rgba(253,126,20,.12);color:#fd7e14;">Dormant</span>
                            <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="detail-label">Record Created</div>
                        <div class="detail-value text-muted small">
                            <?= date('d M Y, H:i', strtotime($member['created_at'])) ?>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="detail-label">Last Updated</div>
                        <div class="detail-value text-muted small">
                            <?= date('d M Y, H:i', strtotime($member['updated_at'])) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /.col right -->
</div><!-- /.row -->

<!-- ── SAVINGS SECTION ─────────────────────────────────────── -->
<div class="row g-4 mt-1">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-semibold">
                    <i class="bi bi-piggy-bank-fill me-2 text-success"></i>Savings Summary
                </h6>
                <div class="d-flex gap-2">
                    <a href="<?= $base ?>?page=savings-member&member_id=<?= $member['id'] ?>"
                       class="btn btn-sm btn-outline-success">
                        <i class="bi bi-clock-history me-1"></i>Full History
                    </a>
                    <a href="<?= $base ?>?page=savings-add&member_id=<?= $member['id'] ?>"
                       class="btn btn-sm btn-success">
                        <i class="bi bi-plus me-1"></i>New Deposit
                    </a>
                </div>
            </div>
            <div class="card-body p-4">
                <div class="row g-3 mb-4">
                    <div class="col-sm-3">
                        <div class="text-center p-3 bg-success bg-opacity-10 rounded-3">
                            <div class="fw-bold text-success fs-5">Shs <?= number_format($savingsBalance, 2) ?></div>
                            <div class="text-muted small mt-1">Current Balance</div>
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <div class="text-center p-3 bg-primary bg-opacity-10 rounded-3">
                            <div class="fw-bold text-primary fs-4"><?= number_format($savingsCount) ?></div>
                            <div class="text-muted small mt-1">Total Deposits</div>
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <div class="text-center p-3 bg-info bg-opacity-10 rounded-3">
                            <div class="fw-bold text-info fs-5">
                                <?= $latestDeposit ? 'Shs ' . number_format($latestDeposit['amount'], 2) : '—' ?>
                            </div>
                            <div class="text-muted small mt-1">Latest Deposit</div>
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <div class="text-center p-3 bg-warning bg-opacity-10 rounded-3">
                            <div class="fw-bold text-warning fs-5">
                                <?= $latestDeposit ? date('d M Y', strtotime($latestDeposit['transaction_date'])) : '—' ?>
                            </div>
                            <div class="text-muted small mt-1">Latest Date</div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($savingsHistory)): ?>
                <form id="bulkDeleteForm" method="POST" action="<?= $base ?>?page=savings-bulk-delete">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
                    <input type="hidden" name="member_id" value="<?= $member['id'] ?>">
                <div class="table-responsive">
                    <table class="table table-hover table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-2" style="width:36px">
                                    <input type="checkbox" class="form-check-input" id="selectAllSavings" title="Select All">
                                </th>
                                <th>Receipt</th>
                                <th class="text-end">Amount (Shs)</th>
                                <th>Method</th>
                                <th>Date</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($savingsHistory as $s): ?>
                            <tr>
                                <td class="ps-2">
                                    <input type="checkbox" class="form-check-input savings-checkbox" name="ids[]" value="<?= $s['id'] ?>" data-amount="<?= $s['amount'] ?>">
                                </td>
                                <td>
                                    <a href="<?= $base ?>?page=savings-view&id=<?= $s['id'] ?>"
                                       class="fw-semibold text-success text-decoration-none">
                                        <?= htmlspecialchars($s['receipt_number']) ?>
                                    </a>
                                </td>
                                <td class="text-end fw-bold text-success">
                                    <?= number_format($s['amount'], 0) ?>
                                </td>
                                <td>
                                    <span class="badge bg-success-subtle text-success rounded-pill px-2">
                                        <?= htmlspecialchars($s['payment_method']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= date('d M Y', strtotime($s['transaction_date'])) ?>
                                </td>
                                <td class="pe-2 text-end">
                                    <a href="<?= $base ?>?page=savings-receipt&id=<?= $s['id'] ?>"
                                       class="btn btn-sm btn-outline-secondary py-0 px-2" target="_blank" title="Print">
                                        <i class="bi bi-printer" style="font-size:.75rem"></i>
                                    </a>
                                    <form method="POST"
                                          action="<?= $base ?>?page=savings-delete&return_to=member-view&return_id=<?= $member['id'] ?>"
                                          style="display:inline"
                                          onsubmit="return confirm('Delete <?= htmlspecialchars($s['receipt_number']) ?>?')">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2 ms-1" title="Delete">
                                            <i class="bi bi-trash" style="font-size:.75rem"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                </form>

                <!-- Bulk Action Bar (hidden until selection) -->
                <div id="bulkActionBar" class="d-none border-top p-3 d-flex align-items-center justify-content-between" style="background:#fef2f2;">
                    <div>
                        <span class="fw-bold text-danger" id="bulkCount">0</span> records selected
                        <span class="text-muted ms-2">(Shs <span id="bulkAmount" class="fw-semibold">0</span>)</span>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" id="bulkClearBtn" class="btn btn-sm btn-outline-secondary">Clear</button>
                        <button type="button" id="bulkDeleteBtn" class="btn btn-sm btn-danger fw-semibold">
                            <i class="bi bi-trash me-1"></i>Delete Selected
                        </button>
                    </div>
                </div>
                <?php else: ?>
                <p class="text-muted text-center py-3 mb-0 small">
                    No savings yet.
                    <a href="<?= $base ?>?page=savings-add&member_id=<?= $member['id'] ?>">Record the first deposit.</a>
                </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── LOANS SECTION ───────────────────────────────────────── -->
<?php
// Load loan data for this member
$activeLoan    = false;
$loanHistory   = [];
try {
    require_once APP_PATH . '/models/LoanModel.php';
    $lnModel    = new LoanModel();
    $activeLoan = $lnModel->memberActiveLoan((int)$member['id']);
    $loanHistory = $lnModel->memberLoanHistory((int)$member['id'], 5);
} catch (Exception $e) {}
?>
<div class="row g-4 mt-1">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-semibold">
                    <i class="bi bi-bank2 me-2 text-warning"></i>Loan Summary
                </h6>
                <div class="d-flex gap-2">
                    <a href="<?= $base ?>?page=loan-member&member_id=<?= $member['id'] ?>"
                       class="btn btn-sm btn-outline-warning">
                        <i class="bi bi-clock-history me-1"></i>Full History
                    </a>
                    <?php if ($canAddLoan): ?>
                    <a href="<?= $base ?>?page=loan-add&member_id=<?= $member['id'] ?>"
                       class="btn btn-sm btn-warning text-white">
                        <i class="bi bi-plus me-1"></i>Record Loan
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-4">

                <?php if ($activeLoan): ?>
                <!-- Active loan highlight -->
                <div class="row g-3 mb-4">
                    <div class="col-sm-3">
                        <div class="text-center p-3 bg-warning bg-opacity-10 rounded-3">
                            <div class="fw-bold text-warning fs-5">Shs <?= number_format($activeLoan['loan_amount'], 2) ?></div>
                            <div class="text-muted small mt-1">Loan Amount</div>
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <div class="text-center p-3 bg-danger bg-opacity-10 rounded-3">
                            <div class="fw-bold text-danger fs-5">Shs <?= number_format($activeLoan['outstanding'], 2) ?></div>
                            <div class="text-muted small mt-1">Outstanding</div>
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <div class="text-center p-3 bg-info bg-opacity-10 rounded-3">
                            <div class="fw-bold text-info"><?= date('d M Y', strtotime($activeLoan['due_date'])) ?></div>
                            <div class="text-muted small mt-1">Due Date</div>
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <div class="text-center p-3 bg-secondary bg-opacity-10 rounded-3">
                            <?php
                            $lStatus = $activeLoan['status'];
                            $lColor  = match($lStatus){'active'=>'success','overdue'=>'danger',default=>'secondary'};
                            ?>
                            <span class="badge bg-<?= $lColor ?> rounded-pill px-3 py-2"><?= ucfirst($lStatus) ?></span>
                            <div class="text-muted small mt-1">Status</div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($loanHistory)): ?>
                <div class="table-responsive">
                    <table class="table table-hover table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-2">Loan No.</th>
                                <th class="text-end">Amount</th>
                                <th class="text-end">Outstanding</th>
                                <th>Due Date</th>
                                <th class="text-center">Status</th>
                                <th class="pe-2 text-end">View</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($loanHistory as $ln): ?>
                            <tr>
                                <td class="ps-2">
                                    <a href="<?= $base ?>?page=loan-view&id=<?= $ln['id'] ?>"
                                       class="fw-semibold text-decoration-none small" style="color:var(--brand-navy)">
                                        <?= htmlspecialchars($ln['loan_number']) ?>
                                    </a>
                                </td>
                                <td class="text-end small">Shs <?= number_format($ln['loan_amount'], 2) ?></td>
                                <td class="text-end fw-bold small text-danger">Shs <?= number_format($ln['outstanding'], 2) ?></td>
                                <td class="text-muted small"><?= date('d M Y', strtotime($ln['due_date'])) ?></td>
                                <td class="text-center">
                                    <span class="badge rounded-pill px-2 small
                                        <?= match($ln['status']){'active'=>'bg-success-subtle text-success','overdue'=>'bg-danger-subtle text-danger',default=>'bg-primary-subtle text-primary'} ?>">
                                        <?= ucfirst($ln['status']) ?>
                                    </span>
                                </td>
                                <td class="pe-2 text-end">
                                    <a href="<?= $base ?>?page=loan-view&id=<?= $ln['id'] ?>"
                                       class="btn btn-sm btn-outline-warning py-0 px-2">
                                        <i class="bi bi-eye" style="font-size:.75rem"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p class="text-muted text-center py-3 mb-0 small">
                    No loans recorded yet.
                    <?php if ($canAddLoan): ?>
                    <a href="<?= $base ?>?page=loan-add&member_id=<?= $member['id'] ?>">Record a loan.</a>
                    <?php endif; ?>
                </p>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<!-- ── SHARES SECTION ─────────────────────────────────── -->
<?php
$shareQuantity = 0;
$shareCapital  = 0;
$shareValue    = 1000.00; // Default share value, could be from settings
$shareAccount  = null;
$shareTransactions = [];
try {
    require_once APP_PATH . '/models/ShareModel.php';
    $shareModel = new ShareModel();
    $shareQuantity = $shareModel->memberQuantity((int)$member['id'], $shareValue);
    $shareCapital  = $shareModel->memberCapital((int)$member['id']);
    $shareTransactions = $shareModel->ledgerForMember((int)$member['id'], $shareValue);
    
    // Get member share account if exists
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare('SELECT * FROM member_share_accounts WHERE member_id = ?');
    $stmt->execute([(int)$member['id']]);
    $shareAccount = $stmt->fetch();
} catch (Exception $e) {
    // Table might not exist yet, silently handle
}
?>

<?php if ($shareQuantity > 0 || $shareAccount): ?>
<div class="row g-4 mt-1">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-semibold">
                    <i class="bi bi-shield-fill-check me-2 text-success"></i>Share Capital
                </h6>
                <?php if ($shareAccount): ?>
                <span class="badge bg-success-subtle text-success">
                    <?= htmlspecialchars($shareAccount['account_number']) ?>
                </span>
                <?php endif; ?>
            </div>
            <div class="card-body p-4">
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="text-center p-3 bg-success bg-opacity-10 rounded-3">
                            <div class="fw-bold text-success fs-5"><?= number_format($shareQuantity, 4) ?></div>
                            <div class="text-muted small mt-1">Total Shares Owned</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center p-3 bg-primary bg-opacity-10 rounded-3">
                            <div class="fw-bold text-primary fs-5">Shs <?= number_format($shareCapital, 2) ?></div>
                            <div class="text-muted small mt-1">Share Capital Value</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center p-3 bg-info bg-opacity-10 rounded-3">
                            <div class="fw-bold text-info fs-5"><?= count($shareTransactions) ?></div>
                            <div class="text-muted small mt-1">Total Transactions</div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($shareTransactions)): ?>
                <div class="table-responsive">
                    <table class="table table-hover table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-2">Date</th>
                                <th>Transaction Type</th>
                                <th class="text-end">Quantity</th>
                                <th class="text-end">Share Value</th>
                                <th class="text-end">Amount</th>
                                <th class="pe-2">Reference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shareTransactions as $st): ?>
                            <tr>
                                <td class="ps-2 text-muted small"><?= isset($st['date']) ? date('d M Y', strtotime($st['date'])) : 'N/A' ?></td>
                                <td>
                                    <span class="badge bg-<?= 
                                        in_array($st['transaction_type'] ?? '', ['transfer_out', 'redemption']) ? 'danger' : 'success' 
                                    ?>-subtle text-<?= 
                                        in_array($st['transaction_type'] ?? '', ['transfer_out', 'redemption']) ? 'danger' : 'success' 
                                    ?> small">
                                        <?= htmlspecialchars(str_replace('_', ' ', ucwords($st['transaction_type'] ?? 'Unknown', '_'))) ?>
                                    </span>
                                </td>
                                <td class="text-end fw-semibold small"><?= number_format($st['quantity'] ?? 0, 4) ?></td>
                                <td class="text-end text-muted small">Shs <?= number_format($st['share_value'] ?? 0, 2) ?></td>
                                <td class="text-end fw-bold small">Shs <?= number_format($st['amount'] ?? 0, 2) ?></td>
                                <td class="pe-2 small text-muted"><?= htmlspecialchars($st['reference_number'] ?? 'N/A') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p class="text-muted text-center py-3 mb-0 small">
                    <i class="bi bi-info-circle me-1"></i>No share transactions recorded yet.
                </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── WITHDRAWALS SECTION ─────────────────────────────────── -->
<?php
$wdlHistory       = [];
$wdlTotalWithdrawn = 0;
$wdlTotalRetained  = 0;
$currentYearWdl    = false;
try {
    require_once APP_PATH . '/models/WithdrawalModel.php';
    $wdlModel          = new WithdrawalModel();
    $wdlHistory        = $wdlModel->memberHistory((int)$member['id']);
    $wdlTotalWithdrawn = $wdlModel->memberTotalWithdrawn((int)$member['id']);
    $wdlTotalRetained  = $wdlModel->memberTotalRetained((int)$member['id']);
    $currentYearWdl    = $wdlModel->hasWithdrawnThisYear((int)$member['id'], (int)date('Y'));
} catch (Exception $e) {}
?>
<div class="row g-4 mt-1">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-semibold">
                    <i class="bi bi-box-arrow-up-right me-2 text-danger"></i>Withdrawal & Share Retention
                </h6>
                <div class="d-flex gap-2 align-items-center">
                    <?php if ($currentYearWdl): ?>
                    <span class="badge bg-warning-subtle text-warning small">
                        <i class="bi bi-check-circle me-1"></i>Withdrew <?= date('Y') ?>
                    </span>
                    <?php else: ?>
                    <a href="<?= $base ?>?page=withdrawal-process&member_id=<?= $member['id'] ?>"
                       class="btn btn-sm btn-danger">
                        <i class="bi bi-plus me-1"></i>Process Withdrawal
                    </a>
                    <?php endif; ?>
                    <a href="<?= $base ?>?page=withdrawal-member&member_id=<?= $member['id'] ?>"
                       class="btn btn-sm btn-outline-danger">
                        <i class="bi bi-clock-history me-1"></i>Full History
                    </a>
                </div>
            </div>
            <div class="card-body p-4">
                <div class="row g-3 mb-4">
                    <div class="col-sm-4">
                        <div class="text-center p-3 bg-danger bg-opacity-10 rounded-3">
                            <div class="fw-bold text-danger fs-5">Shs <?= number_format($wdlTotalWithdrawn, 2) ?></div>
                            <div class="text-muted small mt-1">Total Withdrawn</div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="text-center p-3 bg-success bg-opacity-10 rounded-3">
                            <div class="fw-bold text-success fs-5">Shs <?= number_format($wdlTotalRetained, 2) ?></div>
                            <div class="text-muted small mt-1">Total Retained Shares</div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="text-center p-3 bg-primary bg-opacity-10 rounded-3">
                            <div class="fw-bold text-primary fs-4"><?= count($wdlHistory) ?></div>
                            <div class="text-muted small mt-1">Total Withdrawals</div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($wdlHistory)): ?>
                <div class="table-responsive">
                    <table class="table table-hover table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-2">Withdrawal No.</th>
                                <th class="text-center">Year</th>
                                <th class="text-end">Cash Paid</th>
                                <th class="text-end">Retained</th>
                                <th>Date</th>
                                <th class="pe-2 text-end">Print</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($wdlHistory as $wh): ?>
                            <tr>
                                <td class="ps-2">
                                    <a href="<?= $base ?>?page=withdrawal-view&id=<?= $wh['id'] ?>"
                                       class="fw-semibold text-danger text-decoration-none small">
                                        <?= htmlspecialchars($wh['withdrawal_number']) ?>
                                    </a>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-primary-subtle text-primary rounded-pill small"><?= $wh['financial_year'] ?></span>
                                </td>
                                <td class="text-end fw-bold text-danger small">Shs <?= number_format($wh['withdrawal_amount'], 2) ?></td>
                                <td class="text-end fw-semibold text-success small">Shs <?= number_format($wh['retained_amount'], 2) ?></td>
                                <td class="text-muted small"><?= date('d M Y', strtotime($wh['withdrawal_date'])) ?></td>
                                <td class="pe-2 text-end">
                                    <a href="<?= $base ?>?page=withdrawal-receipt&id=<?= $wh['id'] ?>"
                                       class="btn btn-sm btn-outline-secondary py-0 px-2" target="_blank">
                                        <i class="bi bi-printer" style="font-size:.75rem"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p class="text-muted text-center py-3 mb-0 small">
                    No withdrawals recorded yet.
                    <?php if (!$currentYearWdl): ?>
                    <a href="<?= $base ?>?page=withdrawal-process&member_id=<?= $member['id'] ?>">Process first withdrawal.</a>
                    <?php endif; ?>
                </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($canAdminMember): ?>
<!-- Change Status Modal (Stage 6-A) -->
<div class="modal fade" id="changeStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" action="<?= APP_URL ?>/index.php?page=member-status-change">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="id" value="<?= $member['id'] ?>">
                <div class="modal-header border-0">
                    <h5 class="modal-title">
                        <i class="bi bi-arrow-repeat me-2 text-primary"></i>Change Status
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body py-2">
                    <p class="mb-1 text-muted small">
                        <?= htmlspecialchars($member['member_number']) ?> — <?= $fullName ?>
                    </p>
                    <label class="form-label fw-semibold" for="changeStatusSelect">New Status</label>
                    <select id="changeStatusSelect" name="status" class="form-select">
                        <option value="active"   <?= $memberStatus === 'active'   ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $memberStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        <option value="dormant"  <?= $memberStatus === 'dormant'  ? 'selected' : '' ?>>Dormant</option>
                    </select>
                    <div class="form-text">
                        Dormant is normally computed automatically from savings activity.
                        Setting it manually here is an explicit administrative choice and may be
                        recomputed later if the member's activity still doesn't meet the club's dormancy policy.
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Save Status
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-danger text-white border-0">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Delete
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-4">
                <p class="mb-1 text-muted small">Permanently delete:</p>
                <p class="fw-bold mb-0"><?= htmlspecialchars($member['member_number']) ?> — <?= $fullName ?></p>
                <p class="text-danger small mt-2 mb-0">
                    <i class="bi bi-info-circle me-1"></i>This cannot be undone.
                </p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="<?= APP_URL ?>/index.php?page=member-delete" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="id" value="<?= $member['id'] ?>">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i>Delete
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
<?php if ($canAdminMember): ?>
document.getElementById('openDeleteBtn').addEventListener('click', function () {
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
});
document.getElementById('openChangeStatusBtn').addEventListener('click', function () {
    new bootstrap.Modal(document.getElementById('changeStatusModal')).show();
});
<?php endif; ?>

// ── Bulk delete savings logic ──
(function(){
    const selectAll = document.getElementById('selectAllSavings');
    const checkboxes = document.querySelectorAll('.savings-checkbox');
    const bulkBar = document.getElementById('bulkActionBar');
    const bulkCount = document.getElementById('bulkCount');
    const bulkAmount = document.getElementById('bulkAmount');
    const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
    const bulkClearBtn = document.getElementById('bulkClearBtn');
    const form = document.getElementById('bulkDeleteForm');

    if (!selectAll || !checkboxes.length) return;

    function updateBar() {
        const checked = document.querySelectorAll('.savings-checkbox:checked');
        const count = checked.length;
        let total = 0;
        checked.forEach(cb => { total += parseFloat(cb.dataset.amount || 0); });

        if (count > 0) {
            bulkBar.classList.remove('d-none');
            bulkBar.classList.add('d-flex');
        } else {
            bulkBar.classList.add('d-none');
            bulkBar.classList.remove('d-flex');
        }
        bulkCount.textContent = count;
        bulkAmount.textContent = total.toLocaleString();
    }

    selectAll.addEventListener('change', function() {
        checkboxes.forEach(cb => { cb.checked = this.checked; });
        updateBar();
    });

    checkboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            if (!this.checked) selectAll.checked = false;
            else if (document.querySelectorAll('.savings-checkbox:checked').length === checkboxes.length) {
                selectAll.checked = true;
            }
            updateBar();
        });
    });

    bulkClearBtn?.addEventListener('click', function() {
        selectAll.checked = false;
        checkboxes.forEach(cb => { cb.checked = false; });
        updateBar();
    });

    bulkDeleteBtn?.addEventListener('click', function() {
        const count = document.querySelectorAll('.savings-checkbox:checked').length;
        if (count === 0) return;
        if (confirm('Delete ' + count + ' savings record' + (count > 1 ? 's' : '') + '? This cannot be undone.')) {
            form.submit();
        }
    });
})();
</script>
