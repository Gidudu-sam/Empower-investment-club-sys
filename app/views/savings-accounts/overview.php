<?php
$pageTitle = $pageTitle ?? 'Savings Accounts';
$title = $pageTitle;
$icon  = 'bi-bank';
// Matches SavingsAccountController::requireWriteAccess() exactly -- office_admin
// already has backend authority to open accounts, this UI just wasn't showing it.
$canWrite = Session::hasRole(['admin', 'treasurer', 'office_admin']);

$typeLabels = ['compulsory' => 'Compulsory Savings', 'voluntary' => 'Voluntary Savings', 'joint' => 'Joint Savings', 'corporate' => 'Corporate Savings', 'fixed_deposit' => 'Fixed Deposit'];
$statusLabels = ['active' => 'Active', 'dormant' => 'Dormant', 'closed' => 'Closed'];
?>

<?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <?php if ($msg = Session::flash('success')): ?>
        <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <?php if ($msg = Session::flash('error')): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <?php if ($msg = Session::flash('info')): ?>
        <div class="alert alert-info"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-bank me-2"></i>Savings Overview</span>
            <button type="button" onclick="shareCurrentSaversWhatsApp()" class="btn btn-sm" style="background:#25D366;color:#fff;border:none;">
                <i class="bi bi-whatsapp me-1"></i>Share Current Savers
            </button>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0" id="saversOverviewTable">
                <thead class="table-light">
                    <tr><th>Type</th><th class="text-end">Accounts</th><th class="text-end">Balance</th></tr>
                </thead>
                <tbody>
                    <?php $grandBalance = 0; $grandCount = 0; ?>
                    <?php foreach ($typeLabels as $type => $label): ?>
                        <?php $t = $typeTotals[$type]; $grandBalance += $t['balance']; $grandCount += $t['count']; ?>
                        <tr>
                            <td><?= htmlspecialchars($label) ?></td>
                            <td class="text-end"><?= number_format($t['count']) ?></td>
                            <td class="text-end">Shs <?= number_format($t['balance'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr><th>Total</th><th class="text-end"><?= number_format($grandCount) ?></th><th class="text-end">Shs <?= number_format($grandBalance, 2) ?></th></tr>
                </tfoot>
            </table>
        </div>
    </div>

    <script>
    // Shares only the aggregate overview already visible on this page (counts
    // and balances by account type) -- never a per-member list -- so this
    // needs no new backend endpoint and cannot leak individual member data.
    function shareCurrentSaversWhatsApp() {
        const rows = document.querySelectorAll('#saversOverviewTable tbody tr');
        let text = 'Current Savers Summary — <?= date('d M Y') ?>\n\n';
        rows.forEach(r => {
            const cells = r.querySelectorAll('td');
            text += `${cells[0].textContent.trim()}: ${cells[1].textContent.trim()} accounts, ${cells[2].textContent.trim()}\n`;
        });
        const totalRow = document.querySelector('#saversOverviewTable tfoot tr');
        const totalCells = totalRow.querySelectorAll('th');
        text += `\nTotal: ${totalCells[1].textContent.trim()} accounts, ${totalCells[2].textContent.trim()}`;
        window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank');
    }
    </script>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-list-ul me-2"></i>Savings Accounts</span>
            <?php if ($canWrite): ?>
            <a href="<?= APP_URL ?>/index.php?page=savings-account-open" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-circle me-1"></i> Open Savings Account
            </a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <div class="btn-group btn-group-sm filter-pills mb-3" role="group">
                <a href="<?= APP_URL ?>/index.php?page=savings-accounts" class="btn <?= $filters['type'] === '' ? 'active' : '' ?>">All</a>
                <?php foreach ($typeLabels as $type => $label): ?>
                    <a href="<?= APP_URL ?>/index.php?page=savings-accounts&type=<?= $type ?>" class="btn <?= $filters['type'] === $type ? 'active' : '' ?>"><?= htmlspecialchars(explode(' ', $label)[0]) ?></a>
                <?php endforeach; ?>
            </div>

            <form method="GET" action="<?= APP_URL ?>/index.php" class="row g-2 mb-3">
                <input type="hidden" name="page" value="savings-accounts">
                <input type="hidden" name="type" value="<?= htmlspecialchars($filters['type']) ?>">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Search account or member" value="<?= htmlspecialchars($filters['search']) ?>">
                </div>
                <div class="col-md-3">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Statuses</option>
                        <?php foreach ($statusLabels as $s => $label): ?>
                            <option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-outline-primary">Filter</button>
                    <a href="<?= APP_URL ?>/index.php?page=savings-accounts" class="btn btn-sm btn-outline-secondary">Clear</a>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Account No.</th>
                            <th>Holder</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th class="text-end">Balance</th>
                            <th>Opened</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($accounts)): ?>
                            <tr><td colspan="7" class="text-center text-muted">No savings accounts found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($accounts as $a): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($a['account_number']) ?></strong></td>
                                    <td><?= htmlspecialchars($a['holder_label']) ?></td>
                                    <td><?= htmlspecialchars($typeLabels[$a['account_type']] ?? $a['account_type']) ?></td>
                                    <td><?= htmlspecialchars($statusLabels[$a['status']] ?? $a['status']) ?></td>
                                    <td class="text-end">Shs <?= number_format((float)$a['balance'], 2) ?></td>
                                    <td><?= date('d M Y', strtotime($a['opened_date'])) ?></td>
                                    <td class="text-end">
                                        <a href="<?= APP_URL ?>/index.php?page=savings-account-view&id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                                        <a href="<?= APP_URL ?>/index.php?page=savings-account-statement&id=<?= $a['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">Statement</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
