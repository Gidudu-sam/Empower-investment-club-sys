<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<title>Repayment Card</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 10pt;
    background: #c8cdd8;
    color: #111;
}

/* ── Toolbar ── */
.toolbar {
    max-width: 800px;
    margin: 10px auto 8px;
    display: flex;
    gap: 6px;
}
.toolbar button, .toolbar a {
    padding: 7px 18px;
    border-radius: 4px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    border: none;
}
.btn-print { background: #1B2B6B; color: #fff; }
.btn-back  { background: #fff; border: 1px solid #999; color: #333; }
.btn-close { background: #fff; border: 1px solid #999; color: #333; }

/* ── Card wrapper ── */
.card {
    width: 800px;
    margin: 0 auto 30px;
    background: #fff;
    box-shadow: 0 4px 24px rgba(0,0,0,0.2);
}

/* ════ HEADER ════ */
.hdr {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 22px 12px;
    background: #fff;
}
.hdr-logo {
    width: 90px;
    height: 90px;
    object-fit: contain;
    flex-shrink: 0;
}
.hdr-center {
    flex: 1;
    text-align: center;
    padding: 0 18px;
}
.club-name {
    font-size: 32px;
    font-weight: 900;
    color: #1B2B6B;
    text-transform: uppercase;
    line-height: 1;
    text-align: center;
    letter-spacing: 0.03em;
}
.club-name span { color: #1B2B6B; }
.contact-info {
    font-size: 11.5px;
    color: #111;
    line-height: 1.85;
    margin-top: 6px;
    text-align: center;
}
.stamp-box {
    width: 90px;
    height: 85px;
    border: 1.5px solid #bbb;
    border-radius: 5px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    font-size: 10px;
    color: #bbb;
    line-height: 1.6;
}

/* orange bar */
.orange-bar { height: 5px; background: #F47920; }

/* ════ TITLE ════ */
.doc-title {
    text-align: center;
    padding: 10px 0 8px;
    border-bottom: 3px solid #F47920;
    background: #fff;
}
.doc-title-text {
    font-size: 17px;
    font-weight: 900;
    color: #1B2B6B;
    text-transform: uppercase;
    letter-spacing: 0.1em;
}

/* ════ BODY ════ */
.body-wrap {
    padding: 14px 18px 16px;
    background: #fff;
}

/* ── Section box (bordered) ── */
.section-box {
    border: 1.5px solid #1B2B6B;
    border-radius: 4px;
    padding: 14px 16px;
    margin-bottom: 12px;
}

/* ── ID rows (section 1) ── */
.id-row {
    display: flex;
    align-items: center;
    margin-bottom: 13px;
}
.id-row:last-child { margin-bottom: 0; }

.id-label {
    font-size: 11px;
    font-weight: 700;
    color: #1B2B6B;
    width: 140px;
    flex-shrink: 0;
}

/* digit boxes */
.digit-group { display: flex; gap: 3px; }
.digit-cell {
    width: 22px;
    height: 24px;
    border: 1.5px solid #444;
    border-radius: 2px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    font-weight: 700;
    color: #1B2B6B;
}

/* underline field -- the box is a flex container bottom-aligning its
   value span against the border, and the value span uses line-height:1
   so font metrics never push text above/off the line (fixes the
   card-wide alignment issue). */
.underline {
    border-bottom: 1.5px solid #444;
    flex: 1;
    min-height: 22px;
    display: flex;
    align-items: flex-end;
    padding-bottom: 2px;
}
.underline .lv { font-size: 11px; line-height: 1; color: #111; }

/* date on right of row 1 */
.date-group {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 11px;
    font-weight: 700;
    color: #1B2B6B;
}
.date-line {
    width: 165px;
    border-bottom: 1.5px solid #444;
    min-height: 22px;
    display: flex;
    align-items: flex-end;
    padding-bottom: 2px;
}
.date-line .lv { font-size: 11px; line-height: 1; color: #111; }

/* ════ SECTION 2 — Details + Guarantor ════ */
.two-col {
    display: table;
    width: 100%;
    border: 1.5px solid #1B2B6B;
    border-radius: 4px;
    margin-bottom: 12px;
    border-collapse: separate;
    border-spacing: 0;
    overflow: hidden;
}
.two-col-left {
    display: table-cell;
    width: 50%;
    padding: 12px 14px;
    border-right: 1.5px solid #1B2B6B;
    vertical-align: top;
}
.two-col-right {
    display: table-cell;
    width: 50%;
    vertical-align: top;
}

/* loan detail field */
.detail-row {
    display: flex;
    align-items: flex-end;
    gap: 6px;
    margin-bottom: 11px;
    font-size: 10.5px;
}
.detail-row:last-child { margin-bottom: 0; }
.detail-label {
    font-weight: 700;
    color: #1B2B6B;
    white-space: nowrap;
    flex-shrink: 0;
}
.detail-line {
    flex: 1;
    border-bottom: 1.5px solid #555;
    min-height: 19px;
    display: flex;
    align-items: flex-end;
    padding-bottom: 1px;
}
.detail-line .lv { color: #111; font-size: 10.5px; line-height: 1; }

/* guarantor pane */
.guar-header {
    background: #1B2B6B;
    color: #fff;
    text-align: center;
    padding: 8px 10px;
    font-size: 10px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.09em;
}
.guar-fields { padding: 12px 14px; }
.guar-row {
    display: flex;
    align-items: flex-end;
    gap: 6px;
    margin-bottom: 11px;
    font-size: 10.5px;
}
.guar-row:last-child { margin-bottom: 0; }
.guar-label {
    font-weight: 700;
    color: #1B2B6B;
    white-space: nowrap;
    flex-shrink: 0;
}
.guar-line {
    flex: 1;
    border-bottom: 1.5px solid #555;
    min-height: 19px;
    display: flex;
    align-items: flex-end;
    padding-bottom: 1px;
}
.guar-line .lv { font-size: 10.5px; line-height: 1; color: #111; }
.guar-name-row {
    flex-direction: column;
    align-items: flex-start;
    gap: 3px;
}
.guar-name-row .guar-line { width: 100%; }
.guar-section-title {
    font-size: 9.5px;
    font-weight: 900;
    color: #1B2B6B;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin-bottom: 6px;
}
.guar-section + .guar-section { margin-top: 14px; }

/* ════ REPAYMENT TABLE ════ */
.repay-wrap {
    border: 1.5px solid #1B2B6B;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 12px;
}

.repay-section-headers {
    display: flex;
}
.rsh-left {
    background: #1B2B6B;
    color: #fff;
    text-align: center;
    padding: 8px 6px;
    font-size: 11.5px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    width: 38%;
    border-right: 3px solid #fff;
}
.rsh-right {
    background: #F47920;
    color: #fff;
    text-align: center;
    padding: 8px 6px;
    font-size: 11.5px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    flex: 1;
}

/* repayment table itself */
.repay-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}

.repay-table thead th {
    background: #f0f4fb;
    color: #222;
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    text-align: center;
    padding: 5px 3px;
    border-bottom: 1.5px solid #b5c3db;
    border-right: 1px solid #b5c3db;
}
.repay-table thead th:last-child { border-right: none; }
.th-divider { border-right: 2.5px solid #1B2B6B !important; }

.repay-table tbody td {
    height: 27px;
    padding: 3px 4px;
    text-align: center;
    vertical-align: middle;
    border-bottom: 1px solid #d8e0ef;
    border-right: 1px solid #d8e0ef;
    font-size: 10px;
}
.repay-table tbody td:last-child { border-right: none; }
.repay-table tbody tr:nth-child(even) td { background: #f8f9ff; }
.td-divider { border-right: 2.5px solid #1B2B6B !important; }

.repay-table tfoot td {
    padding: 6px 4px;
    background: #edf0f8;
    border-top: 2px solid #1B2B6B;
    border-right: 1px solid #b5c3db;
    text-align: center;
    font-size: 11px;
    font-weight: 800;
}
.repay-table tfoot td:last-child { border-right: none; }
.total-navy   { color: #1B2B6B; }
.total-orange { color: #F47920; background: #fff8f2 !important; }

/* ════ NOTES ════ */
.notes-box {
    border: 1.5px solid #1B2B6B;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 12px;
}
.notes-label {
    display: inline-block;
    background: #1B2B6B;
    color: #fff;
    padding: 4px 12px;
    font-size: 9.5px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}
.notes-list {
    padding: 8px 16px 10px;
    list-style: none;
    font-size: 10px;
    color: #222;
}
.notes-list li {
    padding: 2px 0 2px 14px;
    position: relative;
}
.notes-list li::before { content: '-'; position: absolute; left: 0; }

/* ════ FOOTER ════ */
.card-footer {
    border-top: 5px solid #F47920;
    padding: 9px 20px;
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}
.footer-logo { height: 20px; opacity: 0.4; }
.footer-text {
    font-size: 12px;
    font-weight: 900;
    color: #1B2B6B;
    text-transform: uppercase;
    letter-spacing: 0.1em;
}
.footer-text span { color: #F47920; }

/* ════ PRINT ════ */
@media print {
    @page { size: A4 portrait; margin: 6mm 7mm; }
    body { background: #fff; }
    .toolbar { display: none !important; }
    .card { width: 100%; margin: 0; box-shadow: none; }
    tr { page-break-inside: avoid; }
}
</style>
</head>
<body>

<?php
$fullName  = trim(($loan['first_name'] ?? '') . ' ' . ($loan['last_name'] ?? ''));
$memberNum = $loan['member_number'] ?? '';
$loanNum   = $loan['loan_number']   ?? '';

// Build digit cells from a string value
function makeDigits(string $val, int $count = 9): string {
    $val   = strtoupper(preg_replace('/\s+/', '', $val));
    $html  = '';
    for ($i = 0; $i < $count; $i++) {
        $ch = ($i < strlen($val)) ? htmlspecialchars($val[$i]) : '&nbsp;';
        $html .= '<div class="digit-cell">' . $ch . '</div>';
    }
    return $html;
}

$firstDue = (!empty($installments)) ? htmlspecialchars($installments[0]['due_date']) : '&nbsp;';
$lastDue  = (!empty($installments)) ? htmlspecialchars(end($installments)['due_date']) : '&nbsp;';
$numInst  = count($installments);
$rowCount = max(12, $numInst);

$tPrin = 0; $tInt = 0; $tAmt = 0;
foreach ($installments as $r) {
    $a = (float)($r['amount_due']    ?? 0);
    $i = (float)($r['interest_due']  ?? 0);
    $p = (float)($r['principal_due'] ?? ($a - $i));
    $tPrin += $p; $tInt += $i; $tAmt += $a;
}

// Actual repayment totals
$rTotalPrin    = 0; $rTotalInt = 0; $rTotalPenalty = 0; $rTotalPaid = 0;
foreach (($repayments ?? []) as $r) {
    $rTotalPrin    += (float)($r['principal_paid'] ?? 0);
    $rTotalInt     += (float)($r['interest_paid']  ?? 0);
    $rTotalPenalty += (float)($r['penalty_paid']   ?? 0);
    $rTotalPaid    += (float)($r['amount_paid']    ?? 0);
}
?>

<div class="toolbar no-print">
    <button class="btn-print" onclick="window.print()">🖨 Print / Save PDF</button>
    <a class="btn-back" href="<?= APP_URL ?>/index.php?page=loan-view&id=<?= (int)($loan['id'] ?? 0) ?>">← Back to Loan</a>
    <button class="btn-close" onclick="window.close()">✕ Close</button>
</div>

<div class="card">

    <!-- ══ HEADER ══ -->
    <div class="hdr">
        <img class="hdr-logo" src="<?= APP_URL ?>/public/images/logo.png" alt="Logo">
        <div class="hdr-center">
            <div class="club-name">EMPOWER <span>INVESTMENT CLUB</span></div>
            <div class="contact-info">
                GAYAZA, GITTA, WAKISO | TEL: 0702970129 / 0701486161<br>
                EMAIL: empowerclub2024@gmail.com<br>
                REG. NO. WCBO/24/24/4290
            </div>
        </div>
        <div class="stamp-box">Official<br>Stamp<br>Here</div>
    </div>

    <!-- ══ TITLE ══ -->
    <div class="doc-title">
        <span class="doc-title-text">LOAN SCHEDULE AND REPAYMENT CARD</span>
    </div>

    <!-- ══ BODY ══ -->
    <div class="body-wrap">

        <!-- Section 1: IDs -->
        <div class="section-box">

            <!-- Row 1: Member Number + Date -->
            <div class="id-row">
                <span class="id-label">Member Number:</span>
                <div class="digit-group"><?= makeDigits($memberNum, 9) ?></div>
                <div class="date-group">
                    <span>Date:</span>
                    <div class="date-line"><span class="lv"><?= !empty($loan['issue_date']) ? htmlspecialchars(date('d/m/Y', strtotime($loan['issue_date']))) : '&nbsp;' ?></span></div>
                </div>
            </div>

            <!-- Row 2: Loan Number -->
            <div class="id-row">
                <span class="id-label">Loan Number:</span>
                <div class="digit-group"><?= makeDigits($loanNum, 9) ?></div>
            </div>

            <!-- Row 3: Name of Borrower -->
            <div class="id-row">
                <span class="id-label">Name of Borrower:</span>
                <div class="underline"><span class="lv"><?= htmlspecialchars($fullName) ?: '&nbsp;' ?></span></div>
            </div>

        </div>

        <!-- Section 2: Loan Details + Guarantor (table-based for reliability) -->
        <div class="two-col">
            <div class="two-col-left">
                <div class="detail-row">
                    <span class="detail-label">Purpose of the Loan:</span>
                    <div class="detail-line"><span class="lv"><?= htmlspecialchars($loan['purpose'] ?? '') ?: '&nbsp;' ?></span></div>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Amount Taken:</span>
                    <div class="detail-line"><span class="lv">UGX <?= number_format((float)($loan['loan_amount'] ?? 0), 2) ?></span></div>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Period:</span>
                    <div class="detail-line"><span class="lv"><?= htmlspecialchars($loan['loan_period'] ?? '') ?: '&nbsp;' ?></span></div>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Interest Rate:</span>
                    <div class="detail-line"><span class="lv"><?= number_format((float)($loan['interest_rate'] ?? 0), 2) ?>% per month</span></div>
                </div>
                <div class="detail-row">
                    <span class="detail-label">No. of Installments:</span>
                    <div class="detail-line"><span class="lv"><?= $numInst ?: '&nbsp;' ?></span></div>
                </div>
                <div class="detail-row">
                    <span class="detail-label">First Installment:</span>
                    <div class="detail-line"><span class="lv"><?= $firstDue ?></span></div>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Last Installment:</span>
                    <div class="detail-line"><span class="lv"><?= $lastDue ?></span></div>
                </div>
            </div>
            <div class="two-col-right">
                <div class="guar-header">Guarantor / Emergency Contact Details</div>
                <div class="guar-fields">
                    <div class="guar-section">
                        <div class="guar-section-title">Guarantor</div>
                        <div class="guar-row guar-name-row">
                            <span class="guar-label">Name:</span>
                            <div class="guar-line"><span class="lv"><?= htmlspecialchars($loan['guarantor_name'] ?? '') ?: '&nbsp;' ?></span></div>
                        </div>
                        <div class="guar-row guar-name-row">
                            <span class="guar-label">Contact:</span>
                            <div class="guar-line"><span class="lv"><?= htmlspecialchars($loan['guarantor_contact'] ?? '') ?: '&nbsp;' ?></span></div>
                        </div>
                    </div>
                    <div class="guar-section">
                        <div class="guar-section-title" style="color:#F47920;">Emergency Contact</div>
                        <div class="guar-row guar-name-row">
                            <span class="guar-label">Name:</span>
                            <div class="guar-line"><span class="lv"><?= htmlspecialchars($loan['emergency_contact_name'] ?? '') ?: '&nbsp;' ?></span></div>
                        </div>
                        <div class="guar-row guar-name-row">
                            <span class="guar-label">Contact:</span>
                            <div class="guar-line"><span class="lv"><?= htmlspecialchars($loan['emergency_contact_phone'] ?? '') ?: '&nbsp;' ?></span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 3: Repayment Table -->
        <div class="repay-wrap">
            <div class="repay-section-headers">
                <div class="rsh-left">Repayment Installments</div>
                <div class="rsh-right">Actual Repayment</div>
            </div>
            <div class="table-responsive">
                <table class="repay-table">
                <colgroup>
                    <col style="width:10%"><!-- Date Due -->
                    <col style="width:8%"> <!-- Principal -->
                    <col style="width:8%"> <!-- Interest -->
                    <col style="width:8%"> <!-- Total (left) -->
                    <col style="width:10%"><!-- Date Paid -->
                    <col style="width:12%"><!-- Reference -->
                    <col style="width:9%"> <!-- Principal -->
                    <col style="width:8%"> <!-- Interest -->
                    <col style="width:8%"> <!-- Penalty -->
                    <col style="width:9%"> <!-- Total (right) -->
                </colgroup>
                <thead>
                    <tr>
                        <th>Date Due</th>
                        <th>Principal</th>
                        <th>Interest</th>
                        <th class="th-divider">Total</th>
                        <th>Date Paid</th>
                        <th>Reference</th>
                        <th>Principal</th>
                        <th>Interest</th>
                        <th>Penalty</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                // Index repayments by installment number (1-based) using paid_date match
                // Each installment row shows its own actual payment on the right side
                $repaymentsList = array_values($repayments ?? []);

                for ($i = 0; $i < $rowCount; $i++):
                    // ── SCHEDULED SIDE ──────────────────────────────────
                    $inst  = $installments[$i] ?? null;
                    $sAmt  = $inst ? (float)($inst['amount_due']    ?? 0) : 0;
                    $sInt  = $inst ? (float)($inst['interest_due']  ?? 0) : 0;
                    $sPrin = $inst ? (float)($inst['principal_due'] ?? ($sAmt - $sInt)) : 0;

                    // ── ACTUAL SIDE ─────────────────────────────────────
                    // Strategy: pair repayment[i] with installment[i] directly
                    // (repayments are ordered oldest-first, same as installments)
                    $rep      = $repaymentsList[$i] ?? null;
                    $rDate    = $rep ? htmlspecialchars($rep['payment_date'] ?? '') : '';
                    $rPrin    = $rep ? number_format((float)($rep['principal_paid'] ?? 0), 0) : '';
                    $rInt     = $rep ? number_format((float)($rep['interest_paid']  ?? 0), 0) : '';
                    $rPenalty = $rep ? number_format((float)($rep['penalty_paid']   ?? 0), 0) : '';
                    $rRef     = $rep ? htmlspecialchars($rep['reference_number'] ?? '') : '';
                    $rTotal   = $rep ? number_format((float)($rep['amount_paid']    ?? 0), 0) : '';

                    // Row background based on installment status
                    $rowBg = '';
                    if ($inst) {
                        $rowBg = match($inst['status'] ?? 'pending') {
                            'paid'    => 'background:#f0fdf4;',
                            'overdue' => 'background:#fff5f5;',
                            'partial' => 'background:#fffbeb;',
                            default   => '',
                        };
                    }
                ?>
                    <tr style="<?= $rowBg ?>">
                        <td><?= $inst ? htmlspecialchars($inst['due_date']) : '&nbsp;' ?></td>
                        <td><?= $inst ? number_format($sPrin, 0) : '&nbsp;' ?></td>
                        <td><?= $inst ? number_format($sInt,  0) : '&nbsp;' ?></td>
                        <td class="td-divider"><?= $inst ? number_format($sAmt, 0) : '&nbsp;' ?></td>
                        <td><?= $rDate    !== '' ? $rDate    : '&nbsp;' ?></td>
                        <td><?= $rRef     !== '' ? $rRef     : '&nbsp;' ?></td>
                        <td><?= $rPrin    !== '' ? $rPrin    : '&nbsp;' ?></td>
                        <td><?= $rInt     !== '' ? $rInt     : '&nbsp;' ?></td>
                        <td><?= $rPenalty !== '' ? $rPenalty : '&nbsp;' ?></td>
                        <td><?= $rTotal   !== '' ? $rTotal   : '&nbsp;' ?></td>
                    </tr>
                <?php endfor; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td>&nbsp;</td>
                        <td class="total-navy" style="font-weight:800;">TOTAL</td>
                        <td><?= $tInt > 0 ? number_format($tInt, 0) : '&nbsp;' ?></td>
                        <td class="td-divider"><?= $tAmt > 0 ? number_format($tAmt, 0) : '&nbsp;' ?></td>
                        <td class="total-orange" style="font-weight:800;">TOTAL</td>
                        <td>&nbsp;</td>
                        <td><?= $rTotalPrin    > 0 ? number_format($rTotalPrin,    0) : '&nbsp;' ?></td>
                        <td><?= $rTotalInt     > 0 ? number_format($rTotalInt,     0) : '&nbsp;' ?></td>
                        <td><?= $rTotalPenalty > 0 ? number_format($rTotalPenalty, 0) : '&nbsp;' ?></td>
                        <td><?= $rTotalPaid    > 0 ? number_format($rTotalPaid,    0) : '&nbsp;' ?></td>
                    </tr>
                </tfoot>
            </table>
            </div>
        </div>

        <!-- Notes / Terms -->
        <div class="notes-box">
            <div><span class="notes-label">Notes / Terms</span></div>
            <ul class="notes-list">
                <li>I/We agree to repay the above loan in the stated installment amounts and on the due dates.</li>
                <li>Late payment may attract penalties as per the club's loan policy.</li>
                <li>I/We have read and understood the loan terms and conditions.</li>
            </ul>
        </div>

    </div><!-- /.body-wrap -->

    <!-- ══ FOOTER ══ -->
    <div class="card-footer">
        <img class="footer-logo" src="<?= APP_URL ?>/public/images/logo.png" alt="">
        <span class="footer-text">Unleash Your <span>Financial</span> Potential</span>
    </div>

</div><!-- /.card -->

<script>
if (new URLSearchParams(window.location.search).get('auto') === '1') {
    window.addEventListener('load', () => window.print());
}
</script>
</body>
</html>
