<?php
/**
 * Export buttons partial — expects $reportId (element ID containing the report content)
 * and $whatsappText (pre-formatted message for WhatsApp)
 */
$reportId    = $reportId ?? 'reportContent';
$whatsappMsg = $whatsappText ?? '';
?>
<div class="d-flex gap-2 flex-wrap no-print">
    <button onclick="copyReport()" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-clipboard me-1"></i>Copy
    </button>
    <a href="https://wa.me/?text=<?= rawurlencode($whatsappMsg) ?>" target="_blank" class="btn btn-sm" style="background:#25D366;color:#fff;border:none;">
        <i class="bi bi-whatsapp me-1"></i>WhatsApp
    </a>
    <button onclick="exportTableCSV()" class="btn btn-outline-success btn-sm">
        <i class="bi bi-file-earmark-excel me-1"></i>Excel
    </button>
    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-printer me-1"></i>Print
    </button>
</div>

<script>
function copyReport() {
    const text = <?= json_encode($whatsappMsg) ?>;
    navigator.clipboard.writeText(text).then(() => {
        const btn = event.target.closest('button');
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check me-1"></i>Copied!';
        btn.classList.add('btn-success');
        btn.classList.remove('btn-outline-secondary');
        setTimeout(() => { btn.innerHTML = orig; btn.classList.remove('btn-success'); btn.classList.add('btn-outline-secondary'); }, 2000);
    });
}

function exportTableCSV() {
    const table = document.getElementById('<?= $reportId ?>');
    if (!table) return;
    let csv = [];
    table.querySelectorAll('tr').forEach(row => {
        let cols = [];
        row.querySelectorAll('td, th').forEach(col => cols.push('"' + col.innerText.replace(/"/g, '""') + '"'));
        csv.push(cols.join(','));
    });
    const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'report_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
}
</script>
