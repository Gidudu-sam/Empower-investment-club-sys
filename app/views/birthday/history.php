<?php
/**
 * Birthday email full history page.
 *
 * Variables provided by BirthdayController::history():
 *   $logs         array   Paginated activity_logs rows with action='birthday_email'
 *   $total        int
 *   $pages        int
 *   $currentPage  int
 */
$base = APP_URL . '/index.php';
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h3 mb-1 fw-bold" style="color:var(--brand-navy);">
            <i class="bi bi-clock-history me-2" style="color:#F47920;"></i>Birthday Email History
        </h1>
        <p class="text-muted mb-0 small">
            <?= number_format($total) ?> record<?= $total !== 1 ? 's' : '' ?> total
        </p>
    </div>
    <a href="<?= $base ?>?page=birthday-dashboard"
       class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body p-0">
        <?php if (empty($logs)): ?>
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
                    <th>Member</th>
                    <th>Year</th>
                    <th>Email address</th>
                    <th>Status</th>
                    <th>Sent by</th>
                    <th class="pe-4">Error</th>
                </tr>
            </thead>
            <tbody style="font-size:.875rem;">
            <?php foreach ($logs as $row):
                $desc = $row['description'] ?? '';
                preg_match('/MEMBER:(\d+)/',    $desc, $mId);
                preg_match('/YEAR:(\d+)/',      $desc, $yr);
                preg_match('/NAME:([^|]+)/',    $desc, $nm);
                preg_match('/EMAIL:([^|]+)/',   $desc, $em);
                preg_match('/ERROR:(.+)$/',     $desc, $er);
                $isSent = str_contains($desc, '|STATUS:sent');
                $isFail = str_contains($desc, '|STATUS:failed');
            ?>
            <tr>
                <td class="ps-4 text-muted small">
                    <?= htmlspecialchars(date('d M Y H:i', strtotime($row['created_at']))) ?>
                </td>
                <td class="fw-semibold">
                    <?= htmlspecialchars($nm[1] ?? ('Member #' . ($mId[1] ?? '?'))) ?>
                </td>
                <td class="text-muted"><?= htmlspecialchars($yr[1] ?? '') ?></td>
                <td class="text-muted small">
                    <?= htmlspecialchars($em[1] ?? '') ?>
                </td>
                <td>
                    <?php if ($isSent): ?>
                        <span class="badge bg-success">sent</span>
                    <?php elseif ($isFail): ?>
                        <span class="badge bg-danger">failed</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">unknown</span>
                    <?php endif; ?>
                </td>
                <td class="text-muted small">
                    <?= htmlspecialchars($row['user_name'] ?? 'System') ?>
                </td>
                <td class="pe-4 text-danger small" style="max-width:200px;word-break:break-all;">
                    <?= htmlspecialchars($er[1] ?? '') ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <!-- Pagination -->
        <?php if ($pages > 1): ?>
        <div class="d-flex justify-content-center py-3">
            <nav aria-label="Birthday history pagination">
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($p = 1; $p <= $pages; $p++): ?>
                    <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
                        <a class="page-link"
                           href="<?= $base ?>?page=birthday-history&p=<?= $p ?>">
                            <?= $p ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
