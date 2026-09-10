<?php
$pageTitle = $pageTitle ?? 'Open Corporate Savings Account';
$title = $pageTitle;
$icon  = 'bi-bank';
$base = APP_URL . '/index.php';
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <?php if ($msg = Session::flash('error')): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-12 col-lg-8">
            <form method="POST" action="<?= $base ?>?page=savings-account-corporate-store">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                <div class="card mb-3">
                    <div class="card-header"><i class="bi bi-building me-2"></i>Section A — Organization</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input org-mode-radio" type="radio" name="org_mode" id="orgModeNew" value="new" checked>
                                <label class="form-check-label" for="orgModeNew">Create New Organization</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input org-mode-radio" type="radio" name="org_mode" id="orgModeExisting" value="existing" <?= empty($organizations) ? 'disabled' : '' ?>>
                                <label class="form-check-label" for="orgModeExisting">Select Existing Organization</label>
                            </div>
                        </div>

                        <div id="orgNewFields">
                            <div class="mb-3">
                                <label class="form-label">Organization Name</label>
                                <input type="text" name="org_name" class="form-control">
                            </div>
                            <div class="row g-2">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Registration Number</label>
                                    <input type="text" name="org_registration_number" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Contact Phone</label>
                                    <input type="text" name="org_contact_phone" class="form-control">
                                </div>
                            </div>
                            <div class="row g-2">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Contact Email</label>
                                    <input type="email" name="org_contact_email" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Address</label>
                                    <input type="text" name="org_address" class="form-control">
                                </div>
                            </div>
                        </div>

                        <div id="orgExistingFields" class="d-none">
                            <div class="mb-3">
                                <label class="form-label">Organization</label>
                                <select name="organization_id" class="form-select">
                                    <option value="">Select Organization</option>
                                    <?php foreach ($organizations as $o): ?>
                                        <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="mb-0">
                            <label class="form-label">Opening Date</label>
                            <input type="date" name="opened_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-people me-2"></i>Section B — Representatives</span>
                        <button type="button" id="addRepBtn" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-plus-circle me-1"></i> Add Representative
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info small">
                            Representatives are optional contacts for this organization's account and do not hold ownership individually.
                        </div>
                        <div id="repRows">
                            <div class="row g-2 mb-2 rep-row align-items-end">
                                <div class="col-md-3">
                                    <label class="form-label small">Full Name</label>
                                    <input type="text" name="rep_full_name[]" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Phone</label>
                                    <input type="text" name="rep_phone[]" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Role</label>
                                    <input type="text" name="rep_role[]" class="form-control form-control-sm" placeholder="e.g. Treasurer">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Linked Member (optional)</label>
                                    <select name="rep_member_id[]" class="form-select form-select-sm">
                                        <option value="">None</option>
                                        <?php foreach ($members as $m): ?>
                                            <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-check">
                                        <input type="checkbox" name="rep_signatory[0]" value="1" class="form-check-input" id="repSig0">
                                        <label class="form-check-label small" for="repSig0">Authorized Signatory</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Create Corporate Account</button>
                    <a href="<?= $base ?>?page=savings-account-open" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>

<script>
document.querySelectorAll('.org-mode-radio').forEach(function (r) {
    r.addEventListener('change', function () {
        const isNew = document.getElementById('orgModeNew').checked;
        document.getElementById('orgNewFields').classList.toggle('d-none', !isNew);
        document.getElementById('orgExistingFields').classList.toggle('d-none', isNew);
    });
});

let repIndex = 1;
document.getElementById('addRepBtn').addEventListener('click', function () {
    const rows = document.getElementById('repRows');
    const clone = rows.querySelector('.rep-row').cloneNode(true);
    clone.querySelectorAll('input[type=text], input[type=email]').forEach(i => i.value = '');
    clone.querySelectorAll('select').forEach(s => s.value = '');
    const checkbox = clone.querySelector('input[type=checkbox]');
    checkbox.checked = false;
    checkbox.name = 'rep_signatory[' + repIndex + ']';
    checkbox.id = 'repSig' + repIndex;
    clone.querySelector('label[for^="repSig"]').setAttribute('for', 'repSig' + repIndex);
    repIndex++;
    rows.appendChild(clone);
});
</script>
