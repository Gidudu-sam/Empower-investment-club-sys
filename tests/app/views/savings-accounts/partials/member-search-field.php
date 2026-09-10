<?php
/**
 * Reusable member typeahead field.
 *
 * Usage:
 *   memberSearchField(string $inputName, string $uid, string $placeholder = '…')
 *
 *   $inputName  — the POST field name that receives the member ID (e.g. 'member_id')
 *   $uid        — a unique string used to namespace IDs on this page  (e.g. 'vol')
 *   $placeholder— hint text shown in the text input
 *
 * Outputs:
 *   - A visible text input for searching
 *   - A hidden input that stores the resolved member ID
 *   - A dropdown results list
 *
 * Requires member-search-js.php to be included once per page (at bottom).
 */
function memberSearchField(string $inputName, string $uid, string $placeholder = 'Search by name or member number…'): string
{
    $base = APP_URL . '/index.php';
    return <<<HTML
<div class="member-search-wrap position-relative" data-uid="{$uid}">
    <div class="input-group">
        <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
        <input type="text"
               id="ms_text_{$uid}"
               class="form-control member-search-input"
               placeholder="{$placeholder}"
               autocomplete="off"
               data-uid="{$uid}">
    </div>
    <!-- Hidden field that actually holds the selected member ID for form submission -->
    <input type="hidden"
           id="ms_id_{$uid}"
           name="{$inputName}"
           class="member-search-id"
           data-uid="{$uid}"
           required>
    <!-- Dropdown results -->
    <div id="ms_drop_{$uid}"
         class="member-search-dropdown list-group shadow-sm position-absolute w-100"
         style="z-index:1050;display:none;max-height:260px;overflow-y:auto;top:100%;left:0;">
    </div>
    <!-- Selected display badge -->
    <div id="ms_sel_{$uid}" class="mt-2" style="display:none;">
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 d-inline-flex align-items-center gap-2">
            <i class="bi bi-person-check"></i>
            <span class="ms_sel_name_{$uid}"></span>
            <button type="button" class="btn-close btn-close-sm ms_clear_{$uid}"
                    style="font-size:.6rem;" aria-label="Clear selection"></button>
        </span>
    </div>
</div>
HTML;
}
