<?php
/**
 * Smart Member Import — embedded on the Add Member page as a second mode
 * alongside the single-member form (members/form.php includes this file
 * directly; $csrfToken is already in scope from that parent view).
 *
 * Flow: Upload -> Column Mapping Confirmation -> Editable Review Grid -> Commit.
 * Commit reuses member-import-process unchanged; the two steps before it
 * are member-import-parse (raw headers/rows + suggested mapping) and
 * member-import-validate (re-validates the admin-confirmed, mapped rows).
 */
$base = APP_URL . '/index.php';
?>
<div id="bulkImportPanel" style="display:none;">

    <!-- STEP 1: Upload -->
    <div class="card mb-4" id="importStepUpload">
        <div class="card-header d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2"><i class="bi bi-cloud-upload" style="color:var(--brand-navy);"></i><h6 class="mb-0 fw-semibold">Upload Spreadsheet</h6></div>
            <a href="<?= $base ?>?page=member-import-template" class="btn btn-outline-success btn-sm"><i class="bi bi-download me-1"></i>Download Template</a>
        </div>
        <div class="card-body p-4">
            <p class="small text-muted">Save your Excel workbook as <strong>.csv</strong> first, then upload it here. The system will try to detect your columns automatically — you confirm the mapping on the next step before anything is saved.</p>
            <div id="importDropZone" class="text-center p-5 rounded-3" style="border:2px dashed var(--hairline);cursor:pointer;">
                <i class="bi bi-file-earmark-spreadsheet fs-1 d-block mb-2" style="color:var(--slate-soft);"></i>
                <p class="mb-1 fw-semibold">Drag & drop your CSV here</p>
                <p class="small text-muted mb-2">or click to browse</p>
                <span class="badge bg-secondary-subtle text-secondary">.csv</span>
                <input type="file" id="importFileInput" accept=".csv" style="display:none;">
            </div>
            <div id="importUploadStatus" class="small text-muted mt-2"></div>
        </div>
    </div>

    <!-- STEP 2: Column Mapping Confirmation -->
    <div class="card mb-4" id="importStepMapping" style="display:none;">
        <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-diagram-3-fill" style="color:var(--brand-navy);"></i><h6 class="mb-0 fw-semibold">Confirm Column Mapping</h6></div>
        <div class="card-body p-4">
            <p class="small text-muted">For each system field, pick the matching column from your file. Leave "— Not in file —" for anything missing, and optionally type one default value to apply to every row for that field (e.g. a single Join Date for the whole batch).</p>
            <div class="table-responsive">
                <table class="table table-sm align-middle" style="font-size:.8rem;">
                    <thead><tr><th>System Field</th><th>Column in Your File</th><th>Default for Blank/Missing Rows</th></tr></thead>
                    <tbody id="mappingBody"></tbody>
                </table>
            </div>
            <div class="d-flex gap-2 justify-content-end mt-3">
                <button type="button" class="btn btn-outline-secondary" onclick="importResetToUpload()">Back</button>
                <button type="button" class="btn btn-primary fw-semibold" id="continueToReviewBtn" onclick="importContinueToReview()">Continue to Review <i class="bi bi-arrow-right ms-1"></i></button>
            </div>
        </div>
    </div>

    <!-- STEP 3: Editable Review Grid -->
    <div class="card mb-4" id="importStepReview" style="display:none;">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <h6 class="mb-0 fw-semibold"><i class="bi bi-table me-2"></i>Review &amp; Confirm Each Record</h6>
            <div class="d-flex gap-2 align-items-center">
                <span class="badge bg-success-subtle text-success" id="importReadyBadge">0 Ready</span>
                <span class="badge bg-danger-subtle text-danger" id="importErrorBadge">0 Errors</span>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="importRecheckBtn" onclick="importRecheckAll()"><i class="bi bi-arrow-clockwise me-1"></i>Re-check All</button>
            </div>
        </div>
        <p class="small text-muted px-4 pt-3 mb-0">Edit any cell to correct it. Rows with a genuine issue (e.g. missing Gender, duplicate phone/NIN) stay excluded until fixed and re-checked. Uncheck a row to skip it even if it's ready.</p>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:.72rem;">
                <thead>
                <tr>
                    <th><input type="checkbox" id="importCheckAll" checked onchange="importToggleAll(this.checked)"></th>
                    <th>Row</th><th>First Name</th><th>Last Name</th><th>Gender</th><th>DOB</th>
                    <th>Phone</th><th>Email</th><th>NIN</th><th>Station</th>
                    <th>Present Addr.</th><th>Home Addr.</th>
                    <th>NOK Name</th><th>NOK Addr.</th><th>NOK Phone</th><th>NOK Relation</th>
                    <th>Account No.</th><th>Join Date</th>
                    <th class="text-center">Status</th><th>Issue</th>
                </tr>
                </thead>
                <tbody id="reviewBody"></tbody>
            </table>
        </div>
        <div class="d-flex gap-2 justify-content-end p-3">
            <button type="button" class="btn btn-outline-secondary" onclick="importResetToUpload()">Start Over</button>
            <button type="button" class="btn btn-primary fw-semibold px-4" id="importCommitBtn" onclick="importCommit()">
                <i class="bi bi-people-fill me-2"></i>Import <span id="importCommitCount">0</span> Members
            </button>
        </div>
    </div>

</div>

<form method="POST" action="<?= $base ?>?page=member-import-process" id="importProcessForm" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="return_to" value="member-add">
    <input type="hidden" name="import_data" id="importProcessData" value="">
</form>

<script>
(function () {
'use strict';

const SYSTEM_FIELDS = [
    { key: 'first_name',           label: 'First Name',                          type: 'text' },
    { key: 'last_name',            label: 'Last Name',                           type: 'text' },
    { key: 'full_name',            label: 'Full Name (use instead of First/Last if your file has one combined column)', type: 'text' },
    { key: 'gender',               label: 'Gender',                              type: 'text' },
    { key: 'date_of_birth',        label: 'Date of Birth',                       type: 'date' },
    { key: 'phone',                label: "Mobile No.",                          type: 'text' },
    { key: 'email',                label: 'Email Address',                       type: 'text' },
    { key: 'national_id',          label: 'National ID (NIN)',                   type: 'text' },
    { key: 'station',              label: 'Station',                             type: 'text' },
    { key: 'present_address',      label: 'Present Address',                     type: 'text' },
    { key: 'home_address',         label: 'Home Address',                        type: 'text' },
    { key: 'account_number',       label: 'Account Number',                      type: 'text' },
    { key: 'next_of_kin_full',     label: 'Next of Kin Name & Address (combined column, if that is how your file has it)', type: 'text' },
    { key: 'next_of_kin_name',     label: 'Next of Kin Name',                    type: 'text' },
    { key: 'next_of_kin_address',  label: "Next of Kin's Address",               type: 'text' },
    { key: 'next_of_kin_phone',    label: "Next of Kin's Contact",               type: 'text' },
    { key: 'next_of_kin_relation', label: "Next of Kin's Relation to Member",    type: 'text' },
    { key: 'join_date',            label: 'Join Date',                           type: 'date' },
];

let state = { headers: [], rawRows: [], mapping: {}, defaults: {}, rows: [] };

const $ = (id) => document.getElementById(id);

// ---------------------------------------------------------------
// STEP 1: Upload
// ---------------------------------------------------------------
const dropZone = $('importDropZone'), fileInput = $('importFileInput');
if (dropZone) {
    dropZone.addEventListener('click', () => fileInput.click());
    dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.style.borderColor = 'var(--gold)'; });
    dropZone.addEventListener('dragleave', () => { dropZone.style.borderColor = 'var(--hairline)'; });
    dropZone.addEventListener('drop', e => {
        e.preventDefault(); dropZone.style.borderColor = 'var(--hairline)';
        if (e.dataTransfer.files.length) { fileInput.files = e.dataTransfer.files; uploadFile(); }
    });
    fileInput.addEventListener('change', uploadFile);
}

function uploadFile() {
    const file = fileInput.files[0];
    if (!file) return;
    const fd = new FormData();
    fd.append('import_file', file);
    $('importUploadStatus').textContent = 'Reading file…';

    fetch('<?= $base ?>?page=member-import-parse', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.error) { $('importUploadStatus').textContent = data.error; return; }
            state.headers = data.headers;
            state.rawRows = data.rows;
            state.mapping = data.suggestedMapping || {};
            state.defaults = {};
            $('importUploadStatus').textContent = data.total + ' row(s) found. Confirm the column mapping below.';
            renderMapping();
            $('importStepUpload').style.display = 'none';
            $('importStepMapping').style.display = '';
            $('importStepReview').style.display = 'none';
        })
        .catch(e => { $('importUploadStatus').textContent = 'Error: ' + e.message; });
}

function importResetToUpload() {
    state = { headers: [], rawRows: [], mapping: {}, defaults: {}, rows: [] };
    fileInput.value = '';
    $('importUploadStatus').textContent = '';
    $('importStepUpload').style.display = '';
    $('importStepMapping').style.display = 'none';
    $('importStepReview').style.display = 'none';
}
window.importResetToUpload = importResetToUpload;

// ---------------------------------------------------------------
// STEP 2: Column Mapping Confirmation
// ---------------------------------------------------------------
function renderMapping() {
    const tbody = $('mappingBody');
    tbody.innerHTML = '';

    SYSTEM_FIELDS.forEach(f => {
        const tr = document.createElement('tr');

        const labelTd = document.createElement('td');
        labelTd.className = 'fw-semibold';
        labelTd.textContent = f.label;
        tr.appendChild(labelTd);

        const selectTd = document.createElement('td');
        const select = document.createElement('select');
        select.className = 'form-select form-select-sm';
        select.dataset.field = f.key;
        const noneOpt = document.createElement('option');
        noneOpt.value = ''; noneOpt.textContent = '— Not in file —';
        select.appendChild(noneOpt);
        state.headers.forEach(h => {
            const opt = document.createElement('option');
            opt.value = h; opt.textContent = h;
            if (state.mapping[f.key] === h) opt.selected = true;
            select.appendChild(opt);
        });
        select.addEventListener('change', () => {
            state.mapping[f.key] = select.value;
            defaultInput.disabled = !!select.value;
            if (select.value) defaultInput.value = '';
        });
        selectTd.appendChild(select);
        tr.appendChild(selectTd);

        const defaultTd = document.createElement('td');
        const defaultInput = document.createElement('input');
        defaultInput.type = f.type === 'date' ? 'date' : 'text';
        defaultInput.className = 'form-control form-control-sm';
        defaultInput.placeholder = 'Applies to every blank row';
        defaultInput.disabled = !!state.mapping[f.key];
        defaultInput.addEventListener('input', () => { state.defaults[f.key] = defaultInput.value; });
        defaultTd.appendChild(defaultInput);
        tr.appendChild(defaultTd);

        tbody.appendChild(tr);
    });
}

function buildMappedRows() {
    return state.rawRows.map(raw => {
        const row = { row_num: raw.row_num };
        SYSTEM_FIELDS.forEach(f => {
            const header = state.mapping[f.key];
            let val = header ? (raw[header] || '') : '';
            if (!val && state.defaults[f.key]) val = state.defaults[f.key];
            row[f.key] = val;
        });
        return row;
    });
}

function importContinueToReview() {
    const mapped = buildMappedRows();
    $('continueToReviewBtn').disabled = true;
    $('continueToReviewBtn').textContent = 'Checking…';

    fetch('<?= $base ?>?page=member-import-validate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'mapped_data=' + encodeURIComponent(JSON.stringify(mapped)),
    })
        .then(r => r.json())
        .then(data => {
            $('continueToReviewBtn').disabled = false;
            $('continueToReviewBtn').innerHTML = 'Continue to Review <i class="bi bi-arrow-right ms-1"></i>';
            if (data.error) { alert(data.error); return; }
            state.rows = data.rows.map(r => Object.assign({ _include: r.status === 'ready' }, r));
            renderReview();
            $('importStepMapping').style.display = 'none';
            $('importStepReview').style.display = '';
        })
        .catch(e => {
            $('continueToReviewBtn').disabled = false;
            $('continueToReviewBtn').innerHTML = 'Continue to Review <i class="bi bi-arrow-right ms-1"></i>';
            alert('Error: ' + e.message);
        });
}
window.importContinueToReview = importContinueToReview;

// ---------------------------------------------------------------
// STEP 3: Editable Review Grid
// ---------------------------------------------------------------
const GENDER_OPTIONS = ['', 'Male', 'Female', 'Other'];

function renderReview() {
    const tbody = $('reviewBody');
    tbody.innerHTML = '';

    state.rows.forEach((row, idx) => {
        const tr = document.createElement('tr');
        tr.dataset.idx = idx;

        const checkTd = document.createElement('td');
        const check = document.createElement('input');
        check.type = 'checkbox';
        check.checked = !!row._include;
        check.addEventListener('change', () => { row._include = check.checked; });
        checkTd.appendChild(check);
        tr.appendChild(checkTd);

        const rowNumTd = document.createElement('td');
        rowNumTd.textContent = row.row_num;
        tr.appendChild(rowNumTd);

        const textField = (key) => {
            const td = document.createElement('td');
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control form-control-sm';
            input.style.minWidth = '90px';
            input.value = row[key] || '';
            input.addEventListener('input', () => { row[key] = input.value; });
            td.appendChild(input);
            return td;
        };
        // Deliberately a text input, not <input type="date"> -- a native
        // date picker silently renders blank for anything that isn't
        // already yyyy-mm-dd, which would hide the original spreadsheet
        // value from the admin the moment a date couldn't be safely
        // auto-normalized (e.g. genuinely ambiguous day/month order).
        // Text keeps whatever the server sent visible and editable.
        const dateField = (key) => {
            const td = document.createElement('td');
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control form-control-sm';
            input.style.minWidth = '90px';
            input.placeholder = 'YYYY-MM-DD';
            input.value = row[key] || '';
            input.addEventListener('input', () => { row[key] = input.value; });
            td.appendChild(input);
            return td;
        };

        tr.appendChild(textField('first_name'));
        tr.appendChild(textField('last_name'));

        // Gender: dropdown, pre-filled with the suggested guess (never
        // silently applied) when the cell arrived blank.
        const genderTd = document.createElement('td');
        const genderSelect = document.createElement('select');
        genderSelect.className = 'form-select form-select-sm';
        const currentGender = row.gender || row.suggested_gender || '';
        GENDER_OPTIONS.forEach(g => {
            const opt = document.createElement('option');
            opt.value = g; opt.textContent = g === '' ? (row.suggested_gender ? '— Uncertain, suggest: ' + row.suggested_gender + ' —' : '— Select —') : g;
            if (currentGender === g) opt.selected = true;
            genderSelect.appendChild(opt);
        });
        genderSelect.addEventListener('change', () => { row.gender = genderSelect.value; });
        genderTd.appendChild(genderSelect);
        tr.appendChild(genderTd);

        tr.appendChild(dateField('date_of_birth'));
        tr.appendChild(textField('phone'));
        tr.appendChild(textField('email'));
        tr.appendChild(textField('national_id'));
        tr.appendChild(textField('station'));
        tr.appendChild(textField('present_address'));
        tr.appendChild(textField('home_address'));
        tr.appendChild(textField('next_of_kin_name'));
        tr.appendChild(textField('next_of_kin_address'));
        tr.appendChild(textField('next_of_kin_phone'));
        tr.appendChild(textField('next_of_kin_relation'));
        tr.appendChild(textField('account_number'));
        tr.appendChild(dateField('join_date'));

        const statusTd = document.createElement('td');
        statusTd.className = 'text-center';
        const badge = document.createElement('span');
        badge.className = row.status === 'ready' ? 'badge bg-success-subtle text-success' : 'badge bg-danger-subtle text-danger';
        badge.textContent = row.status === 'ready' ? 'Ready' : 'Error';
        statusTd.appendChild(badge);
        tr.appendChild(statusTd);

        const errorTd = document.createElement('td');
        errorTd.style.cssText = 'font-size:.65rem;color:var(--rust);';
        errorTd.textContent = row.error || '';
        tr.appendChild(errorTd);

        tbody.appendChild(tr);
    });

    updateReviewCounts();
}

function updateReviewCounts() {
    const ready = state.rows.filter(r => r.status === 'ready').length;
    const errors = state.rows.filter(r => r.status !== 'ready').length;
    const included = state.rows.filter(r => r._include && r.status === 'ready').length;
    $('importReadyBadge').textContent = ready + ' Ready';
    $('importErrorBadge').textContent = errors + ' Errors';
    $('importCommitCount').textContent = included;
    $('importCommitBtn').disabled = included === 0;
}

function importToggleAll(checked) {
    state.rows.forEach(r => { if (r.status === 'ready') r._include = checked; });
    renderReview();
}
window.importToggleAll = importToggleAll;

function importRecheckAll() {
    $('importRecheckBtn').disabled = true;
    fetch('<?= $base ?>?page=member-import-validate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'mapped_data=' + encodeURIComponent(JSON.stringify(state.rows)),
    })
        .then(r => r.json())
        .then(data => {
            $('importRecheckBtn').disabled = false;
            if (data.error) { alert(data.error); return; }
            state.rows = data.rows.map((r, i) => {
                const prev = state.rows[i];
                // A row still (or newly) ready keeps the admin's existing
                // checkbox choice, defaulting to included the first time
                // it becomes ready; a row that is (still) an error is
                // always excluded until it's fixed.
                const include = r.status === 'ready'
                    ? (prev && prev.status === 'ready' ? !!prev._include : true)
                    : false;
                return Object.assign({}, r, { _include: include });
            });
            renderReview();
        })
        .catch(e => { $('importRecheckBtn').disabled = false; alert('Error: ' + e.message); });
}
window.importRecheckAll = importRecheckAll;

function importCommit() {
    $('importCommitBtn').disabled = true;
    $('importCommitBtn').innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Importing…';

    fetch('<?= $base ?>?page=member-import-validate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'mapped_data=' + encodeURIComponent(JSON.stringify(state.rows)),
    })
        .then(r => r.json())
        .then(data => {
            if (data.error) { alert(data.error); resetCommitBtn(); return; }
            const toSubmit = data.rows.filter((r, i) => r.status === 'ready' && state.rows[i] && state.rows[i]._include);
            if (!toSubmit.length) { alert('No ready rows selected to import.'); resetCommitBtn(); return; }
            $('importProcessData').value = JSON.stringify(toSubmit);
            $('importProcessForm').submit();
        })
        .catch(e => { alert('Error: ' + e.message); resetCommitBtn(); });
}
window.importCommit = importCommit;

function resetCommitBtn() {
    $('importCommitBtn').disabled = false;
    $('importCommitBtn').innerHTML = '<i class="bi bi-people-fill me-2"></i>Import <span id="importCommitCount">' + $('importCommitCount').textContent + '</span> Members';
}

})();
</script>
