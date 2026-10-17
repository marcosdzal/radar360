// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Sentinel rubric editor/viewer.
 *
 * init()     → edit page:    injects an editable <select> per criterion row.
 * initView() → preview/grade: injects a read-only <td> column per criterion row.
 *
 * @module     local_sentinel/rubric_editor
 * @copyright  2024 Marcos
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

/**
 * Build a short label from the full category name.
 * Extracts the numeric prefix (e.g. "1.1", "3.2") when present.
 *
 * @param {string} name Full category name.
 * @returns {string}
 */
/**
 * Sort comparator for grade categories.
 * Extracts numeric prefix (e.g. "1", "1.1", "2.1") and sorts hierarchically:
 * parent categories before their subcategories, non-numeric names at the end.
 */
const categorySort = (a, b) => a._i - b._i;

// Module-level UI strings — overridden by PHP-passed strings at init time.
var _S = {
    label:      '📌 Criterio de evaluación (Radar 360):',
    tooltip:    'Asignar esta fila de rúbrica a un criterio de evaluación del libro de calificaciones',
    unassigned: '— Sin asignar —',
    pending:    '💾 Se guardará al publicar',
    neterror:   '❌ Error de red',
    header:     'CE (Radar 360)',
};

// Versión corta para el desplegable de edición (espacio reducido).
const shortLabel = (name) => {
    const match = name.match(/^(\d+[\.\d]*)[.\s]/);
    return match ? `CE ${match[1]} – ${name.substring(match[0].length).trim().slice(0, 55)}` : name.slice(0, 60);
};

// Versión completa para la columna de vista en rúbrica y calificación.
const fullLabel = (name) => {
    const match = name.match(/^(\d+[\.\d]*)[.\s]/);
    return match ? `CE ${match[1]} – ${name.substring(match[0].length).trim()}` : name;
};

/**
 * Extract the numeric criterion id from a rubric row element.
 * Row ids follow the pattern: "{prefix}-criteria-{id}"
 * Returns null for unsaved (draft) rows whose id contains "NEWID".
 *
 * @param {HTMLElement} row
 * @returns {number|null}
 */
const criterionIdFrom = (row) => {
    const m = row.id.match(/-criteria-(\d+)$/);
    return m ? parseInt(m[1], 10) : null;
};

/** True when the row belongs to an unsaved (draft) criterion. */
const isDraftRow = (row) => /NEWID/i.test(row.id);

/** Index of this row among all tr.criterion in its table. */
const criterionIndexFrom = (row) => {
    const table = row.closest('table.criteria');
    if (!table) { return -1; }
    return [...table.querySelectorAll('tr.criterion')].indexOf(row);
};

// ---------------------------------------------------------------------------
// localStorage helpers for draft mappings
// ---------------------------------------------------------------------------

const pendingKey = () => {
    const m = window.location.search.match(/areaid=(\d+)/);
    return 'sentinel_pending_' + (m ? m[1] : 'new');
};

const loadPending  = () => { try { return JSON.parse(localStorage.getItem(pendingKey()) || '{}'); } catch(e) { return {}; } };
const clearPending = () => { try { localStorage.removeItem(pendingKey()); } catch(e) {} };
const savePending  = (index, categoryId) => {
    try {
        const data = loadPending();
        if (parseInt(categoryId, 10) === 0) {
            delete data[index];
        } else {
            data[index] = parseInt(categoryId, 10);
        }
        localStorage.setItem(pendingKey(), JSON.stringify(data));
    } catch(e) {}
};

// ---------------------------------------------------------------------------
// EDIT MODE – interactive <select> injected into each criterion row
// ---------------------------------------------------------------------------

const injectSelect = (row, categories, mappings, ajaxUrl, sesskey) => {
    const criterionId = criterionIdFrom(row);
    const draft       = isDraftRow(row);

    // Skip rows that have neither a real ID nor a NEWID marker.
    if (criterionId === null && !draft) { return; }

    // Avoid double-injection on page reuse.
    if (row.querySelector('.sentinel-category-select')) { return; }

    const label = document.createElement('label');
    label.className = 'sentinel-category-label';
    label.style.cssText = 'display:block;margin-top:8px;font-size:0.85em;color:#555;';
    label.textContent = _S.label;

    const select = document.createElement('select');
    select.className = 'sentinel-category-select form-control form-control-sm';
    select.style.cssText = 'margin-top:4px;font-size:0.82em;';
    select.title = _S.tooltip;

    const blank = document.createElement('option');
    blank.value = '0';
    blank.textContent = _S.unassigned;
    select.appendChild(blank);

    let currentGroup = null;
    categories.map((c, i) => ({...c, _i: i})).sort(categorySort).forEach(cat => {
        if (cat.depth === 2) {
            currentGroup = document.createElement('optgroup');
            currentGroup.label = shortLabel(cat.name);
            currentGroup.title = cat.name;
            select.appendChild(currentGroup);
        } else {
            const opt = document.createElement('option');
            opt.value = cat.id;
            opt.textContent = shortLabel(cat.name);
            opt.title = cat.name;
            (currentGroup || select).appendChild(opt);
        }
    });

    // Pre-select: real mapping for saved rows, localStorage for draft rows.
    if (!draft && mappings[criterionId]) {
        select.value = String(mappings[criterionId]);
    } else if (draft) {
        const idx     = criterionIndexFrom(row);
        const pending = loadPending();
        if (pending[idx]) { select.value = String(pending[idx]); }
    }

    const status = document.createElement('span');
    status.className = 'sentinel-status';
    status.style.cssText = 'font-size:0.78em;margin-left:6px;';

    select.addEventListener('change', async() => {
        if (draft) {
            // Row not yet saved — keep selection in localStorage.
            const idx = criterionIndexFrom(row);
            savePending(idx, select.value);
            status.textContent = _S.pending;
            setTimeout(() => { status.textContent = ''; }, 2500);
            return;
        }
        status.textContent = '⏳';
        try {
            const body = new URLSearchParams({
                sesskey,
                rubric_row_id: criterionId,
                grade_category_id: select.value,
            });
            const resp = await fetch(ajaxUrl, {method: 'POST', body});
            const data = await resp.json();
            status.textContent = data.success ? '✅' : '❌ ' + (data.error ?? '');
        } catch (e) {
            status.textContent = _S.neterror;
        }
    });

    const descCell = row.querySelector('td.description');
    if (descCell) {
        const wrapper = document.createElement('div');
        wrapper.className = 'sentinel-widget';
        wrapper.appendChild(label);
        wrapper.appendChild(select);
        wrapper.appendChild(status);

        // Prevent clicks on the Sentinel widget from triggering Moodle's
        // native rubric editor (which toggles textarea visibility on td.description clicks).
        ['mousedown', 'click', 'focus'].forEach(evt => {
            wrapper.addEventListener(evt, e => e.stopPropagation(), true);
        });

        descCell.appendChild(wrapper);
    }
};

/**
 * After the rubric is saved (page reload), apply any pending draft mappings
 * from localStorage by index and then clear them.
 */
const applyPendingMappings = async(table, ajaxUrl, sesskey) => {
    const pending = loadPending();
    if (!Object.keys(pending).length) { return; }

    const rows = [...table.querySelectorAll('tr.criterion')];
    let anyApplied = false;

    for (const [idxStr, categoryId] of Object.entries(pending)) {
        const idx         = parseInt(idxStr, 10);
        const row         = rows[idx];
        const criterionId = row ? criterionIdFrom(row) : null;
        if (!criterionId || !categoryId) { continue; }

        try {
            const body = new URLSearchParams({
                sesskey,
                rubric_row_id:     criterionId,
                grade_category_id: categoryId,
            });
            const resp = await fetch(ajaxUrl, {method: 'POST', body});
            const data = await resp.json();
            if (data.success) {
                const sel = row.querySelector('.sentinel-category-select');
                if (sel) { sel.value = String(categoryId); }
                const st = row.querySelector('.sentinel-status');
                if (st) { st.textContent = '✅'; setTimeout(() => { st.textContent = ''; }, 2000); }
                anyApplied = true;
            }
        } catch(e) { /* silent */ }
    }

    if (anyApplied) { clearPending(); }
};

const observeNewRows = (table, categories, mappings, ajaxUrl, sesskey) => {
    // MutationObserver and click events both fail with Moodle's YUI-based rubric
    // editor (YUI calls e.halt() which stops propagation). A periodic poll is the
    // most reliable approach: injectSelect is idempotent so re-scanning is safe.
    setInterval(() => {
        table.querySelectorAll('tr.criterion').forEach(row => {
            injectSelect(row, categories, mappings, ajaxUrl, sesskey);
        });
    }, 300);
};

/**
 * Entry point for the rubric EDIT page.
 *
 * @param {Array}  categories [{id, name}, …]
 * @param {Object} mappings   {criterionId: categoryId}
 * @param {string} ajaxUrl
 * @param {string} sesskey
 */
const init = (categories, mappings, ajaxUrl, sesskey, strings) => {
    if (strings) { Object.assign(_S, strings); }
    const run = () => {
        const table = document.querySelector('table.criteria');
        if (!table) { return; }

        table.querySelectorAll('tr.criterion').forEach(row => {
            injectSelect(row, categories, mappings, ajaxUrl, sesskey);
        });
        observeNewRows(table, categories, mappings, ajaxUrl, sesskey);

        // If there are pending draft mappings and all rows now have real IDs,
        // the rubric was just saved — apply and clear them.
        const hasDraftRows = [...table.querySelectorAll('tr.criterion')].some(isDraftRow);
        if (!hasDraftRows) {
            applyPendingMappings(table, ajaxUrl, sesskey);
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
};

// ---------------------------------------------------------------------------
// VIEW MODE – read-only column injected into preview and grading pages
// ---------------------------------------------------------------------------

const addViewHeader = (table) => {
    const thead = table.querySelector('thead');
    if (!thead) {
        return;
    }
    const headerRow = thead.querySelector('tr');
    if (!headerRow || headerRow.querySelector('.sentinel-view-th')) {
        return;
    }
    const th = document.createElement('th');
    th.className = 'sentinel-view-th';
    th.textContent = _S.header;
    th.style.cssText = 'font-size:0.85em;';
    headerRow.appendChild(th);
};

const injectViewCell = (row, categories, mappings) => {
    if (row.querySelector('.sentinel-view-cell')) {
        return;
    }
    const criterionId = criterionIdFrom(row);
    if (criterionId === null) {
        return;
    }

    const td = document.createElement('td');
    td.className = 'sentinel-view-cell';
    td.style.cssText = 'font-size:0.82em;vertical-align:top;padding:6px 8px;';

    const categoryId = mappings[criterionId];
    if (categoryId) {
        const cat = categories.find(c => c.id === categoryId);
        td.textContent = cat ? fullLabel(cat.name) : '—';
        td.style.color = '#1a5276';
    } else {
        td.textContent = '—';
        td.style.color = '#aaa';
    }

    row.appendChild(td);
};

const observeNewRowsView = (table, categories, mappings) => {
    const observer = new MutationObserver(mutations => {
        mutations.forEach(m => {
            m.addedNodes.forEach(node => {
                if (node.nodeType === 1 && node.matches('tr.criterion')) {
                    injectViewCell(node, categories, mappings);
                }
            });
        });
    });
    observer.observe(table, {childList: true, subtree: true});
};

/**
 * Attach Sentinel view column to a rubric table.
 * Called both on initial load and when the table is injected dynamically.
 *
 * @param {HTMLElement} table
 * @param {Array}       categories
 * @param {Object}      mappings
 */
const attachViewToTable = (table, categories, mappings) => {
    addViewHeader(table);
    table.querySelectorAll('tr.criterion').forEach(row => {
        injectViewCell(row, categories, mappings);
    });
    observeNewRowsView(table, categories, mappings);
};

/**
 * Entry point for the rubric PREVIEW and GRADING pages (read-only column).
 *
 * Handles two cases:
 *  1. Table already in DOM on page load (preview page).
 *  2. Table injected dynamically after load (assignment grading panel).
 *
 * @param {Array}  categories [{id, name}, …]
 * @param {Object} mappings   {criterionId: categoryId}
 */
const initView = (categories, mappings, strings) => {
    if (strings) { Object.assign(_S, strings); }
    const run = () => {
        const table = document.querySelector('table.criteria');
        if (table) {
            // Table already present (e.g. preview page).
            attachViewToTable(table, categories, mappings);
            return;
        }

        // Table not yet in DOM — watch for it (dynamic grading panel).
        // Do NOT disconnect after first match: switching students replaces the
        // table entirely, so we must keep watching for each new one.
        let lastTable = null;
        const bodyObserver = new MutationObserver(() => {
            const t = document.querySelector('table.criteria');
            if (!t || t === lastTable) {
                return;
            }
            lastTable = t;
            attachViewToTable(t, categories, mappings);
        });
        bodyObserver.observe(document.body, {childList: true, subtree: true});
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
};

return {init: init, initView: initView};
});
