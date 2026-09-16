<?php
$base     = APP_URL . '/index.php';
$m        = $member;
$fullName = $m['first_name'] . ' ' . $m['last_name'];
$acctNo   = ltrim($m['member_number'], 'EMP') ?: str_pad((string)$m['id'], 6, '0', STR_PAD_LEFT);
$nationalId = $m['national_id'] ?? '—';
$phone    = $m['phone'] ?? '—';
$address  = $m['address'] ?? $m['present_address'] ?? $m['home_address'] ?? '—';
$shareValueFmt = number_format($shareValue, 0);
$statementType = $statementType ?? 'savings';
$isSharesOnly = $statementType === 'shares';
// Carries the exact period/type actually being viewed through to Print and
// WhatsApp share -- previously both always used the current FY dropdown
// value, silently reverting a custom-range or Shares statement back to
// "this FY's savings statement" the moment either was clicked.
$periodQuery = 'statement_type=' . urlencode($statementType);
if (($_GET['range_mode'] ?? 'fy') === 'custom' && !empty($_GET['date_from']) && !empty($_GET['date_to'])) {
    $periodQuery .= '&range_mode=custom&date_from=' . urlencode($_GET['date_from']) . '&date_to=' . urlencode($_GET['date_to']);
} else {
    $periodQuery .= '&year=' . (int)$fyYear;
}
?>

<style>
/* Matches the printed statement's font exactly (statements/print.php) --
 * both now use the standard Empower Inter typeface, so the on-screen
 * preview still looks like what actually prints. */
.stmt-container, .stmt-container * { font-family: 'Inter', sans-serif; }
.stmt-container { max-width: 820px; margin: 0 auto; }
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
    width: 360px; height: 360px; object-fit: contain;
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
.stmt-logo-divider { width: 2px; height: 48px; background: #E5E7F0; }
.stmt-brand-title { font-size: 1.35rem; font-weight: 900; color: #1B2B6B; text-transform: uppercase; line-height: 1.1; letter-spacing: 0.02em; }
.stmt-brand-title span { color: #FF7E06; }
.stmt-brand-tagline { font-size: 0.7rem; color: #6E7689; font-style: italic; margin-top: 3px; }
.stmt-contact-info { text-align: right; font-size: 0.78rem; color: #6B7280; line-height: 1.7; }
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
    border: 1px solid #E5E7F0;
    margin-bottom: 32px;
}
.member-info-row {
    display: flex;
    justify-content: space-between;
    padding-bottom: 6px;
    border-bottom: 1px dashed #E5E7F0;
}
.member-info-row:last-child { border-bottom: none; padding-bottom: 0; }
.member-info-label { color: #6E7689; font-weight: 500; }
.member-info-val { color: #171B2E; font-weight: 600; }

.stmt-section-title {
    font-size: 0.75rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: #1B2B6B;
    margin-bottom: 10px;
}

.stmt-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
    margin-bottom: 24px;
    border: 1px solid #E5E7F0;
}
.stmt-table th {
    background: #1B2B6B;
    color: #fff;
    text-align: center;
    padding: 10px 12px;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    border: 1px solid #E5E7F0;
}
.stmt-table th.right { text-align: right; }
.stmt-table th.left { text-align: left; }
.stmt-table td {
    padding: 9px 12px;
    border: 1px solid #E5E7F0;
    vertical-align: middle;
}
.stmt-table tr:nth-child(even) td { background: #f8f9fc; }
.stmt-table .row-opening { background: #f1f5f9 !important; font-weight: 600; }
.stmt-table .row-closing { background: #e0f2fe !important; font-weight: 700; }

.text-right { text-align: right; }
.text-left { text-align: left; }
.text-center { text-align: center; }
.amount-credit { color: #16a34a; font-weight: 600; }
.amount-debit { color: #dc2626; font-weight: 600; }
.amount-balance { color: #1e3a8a; font-weight: 700; }
.amount-closing { color: #1e3a8a; font-weight: 900; font-size: 1.02rem; }

.tx-summary-bar {
    background: #f8f9fc;
    border: 1px solid #E5E7F0;
    border-radius: 6px;
    padding: 16px 20px;
    margin-bottom: 32px;
    font-size: 0.85rem;
}
.tx-summary-title {
    font-weight: 700;
    color: #1B2B6B;
    text-transform: uppercase;
    font-size: 0.72rem;
    letter-spacing: 0.08em;
    margin-bottom: 12px;
}
.tx-summary-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 12px;
    text-align: center;
}
.tx-summary-item { border-right: 1px solid #E5E7F0; padding-right: 8px; }
.tx-summary-item:last-child { border-right: none; padding-right: 0; }
.tx-summary-label { color: #6E7689; font-size: 0.72rem; margin-bottom: 2px; }
.tx-summary-val { font-weight: 700; color: #171B2E; font-size: 1rem; }

.info-card {
    border: 1px solid #E5E7F0;
    border-radius: 6px;
    overflow: hidden;
    background: #fdfdfd;
    margin-bottom: 28px;
}
.info-card-header {
    background: #1B2B6B;
    color: #fff;
    font-size: 0.75rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    padding: 10px 18px;
}
.info-card-body { padding: 16px 20px; }
.info-card-row {
    display: flex;
    justify-content: space-between;
    padding: 7px 0;
    border-bottom: 1px solid #f1f5f9;
    font-size: 0.9rem;
}
.info-card-row:last-child { border-bottom: none; padding-bottom: 0; }
.info-card-label { color: #6B7280; }
.info-card-val { font-weight: 600; color: #171B2E; }
.info-card-total {
    display: flex;
    justify-content: space-between;
    padding-top: 10px;
    margin-top: 4px;
    border-top: 2px solid #E5E7F0;
    font-size: 1.1rem;
    font-weight: 800;
}
.info-card-total .info-card-label { color: #1B2B6B; font-weight: 800; }
.info-card-total .info-card-val { color: #1B2B6B; font-size: 1.2rem; font-weight: 900; }
.info-card-total.asset-total .info-card-label { color: #111827; }
.info-card-total.asset-total .info-card-val { color: #FF7E06; }

.stmt-footer-note {
    text-align: center;
    margin-top: 32px;
    padding-top: 18px;
    border-top: 1px dashed #E5E7F0;
    font-size: 0.8rem;
    color: #6B7280;
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
.stmt-footer-tagline { font-size: 0.75rem; color: #6E7689; font-style: italic; letter-spacing: 0.04em; }

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
            <i class="bi bi-file-text-fill me-2" style="color:var(--brand-navy)"></i><?= $isSharesOnly ? 'Shares Statement' : 'Savings Statement' ?>
        </h1>
        <p class="text-muted mb-0 small"><?= htmlspecialchars($fullName) ?> · <?= htmlspecialchars($fyLabel) ?></p>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-center">
        <form method="GET" action="<?= $base ?>" class="d-flex align-items-center gap-2 mb-0">
            <input type="hidden" name="page" value="statement-view">
            <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
            <input type="hidden" name="statement_type" value="<?= htmlspecialchars($statementType) ?>">
            <select name="year" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto">
                <?php foreach ($years as $y): ?>
                <option value="<?= $y ?>" <?= $y === $fyYear ? 'selected' : '' ?>>
                    <?= $y ?>/<?= $y+1 ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
        <a href="<?= $base ?>?page=statement-print&member_id=<?= $m['id'] ?>&<?= $periodQuery ?>"
           class="btn btn-success btn-sm" target="_blank">
            <i class="bi bi-printer me-1"></i>Print / PDF
        </a>
        <button type="button" onclick="shareStatementWhatsApp()" class="btn btn-sm" style="background:#25D366;color:#fff;border:none;">
            <i class="bi bi-whatsapp me-1"></i>Share on WhatsApp
        </button>
        <a href="<?= $base ?>?page=statements" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<script>
function shareStatementWhatsApp() {
    fetch('<?= APP_URL ?>/index.php?page=statement-whatsapp&member_id=<?= $m['id'] ?>&<?= $periodQuery ?>')
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
<div class="col-xl-10 col-lg-11">

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

        <div class="stmt-doc-title"><?= $isSharesOnly ? 'Shares Statement' : 'Savings Statement' ?></div>

        <!-- ── MEMBER INFORMATION ─────────────────────────── -->
        <div class="member-info-box">
            <div class="member-info-row">
                <span class="member-info-label">Member:</span>
                <span class="member-info-val"><?= htmlspecialchars($fullName) ?></span>
            </div>
            <div class="member-info-row">
                <span class="member-info-label">Statement Period:</span>
                <span class="member-info-val"><?= htmlspecialchars($fyLabel) ?></span>
            </div>
            <div class="member-info-row">
                <span class="member-info-label">Membership No:</span>
                <span class="member-info-val"><?= htmlspecialchars($m['member_number']) ?></span>
            </div>
            <div class="member-info-row">
                <span class="member-info-label">Generated:</span>
                <span class="member-info-val"><?= htmlspecialchars($dateIssued) ?></span>
            </div>
            <div class="member-info-row">
                <span class="member-info-label">Account Number:</span>
                <span class="member-info-val"><?= htmlspecialchars($acctNo) ?></span>
            </div>
            <div class="member-info-row">
                <span class="member-info-label">Phone:</span>
                <span class="member-info-val"><?= htmlspecialchars($phone) ?></span>
            </div>
            <div class="member-info-row">
                <span class="member-info-label">National ID:</span>
                <span class="member-info-val"><?= htmlspecialchars($nationalId) ?></span>
            </div>
            <div class="member-info-row" style="border-bottom:none;padding-bottom:0;">
                <span class="member-info-label">Address:</span>
                <span class="member-info-val"><?= htmlspecialchars($address) ?></span>
            </div>
        </div>

        <?php if ($isSharesOnly): ?>
        <!-- ── SHARE LEDGER TABLE ──────────────────────────── -->
        <div class="stmt-section-title">Share Ledger</div>

        <div class="table-responsive">
            <table class="stmt-table">
            <colgroup>
                <col style="width: 12%;">
                <col style="width: 33%;">
                <col style="width: 13%;">
                <col style="width: 13%;">
                <col style="width: 13%;">
                <col style="width: 16%;">
            </colgroup>
            <thead>
                <tr>
                    <th class="center">Date</th>
                    <th class="left">Description of the transaction</th>
                    <th class="right">Debits</th>
                    <th class="right">Credits</th>
                    <th class="right">Balance</th>
                    <th class="center">Authorizing Person's Signature</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($shareLedgerRows)): ?>
                <tr>
                    <td colspan="6" class="text-center" style="padding:20px;color:#6E7689;font-style:italic;">
                        No share entries recorded.
                    </td>
                </tr>
                <?php else: foreach ($shareLedgerRows as $row): ?>
                <tr>
                    <td class="text-center"><?= date('d-M-Y', strtotime($row['date'])) ?></td>
                    <td><?= htmlspecialchars($row['description']) ?></td>
                    <td class="text-right"><?= $row['debit'] > 0 ? number_format($row['debit'], 2) : '—' ?></td>
                    <td class="text-right"><?= $row['credit'] > 0 ? number_format($row['credit'], 2) : '—' ?></td>
                    <td class="text-right amount-balance"><?= number_format($row['balance'], 2) ?></td>
                    <td>&nbsp;</td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
        <!-- ── TRANSACTION TABLE ──────────────────────────── -->
        <div class="stmt-section-title">Transaction History</div>

        <div class="table-responsive">
            <table class="stmt-table">
            <colgroup>
                <col style="width: 14%;">
                <col style="width: 34%;">
                <col style="width: 16%;">
                <col style="width: 12%;">
                <col style="width: 12%;">
                <col style="width: 12%;">
            </colgroup>
            <thead>
                <tr>
                    <th class="center">Date</th>
                    <th class="left">Description</th>
                    <th class="left">Reference</th>
                    <th class="right">Debit (UGX)</th>
                    <th class="right">Credit (UGX)</th>
                    <th class="right">Balance (UGX)</th>
                </tr>
            </thead>
            <tbody>
                <!-- Opening Balance Row -->
                <tr class="row-opening">
                    <td class="text-center"><?= htmlspecialchars($fyStart) ?></td>
                    <td>Opening Balance</td>
                    <td class="text-muted" style="color:#6E7689;">—</td>
                    <td class="text-right text-muted" style="color:#6E7689;">—</td>
                    <td class="text-right text-muted" style="color:#6E7689;">—</td>
                    <td class="text-right amount-balance"><?= number_format($openingBalance, 2) ?></td>
                </tr>

                <?php if (empty($transactions)): ?>
                <tr>
                    <td colspan="6" class="text-center" style="padding:20px;color:#6E7689;font-style:italic;">
                        No transactions during the selected statement period.
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($transactions as $tx): ?>
                    <tr>
                        <td class="text-center"><?= date('d-M-Y', strtotime($tx['date'])) ?></td>
                        <td><?= htmlspecialchars($tx['description']) ?><?php if (($tx['type'] ?? null) === 'opening_balance' && !empty($tx['notes'])): ?><br><span style="font-size:.68rem;color:#6E7689;font-style:italic;"><?= htmlspecialchars($tx['notes']) ?></span><?php endif; ?></td>
                        <td><?= htmlspecialchars($tx['reference']) ?></td>
                        <td class="text-right <?= $tx['debit'] > 0 ? 'amount-debit' : 'text-muted' ?>" style="<?= $tx['debit'] == 0 ? 'color:#6E7689;' : '' ?>">
                            <?= $tx['debit'] > 0 ? number_format($tx['debit'], 2) : '—' ?>
                        </td>
                        <td class="text-right <?= $tx['credit'] > 0 ? 'amount-credit' : 'text-muted' ?>" style="<?= $tx['credit'] == 0 ? 'color:#6E7689;' : '' ?>">
                            <?= $tx['credit'] > 0 ? number_format($tx['credit'], 2) : '—' ?>
                        </td>
                        <td class="text-right amount-balance"><?= number_format($tx['balance'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Closing Balance Row -->
                <tr class="row-closing">
                    <td colspan="3" style="color:#0369a1;">Closing Balance</td>
                    <td class="text-right amount-debit"><?= number_format($totalDebits, 2) ?></td>
                    <td class="text-right amount-credit"><?= number_format($totalCredits, 2) ?></td>
                    <td class="text-right amount-closing"><?= number_format($closingBalance, 2) ?></td>
                </tr>
            </tbody>
        </table>
        </div>

        <!-- ── TRANSACTION SUMMARY ────────────────────────── -->
        <div class="tx-summary-bar">
            <div class="tx-summary-title">Transaction Summary</div>
            <div class="tx-summary-grid">
                <div class="tx-summary-item">
                    <div class="tx-summary-label">Deposits</div>
                    <div class="tx-summary-val"><?= $depositCount ?></div>
                </div>
                <div class="tx-summary-item">
                    <div class="tx-summary-label">Withdrawals</div>
                    <div class="tx-summary-val"><?= $withdrawalCount ?></div>
                </div>
                <div class="tx-summary-item">
                    <div class="tx-summary-label">Total Deposited</div>
                    <div class="tx-summary-val amount-credit" style="font-size:0.9rem;">UGX <?= number_format($totalCredits, 2) ?></div>
                </div>
                <div class="tx-summary-item">
                    <div class="tx-summary-label">Total Withdrawn</div>
                    <div class="tx-summary-val amount-debit" style="font-size:0.9rem;">UGX <?= number_format($totalDebits, 2) ?></div>
                </div>
                <div class="tx-summary-item" style="border-right:none;padding-right:0;">
                    <div class="tx-summary-label">Net Change</div>
                    <div class="tx-summary-val" style="font-size:0.9rem;color:<?= $netChange >= 0 ? '#1e3a8a' : '#dc2626' ?>;">UGX <?= number_format($netChange, 2) ?></div>
                </div>
            </div>
        </div>

        <!-- ── ACCOUNT SUMMARY CARD ───────────────────────── -->
        <div class="info-card">
            <div class="info-card-header">Account Summary</div>
            <div class="info-card-body">
                <div class="info-card-row">
                    <span class="info-card-label">Opening Balance</span>
                    <span class="info-card-val">UGX <?= number_format($openingBalance, 2) ?></span>
                </div>
                <div class="info-card-row">
                    <span class="info-card-label">Total Credits</span>
                    <span class="info-card-val amount-credit">UGX <?= number_format($totalCredits, 2) ?></span>
                </div>
                <div class="info-card-row">
                    <span class="info-card-label">Total Debits</span>
                    <span class="info-card-val amount-debit">UGX <?= number_format($totalDebits, 2) ?></span>
                </div>
                <div class="info-card-total">
                    <span class="info-card-label">Closing Balance</span>
                    <span class="info-card-val">UGX <?= number_format($closingBalance, 2) ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isSharesOnly): ?>
        <!-- ── MEMBER POSITION CARD (moved here from the Savings Statement) ── -->
        <div class="info-card">
            <div class="info-card-header">Member Position</div>
            <div class="info-card-body">
                <div class="info-card-row">
                    <span class="info-card-label">Shares Held</span>
                    <span class="info-card-val"><?= number_format($shareCount) ?></span>
                </div>
                <div class="info-card-row">
                    <span class="info-card-label">Share Capital</span>
                    <span class="info-card-val">UGX <?= number_format($shareCapital, 2) ?></span>
                </div>
                <div class="info-card-total asset-total">
                    <span class="info-card-label">Total Assets</span>
                    <span class="info-card-val">UGX <?= number_format($totalMemberAssets, 2) ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── FOOTER NOTICES ─────────────────────────────── -->
        <div class="stmt-footer-note">
            <p class="disclaimer">This statement is computer generated and does not require a signature.</p>
            <p class="end-mark">End of Statement</p>
        </div>

    </div><!-- /.stmt-body -->

    <!-- ── IMPORTANT NOTICE — outside stmt-body, bleeds full width ── -->
    <div style="background:#1B2B6B;color:#fff;font-size:0.72rem;padding:10px 28px;line-height:1.7;">
        <strong>IMPORTANT NOTICE:</strong> Please examine your statement carefully.
        If we do not hear from you within 28 days, we shall assume the details shown on your Account Statement are correct.
        If, however, you have any query about any transaction on your Account Statement, please contact
        Empower Investment Club at <strong>empowerclub2024@gmail.com</strong>
        or call <strong>0702970129 / 0701486161</strong>.
    </div>

    <!-- ── GENERATED-BY LINE ─────────────────────────────── -->
    <div style="font-size:0.7rem;color:#6B7280;font-style:italic;padding:6px 28px 10px;">
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
