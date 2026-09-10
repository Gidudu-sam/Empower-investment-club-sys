<?php
$pageTitle = $pageTitle ?? 'Create Opening Balance';
$title = $pageTitle;
$icon  = 'bi-clipboard2-plus';
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <div class="row g-3">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-clipboard2-plus me-2"></i>Create Opening Balance Batch
                </div>
                <div class="card-body">
                    <?php if (Session::has('error')): ?>
                        <div class="alert alert-danger"><?= Session::flash('error') ?></div>
                    <?php endif; ?>

                    <?php if (empty($periods)): ?>
                        <div class="alert alert-warning">
                            No open accounting period is available. Create or open an accounting period before entering opening balances.
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="<?= APP_URL ?>/index.php?page=opening-balance-store" id="obForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Accounting Period</label>
                                <select name="accounting_period_id" class="form-select" required>
                                    <option value="">Select Open Period</option>
                                    <?php foreach ($periods as $p): ?>
                                        <option value="<?= $p['id'] ?>">
                                            <?= htmlspecialchars($p['financial_year_name']) ?> — <?= htmlspecialchars($p['name']) ?>
                                            (<?= $p['start_date'] ?> to <?= $p['end_date'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">As Of Date</label>
                                <input type="date" name="as_of_date" class="form-control" required>
                            </div>
                        </div>

                        <hr>
                        <h6 class="mb-3">Account Lines</h6>

                        <div class="table-responsive">
                            <table class="table table-sm align-middle" id="linesTable">
                                <thead class="table-light">
                                    <tr>
                                        <th style="min-width:260px;">Account</th>
                                        <th style="width:150px;" class="text-end">Debit</th>
                                        <th style="width:150px;" class="text-end">Credit</th>
                                        <th>Description</th>
                                        <th style="width:40px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="linesBody">
                                    <!-- rows added by JS -->
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th>Totals</th>
                                        <th class="text-end" id="totalDebit">0.00</th>
                                        <th class="text-end" id="totalCredit">0.00</th>
                                        <th colspan="2"></th>
                                    </tr>
                                    <tr>
                                        <th colspan="2"></th>
                                        <th class="text-end" id="totalDiff">Difference: 0.00</th>
                                        <th colspan="2"></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <button type="button" class="btn btn-sm btn-outline-secondary mb-3" id="addLineBtn">
                            <i class="bi bi-plus-circle me-1"></i> Add Line
                        </button>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-1"></i> Save as Draft
                            </button>
                            <a href="<?= APP_URL ?>/index.php?page=opening-balances" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

<template id="lineRowTemplate">
    <tr class="line-row">
        <td>
            <select name="line_account_id[]" class="form-select form-select-sm account-select" required>
                <option value="">Select Account</option>
                <?php foreach ($accounts as $a): ?>
                    <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['code'] . ' — ' . $a['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td><input type="number" step="0.01" min="0" name="line_debit[]" class="form-control form-control-sm text-end debit-input" value="0"></td>
        <td><input type="number" step="0.01" min="0" name="line_credit[]" class="form-control form-control-sm text-end credit-input" value="0"></td>
        <td><input type="text" name="line_description[]" class="form-control form-control-sm"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger remove-line"><i class="bi bi-trash"></i></button></td>
    </tr>
</template>

<script>
(function () {
    const body = document.getElementById('linesBody');
    const template = document.getElementById('lineRowTemplate');

    function addLine() {
        const clone = template.content.cloneNode(true);
        body.appendChild(clone);
        recalcTotals();
    }

    function recalcTotals() {
        let debit = 0, credit = 0;
        body.querySelectorAll('.line-row').forEach(row => {
            debit += parseFloat(row.querySelector('.debit-input').value || 0);
            credit += parseFloat(row.querySelector('.credit-input').value || 0);
        });
        document.getElementById('totalDebit').textContent = debit.toFixed(2);
        document.getElementById('totalCredit').textContent = credit.toFixed(2);
        document.getElementById('totalDiff').textContent = 'Difference: ' + (debit - credit).toFixed(2);
    }

    document.getElementById('addLineBtn').addEventListener('click', addLine);

    body.addEventListener('click', function (e) {
        if (e.target.closest('.remove-line')) {
            e.target.closest('.line-row').remove();
            recalcTotals();
        }
    });
    body.addEventListener('input', function (e) {
        if (e.target.classList.contains('debit-input') || e.target.classList.contains('credit-input')) {
            recalcTotals();
        }
    });

    // Start with two blank lines
    addLine();
    addLine();
})();
</script>
