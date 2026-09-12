<?php
/**
 * Birthday Email Administration Dashboard.
 *
 * Variables provided by BirthdayController::index():
 *   $todayBirthdays     array   Members with today's birthday (eligible + ineligible)
 *   $upcomingBirthdays  array   Active members with birthdays in the next 7 days
 *   $recentHistory      array   Last 20 activity_logs rows with action='birthday_email'
 *   $today              string  'YYYY-MM-DD'
 *   $todayDisplay       string  'Monday, 12 September 2026'
 *   $alreadySentIds     array   Member IDs already sent today (birthday year)
 *   $mailerConfigured   bool    Whether SMTP credentials are present
 *   $canSend            bool    Whether current user may trigger sends
 *   $csrfToken          string
 *   $success            ?string Flash message
 *   $error              ?string Flash message
 */
$base = APP_URL . '/index.php';
?>

<!-- ── Page header ─────────────────────────────────────────────────── -->
<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h3 mb-1 fw-bold" style="color:var(--brand-navy);">
            <i class="bi bi-gift-fill me-2" style="color:#F47920;"></i>Member Birthdays
        </h1>
        <p class="text-muted mb-0 small"><?= htmlspecialchars($todayDisplay) ?></p>
    </div>
    <?php if ($canSend && !empty($todayBirthdays)): ?>
    <div>
        <a href="<?= $base ?>?page=birthday-preview"
           class="btn btn-outline-secondary btn-sm me-2">
            <i class="bi bi-eye me-1"></i>Preview
        </a>
        <?php if (!$mailerConfigured): ?>
            <button class="btn btn-sm btn-secondary" disabled
                    title="SMTP is not configured. Check app/config/mail.php.">
                <i class="bi bi-send me-1"></i>Send Birthday Emails
            </button>
        <?php else: ?>
            <button class="btn btn-sm"
                    style="background:var(--brand-navy);color:#fff;"
                    data-bs-toggle="modal" data-bs-target="#confirmSendModal">
                <i class="bi bi-send me-1"></i>Send Birthday Emails
            </button>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ── Flash messages ──────────────────────────────────────────────── -->
<?php if (!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-check-circle-fill flex-shrink-0"></i>
    <div><?= htmlspecialchars($success) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
    <div><?= htmlspecialchars($error) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (!$mailerConfigured): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
    <div><strong>Email not configured.</strong> SMTP credentials are missing.
    Birthday emails cannot be sent until <code>app/config/mail.php</code> is configured.</div>
</div>
<?php endif; ?>

<!-- ── Today's birthdays ───────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
    <div class="card-header d-flex align-items-center gap-2"
         style="background:var(--brand-navy);color:#fff;border-radius:.5rem .5rem 0 0;">
        <i class="bi bi-cake2-fill" style="color:#F47920;"></i>
        <h6 class="mb-0 fw-semibold">
            Today's Birthdays
            <?php if (!empty($todayBirthdays)): ?>
            <span class="badge ms-2"
                  style="background:#F47920;font-size:.7rem;"><?= count($todayBirthdays) ?></span>
            <?php endif; ?>
        </h6>
    </div>
    <div class="card-body p-0">
        <?php if (empty($todayBirthdays)): ?>
        <p class="text-muted p-4 mb-0 text-center">
            <i class="bi bi-calendar-check me-1"></i>
            No member birthdays today. Check back tomorrow!
        </p>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead style="background:var(--paper);font-size:.78rem;text-transform:uppercase;
                          letter-spacing:.04em;color:var(--slate);">
                <tr>
                    <th class="ps-4">Member</th>
                    <th>Member No.</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th class="text-end pe-4">Email Status</th>
                </tr>
            </thead>
            <tbody style="font-size:.875rem;">
            <?php foreach ($todayBirthdays as $m):
                $eligible  = !empty($m['email']);
                $sent      = in_array((int)$m['id'], $alreadySentIds, true);
                $fullName  = htmlspecialchars($m['first_name'] . ' ' . $m['last_name']);
            ?>
            <tr>
                <td class="ps-4 fw-semibold"><?= $fullName ?></td>
                <td class="text-muted"><?= htmlspecialchars($m['member_number'] ?? '') ?></td>
                <td>
                    <?php if (!empty($m['email'])): ?>
                        <span class="text-muted small"><?= htmlspecialchars($m['email']) ?></span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark">No email</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge <?= $m['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                        <?= htmlspecialchars($m['status']) ?>
                    </span>
                </td>
                <td class="text-end pe-4">
                    <?php if (!$eligible): ?>
                        <span class="badge bg-warning text-dark">Skipped — no email</span>
                    <?php elseif ($sent): ?>
                        <span class="badge bg-success">
                            <i class="bi bi-check-circle me-1"></i>Sent
                        </span>
                    <?php else: ?>
                        <span class="badge bg-light text-secondary border">Pending</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Upcoming birthdays ──────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
    <div class="card-header d-flex align-items-center gap-2"
         style="background:var(--brand-navy);color:#fff;border-radius:.5rem .5rem 0 0;">
        <i class="bi bi-calendar3 me-1" style="color:#F47920;"></i>
        <h6 class="mb-0 fw-semibold">Upcoming Birthdays (Next 7 Days)</h6>
    </div>
    <div class="card-body p-0">
        <?php if (empty($upcomingBirthdays)): ?>
        <p class="text-muted p-4 mb-0 text-center">
            No birthdays in the next 7 days.
        </p>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead style="background:var(--paper);font-size:.78rem;text-transform:uppercase;
                          letter-spacing:.04em;color:var(--slate);">
                <tr>
                    <th class="ps-4">Member</th>
                    <th>Member No.</th>
                    <th>Birthday</th>
                    <th class="pe-4">Email</th>
                </tr>
            </thead>
            <tbody style="font-size:.875rem;">
            <?php foreach ($upcomingBirthdays as $m):
                $bday = new DateTime(date('Y') . '-' . ($m['bday_mmdd'] ?? date_format(new DateTime($m['date_of_birth']), 'm-d')));
                // If already passed this year, show next year
                if ($bday < new DateTime('today')) {
                    $bday->modify('+1 year');
                }
                $daysAway = (int)(new DateTime('today'))->diff($bday)->days;
            ?>
            <tr>
                <td class="ps-4 fw-semibold">
                    <?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?>
                </td>
                <td class="text-muted"><?= htmlspecialchars($m['member_number'] ?? '') ?></td>
                <td>
                    <?= $bday->format('D, d M') ?>
                    <span class="text-muted small ms-1">
                        (<?= $daysAway === 1 ? 'tomorrow' : "in {$daysAway} days" ?>)
                    </span>
                </td>
                <td class="pe-4">
                    <?php if (!empty($m['email'])): ?>
                        <i class="bi bi-check-circle text-success"></i>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark">No email</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Recent history ─────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
    <div class="card-header d-flex align-items-center justify-content-between"
         style="background:var(--brand-navy);color:#fff;border-radius:.5rem .5rem 0 0;">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-clock-history" style="color:#F47920;"></i>
            <h6 class="mb-0 fw-semibold">Birthday Email History</h6>
        </div>
        <a href="<?= $base ?>?page=birthday-history"
           class="btn btn-sm btn-outline-light">
            View all
        </a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($recentHistory)): ?>
        <p class="text-muted p-4 mb-0 text-center">
            No birthday emails have been sent yet.
        </p>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead style="background:var(--paper);font-size:.78rem;text-transform:uppercase;
                          letter-spacing:.04em;color:var(--slate);">
                <tr>
                    <th class="ps-4">Timestamp</th>
                    <th>Description</th>
                    <th class="pe-4">Sent by</th>
                </tr>
            </thead>
            <tbody style="font-size:.875rem;">
            <?php foreach ($recentHistory as $row): ?>
            <tr>
                <td class="ps-4 text-muted small">
                    <?= htmlspecialchars(date('d M Y H:i', strtotime($row['created_at']))) ?>
                </td>
                <td style="font-size:.8rem;max-width:420px;word-break:break-all;">
                    <?php
                    $desc   = $row['description'] ?? '';
                    $isSent = str_contains($desc, '|STATUS:sent');
                    $isFail = str_contains($desc, '|STATUS:failed');
                    $badge  = $isSent ? '<span class="badge bg-success me-1">sent</span>'
                            : ($isFail ? '<span class="badge bg-danger me-1">failed</span>'
                            : '');
                    // Extract readable parts
                    preg_match('/NAME:([^|]+)/', $desc, $nm);
                    preg_match('/YEAR:(\d+)/', $desc, $yr);
                    $display = ($nm[1] ?? '') !== ''
                             ? htmlspecialchars($nm[1]) . ' (' . htmlspecialchars($yr[1] ?? '') . ')'
                             : htmlspecialchars($desc);
                    echo $badge . $display;
                    ?>
                </td>
                <td class="pe-4 text-muted small">
                    <?= htmlspecialchars($row['user_name'] ?? 'System') ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Send confirmation modal ─────────────────────────────────────── -->
<?php if ($canSend && !empty($todayBirthdays) && $mailerConfigured): ?>
<div class="modal fade" id="confirmSendModal" tabindex="-1"
     aria-labelledby="confirmSendModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--brand-navy);color:#fff;">
                <h5 class="modal-title" id="confirmSendModalLabel">
                    <i class="bi bi-send me-2"></i>Send Birthday Emails
                </h5>
                <button type="button" class="btn-close btn-close-white"
                        data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php
                $eligibleCount = 0;
                foreach ($todayBirthdays as $m) {
                    if (!empty($m['email']) && !in_array((int)$m['id'], $alreadySentIds, true)) {
                        $eligibleCount++;
                    }
                }
                ?>
                <?php if ($eligibleCount === 0): ?>
                    <p class="mb-0">
                        <i class="bi bi-info-circle me-1 text-info"></i>
                        All birthday members for today have already been sent emails this year.
                        No new emails will be sent.
                    </p>
                <?php else: ?>
                    <p>
                        This will send a personalized birthday greeting to
                        <strong><?= $eligibleCount ?></strong>
                        eligible member<?= $eligibleCount !== 1 ? 's' : '' ?> whose birthday
                        is <strong>today</strong>.
                    </p>
                    <p class="mb-0 text-muted small">
                        Members already sent a birthday email this year will be skipped.
                        Members without an email address will be skipped.
                    </p>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary"
                        data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="<?= $base ?>?page=birthday-send" class="d-inline">
                    <input type="hidden" name="csrf_token"
                           value="<?= htmlspecialchars($csrfToken) ?>">
                    <button type="submit" class="btn"
                            style="background:var(--brand-navy);color:#fff;">
                        <i class="bi bi-send me-1"></i>
                        <?= $eligibleCount === 0 ? 'Confirm (nothing to send)' : 'Send Now' ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
