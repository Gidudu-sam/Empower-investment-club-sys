<?php
$pageTitle = $pageTitle ?? 'Review Closure Request';
$title     = $pageTitle;
$icon      = 'bi-clipboard-check';
$base      = APP_URL . '/index.php';
$isSelfRequest = (int)$request['requested_by'] === $currentUserId;
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <?php if ($isSelfRequest): ?>
    <div class="alert alert-warning small">
        <i class="bi bi-exclamation-triangle me-1"></i>You submitted this request yourself and cannot approve it —
        another Treasurer or Admin must review it.
    </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-12 col-lg-7">
            <div class="card">
                <div class="card-header"><i class="bi bi-safe me-2 text-primary"></i>Fixed Deposit Closure Request #<?= (int)$request['id'] ?></div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-5 text-muted">Member</dt>
                        <dd class="col-7"><?= htmlspecialchars($request['first_name'] . ' ' . $request['last_name']) ?> (<?= htmlspecialchars($request['member_number']) ?>)</dd>
                        <dt class="col-5 text-muted">FD Account Number</dt>
                        <dd class="col-7 fw-semibold"><?= htmlspecialchars($request['account_number']) ?></dd>
                        <dt class="col-5 text-muted">Principal</dt>
                        <dd class="col-7">Shs <?= number_format((float)$request['principal_amount'], 2) ?></dd>
                        <dt class="col-5 text-muted">Locked Interest Rate</dt>
                        <dd class="col-7"><?= number_format((float)$request['interest_rate'], 3) ?>% p.a.</dd>
                        <dt class="col-5 text-muted">Expected Interest</dt>
                        <dd class="col-7 text-success">Shs <?= number_format((float)$request['expected_interest'], 2) ?></dd>
                        <dt class="col-5 text-muted fw-bold">Total Payout Amount</dt>
                        <dd class="col-7 fw-bold">Shs <?= number_format((float)$request['payout_amount'], 2) ?></dd>
                        <dt class="col-5 text-muted">Closure Requested By</dt>
                        <dd class="col-7"><?= htmlspecialchars($requestedBy) ?></dd>
                        <dt class="col-5 text-muted">Request Date</dt>
                        <dd class="col-7"><?= date('d M Y H:i', strtotime($request['requested_at'])) ?></dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card">
                <div class="card-header"><i class="bi bi-check2-square me-2 text-primary"></i>Decision</div>
                <div class="card-body">
                    <form method="POST" action="<?= $base ?>?page=savings-account-fd-closure-approve" class="mb-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                        <button type="submit" class="btn btn-success w-100" <?= $isSelfRequest ? 'disabled' : '' ?>>
                            <i class="bi bi-check-circle me-1"></i>Approve
                        </button>
                    </form>
                    <form method="POST" action="<?= $base ?>?page=savings-account-fd-closure-reject">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                        <label class="form-label fw-semibold small">Rejection Reason <span class="text-danger">*</span></label>
                        <textarea name="rejection_reason" class="form-control mb-2" rows="3" required></textarea>
                        <button type="submit" class="btn btn-outline-danger w-100">
                            <i class="bi bi-x-circle me-1"></i>Reject
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
