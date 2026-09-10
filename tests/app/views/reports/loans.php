<?php
$base = APP_URL . '/index.php';
$r = $report;
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-bank2 me-2 text-warning"></i>Loan Reports
        </h1>
        <p class="text-muted mb-0 small">Comprehensive loan portfolio analysis</p>
    </div>
    <div class="d-flex gap-2 flex-wrap no-print">
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer me-1"></i>Print
        </button>
        <button onclick="exportTable('loanTable','loan_report')" class="btn btn-outline-success btn-sm">
            <i class="bi bi-file-earmark-excel me-1"></i>Excel
        </button>
        <a href="<?= $base ?>?page=reports" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<!-- Filter -->
<div class="card mb-4 no-print">
    <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="bi bi-funnel me-2"></i>Filter by Date Range</h6></div>
    <div class="card-body p-4">
        <form method="GET" action="<?= $base ?>" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="report-loans">
            <div class="col-md-3">
                <label class="form-label fw-semibold">From Date</label>
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">To Date</label>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary"><i class="bi bi-arrow-clockwise me-1"></i>Generate</button>
            </div>
            <div class="col-auto">
                <a href="<?= $base ?>?page=report-loans" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="stat-card stat-card-primary">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-primary"><i class="bi bi-hash"></i></div>
                <div>
                    <div class="stat-label">Loans Issued</div>
                    <div class="stat-value"><?= number_format($r['total_issued']) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat-card stat-card-warning">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-warning"><i class="bi bi-cash"></i></div>
                <div>
                    <div class="stat-label">Total Amount Loaned</div>
                    <div class="stat-value" style="font-size:1rem">Shs <?= number_format($r['total_amount_loaned'], 2) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat-card stat-card-success">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-success"><i class="bi bi-check-circle-fill"></i></div>
                <div>
                    <div class="stat-label">Active / Completed</div>
                    <div class="stat-value"><?= $r['active_loans'] ?> / <?= $r['completed_loans'] ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat-card stat-card-danger">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-danger"><i class="bi bi-exclamation-triangle-fill"></i></div>
                <div>
                    <div class="stat-label">Overdue Loans</div>
                    <div class="stat-value"><?= number_format($r['overdue_loans']) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3 col-sm-6">
        <div class="card text-center p-3">
            <div class="fw-bold fs-5 text-danger">Shs <?= number_format($r['outstanding_balance'], 2) ?></div>
            <div class="small text-muted">Outstanding Balance</div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card text-center p-3">
            <div class="fw-bold fs-5 text-success">Shs <?= number_format($r['total_repayments'], 2) ?></div>
            <div class="small text-muted">Total Repayments</div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card text-center p-3">
            <div class="fw-bold fs-5" style="color:#F47920">Shs <?= number_format($r['total_interest'], 2) ?></div>
            <div class="small text-muted">Interest Earned</div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card text-center p-3">
            <div class="fw-bold fs-5 text-primary">Shs <?= number_format($r['avg_loan_amount'], 2) ?></div>
            <div class="small text-muted">Average Loan · Max: Shs <?= number_format($r['largest_loan'], 2) ?></div>
        </div>
    </div>
</div>

<!-- Loan Table -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">Loan Register</h6>
        <span class="badge bg-warning-subtle text-warning"><?= count($r['loans']) ?> loans</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="loanTable">
            <thead>
                <tr>
                    <th class="ps-3">#</th>
                    <th>Loan No.</th>
                    <th>Member</th>
                    <th class="text-end">Amount</th>
                    <th class="text-end">Outstanding</th>
                    <th>Issue Date</th>
                    <th>Due Date</th>
                    <th class="text-center">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($r['loans'])): ?>
                <tr><td colspan="8" class="text-center py-4 text-muted">No loans found.</td></tr>
                <?php else: foreach ($r['loans'] as $i => $l): ?>
                <tr>
                    <td class="ps-3 text-muted small"><?= $i + 1 ?></td>
                    <td>
                        <a href="<?= $base ?>?page=loan-view&id=<?= $l['id'] ?>" class="fw-semibold text-decoration-none small">
                            <?= htmlspecialchars($l['loan_number']) ?>
                        </a>
                    </td>
                    <td class="fw-semibold small"><?= htmlspecialchars($l['first_name'] . ' ' . $l['last_name']) ?></td>
                    <td class="text-end small">Shs <?= number_format($l['loan_amount'], 2) ?></td>
                    <td class="text-end fw-bold <?= $l['outstanding'] > 0 ? 'text-danger' : 'text-success' ?> small">
                        Shs <?= number_format($l['outstanding'], 2) ?>
                    </td>
                    <td class="text-muted small"><?= date('d M Y', strtotime($l['issue_date'])) ?></td>
                    <td class="text-muted small"><?= date('d M Y', strtotime($l['due_date'])) ?></td>
                    <td class="text-center">
                        <span class="badge rounded-pill <?= match($l['status']){
                            'active' => 'bg-success-subtle text-success',
                            'overdue' => 'bg-danger-subtle text-danger',
                            'completed' => 'bg-primary-subtle text-primary',
                            default => 'bg-secondary-subtle text-secondary'
                        } ?>"><?= ucfirst($l['status']) ?></span>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function exportTable(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) return;
    let csv = [];
    table.querySelectorAll('tr').forEach(row => {
        let rowData = [];
        row.querySelectorAll('td, th').forEach(col => rowData.push('"' + col.innerText.replace(/"/g, '""') + '"'));
        csv.push(rowData.join(','));
    });
    const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename + '_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
}
</script>

<style>
@media print {
    .no-print { display: none !important; }
    .card { box-shadow: none; border: 1px solid #dee2e6; }
}
</style>
