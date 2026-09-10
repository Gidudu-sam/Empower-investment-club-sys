<?php
$base     = APP_URL . '/index.php';
$fullName = htmlspecialchars($member['first_name'].' '.$member['last_name']);
function msBadge(string $m): string {
    return match($m){
        'Cash'=>'bg-success-subtle text-success','Airtel Money'=>'bg-danger-subtle text-danger','MTN Mobile Money'=>'bg-warning-subtle text-warning',
        'Bank Transfer'=>'bg-info-subtle text-info','Cheque'=>'bg-warning-subtle text-warning',
        default=>'bg-secondary-subtle text-secondary'
    };
}
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-piggy-bank me-2 text-success"></i>Savings History
        </h1>
        <p class="text-muted mb-0 small"><?= $fullName ?> · <?= htmlspecialchars($member['member_number']) ?></p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= $base ?>?page=member-view&id=<?= $member['id'] ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-person me-1"></i>Profile
        </a>
        <a href="<?= $base ?>?page=savings-add&member_id=<?= $member['id'] ?>" class="btn btn-success btn-sm">
            <i class="bi bi-plus me-1"></i>New Deposit
        </a>
    </div>
</div>

<!-- Savings summary cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-sm-3">
        <div class="stat-card stat-card-success">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-success"><i class="bi bi-piggy-bank-fill"></i></div>
                <div>
                    <div class="stat-label">Current Balance</div>
                    <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($balance,2) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-sm-3">
        <div class="stat-card stat-card-primary">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-primary"><i class="bi bi-hash"></i></div>
                <div>
                    <div class="stat-label">Total Deposits</div>
                    <div class="stat-value"><?= number_format($depositCount) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-sm-3">
        <div class="stat-card stat-card-info">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-info"><i class="bi bi-clock-history"></i></div>
                <div>
                    <div class="stat-label">Latest Deposit</div>
                    <div class="stat-value" style="font-size:1rem">
                        <?= $latest ? 'Shs '.number_format($latest['amount'],2) : '—' ?>
                    </div>
                    <div class="stat-sub">
                        <?= $latest ? date('d M Y', strtotime($latest['transaction_date'])) : '' ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-sm-3">
        <div class="stat-card stat-card-warning">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-warning"><i class="bi bi-person-check"></i></div>
                <div>
                    <div class="stat-label">Status</div>
                    <div class="stat-value" style="font-size:1rem">
                        <span class="badge <?= $member['status']==='active'?'bg-success':'bg-secondary' ?> rounded-pill">
                            <?= ucfirst($member['status']) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- History table -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">
            <i class="bi bi-list-ul me-2 text-success"></i>Savings Transactions
        </h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Receipt No.</th>
                    <th class="text-end">Amount (Shs)</th>
                    <th>Method</th>
                    <th>Date</th>
                    <th class="d-none d-md-table-cell">Reference</th>
                    <th class="d-none d-lg-table-cell">Recorded By</th>
                    <th class="text-end pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($history)): ?>
                <tr><td colspan="7" class="text-center py-4 text-muted">
                    No savings records yet.
                    <a href="<?= $base ?>?page=savings-add&member_id=<?= $member['id'] ?>">Record the first deposit.</a>
                </td></tr>
                <?php else: foreach($history as $s): ?>
                <tr>
                    <td class="ps-3">
                        <a href="<?= $base ?>?page=savings-view&id=<?= $s['id'] ?>"
                           class="fw-semibold text-success text-decoration-none small">
                            <?= htmlspecialchars($s['receipt_number']) ?>
                        </a>
                    </td>
                    <td class="text-end fw-bold text-success"><?= number_format($s['amount'],2) ?></td>
                    <td><span class="badge <?= msBadge($s['payment_method']) ?> rounded-pill px-2"><?= htmlspecialchars($s['payment_method']) ?></span></td>
                    <td class="text-muted small"><?= date('d M Y', strtotime($s['transaction_date'])) ?></td>
                    <td class="d-none d-md-table-cell text-muted small">
                        <?= $s['reference_number'] ? htmlspecialchars($s['reference_number']) : '—' ?>
                    </td>
                    <td class="d-none d-lg-table-cell text-muted small">
                        <?= htmlspecialchars($s['cashier_name'] ?? '—') ?>
                    </td>
                    <td class="text-end pe-3">
                        <div class="d-flex justify-content-end gap-1">
                            <a href="<?= $base ?>?page=savings-view&id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-success" title="View">
                                <i class="bi bi-eye"></i>
                            </a>
                            <a href="<?= $base ?>?page=savings-receipt&id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Print" target="_blank">
                                <i class="bi bi-printer"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <?php if(!empty($history)): ?>
            <tfoot class="table-light">
                <tr>
                    <td class="ps-3 fw-bold" colspan="1">Total</td>
                    <td class="text-end fw-bold text-success">Shs <?= number_format($balance,2) ?></td>
                    <td colspan="5"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>
