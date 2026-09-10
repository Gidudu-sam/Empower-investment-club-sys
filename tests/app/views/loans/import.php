<?php $base = APP_URL . '/index.php'; ?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold" style="font-family:'Space Grotesk',sans-serif;">Import Loans</h1>
        <p class="text-muted mb-0" style="font-size:.78rem;">Upload an Excel or CSV file to bulk-import loans</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= $base ?>?page=loan-import-template" class="btn btn-outline-success btn-sm">
            <i class="bi bi-download me-1"></i>Download Template
        </a>
        <a href="<?= $base ?>?page=loans" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to Loans
        </a>
    </div>
</div>

<?php if (!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-check-circle-fill flex-shrink-0"></i><div><?= htmlspecialchars($success) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i><div><?= htmlspecialchars($error) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php
$result = Session::get('import_result');
if ($result): Session::remove('import_result');
?>
<div class="card mb-4" style="border-color:var(--green);">
    <div class="card-header" style="background:var(--green-soft);"><h6 class="mb-0 fw-semibold" style="color:var(--green);"><i class="bi bi-check-circle-fill me-2"></i>Import Report</h6></div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-3 text-center"><div class="fw-bold fs-4"><?= $result['total'] ?></div><div class="small text-muted">Total Rows</div></div>
            <div class="col-3 text-center"><div class="fw-bold fs-4" style="color:var(--green);"><?= $result['imported'] ?></div><div class="small text-muted">Imported</div></div>
            <div class="col-3 text-center"><div class="fw-bold fs-4" style="color:var(--gold);"><?= $result['skipped'] ?></div><div class="small text-muted">Skipped</div></div>
            <div class="col-3 text-center"><div class="fw-bold fs-4" style="color:var(--rust);"><?= $result['errors'] ?></div><div class="small text-muted">Errors</div></div>
        </div>
        <?php if (!empty($result['log'])): ?>
        <details><summary class="small fw-semibold" style="cursor:pointer;">View Import Log</summary>
            <div class="mt-2" style="max-height:200px;overflow-y:auto;">
                <?php foreach ($result['log'] as $entry): ?>
                <div class="small py-1 border-bottom d-flex gap-2">
                    <span class="badge <?= $entry['status'] === 'imported' ? 'bg-success-subtle text-success' : ($entry['status'] === 'skipped' ? 'bg-warning-subtle text-warning' : 'bg-danger-subtle text-danger') ?>"><?= ucfirst($entry['status']) ?></span>
                    <span>Row <?= $entry['row'] ?>: <?= htmlspecialchars($entry['message']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </details>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Upload Area -->
<div class="card mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-cloud-upload" style="color:var(--brand-navy);"></i>
        <h6 class="mb-0 fw-semibold">Upload File</h6>
    </div>
    <div class="card-body p-4">
        <div id="dropZone" class="text-center p-5 rounded-3" style="border:2px dashed var(--hairline);cursor:pointer;transition:border-color .2s;">
            <i class="bi bi-file-earmark-spreadsheet fs-1 d-block mb-2" style="color:var(--slate-soft);"></i>
            <p class="mb-1 fw-semibold">Drag & drop your file here</p>
            <p class="small text-muted mb-2">or click to browse</p>
            <span class="badge bg-secondary-subtle text-secondary">.csv .xlsx .xls</span>
            <input type="file" id="fileInput" accept=".csv,.xlsx,.xls" style="display:none;">
        </div>
        <div id="uploadProgress" class="mt-3" style="display:none;">
            <div class="progress" style="height:6px;">
                <div class="progress-bar bg-primary" id="progressBar" style="width:0%"></div>
            </div>
            <p class="small text-muted mt-1" id="uploadStatus">Uploading...</p>
        </div>
    </div>
</div>

<!-- Preview Table -->
<div id="previewSection" style="display:none;">
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h6 class="mb-0 fw-semibold"><i class="bi bi-table me-2"></i>Preview</h6>
            <div class="d-flex gap-2 align-items-center">
                <span class="badge bg-success-subtle text-success" id="readyBadge">0 Ready</span>
                <span class="badge bg-danger-subtle text-danger" id="errorBadge">0 Errors</span>
                <span class="badge bg-warning-subtle text-warning" id="skipBadge">0 Skipped</span>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:.75rem;">
                <thead>
                    <tr>
                        <th>Row</th>
                        <th>Member</th>
                        <th>Loan Type</th>
                        <th class="text-end">Amount</th>
                        <th>Duration</th>
                        <th>Rate</th>
                        <th class="text-center">Status</th>
                        <th>Issue</th>
                    </tr>
                </thead>
                <tbody id="previewBody"></tbody>
            </table>
        </div>
    </div>

    <!-- Import Button -->
    <form method="POST" action="<?= $base ?>?page=loan-import-process" id="importForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="import_data" id="importData" value="">
        <div class="d-flex gap-3 justify-content-end mb-5">
            <button type="button" class="btn btn-outline-secondary" onclick="resetUpload()">
                <i class="bi bi-arrow-counterclockwise me-1"></i>Upload Different File
            </button>
            <button type="submit" class="btn btn-primary fw-semibold px-4" id="importBtn" disabled>
                <i class="bi bi-cloud-check me-2"></i>Import <span id="importCount">0</span> Loans
            </button>
        </div>
    </form>
</div>

<script>
(function(){
'use strict';

const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const previewSection = document.getElementById('previewSection');
const previewBody = document.getElementById('previewBody');
const uploadProgress = document.getElementById('uploadProgress');
let importRows = [];

// Drag & drop
dropZone.addEventListener('click', () => fileInput.click());
dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.style.borderColor = 'var(--gold)'; });
dropZone.addEventListener('dragleave', () => { dropZone.style.borderColor = 'var(--hairline)'; });
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.style.borderColor = 'var(--hairline)';
    if (e.dataTransfer.files.length) { fileInput.files = e.dataTransfer.files; uploadFile(); }
});
fileInput.addEventListener('change', uploadFile);

function uploadFile() {
    const file = fileInput.files[0];
    if (!file) return;

    const formData = new FormData();
    formData.append('import_file', file);

    uploadProgress.style.display = 'block';
    document.getElementById('progressBar').style.width = '30%';
    document.getElementById('uploadStatus').textContent = 'Reading file...';

    fetch('<?= APP_URL ?>/index.php?page=loan-import-preview', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        document.getElementById('progressBar').style.width = '100%';
        if (data.error) {
            document.getElementById('uploadStatus').textContent = data.error;
            document.getElementById('progressBar').classList.add('bg-danger');
            return;
        }
        document.getElementById('uploadStatus').textContent = 'Preview ready!';
        renderPreview(data);
    })
    .catch(err => {
        document.getElementById('uploadStatus').textContent = 'Upload failed: ' + err.message;
        document.getElementById('progressBar').classList.add('bg-danger');
    });
}

function renderPreview(data) {
    previewSection.style.display = 'block';
    importRows = data.rows.filter(r => r.status === 'ready');

    document.getElementById('readyBadge').textContent = data.ready + ' Ready';
    document.getElementById('errorBadge').textContent = data.errors + ' Errors';
    document.getElementById('skipBadge').textContent = data.skipped + ' Skipped';
    document.getElementById('importCount').textContent = data.ready;
    document.getElementById('importBtn').disabled = data.ready === 0;
    document.getElementById('importData').value = JSON.stringify(data.rows);

    let html = '';
    data.rows.forEach(r => {
        const statusBadge = r.status === 'ready'
            ? '<span class="badge bg-success-subtle text-success">Ready</span>'
            : (r.status === 'skipped'
                ? '<span class="badge bg-warning-subtle text-warning">Skipped</span>'
                : '<span class="badge bg-danger-subtle text-danger">Error</span>');
        html += `<tr>
            <td>${r.row_num}</td>
            <td class="fw-semibold">${r.member_name || r.membership_number}</td>
            <td>${r.loan_type}</td>
            <td class="text-end">${parseInt(r.loan_amount || 0).toLocaleString()}</td>
            <td>${r.duration_months} mo</td>
            <td>${r.interest_rate}%</td>
            <td class="text-center">${statusBadge}</td>
            <td style="font-size:.65rem;color:var(--rust);">${r.error || ''}</td>
        </tr>`;
    });
    previewBody.innerHTML = html;
}

function resetUpload() {
    previewSection.style.display = 'none';
    uploadProgress.style.display = 'none';
    document.getElementById('progressBar').style.width = '0%';
    document.getElementById('progressBar').classList.remove('bg-danger');
    fileInput.value = '';
}
window.resetUpload = resetUpload;

})();
</script>
