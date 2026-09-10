<?php
/**
 * Shared print header for accounting reports: club name/logo, report title,
 * the selected reporting period in words, and a generated-on/by line.
 *
 *   $club          array   ['club_name'=>..,'club_logo'=>..,'club_address'=>..] from `settings`
 *   $reportTitle   string  e.g. "Trial Balance"
 *   $periodLabel   string  human-readable description of the active filters
 */
$club = $club ?? [];
$reportTitle = $reportTitle ?? '';
$periodLabel = $periodLabel ?? 'All periods';
$clubName = $club['club_name'] ?? APP_NAME;
$clubLogo = $club['club_logo'] ?? '';
$clubAddress = $club['club_address'] ?? '';
$generatedBy = Session::get('user_name') ?? 'System User';
?>
<div class="report-print-header d-flex align-items-center justify-content-between mb-0 gap-3">
    <div>
        <div class="fw-bold" style="font-size:1.05rem;"><?= htmlspecialchars($clubName) ?></div>
        <?php if ($clubAddress): ?>
            <div class="text-muted small"><?= htmlspecialchars($clubAddress) ?></div>
        <?php endif; ?>
    </div>
    <div class="text-end">
        <?php if ($reportTitle): ?>
            <div class="fw-bold text-dark"><?= htmlspecialchars($reportTitle) ?></div>
        <?php endif; ?>
        <div class="small text-muted"><?= htmlspecialchars($periodLabel) ?></div>
        <div class="small text-muted">Generated <?= date('d M Y H:i') ?> by <?= htmlspecialchars($generatedBy) ?></div>
    </div>
</div>
