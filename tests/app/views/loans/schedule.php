<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Loan Schedule — <?= htmlspecialchars($loan['loan_number']) ?> · <?= htmlspecialchars($loan['first_name'].' '.$loan['last_name']) ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: Arial, 'Segoe UI', sans-serif;
    font-size: 9pt;
    color: #1a1a1a;
    background: #dde1ea;
}

/* ── Toolbar ─────────────────────────────────────────── */
.toolbar {
    display: flex; gap: .5rem; padding: .75rem 1rem;
    max-width: 215mm; margin: 0 auto;
}
.toolbar button, .toolbar a {
    padding: .4rem 1.1rem; border-radius: 4px; font-size: .85rem;
    cursor: pointer; text-decoration: none; border: none; font-weight: 600;
}
.btn-print { background: #1B2B6B; color: #fff; }
.btn-wa    { background: #25D366; color: #fff; }
.btn-back  { background: #fff; color: #333; border: 1px solid #bbb; }
.btn-close { background: #fff; color: #333; border: 1px solid #bbb; }

/* ── Page wrapper ────────────────────────────────────── */
.page-wrap {
    max-width: 215mm;
    margin: 0 auto 2rem;
    background: #fff;
    border: 1px solid #bbb;
    box-shadow: 0 4px 28px rgba(0,0,0,.18);
    position: relative;
    overflow: hidden;
}

/* ── Watermark ───────────────────────────────────────── */
.watermark {
    position: absolute; top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    width: 420px; height: 420px; object-fit: contain;
    opacity: 0.04; pointer-events: none; z-index: 0;
}

/* ── Header ──────────────────────────────────────────── */
.card-header {
    display: flex; align-items: flex-start; justify-content: space-between;
    padding: 14px 20px 10px;
    border-bottom: 4px solid #F47920;
    position: relative; z-index: 1; background: #fff;
}
.header-left { display: flex; align-items: center; gap: 14px; }
.header-logo { height: 60px; width: 60px; object-fit: contain; }
.brand-name {
    font-size: 1.45rem; font-weight: 900; color: #1B2B6B;
    text-transform: uppercase; line-height: 1; letter-spacing: .02em;
}
.brand-name span { color: #F47920; }
.brand-sub { font-size: .72rem; color: #444; line-height: 1.65; margin-top: 3px; }
.header-stamp {
    border: 1.5px solid #bbb; border-radius: 4px;
    width: 80px; height: 70px;
    display: flex; align-items: center; justify-content: center;
    text-align: center; font-size: .6rem; color: #aaa; line-height: 1.5;
}

/* ── Document title bar ──────────────────────────────── */
.doc-title-bar {
    background: #fff; text-align: center;
    padding: 8px 0 6px;
    border-bottom: 2px solid #F47920;
    position: relative; z-index: 1;
}
.doc-title-text {
    font-size: 1rem; font-weight: 900; color: #1B2B6B;
    text-transform: uppercase; letter-spacing: .12em;
}

/* ── Body ────────────────────────────────────────────── */
.card-body { padding: 16px 20px 18px; position: relative; z-index: 1; }

/* ── Info grid ───────────────────────────────────────── */
.info-grid {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 0; border: 1.5px solid #1B2B6B; border-radius: 4px;
    overflow: hidden; margin-bottom: 14px; font-size: .8rem;
}
.info-cell {
    display: flex; align-items: center; gap: 8px;
    padding: 7px 12px; border-bottom: 1px solid #d4daea;
    border-right: 1px solid #d4daea;
}
.info-cell:nth-child(even) { border-right: none; }
.info-cell:nth-last-child(-n+2) { border-bottom: none; }
.info-label { color: #555; font-weight: 600; white-space: nowrap; flex-shrink: 0; }
.info-val   { color: #1B2B6B; font-weight: 700; }
.info-header {
    grid-column: span 2;
    background: #1B2B6B; color: #fff;
    padding: 6px 12px; font-size: .68rem; font-weight: 900;
    text-transform: uppercase; letter-spacing: .1em;
    border-bottom: 1px solid #1B2B6B;
}

/* ── Summary badges ──────────────────────────────────── */
.summary-row {
    display: flex; gap: 10px; margin-bottom: 14px;
}
.summary-card {
    flex: 1; border-radius: 4px; overflow: hidden;
    border: 1.5px solid #d4daea; text-align: center;
}
.summary-card-header {
    padding: 5px 8px; font-size: .62rem; font-weight: 900;
    text-transform: uppercase; letter-spacing: .07em; color: #fff;
}
.summary-card-header.navy  { background: #1B2B6B; }
.summary-card-header.orange { background: #F47920; }
.summary-card-header.green  { background: #16a34a; }
.summary-card-header.red    { background: #dc2626; }
.summary-card-val {
    padding: 6px 8px; font-size: .82rem; font-weight: 800;
    color: #1B2B6B; background: #f8f9ff;
}

/* ── Section title ───────────────────────────────────── */
.section-title {
    font-size: .7rem; font-weight: 900; text-transform: uppercase;
    letter-spacing: .1em; color: #fff; background: #1B2B6B;
    padding: 6px 12px; border-radius: 4px 4px 0 0;
    margin-bottom: 0;
}

/* ── Installment table ───────────────────────────────── */
.sched-table-wrap {
    border: 1.5px solid #1B2B6B; border-radius: 0 0 4px 4px;
    overflow: hidden; margin-bottom: 16px;
}
.sched-table {
    width: 100%; border-collapse: collapse; font-size: .78rem;
}
.sched-table thead tr th {
    background: #f0f4ff; color: #1B2B6B;
    padding: 7px 8px; font-size: .64rem; font-weight: 800;
    text-transform: uppercase; letter-spacing: .05em;
    border-bottom: 1.5px solid #c5cfe0; border-right: 1px solid #c5cfe0;
    text-align: center;
}
.sched-table thead tr th:last-child { border-right: none; }
.sched-table tbody td {
    padding: 7px 8px; border-bottom: 1px solid #dde3ef;
    border-right: 1px solid #dde3ef; vertical-align: middle;
    text-align: center;
}
.sched-table tbody td:last-child { border-right: none; }
.sched-table tbody tr:nth-child(even) td { background: #f9fafff5; }
.sched-table tbody tr.row-paid    td { background: #f0fdf4 !important; }
.sched-table tbody tr.row-overdue td { background: #fef2f2 !important; }

.sched-table tfoot td {
    padding: 7px 8px; background: #f0f4ff; font-weight: 800;
    border-top: 2px solid #1B2B6B; border-right: 1px solid #c5cfe0;
    text-align: center;
}
.sched-table tfoot td:last-child { border-right: none; }

.text-right  { text-align: right !important; }
.text-left   { text-align: left !important; }
.text-center { text-align: center !important; }

/* Status badges */
.badge {
    display: inline-block; padding: 2px 8px; border-radius: 3px;
    font-size: .62rem; font-weight: 800; text-transform: uppercase;
    letter-spacing: .05em;
}
.badge-paid    { background: #dcfce7; color: #166534; }
.badge-overdue { background: #fee2e2; color: #991b1b; }
.badge-pending { background: #fef9c3; color: #854d0e; }

/* ── Notes box ───────────────────────────────────────── */
.notes-box {
    border: 1.5px solid #1B2B6B; border-radius: 4px;
    overflow: hidden; margin-bottom: 10px;
}
.notes-header {
    background: #1B2B6B; color: #fff;
    padding: 5px 12px; font-size: .68rem; font-weight: 900;
    text-transform: uppercase; letter-spacing: .08em;
}
.notes-body { padding: 8px 14px; font-size: .74rem; color: #333; }
.notes-body ul { list-style: none; padding: 0; }
.notes-body ul li { padding: 3px 0 3px 12px; position: relative; }
.notes-body ul li::before { content: '-'; position: absolute; left: 0; }

/* ── Footer bar ──────────────────────────────────────── */
.card-footer {
    border-top: 4px solid #F47920; padding: 8px 20px;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    background: #fff;
}
.footer-tagline {
    font-size: .75rem; font-weight: 700; letter-spacing: .08em;
    text-transform: uppercase; color: #1B2B6B;
}
.footer-tagline span { color: #F47920; }

/* ── Print ───────────────────────────────────────────── */
@media print {
    @page { size: A4 portrait; margin: 6mm 8mm; }
    body { background: #fff; }
    .toolbar { display: none !important; }
    .page-wrap { max-width: 100%; margin: 0; box-shadow: none; border: none; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
}
</style>
</head>
<body>
<?php
$fullName       = $loan['first_name'] . ' ' . $loan['last_name'];
$totalScheduled = array_sum(array_column($installments, 'amount_due'));
$totalPaid      = array_sum(array_column($installments, 'amount_paid'));
$totalBalance   = $totalScheduled - $totalPaid;
$countPaid      = count(array_filter($installments, fn($i) => strtolower($i['status'] ?? '') === 'paid'));
$countOverdue   = count(array_filter($installments, fn($i) => strtolower($i['status'] ?? '') === 'overdue'));
$countPending   = count($installments) - $countPaid - $countOverdue;
?>

<!-- Toolbar -->
<div class="toolbar no-print">
    <button class="btn-print" onclick="window.print()">🖨 Print / Save PDF</button>
    <button class="btn-wa"    onclick="shareWhatsApp()">💬 Share WhatsApp</button>
    <a class="btn-back" href="<?= APP_URL ?>/index.php?page=loan-view&id=<?= $loan['id'] ?>">← Back to Loan</a>
    <button class="btn-close" onclick="window.close()">✕ Close</button>
</div>

<div class="page-wrap">
    <img class="watermark" src="<?= APP_URL ?>/public/images/logo.png" alt="">

    <!-- ══ HEADER ══════════════════════════════════════════ -->
    <div class="card-header">
        <div class="header-left">
            <img class="header-logo" src="<?= APP_URL ?>/public/images/logo.png" alt="Logo">
            <div>
                <div class="brand-name">EMPOWER <span>INVESTMENT CLUB</span></div>
                <div class="brand-sub">
                    GAYAZA, GITTA, WAKISO | TEL: 0702970129 / 0701486161<br>
                    EMAIL: empowerclub2024@gmail.com<br>
                    REG. NO. WCBO/24/24/4290
                </div>
            </div>
        </div>
        <div class="header-stamp">Official<br>Stamp<br>Here</div>
    </div>

    <!-- ══ DOCUMENT TITLE ══════════════════════════════════ -->
    <div class="doc-title-bar">
        <span class="doc-title-text">Loan Repayment Schedule</span>
    </div>

    <!-- ══ BODY ════════════════════════════════════════════ -->
    <div class="card-body">

        <!-- Borrower Info Grid -->
        <div class="info-grid">
            <div class="info-header">Loan Information</div>
            <div class="info-cell">
                <span class="info-label">Borrower:</span>
                <span class="info-val"><?= htmlspecialchars($fullName) ?></span>
            </div>
            <div class="info-cell">
                <span class="info-label">Loan Amount:</span>
                <span class="info-val">UGX <?= number_format($loan['loan_amount'], 2) ?></span>
            </div>
            <div class="info-cell">
                <span class="info-label">Loan Number:</span>
                <span class="info-val"><?= htmlspecialchars($loan['loan_number']) ?></span>
            </div>
            <div class="info-cell">
                <span class="info-label">Interest Rate:</span>
                <span class="info-val"><?= number_format($loan['interest_rate'], 2) ?>% / month</span>
            </div>
            <div class="info-cell">
                <span class="info-label">Member Number:</span>
                <span class="info-val"><?= htmlspecialchars($loan['member_number']) ?></span>
            </div>
            <div class="info-cell">
                <span class="info-label">Loan Period:</span>
                <span class="info-val"><?= htmlspecialchars($loan['loan_period']) ?></span>
            </div>
            <div class="info-cell">
                <span class="info-label">Date Generated:</span>
                <span class="info-val"><?= date('d F Y') ?></span>
            </div>
            <div class="info-cell">
                <span class="info-label">Purpose:</span>
                <span class="info-val"><?= htmlspecialchars($loan['purpose'] ?? 'General') ?></span>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="summary-row">
            <div class="summary-card">
                <div class="summary-card-header navy">Total Scheduled</div>
                <div class="summary-card-val">UGX <?= number_format($totalScheduled, 0) ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-card-header green">Total Paid</div>
                <div class="summary-card-val" style="color:#16a34a;">UGX <?= number_format($totalPaid, 0) ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-card-header red">Balance</div>
                <div class="summary-card-val" style="color:#dc2626;">UGX <?= number_format($totalBalance, 0) ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-card-header orange">Installments</div>
                <div class="summary-card-val" style="font-size:.72rem;color:#555;">
                    <?= $countPaid ?> Paid &nbsp;·&nbsp; <?= $countOverdue ?> Overdue &nbsp;·&nbsp; <?= $countPending ?> Pending
                </div>
            </div>
        </div>

        <!-- Installment Table -->
        <div class="section-title">Installment Schedule</div>
        <div class="sched-table-wrap">
            <div class="table-responsive">
                <table class="sched-table">
                <thead>
                    <tr>
                        <th style="width:8%;">#</th>
                        <th style="width:20%;">Due Date</th>
                        <th style="width:17%;">Principal</th>
                        <th style="width:15%;">Interest</th>
                        <th style="width:17%;">Amount Due</th>
                        <th style="width:17%;">Amount Paid</th>
                        <th style="width:12%;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($installments)): ?>
                    <tr>
                        <td colspan="7" style="padding:18px;color:#64748b;font-style:italic;text-align:center;">
                            No installment schedule has been generated for this loan.
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($installments as $inst):
                            $instStatus = strtolower($inst['status'] ?? 'pending');
                            $rowClass   = match($instStatus) {
                                'paid'    => 'row-paid',
                                'overdue' => 'row-overdue',
                                default   => ''
                            };
                            $badgeClass = match($instStatus) {
                                'paid'    => 'badge-paid',
                                'overdue' => 'badge-overdue',
                                default   => 'badge-pending'
                            };
                            $badgeLabel = match($instStatus) {
                                'paid'    => 'Paid',
                                'overdue' => 'Overdue',
                                default   => 'Pending'
                            };
                            $amtDue  = (float)($inst['amount_due']  ?? 0);
                            $amtPaid = (float)($inst['amount_paid'] ?? 0);
                            $iDue    = (float)($inst['interest_due']  ?? 0);
                            $pDue    = (float)($inst['principal_due'] ?? ($amtDue - $iDue));
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td style="font-weight:800;"><?= $inst['installment_no'] ?></td>
                            <td><?= htmlspecialchars($inst['due_date']) ?></td>
                            <td class="text-right"><?= number_format($pDue, 0) ?></td>
                            <td class="text-right"><?= number_format($iDue, 0) ?></td>
                            <td class="text-right" style="font-weight:700;"><?= number_format($amtDue, 0) ?></td>
                            <td class="text-right" style="color:#16a34a;font-weight:700;"><?= number_format($amtPaid, 0) ?></td>
                            <td><span class="badge <?= $badgeClass ?>"><?= $badgeLabel ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2" class="text-right" style="font-weight:800;">TOTAL</td>
                        <td class="text-right"></td>
                        <td class="text-right"></td>
                        <td class="text-right" style="color:#1B2B6B;">UGX <?= number_format($totalScheduled, 0) ?></td>
                        <td class="text-right" style="color:#16a34a;">UGX <?= number_format($totalPaid, 0) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            </div>
        </div>

        <!-- Notes -->
        <div class="notes-box">
            <div class="notes-header">Notes / Terms</div>
            <div class="notes-body">
                <ul>
                    <li>This repayment schedule is an official record of Empower Investment Club.</li>
                    <li>Late payment may attract penalties as per the club's loan policy.</li>
                    <li>Please present this schedule during every payment transaction.</li>
                </ul>
            </div>
        </div>

        <!-- Signature row -->
        <div style="display:flex;gap:30px;margin-top:12px;font-size:.78rem;">
            <div style="flex:1;">
                <div style="border-bottom:1px solid #555;min-height:22px;margin-bottom:3px;"></div>
                <span style="color:#555;">Borrower's Signature &amp; Date</span>
            </div>
            <div style="flex:1;">
                <div style="border-bottom:1px solid #555;min-height:22px;margin-bottom:3px;"></div>
                <span style="color:#555;">Authorized Officer &amp; Date</span>
            </div>
            <div style="flex:1;">
                <div style="border-bottom:1px solid #555;min-height:22px;margin-bottom:3px;"></div>
                <span style="color:#555;">Club Stamp &amp; Date</span>
            </div>
        </div>

    </div><!-- /.card-body -->

    <!-- ══ FOOTER ═══════════════════════════════════════════ -->
    <div class="card-footer">
        <img src="<?= APP_URL ?>/public/images/logo.png" alt="" style="height:18px;opacity:.35;">
        <span class="footer-tagline">⊕ Unleash Your <span>Financial</span> Potential</span>
    </div>
</div>

<script>
function shareWhatsApp() {
    fetch('<?= APP_URL ?>/index.php?page=loan-whatsapp-schedule&id=<?= $loan['id'] ?>')
        .then(r => r.json())
        .then(data => {
            if (data.url)  window.open(data.url, '_blank');
            else if (data.text) window.open('https://wa.me/?text=' + encodeURIComponent(data.text), '_blank');
        })
        .catch(() => alert('Failed to generate WhatsApp message.'));
}

if(new URLSearchParams(window.location.search).get('auto') === '1'){
    window.addEventListener('load', () => window.print());
}
</script>
</body>
</html>
