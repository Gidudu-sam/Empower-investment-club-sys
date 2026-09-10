<?php
$pageTitle = $pageTitle ?? 'General Ledger';
$title = $pageTitle;
$icon  = 'bi-journal-text';

$periodLabel = AccountingReportModel::describeFilters($filters, $financialYears, $periods);
$base = APP_URL . '/index.php?page=report-general-ledger';
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <div class="card mb-3 no-print">
        <div class="card-header">Filters</div>
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="page" value="report-general-ledger">
                <div class="col-md-3 position-relative">
                    <label class="form-label small">Account</label>
                    <?php
                        $selectedAccount = null;
                        if ($accountId) {
                            foreach ($accounts as $a) {
                                if ((int)$a['id'] === $accountId) { $selectedAccount = $a; break; }
                            }
                        }
                    ?>
                    <input type="text" id="accountSearch" class="form-control form-control-sm" autocomplete="off"
                           placeholder="Type to search accounts…"
                           value="<?= $selectedAccount ? htmlspecialchars($selectedAccount['code'] . ' — ' . $selectedAccount['name']) : '' ?>">
                    <input type="hidden" name="account_id" id="accountIdInput" value="<?= $accountId ?: '' ?>">
                    <div class="list-group position-absolute" id="accountResults"
                         style="z-index:1000;display:none;max-height:260px;overflow-y:auto;width:100%;"></div>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Financial Year</label>
                    <select name="financial_year_id" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach ($financialYears as $fy): ?>
                            <option value="<?= $fy['id'] ?>" <?= (int)($filters['financial_year_id'] ?? 0) === (int)$fy['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fy['name']) ?><?= $fy['is_legacy'] ? ' (Legacy)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Period</label>
                    <select name="accounting_period_id" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach ($periods as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= (int)($filters['accounting_period_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">From</label>
                    <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">To</label>
                    <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>">
                </div>
                <div class="col-md-1 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary flex-fill">Go</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <?php require VIEW_PATH . '/layouts/report-print-header.php'; ?>

            <div class="d-flex justify-content-end gap-2 mb-3 no-print">
                <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button>
            </div>

            <?php if ($accountId && isset($report['account'])): ?>
                <p class="mb-3"><strong>Account:</strong> <?= htmlspecialchars($report['account']['code'] . ' — ' . $report['account']['name']) ?></p>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th><th>Journal #</th><th>Description</th><th>Source</th>
                                <th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Running Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($report['lines'])): ?>
                                <tr><td colspan="7" class="text-center text-muted">No activity for this account in the selected filters.</td></tr>
                            <?php else: foreach ($report['lines'] as $l): ?>
                                <tr>
                                    <td><?= date('d M Y', strtotime($l['entry_date'])) ?></td>
                                    <td><?= htmlspecialchars($l['entry_number']) ?></td>
                                    <td><?= htmlspecialchars($l['line_description'] ?: $l['entry_description'] ?? '') ?></td>
                                    <td><small class="text-muted"><?= htmlspecialchars(trim(($l['source_module'] ?? '') . ' / ' . ($l['source_reference_type'] ?? ''), ' /')) ?: '-' ?></small></td>
                                    <td class="text-end"><?= $l['debit'] > 0 ? number_format((float)$l['debit'], 2) : '' ?></td>
                                    <td class="text-end"><?= $l['credit'] > 0 ? number_format((float)$l['credit'], 2) : '' ?></td>
                                    <td class="text-end fw-semibold"><?= number_format($l['running_balance'], 2) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($report['lines'])): ?>
                        <tfoot class="table-light">
                            <tr><th colspan="6" class="text-end">Closing Balance</th><th class="text-end">Shs <?= number_format($report['closing_balance'], 2) ?></th></tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th><th>Journal #</th><th>Description</th><th>Account</th>
                                <th class="text-end">Debit</th><th class="text-end">Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($report['lines'])): ?>
                                <tr><td colspan="6" class="text-center text-muted">No journal activity in the selected filters.</td></tr>
                            <?php else: foreach ($report['lines'] as $l): ?>
                                <tr>
                                    <td><?= date('d M Y', strtotime($l['entry_date'])) ?></td>
                                    <td><?= htmlspecialchars($l['entry_number']) ?></td>
                                    <td><?= htmlspecialchars($l['line_description'] ?: $l['entry_description']) ?></td>
                                    <td><?= htmlspecialchars($l['account_code'] . ' — ' . $l['account_name']) ?></td>
                                    <td class="text-end"><?= $l['debit'] > 0 ? number_format((float)$l['debit'], 2) : '' ?></td>
                                    <td class="text-end"><?= $l['credit'] > 0 ? number_format((float)$l['credit'], 2) : '' ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<script>
(function () {
    const ACCOUNTS = <?= json_encode(array_map(fn($a) => [
        'id'    => (int)$a['id'],
        'label' => $a['code'] . ' — ' . $a['name'],
    ], $accounts)) ?>;

    const input   = document.getElementById('accountSearch');
    const hidden  = document.getElementById('accountIdInput');
    const results = document.getElementById('accountResults');

    function renderResults(list) {
        results.innerHTML = '';
        if (!list.length) { results.style.display = 'none'; return; }
        list.forEach(a => {
            const item = document.createElement('a');
            item.href = '#';
            item.className = 'list-group-item list-group-item-action';
            item.style.fontSize = '.82rem';
            item.textContent = a.label;
            item.onclick = (e) => {
                e.preventDefault();
                input.value = a.label;
                hidden.value = a.id;
                results.style.display = 'none';
            };
            results.appendChild(item);
        });
        results.style.display = '';
    }

    input.addEventListener('input', function () {
        hidden.value = '';
        const q = this.value.trim().toLowerCase();
        const list = q === ''
            ? ACCOUNTS
            : ACCOUNTS.filter(a => a.label.toLowerCase().includes(q));
        renderResults(list);
    });

    input.addEventListener('focus', function () {
        if (!this.value.trim()) renderResults(ACCOUNTS);
    });

    document.addEventListener('click', function (e) {
        if (!input.contains(e.target) && !results.contains(e.target)) results.style.display = 'none';
    });
})();
</script>

<style>
@media print { .no-print { display: none !important; } }
</style>
