<?php
/**
 * Coming Soon — placeholder for unbuilt modules.
 * Variables: $module (array), $page (string)
 */
$color = $module['color'];
$icon  = $module['icon'];
$title = $module['title'];
$desc  = $module['desc'];

// All planned modules for the "what's coming" grid
$planned = [
    ['contributions', 'bi-arrow-down-circle-fill', 'success',   'Contributions'],
    ['loans',         'bi-bank2',                  'warning',   'Loans'],
    ['expenses',      'bi-receipt',                'danger',    'Expenses'],
    ['investments',   'bi-graph-up-arrow',         'info',      'Investments'],
    ['reports',       'bi-file-bar-graph-fill',    'primary',   'Reports'],
    ['settings',      'bi-gear-fill',              'secondary', 'Settings'],
];
?>

<!-- Page heading -->
<div class="d-flex align-items-center justify-content-between mt-4 mb-4">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi <?= $icon ?> me-2 text-<?= $color ?>"></i><?= htmlspecialchars($title) ?>
        </h1>
        <p class="text-muted mb-0 small"><?= htmlspecialchars($desc) ?></p>
    </div>
    <a href="<?= APP_URL ?>/index.php?page=dashboard"
       class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Dashboard
    </a>
</div>

<!-- Main coming soon card -->
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm text-center py-5 px-4">

            <!-- Animated icon -->
            <div class="coming-soon-icon mx-auto mb-4 bg-<?= $color ?>-subtle text-<?= $color ?>">
                <i class="bi <?= $icon ?>"></i>
            </div>

            <h2 class="fw-bold mb-2"><?= htmlspecialchars($title) ?> Module</h2>
            <p class="text-muted mb-1">This module is currently under development.</p>
            <p class="text-muted small mb-4"><?= htmlspecialchars($desc) ?></p>

            <!-- Progress bar (visual only) -->
            <div class="mb-4 px-4">
                <div class="d-flex justify-content-between small text-muted mb-1">
                    <span>Development progress</span>
                    <span>Coming soon</span>
                </div>
                <div class="progress" style="height:6px;border-radius:99px;">
                    <div class="progress-bar bg-<?= $color ?> progress-bar-striped progress-bar-animated"
                         role="progressbar" style="width: 35%"></div>
                </div>
            </div>

            <div class="d-flex gap-3 justify-content-center flex-wrap">
                <a href="<?= APP_URL ?>/index.php?page=dashboard"
                   class="btn btn-primary px-4">
                    <i class="bi bi-speedometer2 me-2"></i>Back to Dashboard
                </a>
                <a href="<?= APP_URL ?>/index.php?page=members"
                   class="btn btn-outline-primary px-4">
                    <i class="bi bi-people me-2"></i>View Members
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Other planned modules grid -->
<div class="mt-5">
    <h6 class="text-muted text-uppercase fw-bold small mb-3 text-center">
        <i class="bi bi-grid me-1"></i>Other Modules
    </h6>
    <div class="row g-3 justify-content-center">
        <?php foreach ($planned as [$slug, $pIcon, $pColor, $pTitle]): ?>
        <?php $isCurrent = ($slug === $page); ?>
        <div class="col-lg-2 col-md-3 col-sm-4 col-6">
            <a href="<?= APP_URL ?>/index.php?page=<?= $slug ?>"
               class="card border-0 shadow-sm text-center p-3 text-decoration-none
                      <?= $isCurrent ? 'opacity-50 pe-none' : '' ?>"
               style="transition:transform .15s;">
                <div class="fs-2 mb-2 text-<?= $pColor ?>">
                    <i class="bi <?= $pIcon ?>"></i>
                </div>
                <div class="small fw-semibold text-dark"><?= $pTitle ?></div>
                <?php if ($isCurrent): ?>
                <div class="badge bg-<?= $pColor ?>-subtle text-<?= $pColor ?> mt-1" style="font-size:.65rem;">Current</div>
                <?php else: ?>
                <div class="badge bg-light text-muted mt-1" style="font-size:.65rem;">Coming Soon</div>
                <?php endif; ?>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>
