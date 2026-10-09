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
 * AMD module for the Sentinel competency profile report.
 *
 * Handles three-level drill-down navigation (L1→L2→L3) and updates
 * the native Moodle breadcrumb to reflect the current drill-down level.
 *
 * @module     local_sentinel/descriptor_report
 * @copyright  2024 Marcos
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    'use strict';

    var _reportTitle    = 'Perfil de salida';
    var _bcList         = null;   // <ol class="breadcrumb">
    var _bcLastItem     = null;   // the last <li> on page load ("Perfil de salida")
    var _bcLastClass    = '';
    var _bcLastHTML     = '';
    var _bcExtra        = [];     // <li> elements we injected

    // ── Breadcrumb helpers ────────────────────────────────────────────────────

    function initBreadcrumb() {
        _bcList = document.querySelector('ol.breadcrumb');
        if (!_bcList) { return; }
        var items = _bcList.querySelectorAll('li.breadcrumb-item');
        if (!items.length) { return; }
        _bcLastItem  = items[items.length - 1];
        _bcLastClass = _bcLastItem.className;
        _bcLastHTML  = _bcLastItem.innerHTML;
    }

    /** Remove injected <li>s and restore the original last item. */
    function bcRestore() {
        _bcExtra.forEach(function(li) { li.parentNode && li.parentNode.removeChild(li); });
        _bcExtra = [];
        if (_bcLastItem) {
            _bcLastItem.className = _bcLastClass;
            _bcLastItem.removeAttribute('aria-current');
            _bcLastItem.innerHTML = _bcLastHTML;
            // re-add aria-current if the original had it
            if (_bcLastClass.indexOf('active') !== -1) {
                _bcLastItem.setAttribute('aria-current', 'page');
            }
        }
    }

    /**
     * Update the breadcrumb to show a trail beyond "Perfil de salida".
     * trail: [{label, onclick?}] — items AFTER the fixed "Perfil de salida" entry.
     *   - Items with onclick become links.
     *   - The last item is always rendered as plain text (active).
     */
    function bcSetTrail(trail) {
        if (!_bcList || !_bcLastItem) { return; }
        bcRestore();

        // Convert the "Perfil de salida" item into a clickable link.
        _bcLastItem.className = 'breadcrumb-item';
        _bcLastItem.removeAttribute('aria-current');
        var a0 = document.createElement('a');
        a0.href = '#';
        a0.textContent = _reportTitle;
        a0.addEventListener('click', function(e) {
            e.preventDefault();
            window.sentinelBack('l1');
        });
        _bcLastItem.innerHTML = '';
        _bcLastItem.appendChild(a0);

        // Append new items.
        trail.forEach(function(item, i) {
            var li = document.createElement('li');
            var isLast = (i === trail.length - 1);
            if (isLast) {
                li.className = 'breadcrumb-item active';
                li.setAttribute('aria-current', 'page');
                li.textContent = item.label;
            } else {
                li.className = 'breadcrumb-item';
                var a = document.createElement('a');
                a.href = '#';
                a.textContent = item.label;
                (function(it) {
                    a.addEventListener('click', function(e) {
                        e.preventDefault();
                        it.onclick && it.onclick();
                    });
                })(item);
                li.appendChild(a);
            }
            _bcList.appendChild(li);
            _bcExtra.push(li);
        });
    }

    // ── Area label helper ─────────────────────────────────────────────────────

    function areaLabel(areaCode) {
        var panel = document.getElementById('sentinel-l2-' + areaCode);
        if (!panel) { return areaCode; }
        var name = panel.dataset.areaName || '';
        return name ? (areaCode + ' – ' + name) : areaCode;
    }

    // ── Panel helpers ─────────────────────────────────────────────────────────

    function hideAll() {
        document.querySelectorAll('.sentinel-panel').forEach(function(p) {
            p.classList.remove('active');
        });
    }

    // ── Public navigation (window-scoped for PHP onclick handlers) ────────────

    window.sentinelShowArea = function(areaCode) {
        hideAll();
        var panel = document.getElementById('sentinel-l2-' + areaCode);
        if (panel) {
            panel.classList.add('active');
            panel.scrollIntoView({behavior: 'smooth', block: 'start'});
            bcSetTrail([{label: areaLabel(areaCode)}]);
        }
    };

    window.sentinelShowDescriptor = function(descCode) {
        hideAll();
        var panel = document.getElementById('sentinel-l3-' + descCode);
        if (panel) {
            panel.classList.add('active');
            panel.scrollIntoView({behavior: 'smooth', block: 'start'});
            var ac = panel.dataset.areaCode || '';
            bcSetTrail([
                {label: areaLabel(ac), onclick: function() { window.sentinelBack('l2-' + ac); }},
                {label: panel.dataset.descCode || descCode}
            ]);
        }
    };

    window.sentinelBack = function(panelId) {
        hideAll();
        var panel = document.getElementById('sentinel-' + panelId);
        if (panel) {
            panel.classList.add('active');
            panel.scrollIntoView({behavior: 'smooth', block: 'start'});
        }
        if (panelId === 'l1') {
            bcRestore();
        } else if (panelId.indexOf('l2-') === 0) {
            var ac = panelId.slice(3);
            bcSetTrail([{label: areaLabel(ac)}]);
        }
    };

    // ── Init ──────────────────────────────────────────────────────────────────

    return {
        init: function() {
            var root = document.querySelector('.sentinel-report');
            if (root && root.dataset.title) {
                _reportTitle = root.dataset.title;
            }
            initBreadcrumb();

            // Keyboard: Enter/Space activa tarjetas.
            var sel = '.sentinel-area-card, .sentinel-desc-card:not(.sentinel-desc-unworked), [data-descriptor]';
            document.querySelectorAll(sel).forEach(function(el) {
                el.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        el.click();
                    }
                });
            });
        }
    };
});
