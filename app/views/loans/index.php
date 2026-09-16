<?php
$base = APP_URL . '/index.php';
function loanBadge(string $s): string {
    return match($s) {
        'active'    => 'bg-success-subtle text-success',
        'completed' => 'bg-primary-subtle text-primary',
        'overdue'   => 'bg-danger-subtle text-danger',
        'pending_approval' => 'bg-warning-subtle text-warning',
        'approved'  => 'bg-info-subtle text-info',
        'rejected'  => 'bg-danger-subtle text-danger',
        default     => 'bg-secondary-subtle text-secondary',
    };
}
function loanStatusLabel(string $s): string {
    return $s === 'pending_approval' ? 'Pending Approval' : ucfirst($s);
}
function daysLabel(int $d): string {
    if ($d < 0)   return '<span class="badge bg-danger rounded-pill">'.abs($d).'d overdue</span>';
    if ($d === 0) return '<span class="badge bg-danger rounded-pill">Due Today</span>';
    if ($d <= 3)  return '<span class="badge bg-warning text-dark rounded-pill">'.$d.'d left</span>';
    if ($d <= 7)  return '<span class="badge bg-info text-dark rounded-pill">'.$d.'d left</span>';
    return '<span class="text-muted small">'.date('d M Y', strtotime('+'.$d.' days')).'</span>';
}
// Matches LoanController::requireWriteAccess() / delete() exactly.
$canEditLoan = Session::hasRole(['admin', 'treasurer', 'loans_officer']);
$canDeleteLoan = Session::hasRole(['admin']);
// Matches LoanController::requireOriginateAccess() exactly -- narrower than
// $canEditLoan above: treasurer keeps edit/complete but not origination.
$canAddLoan = Session::hasRole(['admin', 'loans_officer']);
// Awaiting-Approval sidebar link (Chairman) reuses this same list, filtered
// by status -- swap the heading so it doesn't look like the same page as
// the plain Loan Register.
$isAwaitingApprovalView = ($status === 'pending_approval');
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <?php if ($isAwaitingApprovalView): ?>
            <i class="bi bi-hourglass-split me-2 text-warning"></i>Loans Awaiting Approval
            <?php else: ?>
            <i class="bi bi-bank2 me-2 text-warning"></i>Loan Register
            <?php endif; ?>
        </h1>
        <p class="text-muted mb-0 small">
            <?= $isAwaitingApprovalView
                ? 'Loan applications submitted and waiting on a decision'
                : 'Record and track all approved loans' ?>
        </p>
    </div>
    <?php if ($canAddLoan): ?>
    <a href="<?= $base ?>?page=loan-add" class="btn btn-warning text-white">
        <i class="bi bi-plus-circle-fill me-1"></i>Record Loan
    </a>
    <?php endif; ?>
</div>

<!-- Alerts -->
<?php if (!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-check-circle-fill flex-shrink-0"></i>
    <div><?= htmlspecialchars($success) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
    <div><?= htmlspecialchars($error) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Notifications panel -->
<?php if (!empty($alerts)): ?>
<div class="card mb-4 border-warning border-opacity-50">
    <div class="card-header bg-warning bg-opacity-10 d-flex align-items-center gap-2">
        <i class="bi bi-bell-fill text-warning"></i>
        <h6 class="mb-0 fw-semibold text-warning">Loan Notifications (<?= count($alerts) ?>)</h6>
    </div>
    <div class="card-body p-0">
        <ul class="list-group list-group-flush">
            <?php foreach ($alerts as $a):
                $days = (int)$a['days_remaining'];
                if ($days < 0) {
                    $icon = 'bi-exclamation-triangle-fill text-danger';
                    $msg  = "OVERDUE by " . abs($days) . " day" . (abs($days)!==1?'s':'');
                } elseif ($days === 0) {
                    $icon = 'bi-alarm-fill text-danger';
                    $msg  = "Due TODAY";
                } elseif ($days <= 3) {
                    $icon = 'bi-exclamation-circle-fill text-warning';
                    $msg  = "Due in {$days} day" . ($days!==1?'s':'');
                } else {
                    $icon = 'bi-info-circle-fill text-info';
                    $msg  = "Due in {$days} days";
                }
            ?>
            <li class="list-group-item d-flex align-items-center gap-3 py-2 px-3">
                <i class="bi <?= $icon ?> flex-shrink-0"></i>
                <div class="flex-grow-1">
                    <span class="fw-semibold small"><?= htmlspecialchars($a['first_name'].' '.$a['last_name']) ?></span>
                    <span class="text-muted small ms-1">(<?= htmlspecialchars($a['member_number']) ?>)</span>
                    &mdash;
                    <span class="fw-semibold small text-warning"><?= htmlspecialchars($a['loan_number']) ?></span>
                    &mdash; Shs <?= number_format($a['outstanding'],2) ?> outstanding
                </div>
                <span class="small fw-semibold"><?= $msg ?></span>
                <a href="<?= $base ?>?page=loan-view&id=<?= $a['id'] ?>"
                   class="btn btn-sm btn-outline-warning ms-2">View</a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-sm-4">
        <div class="stat-card stat-card-warning">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-warning"><i class="bi bi-bank2"></i></div>
                <div>
                    <div class="stat-label">Active Loans</div>
                    <div class="stat-value"><?= number_format($activeCount) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-sm-4">
        <div class="stat-card stat-card-danger">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-danger"><i class="bi bi-exclamation-triangle-fill"></i></div>
                <div>
                    <div class="stat-label">Overdue Loans</div>
                    <div class="stat-value"><?= number_format($overdueCount) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-4">
        <div class="stat-card stat-card-primary">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-primary"><i class="bi bi-cash-stack"></i></div>
                <div>
                    <div class="stat-label">Outstanding Balance</div>
                    <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($outstanding,2) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Table card -->
<div class="card">
    <div class="card-header">
        <form id="filterForm" method="GET" action="<?= $base ?>" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="loans">

            <div class="col-lg-4 col-md-5">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" id="searchInput" class="form-control"
                           placeholder="Loan no., member name, number…"
                           value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>

            <div class="col-lg-2 col-md-3">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <option value="draft"            <?= $status==='draft'           ?'selected':'' ?>>Draft</option>
                    <option value="pending_approval" <?= $status==='pending_approval'?'selected':'' ?>>Pending Approval</option>
                    <option value="approved"         <?= $status==='approved'        ?'selected':'' ?>>Approved</option>
                    <option value="rejected"         <?= $status==='rejected'        ?'selected':'' ?>>Rejected</option>
                    <option value="active"    <?= $status==='active'   ?'selected':'' ?>>Active</option>
                    <option value="overdue"   <?= $status==='overdue'  ?'selected':'' ?>>Overdue</option>
                    <option value="completed" <?= $status==='completed'?'selected':'' ?>>Completed</option>
                </select>
            </div>

            <div class="col-lg-2 col-md-3">
                <select name="filter" class="form-select form-select-sm">
                    <option value="">All Loans</option>
                    <option value="overdue"   <?= $filter==='overdue'   ?'selected':'' ?>>Overdue</option>
                    <option value="due-today" <?= $filter==='due-today' ?'selected':'' ?>>Due Today</option>
                    <option value="due-week"  <?= $filter==='due-week'  ?'selected':'' ?>>Due This Week</option>
                    <option value="due-month" <?= $filter==='due-month' ?'selected':'' ?>>Due This Month</option>
                </select>
            </div>

            <div class="col-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-funnel me-1"></i>Filter
                </button>
                <?php if ($search||$status||$filter): ?>
                <a href="<?= $base ?>?page=loans" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-x-lg me-1"></i>Clear
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Loan No.</th>
                    <th>Member / Account</th>
                    <th class="d-none d-md-table-cell">Loan Type</th>
                    <th class="text-end">Amount</th>
                    <th class="text-end d-none d-md-table-cell">Outstanding</th>
                    <th class="d-none d-lg-table-cell">Issue Date</th>
                    <th class="d-none d-lg-table-cell">Due / Days Left</th>
                    <th class="d-none d-xl-table-cell">Funding Source</th>
                    <th class="text-center">Status</th>
                    <th class="text-end pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($loans)): ?>
                <tr><td colspan="9" class="text-center py-5 text-muted">
                    <i class="bi bi-bank2 fs-1 d-block mb-2 opacity-25"></i>
                    <?php if ($search||$status||$filter): ?>
                        No loans match your filters.
                    <?php elseif ($canAddLoan): ?>
                        No loans recorded yet. <a href="<?= $base ?>?page=loan-add">Record the first loan.</a>
                    <?php else: ?>
                        No loans recorded yet.
                    <?php endif; ?>
                </td></tr>
                <?php else: foreach($loans as $l):
                    $days = (int)($l['days_remaining'] ?? 999);
                    $isOverdue = $l['status']==='overdue' || ($l['status']==='active' && $days < 0);
                ?>
                <tr class="<?= $isOverdue ? 'table-danger-subtle' : '' ?>">
                    <td class="ps-3">
                        <a href="<?= $base ?>?page=loan-view&id=<?= $l['id'] ?>"
                           class="fw-semibold text-decoration-none" style="color:var(--brand-navy)">
                            <?= htmlspecialchars($l['loan_number']) ?>
                        </a>
                    </td>
                    <td>
                        <a href="<?= $base ?>?page=member-view&id=<?= $l['member_id'] ?>"
                           class="text-decoration-none fw-semibold">
                            <?= htmlspecialchars($l['first_name'].' '.$l['last_name']) ?>
                        </a>
                        <div class="text-muted" style="font-size:.72rem"><?= htmlspecialchars($l['member_number']) ?></div>
                        <?php if (!empty($l['account_number'])): ?>
                        <div style="font-size:.7rem;color:var(--brand-navy)"><i class="bi bi-hash"></i><?= htmlspecialchars($l['account_number']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="d-none d-md-table-cell small text-muted">
                        <?= htmlspecialchars($l['loan_type_name'] ?? '—') ?>
                    </td>
                    <td class="text-end fw-semibold small">Shs <?= number_format($l['loan_amount'],2) ?></td>
                    <td class="text-end fw-bold d-none d-md-table-cell" style="color:var(--brand-navy)">
                        Shs <?= number_format($l['outstanding'],2) ?>
                    </td>
                    <td class="d-none d-lg-table-cell text-muted small">
                        <?= date('d M Y', strtotime($l['issue_date'])) ?>
                    </td>
                    <td class="d-none d-lg-table-cell small">
                        <?php if ($l['status'] !== 'completed'): ?>
                            <?= daysLabel($days) ?>
                        <?php else: ?>
                            <span class="text-muted"><?= date('d M Y', strtotime($l['due_date'])) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="d-none d-xl-table-cell small">
                        <?php if (!empty($l['journal_entry_id']) && !empty($l['disbursement_method'])): ?>
                            <span class="badge bg-success-subtle text-success"><?= htmlspecialchars($l['disbursement_method']) ?></span>
                        <?php elseif (in_array($l['status'], ['active','overdue','completed','defaulted'], true)): ?>
                            <span class="badge bg-secondary-subtle text-secondary">Unclassified / Historical</span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge <?= loanBadge($l['status']) ?> rounded-pill px-2">
                            <?= loanStatusLabel($l['status']) ?>
                        </span>
                    </td>
                    <td class="text-end pe-3">
                        <div class="d-flex justify-content-end gap-1">
                            <a href="<?= $base ?>?page=loan-view&id=<?= $l['id'] ?>"
                               class="btn btn-sm btn-outline-primary" title="View"><i class="bi bi-eye"></i></a>
                            <?php /* Stage 13-F3: defense-in-depth -- posted loans are also blocked at the model layer */ ?>
                            <?php if ($canEditLoan && empty($l['journal_entry_id'])): ?>
                            <a href="<?= $base ?>?page=loan-edit&id=<?= $l['id'] ?>"
                               class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                            <?php if ($l['status'] !== 'completed'): ?>
                            <form method="POST" action="<?= $base ?>?page=loan-complete" style="display:contents"
                                  onsubmit="return confirm('Mark this loan as completed?')">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="id" value="<?= $l['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-success" title="Mark Complete">
                                    <i class="bi bi-check-circle"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($canDeleteLoan): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger js-delete"
                                    data-id="<?= $l['id'] ?>"
                                    data-num="<?= htmlspecialchars($l['loan_number']) ?>"
                                    title="Delete"><i class="bi bi-trash"></i></button>
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
                <a class="page-link" href="<?= $base ?>?page=loans&p=<?=$currentPage-1?>&search=<?=urlencode($search)?>&status=<?=urlencode($status)?>&filter=<?=urlencode($filter)?>"><i class="bi bi-chevron-left"></i></a>
            </li>
            <?php for($pg=max(1,$currentPage-2);$pg<=min($pages,$currentPage+2);$pg++): ?>
            <li class="page-item <?= $pg===$currentPage?'active':'' ?>">
                <a class="page-link" href="<?= $base ?>?page=loans&p=<?=$pg?>&search=<?=urlencode($search)?>&status=<?=urlencode($status)?>&filter=<?=urlencode($filter)?>"><?=$pg?></a>
            </li>
            <?php endfor; ?>
            <li class="page-item <?= $currentPage>=$pages?'disabled':'' ?>">
                <a class="page-link" href="<?= $base ?>?page=loans&p=<?=$currentPage+1?>&search=<?=urlencode($search)?>&status=<?=urlencode($status)?>&filter=<?=urlencode($filter)?>"><i class="bi bi-chevron-right"></i></a>
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
                <p class="mb-1 text-muted small">Permanently delete loan:</p>
                <p class="fw-bold mb-0" id="deleteLoanNum">—</p>
                <p class="text-danger small mt-2 mb-0"><i class="bi bi-info-circle me-1"></i>This cannot be undone.</p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="<?= $base ?>?page=loan-delete" class="d-inline">
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
        btn.addEventListener('click',function(){
            document.getElementById('deleteLoanNum').textContent = btn.dataset.num;
            document.getElementById('confirmDeleteId').value = btn.dataset.id;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        });
    });
    let t;
    const si = document.getElementById('searchInput');
    if(si) si.addEventListener('keyup', function(){ clearTimeout(t); t=setTimeout(()=>document.getElementById('filterForm').submit(),450); });
})();
</script>
