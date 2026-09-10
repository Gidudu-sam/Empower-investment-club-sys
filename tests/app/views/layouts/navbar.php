<?php
$userName   = htmlspecialchars(Session::get('user_name', 'User'));
$userRole   = htmlspecialchars(Session::get('user_role', 'member'));
$userInitial = strtoupper(substr(Session::get('user_name', 'U'), 0, 1));
$currentPage = $_GET['page'] ?? 'dashboard';
$pageLabel   = ucfirst(str_replace('-', ' ', $currentPage));

// Stage 12-C: mark-read/mark-all/delete are now POST+CSRF (previously bare
// GET links, per the pre-Stage-12-C audit). The bell dropdown is rendered
// on every page regardless of which controller handled the request, so the
// token is ensured here directly rather than requiring every controller in
// the app to remember to pass one in -- this reads/writes the exact same
// session-wide `csrf_token` key every other controller's getCsrf() already
// uses, not a second token system.
if (!Session::has('csrf_token')) {
    Session::set('csrf_token', bin2hex(random_bytes(32)));
}
$notifCsrfToken = Session::get('csrf_token');
?>
<nav class="sb-topnav navbar navbar-expand navbar-dark">

    <!-- Breadcrumb / page context -->
    <div class="d-flex align-items-center gap-2">
        <button class="btn btn-link px-2" id="sidebarToggle" aria-label="Toggle sidebar">
            <i class="bi bi-list" style="font-size:1.1rem;"></i>
        </button>
        <span style="font-size:.78rem;color:var(--ink);font-weight:500;"><?= $pageLabel ?></span>
    </div>

    <!-- Center: search -->
    <div class="mx-auto d-none d-md-block" style="width:260px;">
        <div class="input-group input-group-sm">
            <span class="input-group-text border-0" style="background:var(--paper);color:var(--slate-soft);"><i class="bi bi-search" style="font-size:.72rem;"></i></span>
            <input type="text" class="form-control border-0" style="background:var(--paper);font-size:.75rem;" placeholder="Search members, loans...">
        </div>
    </div>

    <!-- Right items -->
    <ul class="navbar-nav ms-auto align-items-center gap-1 flex-row">

        <!-- Notifications -->
        <li class="nav-item dropdown" data-csrf-token="<?= htmlspecialchars($notifCsrfToken) ?>">
            <a class="nav-link px-2 position-relative" href="#" role="button"
               data-bs-toggle="dropdown" aria-expanded="false" id="notifDropdown" aria-label="Notifications">
                <i class="bi bi-bell" style="font-size:1rem;color:var(--slate);" aria-hidden="true"></i>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none"
                      id="notif-badge" style="font-size:.5rem;padding:2px 4px;">0</span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm border p-0 mt-1" style="min-width:320px;max-width:90vw;max-height:400px;overflow-y:auto;" id="notifMenu">
                <li class="dropdown-header d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
                    <span class="fw-semibold" style="font-size:.75rem;">Notifications</span>
                    <button type="button" class="btn btn-link text-decoration-none small p-0 border-0" id="markAllBtn" style="display:none;color:var(--green);font-size:.68rem;">
                        Mark All Read
                    </button>
                </li>
                <li id="notifList">
                    <div class="text-center py-3 text-muted small" style="font-size:.72rem;">
                        No new notifications
                    </div>
                </li>
                <li class="border-top">
                    <a href="<?= APP_URL ?>/index.php?page=notifications" class="dropdown-item text-center py-2 fw-semibold" style="font-size:.72rem;color:var(--brand-navy);">
                        View All Notifications
                    </a>
                </li>
            </ul>
        </li>

        <!-- User -->
        <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle d-flex align-items-center gap-2 px-2"
               href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="text-decoration:none;">
                <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold"
                     style="width:32px;height:32px;background:var(--navy-900);color:#fff;font-size:.68rem;flex-shrink:0;">
                    <?= $userInitial ?>
                </div>
                <div class="d-none d-md-block" style="line-height:1.15;">
                    <div style="font-size:.75rem;font-weight:600;color:var(--ink);"><?= $userName ?></div>
                    <div style="font-size:.6rem;color:var(--slate-soft);text-transform:capitalize;"><?= htmlspecialchars(str_replace('_', ' ', $userRole)) ?></div>
                </div>
            </a>
            <?php
            // "My Profile"/"Settings" both pointed at settings-general (admin-only)
            // for every role -- a guaranteed "Access denied" -> back to dashboard
            // for every non-admin user who clicked either one. No dedicated
            // self-service profile page exists yet, so each item now only
            // appears when it actually leads somewhere that role can reach,
            // rather than a dead end.
            $accountMenuPage = match (true) {
                Session::hasRole(['admin']) => 'settings-general',
                Session::hasRole(['system_admin']) => 'settings-users', // office_admin's access here was explicitly revoked
                default => null,
            };
            ?>
            <?php
            // Stage 14-B.1: admin's Settings item above still points at
            // settings-general (unchanged -- General Settings must stay
            // reachable from the navbar for admin). Rather than repointing
            // that link to settings-users (which would strand admin away
            // from General Settings) or duplicating it for system_admin
            // (whose Settings item already targets settings-users), add one
            // distinct "User Accounts" item for admin only -- the one role
            // here whose existing Settings link does not already lead to
            // Users. This is option (a) from the Stage 14-B.1 brief,
            // applied narrowly to avoid a redundant duplicate entry in
            // system_admin's dropdown.
            $showUserAccountsMenuItem = Session::hasRole(['admin']);
            ?>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm border mt-1" style="min-width:180px;">
                <?php if ($accountMenuPage): ?>
                <li><a class="dropdown-item small py-2" href="<?= APP_URL ?>/index.php?page=<?= $accountMenuPage ?>"><i class="bi bi-gear me-2"></i>Settings</a></li>
                <?php endif; ?>
                <?php if ($showUserAccountsMenuItem): ?>
                <li><a class="dropdown-item small py-2" href="<?= APP_URL ?>/index.php?page=settings-users"><i class="bi bi-person-gear me-2"></i>User Accounts</a></li>
                <?php endif; ?>
                <?php if ($accountMenuPage || $showUserAccountsMenuItem): ?>
                <li><hr class="dropdown-divider my-1"></li>
                <?php endif; ?>
                <li><a class="dropdown-item small py-2" style="color:var(--rust);" href="<?= APP_URL ?>/index.php?page=logout"><i class="bi bi-box-arrow-right me-2"></i>Sign Out</a></li>
            </ul>
        </li>
    </ul>
</nav>
