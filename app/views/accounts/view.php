<?php
$pageTitle = $pageTitle ?? 'Account';
$title = $pageTitle;
$icon  = 'bi-diagram-3';
$isAdmin = Session::hasRole(['admin']);
$base = APP_URL . '/index.php';
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <?php if (Session::has('success')): ?>
        <div class="alert alert-success"><?= Session::flash('success') ?></div>
    <?php endif; ?>
    <?php if (Session::has('error')): ?>
        <div class="alert alert-danger"><?= Session::flash('error') ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-12 col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-diagram-3 me-2"></i><?= htmlspecialchars($account['code']) ?> — <?= htmlspecialchars($account['name']) ?></span>
                    <?= $account['is_active']
                        ? '<span class="badge bg-success">Active</span>'
                        : '<span class="badge bg-secondary">Inactive</span>' ?>
                </div>
                <div class="card-body">
                    <div class="row mb-2">
                        <div class="col-md-4"><strong>Type:</strong> <span class="text-capitalize"><?= htmlspecialchars($account['type']) ?></span></div>
                        <div class="col-md-4"><strong>Classification:</strong> <?= htmlspecialchars($account['subtype'] ?? '-') ?></div>
                        <div class="col-md-4"><strong>Normal Balance:</strong> <span class="text-capitalize"><?= htmlspecialchars($account['normal_balance']) ?></span></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-4"><strong>Account ID:</strong> #<?= $account['id'] ?></div>
                        <div class="col-md-4"><strong>Parent Account:</strong> <?= $parent ? htmlspecialchars($parent['code'] . ' — ' . $parent['name']) : '-' ?></div>
                        <div class="col-md-4"><strong>System Account:</strong> <?= $account['is_system'] ? 'Yes' : 'No' ?></div>
                    </div>
                    <?php if ($account['description']): ?>
                        <p class="mt-2"><strong>Description:</strong> <?= htmlspecialchars($account['description']) ?></p>
                    <?php endif; ?>

                    <?php if (!empty($children)): ?>
                        <hr>
                        <h6 class="text-muted small text-uppercase">Sub-Accounts</h6>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($children as $c): ?>
                                <li>
                                    <a href="<?= $base ?>?page=account-view&id=<?= $c['id'] ?>">
                                        <?= htmlspecialchars($c['code'] . ' — ' . $c['name']) ?>
                                    </a>
                                    <?= $c['is_active'] ? '' : '<span class="badge bg-secondary ms-1">Inactive</span>' ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <hr>
                    <a href="<?= $base ?>?page=report-general-ledger&account_id=<?= $account['id'] ?>" class="btn btn-sm btn-outline-success">
                        <i class="bi bi-journal-text me-1"></i> View Account Activity in General Ledger
                    </a>
                    <?php if ($hasActivity): ?>
                        <span class="text-muted small ms-2">This account has posted journal activity.</span>
                    <?php else: ?>
                        <span class="text-muted small ms-2">No journal activity yet.</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($isAdmin): ?>
        <div class="col-12 col-lg-4">
            <div class="card">
                <div class="card-header py-2"><h6 class="mb-0 fw-semibold small">Actions</h6></div>
                <div class="card-body">
                    <form method="POST" action="<?= $base ?>?page=account-toggle-status"
                          onsubmit="return confirm('<?= $account['is_active'] ? 'Deactivate' : 'Activate' ?> this account?');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="account_id" value="<?= $account['id'] ?>">
                        <button type="submit" class="btn btn-sm w-100 <?= $account['is_active'] ? 'btn-outline-secondary' : 'btn-outline-success' ?>">
                            <?= $account['is_active'] ? 'Deactivate Account' : 'Activate Account' ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <a href="<?= $base ?>?page=chart-of-accounts" class="btn btn-outline-secondary btn-sm mt-3">
        <i class="bi bi-arrow-left me-1"></i> Back to Chart of Accounts
    </a>
