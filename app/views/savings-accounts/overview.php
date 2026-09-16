<?php
$pageTitle = $pageTitle ?? 'Savings Accounts';
$title = $pageTitle;
$icon  = 'bi-bank';
// Matches SavingsAccountController::requireWriteAccess() exactly -- office_admin
// already has backend authority to open accounts, this UI just wasn't showing it.
$canWrite = Session::hasRole(['admin', 'treasurer', 'office_admin']);

$typeLabels = ['compulsory' => 'Compulsory Savings', 'voluntary' => 'Voluntary Savings', 'joint' => 'Joint Savings', 'corporate' => 'Corporate Savings', 'fixed_deposit' => 'Fixed Deposit'];
$statusLabels = ['active' => 'Active', 'dormant' => 'Dormant', 'closed' => 'Closed'];

// Define color scheme for each savings type
$typeColors = [
    'compulsory' => ['bg' => '#EBF5FF', 'text' => '#1E40AF', 'icon' => 'bi-piggy-bank'],
    'voluntary' => ['bg' => '#E0F2F7', 'text' => '#0E7490', 'icon' => 'bi-wallet2'],
    'joint' => ['bg' => '#F3E8FF', 'text' => '#7C3AED', 'icon' => 'bi-people'],
    'corporate' => ['bg' => '#FFF1E6', 'text' => '#C2410C', 'icon' => 'bi-building'],
    'fixed_deposit' => ['bg' => '#FEF3C7', 'text' => '#B45309', 'icon' => 'bi-lock'],
];
?>

<style>
    /* Metric cards - match system stat-card style */
    .metric-card {
        background: var(--surface);
        border-radius: .45rem;
        padding: 1rem;
        border: 1px solid var(--hairline);
        transition: border-color .15s ease;
        text-decoration: none;
        display: block;
    }
    .metric-card:hover {
        border-color: #cdd1de;
    }
    .metric-card.empty {
        opacity: 0.5;
    }
    .metric-icon {
        width: 38px;
        height: 38px;
        border-radius: .35rem;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        margin-bottom: .75rem;
    }
    .metric-value {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 1.3rem;
        font-weight: 600;
        line-height: 1.2;
        margin-bottom: .15rem;
        color: var(--ink);
        font-variant-numeric: tabular-nums;
    }
    .metric-label {
        font-size: .58rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .04em;
        color: var(--slate-soft);
        margin-bottom: .35rem;
    }
    .metric-count {
        font-size: .64rem;
        color: var(--slate-soft);
        margin-top: 2px;
    }
    
    /* Chart containers */
    .chart-card {
        background: var(--surface);
        border-radius: .45rem;
        padding: 1rem;
        border: 1px solid var(--hairline);
        height: 100%;
    }
    .chart-title {
        font-size: .72rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .04em;
        color: var(--slate-soft);
        margin-bottom: .75rem;
    }
    
    /* Segmented control for tabs */
    .segmented-control {
        display: inline-flex;
        background: var(--paper);
        border-radius: .35rem;
        padding: 3px;
        gap: 2px;
    }
    .segmented-control .btn {
        border: none;
        background: transparent;
        color: var(--slate);
        font-weight: 500;
        font-size: .72rem;
        padding: .4rem .75rem;
        border-radius: .3rem;
        transition: all .15s;
    }
    .segmented-control .btn:hover {
        color: var(--ink);
    }
    .segmented-control .btn.active {
        background: var(--surface);
        color: var(--ink);
        box-shadow: 0 1px 2px rgba(0,0,0,0.06);
    }
    
    /* Filter toolbar */
    .filter-toolbar {
        background: var(--surface);
        border-radius: .45rem;
        padding: .75rem;
        border: 1px solid var(--hairline);
        display: flex;
        gap: .5rem;
        align-items: center;
        flex-wrap: wrap;
    }
    
    /* Modern table */
    .modern-table {
        background: var(--surface);
        border-radius: .45rem;
        overflow: hidden;
        border: 1px solid var(--hairline);
    }
    .modern-table table {
        margin-bottom: 0;
    }
    .modern-table thead {
        background: var(--paper);
    }
    .modern-table thead th {
        border-bottom: 1px solid var(--hairline);
        font-weight: 600;
        font-size: .58rem;
        text-transform: uppercase;
        letter-spacing: .04em;
        color: var(--slate-soft);
        padding: .65rem .75rem;
    }
    .modern-table tbody td {
        padding: .75rem;
        border-bottom: 1px solid var(--hairline);
        font-size: .72rem;
    }
    .modern-table tbody tr:last-child td {
        border-bottom: none;
    }
    .modern-table tbody tr:hover {
        background: var(--paper);
    }
    
    /* Action buttons */
    .btn-action {
        padding: .35rem .65rem;
        font-size: .72rem;
        border-radius: .3rem;
        font-weight: 500;
    }
    
    /* Status badges */
    .status-badge {
        padding: .2rem .5rem;
        border-radius: .3rem;
        font-size: .64rem;
        font-weight: 500;
    }
    .status-active {
        background: var(--green-soft);
        color: var(--green);
    }
    .status-dormant {
        background: var(--gold-soft);
        color: var(--gold-deep);
    }
    .status-closed {
        background: var(--paper);
        color: var(--slate);
    }
    
    /* Mobile adjustments */
    @media (max-width: 575.98px) {
        .metric-card { padding: .65rem; }
        .metric-icon { width: 26px; height: 26px; font-size: .85rem; }
        .metric-value { font-size: .8rem !important; }
    }
</style>

<?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <?php if ($msg = Session::flash('success')): ?>
        <div class="alert alert-success border-0 rounded-3"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <?php if ($msg = Session::flash('error')): ?>
        <div class="alert alert-danger border-0 rounded-3"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <?php if ($msg = Session::flash('info')): ?>
        <div class="alert alert-info border-0 rounded-3"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <!-- Metric Cards Grid -->
    <div class="row g-3 mb-4">
        <?php 
        $grandBalance = 0; 
        $grandCount = 0; 
        foreach ($typeLabels as $type => $label): 
            $t = $typeTotals[$type]; 
            $grandBalance += $t['balance']; 
            $grandCount += $t['count'];
            $color = $typeColors[$type];
            $isEmpty = $t['count'] == 0;
        ?>
        <div class="col-lg-4 col-md-6">
            <div class="metric-card <?= $isEmpty ? 'empty' : '' ?>">
                <div class="metric-icon" style="background: <?= $color['bg'] ?>; color: <?= $color['text'] ?>;">
                    <i class="bi <?= $color['icon'] ?>"></i>
                </div>
                <div class="metric-label"><?= htmlspecialchars($label) ?></div>
                <div class="metric-value" style="color: <?= $color['text'] ?>;">
                    <?php if ($isEmpty): ?>
                        <span style="font-size: 1rem; font-weight: 500; color: #9CA3AF;">No accounts yet</span>
                    <?php else: ?>
                        Shs <?= number_format($t['balance'], 0) ?>
                    <?php endif; ?>
                </div>
                <div class="metric-count">
                    <?= number_format($t['count']) ?> account<?= $t['count'] != 1 ? 's' : '' ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Charts Row -->
    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <div class="chart-card">
                <div class="chart-title">Total Savings Balance</div>
                <div style="height: 200px; display: flex; align-items: center; justify-content: center; color: var(--slate-soft);">
                    <div class="text-center">
                        <i class="bi bi-graph-up" style="font-size: 2.5rem; opacity: 0.25;"></i>
                        <div style="font-size: .72rem; margin-top: .5rem;">Growth chart - Coming soon</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="chart-card">
                <div class="chart-title">Distribution by Type</div>
                <div style="height: 200px; display: flex; align-items: center; justify-content: center;">
                    <div class="text-center">
                        <div style="font-family: 'Space Grotesk', sans-serif; font-size: 2rem; font-weight: 700; color: var(--ink); font-variant-numeric: tabular-nums;"><?= number_format($grandCount) ?></div>
                        <div style="font-size: .64rem; color: var(--slate-soft); margin-top: 2px;">Total Accounts</div>
                        <div style="font-family: 'Space Grotesk', sans-serif; font-size: 1.15rem; font-weight: 600; color: var(--green); margin-top: .75rem; font-variant-numeric: tabular-nums;">
                            Shs <?= number_format($grandBalance, 0) ?>
                        </div>
                        <div style="font-size: .64rem; color: var(--slate-soft);">Total Balance</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Accounts Table -->
    <div class="modern-table" id="table">
        <div class="card-header d-flex justify-content-between align-items-center bg-white" style="padding: .875rem 1rem; border-bottom: 1px solid var(--hairline);">
            <div style="font-weight: 600; font-size: .82rem; color: var(--ink);">
                <i class="bi bi-list-ul me-2"></i>Savings Accounts
            </div>
            <div class="d-flex gap-2">
                <button type="button" onclick="shareCurrentSaversWhatsApp()" class="btn btn-sm" style="background:#25D366;color:#fff;border:none; border-radius: .3rem; font-size: .72rem; padding: .35rem .65rem;">
                    <i class="bi bi-whatsapp me-1"></i>Share Overview
                </button>
                <?php if ($canWrite): ?>
                <a href="<?= APP_URL ?>/index.php?page=savings-account-open" class="btn btn-sm btn-primary" style="border-radius: .3rem; font-size: .72rem; padding: .35rem .65rem;">
                    <i class="bi bi-plus-circle me-1"></i> Open Account
                </a>
                <?php endif; ?>
            </div>
        </div>
        
        <div style="padding: 1rem;">
            <!-- Type Filter Tabs -->
            <div class="mb-3">
                <div class="segmented-control">
                    <a href="<?= APP_URL ?>/index.php?page=savings-accounts#table" class="btn <?= $filters['type'] === '' ? 'active' : '' ?>">All</a>
                    <?php foreach ($typeLabels as $type => $label): ?>
                        <a href="<?= APP_URL ?>/index.php?page=savings-accounts&type=<?= $type ?>#table" class="btn <?= $filters['type'] === $type ? 'active' : '' ?>"><?= htmlspecialchars(explode(' ', $label)[0]) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Filter Toolbar -->
            <form method="GET" action="<?= APP_URL ?>/index.php" class="filter-toolbar mb-3">
                <input type="hidden" name="page" value="savings-accounts">
                <input type="hidden" name="type" value="<?= htmlspecialchars($filters['type']) ?>">
                
                <div style="flex: 1; min-width: 200px; max-width: 300px;">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Search account or member..." value="<?= htmlspecialchars($filters['search']) ?>" style="border-radius: .3rem; font-size: .72rem;">
                </div>
                
                <div style="min-width: 150px;">
                    <select name="status" class="form-select form-select-sm" style="border-radius: .3rem; font-size: .72rem;">
                        <option value="">All Statuses</option>
                        <?php foreach ($statusLabels as $s => $label): ?>
                            <option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-sm btn-primary" style="border-radius: .3rem; font-size: .72rem; padding: .35rem .65rem;">Filter</button>
                <a href="<?= APP_URL ?>/index.php?page=savings-accounts" class="btn btn-sm btn-outline-secondary" style="border-radius: .3rem; font-size: .72rem; padding: .35rem .65rem;">Clear</a>
            </form>

            <!-- Accounts Table -->
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
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
                            <tr><td colspan="7" class="text-center py-5" style="color: var(--slate-soft);">
                                <i class="bi bi-inbox" style="font-size: 3rem; opacity: 0.3; display: block; margin-bottom: 0.5rem;"></i>
                                No savings accounts found.
                            </td></tr>
                        <?php else: ?>
                            <?php foreach ($accounts as $a): ?>
                                <?php 
                                $statusClass = match($a['status']) {
                                    'active' => 'status-active',
                                    'dormant' => 'status-dormant',
                                    'closed' => 'status-closed',
                                    default => 'status-closed'
                                };
                                ?>
                                <tr>
                                    <td><strong style="color: #1F2937;"><?= htmlspecialchars($a['account_number']) ?></strong></td>
                                    <td><?= htmlspecialchars($a['holder_label']) ?></td>
                                    <td><span style="font-size: 0.813rem; color: #6B7280;"><?= htmlspecialchars($typeLabels[$a['account_type']] ?? $a['account_type']) ?></span></td>
                                    <td><span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($statusLabels[$a['status']] ?? $a['status']) ?></span></td>
                                    <td class="text-end"><strong style="color: #059669;">Shs <?= number_format((float)$a['balance'], 2) ?></strong></td>
                                    <td style="font-size: 0.813rem; color: #6B7280;"><?= date('d M Y', strtotime($a['opened_date'])) ?></td>
                                    <td class="text-end">
                                        <a href="<?= APP_URL ?>/index.php?page=savings-account-view&id=<?= $a['id'] ?>" class="btn btn-action btn-outline-primary">View</a>
                                        <a href="<?= APP_URL ?>/index.php?page=savings-account-statement&id=<?= $a['id'] ?>" target="_blank" class="btn btn-action btn-outline-secondary">Statement</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
    // Shares only the aggregate overview already visible on this page (counts
    // and balances by account type) -- never a per-member list -- so this
    // needs no new backend endpoint and cannot leak individual member data.
    function shareCurrentSaversWhatsApp() {
        let text = 'Current Savers Summary — <?= date('d M Y') ?>\n\n';
        <?php foreach ($typeLabels as $type => $label): ?>
        <?php $t = $typeTotals[$type]; ?>
        text += '<?= $label ?>: <?= number_format($t['count']) ?> accounts, Shs <?= number_format($t['balance'], 2) ?>\n';
        <?php endforeach; ?>
        text += '\nTotal: <?= number_format($grandCount) ?> accounts, Shs <?= number_format($grandBalance, 2) ?>';
        window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank');
    }
    </script>