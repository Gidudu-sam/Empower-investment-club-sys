<?php
$pageTitle = $pageTitle ?? 'Investment Types';
$title = $pageTitle;
$icon  = 'bi-tags';
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
        <div class="col-12 col-lg-7">
            <div class="card">
                <div class="card-header"><i class="bi bi-tags me-2"></i>Investment Types</div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Type Name</th>
                                <th>Asset Account</th>
                                <th>Income Account</th>
                                <th>Loss Account</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($types as $t): ?>
                                <tr>
                                    <td><?= htmlspecialchars($t['type_name']) ?></td>
                                    <td><small><?= htmlspecialchars($t['asset_code'] . ' ' . $t['asset_name']) ?></small></td>
                                    <td><small><?= htmlspecialchars($t['income_code'] . ' ' . $t['income_name']) ?></small></td>
                                    <td><small><?= $t['loss_code'] ? htmlspecialchars($t['loss_code'] . ' ' . $t['loss_name']) : '<span class="text-muted">not set</span>' ?></small></td>
                                    <td>
                                        <?= $t['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?>
                                    </td>
                                    <td class="text-end">
                                        <form method="POST" action="<?= $base ?>?page=investment-type-toggle" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                            <input type="hidden" name="type_id" value="<?= $t['id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $t['is_active'] ? 'btn-outline-secondary' : 'btn-outline-success' ?>">
                                                <?= $t['is_active'] ? 'Deactivate' : 'Activate' ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card">
                <div class="card-header"><i class="bi bi-plus-circle me-2"></i>New Investment Type</div>
                <div class="card-body">
                    <form method="POST" action="<?= $base ?>?page=investment-type-store">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                        <div class="mb-3">
                            <label class="form-label">Type Name</label>
                            <input type="text" name="type_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Asset GL Account</label>
                            <select name="asset_gl_account_id" class="form-select" required>
                                <option value="">Select Account</option>
                                <?php foreach ($accounts as $a): ?>
                                    <?php if ($a['type'] === 'asset'): ?>
                                    <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['code'] . ' — ' . $a['name']) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Income GL Account</label>
                            <select name="income_gl_account_id" class="form-select" required>
                                <option value="">Select Account</option>
                                <?php foreach ($accounts as $a): ?>
                                    <?php if ($a['type'] === 'income'): ?>
                                    <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['code'] . ' — ' . $a['name']) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Loss GL Account <span class="text-muted">(optional, required for below-carrying-amount disposals)</span></label>
                            <select name="loss_gl_account_id" class="form-select">
                                <option value="">Not set</option>
                                <?php foreach ($accounts as $a): ?>
                                    <?php if ($a['type'] === 'expense'): ?>
                                    <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['code'] . ' — ' . $a['name']) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2"></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle me-1"></i> Create Type
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <a href="<?= $base ?>?page=investments" class="btn btn-outline-secondary btn-sm mt-3">
        <i class="bi bi-arrow-left me-1"></i> Back to Investments
    </a>
