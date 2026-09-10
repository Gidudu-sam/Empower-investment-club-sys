<?php $userName = htmlspecialchars(Session::get('user_name', 'User')); ?>

<!-- ── Page heading ─────────────────────────────────────────── -->
<div class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-sm-between gap-2 mt-4 mb-4">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800" style="font-family:'Space Grotesk',sans-serif;">
            Dashboard
        </h1>
        <p class="text-muted mb-0" style="font-size:.82rem;">Welcome back, <strong><?= $userName ?></strong> — System Administration</p>
    </div>
    <span class="badge bg-primary-subtle text-primary rounded-pill px-3 py-2" style="font-size:.72rem;">
        <i class="bi bi-calendar3 me-1"></i><?= date('l, d F Y') ?>
    </span>
</div>

<div class="alert alert-info small mb-4">
    <i class="bi bi-info-circle me-1"></i>
    This workspace covers technical/system administration only. System Administrator does not have financial transaction authority — savings, loans, withdrawals, vouchers, and member balances are handled by other roles.
</div>

<!-- ── ROW 1: Stat cards ───────────────────────────────────────── -->
<div class="row g-3 mb-3">

    <div class="col-6 col-xl-3 col-lg-4">
        <a href="<?= APP_URL ?>/index.php?page=settings-users" class="text-decoration-none">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-label">Total Users</span>
                    <span class="stat-dot stat-dot-green"></span>
                </div>
                <div class="stat-value" style="font-size:1.2rem"><?= number_format($totalUsers) ?></div>
                <div class="stat-sub"><?= number_format($activeUsers) ?> active</div>
            </div>
        </a>
    </div>

    <div class="col-6 col-xl-3 col-lg-4">
        <a href="<?= APP_URL ?>/index.php?page=settings-database" class="text-decoration-none">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-label">Database Backups</span>
                    <span class="stat-dot <?= $backupCount > 0 ? 'stat-dot-green' : 'stat-dot-rust' ?>"></span>
                </div>
                <div class="stat-value" style="font-size:1.2rem"><?= number_format($backupCount) ?></div>
                <div class="stat-sub">
                    <?= $lastBackup ? 'Last: ' . date('d M Y, g:i A', strtotime($lastBackup['created_at'])) : 'No backup on record' ?>
                </div>
            </div>
        </a>
    </div>

    <div class="col-6 col-xl-3 col-lg-4">
        <a href="<?= APP_URL ?>/index.php?page=settings-audit" class="text-decoration-none">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-label">Roles Configured</span>
                    <span class="stat-dot stat-dot-green"></span>
                </div>
                <div class="stat-value" style="font-size:1.2rem"><?= count($roleDistribution) ?></div>
                <div class="stat-sub">Across all staff accounts</div>
            </div>
        </a>
    </div>

    <div class="col-6 col-xl-3 col-lg-4">
        <a href="<?= APP_URL ?>/index.php?page=settings-audit" class="text-decoration-none">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-label">Recent Audit Entries</span>
                    <span class="stat-dot stat-dot-green"></span>
                </div>
                <div class="stat-value" style="font-size:1.2rem"><?= count($recentAudit) ?></div>
                <div class="stat-sub">Shown below</div>
            </div>
        </a>
    </div>

</div>

<!-- ── ROW 2: Role distribution + Quick Actions ───────────────── -->
<div class="row g-4 mb-4">

    <div class="col-xl-5">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-people-fill me-2 text-primary"></i>Users by Role</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                    <thead><tr><th class="ps-3">Role</th><th class="text-end pe-3">Users</th></tr></thead>
                    <tbody>
                        <?php if (empty($roleDistribution)): ?>
                        <tr><td colspan="2" class="text-center text-muted py-3 small">No users found.</td></tr>
                        <?php else: foreach ($roleDistribution as $label => $count): ?>
                        <tr>
                            <td class="ps-3 small"><?= htmlspecialchars($label) ?></td>
                            <td class="text-end pe-3 fw-semibold small"><?= $count ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-lightning-charge me-2 text-warning"></i>Quick Actions</h6>
            </div>
            <div class="card-body pt-3">
                <div class="row row-cols-2 row-cols-sm-1 g-2">
                    <div class="col">
                        <a href="<?= APP_URL ?>/index.php?page=settings-users" class="btn btn-primary d-flex align-items-center justify-content-center justify-content-sm-start gap-2 w-100 h-100 text-center text-sm-start">
                            <i class="bi bi-person-gear fs-5 flex-shrink-0"></i><span>Manage Users</span>
                        </a>
                    </div>
                    <div class="col">
                        <a href="<?= APP_URL ?>/index.php?page=settings-database" class="btn btn-outline-primary d-flex align-items-center justify-content-center justify-content-sm-start gap-2 w-100 h-100 text-center text-sm-start">
                            <i class="bi bi-database fs-5 flex-shrink-0"></i><span>Backup Database</span>
                        </a>
                    </div>
                    <div class="col">
                        <a href="<?= APP_URL ?>/index.php?page=settings-audit" class="btn btn-outline-secondary d-flex align-items-center justify-content-center justify-content-sm-start gap-2 w-100 h-100 text-center text-sm-start">
                            <i class="bi bi-journal-text fs-5 flex-shrink-0"></i><span>Audit Logs</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-journal-text me-2 text-secondary"></i>Recent Audit Activity</h6>
                <a href="<?= APP_URL ?>/index.php?page=settings-audit" class="btn btn-sm btn-outline-secondary">View All</a>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php if (empty($recentAudit)): ?>
                    <li class="list-group-item text-center text-muted py-3 small">No audit activity recorded yet.</li>
                    <?php else: foreach ($recentAudit as $log): ?>
                    <li class="list-group-item px-3 py-2">
                        <div class="small fw-semibold"><?= htmlspecialchars($log['user_name'] ?? 'System') ?> — <?= htmlspecialchars($log['action']) ?></div>
                        <div class="text-muted" style="font-size:.72rem;"><?= htmlspecialchars($log['description'] ?? '') ?> · <?= date('d M Y, g:i A', strtotime($log['created_at'])) ?></div>
                    </li>
                    <?php endforeach; endif; ?>
                </ul>
            </div>
        </div>
    </div>

</div>
