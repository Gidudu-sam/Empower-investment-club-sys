<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Loan Statement — <?= htmlspecialchars($loan['loan_number']) ?> · <?= htmlspecialchars($loan['first_name'].' '.$loan['last_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Inter', sans-serif;
    font-size: 10pt;
    color: #1a1a1a;
    background: #e8eaf0;
}
.page-wrap { max-width: 780px; margin: 1.5rem auto; }
.toolbar { display: flex; gap: .6rem; margin-bottom: 1rem; }
.toolbar button, .toolbar a {
    padding: .45rem 1.2rem; border-radius: 5px; font-size: .9rem;
    cursor: pointer; text-decoration: none; border: none;
}
.btn-print  { background: #1B2B6B; color: #fff; }
.btn-back   { background: #fff; color: #333; border: 1px solid #ccc; }
.btn-close  { background: #fff; color: #333; border: 1px solid #ccc; }

.stmt-card {
    background: #fff;
    border: 2px solid #1B2B6B;
    border-radius: 8px;
    box-shadow: 0 4px 24px rgba(0,0,0,.18);
    position: relative;
    overflow: hidden;
}
.stmt-watermark {
    position: absolute; top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    width: 360px; height: 360px; object-fit: contain;
    opacity: 0.04; pointer-events: none; z-index: 0;
}
.stmt-header {
    padding: 22px 28px 16px;
    border-bottom: 4px solid #B8892B;
    display: flex; align-items: center; justify-content: space-between;
    background: #fff; position: relative; z-index: 1;
}
.stmt-logo-area { display: flex; align-items: center; gap: 14px; }
.stmt-logo-img { height: 52px; width: 52px; object-fit: contain; }
.stmt-logo-divider { width: 2px; height: 44px; background: #E5E7F0; }
.stmt-brand-title { font-size: 1.3rem; font-weight: 900; color: #1B2B6B; text-transform: uppercase; line-height: 1.1; letter-spacing: 0.02em; }
.stmt-brand-title span { color: #B8892B; }
.stmt-brand-tagline { font-size: 0.68rem; color: #6E7689; font-style: italic; margin-top: 2px; }
.stmt-contact-info { text-align: right; font-size: 0.78rem; color: #6B7280; line-height: 1.7; }
.stmt-contact-info strong { color: #1B2B6B; }

.stmt-body { padding: 20px 24px; position: relative; z-index: 1; }
.stmt-doc-title {
    text-align: center; background: #1B2B6B; color: #fff;
    font-size: 0.82rem; font-weight: 800; letter-spacing: .18em;
    text-transform: uppercase; padding: .55rem 1rem; border-radius: 4px; margin-bottom: 22px;
}

.member-info-box {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 8px 24px; font-size: 0.82rem; margin-bottom: 22px;
    background: #f8f9fc; padding: 14px 18px; border-radius: 6px; border: 1px solid #E5E7F0;
}
.member-info-row {
    display: grid; grid-template-columns: 150px 1fr; gap: 0 12px;
    align-items: baseline; padding-bottom: 4px; border-bottom: 1px dashed #E5E7F0;
}
.member-info-row:last-child { border-bottom: none; padding-bottom: 0; }
.member-info-label { color: #6E7689; font-weight: 500; white-space: nowrap; }
.member-info-val { color: #171B2E; font-weight: 600; }

.stmt-section-title {
    font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; color: #1B2B6B; margin-bottom: 10px; border-bottom: 2px solid #1B2B6B; padding-bottom: 3px;
}

.stmt-table {
    width: 100%; border-collapse: collapse; font-size: 0.68rem; margin-bottom: 24px; border: 1px solid #E5E7F0;
}
.stmt-table th {
    background: #1B2B6B; color: #fff; text-align: center; padding: 6px 4px;
    font-size: 0.58rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; border: 1px solid #E5E7F0;
}
.stmt-table th.right { text-align: right; }
.stmt-table th.left { text-align: left; }
.stmt-table th.center { text-align: center; }
.stmt-table td { padding: 5px 4px; border: 1px solid #E5E7F0; vertical-align: middle; }
.stmt-table tr:nth-child(even) td { background: #f8f9fc; }
.stmt-table .row-disbursement { background: #fef3c7 !important; font-weight: 600; }
.stmt-table .row-closing { background: #e0f2fe !important; font-weight: 700; }
.stmt-table .row-paid { background: #f0fdf4 !important; }
.stmt-table .row-overdue { background: #fef2f2 !important; }

.text-right { text-align: right; }
.text-left { text-align: left; }
.text-center { text-align: center; }
.amount-credit { color: #16a34a; font-weight: 600; }
.amount-debit { color: #dc2626; font-weight: 600; }
.amount-balance { color: #1e3a8a; font-weight: 700; }
.amount-closing { color: #1e3a8a; font-weight: 900; font-size: 0.95rem; }

.summary-card {
    border: 1px solid #E5E7F0; border-radius: 6px; overflow: hidden; background: #fdfdfd; margin-bottom: 24px; font-size: 0.82rem;
}
.summary-card-header {
    background: #1B2B6B; color: #fff; font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; padding: 8px 16px;
}
.summary-card-body { padding: 16px 18px; }
.summary-highlights {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 16px; padding-bottom: 16px; border-bottom: 2px solid #E5E7F0;
}
.summary-highlight-item {
    background: #f8f9fc; border: 1px solid #E5E7F0; border-radius: 6px; padding: 10px; text-align: center;
}
.summary-highlight-label { font-size: 0.68rem; color: #6E7689; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 3px; }
.summary-highlight-value { font-size: 1.05rem; font-weight: 800; color: #1B2B6B; }
.summary-highlight-value.danger { color: #dc2626; font-size: 1.15rem; }

.summary-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 24px; font-size: 0.82rem; }
.summary-row { display: grid; grid-template-columns: 160px 1fr; gap: 0 12px; align-items: baseline; padding: 5px 0; border-bottom: 1px solid #f1f5f9; }
.summary-row:last-child { border-bottom: none; }
.summary-label { color: #6B7280; white-space: nowrap; }
.summary-val { font-weight: 600; color: #171B2E; }

.summary-total-bar {
    display: flex; justify-content: space-between; padding-top: 10px; margin-top: 12px; border-top: 2px solid #1B2B6B; font-size: 1.05rem; font-weight: 900;
}
.summary-total-bar .summary-label { color: #1B2B6B; font-size: 1rem; }
.summary-total-bar .summary-val { color: #dc2626; font-size: 1.2rem; }

.stmt-footer-note {
    text-align: center; margin-top: 24px; padding-top: 14px; border-top: 1px dashed #E5E7F0; font-size: 0.75rem; color: #6B7280; line-height: 1.6;
}
.stmt-footer-note p.disclaimer { font-style: italic; margin-bottom: 3px; }
.stmt-footer-note p.end-mark { font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; color: #1B2B6B; margin-top: 4px; }

.stmt-footer-bar {
    padding: 12px 28px; border-top: 3px solid #B8892B;
    display: flex; align-items: center; justify-content: center; gap: 8px; background: #fff;
}

@media print {
    @page { size: A4 portrait; margin: 8mm 8mm 10mm; }
    body { background: #fff; color: #000; }
    .toolbar { display: none !important; }
    .page-wrap { max-width: 100%; margin: 0; }
    .stmt-card { box-shadow: none !important; border: 1px solid #333 !important; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
}
</style>
</head>
<body>
<?php
$fullName = $loan['first_name'] . ' ' . $loan['last_name'];
$acctNo   = ltrim($loan['member_number'], 'EMP') ?: str_pad((string)$loan['member_id'], 6, '0', STR_PAD_LEFT);
$nationalId = $loan['national_id'] ?? '—';
$phone    = $loan['member_phone'] ?? '—';
$status   = strtolower($loan['status'] ?? 'active');
$statusBadge = match($status) {
    'active'    => 'bg-success',
    'completed' => 'bg-primary',
    'overdue', 'in_arrears' => 'bg-danger',
    default     => 'bg-secondary'
};
$statusLabel = $status === 'overdue' ? 'In Arrears' : ucfirst($status);
?>

<div class="page-wrap">
    <div class="toolbar no-print">
        <button class="btn-print" onclick="window.print()">🖨 Print / Save PDF</button>
        <a class="btn-back" href="<?= APP_URL ?>/index.php?page=loan-statement&id=<?= $loan['id'] ?>">← Back</a>
        <button class="btn-close" onclick="window.close()">✕ Close</button>
    </div>

    <div class="stmt-card">
        <img class="stmt-watermark" src="<?= APP_URL ?>/public/images/logo.png" alt="">

        <!-- Header -->
        <div class="stmt-header">
            <div class="stmt-logo-area">
                <img class="stmt-logo-img" src="<?= APP_URL ?>/public/images/logo.png" alt="Logo">
                <div class="stmt-logo-divider"></div>
                <div class="stmt-brand-title">
                    EMPOWER<span>INVESTMENT CLUB</span>
                    <div class="stmt-brand-tagline">Unleash your financial potential</div>
                </div>
            </div>
            <div class="stmt-contact-info">
                <strong>GAYAZA, GITTA, WAKISO</strong><br>
                TEL: 0702970129 / 0701486161<br>
                EMAIL: empowerclub2024@gmail.com<br>
                REG. NO. WCBO/24/24/4290
            </div>
        </div>

        <div class="stmt-body">
            <div class="stmt-doc-title">LOAN STATEMENT</div>

            <!-- Borrower Information -->
            <div class="member-info-box">
                <div class="member-info-row">
                    <span class="member-info-label">Member Name:</span>
                    <span class="member-info-val"><?= htmlspecialchars($fullName) ?></span>
                </div>
                <div class="member-info-row">
                    <span class="member-info-label">Loan Product:</span>
                    <span class="member-info-val"><?= htmlspecialchars($loan['loan_type_name'] ?? 'Normal Loan') ?></span>
                </div>

                <div class="member-info-row">
                    <span class="member-info-label">Membership Number:</span>
                    <span class="member-info-val"><?= htmlspecialchars($loan['member_number']) ?></span>
                </div>
                <div class="member-info-row">
                    <span class="member-info-label">Loan Officer:</span>
                    <span class="member-info-val"><?= htmlspecialchars($loan['loan_officer'] ?? '—') ?></span>
                </div>

                <div class="member-info-row">
                    <span class="member-info-label">Loan Account Number:</span>
                    <span class="member-info-val"><?= htmlspecialchars($loan['loan_number']) ?></span>
                </div>
                <div class="member-info-row">
                    <span class="member-info-label">Branch:</span>
                    <span class="member-info-val">Head Office (Gayaza)</span>
                </div>

                <div class="member-info-row">
                    <span class="member-info-label">National ID:</span>
                    <span class="member-info-val"><?= htmlspecialchars($nationalId) ?></span>
                </div>
                <div class="member-info-row">
                    <span class="member-info-label">Loan Status:</span>
                    <span class="member-info-val">
                        <span class="badge <?= $statusBadge ?> px-2 py-1" style="font-size:.68rem;">
                            <?= htmlspecialchars($statusLabel) ?>
                        </span>
                    </span>
                </div>

                <div class="member-info-row" style="border-bottom:none;padding-bottom:0;">
                    <span class="member-info-label">Phone Number:</span>
                    <span class="member-info-val"><?= htmlspecialchars($phone) ?></span>
                </div>
                <div class="member-info-row" style="border-bottom:none;padding-bottom:0;">
                    <span class="member-info-label">Date Generated:</span>
                    <span class="member-info-val"><?= htmlspecialchars($dateIssued) ?></span>
                </div>
            </div>

            <!-- Loan Transaction History -->
            <div class="stmt-section-title">Loan Transaction History</div>

            <div class="table-responsive">
                <table class="stmt-table">
                <colgroup>
                    <col style="width: 11%;">
                    <col style="width: 38%;">
                    <col style="width: 13%;">
                    <col style="width: 12%;">
                    <col style="width: 12%;">
                    <col style="width: 14%;">
                </colgroup>
                <thead>
                    <tr>
                        <th class="center">Date</th>
                        <th class="left">Description</th>
                        <th class="left">Reference</th>
                        <th class="right">Debit</th>
                        <th class="right">Credit</th>
                        <th class="right">Outstanding Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="row-disbursement">
                        <td class="text-center"><?= htmlspecialchars($loan['issue_date']) ?></td>
                        <td>Loan Disbursement</td>
                        <td><?= htmlspecialchars($loan['loan_number']) ?></td>
                        <td class="text-right"><?= number_format($loan['loan_amount'], 2) ?></td>
                        <td class="text-right text-muted" style="color:#6E7689;">—</td>
                        <td class="text-right amount-balance"><?= number_format($loan['loan_amount'], 2) ?></td>
                    </tr>

                    <?php if (empty($repayments)): ?>
                    <tr>
                        <td colspan="6" class="text-center" style="padding:14px;color:#6E7689;font-style:italic;">
                            No repayments recorded yet for this loan.
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($repayments as $r): ?>
                        <tr>
                            <td class="text-center"><?= htmlspecialchars($r['payment_date']) ?></td>
                            <td>Monthly Loan Repayment (<?= htmlspecialchars($r['payment_method']) ?>)</td>
                            <td><?= htmlspecialchars($r['repayment_number']) ?></td>
                            <td class="text-right text-muted" style="color:#6E7689;">—</td>
                            <td class="text-right amount-credit"><?= number_format($r['amount_paid'], 2) ?></td>
                            <td class="text-right amount-balance"><?= number_format($r['balance_after'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <tr class="row-closing">
                        <td colspan="3" style="color:#0369a1;">Closing Balance</td>
                        <td class="text-right"><?= number_format($loan['loan_amount'], 2) ?></td>
                        <td class="text-right amount-credit"><?= number_format($totalPaid, 2) ?></td>
                        <td class="text-right amount-closing"><?= number_format($loan['outstanding'], 2) ?></td>
                    </tr>
                </tbody>
            </table>
            </div>

            <!-- Consolidated Loan & Account Summary (Placed after Repayment Schedule) -->
            <div class="summary-card">
                <div class="summary-card-header">Loan &amp; Account Summary</div>
                <div class="summary-card-body">
                    <div class="summary-highlights">
                        <div class="summary-highlight-item">
                            <div class="summary-highlight-label">Original Loan Amount</div>
                            <div class="summary-highlight-value">UGX <?= number_format($loan['loan_amount'], 2) ?></div>
                        </div>
                        <div class="summary-highlight-item">
                            <div class="summary-highlight-label">Total Amount Repaid</div>
                            <div class="summary-highlight-value">UGX <?= number_format($totalPaid, 2) ?></div>
                        </div>
                        <div class="summary-highlight-item">
                            <div class="summary-highlight-label">Total Outstanding</div>
                            <div class="summary-highlight-value danger">UGX <?= number_format($loan['outstanding'], 2) ?></div>
                        </div>
                    </div>

                    <div class="summary-grid-2">
                        <div>
                            <div class="summary-row">
                                <span class="summary-label">Loan Term</span>
                                <span class="summary-val"><?= htmlspecialchars($loan['loan_period']) ?></span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Repayment Frequency</span>
                                <span class="summary-val"><?= ucfirst($loan['repayment_frequency'] ?? 'Monthly') ?></span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Installment Amount</span>
                                <span class="summary-val">UGX <?= number_format($loan['monthly_installment'] ?? 0, 2) ?></span>
                            </div>
                        </div>
                        <div>
                            <div class="summary-row">
                                <span class="summary-label">Amount Disbursed</span>
                                <span class="summary-val">UGX <?= number_format($loan['approved_amount'] ?? $loan['loan_amount'], 2) ?></span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Total Repayments</span>
                                <span class="summary-val amount-credit">UGX <?= number_format($totalPaid, 2) ?></span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Current Outstanding</span>
                                <span class="summary-val">UGX <?= number_format($loan['outstanding'], 2) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer notices -->
            <div class="stmt-footer-note">
                <p class="disclaimer">This statement is computer generated and does not require a signature.</p>
            </div>

        </div><!-- /.stmt-body -->

        <!-- ── IMPORTANT NOTICE — full-width below body ── -->
        <div style="background:#1B2B6B;color:#fff;font-size:0.72rem;padding:12px 28px;line-height:1.75;">
            <strong>IMPORTANT NOTICE:</strong> Please examine your statement carefully.
            If we do not hear from you within 28 days, we shall assume the details shown on your Account Statement are correct.
            If, however, you have any query about any transaction on your Account Statement,
            please contact Empower Investment Club at <strong>empowerclub2024@gmail.com</strong>
            or call <strong>0702970129 / 0701486161</strong>.
        </div>

        <!-- ── GENERATED-BY LINE ─────────────────────────── -->
        <div style="font-size:0.7rem;color:#6B7280;font-style:italic;padding:6px 28px 10px;">
            This statement has been generated from Empower Investment Club Management System.
        </div>

        <div class="stmt-footer-bar">
            <img src="<?= APP_URL ?>/public/images/logo.png" alt="" style="height:20px;opacity:.35;">
            <span style="font-size:.72rem;color:#6E7689;font-style:italic;letter-spacing:.04em;">Unleash your financial potential</span>
        </div>
    </div>
</div>

<script>
if(new URLSearchParams(window.location.search).get('auto')==='1'){
    window.addEventListener('load', ()=>window.print());
}
</script>
</body>
</html>
