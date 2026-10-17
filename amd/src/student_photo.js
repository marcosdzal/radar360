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
 * Student photo widget for local_sentinel.
 *
 * @module     local_sentinel/student_photo
 * @copyright  2024 Marcos
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    'use strict';

    function appendUserId(base, uid) {
        return base + (base.indexOf('?') === -1 ? '?' : '&') + 'userid=' + uid;
    }

    // ── Toast notification ────────────────────────────────────────────────────

    function showToast(msg, isError) {
        var t = document.createElement('div');
        t.textContent = msg;
        t.style.cssText = [
            'position:fixed', 'bottom:24px', 'right:24px', 'z-index:99999',
            'padding:10px 18px', 'border-radius:6px',
            'font-size:13px', 'font-weight:500', 'color:#fff',
            'box-shadow:0 4px 16px rgba(0,0,0,.25)',
            'background:' + (isError ? '#dc2626' : '#16a34a'),
            'max-width:320px', 'line-height:1.4'
        ].join(';');
        document.body.appendChild(t);
        setTimeout(function() { t.parentNode && t.parentNode.removeChild(t); }, 5000);
    }

    // ── Lightbox ──────────────────────────────────────────────────────────────

    function openLightbox(src) {
        var overlay = document.createElement('div');
        overlay.style.cssText = [
            'position:fixed', 'inset:0', 'background:rgba(0,0,0,0.85)',
            'z-index:9999', 'display:flex', 'align-items:center',
            'justify-content:center', 'cursor:zoom-out'
        ].join(';');

        var img = document.createElement('img');
        img.src = src;
        img.style.cssText = 'max-width:90vw;max-height:90vh;border-radius:4px;box-shadow:0 0 40px rgba(0,0,0,0.8);';

        overlay.appendChild(img);
        document.body.appendChild(overlay);

        function close() {
            if (overlay.parentNode) {
                overlay.parentNode.removeChild(overlay);
            }
            document.removeEventListener('keydown', onKey);
        }

        function onKey(e) {
            if (e.key === 'Escape') {
                close();
            }
        }

        overlay.addEventListener('click', close);
        document.addEventListener('keydown', onKey);
    }

    // ── Module-level UI strings (overridden by PHP-passed strings at init time) ─
    var _S = {
        delphoto:     'Eliminar foto',
        addphoto:     'Añadir foto',
        gradetooltip: 'Calificación final del curso',
        photostoggle: 'Mostrar / ocultar fotos',
    };

    // ── Widget builder ────────────────────────────────────────────────────────

    /**
     * Build a photo widget element for a given student.
     *
     * @param {number}      studentid
     * @param {string|null} photoUrl   Current photo URL, or null if none.
     * @param {string}      uploadUrl  Base URL for photo.php
     * @param {string}      sesskey
     * @returns {HTMLElement}
     */
    function buildWidget(studentid, photoUrl, uploadUrl, sesskey) {
        var wrap = document.createElement('div');
        wrap.style.cssText = 'position:relative;display:inline-block;vertical-align:middle;margin:0 8px;overflow:visible;';

        // Circle container
        var circle = document.createElement('div');
        circle.style.cssText = [
            'width:60px', 'height:60px', 'border-radius:50%',
            'border:2px solid #1a5276', 'overflow:hidden',
            'display:flex', 'align-items:center', 'justify-content:center',
            'background:#d6eaf8', 'cursor:pointer', 'position:relative',
            'flex-shrink:0'
        ].join(';');

        var currentUrl = photoUrl;

        // Photo img (visible when photo exists)
        var photoImg = document.createElement('img');
        photoImg.style.cssText = 'width:100%;height:100%;object-fit:cover;display:' + (currentUrl ? 'block' : 'none') + ';';
        if (currentUrl) {
            photoImg.src = currentUrl;
        }

        // Placeholder (visible when no photo — only relevant for editors)
        var placeholder = document.createElement('div');
        placeholder.style.cssText = 'display:' + (currentUrl ? 'none' : 'flex') + ';flex-direction:column;align-items:center;justify-content:center;font-size:10px;color:#555;text-align:center;line-height:1.2;padding:2px;';
        if (uploadUrl) {
            placeholder.innerHTML = '<span style="font-size:20px;">&#128247;</span><span>' + _S.addphoto + '</span>';
        }

        // Upload/edit controls — only when the caller has permission (uploadUrl is non-null)
        var camOverlay = null, delBtn = null, fileInput = null;
        if (uploadUrl) {
            // Camera overlay (shows on hover)
            camOverlay = document.createElement('div');
            camOverlay.style.cssText = [
                'position:absolute', 'inset:0', 'border-radius:50%',
                'background:rgba(0,0,0,0.5)', 'display:flex',
                'align-items:center', 'justify-content:center',
                'opacity:0', 'transition:opacity 0.2s', 'cursor:pointer'
            ].join(';');
            camOverlay.innerHTML = '<span style="font-size:22px;color:#fff;">&#128247;</span>';

            // Delete button — hidden by default, shown on hover
            delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.title = _S.delphoto;
            delBtn.innerHTML = '&times;';
            delBtn.dataset.hasPhoto = currentUrl ? '1' : '';
            delBtn.style.cssText = [
                'position:absolute', 'top:-4px', 'right:-4px',
                'width:18px', 'height:18px', 'border-radius:50%',
                'background:#e74c3c', 'color:#fff', 'border:none',
                'font-size:14px', 'line-height:18px', 'text-align:center',
                'cursor:pointer', 'display:none',
                'z-index:2', 'padding:0',
                'opacity:0', 'transition:opacity 0.2s',
                'box-shadow:0 1px 3px rgba(0,0,0,.3)'
            ].join(';');

            // Hidden file input
            fileInput = document.createElement('input');
            fileInput.type = 'file';
            fileInput.accept = 'image/*';
            fileInput.style.display = 'none';

            // Click on camera overlay → open file picker
            camOverlay.addEventListener('click', function(e) {
                e.stopPropagation();
                fileInput.click();
            });

            // File selected → upload
            fileInput.addEventListener('change', function() {
                if (!fileInput.files || !fileInput.files[0]) {
                    return;
                }
                var formData = new FormData();
                formData.append('action', 'upload');
                formData.append('studentid', studentid);
                formData.append('sesskey', sesskey);
                formData.append('photo', fileInput.files[0]);

                fetch(uploadUrl, {method: 'POST', body: formData})
                    .then(function(r) {
                        if (!r.ok) { return r.text().then(function(t) { throw new Error('HTTP ' + r.status + ': ' + t.slice(0, 300)); }); }
                        return r.json();
                    })
                    .then(function(data) {
                        if (data.success) {
                            currentUrl = data.url;
                            photoImg.src = currentUrl;
                            photoImg.style.display = 'block';
                            placeholder.style.display = 'none';
                            delBtn.dataset.hasPhoto = '1';
                            showToast('Foto actualizada', false);
                        } else {
                            showToast('Error: ' + (data.error || 'desconocido'), true);
                        }
                    })
                    .catch(function(err) { showToast('Error al subir: ' + err.message, true); });

                fileInput.value = '';
            });

            // Delete button
            delBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                var formData = new FormData();
                formData.append('action', 'delete');
                formData.append('studentid', studentid);
                formData.append('sesskey', sesskey);

                fetch(uploadUrl, {method: 'POST', body: formData})
                    .then(function(r) {
                        if (!r.ok) { return r.text().then(function(t) { throw new Error('HTTP ' + r.status + ': ' + t.slice(0, 300)); }); }
                        return r.json();
                    })
                    .then(function(data) {
                        if (data.success) {
                            currentUrl = null;
                            photoImg.style.display = 'none';
                            photoImg.src = '';
                            placeholder.style.display = 'flex';
                            delBtn.dataset.hasPhoto = '';
                            delBtn.style.display = 'none';
                            showToast('Foto eliminada', false);
                        } else {
                            showToast('Error: ' + (data.error || 'desconocido'), true);
                        }
                    })
                    .catch(function(err) { showToast('Error al eliminar: ' + err.message, true); });
            });
        }

        // ── Event handlers (view-only, always active) ─────────────────────────

        // Delete button: show/hide on wrap hover (wrap includes circle + delBtn).
        wrap.addEventListener('mouseenter', function() {
            if (delBtn && delBtn.dataset.hasPhoto) {
                delBtn.style.display = 'block';
                delBtn.style.opacity = '1';
            }
        });
        wrap.addEventListener('mouseleave', function() {
            if (delBtn) {
                delBtn.style.opacity = '0';
                setTimeout(function() { if (delBtn.style.opacity === '0') { delBtn.style.display = 'none'; } }, 200);
            }
        });

        var hoverPopup = null;
        circle.addEventListener('mouseenter', function() {
            if (camOverlay) { camOverlay.style.opacity = '1'; }
            if (!currentUrl) { return; }
            hoverPopup = document.createElement('div');
            hoverPopup.style.cssText = [
                'position:fixed', 'z-index:9000',
                'pointer-events:none',
                'border-radius:6px', 'overflow:hidden',
                'box-shadow:0 4px 20px rgba(0,0,0,0.5)',
                'border:2px solid #1a5276',
                'width:200px', 'height:200px'
            ].join(';');
            var bigImg = document.createElement('img');
            bigImg.src = currentUrl;
            bigImg.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;';
            hoverPopup.appendChild(bigImg);
            document.body.appendChild(hoverPopup);
            var rect = circle.getBoundingClientRect();
            var top  = rect.top;
            var left = rect.right + 8;
            if (rect.right + 216 > window.innerWidth) { left = rect.left - 208; }
            hoverPopup.style.top  = top  + 'px';
            hoverPopup.style.left = left + 'px';
        });
        circle.addEventListener('mouseleave', function() {
            if (camOverlay) { camOverlay.style.opacity = '0'; }
            if (hoverPopup && hoverPopup.parentNode) {
                hoverPopup.parentNode.removeChild(hoverPopup);
                hoverPopup = null;
            }
        });

        // Click on photo → lightbox (always available)
        photoImg.addEventListener('click', function(e) {
            e.stopPropagation();
            if (currentUrl) {
                openLightbox(currentUrl);
            }
        });

        // Assemble
        circle.appendChild(photoImg);
        circle.appendChild(placeholder);
        if (camOverlay) { circle.appendChild(camOverlay); }

        wrap.appendChild(circle);
        if (delBtn)    { wrap.appendChild(delBtn); }
        if (fileInput) { wrap.appendChild(fileInput); }

        return wrap;
    }

    // ── Smaller thumbnail variant (participants page) ─────────────────────────

    function buildThumbnail(studentid, photoUrl, uploadUrl, sesskey, size) {
        size = size || 40;
        var wrap = document.createElement('div');
        wrap.style.cssText = 'position:relative;display:inline-block;vertical-align:middle;';

        var circle = document.createElement('div');
        circle.style.cssText = [
            'width:' + size + 'px', 'height:' + size + 'px', 'border-radius:50%',
            'border:2px solid #1a5276', 'overflow:hidden',
            'display:flex', 'align-items:center', 'justify-content:center',
            'background:#d6eaf8', 'cursor:pointer', 'position:relative',
            'flex-shrink:0'
        ].join(';');

        var currentUrl = photoUrl;

        var photoImg = document.createElement('img');
        photoImg.style.cssText = 'width:100%;height:100%;object-fit:cover;display:' + (currentUrl ? 'block' : 'none') + ';';
        if (currentUrl) {
            photoImg.src = currentUrl;
        }

        var placeholder = document.createElement('div');
        placeholder.style.cssText = 'display:' + (currentUrl ? 'none' : 'flex') + ';align-items:center;justify-content:center;font-size:16px;color:#555;';
        if (uploadUrl) {
            placeholder.innerHTML = '&#128247;';
        }

        // Upload/edit controls — only when caller has permission (uploadUrl is non-null)
        var camOverlay = null, delBtn = null, fileInput = null;
        if (uploadUrl) {
            camOverlay = document.createElement('div');
            camOverlay.style.cssText = [
                'position:absolute', 'inset:0', 'border-radius:50%',
                'background:rgba(0,0,0,0.5)', 'display:flex',
                'align-items:center', 'justify-content:center',
                'opacity:0', 'transition:opacity 0.2s', 'cursor:pointer'
            ].join(';');
            camOverlay.innerHTML = '<span style="font-size:16px;color:#fff;">&#128247;</span>';

            delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.title = _S.delphoto;
            delBtn.innerHTML = '&times;';
            delBtn.dataset.hasPhoto = currentUrl ? '1' : '';
            delBtn.style.cssText = [
                'position:absolute', 'top:-3px', 'right:-3px',
                'width:14px', 'height:14px', 'border-radius:50%',
                'background:#e74c3c', 'color:#fff', 'border:none',
                'font-size:11px', 'line-height:14px', 'text-align:center',
                'cursor:pointer', 'display:none',
                'z-index:2', 'padding:0',
                'opacity:0', 'transition:opacity 0.2s',
                'box-shadow:0 1px 3px rgba(0,0,0,.3)'
            ].join(';');

            fileInput = document.createElement('input');
            fileInput.type = 'file';
            fileInput.accept = 'image/*';
            fileInput.style.display = 'none';

            camOverlay.addEventListener('click', function(e) {
                e.stopPropagation();
                fileInput.click();
            });

            fileInput.addEventListener('change', function() {
                if (!fileInput.files || !fileInput.files[0]) {
                    return;
                }
                var formData = new FormData();
                formData.append('action', 'upload');
                formData.append('studentid', studentid);
                formData.append('sesskey', sesskey);
                formData.append('photo', fileInput.files[0]);

                fetch(uploadUrl, {method: 'POST', body: formData})
                    .then(function(r) {
                        if (!r.ok) { return r.text().then(function(t) { throw new Error('HTTP ' + r.status + ': ' + t.slice(0, 300)); }); }
                        return r.json();
                    })
                    .then(function(data) {
                        if (data.success) {
                            currentUrl = data.url;
                            photoImg.src = currentUrl;
                            photoImg.style.display = 'block';
                            placeholder.style.display = 'none';
                            delBtn.dataset.hasPhoto = '1';
                            showToast('Foto actualizada', false);
                        } else {
                            showToast('Error: ' + (data.error || 'desconocido'), true);
                        }
                    })
                    .catch(function(err) { showToast('Error al subir: ' + err.message, true); });

                fileInput.value = '';
            });

            delBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                var formData = new FormData();
                formData.append('action', 'delete');
                formData.append('studentid', studentid);
                formData.append('sesskey', sesskey);

                fetch(uploadUrl, {method: 'POST', body: formData})
                    .then(function(r) {
                        if (!r.ok) { return r.text().then(function(t) { throw new Error('HTTP ' + r.status + ': ' + t.slice(0, 300)); }); }
                        return r.json();
                    })
                    .then(function(data) {
                        if (data.success) {
                            currentUrl = null;
                            photoImg.style.display = 'none';
                            photoImg.src = '';
                            placeholder.style.display = 'flex';
                            delBtn.dataset.hasPhoto = '';
                            delBtn.style.display = 'none';
                            showToast('Foto eliminada', false);
                        } else {
                            showToast('Error: ' + (data.error || 'desconocido'), true);
                        }
                    })
                    .catch(function(err) { showToast('Error al eliminar: ' + err.message, true); });
            });
        }

        // Delete button: show/hide on wrap hover.
        wrap.addEventListener('mouseenter', function() {
            if (delBtn && delBtn.dataset.hasPhoto) {
                delBtn.style.display = 'block';
                delBtn.style.opacity = '1';
            }
        });
        wrap.addEventListener('mouseleave', function() {
            if (delBtn) {
                delBtn.style.opacity = '0';
                setTimeout(function() { if (delBtn.style.opacity === '0') { delBtn.style.display = 'none'; } }, 200);
            }
        });

        // Hover preview popup (always available for view)
        var hoverPopup = null;
        circle.addEventListener('mouseenter', function() {
            if (camOverlay) { camOverlay.style.opacity = '1'; }
            if (!currentUrl) { return; }
            hoverPopup = document.createElement('div');
            hoverPopup.style.cssText = [
                'position:fixed', 'z-index:9000',
                'pointer-events:none',
                'border-radius:6px', 'overflow:hidden',
                'box-shadow:0 4px 20px rgba(0,0,0,0.5)',
                'border:2px solid #1a5276',
                'width:160px', 'height:160px'
            ].join(';');
            var bigImg = document.createElement('img');
            bigImg.src = currentUrl;
            bigImg.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;';
            hoverPopup.appendChild(bigImg);
            document.body.appendChild(hoverPopup);

            var rect = circle.getBoundingClientRect();
            var top  = rect.top;
            var left = rect.right + 8;
            if (rect.right + 176 > window.innerWidth) {
                left = rect.left - 168;
            }
            hoverPopup.style.top  = top + 'px';
            hoverPopup.style.left = left + 'px';
        });
        circle.addEventListener('mouseleave', function() {
            if (camOverlay) { camOverlay.style.opacity = '0'; }
            if (hoverPopup && hoverPopup.parentNode) {
                hoverPopup.parentNode.removeChild(hoverPopup);
                hoverPopup = null;
            }
        });

        // Lightbox on photo click (always)
        photoImg.addEventListener('click', function(e) {
            e.stopPropagation();
            if (currentUrl) {
                openLightbox(currentUrl);
            }
        });

        circle.appendChild(photoImg);
        circle.appendChild(placeholder);
        if (camOverlay) { circle.appendChild(camOverlay); }

        wrap.appendChild(circle);
        if (delBtn)    { wrap.appendChild(delBtn); }
        if (fileInput) { wrap.appendChild(fileInput); }

        return wrap;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Init on assignment grading page.
     *
     * @param {number}      studentid
     * @param {string|null} photoUrl
     * @param {string}      uploadUrl
     * @param {string}      sesskey
     */
    function initGrading(photoMap, uploadUrl, sesskey, radarMap, reportBaseUrl) {
        // Moodle renderiza [data-region="user-info"] con una plantilla mustache:
        //   <h4><a href="..."><img class="img-rounded" height="40"> Nombre <em>email</em></a></h4>
        // El panel se recarga en cada cambio de alumno vía el evento jQuery "user-changed".
        // Usamos buildThumbnail (36 px) para no ocultar el correo.

        var injectForUser = function(userid) {
            // Eliminar foto previa si existe.
            document.querySelectorAll('.sentinel-grading-photo').forEach(function(el) {
                el.parentNode && el.parentNode.removeChild(el);
            });
            if (!userid) { return false; }

            var anchor = document.querySelector('[data-region="user-info"] h4 a') ||
                         document.querySelector('[data-region="user-info"] h4');
            if (!anchor) { return false; }

            var photoUrl = Object.prototype.hasOwnProperty.call(photoMap, userid)
                ? photoMap[userid] : null;

            var thumb = buildThumbnail(userid, photoUrl, uploadUrl, sesskey, 36);
            thumb.className = 'sentinel-grading-photo';
            thumb.style.marginRight = '4px';
            thumb.style.flexShrink  = '0';
            thumb.style.verticalAlign = 'middle';

            // Insertar antes del avatar de Moodle (img.img-rounded).
            var moodleAvatar = anchor.querySelector('img.img-rounded');
            if (moodleAvatar) {
                anchor.insertBefore(thumb, moodleAvatar);
            } else {
                anchor.insertBefore(thumb, anchor.firstChild);
            }

            // Mini diana.
            if (radarMap && radarMap[userid]) {
                var mc = document.createElement('canvas');
                mc.width = 144;
                mc.height = 144;
                mc.className = 'sentinel-grading-photo';
                mc.style.cssText = 'width:36px;height:36px;vertical-align:middle;margin-right:4px;flex-shrink:0;';
                anchor.insertBefore(mc, thumb.nextSibling);
                drawMiniRadar(mc, radarMap[userid]);
                if (reportBaseUrl) {
                    makeDianaClickable(mc, appendUserId(reportBaseUrl, userid), radarMap[userid]);
                }
            }
            return true;
        };

        var run = function() {
            // Escuchar el evento jQuery que Moodle dispara en cada cambio de alumno.
            require(['jquery'], function($) {
                $(document).on('user-changed', function(e, userid) {
                    // Dar tiempo a que AMD renderice el h4 con el nuevo alumno.
                    var uid   = parseInt(userid, 10);
                    var tries = 0;
                    var attempt = function() {
                        if (injectForUser(uid)) { return; }
                        if (++tries < 15) { setTimeout(attempt, 300); }
                    };
                    setTimeout(attempt, 300);
                });
            });

            // Inyección inicial para el alumno que viene en la URL.
            var m = window.location.href.match(/[?&]userid=(\d+)/);
            if (m) {
                var uid = parseInt(m[1], 10);
                var tries = 0;
                var attempt = function() {
                    if (injectForUser(uid)) { return; }
                    if (++tries < 15) { setTimeout(attempt, 300); }
                };
                attempt();
            }
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', run);
        } else {
            run();
        }
    }

    /**
     * Init on participants page (/user/index.php).
     *
     * @param {Object.<number, string|null>} studentPhotoMap  {userid: url_or_null}
     * @param {string}                       uploadUrl
     * @param {string}                       sesskey
     */
    function initParticipants(studentPhotoMap, uploadUrl, sesskey, radarMap, reportBaseUrl) {
        // Match both /user/view.php (course context) and /user/profile.php (site/admin context).
        var USER_LINK_SEL = 'a[href*="/user/view.php"], a[href*="/user/profile.php"]';

        var processLink = function(link) {
            // Skip non-user links (e.g. profile.php without id param).
            var match = link.href.match(/[?&]id=(\d+)/);
            if (!match) { return; }
            var uid = parseInt(match[1], 10);
            if (!uid) { return; }

            // Avoid inserting twice.
            if (link.dataset.sentinelPhoto) { return; }
            link.dataset.sentinelPhoto = '1';

            // Look up photo URL — null means no stored photo.
            var photoUrl = Object.prototype.hasOwnProperty.call(studentPhotoMap, uid)
                ? studentPhotoMap[uid]
                : (Object.prototype.hasOwnProperty.call(studentPhotoMap, String(uid))
                    ? studentPhotoMap[String(uid)]
                    : null);

            // If there's nothing to show and the viewer can't upload, skip silently.
            if (photoUrl === null && !uploadUrl) { return; }

            var thumb = buildThumbnail(uid, photoUrl, uploadUrl, sesskey);

            // Insert before the Moodle avatar (previous sibling) or before the link itself.
            var prev = link.previousElementSibling;
            if (prev && prev.tagName !== 'A') {
                link.parentNode.insertBefore(thumb, prev);
            } else {
                link.parentNode.insertBefore(thumb, link);
            }

            // Mini diana.
            if (radarMap && radarMap[uid]) {
                var mc = document.createElement('canvas');
                mc.width = 160;
                mc.height = 160;
                mc.style.cssText = 'width:40px;height:40px;vertical-align:middle;margin-right:4px;display:inline-block;';
                link.parentNode.insertBefore(mc, link);
                drawMiniRadar(mc, radarMap[uid]);
                if (reportBaseUrl) {
                    makeDianaClickable(mc, appendUserId(reportBaseUrl, uid), radarMap[uid]);
                }
            }
        };

        var run = function() {
            // Process any links already in the DOM.
            document.querySelectorAll(USER_LINK_SEL).forEach(processLink);

            // Watch for rows added dynamically (Moodle 4.x participants table loads via AJAX).
            var observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    mutation.addedNodes.forEach(function(node) {
                        if (node.nodeType !== 1) { return; }
                        // Check the node itself and its descendants.
                        if (node.matches && node.matches(USER_LINK_SEL)) {
                            processLink(node);
                        }
                        node.querySelectorAll(USER_LINK_SEL).forEach(processLink);
                    });
                });
            });

            observer.observe(document.body, {childList: true, subtree: true});
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', run);
        } else {
            run();
        }
    }

    /**
     * Init on the grader report page.
     * Injects photo thumbnails INSIDE the existing name cell — no new columns,
     * no colspan/rowspan manipulation, table structure is untouched.
     *
     * @param {Object.<number, string|null>} studentPhotoMap  {userid: url_or_null}
     * @param {string}                       uploadUrl
     * @param {string}                       sesskey
     */
    function drawMiniRadar(canvas, m) {
        var w = canvas.width, h = canvas.height;
        var cx = w / 2, cy = h / 2;
        var S = w / 192;
        var maxR = Math.min(cx, cy) - 8 * S;
        var n = m.v.length;
        var ctx = canvas.getContext('2d');
        ctx.clearRect(0, 0, w, h);

        var angle = function(i) { return (Math.PI * 2 * i / n) - Math.PI / 2; };
        var pointXY = function(i, val) {
            var r = (val / 10) * maxR;
            return { x: cx + Math.cos(angle(i)) * r, y: cy + Math.sin(angle(i)) * r };
        };

        // Grid polygons (octagon-style).
        for (var g = 2; g <= 10; g += 2) {
            ctx.strokeStyle = (g === 10) ? '#b0b5be' : '#e5e7eb';
            ctx.lineWidth = ((g === 10) ? 1 : 0.5) * S;
            ctx.beginPath();
            for (var gi = 0; gi < n; gi++) {
                var gp = pointXY(gi, g);
                if (gi === 0) { ctx.moveTo(gp.x, gp.y); } else { ctx.lineTo(gp.x, gp.y); }
            }
            ctx.closePath();
            ctx.stroke();
        }
        // Angle lines.
        ctx.strokeStyle = '#e5e7eb';
        ctx.lineWidth = 0.5 * S;
        for (var i = 0; i < n; i++) {
            var ep = pointXY(i, 10);
            ctx.beginPath();
            ctx.moveTo(cx, cy);
            ctx.lineTo(ep.x, ep.y);
            ctx.stroke();
        }

        // Colored sector fill — each competency gets its own color region.
        var mOcc = m.occ || [];
        var maxO = 1;
        for (var i = 0; i < mOcc.length; i++) { if (mOcc[i] > maxO) { maxO = mOcc[i]; } }

        for (var i = 0; i < n; i++) {
            var prevI = (i - 1 + n) % n;
            var nextI = (i + 1) % n;
            var pPrev = pointXY(prevI, m.v[prevI] || 0);
            var pCurr = pointXY(i, m.v[i] || 0);
            var pNext = pointXY(nextI, m.v[nextI] || 0);
            var midL = { x: (pPrev.x + pCurr.x) / 2, y: (pPrev.y + pCurr.y) / 2 };
            var midR = { x: (pCurr.x + pNext.x) / 2, y: (pCurr.y + pNext.y) / 2 };
            ctx.beginPath();
            ctx.moveTo(cx, cy);
            ctx.lineTo(midL.x, midL.y);
            ctx.lineTo(pCurr.x, pCurr.y);
            ctx.lineTo(midR.x, midR.y);
            ctx.closePath();
            ctx.fillStyle = m.c[i] || '#666';
            ctx.globalAlpha = 0.05 + 0.10 * ((mOcc[i] || 0) / maxO);
            ctx.fill();
            ctx.strokeStyle = '#fff';
            ctx.globalAlpha = 0.5;
            ctx.lineWidth = 0.5 * S;
            ctx.stroke();
        }
        ctx.globalAlpha = 1;

        // Data polygon border.
        ctx.beginPath();
        for (var i = 0; i < n; i++) {
            var p = pointXY(i, m.v[i] || 0);
            if (i === 0) { ctx.moveTo(p.x, p.y); } else { ctx.lineTo(p.x, p.y); }
        }
        ctx.closePath();
        ctx.strokeStyle = '#94a3b8';
        ctx.lineWidth = 1 * S;
        ctx.stroke();

        // Points + donut rings.
        var tc = m.tc || 0;
        for (var i = 0; i < n; i++) {
            var p = pointXY(i, m.v[i] || 0);
            var pr = (1.5 + Math.round(((mOcc[i] || 0) / maxO) * 2.5)) * S;
            var color = m.c[i] || '#666';

            // Donut.
            if (tc > 0) {
                var outerR = pr + 2.5 * S;
                var innerR = outerR - 1.5 * S;
                var segAngle = (Math.PI * 2) / tc;
                var gap = tc > 6 ? 0.04 : 0.08;
                var co = (m.co[i] || []).slice().sort(function(a, b) { return b - a; });
                var mxC = 1;
                for (var j = 0; j < co.length; j++) { if (co[j] > mxC) { mxC = co[j]; } }
                for (var s = 0; s < tc; s++) {
                    var a0 = -Math.PI / 2 + s * segAngle + gap;
                    var a1 = -Math.PI / 2 + (s + 1) * segAngle - gap;
                    ctx.beginPath();
                    ctx.arc(p.x, p.y, outerR, a0, a1);
                    ctx.arc(p.x, p.y, innerR, a1, a0, true);
                    ctx.closePath();
                    var v = co[s] || 0;
                    if (v > 0) {
                        ctx.fillStyle = color;
                        ctx.globalAlpha = 0.3 + 0.65 * (v / mxC);
                    } else {
                        ctx.fillStyle = '#d1d5db';
                        ctx.globalAlpha = 0.3;
                    }
                    ctx.fill();
                }
                ctx.globalAlpha = 1;
            }

            // Point.
            ctx.beginPath();
            ctx.arc(p.x, p.y, pr, 0, Math.PI * 2);
            ctx.fillStyle = color;
            ctx.fill();
            ctx.strokeStyle = '#fff';
            ctx.lineWidth = 0.5 * S;
            ctx.stroke();
        }
    }

    function makeDianaClickable(canvas, url, radarData) {
        canvas.style.cursor = 'pointer';
        var tip = 'Perfil competencial';
        if (radarData && radarData.v) {
            var sum = 0, cnt = 0;
            for (var i = 0; i < radarData.v.length; i++) {
                if (radarData.v[i] > 0) { sum += radarData.v[i]; cnt++; }
            }
            if (cnt > 0) {
                tip += ' · Media: ' + (sum / cnt).toFixed(1);
            }
        }
        canvas.title = tip;
        canvas.addEventListener('click', function(e) {
            e.stopPropagation();
            e.preventDefault();
            window.location.href = url;
        });
    }

    function initGraderReport(studentPhotoMap, uploadUrl, sesskey, radarMap, reportBaseUrl) {
        var STORAGE_KEY = 'sentinel_grader_photos_visible';

        var run = function() {
            var table = document.querySelector('#user-grades') ||
                        document.querySelector('table.gradebook-table');
            // Si la tabla aún no está (Moodle puede pintarla tras el primer paint),
            // usar polling ligero. NO usar MutationObserver en document.body: en páginas
            // con muchos cambios DOM congela el navegador.
            if (!table) {
                var maxTries = 80;
                var n = 0;
                var pollId = setInterval(function() {
                    n++;
                    var t = document.querySelector('#user-grades') ||
                            document.querySelector('table.gradebook-table');
                    if (t) {
                        clearInterval(pollId);
                        run();
                    } else if (n >= maxTries) {
                        clearInterval(pollId);
                    }
                }, 250);
                return;
            }

            // ── Add toggle button to the "Nombre/Apellido" header ─────────────
            // Find the header th that contains a sort link for the name column.
            var nameTh = null;
            table.querySelectorAll('thead th').forEach(function(th) {
                if (nameTh) { return; }
                var txt = th.textContent.toLowerCase();
                if (txt.includes('nombre') || txt.includes('apellido') || txt.includes('name')) {
                    nameTh = th;
                }
            });

            var toggleBtn = document.createElement('button');
            toggleBtn.type  = 'button';
            toggleBtn.title = 'Mostrar / ocultar fotos';
            toggleBtn.style.cssText = [
                'background:none', 'border:1px solid #aaa', 'border-radius:4px',
                'padding:1px 4px', 'cursor:pointer', 'font-size:13px',
                'line-height:1', 'vertical-align:middle', 'margin-left:4px'
            ].join(';');
            toggleBtn.textContent = '📷';
            if (nameTh) {
                nameTh.appendChild(toggleBtn);
            }

            // ── Inject thumbnails inside each student name cell ───────────────
            var photoWrappers = []; // for toggle
            table.querySelectorAll('tbody tr').forEach(function(row) {
                // Find the userid from: (1) link to /user/view.php, (2) data-uid attr,
                // (3) row id like "uid_123" or "user_123", (4) any link with userid param.
                var link = row.querySelector('a[href*="/user/view.php"]') ||
                           row.querySelector('a[href*="userid="]');
                var uid = 0;
                var insertTarget = null;

                if (link) {
                    var m = link.href.match(/[?&](?:id|userid)=(\d+)/);
                    if (m) { uid = parseInt(m[1], 10); }
                    insertTarget = link.parentNode;
                }

                if (!uid && row.dataset.uid) {
                    uid = parseInt(row.dataset.uid, 10);
                }
                if (!uid && row.id) {
                    var rm = row.id.match(/(?:uid|user)[_-]?(\d+)/i);
                    if (rm) { uid = parseInt(rm[1], 10); }
                }
                if (!uid) {
                    var anyLink = row.querySelector('a[href*="id="]');
                    if (anyLink) {
                        var am = anyLink.href.match(/[?&]id=(\d+)/);
                        if (am && Object.prototype.hasOwnProperty.call(studentPhotoMap, parseInt(am[1], 10))) {
                            uid = parseInt(am[1], 10);
                            link = anyLink;
                            insertTarget = anyLink.parentNode;
                        }
                    }
                }
                if (!uid || !Object.prototype.hasOwnProperty.call(studentPhotoMap, uid)) { return; }

                // Find the name cell as insert target if not found yet.
                if (!insertTarget) {
                    var nameCell = row.querySelector('th') || row.querySelector('td');
                    if (nameCell) { insertTarget = nameCell; link = null; }
                }
                if (!insertTarget) { return; }

                // Avoid double-injection.
                if (insertTarget.querySelector('.sentinel-photo-wrap')) { return; }

                var photoUrl = studentPhotoMap[uid] || null;

                var thumb = buildThumbnail(uid, photoUrl, uploadUrl, sesskey, 48);
                thumb.className = 'sentinel-photo-wrap';
                thumb.style.display = 'inline-block';
                thumb.style.marginRight = '6px';
                thumb.style.verticalAlign = 'middle';

                // Insert before the link or at the start of the cell.
                if (link && link.parentNode === insertTarget) {
                    insertTarget.insertBefore(thumb, link);
                } else {
                    insertTarget.insertBefore(thumb, insertTarget.firstChild);
                }
                photoWrappers.push(thumb);

                // Mini diana.
                if (radarMap && radarMap[uid]) {
                    var mc = document.createElement('canvas');
                    mc.width = 192;
                    mc.height = 192;
                    mc.style.cssText = 'width:48px;height:48px;vertical-align:middle;margin-right:4px;display:inline-block;';
                    mc.className = 'sentinel-photo-wrap sentinel-mini-diana';
                    link.parentNode.insertBefore(mc, link);
                    photoWrappers.push(mc);
                    drawMiniRadar(mc, radarMap[uid]);
                    if (reportBaseUrl) {
                        makeDianaClickable(mc, appendUserId(reportBaseUrl, uid), radarMap[uid]);
                    }
                }
            });

            // ── Toggle visibility ─────────────────────────────────────────────
            var applyVisibility = function(visible) {
                photoWrappers.forEach(function(w) {
                    w.style.display = visible ? 'inline-block' : 'none';
                });
                toggleBtn.style.opacity = visible ? '1' : '0.4';
                localStorage.setItem(STORAGE_KEY, visible ? '1' : '0');
            };

            toggleBtn.addEventListener('click', function() {
                applyVisibility(localStorage.getItem(STORAGE_KEY) === '0');
            });

            // Apply stored preference (default: visible).
            applyVisibility(localStorage.getItem(STORAGE_KEY) !== '0');
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', run);
        } else {
            run();
        }
    }

    /**
     * Init on user profile page (/user/view.php).
     * Inserts the photo widget before the student's name heading.
     *
     * @param {number}      studentid
     * @param {string|null} photoUrl
     * @param {string}      uploadUrl
     * @param {string}      sesskey
     */
    function initUserProfile(studentid, photoUrl, uploadUrl, sesskey, gradeBadge, strings, miniRadar, reportUrl) {
        if (strings) { Object.assign(_S, strings); }
        var DEFAULT_SIZE = 100; // px — fallback si no se detecta avatar Moodle

        // Detecta el tamaño del avatar de Moodle ya renderizado en la página.
        function detectMoodleAvatarSize() {
            var candidates = [
                document.querySelector('.myprofileitem.picture img'),
                document.querySelector('.userprofile .picture img'),
                document.querySelector('[data-region="user-summary"] .picture img'),
                document.querySelector('img.userpicture'),
                document.querySelector('.page-header-image img'),
            ];
            for (var i = 0; i < candidates.length; i++) {
                if (candidates[i]) {
                    var w = candidates[i].naturalWidth || candidates[i].offsetWidth;
                    if (w > 0) { return w; }
                    // offsetWidth puede ser 0 si la imagen no ha cargado; intentar con el contenedor
                    var pw = candidates[i].parentNode && candidates[i].parentNode.offsetWidth;
                    if (pw > 0) { return pw; }
                }
            }
            // Iniciales (.userinitials): usar el ancho del elemento
            var mainContent = document.querySelector('#page-content') ||
                              document.querySelector('[role="main"]') ||
                              document.body;
            var initials = mainContent && mainContent.querySelector('.userinitials');
            if (initials && initials.offsetWidth > 0) { return initials.offsetWidth; }
            return DEFAULT_SIZE;
        }

        var inject = function() {
            if (document.querySelector('.sentinel-profile-photo')) { return true; }

            var SIZE = detectMoodleAvatarSize();
            // Respetar máximo y mínimo razonables
            if (SIZE < 30) { SIZE = 30; }
            if (SIZE > 120) { SIZE = 120; }

            var widget = buildWidget(studentid, photoUrl, uploadUrl, sesskey);
            widget.className = 'sentinel-profile-photo';
            var circle = widget.querySelector('div');
            if (circle) {
                circle.style.width  = SIZE + 'px';
                circle.style.height = SIZE + 'px';
            }
            widget.style.cssText += 'vertical-align:middle;align-self:center;';

            // Badge con la nota final del curso — se insertará junto al nombre
            var _gradeBadgeEl = null;
            if (gradeBadge && gradeBadge.value !== null && gradeBadge.value !== undefined) {
                var pct = gradeBadge.max > 0 ? gradeBadge.value / gradeBadge.max : 0;
                var hue = Math.round(pct * 120);
                _gradeBadgeEl = document.createElement('a');
                _gradeBadgeEl.href = gradeBadge.url || '#';
                _gradeBadgeEl.textContent = gradeBadge.value;
                _gradeBadgeEl.title = _S.gradetooltip;
                _gradeBadgeEl.className = 'sentinel-profile-grade';
                _gradeBadgeEl.style.cssText = [
                    'display:inline-block',
                    'background:hsl(' + hue + ',65%,36%)',
                    'color:#fff',
                    'font-weight:700',
                    'padding:2px 10px',
                    'border-radius:12px',
                    'font-size:0.55em',
                    'margin-left:8px',
                    'text-decoration:none',
                    'white-space:nowrap',
                    'box-shadow:0 1px 3px rgba(0,0,0,0.25)',
                    'cursor:pointer',
                    'vertical-align:middle',
                ].join(';');
            }

            // Mini diana canvas (se inserta junto al widget si hay datos).
            var radarCanvas = null;
            if (miniRadar) {
                radarCanvas = document.createElement('canvas');
                radarCanvas.width = SIZE * 4;
                radarCanvas.height = SIZE * 4;
                radarCanvas.className = 'sentinel-profile-photo';
                radarCanvas.style.cssText = 'width:' + SIZE + 'px;height:' + SIZE + 'px;flex-shrink:0;align-self:center;';
                drawMiniRadar(radarCanvas, miniRadar);
                if (reportUrl) {
                    makeDianaClickable(radarCanvas, reportUrl, miniRadar);
                }
            }

            var insertAfterWidget = function(parent) {
                if (radarCanvas) {
                    parent.insertBefore(radarCanvas, widget.nextSibling);
                }
            };

            // Inserta el badge junto al nombre del alumno.
            var placeBadgeNextToName = function() {
                if (!_gradeBadgeEl) { return; }
                var heading = document.querySelector('.page-header-headings h2') ||
                              document.querySelector('.page-header-headings h1') ||
                              document.querySelector('#page-header h2');
                if (heading && !heading.querySelector('.sentinel-profile-grade')) {
                    heading.appendChild(_gradeBadgeEl);
                }
            };

            var hideMoodleAvatar = function() {};

            // Caso 1: perfil estándar de Moodle (página /user/profile.php o perfil propio).
            var anchor = document.querySelector('.myprofileitem.picture') ||
                         document.querySelector('.userprofile .picture') ||
                         document.querySelector('[data-region="user-summary"] .picture');
            if (anchor) {
                anchor.style.display    = 'flex';
                anchor.style.alignItems = 'center';
                anchor.style.gap        = '12px';
                anchor.style.flexWrap   = 'wrap';
                anchor.insertBefore(widget, anchor.firstChild);
                insertAfterWidget(anchor);
                hideMoodleAvatar(anchor);
                placeBadgeNextToName();
                return true;
            }

            // Caso 2: perfil dentro de un curso (Boost 4.x usa .page-header-image).
            var headerImg = document.querySelector('.page-header-image');
            if (headerImg) {
                var headerParent = headerImg.parentNode;
                headerParent.insertBefore(widget, headerImg);
                insertAfterWidget(headerParent);
                hideMoodleAvatar(headerParent);
                placeBadgeNextToName();
                return true;
            }

            // Caso 3: fallback — img.userpicture en cualquier contenedor.
            var img = document.querySelector('.userprofile img') ||
                      document.querySelector('img.userpicture');
            if (img) {
                anchor = img.parentNode;
                anchor.style.display    = 'flex';
                anchor.style.alignItems = 'center';
                anchor.style.gap        = '12px';
                anchor.style.flexWrap   = 'wrap';
                anchor.insertBefore(widget, img);
                insertAfterWidget(anchor);
                hideMoodleAvatar(anchor);
                placeBadgeNextToName();
                return true;
            }

            // Caso 4: avatar de iniciales (span.userinitials).
            var mainContent = document.querySelector('#page-content') ||
                              document.querySelector('[role="main"]') ||
                              document.body;
            var initialsEl = mainContent.querySelector('.userinitials');
            if (initialsEl) {
                var avatarWrap = initialsEl.closest('.page-header-image')
                              || initialsEl.closest('.page-header-headings')
                              || initialsEl.parentNode;
                var container = avatarWrap.parentNode;
                container.style.display    = 'flex';
                container.style.alignItems = 'center';
                container.style.gap        = '12px';
                container.style.flexWrap   = 'wrap';
                container.insertBefore(widget, avatarWrap);
                insertAfterWidget(container);
                hideMoodleAvatar(container);
                placeBadgeNextToName();
                return true;
            }

            return false;
        };

        var run = function() {
            if (inject()) { return; }
            // El bloque myprofile puede cargar tarde — observar.
            var obs = new MutationObserver(function(mutations, observer) {
                if (inject()) { observer.disconnect(); }
            });
            obs.observe(document.body, {childList: true, subtree: true});
            // Desconectar tras 10s para no dejar observers zombis.
            setTimeout(function() { obs.disconnect(); }, 10000);
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', run);
        } else {
            run();
        }
    }

    return {
        initGrading: initGrading,
        initParticipants: initParticipants,
        initGraderReport: initGraderReport,
        initUserProfile: initUserProfile,
    };
});
