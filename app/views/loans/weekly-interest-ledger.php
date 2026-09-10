<?php
/**
 * Weekly Interest Ledger View
 * Shows week-by-week interest schedule for Business Loans with weekly frequency
 */

$loanId = (int)($_GET['loan_id'] ?? 0);
if ($loanId <= 0) {
    echo '<div class="alert alert-danger">Invalid loan ID</div>';
    return;
}

require_once APP_PATH . '/models/LoanModel.php';
require_once APP_PATH . '/models/LoanProductModel.php';

$loanModel = new LoanModel();
$productModel = new LoanProductModel();

$loan = $loanModel->find($loanId);
if (!$loan) {
    echo '<div class="alert alert-danger">Loan not found</div>';
    return;
}

$schedule = $productModel->getWeeklyInterestSchedule($loanId);
$savingsSchedule = $productModel->getWeeklySavingsSchedule($loanId);
$currentWeek = $productModel->getCurrentWeekNumber($loanId);

// Calculate totals
$totalDue = array_sum(array_column($schedule, 'interest_due'));
$totalPaid = array_sum(array_column($schedule, 'interest_paid'));
$totalBalance = $totalDue - $totalPaid;

$savingsTotalDue = array_sum(array_column($savingsSchedule, 'savings_commitment'));
$savingsTotalPaid = array_sum(array_column($savingsSchedule, 'savings_paid'));
$savingsBalance = $savingsTotalDue - $savingsTotalPaid;

$base = APP_URL . '/index.php';
?>

<style>
.week-row.current {
    background-color: #fff3cd !important;
    border-left: 4px solid #ffc107;
}
.week-row.paid {
    background-color: #d1e7dd;
}
.week-row.overdue {
    background-color: #f8d7da;
}
.week-row.partial {
    background-color: #fff3cd;
}
</style>

<?php ob_start(); ?>
<a href="<?=$base?>?page=loan-view&id=<?=$loanId?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Back to Loan
</a>
<a href="<?=$base?>?page=repayment-add&loan_id=<?=$loanId?>" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-circle me-1"></i>Record Payment
</a>
<?php $cta = ob_get_clean(); ?>
<?php extract([
    'icon'      => 'bi-calendar-week',
    'iconStyle' => 'color:var(--brand-orange)',
    'title'     => 'Weekly Interest Ledger',
    'subtitle'  => 'Loan: <strong>' . htmlspecialchars($loan['loan_number']) . '</strong> — ' . htmlspecialchars($loan['member_name'] ?? 'N/A'),
    'cta'       => $cta,
]); include VIEW_PATH . '/layouts/page-title.php'; ?>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small mb-1">Current Installment</div>
                <h3 class="mb-0 fw-bold" style="color:var(--brand-orange)">#<?= $currentWeek ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small mb-1">Total Interest Due</div>
                <h3 class="mb-0 fw-bold">Shs <?= number_format($totalDue, 0) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small mb-1">Total Paid</div>
                <h3 class="mb-0 fw-bold text-success">Shs <?= number_format($totalPaid, 0) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small mb-1">Balance</div>
                <h3 class="mb-0 fw-bold text-danger">Shs <?= number_format($totalBalance, 0) ?></h3>
            </div>
        </div>
    </div>
</div>

<!-- Weekly Interest Schedule -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom">
        <h5 class="mb-0 fw-semibold">
            <i class="bi bi-calendar3 me-2" style="color:var(--brand-orange)"></i>
            Weekly Interest Schedule
        </h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Month</th>
                        <th>Phase</th>
                        <th>Due Date</th>
                        <th class="text-end">Interest Due</th>
                        <th class="text-end">Paid</th>
                        <th class="text-end">Balance</th>
                        <th>Status</th>
                        <th>Paid Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($schedule)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">
                            No weekly interest schedule found. This may not be a weekly interest loan.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php 
                    $currentMonth = 0;
                    foreach ($schedule as $week): 
                        $isCurrent = ($week['week_number'] == $currentWeek);
                        $statusClass = $week['status'];
                        $rowClass = $isCurrent ? 'current' : $statusClass;
                        
                        $statusBadge = match($week['status']) {
                            'paid' => '<span class="badge bg-success">Paid</span>',
                            'partial' => '<span class="badge bg-warning">Partial</span>',
                            'overdue' => '<span class="badge bg-danger">Overdue</span>',
                            default => '<span class="badge bg-secondary">Pending</span>'
                        };
                        
                        $showMonthHeader = ($week['month_number'] != $currentMonth);
                        $currentMonth = $week['month_number'];
                    ?>
                    <?php if ($showMonthHeader): ?>
                    <tr class="table-secondary">
                        <td colspan="9" class="fw-bold small"><?= date('F Y', strtotime($week['due_date'])) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="week-row <?= $rowClass ?>">
                        <td class="fw-semibold">
                            <?= $week['week_number'] ?>
                            <?php if ($isCurrent): ?>
                            <span class="badge bg-warning ms-1">Now</span>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= date('M Y', strtotime($week['due_date'])) ?></td>
                        <td class="small">
                            <?= ($week['period_type'] ?? '') === 'weekly' ? 'Weekly' : 'Monthly' ?> —
                            <?= ($week['payment_type'] ?? '') === 'interest_only' ? 'Interest Only' : 'Principal + Interest' ?>
                        </td>
                        <td class="small"><?= date('d M Y', strtotime($week['due_date'])) ?></td>
                        <td class="text-end fw-semibold">
                            Shs <?= number_format($week['interest_due'], 0) ?>
                        </td>
                        <td class="text-end">
                            Shs <?= number_format($week['interest_paid'], 0) ?>
                        </td>
                        <td class="text-end fw-semibold <?= $week['balance'] > 0 ? 'text-danger' : 'text-success' ?>">
                            Shs <?= number_format($week['balance'], 0) ?>
                        </td>
                        <td><?= $statusBadge ?></td>
                        <td class="small">
                            <?= $week['paid_date'] ? date('d M Y', strtotime($week['paid_date'])) : '—' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="4" class="text-end">TOTAL:</td>
                        <td class="text-end">Shs <?= number_format($totalDue, 0) ?></td>
                        <td class="text-end">Shs <?= number_format($totalPaid, 0) ?></td>
                        <td class="text-end text-danger">Shs <?= number_format($totalBalance, 0) ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Weekly Savings Schedule -->
<?php if (!empty($savingsSchedule)): ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-bottom">
        <h5 class="mb-0 fw-semibold">
            <i class="bi bi-piggy-bank me-2" style="color:var(--brand-orange)"></i>
            Weekly Savings Commitment
        </h5>
    </div>
    <div class="card-body">
        <div class="row mb-3">
            <div class="col-md-4">
                <div class="p-3 rounded bg-light">
                    <div class="text-muted small">Total Commitment</div>
                    <div class="h4 mb-0 fw-bold">Shs <?= number_format($savingsTotalDue, 0) ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 rounded bg-light">
                    <div class="text-muted small">Total Saved</div>
                    <div class="h4 mb-0 fw-bold text-success">Shs <?= number_format($savingsTotalPaid, 0) ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 rounded bg-light">
                    <div class="text-muted small">Balance</div>
                    <div class="h4 mb-0 fw-bold text-danger">Shs <?= number_format($savingsBalance, 0) ?></div>
                </div>
            </div>
        </div>
        
        <div class="progress" style="height: 25px;">
            <?php 
            $savingsPercent = $savingsTotalDue > 0 ? ($savingsTotalPaid / $savingsTotalDue * 100) : 0;
            ?>
            <div class="progress-bar bg-success" style="width: <?= $savingsPercent ?>%">
                <?= round($savingsPercent, 1) ?>% Collected
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
