<?php
$base = APP_URL . '/index.php';
function savBadge(string $m): string {
    return match($m) {
        'Cash'          => 'bg-success-subtle text-success',
        'Airtel Money'    => 'bg-danger-subtle text-danger',
        'MTN Mobile Money' => 'bg-warning-subtle text-warning',
        'Bank Transfer' => 'bg-info-subtle text-info',
        'Cheque'        => 'bg-warning-subtle text-warning',
        default         => 'bg-secondary-subtle text-secondary',
    };
}
// Matches SavingsController::edit() / delete() exactly.
$canEditSavings = Session::hasRole(['admin', 'treasurer']);
$canDeleteSavings = Session::hasRole(['admin']);
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-piggy-bank-fill me-2 text-success"></i>Savings
        </h1>
        <p class="text-muted mb-0 small">All member savings deposits</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= $base ?>?page=savings-report" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-file-bar-graph me-1"></i>Reports
        </a>
        <a href="<?= $base ?>?page=savings-add" class="btn btn-success">
            <i class="bi bi-plus-circle-fill me-1"></i>Record Savings
        </a>
    </div>
</div>

<?php if (!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3" role="alert">
    <i class="bi bi-check-circle-fill flex-shrink-0"></i><div><?= htmlspecialchars($success) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i><div><?= htmlspecialchars($error) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Stat strips -->
<div class="row g-3 mb-4">
    <div class="col-6 col-sm-4">
        <div class="stat-card stat-card-success">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-success"><i class="bi bi-piggy-bank-fill"></i></div>
                <div>
                    <div class="stat-label">Total Savings</div>
                    <div class="stat-value" style="font-size:1.2rem">Shs <?= number_format($totalSavings, 2) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-sm-4">
        <div class="stat-card stat-card-primary">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-primary"><i class="bi bi-calendar-day-fill"></i></div>
                <div>
                    <div class="stat-label">Today's Deposits</div>
                    <div class="stat-value" style="font-size:1.2rem">Shs <?= number_format($todayTotal, 2) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-4">
        <div class="stat-card stat-card-info">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-info"><i class="bi bi-calendar-month-fill"></i></div>
                <div>
                    <div class="stat-label">This Month</div>
                    <div class="stat-value" style="font-size:1.2rem">Shs <?= number_format($monthTotal, 2) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-0">
    <!-- Filter bar -->
    <div class="card-header">
        <form id="filterForm" method="GET" action="<?= $base ?>" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="savings">
            <div class="col-lg-3 col-md-5">
                <label class="form-label form-label-sm mb-1">Search</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" id="searchInput" class="form-control"
                           placeholder="Receipt, member, phone…" value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="col-lg-1 col-md-2 col-sm-3">
                <label class="form-label form-label-sm mb-1">Year</label>
                <select name="year" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php
                    $yearList = $years;
                    if (!in_array((int)date('Y'), array_map('intval',$yearList))) $yearList[] = date('Y');
                    foreach ($yearList as $y):
                    ?>
                    <option value="<?= $y ?>" <?= (string)$year===(string)$y?'selected':'' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-1 col-md-2 col-sm-3">
                <label class="form-label form-label-sm mb-1">Month</label>
                <select name="month" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php for($mo=1;$mo<=12;$mo++): ?>
                    <option value="<?= $mo ?>" <?= (int)$month===$mo?'selected':'' ?>><?= date('M',mktime(0,0,0,$mo,1)) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-3">
                <label class="form-label form-label-sm mb-1">Method</label>
                <select name="method" class="form-select form-select-sm">
                    <option value="">All Methods</option>
                    <?php foreach (['Cash','Airtel Money','MTN Mobile Money','Bank Transfer','Cheque','Other'] as $pm): ?>
                    <option value="<?= $pm ?>" <?= $method===$pm?'selected':'' ?>><?= $pm ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-3 col-sm-4">
                <label class="form-label form-label-sm mb-1">From</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="col-lg-2 col-md-3 col-sm-4">
                <label class="form-label form-label-sm mb-1">To</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <div class="col-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Filter</button>
                <?php if ($search||$year||$month||$dateFrom||$dateTo||$method): ?>
                <a href="<?= $base ?>?page=savings" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg me-1"></i>Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if ($total > 0): ?>
    <div class="px-3 py-2 border-bottom bg-light small text-muted d-flex justify-content-between">
        <span><?= number_format($total) ?> record<?= $total!==1?'s':'' ?></span>
        <span class="fw-semibold text-success">Filtered Total: Shs <?= number_format($filteredTotal,2) ?></span>
    </div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Receipt No.</th>
                    <th>Member No.</th>
                    <th>Member Name</th>
                    <th class="text-end">Amount (Shs)</th>
                    <th class="d-none d-md-table-cell">Method</th>
                    <th class="d-none d-lg-table-cell">Date</th>
                    <th class="d-none d-xl-table-cell">Recorded By</th>
                    <th class="text-end pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($savings)): ?>
                <tr><td colspan="8" class="text-center py-5 text-muted">
                    <i class="bi bi-piggy-bank fs-1 d-block mb-2 opacity-25"></i>
                    <?php if($search||$year||$month||$dateFrom||$dateTo||$method): ?>
                        No records match your filters.
                    <?php else: ?>
                        No savings recorded yet.
                        <a href="<?= $base ?>?page=savings-add">Record the first one.</a>
                    <?php endif; ?>
                </td></tr>
                <?php else: foreach($savings as $s): ?>
                <tr>
                    <td class="ps-3">
                        <a href="<?= $base ?>?page=savings-view&id=<?= $s['id'] ?>"
                           class="fw-semibold text-success text-decoration-none small">
                            <?= htmlspecialchars($s['receipt_number']) ?>
                        </a>
                    </td>
                    <td class="small text-muted"><?= htmlspecialchars($s['member_number']) ?></td>
                    <td>
                        <a href="<?= $base ?>?page=member-view&id=<?= $s['member_id'] ?>"
                           class="text-decoration-none fw-semibold">
                            <?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?>
                        </a>
                    </td>
                    <td class="text-end fw-bold text-success"><?= number_format($s['amount'],2) ?></td>
                    <td class="d-none d-md-table-cell">
                        <span class="badge <?= savBadge($s['payment_method']) ?> rounded-pill px-2">
                            <?= htmlspecialchars($s['payment_method']) ?>
                        </span>
                    </td>
                    <td class="d-none d-lg-table-cell text-muted small">
                        <?= date('d M Y', strtotime($s['transaction_date'])) ?>
                    </td>
                    <td class="d-none d-xl-table-cell text-muted small">
                        <?= htmlspecialchars($s['cashier_name'] ?? '—') ?>
                    </td>
                    <td class="text-end pe-3">
                        <div class="d-flex justify-content-end gap-1">
                            <a href="<?= $base ?>?page=savings-view&id=<?= $s['id'] ?>"
                               class="btn btn-sm btn-outline-success" title="View">
                                <i class="bi bi-eye"></i>
                            </a>
                            <a href="<?= $base ?>?page=savings-receipt&id=<?= $s['id'] ?>"
                               class="btn btn-sm btn-outline-secondary" title="Print" target="_blank">
                                <i class="bi bi-printer"></i>
                            </a>
                            <?php /* Stage 13-F3: defense-in-depth -- posted rows are also blocked at the model layer */ ?>
                            <?php if ($canEditSavings && empty($s['journal_entry_id'])): ?>
                            <a href="<?= $base ?>?page=savings-edit&id=<?= $s['id'] ?>"
                               class="btn btn-sm btn-outline-primary" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php endif; ?>
                            <?php if ($canDeleteSavings): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger js-delete"
                                    data-id="<?= $s['id'] ?>"
                                    data-receipt="<?= htmlspecialchars($s['receipt_number']) ?>"
                                    data-amount="Shs <?= number_format($s['amount'],2) ?>">
                                <i class="bi bi-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
    <div class="card-footer d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="text-muted small">Page <?= $currentPage ?> of <?= $pages ?> · <?= number_format($total) ?> records</div>
        <nav><ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $currentPage<=1?'disabled':'' ?>">
                <a class="page-link" href="<?= $base ?>?page=savings&p=<?=$currentPage-1?>&search=<?=urlencode($search)?>&year=<?=urlencode($year)?>&month=<?=urlencode($month)?>&method=<?=urlencode($method)?>&date_from=<?=urlencode($dateFrom)?>&date_to=<?=urlencode($dateTo)?>"><i class="bi bi-chevron-left"></i></a>
            </li>
            <?php for($pg=max(1,$currentPage-2);$pg<=min($pages,$currentPage+2);$pg++): ?>
            <li class="page-item <?= $pg===$currentPage?'active':'' ?>">
                <a class="page-link" href="<?= $base ?>?page=savings&p=<?=$pg?>&search=<?=urlencode($search)?>&year=<?=urlencode($year)?>&month=<?=urlencode($month)?>&method=<?=urlencode($method)?>&date_from=<?=urlencode($dateFrom)?>&date_to=<?=urlencode($dateTo)?>"><?=$pg?></a>
            </li>
            <?php endfor; ?>
            <li class="page-item <?= $currentPage>=$pages?'disabled':'' ?>">
                <a class="page-link" href="<?= $base ?>?page=savings&p=<?=$currentPage+1?>&search=<?=urlencode($search)?>&year=<?=urlencode($year)?>&month=<?=urlencode($month)?>&method=<?=urlencode($method)?>&date_from=<?=urlencode($dateFrom)?>&date_to=<?=urlencode($dateTo)?>"><i class="bi bi-chevron-right"></i></a>
            </li>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-danger text-white border-0">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Delete</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-4">
                <p class="mb-1 text-muted small">Permanently delete:</p>
                <p class="fw-bold mb-1" id="deleteReceipt">—</p>
                <p class="text-muted small mb-0" id="deleteAmount">—</p>
                <p class="text-danger small mt-2 mb-0"><i class="bi bi-info-circle me-1"></i>This cannot be undone.</p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="<?= $base ?>?page=savings-delete" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="id" id="confirmDeleteId" value="">
                    <button type="submit" id="confirmDeleteBtn" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    document.querySelectorAll('.js-delete').forEach(function(btn){
        btn.addEventListener('click', function(){
            document.getElementById('deleteReceipt').textContent = btn.dataset.receipt;
            document.getElementById('deleteAmount').textContent  = btn.dataset.amount;
            document.getElementById('confirmDeleteId').value = btn.dataset.id;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        });
    });
    let t;
    const si = document.getElementById('searchInput');
    if(si) si.addEventListener('keyup', function(){ clearTimeout(t); t=setTimeout(()=>document.getElementById('filterForm').submit(), 450); });
})();
</script>
