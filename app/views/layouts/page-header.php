<?php
/**
 * Page header — breadcrumb strip.
 * Views set $breadcrumbs = [['label' => 'Home', 'url' => '...'], ...]
 */
$breadcrumbs = $breadcrumbs ?? [];
?>
<?php if (!empty($breadcrumbs)): ?>
<nav aria-label="breadcrumb" class="mt-1">
    <ol class="breadcrumb">
        <li class="breadcrumb-item">
            <a href="<?= APP_URL ?>/index.php?page=dashboard">
                <i class="bi bi-house-door me-1"></i>Home
            </a>
        </li>
        <?php foreach ($breadcrumbs as $i => $crumb): ?>
            <?php $isLast = ($i === array_key_last($breadcrumbs)); ?>
            <?php if ($isLast): ?>
                <li class="breadcrumb-item active" aria-current="page">
                    <?= htmlspecialchars($crumb['label'] ?? '') ?>
                </li>
            <?php else: ?>
                <li class="breadcrumb-item">
                    <a href="<?= htmlspecialchars($crumb['url'] ?? '#') ?>">
                        <?= htmlspecialchars($crumb['label'] ?? '') ?>
                    </a>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ol>
</nav>
<?php endif; ?>
