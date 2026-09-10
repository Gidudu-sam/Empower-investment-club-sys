<?php
$pageTitle = $pageTitle ?? 'Request Account Closure';
$title     = $pageTitle;
$icon      = 'bi-door-closed';
$base      = APP_URL . '/index.php';
$typeLabels = [
    'compulsory'    => 'Compulsory Savings',
    'voluntary'     => 'Voluntary Savings',
    'joint'         => 'Joint Savings',
    'corporate'     => 'Corporate Savings',
];
$memberHolders = array_values(array_filter($holders, fn($h) => !empty($h['member_id'])));
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <?php if ($msg = Session::flash('error')): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-door-closed me-2 text-primary"></i>Request Closure — <?= htmlspecialchars($typeLabels[$account['account_type']] ?? $account['account_type']) ?>
                </div>
                <div class="card-body">
                    <div class="alert alert-info small d-flex gap-2 mb-4">
                        <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
                        <span>
                            Submitting this request does not close the account or pay out any money — it only
                            routes the request to the Treasurer for approval. The Cashier settles the balance
                            (if any) after approval.
                        </span>
                    </div>

                    <dl class="row mb-4 small">
                        <dt class="col-6 text-muted">Account Number</dt>
                        <dd class="col-6 fw-semibold"><?= htmlspecialchars($account['account_number']) ?></dd>
                        <dt class="col-6 text-muted">Current Balance</dt>
                        <dd class="col-6 fw-bold">Shs <?= number_format($eligibility['balance'], 2) ?></dd>
                    </dl>

                    <?php if (!$eligibility['eligible']): ?>
                        <div class="alert alert-warning small">
                            <i class="bi bi-exclamation-triangle me-1"></i>This account is not currently eligible for closure:
                            <?= htmlspecialchars($eligibility['reason']) ?>
                        </div>
                        <a href="<?= $base ?>?page=savings-account-view&id=<?= (int)$account['id'] ?>" class="btn btn-outline-secondary">Back</a>
                    <?php else: ?>
                        <form method="POST" action="<?= $base ?>?page=savings-account-closure-request-store">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>">

                            <?php if ($account['account_type'] === 'joint' && count($memberHolders) > 1): ?>
                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Attribute Settlement To</label>
                                <select name="holder_member_id" class="form-select">
                                    <?php foreach ($memberHolders as $h): ?>
                                        <option value="<?= (int)$h['member_id'] ?>" <?= (int)$h['member_id'] === (int)$eligibility['settlement_member_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($h['first_name'] . ' ' . $h['last_name']) ?> (<?= htmlspecialchars($h['role']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">This account has multiple holders — pick who the settlement payment is being made to.</div>
                            </div>
                            <?php endif; ?>

                            <div class="mb-4">
                                <label class="form-label fw-semibold small">Reason (optional)</label>
                                <textarea name="reason" class="form-control" rows="2"></textarea>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary px-4">
                                    <i class="bi bi-send me-1"></i>Submit Closure Request
                                </button>
                                <a href="<?= $base ?>?page=savings-account-view&id=<?= (int)$account['id'] ?>" class="btn btn-outline-secondary">
                                    Cancel
                                </a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
