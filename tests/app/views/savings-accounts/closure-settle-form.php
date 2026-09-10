<?php
$pageTitle = $pageTitle ?? 'Settle Account Closure';
$title     = $pageTitle;
$icon      = 'bi-cash-coin';
$base      = APP_URL . '/index.php';
$settlementRequired = $currentBalance > 0.0;
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <?php if ($msg = Session::flash('error')): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-header"><i class="bi bi-door-closed me-2 text-primary"></i>Approved Request #<?= (int)$request['id'] ?>
                    <span class="badge bg-light text-dark border ms-2"><?= htmlspecialchars($request['account_type']) ?></span>
                </div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-5 text-muted">Member</dt>
                        <dd class="col-7"><?= htmlspecialchars($request['first_name'] . ' ' . $request['last_name']) ?> (<?= htmlspecialchars($request['member_number']) ?>)</dd>
                        <dt class="col-5 text-muted">Account</dt>
                        <dd class="col-7 fw-semibold"><?= htmlspecialchars($request['account_number']) ?></dd>
                        <dt class="col-5 text-muted fw-bold">Current Balance</dt>
                        <dd class="col-7 fw-bold fs-6">Shs <?= number_format($currentBalance, 2) ?></dd>
                        <dt class="col-5 text-muted">Approved By</dt>
                        <dd class="col-7"><?= htmlspecialchars($approvedBy) ?></dd>
                        <dt class="col-5 text-muted">Approval Date</dt>
                        <dd class="col-7"><?= date('d M Y', strtotime($request['approved_at'])) ?></dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-header"><i class="bi bi-cash-coin me-2 text-primary"></i>
                    <?= $settlementRequired ? 'Record Payment' : 'Finalize Closure' ?>
                </div>
                <div class="card-body">
                    <?php if ($settlementRequired): ?>
                    <div class="alert alert-info small">
                        The amount below is the account's current live balance and cannot be changed here — it is
                        recalculated fresh, on the server, at the moment you confirm.
                    </div>
                    <?php else: ?>
                    <div class="alert alert-secondary small">
                        This account has a zero balance — no payment is needed. Confirming below simply closes it.
                    </div>
                    <?php endif; ?>
                    <form method="POST" action="<?= $base ?>?page=savings-account-settle-store">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Amount to Pay</label>
                            <div class="input-group">
                                <span class="input-group-text">Shs</span>
                                <input type="text" class="form-control" value="<?= number_format($currentBalance, 2) ?>" disabled>
                            </div>
                        </div>
                        <?php if ($settlementRequired): ?>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Payment Method</label>
                            <select name="payment_method" class="form-select" required>
                                <?php foreach (['Cash','Airtel Money','MTN Mobile Money','Bank Transfer','Cheque','Other'] as $m): ?>
                                    <option value="<?= $m ?>"><?= $m ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Payment Reference</label>
                            <input type="text" name="payment_reference" class="form-control" placeholder="e.g. transaction/receipt number">
                        </div>
                        <?php endif; ?>
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Effective Date</label>
                            <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="bi bi-check-circle me-1"></i><?= $settlementRequired ? 'Confirm Settlement' : 'Close Account' ?>
                            </button>
                            <a href="<?= $base ?>?page=savings-account-settlements" class="btn btn-outline-secondary">
                                Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
