<?php
$base = APP_URL . '/index.php';
$title = fn(array $m) => ($m['gender'] === 'Female' ? 'Ms.' : 'Mr.') . ' ' . $m['first_name'] . ' ' . $m['last_name'];
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold" style="font-family:'Space Grotesk',sans-serif;">Weekly Loan Disbursements</h1>
        <p class="text-muted mb-0" style="font-size:.78rem;"><i class="bi bi-calendar-week me-1"></i><?= htmlspecialchars($week['label']) ?></p>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-pills flex-wrap gap-2 mb-4 no-print">
    <li class="nav-item"><a class="nav-link" href="<?= $base ?>?page=weekly-savings<?= isset($_GET['week']) ? '&week='.urlencode($week['start']) : '' ?>">Savings Deposits</a></li>
    <li class="nav-item"><a class="nav-link active" href="<?= $base ?>?page=weekly-loans<?= isset($_GET['week']) ? '&week='.urlencode($week['start']) : '' ?>">Loan Disbursements</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= $base ?>?page=weekly-repayments<?= isset($_GET['week']) ? '&week='.urlencode($week['start']) : '' ?>">Loan Repayments</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= $base ?>?page=weekly-overdue">Overdue Loans</a></li>
</ul>

<!-- Week Selector -->
<div class="card mb-4 no-print">
    <div class="card-body p-3">
        <form method="GET" action="<?= $base ?>" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="weekly-loans">
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

<!-- Summary -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4"><div class="stat-card"><div class="stat-label">Loans Issued</div><div class="stat-value"><?= $summary['total_loans'] ?></div></div></div>
    <div class="col-6 col-md-4"><div class="stat-card"><div class="stat-label">Members</div><div class="stat-value"><?= $summary['total_members'] ?></div></div></div>
    <div class="col-6 col-md-4"><div class="stat-card"><div class="stat-label">Total Disbursed</div><div class="stat-value" style="font-size:1.1rem;"><span style="font-size:.7rem;color:var(--slate-soft);">UGX</span> <?= number_format($summary['total_amount'], 0) ?></div></div></div>
</div>

<!-- Buttons -->
<div class="d-flex gap-2 flex-wrap mb-3 no-print">
    <button onclick="copyReport()" class="btn btn-outline-primary btn-sm"><i class="bi bi-clipboard me-1"></i>Copy</button>
    <button onclick="shareWhatsApp()" class="btn btn-sm" style="background:#25D366;color:#fff;border:none;"><i class="bi bi-whatsapp me-1"></i>WhatsApp</button>
    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer me-1"></i>Print</button>
</div>

<!-- Table -->
<div class="card">
    <div class="card-header"><h6 class="mb-0 fw-semibold" style="font-size:.82rem;"><i class="bi bi-bank2 me-2" style="color:var(--gold-deep);"></i>Loan Disbursements</h6></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="reportTable">
            <thead><tr><th class="ps-3">#</th><th>Member</th><th>Loan No.</th><th class="text-end">Amount</th><th>Issue Date</th><th>Due Date</th></tr></thead>
            <tbody>
                <?php if (empty($loans)): ?>
                <tr><td colspan="6" class="text-center py-4 text-muted">No loans issued this week.</td></tr>
                <?php else: foreach ($loans as $i => $l): ?>
                <tr>
                    <td class="ps-3 text-muted"><?= $i + 1 ?>.</td>
                    <td class="fw-semibold"><?= htmlspecialchars($title($l)) ?></td>
                    <td style="font-size:.72rem;"><?= htmlspecialchars($l['loan_number']) ?></td>
                    <td class="text-end fw-bold" style="color:var(--gold-deep);"><?= number_format($l['loan_amount'], 0) ?></td>
                    <td class="text-muted" style="font-size:.72rem;"><?= date('d M Y', strtotime($l['issue_date'])) ?></td>
                    <td class="text-muted" style="font-size:.72rem;"><?= date('d M Y', strtotime($l['due_date'])) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function generateReportText() {
    const week = <?= json_encode($week['label']) ?>;
    const loans = <?= json_encode(array_map(fn($l) => $title($l) . ' - UGX ' . number_format($l['loan_amount'], 0), $loans)) ?>;
    let text = week + '\n\nLOAN DISBURSEMENTS\n\n';
    loans.forEach((l, i) => { text += (i+1) + '. ' + l + '\n'; });
    text += '\nTotal Loans: ' + <?= $summary['total_loans'] ?>;
    text += '\nTotal Disbursed: UGX ' + (<?= (int)$summary['total_amount'] ?>).toLocaleString();
    text += '\n\n— Empower Investment Club';
    return text;
}
function copyReport() { navigator.clipboard.writeText(generateReportText()); }
function shareWhatsApp() { window.open('https://wa.me/?text=' + encodeURIComponent(generateReportText()), '_blank'); }
</script>
<style>@media print { .no-print { display: none !important; } }</style>
