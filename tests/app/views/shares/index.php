<?php
$base = APP_URL . '/index.php';
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-pie-chart-fill me-2 text-success"></i>Shares
        </h1>
        <p class="text-muted mb-0 small">Ownership, share capital, and shareholder positions</p>
    </div>
    <div class="d-flex gap-2 flex-wrap no-print">
        <?php if (!empty($canRecordTransaction)): ?>
        <a href="<?= $base ?>?page=share-transaction-create" class="btn btn-success btn-sm">
            <i class="bi bi-receipt me-1"></i>Record Share Transaction
        </a>
        <?php endif; ?>
        <?php if (!empty($canRecordHistorical)): ?>
        <a href="<?= $base ?>?page=share-historical-create" class="btn btn-outline-success btn-sm">
            <i class="bi bi-clock-history me-1"></i>Record Historical Shares
        </a>
        <?php endif; ?>
        <a href="<?= $base ?>?page=report-shares" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-file-earmark-bar-graph me-1"></i>Full Share Report
        </a>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card stat-card-success h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-success"><i class="bi bi-diagram-3-fill"></i></div>
                <div>
                    <div class="stat-label">Total Shares Issued</div>
                    <div class="stat-value" style="font-size:1.1rem"><?= number_format($totalQty, 4) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-card-info h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-info"><i class="bi bi-cash-stack"></i></div>
                <div>
                    <div class="stat-label">Total Share Capital</div>
                    <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($totalCapital, 2) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-card-primary h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-primary"><i class="bi bi-people-fill"></i></div>
                <div>
                    <div class="stat-label">Shareholders</div>
                    <div class="stat-value"><?= number_format($shareholders) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-card-warning h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-warning"><i class="bi bi-tag-fill"></i></div>
                <div>
                    <div class="stat-label">Current Share Value</div>
                    <div class="stat-value" style="font-size:1.1rem">Shs <?= number_format($shareValue, 2) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Member Share Position search -->
<div class="card mb-4">
    <div class="card-header">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-search me-2 text-muted"></i>Find a Member's Share Position</h6>
    </div>
    <div class="card-body p-3">
        <div class="position-relative" style="max-width:500px;">
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-person-circle"></i></span>
                <input type="text" id="shareMemberSearch" class="form-control"
                       placeholder="Type name, member number or phone…" autocomplete="off">
            </div>
            <div id="shareMemberDropdown" class="list-group shadow mt-1"
                 style="position:absolute;z-index:1050;width:100%;display:none;max-height:260px;overflow-y:auto;"></div>
        </div>
    </div>
</div>

<!-- Recent Transactions / Top Shareholders -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-trophy me-2 text-warning"></i>Top Shareholders</h6>
        <span class="badge bg-success-subtle text-success"><?= count($topShareholders) ?> shown</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Rank</th>
                    <th>Member No.</th>
                    <th>Name</th>
                    <th class="text-end">Shares</th>
                    <th class="text-end">Share Capital (Shs)</th>
                    <th class="text-end">Ownership %</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($topShareholders)): ?>
                <tr><td colspan="6" class="text-center py-4 text-muted">No share transactions recorded yet.</td></tr>
                <?php else: foreach ($topShareholders as $i => $sh): ?>
                <tr>
                    <td class="ps-3">
                        <?php if ($i < 3): ?>
                        <span class="badge <?= ['bg-warning text-dark','bg-secondary','bg-danger-subtle text-danger'][$i] ?> rounded-pill"><?= $i + 1 ?></span>
                        <?php else: ?>
                        <span class="text-muted"><?= $i + 1 ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="fw-semibold small"><?= htmlspecialchars($sh['member_number']) ?></td>
                    <td class="fw-semibold small">
                        <a href="<?= $base ?>?page=share-member&member_id=<?= (int)$sh['id'] ?>">
                            <?= htmlspecialchars($sh['first_name'] . ' ' . $sh['last_name']) ?>
                        </a>
                    </td>
                    <td class="text-end"><?= number_format((float)$sh['total_quantity'], 4) ?></td>
                    <td class="text-end fw-bold text-success">Shs <?= number_format((float)$sh['total_capital'], 2) ?></td>
                    <td class="text-end"><?= number_format((float)$sh['ownership_pct'], 2) ?>%</td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

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
