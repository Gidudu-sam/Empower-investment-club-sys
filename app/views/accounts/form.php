<?php
$pageTitle = $pageTitle ?? 'Create Account';
$title = $pageTitle;
$icon  = 'bi-diagram-3';
$base = APP_URL . '/index.php';
$v = fn(string $k, string $d = '') => htmlspecialchars($old[$k] ?? $d);
$err = fn(string $k) => $errors[$k] ?? '';
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <div class="row g-3">
        <div class="col-12 col-lg-8">
            <div class="card">
                <div class="card-header"><i class="bi bi-diagram-3 me-2"></i>Create Account</div>
                <div class="card-body">
                    <div class="alert alert-info small">
                        The Chart of Accounts is already complete against the authoritative Empower account list — this form exists for genuinely new accounts only, not for editing or duplicating existing ones.
                    </div>

                    <form method="POST" action="<?= $base ?>?page=account-store">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Account Code</label>
                                <input type="text" name="code" class="form-control<?= $err('code') ? ' is-invalid' : '' ?>" value="<?= $v('code') ?>" required>
                                <?php if ($err('code')): ?><div class="invalid-feedback"><?= htmlspecialchars($err('code')) ?></div><?php endif; ?>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">Account Name</label>
                                <input type="text" name="name" class="form-control<?= $err('name') ? ' is-invalid' : '' ?>" value="<?= $v('name') ?>" required>
                                <?php if ($err('name')): ?><div class="invalid-feedback"><?= htmlspecialchars($err('name')) ?></div><?php endif; ?>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Type</label>
                                <select name="type" class="form-select<?= $err('type') ? ' is-invalid' : '' ?>" required>
                                    <option value="">Select Type</option>
                                    <?php foreach (['asset' => 'Asset', 'liability' => 'Liability', 'equity' => 'Equity', 'income' => 'Income', 'expense' => 'Expense'] as $val => $label): ?>
                                        <option value="<?= $val ?>" <?= ($old['type'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($err('type')): ?><div class="invalid-feedback"><?= htmlspecialchars($err('type')) ?></div><?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Normal Balance</label>
                                <select name="normal_balance" class="form-select<?= $err('normal_balance') ? ' is-invalid' : '' ?>" required>
                                    <option value="">Select</option>
                                    <option value="debit" <?= ($old['normal_balance'] ?? '') === 'debit' ? 'selected' : '' ?>>Debit</option>
                                    <option value="credit" <?= ($old['normal_balance'] ?? '') === 'credit' ? 'selected' : '' ?>>Credit</option>
                                </select>
                                <?php if ($err('normal_balance')): ?><div class="invalid-feedback"><?= htmlspecialchars($err('normal_balance')) ?></div><?php endif; ?>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Classification (subtype, e.g. current_asset)</label>
                            <input type="text" name="subtype" class="form-control" value="<?= $v('subtype') ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2"><?= $v('description') ?></textarea>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-1"></i> Create Account
                            </button>
                            <a href="<?= $base ?>?page=chart-of-accounts" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
