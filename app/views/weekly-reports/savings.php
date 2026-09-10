<?php
$base = APP_URL . '/index.php';
// Only prepend Mr./Ms. for an actual individual member -- a group/chama
// entry (e.g. "The Alphas") has no meaningful gender and must be shown as-is.
$title = fn(array $m) => match ($m['gender'] ?? null) {
    'Male'   => 'Mr. ' . $m['first_name'] . ' ' . $m['last_name'],
    'Female' => 'Ms. ' . $m['first_name'] . ' ' . $m['last_name'],
    default  => trim($m['first_name'] . ' ' . $m['last_name']),
};

/** "31st-06th SEPTEMBER 2026" -- start day (no padding) + ordinal suffix,
 *  end day (zero-padded) + ordinal suffix, single month name (the end
 *  date's month, uppercase) and year, for the WhatsApp share text. */
function ordinalSuffix(int $day): string {
    if ($day >= 11 && $day <= 13) return 'th';
    return match ($day % 10) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' };
}
$waStart = new DateTime($week['start']);
$waEnd   = new DateTime($week['end']);
$waLabel = $waStart->format('j') . ordinalSuffix((int)$waStart->format('j'))
         . '-' . $waEnd->format('d') . ordinalSuffix((int)$waEnd->format('j'))
         . ' ' . strtoupper($waEnd->format('F')) . ' ' . $waEnd->format('Y');
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold" style="font-family:'Space Grotesk',sans-serif;">Weekly Savings Report</h1>
        <p class="text-muted mb-0" style="font-size:.78rem;">
            <i class="bi bi-calendar-week me-1"></i><?= htmlspecialchars($week['label']) ?>
            &middot; Generated: <?= date('d M Y, H:i') ?>
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap no-print">
        <a href="<?= $base ?>?page=weekly-savings" class="btn btn-outline-secondary btn-sm <?= !isset($_GET['week']) ? 'active' : '' ?>">This Week</a>
    </div>
</div>

<!-- Tab Navigation -->
<!-- Stage 13-F2 (13E-XSS-02): these tab links previously re-read the raw,
     unvalidated $_GET['week'] querystring value and spliced it straight
     into an href attribute with zero encoding, so a payload like
     ?week="><script>...</script> would break out of the attribute and
     execute. $week['start'] is the already-server-computed value (see
     WeeklyReportModel::getWeekBounds(): date('Y-m-d', strtotime($date)))
     -- guaranteed to be a plain YYYY-MM-DD string regardless of what the
     attacker put in the query string, so it can never contain anything
     that could break out of the attribute or execute. urlencode() is
     added defensively; date('Y-m-d') output never actually needs it. -->
<ul class="nav nav-pills flex-wrap gap-2 mb-4 no-print">
    <li class="nav-item"><a class="nav-link active" href="<?= $base ?>?page=weekly-savings<?= isset($_GET['week']) ? '&week='.urlencode($week['start']) : '' ?>">Savings Deposits</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= $base ?>?page=weekly-loans<?= isset($_GET['week']) ? '&week='.urlencode($week['start']) : '' ?>">Loan Disbursements</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= $base ?>?page=weekly-repayments<?= isset($_GET['week']) ? '&week='.urlencode($week['start']) : '' ?>">Loan Repayments</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= $base ?>?page=weekly-overdue">Overdue Loans</a></li>
</ul>

<!-- Week Selector -->
<div class="card mb-4 no-print">
    <div class="card-body p-3">
        <form method="GET" action="<?= $base ?>" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="weekly-savings">
            <div class="col-md-4">
                <label class="form-label fw-semibold" style="font-size:.72rem;">Select Week</label>
                <select name="week" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Current Week</option>
                    <?php foreach ($weeks as $w): ?>
                    <option value="<?= $w['start'] ?>" <?= ($week['start'] === $w['start']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($w['label']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="stat-card">
            <div class="stat-label">Reporting Week</div>
            <div class="stat-value" style="font-size:1rem;"><?= htmlspecialchars($week['label']) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="stat-card">
            <div class="d-flex align-items-center justify-content-between">
                <div class="stat-label">Members Who Saved</div>
                <span class="stat-dot stat-dot-green"></span>
            </div>
            <div class="stat-value"><?= number_format($summary['total_members']) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="stat-card">
            <div class="d-flex align-items-center justify-content-between">
                <div class="stat-label">Total Savings Collected</div>
                <span class="stat-dot stat-dot-gold"></span>
            </div>
            <div class="stat-value" style="font-size:1.1rem;"><span style="font-size:.7rem;color:var(--slate-soft);">UGX</span> <?= number_format($summary['total_amount'], 0) ?></div>
        </div>
    </div>
</div>

<!-- Action Buttons -->
<div class="d-flex gap-2 flex-wrap mb-3 no-print">
    <button onclick="copyReport()" class="btn btn-outline-primary btn-sm"><i class="bi bi-clipboard me-1"></i>Copy to Clipboard</button>
    <button onclick="shareWhatsApp()" class="btn btn-sm" style="background:#25D366;color:#fff;border:none;"><i class="bi bi-whatsapp me-1"></i>Share to WhatsApp</button>
    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer me-1"></i>Print Report</button>
    <button onclick="exportCSV()" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Export Excel</button>
</div>

<!-- Report Content -->
<div class="card" id="reportCard">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold" style="font-size:.82rem;">
            <i class="bi bi-piggy-bank me-2" style="color:var(--green);"></i>Savings Deposits List
        </h6>
        <span class="badge bg-success-subtle text-success"><?= count($members) ?> members</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="reportTable">
            <thead>
                <tr>
                    <th class="ps-3" style="width:40px;">#</th>
                    <th>Member Name</th>
                    <th>Member No.</th>
                    <th class="text-end">Amount (UGX)</th>
                    <th class="text-end pe-3">Deposits</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($members)): ?>
                <tr><td colspan="5" class="text-center py-4 text-muted">No savings recorded this week.</td></tr>
                <?php else: foreach ($members as $i => $m): ?>
                <tr>
                    <td class="ps-3 text-muted"><?= $i + 1 ?>.</td>
                    <td class="fw-semibold"><?= htmlspecialchars($title($m)) ?></td>
                    <td class="text-muted" style="font-size:.72rem;"><?= htmlspecialchars($m['member_number']) ?></td>
                    <td class="text-end fw-bold" style="color:var(--green);font-variant-numeric:tabular-nums;"><?= number_format($m['total_deposited'], 0) ?></td>
                    <td class="text-end pe-3 text-muted"><?= $m['deposit_count'] ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($members)): ?>
            <tfoot class="border-top">
                <tr class="fw-bold">
                    <td class="ps-3" colspan="3">Total</td>
                    <td class="text-end" style="color:var(--green);">UGX <?= number_format($summary['total_amount'], 0) ?></td>
                    <td class="text-end pe-3"><?= $summary['total_transactions'] ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- Success Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:1055">
    <div id="successToast" class="toast align-items-center border-0" role="alert" style="background:var(--green);color:#fff;">
        <div class="d-flex">
            <div class="toast-body"><i class="bi bi-check-circle me-2"></i><span id="toastMsg">Copied!</span></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script>
// Generate the plain-text report (WhatsApp share format)
function generateReportText() {
    const waLabel = <?= json_encode($waLabel) ?>;
    const members = <?= json_encode(array_map(function($m) use ($title) { return $title($m); }, $members)) ?>;

    let text = '*' + waLabel + '*\n\n';
    text += '*SAVING DEPOSITS LIST*\n\n';

    members.forEach((name, i) => {
        text += (i + 1) + '. ' + name + '\n';
    });

    return text;
}

// Copy to clipboard
function copyReport() {
    const text = generateReportText();
    navigator.clipboard.writeText(text).then(() => {
        showToast('Report copied to clipboard!');
    });
}

// Share to WhatsApp
function shareWhatsApp() {
    const text = generateReportText();
    const encoded = encodeURIComponent(text);
    window.open('https://wa.me/?text=' + encoded, '_blank');
}

// Export CSV
function exportCSV() {
    const table = document.getElementById('reportTable');
    let csv = [];
    table.querySelectorAll('thead tr, tbody tr').forEach(row => {
        let rowData = [];
        row.querySelectorAll('td, th').forEach(col => rowData.push('"' + col.innerText.replace(/"/g, '""').trim() + '"'));
        if (rowData.length > 0) csv.push(rowData.join(','));
    });
    const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'weekly_savings_' + <?= json_encode($week['start']) ?> + '.csv';
    a.click();
    showToast('Excel file downloaded!');
}

// Toast
function showToast(msg) {
    document.getElementById('toastMsg').textContent = msg;
    const toast = new bootstrap.Toast(document.getElementById('successToast'));
    toast.show();
}
</script>

<style>
@media print {
    .no-print { display: none !important; }
    .card { border: 1px solid #ddd; box-shadow: none; }
}
</style>
