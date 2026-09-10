<?php
$base = APP_URL . '/index.php';
$title = fn(array $m) => ($m['gender'] === 'Female' ? 'Ms.' : 'Mr.') . ' ' . $m['first_name'] . ' ' . $m['last_name'];
$totalOutstanding = array_sum(array_column($loans, 'outstanding'));
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold" style="font-family:'Space Grotesk',sans-serif;">Overdue Loans</h1>
        <p class="text-muted mb-0" style="font-size:.78rem;">As at <?= date('d M Y, H:i') ?></p>
    </div>
</div>

<ul class="nav nav-pills flex-wrap gap-2 mb-4 no-print">
    <li class="nav-item"><a class="nav-link" href="<?= $base ?>?page=weekly-savings">Savings Deposits</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= $base ?>?page=weekly-loans">Loan Disbursements</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= $base ?>?page=weekly-repayments">Loan Repayments</a></li>
    <li class="nav-item"><a class="nav-link active" href="<?= $base ?>?page=weekly-overdue">Overdue Loans</a></li>
</ul>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4"><div class="stat-card"><div class="stat-label">Overdue Loans</div><div class="stat-value" style="color:var(--rust);"><?= count($loans) ?></div></div></div>
    <div class="col-6 col-md-4"><div class="stat-card"><div class="stat-label">Total Outstanding</div><div class="stat-value" style="font-size:1.1rem;color:var(--rust);"><span style="font-size:.7rem;color:var(--slate-soft);">UGX</span> <?= number_format($totalOutstanding, 0) ?></div></div></div>
</div>

<div class="d-flex gap-2 flex-wrap mb-3 no-print">
    <button onclick="copyReport()" class="btn btn-outline-primary btn-sm"><i class="bi bi-clipboard me-1"></i>Copy</button>
    <button onclick="shareWhatsApp()" class="btn btn-sm" style="background:#25D366;color:#fff;border:none;"><i class="bi bi-whatsapp me-1"></i>WhatsApp</button>
    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer me-1"></i>Print</button>
</div>

<div class="card">
    <div class="card-header"><h6 class="mb-0 fw-semibold" style="font-size:.82rem;"><i class="bi bi-exclamation-triangle me-2" style="color:var(--rust);"></i>Overdue Loans</h6></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th class="ps-3">#</th><th>Member</th><th>Loan No.</th><th class="text-end">Outstanding</th><th>Due Date</th><th class="text-end pe-3">Days Overdue</th></tr></thead>
            <tbody>
                <?php if (empty($loans)): ?>
                <tr><td colspan="6" class="text-center py-4 text-muted">No overdue loans. All clear!</td></tr>
                <?php else: foreach ($loans as $i => $l): ?>
                <tr>
                    <td class="ps-3 text-muted"><?= $i + 1 ?>.</td>
                    <td class="fw-semibold"><?= htmlspecialchars($title($l)) ?></td>
                    <td style="font-size:.72rem;"><?= htmlspecialchars($l['loan_number']) ?></td>
                    <td class="text-end fw-bold" style="color:var(--rust);"><?= number_format($l['outstanding'], 0) ?></td>
                    <td class="text-muted" style="font-size:.72rem;"><?= date('d M Y', strtotime($l['due_date'])) ?></td>
                    <td class="text-end pe-3"><span class="badge bg-danger-subtle text-danger"><?= $l['days_overdue'] ?> days</span></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function generateReportText() {
    const loans = <?= json_encode(array_map(fn($l) => $title($l) . ' - ' . $l['loan_number'] . ' - UGX ' . number_format($l['outstanding'],0) . ' (' . $l['days_overdue'] . ' days)', $loans)) ?>;
    let text = 'OVERDUE LOANS - ' + <?= json_encode(date('d M Y')) ?> + '\n\n';
    loans.forEach((l, i) => { text += (i+1) + '. ' + l + '\n'; });
    text += '\nTotal Overdue: ' + <?= count($loans) ?>;
    text += '\nTotal Outstanding: UGX ' + (<?= (int)$totalOutstanding ?>).toLocaleString();
    text += '\n\n— Empower Investment Club';
    return text;
}
function copyReport() { navigator.clipboard.writeText(generateReportText()); }
function shareWhatsApp() { window.open('https://wa.me/?text=' + encodeURIComponent(generateReportText()), '_blank'); }
</script>
<style>@media print { .no-print { display: none !important; } }</style>
