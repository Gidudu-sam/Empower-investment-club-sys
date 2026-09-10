<?php
$base       = APP_URL . '/index.php';
$months     = ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$typeLabels = ['daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly','annual'=>'Annual'];
$typeLabel  = $typeLabels[$type] ?? ucfirst($type);
$periodLabel = match($type) {
    'daily'   => date('d F Y', strtotime($dateFrom)),
    'weekly'  => date('d M',strtotime($dateFrom)).' – '.date('d M Y',strtotime($dateTo)),
    'monthly' => $months[(int)$month].' '.$year,
    'annual'  => 'Year '.$year,
    default   => ($dateFrom && $dateTo) ? date('d M',strtotime($dateFrom)).' – '.date('d M Y',strtotime($dateTo)) : 'All Time',
};
function repBadge(string $m): string {
    return match($m){
        'Cash'=>'bg-success-subtle text-success','Airtel Money'=>'bg-danger-subtle text-danger','MTN Mobile Money'=>'bg-warning-subtle text-warning',
        'Bank Transfer'=>'bg-info-subtle text-info','Cheque'=>'bg-warning-subtle text-warning',
        default=>'bg-secondary-subtle text-secondary'
    };
}
// Group by payment method
$methodTotals = [];
foreach($savings as $s){
    $methodTotals[$s['payment_method']] = ($methodTotals[$s['payment_method']] ?? 0) + $s['amount'];
}
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-file-bar-graph-fill me-2 text-primary"></i>Savings Report
        </h1>
        <p class="text-muted mb-0 small"><?= $typeLabel ?> Report · <?= $periodLabel ?></p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm no-print">
            <i class="bi bi-printer me-1"></i>Print
        </button>
        <a href="<?= $base ?>?page=savings" class="btn btn-outline-secondary btn-sm no-print">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<!-- Report type selector -->
<div class="card mb-4 no-print">
    <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="bi bi-funnel me-2"></i>Report Parameters</h6></div>
    <div class="card-body p-4">
        <form method="GET" action="<?= $base ?>" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="savings-report">
            <div class="col-md-3">
                <label class="form-label fw-semibold">Report Type</label>
                <select name="type" class="form-select" onchange="this.form.submit()">
                    <?php foreach($typeLabels as $k=>$l): ?>
                    <option value="<?=$k?>" <?=$type===$k?'selected':''?>><?=$l?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if(in_array($type,['monthly','annual'])): ?>
            <div class="col-md-2">
                <label class="form-label fw-semibold">Year</label>
                <select name="year" class="form-select">
                    <?php
                    $yl = $years;
                    if(!in_array((int)date('Y'),array_map('intval',$yl))) $yl[]=(int)date('Y');
                    foreach($yl as $y): ?>
                    <option value="<?=$y?>" <?=(string)$year===(string)$y?'selected':''?>><?=$y?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if($type==='monthly'): ?>
            <div class="col-md-2">
                <label class="form-label fw-semibold">Month</label>
                <select name="month" class="form-select">
                    <?php for($mo=1;$mo<=12;$mo++): ?>
                    <option value="<?=$mo?>" <?=(int)$month===$mo?'selected':''?>><?=date('F',mktime(0,0,0,$mo,1))?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <?php if($type==='daily'): ?>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Date</label>
                <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <?php endif; ?>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-arrow-clockwise me-1"></i>Generate
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Summary stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-sm-4">
        <div class="stat-card stat-card-success">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-success"><i class="bi bi-cash-stack"></i></div>
                <div>
                    <div class="stat-label">Total Amount</div>
                    <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($totalAmount,2) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-sm-4">
        <div class="stat-card stat-card-primary">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-primary"><i class="bi bi-hash"></i></div>
                <div>
                    <div class="stat-label">Transactions</div>
                    <div class="stat-value"><?= count($savings) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-4">
        <div class="stat-card stat-card-info">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-info"><i class="bi bi-calculator"></i></div>
                <div>
                    <div class="stat-label">Average Deposit</div>
                    <div class="stat-value" style="font-size:1.1rem">
                        Shs <?= count($savings) > 0 ? number_format($totalAmount/count($savings),2) : '0.00' ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Method breakdown -->
<?php if($methodTotals): ?>
<div class="row g-3 mb-4">
    <?php foreach($methodTotals as $pm=>$amt): ?>
    <div class="col-auto">
        <div class="card px-3 py-2 d-flex flex-row align-items-center gap-2">
            <span class="badge <?= repBadge($pm) ?> rounded-pill px-2"><?= htmlspecialchars($pm) ?></span>
            <span class="fw-semibold small">Shs <?= number_format($amt,2) ?></span>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Report table -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">
            <?= $typeLabel ?> Savings Report &mdash; <?= $periodLabel ?>
        </h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">#</th>
                    <th>Receipt No.</th>
                    <th>Member</th>
                    <th class="text-end">Amount (Shs)</th>
                    <th>Method</th>
                    <th>Date</th>
                    <th class="d-none d-md-table-cell">Reference</th>
                    <th class="no-print">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($savings)): ?>
                <tr><td colspan="8" class="text-center py-4 text-muted">No savings records for this period.</td></tr>
                <?php else: foreach($savings as $i=>$s): ?>
                <tr>
                    <td class="ps-3 text-muted small"><?= $i+1 ?></td>
                    <td class="fw-semibold text-success small"><?= htmlspecialchars($s['receipt_number']) ?></td>
                    <td>
                        <div class="fw-semibold small"><?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?></div>
                        <div class="text-muted" style="font-size:.72rem"><?= htmlspecialchars($s['member_number']) ?></div>
                    </td>
                    <td class="text-end fw-bold text-success"><?= number_format($s['amount'],2) ?></td>
                    <td><span class="badge <?= repBadge($s['payment_method']) ?> rounded-pill px-2 small"><?= htmlspecialchars($s['payment_method']) ?></span></td>
                    <td class="text-muted small"><?= date('d M Y',strtotime($s['transaction_date'])) ?></td>
                    <td class="d-none d-md-table-cell text-muted small"><?= $s['reference_number'] ? htmlspecialchars($s['reference_number']) : '—' ?></td>
                    <td class="no-print">
                        <a href="<?= $base ?>?page=savings-receipt&id=<?= $s['id'] ?>"
                           class="btn btn-sm btn-outline-secondary" target="_blank" title="Print Receipt">
                            <i class="bi bi-printer"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <?php if(!empty($savings)): ?>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td class="ps-3" colspan="3">TOTAL</td>
                    <td class="text-end text-success">Shs <?= number_format($totalAmount,2) ?></td>
                    <td colspan="4"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<style>
@media print {
    .no-print { display:none !important; }
    .card { box-shadow:none; border:1px solid #dee2e6; }
}
</style>
