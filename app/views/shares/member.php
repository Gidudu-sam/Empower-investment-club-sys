<?php
$base = APP_URL . '/index.php';
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-person-vcard-fill me-2 text-success"></i>Member Share Position
        </h1>
        <p class="text-muted mb-0 small">Shares held, share capital, and transaction history</p>
    </div>
    <div class="d-flex gap-2 flex-wrap no-print">
        <a href="<?= $base ?>?page=shares" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to Shares
        </a>
    </div>
</div>

<!-- Member Search -->
<div class="card mb-4">
    <div class="card-body p-3">
        <div class="position-relative" style="max-width:500px;">
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" id="shareMemberSearch" class="form-control"
                       placeholder="Type name, member number or phone…" autocomplete="off" value="<?= $member ? htmlspecialchars($member['first_name'] . ' ' . $member['last_name'] . ' (' . $member['member_number'] . ')') : '' ?>">
            </div>
            <div id="shareMemberDropdown" class="list-group shadow mt-1"
                 style="position:absolute;z-index:1050;width:100%;display:none;max-height:260px;overflow-y:auto;"></div>
        </div>
    </div>
</div>

<?php if (!$member): ?>
<div class="alert alert-info d-flex align-items-center gap-2">
    <i class="bi bi-info-circle-fill flex-shrink-0"></i>
    <div>Search for a member above to view their share position.</div>
</div>
<?php else: ?>

<!-- Member Share Card -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="member-avatar-sm bg-blue"><?= strtoupper(substr($member['first_name'], 0, 1)) ?></div>
                    <div>
                        <div class="fw-semibold"><?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></div>
                        <div class="text-muted small"><?= htmlspecialchars($member['member_number']) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="stat-card stat-card-success h-100">
            <div class="stat-label">Shares Held</div>
            <div class="stat-value" style="font-size:1.1rem"><?= number_format($memberQty, 4) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="stat-card stat-card-warning h-100">
            <div class="stat-label">Share Value</div>
            <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($shareValue, 2) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="stat-card stat-card-info h-100">
            <div class="stat-label">Share Capital</div>
            <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($memberCapital, 2) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="stat-card stat-card-primary h-100">
            <div class="stat-label">Ownership</div>
            <div class="stat-value" style="font-size:1.1rem"><?= number_format($ownershipPct, 2) ?>%</div>
        </div>
    </div>
</div>

<!-- Share Ledger -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-journal-text me-2 text-muted"></i>Share Ledger</h6>
        <span class="badge bg-secondary-subtle text-secondary"><?= count($ledger) ?> transactions</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Date</th>
                    <th>Reference</th>
                    <th>Type</th>
                    <th>Source</th>
                    <th class="text-end">Quantity</th>
                    <th class="text-end">Share Value</th>
                    <th class="text-end">Amount</th>
                    <th class="text-end">Running Qty</th>
                    <th class="text-end">Running Capital</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($ledger)): ?>
                <tr><td colspan="9" class="text-center py-4 text-muted">No share transactions recorded for this member yet.</td></tr>
                <?php else: foreach ($ledger as $row): ?>
                <tr>
                    <td class="ps-3 small"><?= htmlspecialchars($row['date']) ?></td>
                    <td class="small"><?= htmlspecialchars($row['reference_number']) ?></td>
                    <td class="small"><span class="badge bg-light text-dark border"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $row['transaction_type']))) ?></span></td>
                    <td class="small text-muted"><?= htmlspecialchars($row['source_reference_type'] ?? '—') ?></td>
                    <td class="text-end"><?= number_format($row['quantity'], 4) ?></td>
                    <td class="text-end">Shs <?= number_format($row['share_value'], 2) ?></td>
                    <td class="text-end fw-semibold">Shs <?= number_format($row['amount'], 2) ?></td>
                    <td class="text-end"><?= number_format($row['running_quantity'], 4) ?></td>
                    <td class="text-end fw-bold text-success">Shs <?= number_format($row['running_capital'], 2) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<script>
(function(){
'use strict';
function escHtml(str){ const d=document.createElement('div'); d.textContent=str||''; return d.innerHTML; }
const base = '<?= APP_URL ?>/index.php';
const searchEl = document.getElementById('shareMemberSearch');
const dropdown = document.getElementById('shareMemberDropdown');
let timer;

if (searchEl) {
    searchEl.addEventListener('input', function(){
        clearTimeout(timer);
        if (this.value.trim().length < 2) { dropdown.style.display = 'none'; return; }
        timer = setTimeout(() => {
            fetch(base + '?page=share-member-search&q=' + encodeURIComponent(this.value.trim()))
                .then(r => r.json()).then(data => {
                    dropdown.innerHTML = '';
                    if (!data.members?.length) { dropdown.style.display = 'none'; return; }
                    data.members.forEach(m => {
                        const a = document.createElement('a');
                        a.href = base + '?page=share-member&member_id=' + m.id;
                        a.className = 'list-group-item list-group-item-action py-2 px-3';
                        a.innerHTML = `<div class="fw-semibold small">${escHtml(m.full_name)}</div>
                                       <div class="text-muted" style="font-size:.75rem">${escHtml(m.member_number)} · ${escHtml(m.phone)}</div>`;
                        dropdown.appendChild(a);
                    });
                    dropdown.style.display = 'block';
                }).catch(() => {});
        }, 300);
    });
    document.addEventListener('click', e => { if (!searchEl.contains(e.target)) dropdown.style.display = 'none'; });
}
})();
</script>
