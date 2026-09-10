<?php
$pageTitle = $pageTitle ?? 'Accounting Period';
$title = $pageTitle;
$icon  = 'bi-calendar3';
$base  = APP_URL . '/index.php';
$pid   = (int)$period['id'];

function ap_tab_url(string $tab, int $pid): string {
    return APP_URL . '/index.php?page=accounting-period-view&id=' . $pid . '&tab=' . $tab;
}
$tabs = [
    'overview'         => 'Overview',
    'activity'         => 'Activity',
    'journals'         => 'Journals',
    'trial-balance'    => 'Trial Balance',
    'general-ledger'   => 'General Ledger',
    'income-statement' => 'Income Statement',
    'balance-sheet'    => 'Balance Sheet',
    'audit'            => 'Audit',
];
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-1"><?= htmlspecialchars($period['name']) ?>
                <?php if ($period['status'] === 'open'): ?>
                    <span class="badge bg-success ms-2"><i class="bi bi-unlock"></i> Open</span>
                <?php else: ?>
                    <span class="badge bg-secondary ms-2"><i class="bi bi-lock"></i> Closed</span>
                <?php endif; ?>
            </h4>
            <div class="text-muted small">
                Financial Year: <strong><?= htmlspecialchars($period['financial_year_name'] ?? 'N/A') ?></strong>
                &nbsp;·&nbsp; <?= date('d M Y', strtotime($period['start_date'])) ?> – <?= date('d M Y', strtotime($period['end_date'])) ?>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= $base ?>?page=accounting-periods" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
            <?php if ($canWrite): ?>
                <?php if ($period['status'] === 'open'): ?>
                    <a href="<?= $base ?>?page=accounting-period-close-confirm&id=<?= $pid ?>" class="btn btn-warning btn-sm"><i class="bi bi-lock me-1"></i>Close Period</a>
                <?php else: ?>
                    <a href="<?= $base ?>?page=accounting-period-reopen-confirm&id=<?= $pid ?>" class="btn btn-outline-danger btn-sm"><i class="bi bi-unlock me-1"></i>Reopen Period</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if (Session::has('success')): ?>
        <div class="alert alert-success"><?= htmlspecialchars(Session::flash('success')) ?></div>
    <?php endif; ?>
    <?php if (Session::has('error')): ?>
        <div class="alert alert-danger"><?= htmlspecialchars(Session::flash('error')) ?></div>
    <?php endif; ?>

    <!-- Summary cards -->
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-2">
            <div class="card text-center"><div class="card-body py-3">
                <div class="small text-muted">Journal Entries</div>
                <div class="fs-5 fw-bold"><?= (int)$summary['journal_entries'] ?></div>
            </div></div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card text-center"><div class="card-body py-3">
                <div class="small text-muted">Total Debits</div>
                <div class="fs-6 fw-bold">Shs <?= number_format($summary['total_debit'], 2) ?></div>
            </div></div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card text-center"><div class="card-body py-3">
                <div class="small text-muted">Total Credits</div>
                <div class="fs-6 fw-bold">Shs <?= number_format($summary['total_credit'], 2) ?></div>
            </div></div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card text-center"><div class="card-body py-3">
                <div class="small text-muted">Income</div>
                <div class="fs-6 fw-bold text-success">Shs <?= number_format($summary['total_income'], 2) ?></div>
            </div></div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card text-center"><div class="card-body py-3">
                <div class="small text-muted">Expenses</div>
                <div class="fs-6 fw-bold text-danger">Shs <?= number_format($summary['total_expense'], 2) ?></div>
            </div></div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card text-center"><div class="card-body py-3">
                <div class="small text-muted">Net Result</div>
                <div class="fs-6 fw-bold"><?= $summary['net_result'] < 0 ? '(' : '' ?>Shs <?= number_format(abs($summary['net_result']), 2) ?><?= $summary['net_result'] < 0 ? ')' : '' ?></div>
            </div></div>
        </div>
    </div>

    <?php
    $bal = abs($summary['total_debit'] - $summary['total_credit']) < 0.01;
    if (!$bal): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i>
            This period's journal activity is NOT balanced (debits Shs <?= number_format($summary['total_debit'], 2) ?>
            vs credits Shs <?= number_format($summary['total_credit'], 2) ?>). Closing is blocked until this is investigated.
        </div>
    <?php endif; ?>

    <!-- Tab navigation -->
    <ul class="nav nav-tabs mb-3">
        <?php foreach ($tabs as $key => $label): ?>
            <li class="nav-item">
                <a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="<?= ap_tab_url($key, $pid) ?>"><?= $label ?></a>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="card">
        <div class="card-body">
        <?php if ($tab === 'overview'): ?>

            <div class="table-responsive">
                <table class="table table-sm">
                <tr><th style="width:200px;">Financial Year</th><td><?= htmlspecialchars($period['financial_year_name'] ?? 'N/A') ?> (<?= htmlspecialchars($period['financial_year_status'] ?? '') ?>)</td></tr>
                <tr><th>Status</th><td><?= strtoupper($period['status']) ?></td></tr>
                <tr><th>Start</th><td><?= date('d M Y', strtotime($period['start_date'])) ?></td></tr>
                <tr><th>End</th><td><?= date('d M Y', strtotime($period['end_date'])) ?></td></tr>
                <tr><th>Created</th><td><?= $period['created_at'] ? date('d M Y H:i', strtotime($period['created_at'])) : '—' ?></td></tr>
                <?php if ($period['status'] === 'closed'): ?>
                <tr><th>Closed</th><td><?= $period['closed_at'] ? date('d M Y H:i', strtotime($period['closed_at'])) : '—' ?></td></tr>
                <tr><th>Close Reason</th><td><?= htmlspecialchars($period['close_reason'] ?? '—') ?></td></tr>
                <?php endif; ?>
                <?php if (!empty($period['reopened_at'])): ?>
                <tr><th>Last Reopened</th><td><?= date('d M Y H:i', strtotime($period['reopened_at'])) ?></td></tr>
                <tr><th>Reopen Reason</th><td><?= htmlspecialchars($period['reopen_reason'] ?? '—') ?></td></tr>
                <?php endif; ?>
            </table>
            </div>
            <p class="text-muted small mb-0">Use the tabs above to inspect this period's activity, journal entries, Trial Balance, General Ledger, Income Statement, Balance Sheet, and audit history.</p>

        <?php elseif ($tab === 'activity'): ?>

            <form method="GET" class="row g-2 mb-3">
                <input type="hidden" name="page" value="accounting-period-view">
                <input type="hidden" name="id" value="<?= $pid ?>">
                <input type="hidden" name="tab" value="activity">
                <div class="col-auto">
                    <select name="module" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Modules</option>
                        <?php foreach (['Savings','Loans','Repayments','Withdrawals','Fees','Other Income','Expenses','Investments'] as $m): ?>
                            <option value="<?= $m ?>" <?= ($_GET['module'] ?? '') === $m ? 'selected' : '' ?>><?= $m ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
            <?php if (empty($activity)): ?>
                <p class="text-muted">No financial activity recorded for this period.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead class="table-light"><tr>
                        <th>Date</th><th>Module</th><th>Reference</th><th>Description</th>
                        <th class="text-end">Amount</th><th>Journal</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($activity as $a): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$a['date']) ?></td>
                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($a['module']) ?></span></td>
                            <td><?= htmlspecialchars((string)($a['reference'] ?? $a['id'])) ?></td>
                            <td><?= htmlspecialchars((string)$a['description']) ?></td>
                            <td class="text-end">Shs <?= number_format((float)$a['amount'], 2) ?></td>
                            <td>
                                <?php if ($a['journal_linked']): ?>
                                    <span class="badge bg-success">Linked</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Not journalized</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        <?php elseif ($tab === 'journals'): ?>

            <form method="GET" class="row g-2 mb-3">
                <input type="hidden" name="page" value="accounting-period-view">
                <input type="hidden" name="id" value="<?= $pid ?>">
                <input type="hidden" name="tab" value="journals">
                <div class="col-auto">
                    <select name="module" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Modules</option>
                        <?php foreach (['savings','loans','loan_repayments','withdrawals','expenses','other_income'] as $m): ?>
                            <option value="<?= $m ?>" <?= ($_GET['module'] ?? '') === $m ? 'selected' : '' ?>><?= $m ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
            <?php if (empty($journals)): ?>
                <p class="text-muted">No journal entries recorded for this period.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead class="table-light"><tr>
                        <th>Entry #</th><th>Date</th><th>Source Module</th><th>Description</th>
                        <th class="text-end">Debit</th><th class="text-end">Credit</th><th>Status</th><th>Created By</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($journals as $j): ?>
                        <tr>
                            <td><?= htmlspecialchars($j['entry_number']) ?></td>
                            <td><?= date('d M Y', strtotime($j['entry_date'])) ?></td>
                            <td>
                                <?= htmlspecialchars($j['source_module'] ?? '—') ?>
                                <?php if (empty($j['source_reference_type']) && !empty($j['reversal_of_id'])): ?>
                                    <span class="badge bg-info text-dark">Reversal</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($j['description'] ?? '') ?></td>
                            <td class="text-end">Shs <?= number_format((float)$j['total_debit'], 2) ?></td>
                            <td class="text-end">Shs <?= number_format((float)$j['total_credit'], 2) ?></td>
                            <td><?= $j['reversed'] ? '<span class="badge bg-warning text-dark">Reversed</span>' : '<span class="badge bg-success">Posted</span>' ?></td>
                            <td><?= htmlspecialchars($j['created_by_name'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="text-muted small">Journals whose source_reference no longer resolves to a current row (e.g. the
            37 orphan journals documented and preserved in Stage 19D) still appear here if their date falls inside
            this period — they are historical records, not hidden data.</p>
            <?php endif; ?>

        <?php elseif ($tab === 'trial-balance'): ?>

            <?php if (empty($trialBalance['accounts'])): ?>
                <p class="text-muted">No Trial Balance activity recorded for this period.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead class="table-light"><tr><th>Code</th><th>Account</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
                    <tbody>
                    <?php foreach ($trialBalance['accounts'] as $r): if ((float)$r['debit'] === 0.0 && (float)$r['credit'] === 0.0) continue; ?>
                        <tr>
                            <td><?= htmlspecialchars($r['code']) ?></td>
                            <td><?= htmlspecialchars($r['name']) ?></td>
                            <td class="text-end">Shs <?= number_format((float)$r['debit'], 2) ?></td>
                            <td class="text-end">Shs <?= number_format((float)$r['credit'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold">
                        <tr><td colspan="2">Total</td>
                            <td class="text-end">Shs <?= number_format((float)$trialBalance['total_debit'], 2) ?></td>
                            <td class="text-end">Shs <?= number_format((float)$trialBalance['total_credit'], 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="alert <?= $trialBalance['balanced'] ? 'alert-success' : 'alert-danger' ?> mb-0">
                <?= $trialBalance['balanced'] ? 'Balanced — total debits equal total credits.' : 'NOT balanced.' ?>
            </div>
            <?php endif; ?>

        <?php elseif ($tab === 'general-ledger'): ?>

            <form method="GET" class="row g-2 mb-3">
                <input type="hidden" name="page" value="accounting-period-view">
                <input type="hidden" name="id" value="<?= $pid ?>">
                <input type="hidden" name="tab" value="general-ledger">
                <div class="col-auto">
                    <select name="account_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Select an account…</option>
                        <?php foreach ($accounts as $acc): ?>
                            <option value="<?= (int)$acc['id'] ?>" <?= ($selectedAccountId ?? 0) === (int)$acc['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($acc['code'] . ' — ' . $acc['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
            <?php if (empty($ledger)): ?>
                <p class="text-muted">Select an account above to view its General Ledger activity for this period.</p>
            <?php elseif (empty($ledger['lines'])): ?>
                <p class="text-muted">No ledger activity for this account in this period.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead class="table-light"><tr><th>Date</th><th>Journal #</th><th>Description</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
                    <tbody>
                    <?php $running = 0.0; foreach ($ledger['lines'] as $l): $running += (float)$l['debit'] - (float)$l['credit']; ?>
                        <tr>
                            <td><?= date('d M Y', strtotime($l['entry_date'])) ?></td>
                            <td><?= htmlspecialchars($l['entry_number']) ?></td>
                            <td><?= htmlspecialchars($l['line_description'] ?? $l['entry_description'] ?? '') ?></td>
                            <td class="text-end">Shs <?= number_format((float)$l['debit'], 2) ?></td>
                            <td class="text-end">Shs <?= number_format((float)$l['credit'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold"><tr><td colspan="4">Closing Balance</td><td class="text-end">Shs <?= number_format((float)$ledger['closing_balance'], 2) ?></td></tr></tfoot>
                </table>
            </div>
            <?php endif; ?>

        <?php elseif ($tab === 'income-statement'): ?>

            <?php
            $incomeRows  = array_filter($incomeStatement['income']  ?? [], fn($r) => (float)$r['net_amount'] != 0.0);
            $expenseRows = array_filter($incomeStatement['expense'] ?? [], fn($r) => (float)$r['net_amount'] != 0.0);
            ?>
            <div class="row">
                <div class="col-md-6">
                    <h6>Income</h6>
                    <?php if (empty($incomeRows)): ?><p class="text-muted">No income recorded for this period.</p>
                    <?php else: foreach ($incomeRows as $r): ?>
                        <div class="d-flex justify-content-between"><span><?= htmlspecialchars($r['name']) ?></span><span>Shs <?= number_format((float)$r['net_amount'], 2) ?></span></div>
                    <?php endforeach; endif; ?>
                    <hr><div class="d-flex justify-content-between fw-bold"><span>Total Income</span><span>Shs <?= number_format((float)($incomeStatement['total_income'] ?? 0), 2) ?></span></div>
                </div>
                <div class="col-md-6">
                    <h6>Expenses</h6>
                    <?php if (empty($expenseRows)): ?><p class="text-muted">No expenses recorded for this period.</p>
                    <?php else: foreach ($expenseRows as $r): ?>
                        <div class="d-flex justify-content-between"><span><?= htmlspecialchars($r['name']) ?></span><span>Shs <?= number_format((float)$r['net_amount'], 2) ?></span></div>
                    <?php endforeach; endif; ?>
                    <hr><div class="d-flex justify-content-between fw-bold"><span>Total Expense</span><span>Shs <?= number_format((float)($incomeStatement['total_expense'] ?? 0), 2) ?></span></div>
                </div>
            </div>

        <?php elseif ($tab === 'balance-sheet'): ?>

            <?php if (empty($balanceSheet['openingBalancesEstablished'])): ?>
                <p class="text-muted">No Balance Sheet is available yet for this period's financial year — opening
                balances have not been established (unchanged, pre-existing condition, not caused by this period).</p>
            <?php else: ?>
                <p class="text-muted">Balance Sheet for the financial year (Balance Sheets are cumulative
                since-inception figures, not period-scoped — shown here as the year-level context for this period).</p>
                <pre class="small"><?= htmlspecialchars(json_encode($balanceSheet, JSON_PRETTY_PRINT)) ?></pre>
            <?php endif; ?>

        <?php elseif ($tab === 'audit'): ?>

            <?php if (empty($audit)): ?>
                <p class="text-muted">No period-management audit events recorded yet.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead class="table-light"><tr><th>When</th><th>Action</th><th>Description</th></tr></thead>
                    <tbody>
                    <?php foreach ($audit as $a): ?>
                        <tr>
                            <td><?= date('d M Y H:i', strtotime($a['created_at'])) ?></td>
                            <td><?= htmlspecialchars(str_replace('_', ' ', $a['action'])) ?></td>
                            <td><?= htmlspecialchars($a['description']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        <?php endif; ?>
        </div>
    </div>
