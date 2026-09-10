<?php
$pageTitle = $pageTitle ?? 'Chart of Accounts';
$title = $pageTitle;
$icon  = 'bi-diagram-3';
$isAdmin = Session::hasRole(['admin']);
$base = APP_URL . '/index.php';

$sections = [
    'asset'     => ['label' => 'Assets',      'icon' => 'bi-building',        'badge' => 'bg-primary'],
    'liability' => ['label' => 'Liabilities', 'icon' => 'bi-credit-card',     'badge' => 'bg-danger'],
    'equity'    => ['label' => 'Equity',      'icon' => 'bi-pie-chart',       'badge' => 'bg-warning text-dark'],
    'income'    => ['label' => 'Income',      'icon' => 'bi-graph-up-arrow',  'badge' => 'bg-success'],
    'expense'   => ['label' => 'Expenses',    'icon' => 'bi-graph-down-arrow','badge' => 'bg-secondary'],
];
$totalCount = array_sum(array_map('count', $grouped));
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <?php if (Session::has('success')): ?>
        <div class="alert alert-success"><?= Session::flash('success') ?></div>
    <?php endif; ?>
    <?php if (Session::has('error')): ?>
        <div class="alert alert-danger"><?= Session::flash('error') ?></div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-diagram-3 me-2"></i>Chart of Accounts <span class="text-muted small">(<?= $totalCount ?> accounts)</span></span>
            <?php if ($isAdmin): ?>
            <a href="<?= $base ?>?page=account-create" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-circle me-1"></i> Create Account
            </a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="page" value="chart-of-accounts">
                <div class="col-md-4">
                    <label class="form-label small">Search (code or name)</label>
                    <input type="text" name="search" class="form-control form-control-sm" value="<?= htmlspecialchars($search) ?>" placeholder="e.g. 4035 or Loan Interest">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Type</label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="">All Types</option>
                        <?php foreach ($sections as $key => $s): ?>
                            <option value="<?= $key ?>" <?= $type === $key ? 'selected' : '' ?>><?= $s['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary flex-fill">Apply</button>
                    <a href="<?= $base ?>?page=chart-of-accounts" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <?php foreach ($sections as $key => $s): ?>
        <?php if (empty($grouped[$key])) continue; ?>
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi <?= $s['icon'] ?> me-2"></i><?= $s['label'] ?></span>
                <span class="badge <?= $s['badge'] ?>"><?= count($grouped[$key]) ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Classification</th>
                                <th>Normal Balance</th>
                                <th>Status</th>
                                <th class="text-muted small">ID</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grouped[$key] as $a): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($a['code']) ?></strong></td>
                                    <td>
                                        <?= htmlspecialchars($a['name']) ?>
                                        <?php if (!empty($a['parent_id'])): ?>
                                            <span class="text-muted small">(sub-account)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small class="text-muted"><?= htmlspecialchars($a['subtype'] ?? '') ?></small></td>
                                    <td class="text-capitalize"><?= htmlspecialchars($a['normal_balance']) ?></td>
                                    <td>
                                        <?= $a['is_active']
                                            ? '<span class="badge bg-success">Active</span>'
                                            : '<span class="badge bg-secondary">Inactive</span>' ?>
                                    </td>
                                    <td class="text-muted small">#<?= $a['id'] ?></td>
                                    <td class="text-end">
                                        <a href="<?= $base ?>?page=account-view&id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($totalCount === 0): ?>
        <div class="alert alert-info">No accounts match the selected filters.</div>
    <?php endif; ?>
