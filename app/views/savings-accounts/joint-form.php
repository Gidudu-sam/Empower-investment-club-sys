<?php
$pageTitle = $pageTitle ?? 'Open Joint Savings Account';
$title     = $pageTitle;
$icon      = 'bi-bank';
$base      = APP_URL . '/index.php';
require_once VIEW_PATH . '/savings-accounts/partials/member-search-field.php';
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <?php if ($msg = Session::flash('error')): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-12 col-lg-7">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-people me-2 text-success"></i>Open Joint Savings Account
                </div>
                <div class="card-body">
                    <div class="alert alert-info small d-flex gap-2 mb-4">
                        <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
                        <span>
                            Select at least two members. One holder may be designated primary.
                        </span>
                    </div>

                    <form method="POST" action="<?= $base ?>?page=savings-account-joint-store">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <!-- primary_member_id: synced to the selected member's ID in whichever row has the Primary radio checked -->
                        <input type="hidden" name="primary_member_id" id="primaryMemberIdField" value="">

                        <!-- Holder rows -->
                        <div id="holderRows">

                            <!-- Holder 1 -->
                            <div class="holder-row mb-3 p-3 border rounded-3" data-index="0">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <span class="fw-semibold small text-muted">Holder 1</span>
                                    <div class="form-check form-check-inline mb-0">
                                        <input class="form-check-input primary-radio" type="radio"
                                               name="primary_holder_row" value="0" id="primary_0">
                                        <label class="form-check-label small" for="primary_0">
                                            Primary holder
                                        </label>
                                    </div>
                                </div>
                                <!-- Hidden member_id for this holder -->
                                <input type="hidden" name="member_ids[]"
                                       id="ms_id_jnt0"
                                       class="member-search-id joint-member-id"
                                       data-uid="jnt0" required>
                                <!-- Typeahead -->
                                <div class="member-search-wrap position-relative" data-uid="jnt0">
                                    <div class="input-group">
                                        <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                                        <input type="text"
                                               id="ms_text_jnt0"
                                               class="form-control member-search-input"
                                               placeholder="Search by name or member number…"
                                               autocomplete="off"
                                               data-uid="jnt0">
                                    </div>
                                    <div id="ms_drop_jnt0"
                                         class="member-search-dropdown list-group shadow-sm position-absolute w-100"
                                         style="z-index:1050;display:none;max-height:240px;overflow-y:auto;top:100%;left:0;"></div>
                                    <div id="ms_sel_jnt0" class="mt-2" style="display:none;">
                                        <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 d-inline-flex align-items-center gap-2">
                                            <i class="bi bi-person-check"></i>
                                            <span class="ms_sel_name_jnt0"></span>
                                            <button type="button" class="btn-close ms_clear_jnt0" style="font-size:.6rem;" aria-label="Clear"></button>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Holder 2 -->
                            <div class="holder-row mb-3 p-3 border rounded-3" data-index="1">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <span class="fw-semibold small text-muted">Holder 2</span>
                                    <div class="form-check form-check-inline mb-0">
                                        <input class="form-check-input primary-radio" type="radio"
                                               name="primary_holder_row" value="1" id="primary_1">
                                        <label class="form-check-label small" for="primary_1">
                                            Primary holder
                                        </label>
                                    </div>
                                </div>
                                <input type="hidden" name="member_ids[]"
                                       id="ms_id_jnt1"
                                       class="member-search-id joint-member-id"
                                       data-uid="jnt1" required>
                                <div class="member-search-wrap position-relative" data-uid="jnt1">
                                    <div class="input-group">
                                        <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                                        <input type="text"
                                               id="ms_text_jnt1"
                                               class="form-control member-search-input"
                                               placeholder="Search by name or member number…"
                                               autocomplete="off"
                                               data-uid="jnt1">
                                    </div>
                                    <div id="ms_drop_jnt1"
                                         class="member-search-dropdown list-group shadow-sm position-absolute w-100"
                                         style="z-index:1050;display:none;max-height:240px;overflow-y:auto;top:100%;left:0;"></div>
                                    <div id="ms_sel_jnt1" class="mt-2" style="display:none;">
                                        <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 d-inline-flex align-items-center gap-2">
                                            <i class="bi bi-person-check"></i>
                                            <span class="ms_sel_name_jnt1"></span>
                                            <button type="button" class="btn-close ms_clear_jnt1" style="font-size:.6rem;" aria-label="Clear"></button>
                                        </span>
                                    </div>
                                </div>
                            </div>

                        </div><!-- /#holderRows -->

                        <button type="button" id="addHolderBtn"
                                class="btn btn-sm btn-outline-secondary mb-4">
                            <i class="bi bi-plus-circle me-1"></i>Add Another Holder
                        </button>

                        <div class="mb-4">
                            <label class="form-label fw-semibold">Opening Date</label>
                            <input type="date" name="opened_date" class="form-control"
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-success px-4">
                                <i class="bi bi-bank me-1"></i>Create Account
                            </button>
                            <a href="<?= $base ?>?page=savings-account-open" class="btn btn-outline-secondary">
                                Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

<?php require VIEW_PATH . '/savings-accounts/partials/member-search-js.php'; ?>

<script>
// ── Sync primary_member_id whenever a radio or a member selection changes ─
(function () {
    function syncPrimary() {
        var checked = document.querySelector('input[name="primary_holder_row"]:checked');
        if (!checked) { document.getElementById('primaryMemberIdField').value = ''; return; }
        var row = checked.closest('.holder-row');
        if (!row) return;
        var uid = row.dataset.index !== undefined ? 'jnt' + row.dataset.index : null;
        if (!uid) return;
        var hiddenId = document.getElementById('ms_id_' + uid);
        document.getElementById('primaryMemberIdField').value = hiddenId ? hiddenId.value : '';
    }

    // Re-sync whenever any radio changes
    document.addEventListener('change', function (e) {
        if (e.target.name === 'primary_holder_row' || e.target.classList.contains('member-search-id')) {
            syncPrimary();
        }
    });

    // Also expose for use after a member is selected via typeahead
    window.syncPrimaryMember = syncPrimary;
})();
(function () {
    var holderCount = 2; // 0 and 1 already rendered

    document.getElementById('addHolderBtn').addEventListener('click', function () {
        var idx  = holderCount++;
        var uid  = 'jnt' + idx;
        var row  = document.createElement('div');
        row.className   = 'holder-row mb-3 p-3 border rounded-3';
        row.dataset.index = idx;
        row.innerHTML =
            '<div class="d-flex align-items-center justify-content-between mb-2">' +
            '  <span class="fw-semibold small text-muted">Holder ' + (idx + 1) + '</span>' +
            '  <div class="form-check form-check-inline mb-0">' +
            '    <input class="form-check-input primary-radio" type="radio" name="primary_holder_row" value="' + idx + '" id="primary_' + idx + '">' +
            '    <label class="form-check-label small" for="primary_' + idx + '">Primary holder</label>' +
            '  </div>' +
            '</div>' +
            '<input type="hidden" name="member_ids[]" id="ms_id_' + uid + '" class="member-search-id joint-member-id" data-uid="' + uid + '" required>' +
            '<div class="member-search-wrap position-relative" data-uid="' + uid + '">' +
            '  <div class="input-group">' +
            '    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>' +
            '    <input type="text" id="ms_text_' + uid + '" class="form-control member-search-input"' +
            '           placeholder="Search by name or member number…" autocomplete="off" data-uid="' + uid + '">' +
            '  </div>' +
            '  <div id="ms_drop_' + uid + '" class="member-search-dropdown list-group shadow-sm position-absolute w-100"' +
            '       style="z-index:1050;display:none;max-height:240px;overflow-y:auto;top:100%;left:0;"></div>' +
            '  <div id="ms_sel_' + uid + '" class="mt-2" style="display:none;">' +
            '    <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 d-inline-flex align-items-center gap-2">' +
            '      <i class="bi bi-person-check"></i>' +
            '      <span class="ms_sel_name_' + uid + '"></span>' +
            '      <button type="button" class="btn-close ms_clear_' + uid + '" style="font-size:.6rem;" aria-label="Clear"></button>' +
            '    </span>' +
            '  </div>' +
            '</div>' +
            '<button type="button" class="btn btn-sm btn-link text-danger mt-2 remove-holder-btn">' +
            '  <i class="bi bi-trash me-1"></i>Remove</button>';

        document.getElementById('holderRows').appendChild(row);

        // Init the new typeahead field (the main member-search-js already
        // handles fields present at DOMContentLoaded; new fields added
        // dynamically need manual init — call initMemberSearch defined below)
        if (typeof initMemberSearchField === 'function') {
            initMemberSearchField(uid);
        }
    });

    // ── Remove holder ──────────────────────────────────────────────────
    document.getElementById('holderRows').addEventListener('click', function (e) {
        var btn = e.target.closest('.remove-holder-btn');
        if (!btn) return;
        var rows = this.querySelectorAll('.holder-row');
        if (rows.length <= 2) {
            alert('A joint account requires at least two holders.');
            return;
        }
        btn.closest('.holder-row').remove();
    });
})();
</script>
