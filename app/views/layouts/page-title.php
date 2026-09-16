<?php
/**
 * Shared page-title header: icon + heading + subtitle + optional CTA markup.
 *
 *   $icon         string|null  Bootstrap icon class incl. any color utility, e.g. 'bi-piggy-bank-fill text-success'. Null = no icon.
 *   $iconStyle    string|null  optional inline style on the icon, e.g. 'color:var(--brand-orange)' — for the few files that color the icon inline rather than via utility class
 *   $title        string       Title text/HTML.
 *   $titleTag     string       'h3' (default) | 'h4'
 *   $titleClass   string       default 'mb-1 fw-bold text-gray-800'
 *   $titleStyle   string|null  optional inline style, e.g. 'color:var(--brand-navy)' — only the few files that need it
 *   $subtitle     string|null  subtitle text/HTML
 *   $subtitleTag  string       'p' (default) | 'small'
 *   $cta          string|null  raw pre-rendered CTA markup — printed as-is; the view builds its buttons exactly as it does today and just hands the string over
 *   $wrapperClass string       default 'mt-4 mb-3 flex-wrap gap-2' — matches the
 *                              top margin used by every page still on the older,
 *                              hand-rolled header markup, so a page using this
 *                              partial sits the same distance below the breadcrumb
 *                              as one that doesn't.
 */
$icon         = $icon ?? null;
$iconStyle    = $iconStyle ?? null;
$title        = $title ?? ($pageTitle ?? 'Page Title');
$titleTag     = $titleTag ?? 'h3';
$titleClass   = $titleClass ?? 'mb-1 fw-bold text-gray-800';
$titleStyle   = $titleStyle ?? null;
$subtitle     = $subtitle ?? null;
$subtitleTag  = $subtitleTag ?? 'p';
$cta          = $cta ?? null;
$wrapperClass = $wrapperClass ?? 'mt-4 mb-3 flex-wrap gap-2';
?>
<div class="d-flex align-items-center justify-content-between <?= $wrapperClass ?>">
    <div>
        <<?= $titleTag ?> class="<?= $titleClass ?>"<?= $titleStyle ? ' style="'.$titleStyle.'"' : '' ?>>
            <?php if ($icon): ?><i class="bi <?= $icon ?> me-2"<?= $iconStyle ? ' style="'.$iconStyle.'"' : '' ?>></i><?php endif; ?><?= $title ?>
        </<?= $titleTag ?>>
        <?php if ($subtitle !== null): ?>
        <<?= $subtitleTag ?> class="text-muted mb-0<?= $subtitleTag === 'p' ? ' small' : '' ?>"><?= $subtitle ?></<?= $subtitleTag ?>>
        <?php endif; ?>
    </div>
    <?php if ($cta !== null): ?>
    <div class="d-flex gap-2 flex-wrap">
        <?= $cta ?>
    </div>
    <?php endif; ?>
</div>
