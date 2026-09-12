<?php
/**
 * Full-width "financial record" layout (2026-09), matching the pattern
 * already applied to Expenses -- identity+actions in the page-title CTA
 * slot instead of a separate sidebar column, single stack of full-width
 * sections below. Added a Recent Transactions table (this loan's other
 * repayments, most recent first) sourced from RepaymentModel::forLoan(),
 * already-proven data -- no new query logic.
 */
$base = APP_URL . '/index.php';
$r    = $repayment;
$pct  = $r['total_payable'] > 0 ? min(100, round((($r['total_payable'] - $r['balance_after']) / $r['total_payable']) * 100)) : 0;

ob_start(); ?>
<a href="<?= $base ?>?page=loan-view&id=<?= $r['loan_id'] ?>" class="btn btn-sm btn-outline-warning text-dark">
    <i class="bi bi-bank2 me-1"></i>View Loan
</a>
<a href="<?= $base ?>?page=repayment-add&loan_id=<?= $r['loan_id'] ?>" class="btn btn-sm text-white" style="background:var(--brand-orange)">
    <i class="bi bi-plus me-1"></i>New Payment
</a>
<a href="<?= $base ?>?page=repayment-loan&loan_id=<?= $r['loan_id'] ?>" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-list me-1"></i>History
</a>
<a href="<?= $base ?>?page=repayment-receipt&id=<?= $r['id'] ?>" class="btn btn-sm btn-success" target="_blank">
    <i class="bi bi-printer me-1"></i>Print Receipt
</a>
<?php
$cta      = ob_get_clean();
$icon     = 'bi-receipt';
$title    = 'Repayment ' . htmlspecialchars($r['repayment_number']);
$subtitle = htmlspecialchars($r['first_name'] . ' ' . $r['last_name'] . ' (' . $r['member_number'] . ')')
          . ' &middot; Loan ' . htmlspecialchars($r['loan_number']);
?>

<?php require VIEW_PATH . '/layouts/page-title.php'; ?>

<?php if (!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-check-circle-fill flex-shrink-0"></i><div><?= htmlspecialchars($success) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Payment details -->
<div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-receipt me-2" style="color:var(--brand-orange)"></i>Payment Details</h6>
        <span class="fw-bold" style="color:var(--ink);font-size:1.1rem"><?= htmlspecialchars($r['repayment_number']) ?></span>
    </div>
    <div class="card-body p-4">
        <div class="row g-4">
            <div class="col-sm-4">
                <div class="detail-label">Amount Paid</div>
                <div class="fw-bold fs-3 text-success">Shs <?= number_format($r['amount_paid'],2) ?></div>
            </div>
            <div class="col-sm-4">
                <div class="detail-label">Payment Date</div>
                <div class="detail-value fw-semibold"><?= date('d F Y', strtotime($r['payment_date'])) ?></div>
            </div>
            <div class="col-sm-4">
                <div class="detail-label">Payment Method</div>
                <div class="detail-value"><?= htmlspecialchars($r['payment_method']) ?></div>
            </div>
            <div class="col-sm-4">
                <div class="detail-label">Balance Before</div>
                <div class="detail-value text-danger">Shs <?= number_format($r['balance_before'],2) ?></div>
            </div>
            <div class="col-sm-4">
                <div class="detail-label">Balance After</div>
                <div class="detail-value fw-bold <?= $r['balance_after'] <= 0 ? 'text-success' : 'text-danger' ?>">
                    Shs <?= number_format($r['balance_after'],2) ?>
                    <?php if ($r['balance_after'] <= 0): ?>
                    <span class="badge bg-success ms-1 small">CLEARED</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ((float)($r['penalty_paid'] ?? 0) > 0): ?>
            <div class="col-sm-4">
                <div class="detail-label">Principal Portion</div>
                <div class="detail-value"><?= number_format((float)$r['principal_paid'], 2) ?></div>
            </div>
            <div class="col-sm-4">
                <div class="detail-label">Penalty Portion</div>
                <div class="detail-value text-warning fw-semibold"><?= number_format((float)$r['penalty_paid'], 2) ?></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($r['cash_reference_number'])): ?>
            <div class="col-sm-4">
                <div class="detail-label">Cash Reference</div>
                <div class="detail-value"><?= htmlspecialchars($r['cash_reference_number']) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($r['reference_number']): ?>
            <div class="col-sm-4">
                <div class="detail-label">Reference Number</div>
                <div class="detail-value"><?= htmlspecialchars($r['reference_number']) ?></div>
            </div>
            <?php endif; ?>
            <div class="col-sm-4">
                <div class="detail-label">Received By</div>
                <div class="detail-value"><?= htmlspecialchars($r['cashier_name'] ?? '—') ?></div>
            </div>
            <?php if ($r['notes']): ?>
            <div class="col-12">
                <div class="detail-label">Notes</div>
                <div class="detail-value text-muted"><?= nl2br(htmlspecialchars($r['notes'])) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Repayment progress -->
<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0 fw-semibold">Loan Repayment Progress</h6></div>
    <div class="card-body p-4">
        <div class="d-flex justify-content-between small text-muted mb-1">
            <span>Loan: Shs <?= number_format($r['total_payable'],2) ?></span>
            <span><?= $pct ?>% repaid</span>
        </div>
        <div class="progress mb-3" style="height:12px;border-radius:99px;">
            <div class="progress-bar bg-success"
                 role="progressbar" style="width:<?=$pct?>%"
                 aria-valuenow="<?=$pct?>" aria-valuemin="0" aria-valuemax="100"></div>
        </div>
        <div class="row text-center small">
            <div class="col-4">
                <div class="text-muted">Loan Amount</div>
                <div class="fw-bold">Shs <?= number_format($r['loan_amount'],2) ?></div>
            </div>
            <div class="col-4">
                <div class="text-muted">Outstanding</div>
                <div class="fw-bold <?= $r['loan_outstanding'] <= 0 ? 'text-success' : 'text-danger' ?>">
                    Shs <?= number_format($r['loan_outstanding'],2) ?>
                </div>
            </div>
            <div class="col-4">
                <div class="text-muted">This Payment</div>
                <div class="fw-bold text-success">Shs <?= number_format($r['amount_paid'],2) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Recent transactions -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-clock-history me-2"></i>Recent Transactions</h6>
        <a href="<?= $base ?>?page=repayment-loan&loan_id=<?= $r['loan_id'] ?>" class="small text-decoration-none">Full history &rarr;</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Date</th>
                    <th>Description</th>
                    <th class="text-end">Principal</th>
                    <th class="text-end">Interest</th>
                    <th class="text-end pe-3">Closing Balance</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentTransactions)): ?>
                <tr><td colspan="5" class="text-center py-4 text-muted">No repayment history for this loan yet.</td></tr>
                <?php else: foreach ($recentTransactions as $t): ?>
                <tr class="<?= (int)$t['id'] === (int)$r['id'] ? 'table-warning-subtle' : '' ?>">
                    <td class="ps-3 small"><?= date('d M Y', strtotime($t['payment_date'])) ?></td>
                    <td>
                        <span class="small fw-semibold"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $t['payment_type']))) ?></span>
                        <div class="text-muted" style="font-size:.7rem"><?= htmlspecialchars($t['repayment_number']) ?></div>
                    </td>
                    <td class="text-end small">Shs <?= number_format((float)$t['principal_paid'], 2) ?></td>
                    <td class="text-end small">Shs <?= number_format((float)$t['interest_paid'], 2) ?></td>
                    <td class="text-end pe-3 fw-semibold small">Shs <?= number_format((float)$t['balance_after'], 2) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
