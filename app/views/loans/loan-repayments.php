<?php
$base = APP_URL . '/index.php';
$totalPayable = (float)$loan['total_payable'];
$outstanding  = (float)$loan['outstanding'];
$paid         = $totalPaid;
$trueTotal    = $paid + max(0, $outstanding);
$pct          = $trueTotal > 0 ? min(100, round(($paid / $trueTotal) * 100)) : ($outstanding <= 0 ? 100 : 0);
?>

<?php ob_start(); ?>
<a href="<?=$base?>?page=loan-view&id=<?=$loan['id']?>" class="btn btn-outline-warning btn-sm">
    <i class="bi bi-bank2 me-1"></i>Loan Details
</a>
<?php if($loan['status'] !== 'completed'): ?>
<a href="<?=$base?>?page=repayment-add&loan_id=<?=$loan['id']?>" class="btn text-white btn-sm fw-semibold" style="background:var(--brand-orange)">
    <i class="bi bi-plus me-1"></i>Record Payment
</a>
<?php endif; ?>
<?php $cta = ob_get_clean(); ?>
<?php extract([
    'icon'      => 'bi-clock-history',
    'iconStyle' => 'color:var(--brand-orange)',
    'title'     => 'Repayment History',
    'subtitle'  => htmlspecialchars($loan['loan_number']) . ' — ' . htmlspecialchars($loan['first_name'].' '.$loan['last_name']),
    'cta'       => $cta,
]); include VIEW_PATH . '/layouts/page-title.php'; ?>

<!-- Progress card -->
<div class="card mb-4">
    <div class="card-body p-4">
        <div class="row g-3 mb-3">
            <div class="col-sm-3 text-center">
                <div class="text-muted small">Loan Amount</div>
                <div class="fw-bold fs-5">Shs <?= number_format($loan['loan_amount'],2) ?></div>
            </div>
            <div class="col-sm-3 text-center">
                <div class="text-muted small">Total Payable</div>
                <div class="fw-bold fs-5">Shs <?= number_format($totalPayable,2) ?></div>
            </div>
            <div class="col-sm-3 text-center">
                <div class="text-muted small">Total Paid</div>
                <div class="fw-bold fs-5 text-success">Shs <?= number_format($paid,2) ?></div>
            </div>
            <div class="col-sm-3 text-center">
                <div class="text-muted small">Outstanding</div>
                <div class="fw-bold fs-5 <?= $outstanding<=0?'text-success':'text-danger' ?>">
                    Shs <?= number_format($outstanding,2) ?>
                </div>
            </div>
        </div>
        <div class="d-flex justify-content-between small text-muted mb-1">
            <span>Repayment Progress</span>
            <span><?=$pct?>% paid</span>
        </div>
        <div class="progress" style="height:14px;border-radius:99px;">
            <div class="progress-bar <?= $pct>=100?'bg-success':'bg-warning' ?>"
                 role="progressbar" style="width:<?=$pct?>%"></div>
        </div>
        <?php if($loan['status']==='completed'): ?>
        <div class="alert alert-success d-flex align-items-center gap-2 mt-3 mb-0">
            <i class="bi bi-check-circle-fill flex-shrink-0"></i>
            <div><strong>Loan Fully Paid!</strong> This loan has been completed.</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Repayments table -->
<div class="card">
    <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="bi bi-list-ul me-2" style="color:var(--brand-orange)"></i>Payment Timeline</h6></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">#</th>
                    <th>Receipt No.</th>
                    <th>Date</th>
                    <th class="text-end">Amount Paid</th>
                    <th class="text-end">Balance After</th>
                    <th class="d-none d-md-table-cell">Method</th>
                    <th class="d-none d-lg-table-cell">Reference</th>
                    <th class="d-none d-lg-table-cell">Cashier</th>
                    <th class="text-end pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($repayments)): ?>
                <tr><td colspan="9" class="text-center py-4 text-muted">
                    No repayments recorded.
                    <a href="<?=$base?>?page=repayment-add&loan_id=<?=$loan['id']?>">Record first payment.</a>
                </td></tr>
                <?php else: foreach($repayments as $i=>$r): ?>
                <tr>
                    <td class="ps-3 text-muted small"><?= $i+1 ?></td>
                    <td>
                        <a href="<?=$base?>?page=repayment-view&id=<?=$r['id']?>"
                           class="fw-semibold text-decoration-none" style="color:var(--brand-orange)">
                            <?= htmlspecialchars($r['repayment_number']) ?>
                        </a>
                    </td>
                    <td class="small"><?= date('d M Y', strtotime($r['payment_date'])) ?></td>
                    <td class="text-end fw-bold text-success">Shs <?= number_format($r['amount_paid'],2) ?></td>
                    <td class="text-end fw-semibold <?= $r['balance_after']<=0?'text-success':'text-danger' ?>">
                        Shs <?= number_format($r['balance_after'],2) ?>
                    </td>
                    <td class="d-none d-md-table-cell small"><?= htmlspecialchars($r['payment_method']) ?></td>
                    <td class="d-none d-lg-table-cell text-muted small">
                        <?= $r['reference_number'] ? htmlspecialchars($r['reference_number']) : '—' ?>
                    </td>
                    <td class="d-none d-lg-table-cell text-muted small">
                        <?= htmlspecialchars($r['cashier_name'] ?? '—') ?>
                    </td>
                    <td class="text-end pe-3">
                        <div class="d-flex justify-content-end gap-1">
                            <a href="<?=$base?>?page=repayment-view&id=<?=$r['id']?>"
                               class="btn btn-sm btn-outline-primary" title="View"><i class="bi bi-eye"></i></a>
                            <a href="<?=$base?>?page=repayment-receipt&id=<?=$r['id']?>"
                               class="btn btn-sm btn-outline-secondary" title="Print" target="_blank"><i class="bi bi-printer"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <?php if(!empty($repayments)): ?>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td class="ps-3" colspan="3">Total Paid</td>
                    <td class="text-end text-success">Shs <?= number_format($paid,2) ?></td>
                    <td colspan="5"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>
