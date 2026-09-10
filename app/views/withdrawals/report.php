<?php $base = APP_URL . '/index.php'; ?>
<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-file-bar-graph-fill me-2 text-danger"></i>Withdrawals Report
        </h1>
        <p class="text-muted mb-0 small">Financial Year <?= $year ?></p>
    </div>
    <div class="d-flex gap-2 no-print">
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer me-1"></i>Print</button>
        <a href="<?=$base?>?page=withdrawals" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>
</div>

<div class="card mb-4 no-print">
    <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="bi bi-funnel me-2"></i>Select Year</h6></div>
    <div class="card-body p-4">
        <form method="GET" action="<?=$base?>" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="withdrawal-report">
            <div class="col-md-3">
                <label class="form-label fw-semibold">Financial Year</label>
                <select name="year" class="form-select">
                    <?php
                    $yl = $years;
                    if(!in_array((int)date('Y'),array_map('intval',$yl))) $yl[]=(int)date('Y');
                    foreach($yl as $y): ?>
                    <option value="<?=$y?>" <?=(int)$year===(int)$y?'selected':''?>><?=$y?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto"><button type="submit" class="btn btn-primary"><i class="bi bi-arrow-clockwise me-1"></i>Generate</button></div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-sm-4">
        <div class="stat-card stat-card-danger">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-danger"><i class="bi bi-cash-stack"></i></div>
                <div><div class="stat-label">Total Cash Paid</div><div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($totalWithdrawn,2) ?></div></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-sm-4">
        <div class="stat-card stat-card-success">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-success"><i class="bi bi-shield-check-fill"></i></div>
                <div><div class="stat-label">Total Retained</div><div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($totalRetained,2) ?></div></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-4">
        <div class="stat-card stat-card-primary">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-primary"><i class="bi bi-people-fill"></i></div>
                <div><div class="stat-label">Members Processed</div><div class="stat-value"><?= count($withdrawals) ?></div></div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h6 class="mb-0 fw-semibold">Withdrawal Report — Financial Year <?= $year ?></h6></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">#</th><th>Withdrawal No.</th><th>Member</th><th>Type</th>
                    <th class="text-end">Savings</th><th class="text-end">Cash Paid</th>
                    <th class="text-end">Retained</th><th>Method</th><th>Date</th>
                    <th class="no-print">Print</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($withdrawals)): ?>
                <tr><td colspan="10" class="text-center py-4 text-muted">No withdrawals for <?=$year?>.</td></tr>
                <?php else: foreach($withdrawals as $i=>$w): $isAnnualRow = ($w['withdrawal_type'] ?? 'annual_compulsory') === 'annual_compulsory'; ?>
                <tr>
                    <td class="ps-3 text-muted small"><?=$i+1?></td>
                    <td class="fw-semibold text-danger small"><?= htmlspecialchars($w['withdrawal_number']) ?></td>
                    <td>
                        <div class="fw-semibold small"><?= htmlspecialchars($w['first_name'].' '.$w['last_name']) ?></div>
                        <div class="text-muted" style="font-size:.72rem"><?= htmlspecialchars($w['member_number']) ?></div>
                    </td>
                    <td class="small"><span class="badge <?= $isAnnualRow ? 'bg-danger-subtle text-danger' : 'bg-info-subtle text-info' ?>"><?= $isAnnualRow ? 'Annual' : 'Voluntary' ?></span></td>
                    <td class="text-end small">Shs <?= number_format($w['total_available_savings'],2) ?></td>
                    <td class="text-end fw-bold text-danger">Shs <?= number_format($w['withdrawal_amount'],2) ?></td>
                    <td class="text-end fw-semibold text-success">Shs <?= number_format($w['retained_amount'],2) ?></td>
                    <td class="small"><?= htmlspecialchars($w['payment_method']) ?></td>
                    <td class="small text-muted"><?= date('d M Y',strtotime($w['withdrawal_date'])) ?></td>
                    <td class="no-print"><a href="<?=$base?>?page=withdrawal-receipt&id=<?=$w['id']?>" class="btn btn-sm btn-outline-secondary" target="_blank"><i class="bi bi-printer"></i></a></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <?php if(!empty($withdrawals)): ?>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td class="ps-3" colspan="5">TOTAL</td>
                    <td class="text-end text-danger">Shs <?= number_format($totalWithdrawn,2) ?></td>
                    <td class="text-end text-success">Shs <?= number_format($totalRetained,2) ?></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>
<style>@media print{.no-print{display:none!important;}.card{box-shadow:none;border:1px solid #dee2e6;}}</style>
