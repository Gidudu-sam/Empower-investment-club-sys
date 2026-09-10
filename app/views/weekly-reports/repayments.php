<?php
$base = APP_URL . '/index.php';
$title = fn(array $m) => ($m['gender'] === 'Female' ? 'Ms.' : 'Mr.') . ' ' . $m['first_name'] . ' ' . $m['last_name'];
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold" style="font-family:'Space Grotesk',sans-serif;">Weekly Loan Repayments</h1>
        <p class="text-muted mb-0" style="font-size:.78rem;"><i class="bi bi-calendar-week me-1"></i><?= htmlspecialchars($week['label']) ?></p>
    </div>
</div>

<ul class="nav nav-pills flex-wrap gap-2 mb-4 no-print">
    <li class="nav-item"><a class="nav-link" href="<?= $base ?>?page=weekly-savings<?= isset($_GET['week']) ? '&week='.urlencode($week['start']) : '' ?>">Savings Deposits</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= $base ?>?page=weekly-loans<?= isset($_GET['week']) ? '&week='.urlencode($week['start']) : '' ?>">Loan Disbursements</a></li>
    <li class="nav-item"><a class="nav-link active" href="<?= $base ?>?page=weekly-repayments<?= isset($_GET['week']) ? '&week='.urlencode($week['start']) : '' ?>">Loan Repayments</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= $base ?>?page=weekly-overdue">Overdue Loans</a></li>
</ul>

<div class="card mb-4 no-print">
    <div class="card-body p-3">
        <form method="GET" action="<?= $base ?>" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="weekly-repayments">
            <div class="col-md-4">
                <select name="week" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Current Week</option>
                    <?php foreach ($weeks as $w): ?>
                    <option value="<?= $w['start'] ?>" <?= ($week['start'] === $w['start']) ? 'selected' : '' ?>><?= htmlspecialchars($w['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4"><div class="stat-card"><div class="stat-label">Members Who Paid</div><div class="stat-value"><?= $summary['total_members'] ?></div></div></div>
    <div class="col-6 col-md-4"><div class="stat-card"><div class="stat-label">Total Payments</div><div class="stat-value"><?= $summary['total_payments'] ?></div></div></div>
    <div class="col-6 col-md-4"><div class="stat-card"><div class="stat-label">Total Collected</div><div class="stat-value" style="font-size:1.1rem;"><span style="font-size:.7rem;color:var(--slate-soft);">UGX</span> <?= number_format($summary['total_amount'], 0) ?></div></div></div>
</div>

<div class="d-flex gap-2 flex-wrap mb-3 no-print">
    <button onclick="copyReport()" class="btn btn-outline-primary btn-sm"><i class="bi bi-clipboard me-1"></i>Copy</button>
    <button onclick="shareWhatsApp()" class="btn btn-sm" style="background:#25D366;color:#fff;border:none;"><i class="bi bi-whatsapp me-1"></i>WhatsApp</button>
    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer me-1"></i>Print</button>
    <button onclick="exportCSV()" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Excel</button>
</div>

<div class="card">
    <div class="card-header"><h6 class="mb-0 fw-semibold" style="font-size:.82rem;"><i class="bi bi-arrow-down-circle me-2" style="color:var(--green);"></i>Loan Repayments List</h6></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="reportTable">
            <thead><tr><th class="ps-3">#</th><th>Member</th><th class="text-end">Total Paid</th><th class="text-end pe-3">Payments</th></tr></thead>
            <tbody>
                <?php if (empty($members)): ?>
                <tr><td colspan="4" class="text-center py-4 text-muted">No repayments this week.</td></tr>
                <?php else: foreach ($members as $i => $m): ?>
                <tr>
                    <td class="ps-3 text-muted"><?= $i + 1 ?>.</td>
                    <td class="fw-semibold"><?= htmlspecialchars($title($m)) ?></td>
                    <td class="text-end fw-bold" style="color:var(--green);"><?= number_format($m['total_paid'], 0) ?></td>
                    <td class="text-end pe-3 text-muted"><?= $m['payment_count'] ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($members)): ?>
            <tfoot class="border-top"><tr class="fw-bold"><td class="ps-3" colspan="2">Total</td><td class="text-end" style="color:var(--green);">UGX <?= number_format($summary['total_amount'], 0) ?></td><td class="text-end pe-3"><?= $summary['total_payments'] ?></td></tr></tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<script>
function generateReportText() {
    const week = <?= json_encode($week['label']) ?>;
    const members = <?= json_encode(array_map(fn($m) => $title($m), $members)) ?>;
    let text = week + '\n\nLOAN REPAYMENTS LIST\n\n';
    members.forEach((n, i) => { text += (i+1) + '. ' + n + '\n'; });
    text += '\nTotal Members: ' + <?= (int)$summary['total_members'] ?>;
    text += '\nTotal Collected: UGX ' + (<?= (int)$summary['total_amount'] ?>).toLocaleString();
    text += '\n\n— Empower Investment Club';
    return text;
}
function copyReport() { navigator.clipboard.writeText(generateReportText()); }
function shareWhatsApp() { window.open('https://wa.me/?text=' + encodeURIComponent(generateReportText()), '_blank'); }
function exportCSV() {
    const table = document.getElementById('reportTable');
    let csv = [];
    table.querySelectorAll('tr').forEach(row => {
        let r = []; row.querySelectorAll('td,th').forEach(c => r.push('"'+c.innerText.trim().replace(/"/g,'""')+'"'));
        if(r.length) csv.push(r.join(','));
    });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv.join('\n')],{type:'text/csv'}));
    a.download = 'weekly_repayments_' + <?= json_encode($week['start']) ?> + '.csv';
    a.click();
}
</script>
<style>@media print { .no-print { display: none !important; } }</style>
