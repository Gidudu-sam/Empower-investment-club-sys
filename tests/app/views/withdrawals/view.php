<?php
$base = APP_URL . '/index.php';
$w    = $withdrawal;
$isAnnual = ($w['withdrawal_type'] ?? 'annual_compulsory') === 'annual_compulsory';
$canWrite = Session::hasRole(['admin', 'treasurer', 'chairman']); // matches WithdrawalController::requireWriteAccess() exactly
?>
<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-box-arrow-up-right me-2 text-danger"></i>Withdrawal Record
            <span class="badge <?= $isAnnual ? 'bg-danger-subtle text-danger' : 'bg-info-subtle text-info' ?> ms-2 align-middle">
                <?= $isAnnual ? 'Annual Compulsory' : 'Voluntary' ?>
            </span>
        </h1>
        <p class="text-muted mb-0 small"><?= htmlspecialchars($w['withdrawal_number']) ?></p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?=$base?>?page=withdrawal-receipt&id=<?=$w['id']?>" class="btn btn-sm btn-success" target="_blank">
            <i class="bi bi-printer me-1"></i>Print Receipt
        </a>
        <?php if ($canWrite): ?>
        <form method="POST" action="<?=$base?>?page=withdrawal-delete" class="d-inline"
              onsubmit="return confirm('Reverse this withdrawal? This will restore the savings balance and reverse the journal entry. This cannot be undone.');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
            <input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger">
                <i class="bi bi-arrow-counterclockwise me-1"></i>Reverse
            </button>
        </form>
        <?php endif; ?>
        <a href="<?=$base?>?page=withdrawals" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<?php if(!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-check-circle-fill flex-shrink-0"></i><div><?= htmlspecialchars($success) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-box-arrow-up-right me-2 text-danger"></i>Withdrawal Details</h6>
                <span class="badge bg-primary-subtle text-primary rounded-pill px-3">FY <?= $w['financial_year'] ?></span>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-sm-4">
                        <div class="detail-label">Withdrawal Number</div>
                        <div class="detail-value fw-bold text-danger"><?= htmlspecialchars($w['withdrawal_number']) ?></div>
                    </div>
                    <div class="col-sm-4">
                        <div class="detail-label"><?= $isAnnual ? 'Qualifying Compulsory Balance' : 'Voluntary Balance Before' ?></div>
                        <div class="detail-value fw-bold">Shs <?= number_format($w['total_available_savings'],2) ?></div>
                    </div>
                    <div class="col-sm-4">
                        <div class="detail-label">Financial Year</div>
                        <div class="detail-value fw-bold"><?= $w['financial_year'] ?></div>
                    </div>
                    <?php if (!empty($w['account_number'])): ?>
                    <div class="col-sm-4">
                        <div class="detail-label">Savings Account</div>
                        <div class="detail-value fw-bold"><?= htmlspecialchars($w['account_number']) ?> (<?= ucfirst($w['account_type']) ?>)</div>
                    </div>
                    <?php endif; ?>

                    <div class="col-sm-4">
                        <div class="p-3 bg-danger bg-opacity-10 rounded-3 text-center">
                            <div class="text-danger fw-semibold small mb-1">Cash Withdrawn (<?= $w['withdrawal_percentage'] ?>%)</div>
                            <div class="fw-bold fs-3 text-danger">Shs <?= number_format($w['withdrawal_amount'],2) ?></div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="p-3 bg-success bg-opacity-10 rounded-3 text-center">
                            <div class="text-success fw-semibold small mb-1">
                                <?= $isAnnual ? 'Converted to Share Capital' : 'Share Capital (n/a)' ?> (<?= $w['retained_percentage'] ?>%)
                            </div>
                            <div class="fw-bold fs-3 text-success">Shs <?= number_format($w['retained_amount'],2) ?></div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="p-3 bg-primary bg-opacity-10 rounded-3 text-center">
                            <div class="text-primary fw-semibold small mb-1"><?= $isAnnual ? 'Remaining Compulsory Balance' : 'Remaining Voluntary Balance' ?></div>
                            <div class="fw-bold fs-3 text-primary">
                                Shs <?= number_format(max(0, $w['total_available_savings'] - $w['withdrawal_amount'] - $w['retained_amount']),2) ?>
                            </div>
                        </div>
                    </div>
                    <?php if (!empty($w['entry_number'])): ?>
                    <div class="col-sm-4">
                        <div class="detail-label">Journal Entry</div>
                        <div class="detail-value fw-bold text-success"><?= htmlspecialchars($w['entry_number']) ?></div>
                    </div>
                    <?php endif; ?>

                    <div class="col-sm-4">
                        <div class="detail-label">Payment Method</div>
                        <div class="detail-value"><?= htmlspecialchars($w['payment_method']) ?></div>
                    </div>
                    <?php if($w['reference_number']): ?>
                    <div class="col-sm-4">
                        <div class="detail-label">Reference Number</div>
                        <div class="detail-value"><?= htmlspecialchars($w['reference_number']) ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="col-sm-4">
                        <div class="detail-label">Withdrawal Date</div>
                        <div class="detail-value"><?= date('d F Y', strtotime($w['withdrawal_date'])) ?></div>
                    </div>
                    <div class="col-sm-4">
                        <div class="detail-label">Processed By</div>
                        <div class="detail-value"><?= htmlspecialchars($w['cashier_name'] ?? '—') ?></div>
                    </div>
                    <?php if($w['remarks']): ?>
                    <div class="col-12">
                        <div class="detail-label">Remarks</div>
                        <div class="detail-value text-muted"><?= nl2br(htmlspecialchars($w['remarks'])) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-person-circle text-primary"></i>
                <h6 class="mb-0 fw-semibold">Member</h6>
            </div>
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="member-avatar-sm bg-blue flex-shrink-0">
                        <?= strtoupper(substr($w['first_name'],0,1)) ?>
                    </div>
                    <div>
                        <div class="fw-bold"><?= htmlspecialchars($w['first_name'].' '.$w['last_name']) ?></div>
                        <div class="text-muted small"><?= htmlspecialchars($w['member_number']) ?></div>
                    </div>
                </div>
                <div class="d-flex flex-column gap-2">
                    <a href="<?=$base?>?page=member-view&id=<?=$w['member_id']?>" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-person me-1"></i>View Profile
                    </a>
                    <a href="<?=$base?>?page=withdrawal-member&member_id=<?=$w['member_id']?>" class="btn btn-sm btn-outline-danger">
                        <i class="bi bi-clock-history me-1"></i>Withdrawal History
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
