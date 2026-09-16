<?php
$base     = APP_URL . '/index.php';
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

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&display=swap');

* { font-family: 'Inter', sans-serif; }
.stmt-container { max-width: 920px; margin: 0 auto; }
.stmt-card {
    background: #fff;
    border: 2px solid #1B2B6B;
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(27,43,107,0.12);
    position: relative;
    overflow: hidden;
}
.stmt-watermark {
    position: absolute; top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    width: 380px; height: 380px; object-fit: contain;
    opacity: 0.04; pointer-events: none; z-index: 0;
}
.stmt-header {
    padding: 24px 32px;
    border-bottom: 4px solid #FF7E06;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #fff;
    position: relative;
    z-index: 1;
}
.stmt-logo-area { display: flex; align-items: center; gap: 16px; }
.stmt-logo-img { height: 56px; width: 56px; object-fit: contain; }
.stmt-logo-divider { width: 2px; height: 48px; background: #e2e8f0; }
.stmt-brand-title { font-family: 'Space Grotesk', sans-serif; font-size: 1.35rem; font-weight: 900; color: #1B2B6B; text-transform: uppercase; line-height: 1.1; letter-spacing: 0.02em; }
.stmt-brand-title span { color: #FF7E06; }
.stmt-brand-tagline { font-size: 0.7rem; color: #64748b; font-style: italic; margin-top: 3px; }
.stmt-contact-info { text-align: right; font-size: 0.78rem; color: #334155; line-height: 1.7; }
.stmt-contact-info strong { color: #1B2B6B; }

.stmt-body { padding: 32px; position: relative; z-index: 1; }
.stmt-doc-title {
    text-align: center;
    background: #1B2B6B;
    color: #fff;
    font-size: 0.85rem;
    font-weight: 800;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    padding: 0.6rem 1rem;
    border-radius: 4px;
    margin-bottom: 28px;
}

.member-info-box {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px 32px;
    font-size: 0.875rem;
    background: #f8f9fc;
    padding: 18px 24px;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
    margin-bottom: 32px;
}
.member-info-row {
    display: flex;
    justify-content: space-between;
    padding-bottom: 6px;
    border-bottom: 1px dashed #e2e8f0;
}
.member-info-row:last-child { border-bottom: none; padding-bottom: 0; }
.member-info-label { color: #64748b; font-weight: 500; }
.member-info-val { color: #1e293b; font-weight: 600; }

.stmt-section-title {
    font-size: 0.78rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: #1B2B6B;
    margin-bottom: 12px;
    border-bottom: 2px solid #1B2B6B;
    padding-bottom: 4px;
}

.stmt-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8rem;
    margin-bottom: 32px;
    border: 1px solid #cbd5e1;
}
.stmt-table th {
    background: #1B2B6B;
    color: #fff;
    text-align: center;
    padding: 11px 8px;
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    border: 1px solid #cbd5e1;
}
.stmt-table th.right { text-align: right; }
.stmt-table th.left { text-align: left; }
.stmt-table th.center { text-align: center; }
.stmt-table td {
    padding: 9px 10px;
    border: 1px solid #cbd5e1;
    vertical-align: middle;
}
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
.amount-closing { color: #1e3a8a; font-weight: 900; font-size: 1.02rem; }

/* Consolidated Summary Card */
.summary-card {
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    overflow: hidden;
    background: #fdfdfd;
    margin-bottom: 32px;
}
.summary-card-header {
    background: #1B2B6B;
    color: #fff;
    font-size: 0.78rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    padding: 11px 20px;
}
.summary-card-body { padding: 20px 24px; }
.summary-highlights {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 20px;
    padding-bottom: 20px;
    border-bottom: 2px solid #e2e8f0;
}
.summary-highlight-item {
    background: #f8f9fc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 14px;
    text-align: center;
}
.summary-highlight-label { font-size: 0.72rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; }
.summary-highlight-value { font-family: 'Space Grotesk', sans-serif; font-size: 1.15rem; font-weight: 800; color: #1B2B6B; }
.summary-highlight-value.danger { color: #dc2626; font-size: 1.3rem; }

.summary-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px 32px;
    font-size: 0.88rem;
}
.summary-row {
    display: flex;
    justify-content: space-between;
    padding: 7px 0;
    border-bottom: 1px solid #f1f5f9;
}
.summary-row:last-child { border-bottom: none; }
.summary-label { color: #475569; }
.summary-val { font-weight: 600; color: #1e293b; }

.summary-total-bar {
    display: flex;
    justify-content: space-between;
    padding-top: 14px;
    margin-top: 16px;
    border-top: 2px solid #1B2B6B;
    font-size: 1.2rem;
    font-weight: 900;
}
.summary-total-bar .summary-label { color: #1B2B6B; font-size: 1.1rem; }
.summary-total-bar .summary-val { color: #dc2626; font-size: 1.35rem; }

.stmt-footer-note {
    text-align: center;
    margin-top: 36px;
    padding-top: 18px;
    border-top: 1px dashed #cbd5e1;
    font-size: 0.8rem;
    color: #475569;
    line-height: 1.6;
}
.stmt-footer-note p.disclaimer { font-style: italic; margin-bottom: 4px; }
.stmt-footer-note p.end-mark { font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; color: #1B2B6B; margin-top: 6px; }

.stmt-footer-bar {
    padding: 14px 32px;
    border-top: 3px solid #FF7E06;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    background: #fff;
}
.stmt-footer-tagline { font-size: 0.75rem; color: #9ca3af; font-style: italic; letter-spacing: 0.04em; }

@media print {
    @page { size: A4 portrait; margin: 10mm 12mm 12mm; }
    body { background: #fff; color: #000; }
    .toolbar { display: none !important; }
    .stmt-container { max-width: 100%; margin: 0; }
    .stmt-card { box-shadow: none !important; border: 1px solid #333 !important; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
}
</style>

<!-- ── Toolbar ─────────────────────────────────────────────── -->
<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-file-earmark-text-fill me-2" style="color:var(--brand-navy)"></i>Loan Statement
        </h1>
        <p class="text-muted mb-0 small"><?= htmlspecialchars($fullName) ?> · Loan #<?= htmlspecialchars($loan['loan_number']) ?></p>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-center">
        <a href="<?= $base ?>?page=loan-statement-print&id=<?= $loan['id'] ?>"
           class="btn btn-success btn-sm" target="_blank">
            <i class="bi bi-printer me-1"></i>Print / PDF
        </a>
        <button type="button" onclick="shareLoanStatementWhatsApp()" class="btn btn-sm" style="background:#25D366;color:#fff;border:none;">
            <i class="bi bi-whatsapp me-1"></i>Share on WhatsApp
        </button>
        <a href="<?= $base ?>?page=loan-view&id=<?= $loan['id'] ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to Loan
        </a>
    </div>
</div>

<script>
function shareLoanStatementWhatsApp() {
    fetch('<?= APP_URL ?>/index.php?page=loan-whatsapp-schedule&id=<?= $loan['id'] ?>')
        .then(r => r.json())
        .then(data => {
            if (data.url) window.open(data.url, '_blank');
            else alert('Could not generate statement summary.');
        })
        .catch(() => alert('Error generating statement summary.'));
}
</script>

<!-- ── Statement document ───────────────────────────────────── -->
<div class="row justify-content-center">
<div class="col-xl-11 col-lg-12">

<div class="stmt-container">
<div class="stmt-card">

    <!-- Watermark -->
    <img class="stmt-watermark" src="<?= APP_URL ?>/public/images/logo.png" alt="">

    <!-- ── HEADER ─────────────────────────────────────────── -->
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

    <!-- ── BODY ───────────────────────────────────────────── -->
    <div class="stmt-body">

        <div class="stmt-doc-title">LOAN STATEMENT</div>

        <!-- ── 1. BORROWER INFORMATION ─────────────────────── -->
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
                    <span class="badge <?= $statusBadge ?> px-2 py-1" style="font-size:.75rem;">
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

        <!-- ── 2. LOAN TRANSACTION HISTORY (PRIMARY SECTION) ── -->
        <div class="stmt-section-title">Loan Transaction History</div>

        <div class="table-responsive">
            <table class="stmt-table">
            <colgroup>
                <col style="width: 12%;">
                <col style="width: 40%;">
                <col style="width: 14%;">
                <col style="width: 11%;">
                <col style="width: 11%;">
                <col style="width: 12%;">
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
                    <td class="text-right text-muted" style="color:#94a3af;">—</td>
                    <td class="text-right amount-balance"><?= number_format($loan['loan_amount'], 2) ?></td>
                </tr>

                <?php if (empty($repayments)): ?>
                <tr>
                    <td colspan="6" class="text-center" style="padding:16px;color:#64748b;font-style:italic;">
                        No repayments recorded yet for this loan.
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($repayments as $r): ?>
                    <tr>
                        <td class="text-center"><?= htmlspecialchars($r['payment_date']) ?></td>
                        <td>Monthly Loan Repayment (<?= htmlspecialchars($r['payment_method']) ?>)</td>
                        <td><?= htmlspecialchars($r['repayment_number']) ?></td>
                        <td class="text-right text-muted" style="color:#94a3af;">—</td>
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

        <!-- ── 4. LOAN & ACCOUNT SUMMARY ──────────────────── -->
        <div class="summary-card">
            <div class="summary-card-header">Loan &amp; Account Summary</div>
            <div class="summary-card-body">

                <!-- 3 highlight metrics -->
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

                <!-- Detail rows — no interest/profit figures -->
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

        <!-- ── 5. FOOTER NOTICES ───────────────────────────── -->
        <div class="stmt-footer-note">
            <p class="disclaimer">This statement is computer generated and does not require a signature.</p>
        </div>

    </div><!-- /.stmt-body -->

    <!-- ── IMPORTANT NOTICE — full-width below body ──────── -->
    <div style="background:#1B2B6B;color:#fff;font-size:0.75rem;padding:12px 32px;line-height:1.75;">
        <strong>IMPORTANT NOTICE:</strong> Please examine your statement carefully.
        If we do not hear from you within 28 days, we shall assume the details shown on your Account Statement are correct.
        If, however, you have any query about any transaction on your Account Statement,
        please contact Empower Investment Club at <strong>empowerclub2024@gmail.com</strong>
        or call <strong>0702970129 / 0701486161</strong>.
    </div>

    <!-- ── GENERATED-BY LINE ─────────────────────────────── -->
    <div style="font-size:0.7rem;color:#475569;font-style:italic;padding:6px 32px 10px;">
        This statement has been generated from Empower Investment Club Management System.
    </div>

    <!-- ── LETTERHEAD FOOTER ──────────────────────────────── -->
    <div class="stmt-footer-bar">
        <img src="<?= APP_URL ?>/public/images/logo.png" alt="" style="height:20px;opacity:.35;">
        <span class="stmt-footer-tagline">Unleash your financial potential</span>
    </div>

</div><!-- /.stmt-card -->
</div><!-- /.stmt-container -->

</div><!-- /.col -->
</div><!-- /.row -->
