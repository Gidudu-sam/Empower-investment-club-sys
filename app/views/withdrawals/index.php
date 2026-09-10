<?php
$base = APP_URL . '/index.php';
function wdlBadge(string $m): string {
    return match($m){ 'Cash'=>'bg-success-subtle text-success','Airtel Money'=>'bg-danger-subtle text-danger','MTN Mobile Money'=>'bg-warning-subtle text-warning',
        'Bank Transfer'=>'bg-info-subtle text-info','Cheque'=>'bg-warning-subtle text-warning',
        default=>'bg-secondary-subtle text-secondary'};
}
// Matches WithdrawalController::requireProcessAccess() exactly.
$canProcessWithdrawal = Session::hasRole(['admin', 'treasurer', 'cashier']);
?>
<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-box-arrow-up-right me-2 text-danger"></i>Withdrawals
        </h1>
        <p class="text-muted mb-0 small">Annual member withdrawals & share retention</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?=$base?>?page=withdrawal-report" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-file-bar-graph me-1"></i>Report
        </a>
        <?php if ($canProcessWithdrawal): ?>
        <a href="<?=$base?>?page=withdrawal-process" class="btn btn-danger">
            <i class="bi bi-plus-circle-fill me-1"></i>Process Withdrawal
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if(!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-check-circle-fill flex-shrink-0"></i><div><?= htmlspecialchars($success) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if(!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i><div><?= htmlspecialchars($error) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-sm-4">
        <div class="stat-card stat-card-danger">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-danger"><i class="bi bi-box-arrow-up-right"></i></div>
                <div>
                    <div class="stat-label">Total Cash Paid Out</div>
                    <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($grandTotal,2) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-sm-4">
        <div class="stat-card stat-card-success">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-success"><i class="bi bi-shield-check-fill"></i></div>
                <div>
                    <div class="stat-label">Total Retained Shares</div>
                    <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($totalRetained,2) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-4">
        <div class="stat-card stat-card-warning">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-warning"><i class="bi bi-calendar-year"></i></div>
                <div>
                    <div class="stat-label">This Year (<?= date('Y') ?>)</div>
                    <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($annualTotal,2) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <form id="filterForm" method="GET" action="<?=$base?>" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="withdrawals">
            <div class="col-lg-4 col-md-5">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" id="searchInput" class="form-control"
                           placeholder="Withdrawal no., member…" value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="col-lg-2 col-md-2">
                <select name="year" class="form-select form-select-sm">
                    <option value="0">All Years</option>
                    <?php
                    $yl = $years;
                    if(!in_array((int)date('Y'),array_map('intval',$yl))) $yl[]=(int)date('Y');
                    foreach($yl as $y): ?>
                    <option value="<?=$y?>" <?=$year===(int)$y?'selected':''?>><?=$y?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-2">
                <select name="method" class="form-select form-select-sm">
                    <option value="">All Methods</option>
                    <?php foreach(['Cash','Airtel Money','MTN Mobile Money','Bank Transfer','Cheque','Other'] as $pm): ?>
                    <option value="<?=$pm?>" <?=$method===$pm?'selected':''?>><?=$pm?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Filter</button>
                <?php if($search||$year||$method): ?>
                <a href="<?=$base?>?page=withdrawals" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg me-1"></i>Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if($total > 0): ?>
    <div class="px-3 py-2 border-bottom bg-light small text-muted d-flex justify-content-between">
        <span><?= number_format($total) ?> record<?= $total!==1?'s':'' ?></span>
        <span>Filtered Cash Out: <strong class="text-danger">Shs <?= number_format($totalAmt,2) ?></strong></span>
    </div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Withdrawal No.</th>
                    <th>Member</th>
                    <th class="text-center">Type</th>
                    <th class="text-center">Year</th>
                    <th class="text-end">Cash Paid</th>
                    <th class="text-end d-none d-md-table-cell">Retained Shares</th>
                    <th class="d-none d-md-table-cell">Method</th>
                    <th class="d-none d-lg-table-cell">Date</th>
                    <th class="text-end pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($withdrawals)): ?>
                <tr><td colspan="9" class="text-center py-5 text-muted">
                    <i class="bi bi-box-arrow-up-right fs-1 d-block mb-2 opacity-25"></i>
                    No withdrawals yet.
                    <?php if ($canProcessWithdrawal): ?>
                    <a href="<?=$base?>?page=withdrawal-process">Process the first withdrawal.</a>
                    <?php endif; ?>
                </td></tr>
                <?php else: foreach($withdrawals as $w): ?>
                <tr>
                    <td class="ps-3">
                        <a href="<?=$base?>?page=withdrawal-view&id=<?=$w['id']?>"
                           class="fw-semibold text-danger text-decoration-none small">
                            <?= htmlspecialchars($w['withdrawal_number']) ?>
                        </a>
                    </td>
                    <td>
                        <a href="<?=$base?>?page=member-view&id=<?=$w['member_id']?>" class="text-decoration-none fw-semibold">
                            <?= htmlspecialchars($w['first_name'].' '.$w['last_name']) ?>
                        </a>
                        <div class="text-muted" style="font-size:.72rem"><?= htmlspecialchars($w['member_number']) ?></div>
                    </td>
                    <td class="text-center">
                        <?php $isAnnualRow = ($w['withdrawal_type'] ?? 'annual_compulsory') === 'annual_compulsory'; ?>
                        <span class="badge <?= $isAnnualRow ? 'bg-danger-subtle text-danger' : 'bg-info-subtle text-info' ?> small">
                            <?= $isAnnualRow ? 'Annual' : 'Voluntary' ?>
                        </span>
                    </td>
                    <td class="text-center"><span class="badge bg-primary-subtle text-primary rounded-pill"><?= $w['financial_year'] ?></span></td>
                    <td class="text-end fw-bold text-danger">Shs <?= number_format($w['withdrawal_amount'],2) ?></td>
                    <td class="text-end d-none d-md-table-cell fw-semibold text-success">Shs <?= number_format($w['retained_amount'],2) ?></td>
                    <td class="d-none d-md-table-cell">
                        <span class="badge <?= wdlBadge($w['payment_method']) ?> rounded-pill px-2 small"><?= htmlspecialchars($w['payment_method']) ?></span>
                    </td>
                    <td class="d-none d-lg-table-cell text-muted small"><?= date('d M Y', strtotime($w['withdrawal_date'])) ?></td>
                    <td class="text-end pe-3">
                        <div class="d-flex justify-content-end gap-1">
                            <a href="<?=$base?>?page=withdrawal-view&id=<?=$w['id']?>" class="btn btn-sm btn-outline-danger" title="View"><i class="bi bi-eye"></i></a>
                            <a href="<?=$base?>?page=withdrawal-receipt&id=<?=$w['id']?>" class="btn btn-sm btn-outline-secondary" title="Print" target="_blank"><i class="bi bi-printer"></i></a>
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
            <li class="page-item <?=$currentPage<=1?'disabled':''?>"><a class="page-link" href="<?=$base?>?page=withdrawals&p=<?=$currentPage-1?>&search=<?=urlencode($search)?>&year=<?=$year?>&method=<?=urlencode($method)?>"><i class="bi bi-chevron-left"></i></a></li>
            <?php for($pg=max(1,$currentPage-2);$pg<=min($pages,$currentPage+2);$pg++): ?>
            <li class="page-item <?=$pg===$currentPage?'active':''?>"><a class="page-link" href="<?=$base?>?page=withdrawals&p=<?=$pg?>&search=<?=urlencode($search)?>&year=<?=$year?>&method=<?=urlencode($method)?>"><?=$pg?></a></li>
            <?php endfor; ?>
            <li class="page-item <?=$currentPage>=$pages?'disabled':''?>"><a class="page-link" href="<?=$base?>?page=withdrawals&p=<?=$currentPage+1?>&search=<?=urlencode($search)?>&year=<?=$year?>&method=<?=urlencode($method)?>"><i class="bi bi-chevron-right"></i></a></li>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<script>
(function(){ let t; const si=document.getElementById('searchInput');
if(si) si.addEventListener('keyup',function(){ clearTimeout(t); t=setTimeout(()=>document.getElementById('filterForm').submit(),450); }); })();
</script>
