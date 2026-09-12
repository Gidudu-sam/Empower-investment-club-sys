<?php
/**
 * Birthday email dry-run / preview page.
 *
 * Variables provided by BirthdayController::preview():
 *   $todayBirthdays   array   Active members with today's birthday
 *   $sampleHtml       string  Rendered email HTML for the first eligible member
 *   $sampleMember     array|null  The member used for the preview
 *   $alreadySentIds   array   Member IDs already sent this year
 *   $today            string  'YYYY-MM-DD'
 *   $todayDisplay     string  'Monday, 12 September 2026'
 *   $canSend          bool
 *   $csrfToken        string
 */
$base = APP_URL . '/index.php';
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h3 mb-1 fw-bold" style="color:var(--brand-navy);">
            <i class="bi bi-eye-fill me-2" style="color:#F47920;"></i>Birthday Email Preview
        </h1>
        <p class="text-muted mb-0 small">Dry-run — no emails are sent from this page</p>
    </div>
    <a href="<?= $base ?>?page=birthday-dashboard"
       class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

<!-- Eligibility summary -->
<div class="card shadow-sm mb-4">
    <div class="card-header d-flex align-items-center gap-2"
         style="background:var(--brand-navy);color:#fff;border-radius:.5rem .5rem 0 0;">
        <i class="bi bi-list-check" style="color:#F47920;"></i>
        <h6 class="mb-0 fw-semibold">
            Eligibility — <?= htmlspecialchars($todayDisplay) ?>
        </h6>
    </div>
    <div class="card-body p-0">
        <?php if (empty($todayBirthdays)): ?>
        <p class="text-muted p-4 mb-0 text-center">No birthdays today.</p>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead style="background:var(--paper);font-size:.78rem;text-transform:uppercase;
                          letter-spacing:.04em;color:var(--slate);">
                <tr>
                    <th class="ps-4">Member</th>
                    <th>Email</th>
                    <th class="pe-4">Would send?</th>
                </tr>
            </thead>
            <tbody style="font-size:.875rem;">
            <?php foreach ($todayBirthdays as $m):
                $sent      = in_array((int)$m['id'], $alreadySentIds, true);
                $hasEmail  = !empty($m['email']);
                $wouldSend = $hasEmail && !$sent;
            ?>
            <tr>
                <td class="ps-4 fw-semibold">
                    <?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?>
                    <span class="text-muted small ms-1">(<?= htmlspecialchars($m['member_number'] ?? '') ?>)</span>
                </td>
                <td class="text-muted small">
                    <?= $hasEmail ? htmlspecialchars($m['email']) : '<em class="text-warning">No email on file</em>' ?>
                </td>
                <td class="pe-4">
                    <?php if ($wouldSend): ?>
                        <span class="badge bg-success">
                            <i class="bi bi-check-circle me-1"></i>Yes
                        </span>
                    <?php elseif ($sent): ?>
                        <span class="badge bg-secondary">Already sent this year</span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark">Skipped — no email</span>
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

<!-- Sample email preview -->
<?php if ($sampleMember !== null): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header d-flex align-items-center gap-2"
         style="background:var(--brand-navy);color:#fff;border-radius:.5rem .5rem 0 0;">
        <i class="bi bi-envelope-fill" style="color:#F47920;"></i>
        <h6 class="mb-0 fw-semibold">
            Sample Email —
            <?= htmlspecialchars($sampleMember['first_name'] . ' ' . $sampleMember['last_name']) ?>
        </h6>
    </div>
    <div class="card-body p-0">
        <div class="alert alert-info d-flex align-items-center gap-2 m-3 mb-0">
            <i class="bi bi-info-circle-fill flex-shrink-0"></i>
            <div class="small">
                This is a preview of the email that would be sent.
                <strong>No email has been sent.</strong>
                Each eligible member receives a personalised copy with their own name.
            </div>
        </div>
        <div style="padding:16px;">
            <!-- Sandbox iframe to safely render the email HTML -->
            <iframe
                id="emailPreviewFrame"
                sandbox="allow-same-origin"
                style="width:100%;border:1px solid #e2e8f0;border-radius:4px;
                       min-height:520px;background:#e8eaf0;"
                title="Birthday email preview">
            </iframe>
        </div>
    </div>
</div>

<script>
(function () {
    const emailHtml = <?= json_encode($sampleHtml, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const frame = document.getElementById('emailPreviewFrame');
    if (frame) {
        frame.addEventListener('load', function () {
            const body = frame.contentDocument && frame.contentDocument.body;
            if (body) {
                // Auto-size to content height
                frame.style.minHeight = Math.max(520, body.scrollHeight + 32) + 'px';
            }
        });
        // Write the email HTML into the sandboxed iframe
        const doc = frame.contentDocument || frame.contentWindow.document;
        doc.open();
        doc.write(emailHtml);
        doc.close();
    }
})();
</script>
<?php endif; ?>
