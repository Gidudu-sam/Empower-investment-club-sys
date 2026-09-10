<?php $base = APP_URL . '/index.php'; ?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold" style="font-family:'Space Grotesk',sans-serif;">Import Members</h1>
        <p class="text-muted mb-0" style="font-size:.78rem;">Upload a CSV file to bulk-register members</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= $base ?>?page=member-import-template" class="btn btn-outline-success btn-sm"><i class="bi bi-download me-1"></i>Download Template</a>
        <a href="<?= $base ?>?page=members" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
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

<?php $result = Session::get('member_import_result'); if ($result): Session::remove('member_import_result'); ?>
<div class="card mb-4" style="border-color:var(--green);"><div class="card-header" style="background:var(--green-soft);"><h6 class="mb-0 fw-semibold" style="color:var(--green);"><i class="bi bi-check-circle-fill me-2"></i>Import Report</h6></div>
<div class="card-body">
    <div class="row g-3 mb-3">
        <div class="col-3 text-center"><div class="fw-bold fs-4"><?= $result['total'] ?></div><div class="small text-muted">Total</div></div>
        <div class="col-3 text-center"><div class="fw-bold fs-4" style="color:var(--green);"><?= $result['imported'] ?></div><div class="small text-muted">Imported</div></div>
        <div class="col-3 text-center"><div class="fw-bold fs-4" style="color:var(--gold);"><?= $result['skipped'] ?></div><div class="small text-muted">Skipped</div></div>
        <div class="col-3 text-center"><div class="fw-bold fs-4" style="color:var(--rust);"><?= $result['errors'] ?></div><div class="small text-muted">Errors</div></div>
    </div>
    <?php if (!empty($result['log'])): ?>
    <details><summary class="small fw-semibold" style="cursor:pointer;">View Log</summary><div class="mt-2" style="max-height:200px;overflow-y:auto;">
        <?php foreach ($result['log'] as $e): ?>
        <div class="small py-1 border-bottom"><span class="badge <?= $e['status']==='imported'?'bg-success-subtle text-success':($e['status']==='skipped'?'bg-warning-subtle text-warning':'bg-danger-subtle text-danger') ?>"><?= ucfirst($e['status']) ?></span> Row <?= $e['row'] ?>: <?= htmlspecialchars($e['message']) ?></div>
        <?php endforeach; ?>
    </div></details>
    <?php endif; ?>
</div></div>
<?php endif; ?>

<!-- Upload -->
<div class="card mb-4"><div class="card-header d-flex align-items-center gap-2"><i class="bi bi-cloud-upload" style="color:var(--brand-navy);"></i><h6 class="mb-0 fw-semibold">Upload CSV File</h6></div>
<div class="card-body p-4">
    <div id="dropZone" class="text-center p-5 rounded-3" style="border:2px dashed var(--hairline);cursor:pointer;">
        <i class="bi bi-people fs-1 d-block mb-2" style="color:var(--slate-soft);"></i>
        <p class="mb-1 fw-semibold">Drag & drop your CSV here</p>
        <p class="small text-muted mb-2">or click to browse</p>
        <span class="badge bg-secondary-subtle text-secondary">.csv</span>
        <input type="file" id="fileInput" accept=".csv" style="display:none;">
    </div>
    <div id="uploadProgress" class="mt-3" style="display:none;">
        <div class="progress" style="height:6px;"><div class="progress-bar bg-primary" id="progressBar" style="width:0%"></div></div>
        <p class="small text-muted mt-1" id="uploadStatus">Reading...</p>
    </div>
</div></div>

<!-- Preview -->
<div id="previewSection" style="display:none;">
<div class="card mb-4"><div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 fw-semibold"><i class="bi bi-table me-2"></i>Preview</h6>
    <div class="d-flex gap-2"><span class="badge bg-success-subtle text-success" id="readyBadge">0 Ready</span><span class="badge bg-danger-subtle text-danger" id="errorBadge">0 Errors</span></div>
</div>
<div class="table-responsive"><table class="table table-hover align-middle mb-0" style="font-size:.72rem;">
    <thead><tr><th>Row</th><th>Name</th><th>Phone</th><th>NIN</th><th>Station</th><th>Next of Kin</th><th class="text-center">Status</th><th>Issue</th></tr></thead>
    <tbody id="previewBody"></tbody>
</table></div></div>

<form method="POST" action="<?= $base ?>?page=member-import-process" id="importForm">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="import_data" id="importData" value="">
    <div class="d-flex gap-3 justify-content-end mb-5">
        <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('previewSection').style.display='none';">Cancel</button>
        <button type="submit" class="btn btn-primary fw-semibold px-4" id="importBtn" disabled><i class="bi bi-people-fill me-2"></i>Import <span id="importCount">0</span> Members</button>
    </div>
</form>
</div>

<script>
(function(){
const dropZone=document.getElementById('dropZone'),fileInput=document.getElementById('fileInput');
dropZone.addEventListener('click',()=>fileInput.click());
dropZone.addEventListener('dragover',e=>{e.preventDefault();dropZone.style.borderColor='var(--gold)';});
dropZone.addEventListener('dragleave',()=>{dropZone.style.borderColor='var(--hairline)';});
dropZone.addEventListener('drop',e=>{e.preventDefault();dropZone.style.borderColor='var(--hairline)';if(e.dataTransfer.files.length){fileInput.files=e.dataTransfer.files;upload();}});
fileInput.addEventListener('change',upload);

function upload(){
    const file=fileInput.files[0]; if(!file) return;
    const fd=new FormData(); fd.append('import_file',file);
    document.getElementById('uploadProgress').style.display='block';
    document.getElementById('progressBar').style.width='50%';
    document.getElementById('uploadStatus').textContent='Reading file...';

    fetch('<?=APP_URL?>/index.php?page=member-import-preview',{method:'POST',body:fd})
    .then(r=>r.json()).then(data=>{
        document.getElementById('progressBar').style.width='100%';
        if(data.error){document.getElementById('uploadStatus').textContent=data.error;return;}
        document.getElementById('uploadStatus').textContent='Preview ready!';
        renderPreview(data);
    }).catch(e=>{document.getElementById('uploadStatus').textContent='Error: '+e.message;});
}

function renderPreview(data){
    document.getElementById('previewSection').style.display='block';
    document.getElementById('readyBadge').textContent=data.ready+' Ready';
    document.getElementById('errorBadge').textContent=data.errors+' Errors';
    document.getElementById('importCount').textContent=data.ready;
    document.getElementById('importBtn').disabled=data.ready===0;
    document.getElementById('importData').value=JSON.stringify(data.rows);

    // Stage 13-F2 (13E-XSS-01): this preview renders raw, not-yet-saved
    // CSV cell values -- exactly the untrusted data the audit flagged.
    // Every cell is now built with textContent, never innerHTML/template
    // strings, so a malicious script tag in an uploaded CSV cell renders
    // as inert text instead of executing. Only the ready/error badge is
    // real markup, and it is entirely server-controlled (never derived
    // from row data). (Deliberately not spelling out the literal payload
    // in this comment: the sequence "</" + "script>" anywhere inside this
    // <script> element -- even inside a comment or string -- ends the
    // element as far as the HTML parser is concerned, before the JS
    // engine ever sees it.)
    const tbody = document.getElementById('previewBody');
    tbody.innerHTML = '';
    data.rows.forEach(r=>{
        const tr = document.createElement('tr');

        const addCell = (text, opts={}) => {
            const td = document.createElement('td');
            if (opts.className) td.className = opts.className;
            if (opts.style) td.style.cssText = opts.style;
            td.textContent = text;
            tr.appendChild(td);
            return td;
        };

        addCell(r.row_num);
        addCell(((r.first_name||'') + ' ' + (r.last_name||'')).trim(), {className:'fw-semibold'});
        addCell(r.phone||'');
        addCell(r.national_id||'—', {style:'font-size:.65rem;'});
        addCell(r.station||'—');
        addCell(r.next_of_kin_name||'—');

        const statusTd = document.createElement('td');
        statusTd.className = 'text-center';
        const badge = document.createElement('span');
        badge.className = r.status==='ready' ? 'badge bg-success-subtle text-success' : 'badge bg-danger-subtle text-danger';
        badge.textContent = r.status==='ready' ? 'Ready' : 'Error';
        statusTd.appendChild(badge);
        tr.appendChild(statusTd);

        addCell(r.error||'', {style:'font-size:.65rem;color:var(--rust);'});

        tbody.appendChild(tr);
    });
}
})();
</script>
