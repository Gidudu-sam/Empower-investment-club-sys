<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/public/css/app.css?v=<?= time() ?>">
    <meta name="theme-color" content="#0b1f3a">
</head>
<body>
<?php
// The portal previously had its own lightweight top-navbar-only shell,
// visually disconnected from the rest of the app (no logo, no sidebar,
// generic Bootstrap navbar). This reuses the exact same sb-sidenav /
// sb-topnav / layoutSidenav shell and CSS (public/css/app.css) that every
// staff role already uses, so a member sees the same Empower chrome, logo
// and brand mark as staff -- just with a member-only nav list. No backend,
// route, or authorization logic here; purely the visual shell.
$portalNav = [
    ['page' => 'portal-home',       'icon' => 'bi-house-door',        'label' => 'Home'],
    ['page' => 'portal-profile',    'icon' => 'bi-person',            'label' => 'My Profile'],
    ['page' => 'portal-savings',    'icon' => 'bi-piggy-bank',        'label' => 'My Savings'],
    ['page' => 'portal-loans',      'icon' => 'bi-cash-coin',         'label' => 'My Loans'],
    ['page' => 'portal-repayments', 'icon' => 'bi-receipt',           'label' => 'My Repayments'],
    ['page' => 'portal-fees',       'icon' => 'bi-tag',               'label' => 'My Fees'],
    ['page' => 'portal-statement',  'icon' => 'bi-file-earmark-text', 'label' => 'My Statement'],
];
$currentPage  = $_GET['page'] ?? 'portal-home';
// loan-view.php drills into a single loan from My Loans -- keep that item
// highlighted as active rather than showing nothing selected.
if ($currentPage === 'portal-loan-view') { $currentPage = 'portal-loans'; }

$memberName   = htmlspecialchars(Session::get('user_name', 'Member'));
$memberNumber = isset($member['member_number']) ? htmlspecialchars($member['member_number']) : '';
$userInitial  = strtoupper(substr(Session::get('user_name', 'M'), 0, 1));

$activeNavEntry = null;
foreach ($portalNav as $nav) {
    if ($nav['page'] === $currentPage) { $activeNavEntry = $nav; break; }
}
$pageLabel = $activeNavEntry['label'] ?? ucfirst(str_replace(['portal-', '-'], ['', ' '], $currentPage));
?>
<nav class="sb-topnav navbar navbar-expand navbar-dark">
    <div class="d-flex align-items-center gap-2">
        <button class="btn btn-link px-2" id="sidebarToggle" aria-label="Toggle sidebar">
            <i class="bi bi-list" style="font-size:1.1rem;"></i>
        </button>
        <span style="font-size:.78rem;color:var(--ink);font-weight:500;"><?= htmlspecialchars($pageLabel) ?></span>
    </div>

    <ul class="navbar-nav ms-auto align-items-center gap-1 flex-row">
        <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle d-flex align-items-center gap-2 px-2"
               href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="text-decoration:none;">
                <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold"
                     style="width:32px;height:32px;background:var(--navy-900);color:#fff;font-size:.68rem;flex-shrink:0;">
                    <?= $userInitial ?>
                </div>
                <div class="d-none d-md-block" style="line-height:1.15;">
                    <div style="font-size:.75rem;font-weight:600;color:var(--ink);"><?= $memberName ?></div>
                    <div style="font-size:.6rem;color:var(--slate-soft);"><?= $memberNumber !== '' ? 'Member ' . $memberNumber : 'Member' ?></div>
                </div>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm border mt-1" style="min-width:180px;">
                <li><a class="dropdown-item small py-2" href="<?= APP_URL ?>/index.php?page=portal-profile"><i class="bi bi-person me-2"></i>My Profile</a></li>
                <li><a class="dropdown-item small py-2" href="<?= APP_URL ?>/index.php?page=portal-change-password"><i class="bi bi-key me-2"></i>Change Password</a></li>
                <li><hr class="dropdown-divider my-1"></li>
                <li><a class="dropdown-item small py-2" style="color:var(--rust);" href="<?= APP_URL ?>/index.php?page=logout"><i class="bi bi-box-arrow-right me-2"></i>Sign Out</a></li>
            </ul>
        </li>
    </ul>
</nav>

<div id="layoutSidenav">
    <div id="layoutSidenav_nav">
        <nav class="sb-sidenav sb-sidenav-dark">
            <div style="padding:1.25rem 1.25rem .75rem;display:flex;align-items:center;gap:.65rem;">
                <img src="<?= APP_URL ?>/public/images/logo.png" alt="Empower Logo" style="width:36px;height:36px;border-radius:.4rem;object-fit:contain;">
                <div style="line-height:1.15;overflow:hidden;">
                    <div style="font-size:.82rem;font-weight:700;color:#fff;white-space:nowrap;">Empower</div>
                    <div style="font-size:.54rem;font-weight:500;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.1em;">Member Portal</div>
                </div>
            </div>

            <div class="sb-sidenav-menu">
                <div class="nav">
                    <?php foreach ($portalNav as $nav): ?>
                    <a class="nav-link <?= $currentPage === $nav['page'] ? 'active' : '' ?>"
                       href="<?= APP_URL ?>/index.php?page=<?= $nav['page'] ?>">
                        <div class="sb-nav-link-icon"><i class="bi <?= $nav['icon'] ?>"></i></div>
                        <?= htmlspecialchars($nav['label']) ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="sb-sidenav-footer">
                <div class="small">Logged in as:</div>
                <strong><?= $memberName ?></strong>
            </div>
        </nav>
    </div>

    <div id="layoutSidenav_content">
        <main>
            <div class="container-fluid px-4 py-4">
                <?= $content ?>
            </div>
        </main>
        <?php include VIEW_PATH . '/layouts/footer.php'; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/public/js/app.js"></script>
</body>
</html>
