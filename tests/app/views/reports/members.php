<?php
$base = APP_URL . '/index.php';
$r = $report;
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-people-fill me-2 text-primary"></i>Member Reports
        </h1>
        <p class="text-muted mb-0 small">Comprehensive member statistics and analysis</p>
    </div>
    <div class="d-flex gap-2 flex-wrap no-print">
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer me-1"></i>Print
        </button>
        <button onclick="exportTable('memberTable','member_report')" class="btn btn-outline-success btn-sm">
            <i class="bi bi-file-earmark-excel me-1"></i>Excel
        </button>
        <a href="<?= $base ?>?page=reports" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="stat-card stat-card-primary h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-primary"><i class="bi bi-people-fill"></i></div>
                <div>
                    <div class="stat-label">Total Registered</div>
                    <div class="stat-value"><?= number_format($r['total']) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat-card stat-card-success h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-success"><i class="bi bi-person-check-fill"></i></div>
                <div>
                    <div class="stat-label">Active Members</div>
                    <div class="stat-value"><?= number_format($r['active']) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat-card stat-card-danger h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-danger"><i class="bi bi-person-x-fill"></i></div>
                <div>
                    <div class="stat-label">Inactive Members</div>
                    <div class="stat-value"><?= number_format($r['inactive']) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat-card stat-card-info h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-info"><i class="bi bi-person-plus-fill"></i></div>
                <div>
                    <div class="stat-label">New This Month</div>
                    <div class="stat-value"><?= number_format($r['new_this_month']) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Detailed Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-sm-6">
        <div class="card text-center p-3">
            <div class="fw-bold fs-4 text-danger"><?= number_format($r['never_saved']) ?></div>
            <div class="small text-muted">Never Saved</div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card text-center p-3">
            <div class="fw-bold fs-4 text-warning"><?= number_format($r['with_active_loans']) ?></div>
            <div class="small text-muted">With Active Loans</div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card text-center p-3">
            <div class="fw-bold fs-4 text-danger"><?= number_format($r['with_outstanding']) ?></div>
            <div class="small text-muted">Outstanding Loans</div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card text-center p-3">
            <div class="fw-bold fs-4 text-success"><?= number_format($r['eligible_withdrawal']) ?></div>
            <div class="small text-muted">Eligible for Withdrawal</div>
        </div>
    </div>
</div>

<!-- Member Table -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">All Members</h6>
        <span class="badge bg-primary-subtle text-primary"><?= count($r['members']) ?> members</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="memberTable">
            <thead>
                <tr>
                    <th class="ps-3">#</th>
                    <th>Member No.</th>
                    <th>Name</th>
                    <th>Phone</th>
                    <th class="text-end">Total Savings</th>
                    <th class="text-center">Active Loans</th>
                    <th class="text-center">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($r['members'])): ?>
                <tr><td colspan="7" class="text-center py-4 text-muted">No members found.</td></tr>
                <?php else: foreach ($r['members'] as $i => $m): ?>
                <tr>
                    <td class="ps-3 text-muted small"><?= $i + 1 ?></td>
                    <td class="fw-semibold small"><?= htmlspecialchars($m['member_number']) ?></td>
                    <td>
                        <a href="<?= $base ?>?page=member-view&id=<?= $m['id'] ?>" class="text-decoration-none fw-semibold small">
                            <?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?>
                        </a>
                    </td>
                    <td class="small text-muted"><?= htmlspecialchars($m['phone'] ?? '—') ?></td>
                    <td class="text-end fw-bold text-success small">Shs <?= number_format($m['total_savings'], 2) ?></td>
                    <td class="text-center">
                        <?php if ($m['active_loans'] > 0): ?>
                        <span class="badge bg-warning-subtle text-warning"><?= $m['active_loans'] ?></span>
                        <?php else: ?>
                        <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge rounded-pill <?= $m['status'] === 'active' ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?>">
                            <?= ucfirst($m['status']) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Excel Export Script -->
<script>
function exportTable(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) return;
    let csv = [];
    const rows = table.querySelectorAll('tr');
    rows.forEach(row => {
        const cols = row.querySelectorAll('td, th');
        let rowData = [];
        cols.forEach(col => rowData.push('"' + col.innerText.replace(/"/g, '""') + '"'));
        csv.push(rowData.join(','));
    });
    const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename + '_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
    URL.revokeObjectURL(url);
}
</script>

<style>
@media print {
    .no-print { display: none !important; }
    .card { box-shadow: none; border: 1px solid #dee2e6; }
}
</style>
