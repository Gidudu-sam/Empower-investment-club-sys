<?php
/**
 * Members — Add / Edit Form
 */
$isEdit  = $formMode === 'edit';
$v       = fn(string $k, string $def = '') => htmlspecialchars($member[$k] ?? $def);
$err     = fn(string $k) => $errors[$k] ?? '';
$cls     = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800" style="font-family:'Space Grotesk',sans-serif;">
            <?= $isEdit ? 'Edit Member' : 'Add New Member' ?>
        </h1>
        <p class="text-muted mb-0 small">
            Member Number: <strong class="text-primary"><?= htmlspecialchars($memberNumber) ?></strong>
        </p>
    </div>
    <a href="<?= APP_URL ?>/index.php?page=members" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to Members
    </a>
</div>

<form id="memberForm" action="<?= $formAction ?>" method="POST" novalidate>

    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

    <!-- ── PERSONAL INFORMATION ─────────────────────────────── -->
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-person-badge text-primary"></i>
            <h6 class="mb-0 fw-semibold">Personal Information</h6>
        </div>
        <div class="card-body p-4">
            <div class="row g-3">

                <!-- First Name -->
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="first_name">
                        First Name <span class="text-danger">*</span>
                    </label>
                    <input type="text" id="first_name" name="first_name"
                           class="form-control<?= $cls('first_name') ?>"
                           value="<?= $v('first_name') ?>"
                           placeholder="e.g. John" maxlength="80" required>
                    <?php if ($err('first_name')): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($err('first_name')) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Last Name -->
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="last_name">
                        Last Name <span class="text-danger">*</span>
                    </label>
                    <input type="text" id="last_name" name="last_name"
                           class="form-control<?= $cls('last_name') ?>"
                           value="<?= $v('last_name') ?>"
                           placeholder="e.g. Doe" maxlength="80" required>
                    <?php if ($err('last_name')): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($err('last_name')) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Gender -->
                <div class="col-md-2">
                    <label class="form-label fw-semibold" for="gender">
                        Gender <span class="text-danger">*</span>
                    </label>
                    <select id="gender" name="gender"
                            class="form-select<?= $cls('gender') ?>" required>
                        <option value="">— Select —</option>
                        <?php foreach (['Male','Female','Other'] as $g): ?>
                        <option value="<?= $g ?>" <?= ($v('gender') === $g) ? 'selected' : '' ?>>
                            <?= $g ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($err('gender')): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($err('gender')) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Date of Birth -->
                <div class="col-md-2">
                    <label class="form-label fw-semibold" for="date_of_birth">
                        Date of Birth <span class="text-danger">*</span>
                    </label>
                    <input type="date" id="date_of_birth" name="date_of_birth"
                           class="form-control<?= $cls('date_of_birth') ?>"
                           value="<?= $v('date_of_birth') ?>"
                           max="<?= date('Y-m-d') ?>" required>
                    <?php if ($err('date_of_birth')): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($err('date_of_birth')) ?></div>
                    <?php endif; ?>
                </div>

                <!-- National ID (NIN) -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="national_id">
                        Official Personal No. (NIN) <span class="text-danger">*</span>
                    </label>
                    <input type="text" id="national_id" name="national_id"
                           class="form-control<?= $cls('national_id') ?>"
                           value="<?= $v('national_id') ?>"
                           placeholder="e.g. CM83021012ABCD" maxlength="50" required>
                    <?php if ($err('national_id')): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($err('national_id')) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Station (Workplace/School/Organization) -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="station">
                        Station (Workplace/School/Organization) <span class="text-danger">*</span>
                    </label>
                    <input type="text" id="station" name="station"
                           class="form-control<?= $cls('station') ?>"
                           value="<?= $v('station') ?>"
                           placeholder="e.g. Makerere University" maxlength="200" required>
                    <?php if ($err('station')): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($err('station')) ?></div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

    <!-- ── CONTACT INFORMATION ──────────────────────────────── -->
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-telephone text-primary"></i>
            <h6 class="mb-0 fw-semibold">Contact Information</h6>
        </div>
        <div class="card-body p-4">
            <div class="row g-3">

                <!-- Phone -->
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="phone">
                        Mobile No. <span class="text-danger">*</span>
                    </label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                        <input type="tel" id="phone" name="phone"
                               class="form-control<?= $cls('phone') ?>"
                               value="<?= $v('phone') ?>"
                               placeholder="0712 345 678" maxlength="20" required>
                    </div>
                    <?php if ($err('phone')): ?>
                    <div class="text-danger small mt-1"><?= htmlspecialchars($err('phone')) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Email -->
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="email">
                        Email Address <span class="text-danger">*</span>
                    </label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" id="email" name="email"
                               class="form-control<?= $cls('email') ?>"
                               value="<?= $v('email') ?>"
                               placeholder="member@example.com" maxlength="191" required>
                    </div>
                    <?php if ($err('email')): ?>
                    <div class="text-danger small mt-1"><?= htmlspecialchars($err('email')) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Present Address -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="present_address">
                        Present Address <span class="text-danger">*</span>
                    </label>
                    <textarea id="present_address" name="present_address" rows="2"
                              class="form-control<?= $cls('present_address') ?>"
                              placeholder="Current residential address…"
                              maxlength="1000" required><?= $v('present_address') ?></textarea>
                    <?php if ($err('present_address')): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($err('present_address')) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Home Address -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="home_address">
                        Home Address <span class="text-danger">*</span>
                    </label>
                    <textarea id="home_address" name="home_address" rows="2"
                              class="form-control<?= $cls('home_address') ?>"
                              placeholder="Permanent home address…"
                              maxlength="1000" required><?= $v('home_address') ?></textarea>
                    <?php if ($err('home_address')): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($err('home_address')) ?></div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

    <!-- ── NEXT OF KIN ───────────────────────────────────────── -->
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-people text-primary"></i>
            <h6 class="mb-0 fw-semibold">Next of Kin</h6>
        </div>
        <div class="card-body p-4">
            <div class="row g-3">

                <!-- Next of Kin Name -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="next_of_kin_name">
                        Next of Kin Name and Address <span class="text-danger">*</span>
                    </label>
                    <input type="text" id="next_of_kin_name" name="next_of_kin_name"
                           class="form-control<?= $cls('next_of_kin_name') ?>"
                           value="<?= $v('next_of_kin_name') ?>"
                           placeholder="Full name of next of kin" maxlength="150" required>
                    <?php if ($err('next_of_kin_name')): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($err('next_of_kin_name')) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Next of Kin Phone -->
                <div class="col-md-3">
                    <label class="form-label fw-semibold" for="next_of_kin_phone">
                        Next of Kin's Contact <span class="text-danger">*</span>
                    </label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                        <input type="tel" id="next_of_kin_phone" name="next_of_kin_phone"
                               class="form-control<?= $cls('next_of_kin_phone') ?>"
                               value="<?= $v('next_of_kin_phone') ?>"
                               placeholder="0722 000 111" maxlength="20" required>
                    </div>
                    <?php if ($err('next_of_kin_phone')): ?>
                    <div class="text-danger small mt-1"><?= htmlspecialchars($err('next_of_kin_phone')) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Next of Kin Relation -->
                <div class="col-md-3">
                    <label class="form-label fw-semibold" for="next_of_kin_relation">
                        Relation to Member <span class="text-danger">*</span>
                    </label>
                    <?php
                    $allRelations  = \MemberModel::relationshipOptions();
                    $savedRelation = $v('next_of_kin_relation');
                    // If the saved value isn't in the list, it was a custom "Other" value
                    $isCustom      = $savedRelation !== '' && !in_array($savedRelation, $allRelations);
                    $selectValue   = $isCustom ? 'Other' : $savedRelation;
                    ?>
                    <select id="next_of_kin_relation" name="next_of_kin_relation"
                            class="form-select<?= $cls('next_of_kin_relation') ?>" required>
                        <option value="">— Select —</option>
                        <?php foreach ($allRelations as $rel): ?>
                        <option value="<?= $rel ?>" <?= ($selectValue === $rel) ? 'selected' : '' ?>>
                            <?= $rel ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($err('next_of_kin_relation')): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($err('next_of_kin_relation')) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Custom relation (shown when Other is selected) -->
                <div class="col-md-3" id="relationOtherGroup" style="<?= ($selectValue === 'Other') ? '' : 'display:none' ?>">
                    <label class="form-label fw-semibold" for="next_of_kin_relation_other">
                        Specify Relationship <span class="text-danger">*</span>
                    </label>
                    <input type="text" id="next_of_kin_relation_other" name="next_of_kin_relation_other"
                           class="form-control"
                           value="<?= $isCustom ? htmlspecialchars($savedRelation) : '' ?>"
                           placeholder="e.g. Step-brother, Fiancé…" maxlength="80">
                </div>

                <!-- Next of Kin Address -->
                <div class="col-12">
                    <label class="form-label fw-semibold" for="next_of_kin_address">
                        Next of Kin's Address
                    </label>
                    <textarea id="next_of_kin_address" name="next_of_kin_address" rows="2"
                              class="form-control"
                              placeholder="Address of next of kin…"
                              maxlength="500"><?= $v('next_of_kin_address') ?></textarea>
                </div>

            </div>
        </div>
    </div>

    <!-- ── ACCOUNT INFORMATION ──────────────────────────────── -->
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-card-checklist text-primary"></i>
            <h6 class="mb-0 fw-semibold">Account Information</h6>
        </div>
        <div class="card-body p-4">
            <div class="row g-3">

                <!-- Account Number -->
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="account_number">
                        Account Number
                        <span class="badge bg-info-subtle text-info ms-1" style="font-size:.6rem;">OPTIONAL</span>
                    </label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-hash"></i></span>
                        <input type="text" id="account_number" name="account_number"
                               class="form-control<?= $cls('account_number') ?>"
                               value="<?= $v('account_number') ?>"
                               placeholder="e.g. <?= htmlspecialchars($nextAccountNumber ?? '100001') ?>"
                               maxlength="30">
                    </div>
                    <?php if ($err('account_number')): ?>
                    <div class="text-danger small mt-1"><?= htmlspecialchars($err('account_number')) ?></div>
                    <?php else: ?>
                    <div class="form-text text-muted">
                        Leave blank to assign later. Suggested next: <strong><?= htmlspecialchars($nextAccountNumber ?? '') ?></strong>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Member Number (read-only) -->
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Member Number</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                        <input type="text" class="form-control bg-light fw-bold text-primary"
                               value="<?= htmlspecialchars($memberNumber) ?>" readonly>
                    </div>
                    <div class="form-text text-muted">Auto-assigned by system.</div>
                </div>

                <!-- Join Date -->
                <div class="col-md-3">
                    <label class="form-label fw-semibold" for="join_date">
                        Join Date <span class="text-danger">*</span>
                    </label>
                    <input type="date" id="join_date" name="join_date"
                           class="form-control<?= $cls('join_date') ?>"
                           value="<?= $v('join_date', date('Y-m-d')) ?>"
                           max="<?= date('Y-m-d') ?>" required>
                    <?php if ($err('join_date')): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($err('join_date')) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Status -->
                <div class="col-md-2">
                    <label class="form-label fw-semibold" for="status">Status</label>
                    <select id="status" name="status" class="form-select">
                        <option value="active"
                            <?= ($v('status','active') === 'active') ? 'selected' : '' ?>>Active</option>
                        <option value="inactive"
                            <?= ($v('status','active') === 'inactive') ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>

            </div>
        </div>
    </div>

    <!-- Form action buttons -->
    <div class="d-flex align-items-center gap-3 justify-content-end mb-5 flex-wrap">
        <button type="reset" class="btn btn-light px-4">
            <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
        </button>
        <a href="<?= APP_URL ?>/index.php?page=members" class="btn btn-outline-secondary px-4">
            <i class="bi bi-x-lg me-1"></i>Cancel
        </a>
        <button type="submit" id="submitBtn" class="btn btn-primary px-5 fw-semibold">
            <i class="bi bi-<?= $isEdit ? 'floppy' : 'person-plus-fill' ?> me-2"></i>
            <?= $isEdit ? 'Save Changes' : 'Add Member' ?>
        </button>
    </div>

</form>

<script>
(function () {
    'use strict';
    const form = document.getElementById('memberForm');
    const btn  = document.getElementById('submitBtn');

    form.addEventListener('submit', function (e) {
        if (!form.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
            const first = form.querySelector(':invalid');
            if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Saving…';
        }
        form.classList.add('was-validated');
    });

    // Phone number filter
    ['phone', 'next_of_kin_phone'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', function () {
            this.value = this.value.replace(/[^0-9+\-\s()]/g, '');
        });
    });

    // Show/hide custom relationship "Other" input
    const relSelect   = document.getElementById('next_of_kin_relation');
    const otherGroup  = document.getElementById('relationOtherGroup');
    const otherInput  = document.getElementById('next_of_kin_relation_other');
    function toggleOther() {
        const show = relSelect && relSelect.value === 'Other';
        if (otherGroup) otherGroup.style.display = show ? '' : 'none';
        if (otherInput) otherInput.required = show;
    }
    if (relSelect) {
        relSelect.addEventListener('change', toggleOther);
        toggleOther();
    }
})();
</script>
