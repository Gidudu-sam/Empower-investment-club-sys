<?php
$base       = APP_URL . '/index.php';
$typeLabels = ['daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly','annual'=>'Annual'];
$typeLabel  = $typeLabels[$type] ?? ucfirst($type);
$months     = ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$periodLabel = match($type){
    'daily'   => date('d F Y', strtotime($dateFrom)),
    'weekly'  => date('d M',strtotime($dateFrom)).' – '.date('d M Y',strtotime($dateTo)),
    'monthly' => $months[(int)$month].' '.$year,
    'annual'  => 'Year '.$year,
    default   => 'All Periods',
};
?>
<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-file-bar-graph-fill me-2 text-primary"></i>Repayments Report
        </h1>
        <p class="text-muted mb-0 small"><?=$typeLabel?> · <?=$periodLabel?></p>
    </div>
    <div class="d-flex gap-2 no-print">
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer me-1"></i>Print
        </button>
        <a href="<?=$base?>?page=repayments" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<!-- Report selector -->
<div class="card mb-4 no-print">
    <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="bi bi-funnel me-2"></i>Report Parameters</h6></div>
    <div class="card-body p-4">
        <form method="GET" action="<?=$base?>" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="repayment-report">
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
                <input type="number" name="year" class="form-control" value="<?= htmlspecialchars($year) ?>" min="2020" max="<?= date('Y')+1 ?>">
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
                <button type="submit" class="btn btn-primary"><i class="bi bi-arrow-clockwise me-1"></i>Generate</button>
            </div>
        </form>
    </div>
</div>

<!-- Summary -->
<div class="row g-3 mb-4">
    <div class="col-6 col-sm-4">
        <div class="stat-card stat-card-success">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-success"><i class="bi bi-cash-stack"></i></div>
                <div>
                    <div class="stat-label">Total Collected</div>
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
                    <div class="stat-value"><?= count($repayments) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-4">
        <div class="stat-card stat-card-info">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-info"><i class="bi bi-calculator"></i></div>
                <div>
                    <div class="stat-label">Average Payment</div>
                    <div class="stat-value" style="font-size:1.1rem">
                        Shs <?= count($repayments) > 0 ? number_format($totalAmount/count($repayments),2) : '0.00' ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><?=$typeLabel?> Repayments Report — <?=$periodLabel?></h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">#</th>
                    <th>Receipt No.</th>
                    <th>Loan No.</th>
                    <th>Member</th>
                    <th class="text-end">Amount Paid</th>
                    <th>Method</th>
                    <th>Date</th>
                    <th class="no-print">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($repayments)): ?>
                <tr><td colspan="8" class="text-center py-4 text-muted">No repayments for this period.</td></tr>
                <?php else: foreach($repayments as $i=>$r): ?>
                <tr>
                    <td class="ps-3 text-muted small"><?=$i+1?></td>
                    <td class="fw-semibold small" style="color:var(--brand-orange)"><?= htmlspecialchars($r['repayment_number']) ?></td>
                    <td class="small" style="color:var(--brand-navy)"><?= htmlspecialchars($r['loan_number']) ?></td>
                    <td>
                        <div class="fw-semibold small"><?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?></div>
                        <div class="text-muted" style="font-size:.72rem"><?= htmlspecialchars($r['member_number']) ?></div>
                    </td>
                    <td class="text-end fw-bold text-success">Shs <?= number_format($r['amount_paid'],2) ?></td>
                    <td class="small"><?= htmlspecialchars($r['payment_method']) ?></td>
                    <td class="small text-muted"><?= date('d M Y',strtotime($r['payment_date'])) ?></td>
                    <td class="no-print">
                        <a href="<?=$base?>?page=repayment-receipt&id=<?=$r['id']?>" class="btn btn-sm btn-outline-secondary" target="_blank"><i class="bi bi-printer"></i></a>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <?php if(!empty($repayments)): ?>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td class="ps-3" colspan="4">TOTAL</td>
                    <td class="text-end text-success">Shs <?= number_format($totalAmount,2) ?></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>
<style>@media print{.no-print{display:none!important;}.card{box-shadow:none;border:1px solid #dee2e6;}}</style>
