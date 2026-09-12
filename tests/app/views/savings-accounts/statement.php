<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<?php
$typeLabels = ['compulsory' => 'Compulsory Savings', 'voluntary' => 'Voluntary Savings', 'joint' => 'Joint Savings', 'corporate' => 'Corporate Savings', 'fixed_deposit' => 'Fixed Deposit'];
$statusLabels = ['active' => 'Active', 'dormant' => 'Dormant', 'closed' => 'Closed'];
?>
<title>Savings Account Statement — <?= htmlspecialchars($account['account_number']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', sans-serif; font-size: 10pt; color: #1a1a1a; background: #e8eaf0; }
.page-wrap { max-width: 780px; margin: 1.5rem auto; }
.toolbar { display: flex; gap: .6rem; margin-bottom: 1rem; }
.toolbar button, .toolbar a { padding: .45rem 1.2rem; border-radius: 5px; font-size: .9rem; cursor: pointer; text-decoration: none; border: none; }
.btn-print { background: #1B2B6B; color: #fff; }
.btn-close { background: #fff; color: #333; border: 1px solid #ccc; }

.stmt-card { background: #fff; border: 2px solid #1B2B6B; border-radius: 8px; box-shadow: 0 4px 24px rgba(0,0,0,.18); position: relative; overflow: hidden; }
.stmt-watermark { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 360px; height: 360px; object-fit: contain; opacity: 0.04; pointer-events: none; z-index: 0; }
.stmt-header { padding: 22px 28px 16px; border-bottom: 4px solid #B8892B; display: flex; align-items: center; justify-content: space-between; background: #fff; position: relative; z-index: 1; }
.stmt-logo-area { display: flex; align-items: center; gap: 14px; }
.stmt-logo-img { height: 52px; width: 52px; object-fit: contain; }
.stmt-logo-divider { width: 2px; height: 44px; background: #E5E7F0; }
.stmt-brand-title { font-size: 1.3rem; font-weight: 900; color: #1B2B6B; text-transform: uppercase; line-height: 1.1; letter-spacing: 0.02em; }
.stmt-brand-title span { color: #B8892B; }
.stmt-brand-tagline { font-size: 0.68rem; color: #6E7689; font-style: italic; margin-top: 2px; }
.stmt-contact-info { text-align: right; font-size: 0.78rem; color: #6B7280; line-height: 1.7; }
.stmt-contact-info strong { color: #1B2B6B; }

.stmt-body { padding: 20px 24px; position: relative; z-index: 1; }
.stmt-doc-title { text-align: center; background: #1B2B6B; color: #fff; font-size: 0.82rem; font-weight: 800; letter-spacing: .18em; text-transform: uppercase; padding: .55rem 1rem; border-radius: 4px; margin-bottom: 22px; }

.acct-info-box { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 24px; font-size: 0.82rem; margin-bottom: 22px; background: #f8f9fc; padding: 14px 18px; border-radius: 6px; border: 1px solid #E5E7F0; }
.acct-info-row { display: grid; grid-template-columns: 150px 1fr; gap: 0 12px; align-items: baseline; padding-bottom: 4px; border-bottom: 1px dashed #E5E7F0; }
.acct-info-row:last-child { border-bottom: none; padding-bottom: 0; }
.acct-info-label { color: #6E7689; font-weight: 500; white-space: nowrap; }
.acct-info-val { color: #171B2E; font-weight: 600; }

.stmt-section-title { font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; color: #1B2B6B; margin-bottom: 10px; border-bottom: 2px solid #1B2B6B; padding-bottom: 3px; }

.stmt-table { width: 100%; border-collapse: collapse; font-size: 0.72rem; margin-bottom: 24px; border: 1px solid #E5E7F0; }
.stmt-table th { background: #1B2B6B; color: #fff; text-align: center; padding: 6px 4px; font-size: 0.6rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; border: 1px solid #E5E7F0; }
.stmt-table th.right { text-align: right; }
.stmt-table th.left { text-align: left; }
.stmt-table td { padding: 5px 4px; border: 1px solid #E5E7F0; vertical-align: middle; }
.stmt-table tr:nth-child(even) td { background: #f8f9fc; }
.stmt-table .row-closing { background: #e0f2fe !important; font-weight: 700; }
.text-right { text-align: right; }
.text-center { text-align: center; }
.amount-credit { color: #16a34a; font-weight: 600; }
.amount-debit { color: #dc2626; font-weight: 600; }
.amount-balance { color: #1e3a8a; font-weight: 700; }

.stmt-footer-note { text-align: center; margin-top: 24px; padding-top: 14px; border-top: 1px dashed #E5E7F0; font-size: 0.75rem; color: #6B7280; line-height: 1.6; }
.stmt-footer-note p.disclaimer { font-style: italic; margin-bottom: 3px; }
.stmt-footer-note p.end-mark { font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; color: #1B2B6B; margin-top: 4px; }
.stmt-footer-bar { padding: 12px 28px; border-top: 3px solid #B8892B; display: flex; align-items: center; justify-content: center; gap: 8px; background: #fff; }

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

<div class="page-wrap">
    <div class="toolbar no-print" style="flex-wrap:wrap;align-items:center;">
        <button class="btn-print" onclick="window.print()">🖨 Print / Save PDF</button>
        <button class="btn-close" onclick="window.close()">✕ Close</button>
        <form method="GET" style="display:flex;gap:.4rem;align-items:center;margin-left:auto;">
            <input type="hidden" name="page" value="savings-account-statement">
            <input type="hidden" name="id" value="<?= (int)$account['id'] ?>">
            <label style="font-size:.8rem;color:#6B7280;">From
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" style="padding:.3rem .5rem;border:1px solid #E5E7F0;border-radius:5px;font-size:.82rem;">
            </label>
            <label style="font-size:.8rem;color:#6B7280;">To
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" style="padding:.3rem .5rem;border:1px solid #E5E7F0;border-radius:5px;font-size:.82rem;">
            </label>
            <button type="submit" style="background:#1B2B6B;color:#fff;border:none;padding:.45rem 1rem;border-radius:5px;font-size:.85rem;cursor:pointer;">Apply</button>
        </form>
    </div>

    <div class="stmt-card">
        <img class="stmt-watermark" src="<?= APP_URL ?>/public/images/logo.png" alt="">

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
            <div class="stmt-doc-title">Savings Account Statement</div>

            <div class="acct-info-box">
                <div class="acct-info-row">
                    <span class="acct-info-label">Account Holder:</span>
                    <span class="acct-info-val"><?= htmlspecialchars($holderLabel) ?></span>
                </div>
                <div class="acct-info-row">
                    <span class="acct-info-label">Account Type:</span>
                    <span class="acct-info-val"><?= htmlspecialchars($typeLabels[$account['account_type']] ?? $account['account_type']) ?></span>
                </div>
                <div class="acct-info-row">
                    <span class="acct-info-label">Account Number:</span>
                    <span class="acct-info-val"><?= htmlspecialchars($account['account_number']) ?></span>
                </div>
                <div class="acct-info-row">
                    <span class="acct-info-label">Status:</span>
                    <span class="acct-info-val"><?= htmlspecialchars($statusLabels[$account['status']] ?? $account['status']) ?></span>
                </div>
                <div class="acct-info-row">
                    <span class="acct-info-label">Statement Period:</span>
                    <span class="acct-info-val"><?= date('d M Y', strtotime($dateFrom)) ?> &ndash; <?= date('d M Y', strtotime($dateTo)) ?></span>
                </div>
                <div class="acct-info-row">
                    <span class="acct-info-label">Opened:</span>
                    <span class="acct-info-val"><?= date('d M Y', strtotime($account['opened_date'])) ?></span>
                </div>
                <div class="acct-info-row">
                    <span class="acct-info-label">Date Generated:</span>
                    <span class="acct-info-val"><?= date('d M Y') ?></span>
                </div>
            </div>

            <div class="stmt-section-title">Transaction History</div>

            <div class="table-responsive">
                <table class="stmt-table">
                <thead>
                    <tr>
                        <th class="left" style="width:10%;">Date</th>
                        <th class="left" style="width:13%;">Receipt</th>
                        <th class="left" style="width:12%;">Cash Ref.</th>
                        <th class="left" style="width:11%;">Type</th>
                        <th class="left" style="width:18%;">Description</th>
                        <th class="right" style="width:12%;">Debit</th>
                        <th class="right" style="width:12%;">Credit</th>
                        <th class="right" style="width:12%;">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td colspan="7" style="font-style:italic;color:#6B7280;">Opening Balance</td>
                        <td class="text-right amount-balance">Shs <?= number_format($openingBalance, 2) ?></td>
                    </tr>
                    <?php if (empty($transactions)): ?>
                        <tr><td colspan="8" class="text-center" style="padding:14px;color:#6E7689;font-style:italic;">No transactions in this period.</td></tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $t): ?>
                            <tr>
                                <td><?= date('d M Y', strtotime($t['transaction_date'])) ?></td>
                                <td><?= htmlspecialchars($t['receipt_number'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($t['cash_reference_number'] ?? '—') ?></td>
                                <td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $t['transaction_type']))) ?></td>
                                <td><?= htmlspecialchars($t['description'] ?? '') ?><?php if ($t['transaction_type'] === 'opening_balance' && !empty($t['notes'])): ?><br><span style="font-size:.62rem;color:#6E7689;font-style:italic;"><?= htmlspecialchars($t['notes']) ?></span><?php endif; ?></td>
                                <td class="text-right amount-debit"><?= (float)$t['debit'] > 0 ? number_format((float)$t['debit'], 2) : '—' ?></td>
                                <td class="text-right amount-credit"><?= (float)$t['credit'] > 0 ? number_format((float)$t['credit'], 2) : '—' ?></td>
                                <td class="text-right amount-balance"><?= number_format((float)($t['running_balance'] ?? 0), 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <tr class="row-closing">
                        <td colspan="7">Closing Balance</td>
                        <td class="text-right amount-balance">Shs <?= number_format($closingBalance, 2) ?></td>
                    </tr>
                </tbody>
            </table>
            </div>

            <!-- ── END OF STATEMENT DIVIDER ─────────────────── -->
            <div style="text-align:center;margin:24px 0 20px;display:flex;align-items:center;gap:12px;">
                <div style="flex:1;height:1px;background:#E5E7F0;"></div>
                <span style="font-size:0.72rem;font-weight:700;letter-spacing:0.12em;color:#6B7280;text-transform:uppercase;white-space:nowrap;">— End of Statement —</span>
                <div style="flex:1;height:1px;background:#E5E7F0;"></div>
            </div>

            <!-- ── SUMMARY TABLE ─────────────────────────────── -->
            <div style="margin-bottom:20px;">
                <div style="font-size:0.72rem;font-weight:800;text-transform:uppercase;letter-spacing:0.1em;color:#1B2B6B;margin-bottom:8px;">Summary</div>
                <div class="table-responsive">
                    <table style="width:100%;border-collapse:collapse;font-size:0.82rem;border:1px solid #E5E7F0;">
                    <thead>
                        <tr>
                            <th style="background:#1B2B6B;color:#fff;padding:10px 14px;text-align:center;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;border:1px solid #142057;width:25%;">Opening Balance</th>
                            <th style="background:#1B2B6B;color:#fff;padding:10px 14px;text-align:center;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;border:1px solid #142057;width:25%;">Total Debits</th>
                            <th style="background:#1B2B6B;color:#fff;padding:10px 14px;text-align:center;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;border:1px solid #142057;width:25%;">Total Credits</th>
                            <th style="background:#1B2B6B;color:#fff;padding:10px 14px;text-align:center;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;border:1px solid #142057;width:25%;">Closing Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="padding:12px 14px;text-align:center;border:1px solid #E5E7F0;font-weight:600;color:#171B2E;font-size:0.88rem;"><?= number_format($openingBalance, 2) ?></td>
                            <td style="padding:12px 14px;text-align:center;border:1px solid #E5E7F0;font-weight:600;color:#dc2626;font-size:0.88rem;"><?= number_format($totalDebits, 2) ?></td>
                            <td style="padding:12px 14px;text-align:center;border:1px solid #E5E7F0;font-weight:600;color:#16a34a;font-size:0.88rem;"><?= number_format($totalCredits, 2) ?></td>
                            <td style="padding:12px 14px;text-align:center;border:1px solid #E5E7F0;font-weight:700;color:#1B2B6B;font-size:0.88rem;"><?= number_format($closingBalance, 2) ?></td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </div>

            <!-- ── GENERATED-BY LINE ──────────────────────────── -->
            <div style="font-size:0.7rem;color:#6B7280;font-style:italic;margin-bottom:0;">
                This statement has been generated from Empower Investment Club Management System.
            </div>
        </div><!-- /.stmt-body -->

        <!-- ── IMPORTANT NOTICE — outside stmt-body, bleeds full width ── -->
        <div style="background:#1B2B6B;color:#fff;font-size:0.72rem;padding:10px 24px;line-height:1.7;">
            <strong>IMPORTANT NOTICE:</strong> Please examine your statement carefully.
            If we do not hear from you within 28 days, we shall assume the details shown on your Account Statement are correct.
            If, however, you have any query about any transaction on your Account Statement, please contact
            Empower Investment Club at <strong>empowerclub2024@gmail.com</strong>
            or call <strong>0702970129 / 0701486161</strong>.
        </div>

        <div class="stmt-footer-bar">
            <img src="<?= APP_URL ?>/public/images/logo.png" alt="" style="height:20px;opacity:.35;">
            <span style="font-size:.72rem;color:#6E7689;font-style:italic;letter-spacing:.04em;">Unleash your financial potential</span>
        </div>
    </div>
</div>

<script>
if (new URLSearchParams(window.location.search).get('auto') === '1') {
    window.addEventListener('load', function() { window.print(); });
}
</script>
</body>
</html>
