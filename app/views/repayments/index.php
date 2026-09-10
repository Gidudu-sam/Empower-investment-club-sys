<?php
$base = APP_URL . '/index.php';
function pmBadge(string $m): string {
    return match($m){
        'Cash'=>'bg-success-subtle text-success','Airtel Money'=>'bg-danger-subtle text-danger','MTN Mobile Money'=>'bg-warning-subtle text-warning',
        'Bank Transfer'=>'bg-info-subtle text-info','Cheque'=>'bg-warning-subtle text-warning',
        default=>'bg-secondary-subtle text-secondary'};
}
?>
<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-arrow-down-circle-fill me-2" style="color:var(--brand-orange)"></i>Loan Repayments
        </h1>
        <p class="text-muted mb-0 small">All loan repayment transactions</p>
    </div>
    <a href="<?= $base ?>?page=repayment-add" class="btn text-white fw-semibold" style="background:var(--brand-orange)">
        <i class="bi bi-plus-circle-fill me-1"></i>Record Repayment
    </a>
</div>

<?php if (!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-check-circle-fill flex-shrink-0"></i><div><?= htmlspecialchars($success) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i><div><?= htmlspecialchars($error) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-sm-4">
        <div class="stat-card stat-card-accent">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-accent"><i class="bi bi-calendar-day-fill"></i></div>
                <div>
                    <div class="stat-label">Today's Collections</div>
                    <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($todayTotal,2) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-sm-4">
        <div class="stat-card stat-card-success">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-success"><i class="bi bi-calendar-month-fill"></i></div>
                <div>
                    <div class="stat-label">This Month</div>
                    <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($monthTotal,2) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-4">
        <div class="stat-card stat-card-primary">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-primary"><i class="bi bi-cash-stack"></i></div>
                <div>
                    <div class="stat-label">Filtered Total</div>
                    <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($totalPaid,2) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <form id="filterForm" method="GET" action="<?= $base ?>" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="repayments">
            <div class="col-lg-4 col-md-5">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" id="searchInput" class="form-control"
                           placeholder="Receipt no., loan no., member…" value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="col-lg-2 col-md-3">
                <select name="method" class="form-select form-select-sm">
                    <option value="">All Methods</option>
                    <?php foreach(['Cash','Airtel Money','MTN Mobile Money','Bank Transfer','Cheque','Other'] as $pm): ?>
                    <option value="<?=$pm?>" <?=$method===$pm?'selected':''?>><?=$pm?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-3">
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($dateFrom) ?>" placeholder="From">
            </div>
            <div class="col-lg-2 col-md-3">
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($dateTo) ?>" placeholder="To">
            </div>
            <div class="col-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Filter</button>
                <?php if($search||$method||$dateFrom||$dateTo): ?>
                <a href="<?=$base?>?page=repayments" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg me-1"></i>Clear</a>
                <?php endif; ?>
                <a href="<?=$base?>?page=repayment-report" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-bar-graph me-1"></i>Report</a>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Receipt No.</th>
                    <th>Loan No.</th>
                    <th>Member</th>
                    <th class="text-end">Amount Paid</th>
                    <th class="text-end d-none d-md-table-cell">Balance After</th>
                    <th class="d-none d-md-table-cell">Method</th>
                    <th class="d-none d-lg-table-cell">Date</th>
                    <th class="text-end pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($repayments)): ?>
                <tr><td colspan="8" class="text-center py-5 text-muted">
                    <i class="bi bi-arrow-down-circle fs-1 d-block mb-2 opacity-25"></i>
                    No repayments yet. <a href="<?=$base?>?page=repayment-add">Record the first payment.</a>
                </td></tr>
                <?php else: foreach($repayments as $r): ?>
                <tr>
                    <td class="ps-3">
                        <a href="<?=$base?>?page=repayment-view&id=<?=$r['id']?>"
                           class="fw-semibold text-decoration-none" style="color:var(--brand-orange)">
                            <?= htmlspecialchars($r['repayment_number']) ?>
                        </a>
                    </td>
                    <td>
                        <a href="<?=$base?>?page=loan-view&id=<?=$r['loan_id']?>"
                           class="text-decoration-none small" style="color:var(--brand-navy)">
                            <?= htmlspecialchars($r['loan_number']) ?>
                        </a>
                    </td>
                    <td>
                        <div class="fw-semibold small"><?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?></div>
                        <div class="text-muted" style="font-size:.72rem"><?= htmlspecialchars($r['member_number']) ?></div>
                    </td>
                    <td class="text-end fw-bold text-success">Shs <?= number_format($r['amount_paid'],2) ?></td>
                    <td class="text-end d-none d-md-table-cell fw-semibold" style="color:var(--brand-navy)">
                        Shs <?= number_format($r['balance_after'],2) ?>
                    </td>
                    <td class="d-none d-md-table-cell">
                        <span class="badge <?= pmBadge($r['payment_method']) ?> rounded-pill px-2 small">
                            <?= htmlspecialchars($r['payment_method']) ?>
                        </span>
                    </td>
                    <td class="d-none d-lg-table-cell text-muted small">
                        <?= date('d M Y', strtotime($r['payment_date'])) ?>
                    </td>
                    <td class="text-end pe-3">
                        <div class="d-flex justify-content-end gap-1">
                            <a href="<?=$base?>?page=repayment-view&id=<?=$r['id']?>"
                               class="btn btn-sm btn-outline-primary" title="View"><i class="bi bi-eye"></i></a>
                            <a href="<?=$base?>?page=repayment-receipt&id=<?=$r['id']?>"
                               class="btn btn-sm btn-outline-secondary" title="Print" target="_blank"><i class="bi bi-printer"></i></a>
                            <?php if (Session::get('user_role') === 'admin'): ?>
                            <form method="POST" action="<?=$base?>?page=repayment-delete" style="display:contents"
                                  onsubmit="return confirm('Delete this payment? This will reverse the loan balance.')">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="id" value="<?=$r['id']?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if($pages > 1): ?>
    <div class="card-footer d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="text-muted small">Page <?=$currentPage?> of <?=$pages?> · <?= number_format($total) ?> records</div>
        <nav><ul class="pagination pagination-sm mb-0">
            <li class="page-item <?=$currentPage<=1?'disabled':''?>">
                <a class="page-link" href="<?=$base?>?page=repayments&p=<?=$currentPage-1?>&search=<?=urlencode($search)?>&method=<?=urlencode($method)?>&date_from=<?=urlencode($dateFrom)?>&date_to=<?=urlencode($dateTo)?>"><i class="bi bi-chevron-left"></i></a>
            </li>
            <?php for($pg=max(1,$currentPage-2);$pg<=min($pages,$currentPage+2);$pg++): ?>
            <li class="page-item <?=$pg===$currentPage?'active':''?>">
                <a class="page-link" href="<?=$base?>?page=repayments&p=<?=$pg?>&search=<?=urlencode($search)?>&method=<?=urlencode($method)?>&date_from=<?=urlencode($dateFrom)?>&date_to=<?=urlencode($dateTo)?>"><?=$pg?></a>
            </li>
            <?php endfor; ?>
            <li class="page-item <?=$currentPage>=$pages?'disabled':''?>">
                <a class="page-link" href="<?=$base?>?page=repayments&p=<?=$currentPage+1?>&search=<?=urlencode($search)?>&method=<?=urlencode($method)?>&date_from=<?=urlencode($dateFrom)?>&date_to=<?=urlencode($dateTo)?>"><i class="bi bi-chevron-right"></i></a>
            </li>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<script>
(function(){
    let t;
    const si = document.getElementById('searchInput');
    if(si) si.addEventListener('keyup', function(){ clearTimeout(t); t=setTimeout(()=>document.getElementById('filterForm').submit(),450); });
})();
</script>
