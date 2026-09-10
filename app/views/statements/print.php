<?php $isSharesOnly = ($statementType ?? 'savings') === 'shares'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Statement — <?= htmlspecialchars($member['first_name'].' '.$member['last_name']) ?> · <?= $fyLabel ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Segoe UI', Arial, sans-serif;
    font-size: 10pt;
    color: #1a1a1a;
    background: #e8eaf0;
}
.page-wrap { max-width: 760px; margin: 1.5rem auto; }
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
    box-shadow: 0 4px 20px rgba(0,0,0,.18);
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
    border-bottom: 4px solid #F47920;
    display: flex; align-items: center; justify-content: space-between;
    background: #fff; position: relative; z-index: 1;
}
.stmt-logo-area { display: flex; align-items: center; gap: 14px; }
.stmt-logo-img { height: 52px; width: 52px; object-fit: contain; }
.stmt-logo-divider { width: 2px; height: 44px; background: #e2e8f0; }
.stmt-brand-title { font-size: 1.3rem; font-weight: 900; color: #1B2B6B; text-transform: uppercase; line-height: 1.1; letter-spacing: 0.02em; }
.stmt-brand-title span { color: #F47920; }
.stmt-brand-tagline { font-size: 0.68rem; color: #64748b; font-style: italic; margin-top: 2px; }
.stmt-contact-info { text-align: right; font-size: 0.78rem; color: #334155; line-height: 1.7; }
.stmt-contact-info strong { color: #1B2B6B; }

.stmt-body { padding: 26px 32px; position: relative; z-index: 1; }
.stmt-doc-title {
    text-align: center; background: #1B2B6B; color: #fff;
    font-size: 0.82rem; font-weight: 800; letter-spacing: .18em;
    text-transform: uppercase; padding: .55rem 1rem; border-radius: 4px; margin-bottom: 24px;
}

.member-info-box {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 8px 24px; font-size: 0.82rem; margin-bottom: 26px;
    background: #f8f9fc; padding: 14px 18px; border-radius: 6px; border: 1px solid #e2e8f0;
}
.member-info-row {
    display: grid; grid-template-columns: 130px 1fr; gap: 0 12px;
    align-items: baseline; padding-bottom: 4px; border-bottom: 1px dashed #e2e8f0;
}
.member-info-row:last-child { border-bottom: none; padding-bottom: 0; }
.member-info-label { color: #64748b; font-weight: 500; white-space: nowrap; }
.member-info-val { color: #1e293b; font-weight: 600; }

.stmt-section-title {
    font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; color: #1B2B6B; margin-bottom: 8px;
}

.stmt-table {
    width: 100%; border-collapse: collapse; font-size: 0.8rem; margin-bottom: 18px; border: 1px solid #cbd5e1;
}
.stmt-table th {
    background: #1B2B6B; color: #fff; text-align: center; padding: 8px 10px;
    font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; border: 1px solid #cbd5e1;
}
.stmt-table th.right { text-align: right; }
.stmt-table th.left { text-align: left; }
.stmt-table td { padding: 7px 10px; border: 1px solid #cbd5e1; vertical-align: middle; }
.stmt-table tr:nth-child(even) td { background: #f8f9fc; }
.stmt-table .row-opening { background: #f1f5f9 !important; font-weight: 600; }
.stmt-table .row-closing { background: #e0f2fe !important; font-weight: 700; }

.text-right { text-align: right; }
.text-left { text-align: left; }
.text-center { text-align: center; }
.amount-credit { color: #16a34a; font-weight: 600; }
.amount-debit { color: #dc2626; font-weight: 600; }
.amount-balance { color: #1e3a8a; font-weight: 700; }
.amount-closing { color: #1e3a8a; font-weight: 900; font-size: 0.98rem; }

.tx-summary-bar {
    background: #f8f9fc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 16px; margin-bottom: 24px; font-size: 0.8rem;
}
.tx-summary-title {
    font-weight: 700; color: #1B2B6B; text-transform: uppercase; font-size: 0.68rem; letter-spacing: 0.08em; margin-bottom: 8px;
}
.tx-summary-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; text-align: center; }
.tx-summary-item { border-right: 1px solid #e2e8f0; padding-right: 6px; }
.tx-summary-item:last-child { border-right: none; padding-right: 0; }
.tx-summary-label { color: #64748b; font-size: 0.68rem; margin-bottom: 2px; }
.tx-summary-val { font-weight: 700; color: #1e293b; font-size: 0.9rem; }

.info-card {
    border: 1px solid #cbd5e1; border-radius: 6px; overflow: hidden; background: #fdfdfd; margin-bottom: 22px; font-size: 0.85rem;
}
.info-card-header {
    background: #1B2B6B; color: #fff; font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; padding: 8px 14px;
}
.info-card-body { padding: 12px 16px; }
.info-card-row { display: grid; grid-template-columns: 160px 1fr; gap: 0 12px; align-items: baseline; padding: 5px 0; border-bottom: 1px solid #f1f5f9; font-size: 0.85rem; }
.info-card-row:last-child { border-bottom: none; padding-bottom: 0; }
.info-card-label { color: #475569; white-space: nowrap; }
.info-card-val { font-weight: 600; color: #1e293b; }
.info-card-total {
    display: flex; justify-content: space-between; padding-top: 8px; margin-top: 3px; border-top: 2px solid #cbd5e1; font-size: 1.02rem; font-weight: 800;
}
.info-card-total .info-card-label { color: #1B2B6B; font-weight: 800; }
.info-card-total .info-card-val { color: #1B2B6B; font-size: 1.1rem; font-weight: 900; }
.info-card-total.asset-total .info-card-label { color: #111827; }
.info-card-total.asset-total .info-card-val { color: #F47920; }

.stmt-footer-note {
    text-align: center; margin-top: 24px; padding-top: 14px; border-top: 1px dashed #cbd5e1; font-size: 0.75rem; color: #475569; line-height: 1.6;
}
.stmt-footer-note p.disclaimer { font-style: italic; margin-bottom: 3px; }
.stmt-footer-note p.end-mark { font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; color: #1B2B6B; margin-top: 4px; }

.stmt-footer-bar {
    padding: 12px 28px; border-top: 3px solid #F47920;
    display: flex; align-items: center; justify-content: center; gap: 8px; background: #fff;
}

@media print {
    @page { size: A4 portrait; margin: 10mm 12mm 12mm; }
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
$m        = $member;
$fullName = $m['first_name'] . ' ' . $m['last_name'];
$acctNo   = ltrim($m['member_number'], 'EMP') ?: str_pad((string)$m['id'], 6, '0', STR_PAD_LEFT);
$nationalId = $m['national_id'] ?? '—';
$phone    = $m['phone'] ?? '—';
$address  = $m['address'] ?? $m['present_address'] ?? $m['home_address'] ?? '—';
$shareValueFmt = number_format($shareValue, 0);
$periodQuery = 'statement_type=' . urlencode($statementType ?? 'savings');
if (($_GET['range_mode'] ?? 'fy') === 'custom' && !empty($_GET['date_from']) && !empty($_GET['date_to'])) {
    $periodQuery .= '&range_mode=custom&date_from=' . urlencode($_GET['date_from']) . '&date_to=' . urlencode($_GET['date_to']);
} else {
    $periodQuery .= '&year=' . (int)$fyYear;
}
// Member portal's own print action (MemberPortalController::statementPrint())
// renders this same view with $portalMode = true -- the staff "Back" target
// below (statement-view&member_id=...) is a staff-only route a member
// session can't reach, so the back link must point at the member's own
// statement page instead, with no member_id (derived from session there).
$portalMode = $portalMode ?? false;
$backUrl = $portalMode
    ? APP_URL . '/index.php?page=portal-statement'
    : APP_URL . '/index.php?page=statement-view&member_id=' . $m['id'] . '&' . $periodQuery;
?>

<div class="page-wrap">
    <div class="toolbar no-print">
        <button class="btn-print" onclick="window.print()">🖨 Print / Save PDF</button>
        <a class="btn-back" href="<?= htmlspecialchars($backUrl) ?>">← Back</a>
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
            <?php if ($isSharesOnly): ?>
            <div class="stmt-doc-title">Shares Statement</div>

            <!-- Member Information -- matches the Savings Statement's info box exactly -->
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

            <div class="stmt-section-title">Share Ledger</div>

            <div class="table-responsive">
                <table class="stmt-table">
                <colgroup>
                    <col style="width:12%;"><col style="width:33%;"><col style="width:13%;">
                    <col style="width:13%;"><col style="width:13%;"><col style="width:16%;">
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
                        <td colspan="6" class="text-center" style="padding:20px;color:#64748b;font-style:italic;">No share entries recorded.</td>
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

            <!-- ── MEMBER POSITION (moved here from the Savings Statement) ── -->
            <?php if (($shareCount ?? 0) > 0 || ($totalSavings ?? 0) > 0): ?>
            <div class="table-responsive">
                <table style="width:100%;border-collapse:collapse;font-size:0.82rem;border:1px solid #cbd5e1;margin-top:22px;">
                <thead>
                    <tr>
                        <th colspan="2" style="background:#1B2B6B;color:#fff;padding:8px 14px;text-align:left;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;">Member Position</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="padding:8px 14px;border:1px solid #e2e8f0;color:#475569;width:50%;">Shares Held</td>
                        <td style="padding:8px 14px;border:1px solid #e2e8f0;font-weight:600;color:#1e293b;text-align:right;"><?= number_format($shareCount ?? 0) ?></td>
                    </tr>
                    <tr style="background:#f8f9fc;">
                        <td style="padding:8px 14px;border:1px solid #e2e8f0;color:#475569;">Share Capital</td>
                        <td style="padding:8px 14px;border:1px solid #e2e8f0;font-weight:600;color:#1e293b;text-align:right;">UGX <?= number_format($shareCapital ?? 0, 2) ?></td>
                    </tr>
                    <tr style="background:#f0f7ff;">
                        <td style="padding:9px 14px;border:1px solid #e2e8f0;font-weight:700;color:#1B2B6B;">Total Assets</td>
                        <td style="padding:9px 14px;border:1px solid #e2e8f0;font-weight:800;color:#F47920;text-align:right;font-size:0.95rem;">UGX <?= number_format($totalMemberAssets ?? 0, 2) ?></td>
                    </tr>
                </tbody>
            </table>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div class="stmt-doc-title">Savings Statement</div>

            <!-- Member Information -->
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

            <!-- Transaction Table -->
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
                        <td class="text-muted" style="color:#94a3af;">—</td>
                        <td class="text-right text-muted" style="color:#94a3af;">—</td>
                        <td class="text-right text-muted" style="color:#94a3af;">—</td>
                        <td class="text-right amount-balance"><?= number_format($openingBalance, 2) ?></td>
                    </tr>

                    <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="6" class="text-center" style="padding:16px;color:#64748b;font-style:italic;">
                            No transactions during the selected statement period.
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $tx): ?>
                        <tr>
                            <td class="text-center"><?= date('d-M-Y', strtotime($tx['date'])) ?></td>
                            <td><?= htmlspecialchars($tx['description']) ?></td>
                            <td><?= htmlspecialchars($tx['reference']) ?></td>
                            <td class="text-right <?= $tx['debit'] > 0 ? 'amount-debit' : 'text-muted' ?>" style="<?= $tx['debit'] == 0 ? 'color:#94a3af;' : '' ?>">
                                <?= $tx['debit'] > 0 ? number_format($tx['debit'], 2) : '—' ?>
                            </td>
                            <td class="text-right <?= $tx['credit'] > 0 ? 'amount-credit' : 'text-muted' ?>" style="<?= $tx['credit'] == 0 ? 'color:#94a3af;' : '' ?>">
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

            <!-- ── END OF STATEMENT DIVIDER ─────────────────── -->
            <div style="text-align:center;margin:28px 0 22px;display:flex;align-items:center;gap:12px;">
                <div style="flex:1;height:1px;background:#cbd5e1;"></div>
                <span style="font-size:0.75rem;font-weight:700;letter-spacing:0.12em;color:#475569;text-transform:uppercase;white-space:nowrap;">— End of Statement —</span>
                <div style="flex:1;height:1px;background:#cbd5e1;"></div>
            </div>

            <!-- ── SUMMARY TABLE (bank-style) ─────────────────── -->
            <div style="margin-bottom:22px;">
                <div style="font-size:0.72rem;font-weight:800;text-transform:uppercase;letter-spacing:0.1em;color:#1B2B6B;margin-bottom:8px;">Summary</div>
                <div class="table-responsive">
                    <table style="width:100%;border-collapse:collapse;font-size:0.82rem;border:1px solid #cbd5e1;">
                    <thead>
                        <tr>
                            <th style="background:#1B2B6B;color:#fff;padding:10px 14px;text-align:center;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;border:1px solid #142057;width:25%;">Opening Balance</th>
                            <th style="background:#1B2B6B;color:#fff;padding:10px 14px;text-align:center;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;border:1px solid #142057;width:25%;">Total Debits</th>
                            <th style="background:#1B2B6B;color:#fff;padding:10px 14px;text-align:center;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;border:1px solid #142057;width:25%;">Total Credits</th>
                            <th style="background:#1B2B6B;color:#fff;padding:10px 14px;text-align:center;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;border:1px solid #142057;width:25%;">Closing Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="padding:12px 14px;text-align:center;border:1px solid #cbd5e1;font-weight:600;color:#1e293b;font-size:0.9rem;"><?= number_format($openingBalance, 2) ?></td>
                            <td style="padding:12px 14px;text-align:center;border:1px solid #cbd5e1;font-weight:600;color:#dc2626;font-size:0.9rem;"><?= number_format($totalDebits, 2) ?></td>
                            <td style="padding:12px 14px;text-align:center;border:1px solid #cbd5e1;font-weight:600;color:#16a34a;font-size:0.9rem;"><?= number_format($totalCredits, 2) ?></td>
                            <td style="padding:12px 14px;text-align:center;border:1px solid #cbd5e1;font-weight:700;color:#1B2B6B;font-size:0.9rem;"><?= number_format($closingBalance, 2) ?></td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </div>
            <?php endif; // end of the top-level if ($isSharesOnly) / else block ?>

            <!-- ── GENERATED-BY LINE ──────────────────────────── -->
            <div style="font-size:0.7rem;color:#334155;font-style:italic;margin-bottom:0;">
                This statement has been generated from Empower Investment Club Management System.
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

        <div class="stmt-footer-bar">
            <img src="<?= APP_URL ?>/public/images/logo.png" alt="" style="height:20px;opacity:.35;">
            <span style="font-size:.72rem;color:#9ca3af;font-style:italic;letter-spacing:.04em;">Unleash your financial potential</span>
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
