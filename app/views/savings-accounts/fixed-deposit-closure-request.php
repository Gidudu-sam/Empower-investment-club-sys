<?php
$pageTitle = $pageTitle ?? 'Request Fixed Deposit Closure';
$title     = $pageTitle;
$icon      = 'bi-safe';
$base      = APP_URL . '/index.php';
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <?php if ($msg = Session::flash('error')): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-safe me-2 text-primary"></i>Request Fixed Deposit Closure
                </div>
                <div class="card-body">
                    <div class="alert alert-info small d-flex gap-2 mb-4">
                        <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
                        <span>
                            This account has matured. Submitting this request does not close the account or
                            pay out any money — it only routes the request to the Treasurer for approval.
                            The Cashier records the actual payment after approval.
                        </span>
                    </div>

                    <!-- These figures are display-only, never editable -- the
                         actual request is created server-side from the
                         account's own locked, stored terms. -->
                    <dl class="row mb-4 small">
                        <dt class="col-6 text-muted">Member</dt>
                        <dd class="col-6"><?= htmlspecialchars(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) ?></dd>
                        <dt class="col-6 text-muted">Account Number</dt>
                        <dd class="col-6 fw-semibold"><?= htmlspecialchars($account['account_number']) ?></dd>
                        <dt class="col-6 text-muted">Principal</dt>
                        <dd class="col-6">Shs <?= number_format((float)$account['principal_amount'], 2) ?></dd>
                        <dt class="col-6 text-muted">Interest Rate</dt>
                        <dd class="col-6"><?= number_format((float)$account['interest_rate'], 3) ?>% p.a.</dd>
                        <dt class="col-6 text-muted">Expected Interest</dt>
                        <dd class="col-6 text-success">Shs <?= number_format((float)$account['expected_interest'], 2) ?></dd>
                        <dt class="col-6 text-muted fw-bold">Total Maturity Amount</dt>
                        <dd class="col-6 fw-bold">Shs <?= number_format((float)$account['expected_maturity_amount'], 2) ?></dd>
                        <dt class="col-6 text-muted">Maturity Date</dt>
                        <dd class="col-6"><?= $account['maturity_date'] ? date('d M Y', strtotime($account['maturity_date'])) : '—' ?></dd>
                    </dl>

                    <form method="POST" action="<?= $base ?>?page=savings-account-fd-closure-request-store">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="bi bi-send me-1"></i>Submit Closure Request
                            </button>
                            <a href="<?= $base ?>?page=savings-account-view&id=<?= (int)$account['id'] ?>" class="btn btn-outline-secondary">
                                Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
