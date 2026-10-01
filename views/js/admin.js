(function () {
    'use strict';

    var root = document.getElementById('mfa-root');
    if (!root) { return; }

    var cfg;
    try { cfg = JSON.parse(root.getAttribute('data-config')); } catch (e) { return; }
    var T = cfg.tr || {};
    var settings = cfg.settings || {};
    var presets = cfg.presets || [];

    /* PrestaShop may render a second module title above getContent().
       Hide only the redundant "Module name vX" heading, not the main
       "Configure Module name" page heading. */
    (function removeDuplicateModuleHeading() {
        var label = String(cfg.moduleDisplayName || '').trim();
        var version = String(cfg.moduleVersion || '').trim();
        if (!label || !version) { return; }
        var wanted = (label + ' v').toLowerCase();
        var headings = qsa('h1,h2,h3,h4', document);
        headings.forEach(function (heading) {
            if (heading === root || root.contains(heading)) { return; }
            var text = (heading.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
            if (text.indexOf(wanted) === 0 && /^\d+(?:\.\d+){1,3}$/.test(text.slice(wanted.length))) {
                heading.style.display = 'none';
            }
        });
    })();

    var ICON_WARN = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M12 2L1 21h22z" fill="currentColor"/><path d="M12 9v5.2" stroke="#fff" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="17.6" r="1.2" fill="#fff"/></svg>';
    var ICON_OK = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><circle cx="12" cy="12" r="10" fill="currentColor"/><path d="M7 12.5l3.2 3.2L17 9" stroke="#fff" stroke-width="2.2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    var ICON_SCAN = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><circle cx="12" cy="12" r="2" fill="currentColor"/><path d="M12 4a8 8 0 0 1 8 8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M12 7a5 5 0 0 1 5 5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M12 20a8 8 0 0 1-8-8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M12 17a5 5 0 0 1-5-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';

    function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
    function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
    function fmt(str, map) {
        return String(str).replace(/%(\w+)%/g, function (m, k) {
            return Object.prototype.hasOwnProperty.call(map, k) ? map[k] : m;
        });
    }
    function esc(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function firstValue(obj) {
        for (var k in obj) {
            if (Object.prototype.hasOwnProperty.call(obj, k) && obj[k]) { return obj[k]; }
        }
        return '';
    }

    /* ------------------------------ AJAX ------------------------------ */
    function post(action, data) {
        var url = cfg.ajaxUrl + (cfg.ajaxUrl.indexOf('?') === -1 ? '?' : '&') + 'ajax=1&action=' + encodeURIComponent(action);
        var body = new URLSearchParams();
        Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        }).then(function (r) { return r.text(); }).then(function (txt) {
            try { return JSON.parse(txt); } catch (e) { return { success: false, message: T.error }; }
        }).catch(function () { return { success: false, message: T.error }; });
    }

    var toastEl = qs('#mfa-toast'), toastTimer = null;
    function toast(msg, ok) {
        if (!msg) { return; }
        toastEl.textContent = msg;
        toastEl.className = 'mfa-toast ' + (ok ? 'ok' : 'err');
        toastEl.style.display = 'block';
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.style.display = 'none'; }, 4500);
    }

    /* ------------------------------ Tabs ------------------------------ */
    qsa('.mfa-tab', root).forEach(function (tab) {
        tab.addEventListener('click', function () {
            var name = tab.getAttribute('data-tab');
            qsa('.mfa-tab', root).forEach(function (t) { t.classList.toggle('is-active', t === tab); });
            qsa('.mfa-pane', root).forEach(function (p) { p.classList.toggle('is-active', p.getAttribute('data-pane') === name); });
        });
    });

    /* ------------------------------ Display settings ------------------------------ */
    function applySettings() {
        root.classList.toggle('mfa-hide-id', !settings.show_id);
        root.classList.toggle('mfa-hide-logo', !settings.show_logo);
        root.classList.toggle('mfa-hide-name', !settings.show_name);
        root.classList.toggle('mfa-show-exc-text', !!settings.show_exc_text);
        root.classList.toggle('mfa-show-label-text', !!settings.show_label_text);
    }
    applySettings();

    var saveSettingsBtn = qs('#mfa-save-settings');
    if (saveSettingsBtn) {
        saveSettingsBtn.addEventListener('click', function () {
            var data = {};
            qsa('[data-setting]', root).forEach(function (cb) { data[cb.getAttribute('data-setting')] = cb.checked ? 1 : 0; });
            saveSettingsBtn.disabled = true;
            post('save_settings', data).then(function (r) {
                saveSettingsBtn.disabled = false;
                if (r.success && r.settings) { settings = r.settings; applySettings(); }
                toast(r.message, r.success);
            });
        });
    }

    /* ------------------------------ Label templates (dropdown) ------------------------------ */
    function presetLabel(p) {
        return p.texts[cfg.adminIso] || p.texts.en || firstValue(p.texts);
    }
    function findPreset(key) {
        for (var i = 0; i < presets.length; i++) { if (presets[i].key === key) { return presets[i]; } }
        return null;
    }
    function buildPresetOptions(select) {
        var html = '<option value="">' + esc(T.preset) + '</option>';
        presets.forEach(function (p) { html += '<option value="' + esc(p.key) + '">' + esc(presetLabel(p)) + '</option>'; });
        select.innerHTML = html;
    }
    function visibleLabelInput(row) {
        var inputs = qsa('.mfa-label', row);
        for (var i = 0; i < inputs.length; i++) { if (inputs[i].style.display !== 'none') { return inputs[i]; } }
        return inputs[0];
    }
    function updatePlus(row) {
        var plus = qs('.mfa-add-preset', row);
        var inp = visibleLabelInput(row);
        if (!plus || !inp) { return; }
        var val = (inp.value || '').trim().toLowerCase();
        var iso = inp.getAttribute('data-iso');
        var exists = presets.some(function (p) { return (p.texts[iso] || '').toLowerCase() === val; });
        plus.style.display = (val !== '' && !exists) ? '' : 'none';
    }
    function rebuildPresets() {
        qsa('.mfa-preset', root).forEach(buildPresetOptions);
        qsa('.mfa-row', root).forEach(updatePlus);
    }
    function renderPresetManager() {
        var manager = qs('#mfa-preset-manager');
        if (!manager) { return; }
        manager.innerHTML = '';

        if (!presets.length) {
            var empty = document.createElement('div');
            empty.className = 'mfa-preset-empty';
            empty.textContent = T.cfgLabelEmpty || T.emptyList;
            manager.appendChild(empty);
            return;
        }

        presets.forEach(function (preset) {
            var item = document.createElement('div');
            item.className = 'mfa-preset-item';
            item.setAttribute('data-preset-key', preset.key);

            var head = document.createElement('div');
            head.className = 'mfa-preset-item-head';

            var title = document.createElement('span');
            title.className = 'mfa-preset-item-title';
            title.textContent = presetLabel(preset);

            var actions = document.createElement('div');
            actions.className = 'mfa-preset-item-actions';

            var save = document.createElement('button');
            save.type = 'button';
            save.className = 'mfa-btn mfa-btn-primary';
            save.textContent = T.cfgLabelSave || T.save || 'Save';

            var del = document.createElement('button');
            del.type = 'button';
            del.className = 'mfa-btn mfa-btn-danger';
            del.textContent = T.cfgLabelDelete || T.btnRemove;

            actions.appendChild(save);
            actions.appendChild(del);
            head.appendChild(title);
            head.appendChild(actions);

            var body = document.createElement('div');
            body.className = 'mfa-preset-item-body';
            var inputs = [];

            cfg.langs.forEach(function (lang) {
                var line = document.createElement('div');
                line.className = 'mfa-preset-lang';

                var code = document.createElement('span');
                code.className = 'mfa-preset-lang-code';
                code.textContent = lang.iso;

                var inp = document.createElement('input');
                inp.type = 'text';
                inp.maxLength = 255;
                inp.value = preset.texts[lang.iso] || '';
                inp.setAttribute('data-iso', lang.iso);

                inputs.push(inp);
                line.appendChild(code);
                line.appendChild(inp);
                body.appendChild(line);
            });

            item.appendChild(head);
            item.appendChild(body);
            manager.appendChild(item);

            save.addEventListener('click', function () {
                var data = { key: preset.key };
                var any = false;
                inputs.forEach(function (inp) {
                    var value = (inp.value || '').trim();
                    data['texts[' + inp.getAttribute('data-iso') + ']'] = value;
                    if (value !== '') { any = true; }
                });
                if (!any) {
                    toast(T.enterText, false);
                    return;
                }

                item.classList.add('is-saving');
                save.disabled = true;
                del.disabled = true;
                post('update_preset', data).then(function (r) {
                    item.classList.remove('is-saving');
                    save.disabled = false;
                    del.disabled = false;
                    if (r.success && r.presets) { setPresets(r.presets); }
                    toast(r.message, r.success);
                });
            });

            del.addEventListener('click', function () {
                if (!window.confirm(T.cfgLabelConfirmDelete || T.confirmClear)) { return; }
                item.classList.add('is-saving');
                save.disabled = true;
                del.disabled = true;
                post('delete_preset', { key: preset.key }).then(function (r) {
                    item.classList.remove('is-saving');
                    if (r.success && r.presets) { setPresets(r.presets); }
                    toast(r.message, r.success);
                });
            });
        });
    }

    function setPresets(list) {
        presets = list || [];
        rebuildPresets();
        renderPresetManager();
    }

    /* ------------------------------ Rows ------------------------------ */
    function renderExc(row) {
        var cell = qs('.mfa-c-exc', row);
        var total = parseInt(row.getAttribute('data-total'), 10) || 0;
        var exc = parseInt(row.getAttribute('data-exceptions'), 10) || 0;
        var cls, icon, count, title;
        if (exc > 0) {
            cls = 'mfa-exc-warn';
            icon = ICON_WARN;
            count = '<span class="mfa-exc-count">(' + exc + '/' + total + ')</span>';
            title = fmt(T.tipExceptions, { exceptions: exc, total: total });
        } else {
            cls = 'mfa-exc-ok';
            icon = ICON_OK;
            count = '';
            title = T.tipNone;
        }
        var dis = (total === 0 ? ' disabled="disabled"' : '');
        cell.innerHTML = '<div class="mfa-exc-wrap">' +
            '<button type="button" class="mfa-exc-btn ' + cls + '" title="' + esc(title) + '"' + dis + '>' +
                icon + count + '<span class="mfa-btn-text">' + esc(T.exceptionsLabel) + '</span></button>' +
            '<button type="button" class="mfa-scan-btn" title="' + esc(T.scanTitle) + '"' + dis + '>' +
                ICON_SCAN + '<span class="mfa-btn-text">' + esc(T.scanLabel) + '</span></button>' +
            '</div>';
    }

    function setBusy(row, busy) {
        qsa('input, select, button', row).forEach(function (el) { el.disabled = busy; });
        if (!busy) { renderExc(row); }
    }

    function markDirty(row) { row.classList.add('is-dirty'); }

    function saveRow(row, silent) {
        var data = {
            id_manufacturer: row.getAttribute('data-id'),
            active: qs('.mfa-active', row).checked ? 1 : 0,
            out_of_stock: qs('.mfa-oos', row).value
        };
        qsa('.mfa-label', row).forEach(function (inp) {
            data['label[' + inp.getAttribute('data-lang') + ']'] = inp.value;
        });
        setBusy(row, true);
        return post('save_manufacturer', data).then(function (r) {
            if (r.success && typeof r.exceptions !== 'undefined') {
                row.setAttribute('data-exceptions', r.exceptions);
            }
            setBusy(row, false);
            if (!silent) { toast(r.message, r.success); }
            if (r.success) {
                row.classList.toggle('is-off', !data.active);
                row.classList.remove('is-dirty');
            }
            updatePlus(row);
            return r;
        });
    }

    function scanRow(row) {
        setBusy(row, true);
        post('scan_exceptions', {
            id_manufacturer: row.getAttribute('data-id'),
            out_of_stock: qs('.mfa-oos', row).value
        }).then(function (r) {
            if (r.success && typeof r.exceptions !== 'undefined') {
                row.setAttribute('data-exceptions', r.exceptions);
            }
            setBusy(row, false);
            toast(r.message, r.success);
            applyFilter();
        });
    }

    qsa('.mfa-row', root).forEach(function (row) {
        buildPresetOptions(qs('.mfa-preset', row));
        renderExc(row);
        updatePlus(row);

        qs('.mfa-save', row).addEventListener('click', function () { saveRow(row, false); });

        var cb = qs('.mfa-active', row);
        cb.addEventListener('change', function () {
            var wanted = cb.checked;
            saveRow(row, false).then(function (r) {
                if (!r.success) {
                    cb.checked = !wanted;
                    row.classList.toggle('is-off', !cb.checked);
                    renderExc(row);
                }
            });
        });

        qs('.mfa-oos', row).addEventListener('change', function () { markDirty(row); });
        qsa('.mfa-label', row).forEach(function (inp) {
            inp.addEventListener('input', function () { markDirty(row); updatePlus(row); });
        });

        var preset = qs('.mfa-preset', row);
        preset.addEventListener('change', function () {
            var p = findPreset(preset.value);
            if (!p) { return; }
            var fallback = firstValue(p.texts);
            qsa('.mfa-label', row).forEach(function (inp) {
                inp.value = p.texts[inp.getAttribute('data-iso')] || fallback;
            });
            preset.value = '';
            markDirty(row);
            updatePlus(row);
        });

        var langSel = qs('.mfa-lang', row);
        if (langSel) {
            langSel.addEventListener('change', function () {
                qsa('.mfa-label', row).forEach(function (inp) {
                    inp.style.display = inp.getAttribute('data-lang') === langSel.value ? '' : 'none';
                });
                updatePlus(row);
            });
        }

        qs('.mfa-add-preset', row).addEventListener('click', function () {
            var data = {};
            qsa('.mfa-label', row).forEach(function (inp) {
                var v = (inp.value || '').trim();
                if (v !== '') { data['texts[' + inp.getAttribute('data-iso') + ']'] = v; }
            });
            post('add_preset', data).then(function (r) {
                if (r.success && r.presets) { setPresets(r.presets); }
                toast(r.message, r.success);
            });
        });
    });

    /* ------------------------------ Sorting ------------------------------ */
    var sort = { key: 'name', dir: 1 };
    var tbody = qs('#mfa-table tbody');

    function compareNames(a, b) {
        try {
            return a.localeCompare(b, cfg.locale || undefined, { sensitivity: 'base', numeric: true });
        } catch (e) {
            return a.localeCompare(b);
        }
    }

    function sortRows() {
        if (!tbody) { return; }
        var rows = qsa('.mfa-row', tbody);
        rows.sort(function (a, b) {
            if (sort.key === 'id') {
                return (parseInt(a.getAttribute('data-id'), 10) - parseInt(b.getAttribute('data-id'), 10)) * sort.dir;
            }
            return compareNames(a.getAttribute('data-name'), b.getAttribute('data-name')) * sort.dir;
        });
        rows.forEach(function (r) { tbody.appendChild(r); });
        qsa('.mfa-sortable', root).forEach(function (th) {
            var active = th.getAttribute('data-sort') === sort.key;
            th.classList.toggle('is-sorted', active);
            qs('.mfa-sort-ind', th).textContent = active ? (sort.dir === 1 ? ' \u25B2' : ' \u25BC') : '';
        });
    }

    qsa('.mfa-sortable', root).forEach(function (th) {
        th.addEventListener('click', function () {
            var key = th.getAttribute('data-sort');
            if (sort.key === key) { sort.dir = -sort.dir; } else { sort.key = key; sort.dir = 1; }
            sortRows();
        });
    });
    sortRows();

    /* ------------------------------ Filtering ------------------------------ */
    var filter = 'all';

    function applyFilter() {
        qsa('.mfa-row', root).forEach(function (row) {
            var active = qs('.mfa-active', row).checked;
            var exc = parseInt(row.getAttribute('data-exceptions'), 10) || 0;
            var show = true;
            if (filter === 'active') { show = active; }
            else if (filter === 'inactive') { show = !active; }
            else if (filter === 'exceptions') { show = exc > 0; }
            row.style.display = show ? '' : 'none';
        });
    }

    qsa('.mfa-fbtn', root).forEach(function (btn) {
        btn.addEventListener('click', function () {
            filter = btn.getAttribute('data-filter');
            qsa('.mfa-fbtn', root).forEach(function (b) { b.classList.toggle('is-active', b === btn); });
            applyFilter();
        });
    });

    /* ------------------------------ Save all ------------------------------ */
    var saveAllBtn = qs('#mfa-save-all');
    if (saveAllBtn) {
        saveAllBtn.addEventListener('click', function () {
            var rows = qsa('.mfa-row.is-dirty', root);
            if (!rows.length) { toast(T.nothingToSave, true); return; }
            var ok = 0, fail = 0;
            saveAllBtn.disabled = true;
            var chain = Promise.resolve();
            rows.forEach(function (row) {
                chain = chain.then(function () {
                    return saveRow(row, true).then(function (r) { if (r.success) { ok++; } else { fail++; } });
                });
            });
            chain.then(function () {
                saveAllBtn.disabled = false;
                if (fail) { toast(fmt(T.saveFailed, { count: fail }), false); }
                else { toast(fmt(T.savedCount, { count: ok }), true); }
            });
        });
    }

    /* ------------------------------ Label management modal ------------------------------ */
    var lm = qs('#mfa-label-modal');
    document.body.appendChild(lm);
    var lmTitle = qs('#mfa-label-title');
    var lmBody = qs('#mfa-label-body');
    var lmFooter = qs('#mfa-label-footer');

    function closeLabelModal() { lm.style.display = 'none'; }
    qs('#mfa-label-close').addEventListener('click', closeLabelModal);
    lm.addEventListener('mousedown', function (e) { if (e.target === lm) { closeLabelModal(); } });

    function openAddLabel() {
        lmTitle.textContent = T.addLabel;
        lmBody.innerHTML = '';
        lmFooter.innerHTML = '';
        var inputs = [];
        cfg.langs.forEach(function (lang) {
            var wrap = document.createElement('div');
            wrap.className = 'mfa-label-line';
            var tag = document.createElement('span');
            tag.className = 'mfa-lang-tag';
            tag.textContent = lang.iso.toUpperCase();
            var inp = document.createElement('input');
            inp.type = 'text';
            inp.maxLength = 255;
            inp.setAttribute('data-iso', lang.iso);
            inputs.push(inp);
            wrap.appendChild(tag);
            wrap.appendChild(inp);
            lmBody.appendChild(wrap);
        });
        var add = document.createElement('button');
        add.type = 'button';
        add.className = 'mfa-btn mfa-btn-primary';
        add.textContent = T.btnAdd;
        add.addEventListener('click', function () {
            var data = {}, any = false;
            inputs.forEach(function (inp) {
                var v = inp.value.trim();
                if (v !== '') { data['texts[' + inp.getAttribute('data-iso') + ']'] = v; any = true; }
            });
            if (!any) { toast(T.enterText, false); return; }
            add.disabled = true;
            post('add_preset', data).then(function (r) {
                add.disabled = false;
                if (r.success && r.presets) { setPresets(r.presets); closeLabelModal(); }
                toast(r.message, r.success);
            });
        });
        lmFooter.appendChild(add);
        lm.style.display = 'flex';
        if (inputs[0]) { inputs[0].focus(); }
    }

    function openRemoveLabel() {
        lmTitle.textContent = T.removeLabel;
        lmFooter.innerHTML = '';
        function render() {
            lmBody.innerHTML = '';
            if (!presets.length) {
                var p = document.createElement('p');
                p.className = 'mfa-empty';
                p.textContent = T.emptyList;
                lmBody.appendChild(p);
                return;
            }
            presets.forEach(function (preset) {
                var line = document.createElement('div');
                line.className = 'mfa-label-line mfa-label-item';
                var text = document.createElement('span');
                text.className = 'mfa-label-text';
                text.textContent = presetLabel(preset);
                var del = document.createElement('button');
                del.type = 'button';
                del.className = 'mfa-btn mfa-btn-danger';
                del.textContent = T.btnRemove;
                del.addEventListener('click', function () {
                    del.disabled = true;
                    post('delete_preset', { key: preset.key }).then(function (r) {
                        if (r.success && r.presets) { setPresets(r.presets); render(); }
                        toast(r.message, r.success);
                    });
                });
                line.appendChild(text);
                line.appendChild(del);
                lmBody.appendChild(line);
            });
        }
        render();
        lm.style.display = 'flex';
    }

    var addLabelBtn = qs('#mfa-add-label');
    var delLabelBtn = qs('#mfa-del-label');
    if (addLabelBtn) { addLabelBtn.addEventListener('click', openAddLabel); }
    if (delLabelBtn) { delLabelBtn.addEventListener('click', openRemoveLabel); }

    var configAddLabelBtn = qs('#mfa-config-add-label');
    if (configAddLabelBtn) { configAddLabelBtn.addEventListener('click', openAddLabel); }

    renderPresetManager();

    /* ------------------------------ Exceptions modal ------------------------------ */
    var modal = qs('#mfa-modal');
    document.body.appendChild(modal); // avoid clipping by admin layout containers
    var body = qs('#mfa-modal-body');
    var moreBtn = qs('#mfa-more');
    var emptyEl = qs('#mfa-modal-empty');
    var fName = qs('#mfa-f-name');
    var fRef = qs('#mfa-f-ref');
    var selCount = qs('#mfa-sel-count');
    var state = null;

    function updateSelCount() {
        selCount.textContent = fmt(T.selected, { count: Object.keys(state.selected).length });
    }

    function addProductRow(p) {
        var tr = document.createElement('tr');
        var checked = !!state.selected[p.id];
        tr.className = (checked ? 'is-checked ' : '') + (p.active ? '' : 'is-inactive');
        tr.setAttribute('data-id', p.id);

        var td1 = document.createElement('td');
        var cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.checked = checked;
        cb.addEventListener('change', function () {
            if (cb.checked) { state.selected[p.id] = true; } else { delete state.selected[p.id]; }
            tr.classList.toggle('is-checked', cb.checked);
            updateSelCount();
        });
        td1.appendChild(cb);

        var td2 = document.createElement('td'); td2.textContent = p.id;
        var td3 = document.createElement('td'); td3.textContent = p.reference;
        var td4 = document.createElement('td'); td4.className = 'mfa-pname'; td4.textContent = p.name;

        tr.appendChild(td1); tr.appendChild(td2); tr.appendChild(td3); tr.appendChild(td4);
        body.appendChild(tr);
    }

    function loadProducts(reset) {
        if (reset) { state.offset = 0; body.innerHTML = ''; }
        emptyEl.style.display = 'block';
        emptyEl.textContent = T.loading;
        moreBtn.style.display = 'none';
        var token = ++state.token;

        post('get_exceptions', {
            id_manufacturer: state.id,
            name: fName.value,
            ref: fRef.value,
            offset: state.offset,
            limit: 100,
            with_ids: state.idsLoaded ? 0 : 1
        }).then(function (r) {
            if (!state || token !== state.token) { return; }
            if (!r.success) { emptyEl.textContent = r.message || T.error; return; }
            if (r.ids) {
                state.selected = {};
                r.ids.forEach(function (id) { state.selected[id] = true; });
                state.idsLoaded = true;
                updateSelCount();
            }
            r.products.forEach(addProductRow);
            state.offset += r.products.length;
            if (state.offset === 0) {
                emptyEl.style.display = 'block';
                emptyEl.textContent = T.noProducts;
            } else {
                emptyEl.style.display = 'none';
            }
            moreBtn.style.display = state.offset < r.total ? '' : 'none';
        });
    }

    function openModal(row) {
        state = { id: row.getAttribute('data-id'), row: row, selected: {}, idsLoaded: false, offset: 0, token: 0 };
        qs('#mfa-modal-title').textContent = fmt(T.modalTitle, { name: row.getAttribute('data-name') });
        fName.value = '';
        fRef.value = '';
        selCount.textContent = '';
        modal.style.display = 'flex';
        loadProducts(true);
    }

    function closeModal() {
        modal.style.display = 'none';
        state = null;
    }

    root.addEventListener('click', function (e) {
        var scan = e.target.closest ? e.target.closest('.mfa-scan-btn') : null;
        if (scan && !scan.disabled) { scanRow(scan.closest('.mfa-row')); return; }
        var btn = e.target.closest ? e.target.closest('.mfa-exc-btn') : null;
        if (btn && !btn.disabled) { openModal(btn.closest('.mfa-row')); }
    });

    qs('#mfa-modal-close').addEventListener('click', closeModal);
    modal.addEventListener('mousedown', function (e) { if (e.target === modal) { closeModal(); } });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        if (state) { closeModal(); }
        closeLabelModal();
    });

    var filterTimer = null;
    function onFilter() {
        clearTimeout(filterTimer);
        filterTimer = setTimeout(function () { if (state) { loadProducts(true); } }, 300);
    }
    fName.addEventListener('input', onFilter);
    fRef.addEventListener('input', onFilter);
    moreBtn.addEventListener('click', function () { loadProducts(false); });

    function toggleVisible(flag) {
        qsa('tr', body).forEach(function (tr) {
            var id = tr.getAttribute('data-id');
            var cb = qs('input', tr);
            cb.checked = flag;
            tr.classList.toggle('is-checked', flag);
            if (flag) { state.selected[id] = true; } else { delete state.selected[id]; }
        });
        updateSelCount();
    }
    qs('#mfa-sel-all').addEventListener('click', function (e) { e.preventDefault(); toggleVisible(true); });
    qs('#mfa-sel-none').addEventListener('click', function (e) { e.preventDefault(); toggleVisible(false); });

    function afterExceptionsChange(r) {
        toast(r.message, r.success);
        if (r.success && state) {
            state.row.setAttribute('data-exceptions', r.exceptions);
            renderExc(state.row);
            closeModal();
            applyFilter();
        }
    }

    qs('#mfa-exc-save').addEventListener('click', function () {
        post('save_exceptions', {
            id_manufacturer: state.id,
            ids: JSON.stringify(Object.keys(state.selected).map(Number))
        }).then(afterExceptionsChange);
    });

    qs('#mfa-exc-clear').addEventListener('click', function () {
        if (!window.confirm(T.confirmClear)) { return; }
        post('clear_exceptions', { id_manufacturer: state.id }).then(afterExceptionsChange);
    });
})();
