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
        document.querySelectorAll('.sn-view-pane').forEach(function(p) {
            p.classList.remove('active');
        });
    }

    // ── Public navigation (window-scoped for PHP onclick handlers) ────────────

    var _lastViewTab = 'diana';
    try { _lastViewTab = localStorage.getItem('sentinel_view_tab') || 'diana'; } catch(e) {}

    window.sentinelViewTab = function(tab) {
        // Verify the target pane exists; fall back to 'diana' if not.
        var pane = document.getElementById('sentinel-pane-' + tab);
        if (!pane) { tab = 'diana'; }
        _lastViewTab = tab;
        try { localStorage.setItem('sentinel_view_tab', tab); } catch(e) {}
        var btns = document.querySelectorAll('.sn-view-tab');
        btns.forEach(function(t) {
            var btnTab = t.getAttribute('data-tab');
            t.classList.toggle('active', btnTab === tab);
        });
        document.querySelectorAll('.sn-view-pane').forEach(function(p) { p.classList.remove('active'); });
        document.querySelectorAll('.sentinel-panel').forEach(function(p) { p.classList.remove('active'); });
        var pane = document.getElementById('sentinel-pane-' + tab);
        if (pane) { pane.classList.add('active'); }
        // Highlight the active group + background tint.
        var isSpecific = (tab === 'subject-diana' || tab === 'subject-grid');
        document.querySelectorAll('.sn-tab-group').forEach(function(g) {
            g.classList.remove('sn-group-active-key', 'sn-group-active-specific');
            if (g.dataset.group === 'key' && !isSpecific) {
                g.classList.add('sn-group-active-key');
            } else if (g.dataset.group === 'specific' && isSpecific) {
                g.classList.add('sn-group-active-specific');
            }
        });
        var report = document.querySelector('.sentinel-report');
        if (report) {
            report.classList.remove('sn-bg-key', 'sn-bg-specific');
            report.classList.add(isSpecific ? 'sn-bg-specific' : 'sn-bg-key');
        }
        // Restore L1 panels for the grid tabs.
        if (tab === 'grid') {
            var l1 = document.getElementById('sentinel-l1');
            if (l1) { l1.classList.add('active'); }
        }
        if (tab === 'subject-grid') {
            var ceL1 = document.getElementById('sentinel-ce-l1');
            if (ceL1) { ceL1.classList.add('active'); }
        }
        if (tab === 'diana' && window.sentinelRedrawRadar) {
            setTimeout(window.sentinelRedrawRadar, 50);
        }
        if (tab === 'subject-diana' && window.sentinelDrawSubjectRadar) {
            setTimeout(window.sentinelDrawSubjectRadar, 50);
        }
        bcRestore();
    };

    function highlightGroup(group) {
        document.querySelectorAll('.sn-tab-group').forEach(function(g) {
            g.classList.remove('sn-group-active-key', 'sn-group-active-specific');
            if (g.dataset.group === 'key' && group === 'key') {
                g.classList.add('sn-group-active-key');
            } else if (g.dataset.group === 'specific' && group === 'specific') {
                g.classList.add('sn-group-active-specific');
            }
        });
    }

    window.sentinelShowArea = function(areaCode) {
        hideAll();
        highlightGroup('key');
        var panel = document.getElementById('sentinel-l2-' + areaCode);
        if (panel) {
            var back = panel.querySelector('.sn-back-link');
            if (back) {
                back.textContent = _lastViewTab === 'diana'
                    ? '← Volver a Competencias Clave (Diana)' : '← Volver a Competencias Clave (Tarjetas)';
            }
            panel.classList.add('active');
            panel.scrollIntoView({behavior: 'smooth', block: 'start'});
            bcSetTrail([{label: areaLabel(areaCode)}]);
        }
    };

    window.sentinelShowDescriptor = function(descCode) {
        hideAll();
        highlightGroup('key');
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
        if (panelId === 'l1') {
            sentinelViewTab(_lastViewTab);
            bcRestore();
        } else {
            var panel = document.getElementById('sentinel-' + panelId);
            if (panel) {
                panel.classList.add('active');
                panel.scrollIntoView({behavior: 'smooth', block: 'start'});
            }
            if (panelId.indexOf('l2-') === 0) {
                var ac = panelId.slice(3);
                bcSetTrail([{label: areaLabel(ac)}]);
            }
        }
    };

    // ── CE (subject) navigation ─────────────────────────────────────────────

    function ceLabel(ceIdx) {
        var panel = document.getElementById('sentinel-ce-l2-' + ceIdx);
        if (!panel) { return 'CE' + (ceIdx + 1); }
        var lbl = panel.dataset.ceLabel || '';
        var name = panel.dataset.ceName || '';
        if (!name) { return lbl; }
        var full = lbl + ' – ' + name;
        return full.length > 60 ? full.substring(0, 57) + '…' : full;
    }

    window.sentinelShowCE = function(ceIdx) {
        hideAll();
        highlightGroup('specific');
        var panel = document.getElementById('sentinel-ce-l2-' + ceIdx);
        if (panel) {
            var back = panel.querySelector('.sn-back-link');
            if (back) {
                back.textContent = _lastViewTab === 'subject-diana'
                    ? '← Volver a Competencias Específicas (Diana)' : '← Volver a Competencias Específicas (Tarjetas)';
            }
            panel.classList.add('active');
            panel.scrollIntoView({behavior: 'smooth', block: 'start'});
            bcSetTrail([{label: ceLabel(ceIdx)}]);
        }
    };

    window.sentinelCEBack = function() {
        hideAll();
        var backTab = (_lastViewTab === 'subject-diana' || _lastViewTab === 'subject-grid')
            ? _lastViewTab : 'subject-grid';
        sentinelViewTab(backTab);
        bcRestore();
    };

    window.sentinelShowCECrit = function(ceIdx, critIdx) {
        hideAll();
        highlightGroup('specific');
        var panel = document.getElementById('sentinel-ce-l3-' + ceIdx + '-' + critIdx);
        if (panel) {
            panel.classList.add('active');
            panel.scrollIntoView({behavior: 'smooth', block: 'start'});
            var critName = panel.dataset.critName || panel.getAttribute('data-crit-name') || '';
            if (critName.length > 50) { critName = critName.substring(0, 47) + '…'; }
            bcSetTrail([
                {label: ceLabel(ceIdx), onclick: function() { window.sentinelShowCE(ceIdx); }},
                {label: critName || ('Criterio ' + (critIdx + 1))}
            ]);
        }
    };

    window.sentinelCECritBack = function(ceIdx) {
        hideAll();
        var panel = document.getElementById('sentinel-ce-l2-' + ceIdx);
        if (panel) {
            panel.classList.add('active');
            panel.scrollIntoView({behavior: 'smooth', block: 'start'});
            bcSetTrail([{label: ceLabel(ceIdx)}]);
        }
    };

    window.sentinelChangeCourse = function(courseid) {
        var url = new URL(window.location.href);
        url.searchParams.set('courseid', courseid);
        window.location.href = url.toString();
    };

    // ── Init ──────────────────────────────────────────────────────────────────

    return {
        init: function() {
            var root = document.querySelector('.sentinel-report');
            if (root && root.dataset.title) {
                _reportTitle = root.dataset.title;
            }
            initBreadcrumb();

            sentinelViewTab(_lastViewTab);

            // Keyboard: Enter/Space activa tarjetas.
            var sel = '.sentinel-area-card, .sentinel-desc-card:not(.sentinel-desc-unworked), .sn-ce-card, [data-descriptor]';
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
