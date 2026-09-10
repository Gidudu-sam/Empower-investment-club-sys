<script>
/**
 * Member typeahead — wires up all .member-search-input elements on the page.
 * Calls savings-member-search?q= which returns {members:[{id,full_name,member_number,phone}]}
 *
 * Also exposes window.initMemberSearchField(uid) so dynamically added rows
 * (joint account "Add Another Holder") can be wired up after insertion.
 */
(function () {
    var SEARCH_URL = '<?= APP_URL ?>/index.php?page=savings-member-search&q=';
    var debounceTimers = {};

    function initField(uid) {
        var textInput = document.getElementById('ms_text_' + uid);
        var hiddenId  = document.getElementById('ms_id_'   + uid);
        var dropdown  = document.getElementById('ms_drop_'  + uid);
        var selBadge  = document.getElementById('ms_sel_'   + uid);
        var selName   = selBadge ? selBadge.querySelector('.ms_sel_name_' + uid) : null;
        var clearBtn  = selBadge ? selBadge.querySelector('.ms_clear_' + uid)    : null;

        if (!textInput || !hiddenId || !dropdown) return;

        // ── Type to search ──────────────────────────────────────────────
        textInput.addEventListener('input', function () {
            var q = this.value.trim();
            clearSelection();
            if (q.length < 2) { hideDropdown(); return; }

            clearTimeout(debounceTimers[uid]);
            debounceTimers[uid] = setTimeout(function () {
                fetch(SEARCH_URL + encodeURIComponent(q))
                    .then(function (r) { return r.json(); })
                    .then(function (data) { renderResults(data.members || []); })
                    .catch(function () { hideDropdown(); });
            }, 220);
        });

        // ── Render results ──────────────────────────────────────────────
        function renderResults(members) {
            dropdown.innerHTML = '';
            if (members.length === 0) {
                dropdown.innerHTML =
                    '<div class="list-group-item text-muted small py-2 px-3">' +
                    '<i class="bi bi-search me-2"></i>No members found</div>';
                showDropdown();
                return;
            }
            members.forEach(function (m) {
                var item = document.createElement('button');
                item.type = 'button';
                item.className = 'list-group-item list-group-item-action py-2 px-3';
                item.innerHTML =
                    '<div class="d-flex align-items-center gap-2">' +
                    '  <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary bg-opacity-10 text-primary fw-semibold flex-shrink-0" style="width:32px;height:32px;font-size:.75rem;">' +
                         initials(m.full_name) +
                    '  </span>' +
                    '  <div>' +
                    '    <div class="fw-semibold small">' + escHtml(m.full_name) + '</div>' +
                    '    <div class="text-muted" style="font-size:.72rem;">' + escHtml(m.member_number) +
                         (m.phone ? ' &middot; ' + escHtml(m.phone) : '') + '</div>' +
                    '  </div>' +
                    '</div>';
                item.addEventListener('click', function () {
                    selectMember(m.id, m.full_name + ' (' + m.member_number + ')');
                });
                dropdown.appendChild(item);
            });
            showDropdown();
        }

        // ── Select a member ─────────────────────────────────────────────
        function selectMember(id, label) {
            hiddenId.value = id;
            textInput.value = '';
            hideDropdown();
            // Hide input-group, show selected badge
            var wrap = textInput.closest('.input-group');
            if (wrap) wrap.style.display = 'none';
            if (selBadge) {
                if (selName) selName.textContent = label;
                selBadge.style.display = '';
            }
        }

        // ── Clear selection ─────────────────────────────────────────────
        function clearSelection() {
            hiddenId.value = '';
            if (selBadge) selBadge.style.display = 'none';
            var wrap = textInput.closest('.input-group');
            if (wrap) wrap.style.display = '';
        }

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                clearSelection();
                textInput.focus();
            });
        }

        // ── Close on outside click ──────────────────────────────────────
        document.addEventListener('click', function (e) {
            if (!dropdown.contains(e.target) && e.target !== textInput) {
                hideDropdown();
            }
        });

        function showDropdown() { dropdown.style.display = ''; }
        function hideDropdown() { dropdown.style.display = 'none'; }
    }

    // ── Helpers ─────────────────────────────────────────────────────────
    function initials(name) {
        var parts = (name || '').trim().split(/\s+/);
        return ((parts[0] ? parts[0][0] : '') + (parts[1] ? parts[1][0] : '')).toUpperCase();
    }
    function escHtml(str) {
        return String(str)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

    // ── Init all fields present at load ────────────────────────────────
    document.querySelectorAll('.member-search-input').forEach(function (el) {
        initField(el.dataset.uid);
    });

    // ── Expose for dynamic rows (joint account add-holder) ──────────────
    window.initMemberSearchField = initField;
})();
</script>
