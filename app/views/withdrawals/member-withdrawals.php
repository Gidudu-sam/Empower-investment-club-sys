<?php $base = APP_URL . '/index.php'; ?>
<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-box-arrow-up-right me-2 text-danger"></i>Withdrawal History
        </h1>
        <p class="text-muted mb-0 small"><?= htmlspecialchars($member['first_name'].' '.$member['last_name']) ?> · <?= htmlspecialchars($member['member_number']) ?></p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?=$base?>?page=member-view&id=<?=$member['id']?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-person me-1"></i>Profile</a>
        <a href="<?=$base?>?page=withdrawal-process&member_id=<?=$member['id']?>" class="btn btn-danger btn-sm"><i class="bi bi-plus me-1"></i>New Withdrawal</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-sm-4">
        <div class="stat-card stat-card-danger">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-danger"><i class="bi bi-box-arrow-up-right"></i></div>
                <div>
                    <div class="stat-label">Total Withdrawn</div>
                    <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($totalWithdrawn,2) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-sm-4">
        <div class="stat-card stat-card-success">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-success"><i class="bi bi-shield-check-fill"></i></div>
                <div>
                    <div class="stat-label">Total Retained Shares</div>
                    <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($totalRetained,2) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-4">
        <div class="stat-card stat-card-primary">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-primary"><i class="bi bi-calendar-year"></i></div>
                <div>
                    <div class="stat-label">Total Withdrawals</div>
                    <div class="stat-value"><?= count($withdrawals) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="bi bi-list-ul me-2 text-danger"></i>Annual Withdrawal History</h6></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Withdrawal No.</th>
                    <th class="text-center">Year</th>
                    <th class="text-end">Savings</th>
                    <th class="text-end">Cash Paid</th>
                    <th class="text-end">Retained</th>
                    <th>Method</th>
                    <th>Date</th>
                    <th class="text-end pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($withdrawals)): ?>
                <tr><td colspan="8" class="text-center py-4 text-muted">
                    No withdrawals recorded.
                    <a href="<?=$base?>?page=withdrawal-process&member_id=<?=$member['id']?>">Process first withdrawal.</a>
                </td></tr>
                <?php else: foreach($withdrawals as $w): ?>
                <tr>
                    <td class="ps-3">
                        <a href="<?=$base?>?page=withdrawal-view&id=<?=$w['id']?>"
                           class="fw-semibold text-danger text-decoration-none small">
                            <?= htmlspecialchars($w['withdrawal_number']) ?>
                        </a>
                    </td>
                    <td class="text-center"><span class="badge bg-primary-subtle text-primary rounded-pill"><?= $w['financial_year'] ?></span></td>
                    <td class="text-end small">Shs <?= number_format($w['total_available_savings'],2) ?></td>
                    <td class="text-end fw-bold text-danger">Shs <?= number_format($w['withdrawal_amount'],2) ?></td>
                    <td class="text-end fw-semibold text-success">Shs <?= number_format($w['retained_amount'],2) ?></td>
                    <td class="small"><?= htmlspecialchars($w['payment_method']) ?></td>
                    <td class="text-muted small"><?= date('d M Y', strtotime($w['withdrawal_date'])) ?></td>
                    <td class="text-end pe-3">
                        <div class="d-flex justify-content-end gap-1">
                            <a href="<?=$base?>?page=withdrawal-view&id=<?=$w['id']?>" class="btn btn-sm btn-outline-danger"><i class="bi bi-eye"></i></a>
                            <a href="<?=$base?>?page=withdrawal-receipt&id=<?=$w['id']?>" class="btn btn-sm btn-outline-secondary" target="_blank"><i class="bi bi-printer"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <?php if(!empty($withdrawals)): ?>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td class="ps-3" colspan="3">Total</td>
                    <td class="text-end text-danger">Shs <?= number_format($totalWithdrawn,2) ?></td>
                    <td class="text-end text-success">Shs <?= number_format($totalRetained,2) ?></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>
