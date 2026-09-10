<?php $base = APP_URL . '/index.php'; $s = $adj['status']; ?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold" style="font-family:'Space Grotesk',sans-serif;"><?= htmlspecialchars($adj['adjustment_number']) ?></h1>
        <p class="text-muted mb-0" style="font-size:.78rem;">
            <span class="badge rounded-pill <?= match($s){'posted'=>'bg-success-subtle text-success','pending_approval'=>'bg-warning-subtle text-warning','approved'=>'bg-info-subtle text-info','rejected'=>'bg-danger-subtle text-danger',default=>'bg-secondary-subtle text-secondary'} ?>">
                <?= ucwords(str_replace('_', ' ', $s)) ?>
            </span>
            <?php if ($adj['reversed_at']): ?><span class="badge bg-secondary ms-1">Reversed <?= date('d M Y', strtotime($adj['reversed_at'])) ?></span><?php endif; ?>
        </p>
    </div>
    <a href="<?= $base ?>?page=member-adjustments" class="btn btn-outline-secondary btn-sm">Back to Register</a>
</div>

<?php if (Session::has('success')): ?><div class="alert alert-success"><?= htmlspecialchars(Session::flash('success')) ?></div><?php endif; ?>
<?php if (Session::has('error')): ?><div class="alert alert-danger"><?= htmlspecialchars(Session::flash('error')) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card mb-3">
            <div class="card-body p-4">
                <div class="table-responsive">
                    <table class="table table-sm">
                    <tr><th style="width:200px;">Member</th><td><?= htmlspecialchars($adj['first_name'] . ' ' . $adj['last_name']) ?> (<?= htmlspecialchars($adj['member_number']) ?>)</td></tr>
                    <tr><th>Account</th><td><?= htmlspecialchars(ucfirst($adj['account_type'])) ?> — <?= htmlspecialchars($adj['account_number']) ?></td></tr>
                    <tr><th>Type</th><td><span class="badge <?= $adj['adjustment_type']==='credit'?'bg-success':'bg-danger' ?>"><?= ucfirst($adj['adjustment_type']) ?></span></td></tr>
                    <tr><th>Amount</th><td class="fw-bold">Shs <?= number_format($adj['amount'], 2) ?></td></tr>
                    <tr><th>Reason</th><td><?= nl2br(htmlspecialchars($adj['reason'])) ?></td></tr>
                    <tr><th>Original Reference</th><td><?= htmlspecialchars($adj['original_reference'] ?? '—') ?></td></tr>
                    <tr><th>Contra GL Account</th><td><?= htmlspecialchars($adj['contra_account_name'] . ' (' . $adj['contra_account_code'] . ')') ?></td></tr>
                    <?php if ($adj['status'] === 'posted'): ?>
                    <tr><th>Balance Before</th><td>Shs <?= number_format($adj['balance_before'], 2) ?></td></tr>
                    <tr><th>Balance After</th><td class="fw-bold">Shs <?= number_format($adj['balance_after'], 2) ?></td></tr>
                    <tr><th>Journal Entry</th><td><i class="bi bi-journal-check me-1 text-success"></i><?= htmlspecialchars($adj['entry_number'] ?? '—') ?></td></tr>
                    <?php else: ?>
                    <tr><th>Account Balance Now</th><td>Shs <?= number_format($currentBalance, 2) ?></td></tr>
                    <?php endif; ?>
                    <tr><th>Prepared By</th><td><?= htmlspecialchars($adj['recorded_by_name'] ?? '—') ?></td></tr>
                    <?php if ($adj['approved_by_name']): ?><tr><th>Approved By</th><td><?= htmlspecialchars($adj['approved_by_name']) ?> — <?= date('d M Y H:i', strtotime($adj['approved_at'])) ?></td></tr><?php endif; ?>
                    <?php if ($adj['rejected_by_name']): ?><tr><th>Rejected By</th><td><?= htmlspecialchars($adj['rejected_by_name']) ?> — <?= htmlspecialchars($adj['rejection_reason']) ?></td></tr><?php endif; ?>
                    <?php if ($adj['reversed_by_name']): ?><tr><th>Reversed By</th><td><?= htmlspecialchars($adj['reversed_by_name']) ?> — <?= htmlspecialchars($adj['reversal_reason']) ?></td></tr><?php endif; ?>
                </table>
                </div>

                <?php if ($canWrite && $s === 'draft'): ?>
                <form method="POST" action="<?= $base ?>?page=member-adjustment-submit" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="adjustment_id" value="<?= $adj['id'] ?>">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Submit for Approval</button>
                </form>
                <?php endif; ?>

                <?php if ($isAdmin && $s === 'pending_approval' && $adj['recorded_by'] != $currentUserId): ?>
                <form method="POST" action="<?= $base ?>?page=member-adjustment-approve" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="adjustment_id" value="<?= $adj['id'] ?>">
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-circle me-1"></i>Approve</button>
                </form>
                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal"><i class="bi bi-x-circle me-1"></i>Reject</button>
                <?php elseif ($s === 'pending_approval' && $adj['recorded_by'] == $currentUserId): ?>
                <div class="alert alert-warning small mb-0">You prepared this adjustment — a different admin must approve it.</div>
                <?php endif; ?>

                <?php if ($canWrite && $s === 'approved'): ?>
                <form method="POST" action="<?= $base ?>?page=member-adjustment-post" class="d-inline" onsubmit="return confirm('Post this adjustment? It will immediately affect the member balance and the General Ledger.')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="adjustment_id" value="<?= $adj['id'] ?>">
                    <button type="submit" class="btn btn-success"><i class="bi bi-cash-coin me-1"></i>Post to Ledger</button>
                </form>
                <?php endif; ?>

                <?php if ($isAdmin && $s === 'posted' && !$adj['reversed_at']): ?>
                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#reverseModal"><i class="bi bi-arrow-counterclockwise me-1"></i>Reverse</button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="card">
            <div class="card-header">Audit History</div>
            <div class="card-body">
                <?php if (empty($auditTrail)): ?>
                    <p class="text-muted mb-0">No events yet.</p>
                <?php else: foreach ($auditTrail as $a): ?>
                    <div class="mb-2 pb-2 border-bottom small">
                        <div><?= date('d M Y H:i', strtotime($a['created_at'])) ?> — <strong><?= htmlspecialchars(ucfirst($a['action'])) ?></strong> by <?= htmlspecialchars($a['user_name'] ?? '—') ?></div>
                        <?php if ($a['reason']): ?><div class="text-muted"><?= htmlspecialchars($a['reason']) ?></div><?php endif; ?>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <form method="POST" action="<?= $base ?>?page=member-adjustment-reject">
            <div class="modal-header"><h6 class="modal-title fw-semibold">Reject Adjustment</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="adjustment_id" value="<?= $adj['id'] ?>">
                <label class="form-label">Reason</label>
                <textarea name="rejection_reason" class="form-control" rows="2" required></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger btn-sm">Reject</button>
            </div>
        </form>
    </div></div>
</div>

<div class="modal fade" id="reverseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <form method="POST" action="<?= $base ?>?page=member-adjustment-reverse">
            <div class="modal-header"><h6 class="modal-title fw-semibold">Reverse Adjustment</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="adjustment_id" value="<?= $adj['id'] ?>">
                <p class="small">This creates an equal-and-opposite entry on both the member ledger and the General Ledger. The original adjustment is kept, not deleted.</p>
                <label class="form-label">Reversal Reason</label>
                <textarea name="reversal_reason" class="form-control" rows="2" required></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger btn-sm">Confirm Reversal</button>
            </div>
        </form>
    </div></div>
</div>
