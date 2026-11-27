// Quick Panel: an auto-hide shortcut panel on the right edge of every page.
// - Tiles are taken from the menu the user can already see (the sidebar), so a tile
//   never leads to a page the user has no permission for.
// - Customise: drag an option onto the panel to add it, drag tiles to reorder, drag a
//   tile back to the option list (or press its x) to remove it. Saved per login.
// - Instant search: every kind of invoice, product code/barcode and serial number.
(function () {
    'use strict';
    var config = window.QUICK_PANEL || {};
    var sidebar = document.querySelector('.main-sidebar .nav-sidebar');
    if (!config.api || !sidebar) return;

    var MAX_ITEMS = 24;
    var root = String(config.root || '').replace(/\/+$/, '');
    var storageKey = 'quickPanelPinned:' + (config.owner || '');

    // ---------- Options: every link of the rendered menu ----------
    var relPath = function (href) {
        var url;
        try { url = new URL(href, location.href); } catch (e) { return ''; }
        if (url.origin !== location.origin) return '';
        var path = url.pathname;
        if (root && path.indexOf(root + '/') === 0) path = path.slice(root.length + 1);
        else path = path.replace(/^\/+/, '');
        if (!/\.php$/i.test(path) || /(^|\/)logout\.php$/i.test(path) || /(^|\/)delete[^/]*\.php$/i.test(path)) return '';
        return path + url.search;
    };
    var ownText = function (p) {
        if (!p) return '';
        return Array.prototype.filter.call(p.childNodes, function (n) { return n.nodeType === 3; })
            .map(function (n) { return n.textContent; }).join(' ').replace(/\s+/g, ' ').trim();
    };

    var RULES = [
        [/purchase.?return|purchases\/purchase_return/, 'fas fa-truck-loading', '#f08c00'],
        [/sales.?return|refund|damage.?return|damaged_return/, 'fas fa-undo-alt', '#e8590c'],
        [/create_invoice\.php|create invoice|new sale|pos\b|booking_create|new booking/, 'fas fa-cash-register', '#d6336c'],
        [/service/, 'fas fa-tools', '#0ca678'],
        [/warranty/, 'fas fa-shield-alt', '#1c7ed6'],
        [/visa/, 'fas fa-passport', '#7048e8'],
        [/hajj|umrah|groups?\.php/, 'fas fa-kaaba', '#495057'],
        [/air.?ticket|travel|booking/, 'fas fa-plane', '#7048e8'],
        [/invoice/, 'fas fa-file-invoice', '#d6336c'],
        [/receive_payment|due pay|payment|collection|receipt/, 'fas fa-hand-holding-usd', '#37b24d'],
        [/purchase/, 'fas fa-shopping-cart', '#e03131'],
        [/products\/create|add product/, 'fas fa-cart-plus', '#1098ad'],
        [/categor/, 'fas fa-tags', '#f59f00'],
        [/expired/, 'fas fa-hourglass-end', '#c92a2a'],
        [/product/, 'fas fa-box-open', '#f59f00'],
        [/expense/, 'fas fa-money-bill-wave', '#7048e8'],
        [/moneyin|money in/, 'fas fa-coins', '#2b8a3e'],
        [/transfer/, 'fas fa-exchange-alt', '#1098ad'],
        [/transaction|cash out|statement/, 'fas fa-file-alt', '#495057'],
        [/wallet/, 'fas fa-wallet', '#0b7285'],
        [/supplier|vendor/, 'fas fa-truck', '#5c7cfa'],
        [/customer|client/, 'fas fa-users', '#4263eb'],
        [/live.?report|stock|inventory|warehouse/, 'fas fa-warehouse', '#9c6644'],
        [/report|ledger|profit/, 'fas fa-chart-bar', '#c2255c'],
        [/attendance/, 'fas fa-user-check', '#5f3dc4'],
        [/salary/, 'fas fa-money-check-alt', '#5f3dc4'],
        [/leave/, 'fas fa-file-medical', '#5f3dc4'],
        [/staff/, 'fas fa-user-tie', '#5f3dc4'],
        [/notice/, 'fas fa-bullhorn', '#e67700'],
        [/lead/, 'fas fa-bullseye', '#d9480f'],
        [/project|package/, 'fas fa-project-diagram', '#3b5bdb'],
        [/parking|vehicle/, 'fas fa-parking', '#1971c2'],
        [/land|plot/, 'fas fa-map-marked-alt', '#2f9e44'],
        [/e-?shop|store/, 'fas fa-store', '#ae3ec9'],
        [/table|restaurant|menu/, 'fas fa-utensils', '#e8590c'],
        [/backup|export|import/, 'fas fa-database', '#495057'],
        [/setting|setup|option|manage/, 'fas fa-cog', '#495057'],
        [/dashboard|index\.php$/, 'fas fa-home', '#1677e8'],
        [/profile|user/, 'fas fa-user', '#1677e8'],
        [/help|tutorial|support/, 'fas fa-question-circle', '#868e96']
    ];
    var PALETTE = ['#d6336c', '#0ca678', '#e8590c', '#e03131', '#1098ad', '#7048e8', '#37b24d', '#1c7ed6', '#f59f00', '#5f3dc4'];
    var defaultLook = function (path, label, menuIcon) {
        var text = (path + ' ' + label).toLowerCase();
        for (var i = 0; i < RULES.length; i++) {
            if (RULES[i][0].test(text)) return {icon: RULES[i][1], color: RULES[i][2]};
        }
        var hash = 0;
        for (var j = 0; j < text.length; j++) hash = (hash * 31 + text.charCodeAt(j)) >>> 0;
        var icon = /^(fas|far|fab) fa-[a-z0-9-]+$/.test(menuIcon || '') && !/fa-circle$/.test(menuIcon) ? menuIcon : 'fas fa-th-large';
        return {icon: icon, color: PALETTE[hash % PALETTE.length]};
    };

    var catalog = [];
    var catalogByPath = {};
    sidebar.querySelectorAll('a.nav-link[href]').forEach(function (link) {
        var href = link.getAttribute('href') || '';
        if (href === '#' || /^javascript:/i.test(href)) return;
        var path = relPath(link.href);
        if (!path || catalogByPath[path]) return;
        var label = ownText(link.querySelector('p')) || link.textContent.replace(/\s+/g, ' ').trim();
        if (!label) return;
        var tree = link.closest('ul.nav-treeview');
        var parentLink = tree && tree.parentElement ? tree.parentElement.querySelector(':scope > a.nav-link > p') : null;
        var group = parentLink ? ownText(parentLink) : 'Main';
        var iconEl = link.querySelector('i.nav-icon');
        var menuIcon = iconEl ? iconEl.className.replace('nav-icon', '').trim() : '';
        var look = defaultLook(path, label, menuIcon);
        var item = {path: path, label: label, icon: look.icon, color: look.color, group: group || 'Main'};
        catalog.push(item);
        catalogByPath[path] = item;
    });
    // "Add new" pages that the menu reaches only through their list page: offered
    // when the list page is in the menu (same module, same permission). The server
    // may send its own list (config.extras) when staff cannot open some of them.
    (Array.isArray(config.extras) ? config.extras : [
        ['products/index.php', 'products/create.php', 'Add Product'],
        ['purchases/index.php', 'purchases/create.php', 'New Purchase'],
        ['expenses/index.php', 'expenses/create.php', 'Add Expense'],
        ['moneyin/index.php', 'moneyin/create.php', 'Add Money In'],
        ['customers/index.php', 'customers/create.php', 'Add ' + (config.customerLabel || 'Customer')],
        ['suppliers/index.php', 'suppliers/create.php', 'Add ' + (config.supplierLabel || 'Supplier')]
    ]).forEach(function (extra) {
        var list = catalogByPath[extra[0]];
        if (!list || catalogByPath[extra[1]]) return;
        var look = defaultLook(extra[1], extra[2], '');
        var item = {path: extra[1], label: extra[2], icon: look.icon, color: look.color, group: 'Quick Actions'};
        catalog.push(item);
        catalogByPath[extra[1]] = item;
    });
    if (!catalog.length) return;

    // A shortcut of a menu item matches the same page even when its query string differs.
    var findOption = function (path) {
        if (catalogByPath[path]) return catalogByPath[path];
        return null;
    };

    var DEFAULTS = [
        'sales/create_invoice.php', 'create_invoice/index.php', 'travel/booking_create.php',
        'sales/service_jobs.php', 'sales/sales_return.php', 'purchases/create.php',
        'purchases/purchase_return.php', 'products/create.php', 'expenses/index.php',
        'travel/receive_payment.php', 'sales/receive_payment.php', 'suppliers/supplier_payment.php',
        'transactions/index.php', 'sales/warranty_claims.php', 'warehouse/live_report.php',
        'moneyin/index.php', 'sales/invoice_list.php', 'create_invoice/invoice_list.php',
        'travel/bookings.php', 'travel/visa_tracker.php', 'customers/index.php', 'products/index.php'
    ];
    var defaultItems = function () {
        var list = [];
        DEFAULTS.forEach(function (path) {
            var option = findOption(path);
            if (option && list.length < 12) list.push(copyItem(option));
        });
        if (list.length < 4) {
            catalog.forEach(function (option) {
                if (list.length < 8 && !list.some(function (x) { return x.path === option.path; })) list.push(copyItem(option));
            });
        }
        return list;
    };
    var copyItem = function (item) {
        return {path: item.path, label: item.label, icon: item.icon, color: item.color};
    };

    // Saved tiles that are not in the menu right now (another branch, a module
    // switched off) stay saved but are not shown.
    var items = [];
    var unavailable = [];
    if (Array.isArray(config.saved)) {
        config.saved.forEach(function (saved) {
            var option = findOption(saved.path);
            if (option) items.push({path: saved.path, label: option.label, icon: saved.icon || option.icon, color: saved.color || option.color});
            else unavailable.push(saved);
        });
    } else {
        items = defaultItems();
    }

    // ---------- Markup ----------
    var esc = function (value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    };
    var url = function (path) { return (root || '') + '/' + path; };

    var handle = document.createElement('button');
    handle.type = 'button';
    handle.className = 'qp-handle';
    handle.title = 'Quick Panel (Alt+Q)';
    handle.setAttribute('aria-label', 'Open Quick Panel');
    handle.innerHTML = '<i class="fas fa-bolt"></i><span>Quick</span>';

    var panel = document.createElement('aside');
    panel.className = 'qp-panel';
    panel.setAttribute('aria-label', 'Quick Panel');
    panel.innerHTML =
        '<div class="qp-head">' +
            '<div class="qp-title"><i class="fas fa-bolt"></i>Quick Panel</div>' +
            '<button type="button" class="qp-icon-btn qp-pin-btn" title="Keep open (pin)" aria-label="Pin"><i class="fas fa-thumbtack"></i></button>' +
            '<button type="button" class="qp-icon-btn qp-edit-btn" title="Customise: add, remove or reorder options" aria-label="Customise"><i class="fas fa-pen"></i></button>' +
            '<button type="button" class="qp-icon-btn qp-close-btn" title="Close (Esc)" aria-label="Close"><i class="fas fa-times"></i></button>' +
        '</div>' +
        '<div class="qp-search">' +
            '<i class="fas fa-search"></i>' +
            '<input type="search" autocomplete="off" spellcheck="false" placeholder="' + esc(config.placeholder || 'Invoice no, product code or serial') + '">' +
            '<button type="button" class="qp-clear" aria-label="Clear search"><i class="fas fa-times"></i></button>' +
        '</div>' +
        '<div class="qp-body">' +
            '<div class="qp-results" role="listbox"></div>' +
            '<div class="qp-grid"></div>' +
            '<div class="qp-library">' +
                '<div class="qp-remove-msg"><i class="fas fa-trash-alt mr-1"></i> Drop here to remove</div>' +
                '<div class="qp-library-inner">' +
                    '<div class="qp-library-head"><strong><i class="fas fa-th mr-1"></i> All options</strong><input type="search" class="qp-filter" placeholder="Find option"></div>' +
                    '<div class="qp-hint">Drag an option onto the panel to <b>add</b> it (or click it). Drag tiles to <b>reorder</b>. Drag a tile here (or press its &times;) to <b>remove</b> it.</div>' +
                    '<div class="qp-option-list"></div>' +
                '</div>' +
            '</div>' +
            '<div class="qp-status"></div>' +
        '</div>' +
        '<div class="qp-foot">' +
            '<button type="button" class="btn btn-sm btn-outline-light qp-reset-btn"><i class="fas fa-undo mr-1"></i> Reset default</button>' +
            '<button type="button" class="btn btn-sm btn-warning qp-done-btn"><i class="fas fa-check mr-1"></i> Done</button>' +
        '</div>';

    document.body.appendChild(handle);
    document.body.appendChild(panel);

    var $ = function (selector) { return panel.querySelector(selector); };
    var grid = $('.qp-grid');
    var library = $('.qp-library');
    var optionList = $('.qp-option-list');
    var filterInput = $('.qp-filter');
    var searchBox = $('.qp-search');
    var searchInput = $('.qp-search input');
    var resultsBox = $('.qp-results');
    var statusBox = $('.qp-status');
    var body = document.body;

    // Navbar button next to the notification bell.
    var navList = document.querySelector('.main-header .navbar-nav.ml-auto');
    if (navList) {
        var navItem = document.createElement('li');
        navItem.className = 'nav-item qp-nav-item';
        navItem.innerHTML = '<a class="nav-link" href="#" role="button" title="Quick Panel (Alt+Q)" aria-label="Quick Panel"><i class="fas fa-th"></i></a>';
        var bell = navList.querySelector(':scope > li.dropdown');
        navList.insertBefore(navItem, bell || null);
        navItem.querySelector('a').addEventListener('click', function (event) {
            event.preventDefault();
            toggle(true);
        });
    }

    // ---------- Open / auto-hide ----------
    var editing = false;
    var dragging = null;
    var pinned = false;
    try { pinned = localStorage.getItem(storageKey) === '1'; } catch (e) { pinned = false; }
    var hideTimer = null;
    var hoverTimer = null;
    var isOpen = function () { return body.classList.contains('qp-open'); };
    var open = function (focusSearch) {
        clearTimeout(hideTimer);
        body.classList.add('qp-open');
        if (focusSearch) setTimeout(function () { searchInput.focus(); searchInput.select(); }, 60);
    };
    var close = function () {
        if (editing) setEditing(false);
        body.classList.remove('qp-open');
    };
    var toggle = function (focusSearch) { isOpen() ? close() : open(focusSearch); };
    // Stay open while pinned, customising, dragging or while a search is in use.
    var mayAutoHide = function () {
        return !pinned && !editing && !dragging && document.activeElement !== searchInput && searchInput.value.trim() === '';
    };
    var scheduleHide = function () {
        clearTimeout(hideTimer);
        hideTimer = setTimeout(function () { if (mayAutoHide()) close(); }, 650);
    };
    var setPinned = function (value) {
        pinned = value;
        body.classList.toggle('qp-pinned', pinned);
        $('.qp-pin-btn').title = pinned ? 'Unpin (auto-hide)' : 'Keep open (pin)';
        try { localStorage.setItem(storageKey, pinned ? '1' : '0'); } catch (e) { /* private mode */ }
    };
    setPinned(pinned);
    if (pinned) open(false);

    handle.addEventListener('mouseenter', function () {
        clearTimeout(hoverTimer);
        hoverTimer = setTimeout(function () { open(false); }, 180);
    });
    handle.addEventListener('mouseleave', function () { clearTimeout(hoverTimer); });
    handle.addEventListener('click', function () { open(true); });
    panel.addEventListener('mouseenter', function () { clearTimeout(hideTimer); });
    panel.addEventListener('mouseleave', function () { if (isOpen()) scheduleHide(); });
    searchInput.addEventListener('blur', function () {
        setTimeout(function () { if (isOpen() && !panel.matches(':hover')) scheduleHide(); }, 150);
    });
    document.addEventListener('mousedown', function (event) {
        if (!isOpen() || pinned || panel.contains(event.target) || handle.contains(event.target) || event.target.closest('.qp-nav-item')) return;
        close();
    });
    document.addEventListener('keydown', function (event) {
        if (event.altKey && !event.ctrlKey && !event.metaKey && (event.key === 'q' || event.key === 'Q')) {
            event.preventDefault();
            if (isOpen() && document.activeElement === searchInput) close();
            else open(true);
        } else if (event.key === 'Escape' && isOpen()) {
            if (searchInput.value) { clearSearch(); }
            else if (editing) { setEditing(false); }
            else { close(); }
        }
    });
    $('.qp-close-btn').addEventListener('click', function () { setPinned(false); close(); });
    $('.qp-pin-btn').addEventListener('click', function () { setPinned(!pinned); });

    // ---------- Tiles ----------
    var renderGrid = function () {
        grid.innerHTML = '';
        if (!items.length) {
            grid.innerHTML = '<div class="qp-empty">' + (editing ? 'Drag options here from the list below.' : 'No option added. Press <i class="fas fa-pen"></i> to add options.') + '</div>';
        }
        items.forEach(function (item, index) {
            var tile = document.createElement('a');
            tile.className = 'qp-tile';
            tile.href = url(item.path);
            tile.title = item.label;
            tile.draggable = editing;
            tile.dataset.index = index;
            tile.innerHTML = '<span class="qp-tile-icon" style="background:' + esc(item.color) + '"><i class="' + esc(item.icon) + '"></i></span>' +
                '<span class="qp-tile-label">' + esc(item.label) + '</span>' +
                '<button type="button" class="qp-tile-remove" title="Remove" aria-label="Remove ' + esc(item.label) + '"><i class="fas fa-times"></i></button>';
            grid.appendChild(tile);
        });
        renderOptions();
    };

    var renderOptions = function () {
        if (!editing) return;
        var needle = filterInput.value.trim().toLowerCase();
        var added = {};
        items.forEach(function (item) { added[item.path] = true; });
        var groups = [];
        var byGroup = {};
        catalog.forEach(function (option) {
            if (needle && (option.label + ' ' + option.group).toLowerCase().indexOf(needle) === -1) return;
            if (!byGroup[option.group]) { byGroup[option.group] = []; groups.push(option.group); }
            byGroup[option.group].push(option);
        });
        optionList.innerHTML = groups.length ? '' : '<div class="qp-hint">No option found.</div>';
        groups.forEach(function (group) {
            var title = document.createElement('div');
            title.className = 'qp-group';
            title.textContent = group;
            var wrap = document.createElement('div');
            wrap.className = 'qp-options';
            byGroup[group].forEach(function (option) {
                var isAdded = !!added[option.path];
                var chip = document.createElement('button');
                chip.type = 'button';
                chip.className = 'qp-option' + (isAdded ? ' qp-added' : '');
                chip.draggable = !isAdded;
                chip.dataset.path = option.path;
                chip.title = isAdded ? 'Already on the panel' : 'Drag onto the panel or click to add';
                chip.innerHTML = '<span class="qp-option-icon" style="background:' + esc(option.color) + '"><i class="' + esc(option.icon) + '"></i></span>' +
                    '<span>' + esc(option.label) + '</span><i class="fas ' + (isAdded ? 'fa-check' : 'fa-plus') + ' qp-option-add"></i>';
                wrap.appendChild(chip);
            });
            optionList.appendChild(title);
            optionList.appendChild(wrap);
        });
    };

    var setEditing = function (value) {
        editing = value;
        panel.classList.toggle('qp-editing', editing);
        $('.qp-edit-btn').classList.toggle('active', editing);
        if (editing) { clearSearch(); filterInput.value = ''; }
        renderGrid();
    };
    $('.qp-edit-btn').addEventListener('click', function () { open(false); setEditing(!editing); });
    $('.qp-done-btn').addEventListener('click', function () { setEditing(false); });
    filterInput.addEventListener('input', renderOptions);

    var addOption = function (path, index) {
        var option = findOption(path);
        if (!option || items.some(function (x) { return x.path === path; })) return;
        if (items.length + unavailable.length >= MAX_ITEMS) { flash('You can keep up to ' + MAX_ITEMS + ' options.'); return; }
        var at = typeof index === 'number' ? Math.max(0, Math.min(index, items.length)) : items.length;
        items.splice(at, 0, copyItem(option));
        renderGrid();
        save();
    };
    var removeAt = function (index) {
        items.splice(index, 1);
        renderGrid();
        save();
    };

    grid.addEventListener('click', function (event) {
        var remove = event.target.closest('.qp-tile-remove');
        var tile = event.target.closest('.qp-tile');
        if (remove && tile) {
            event.preventDefault();
            event.stopPropagation();
            removeAt(+tile.dataset.index);
        } else if (tile && editing) {
            event.preventDefault(); // While customising a tile is not opened.
        }
    });
    optionList.addEventListener('click', function (event) {
        var chip = event.target.closest('.qp-option');
        if (chip && !chip.classList.contains('qp-added')) addOption(chip.dataset.path);
    });

    // ---------- Drag and drop ----------
    var placeholder = document.createElement('div');
    placeholder.className = 'qp-placeholder';
    var dropIndex = function (event) {
        var tiles = Array.prototype.filter.call(grid.children, function (el) { return el.classList.contains('qp-tile') && !el.classList.contains('qp-dragging'); });
        for (var i = 0; i < tiles.length; i++) {
            var box = tiles[i].getBoundingClientRect();
            var sameRow = event.clientY >= box.top && event.clientY <= box.bottom;
            if (event.clientY < box.top || (sameRow && event.clientX < box.left + box.width / 2)) return {index: +tiles[i].dataset.index, before: tiles[i]};
        }
        return {index: items.length, before: null};
    };
    var endDrag = function () {
        if (dragging && dragging.el) dragging.el.classList.remove('qp-dragging');
        dragging = null;
        placeholder.remove();
        grid.classList.remove('qp-drop-active');
        library.classList.remove('qp-remove-zone');
    };

    panel.addEventListener('dragstart', function (event) {
        if (!editing) return;
        var tile = event.target.closest && event.target.closest('.qp-tile');
        var chip = event.target.closest && event.target.closest('.qp-option');
        if (tile) {
            dragging = {from: 'grid', index: +tile.dataset.index, el: tile};
            setTimeout(function () { tile.classList.add('qp-dragging'); library.classList.add('qp-remove-zone'); }, 0);
        } else if (chip && !chip.classList.contains('qp-added')) {
            dragging = {from: 'library', path: chip.dataset.path, el: null};
        } else {
            return;
        }
        event.dataTransfer.effectAllowed = 'move';
        try { event.dataTransfer.setData('text/plain', 'quick-panel'); } catch (e) { /* IE */ }
    });
    panel.addEventListener('dragend', endDrag);

    grid.addEventListener('dragover', function (event) {
        if (!dragging) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        grid.classList.add('qp-drop-active');
        var target = dropIndex(event);
        if (target.before) grid.insertBefore(placeholder, target.before);
        else grid.appendChild(placeholder);
        var empty = grid.querySelector('.qp-empty');
        if (empty) empty.remove();
    });
    grid.addEventListener('dragleave', function (event) {
        if (!grid.contains(event.relatedTarget)) { placeholder.remove(); grid.classList.remove('qp-drop-active'); }
    });
    grid.addEventListener('drop', function (event) {
        if (!dragging) return;
        event.preventDefault();
        var target = dropIndex(event).index;
        var drag = dragging;
        endDrag();
        if (drag.from === 'grid') {
            var moved = items.splice(drag.index, 1)[0];
            if (target > drag.index) target--;
            items.splice(target, 0, moved);
            renderGrid();
            save();
        } else {
            addOption(drag.path, target);
        }
    });
    library.addEventListener('dragover', function (event) {
        if (!dragging || dragging.from !== 'grid') return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        placeholder.remove();
        library.classList.add('qp-remove-zone');
    });
    library.addEventListener('drop', function (event) {
        if (!dragging || dragging.from !== 'grid') return;
        event.preventDefault();
        var index = dragging.index;
        endDrag();
        removeAt(index);
    });

    // ---------- Saving ----------
    var saveTimer = null;
    var flash = function (message, isError) {
        statusBox.textContent = message;
        statusBox.style.color = isError ? '#ffa8a8' : '';
        clearTimeout(flash.timer);
        flash.timer = setTimeout(function () { statusBox.textContent = ''; }, 2500);
    };
    var post = function (data) {
        var form = new FormData();
        form.append('token', config.token || '');
        Object.keys(data).forEach(function (key) { form.append(key, data[key]); });
        return fetch(config.api, {method: 'POST', body: form, credentials: 'same-origin'}).then(function (response) {
            return response.json().then(function (json) {
                if (!response.ok || !json.ok) throw new Error(json.error || 'Could not save');
                return json;
            });
        });
    };
    var save = function () {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(function () {
            var list = items.map(copyItem).concat(unavailable);
            post({action: 'save', items: JSON.stringify(list)})
                .then(function () { flash('Saved'); })
                .catch(function (error) { flash(error.message, true); });
        }, 350);
    };
    $('.qp-reset-btn').addEventListener('click', function () {
        if (!confirm('Reset the Quick Panel to its default options?')) return;
        post({action: 'reset'}).then(function () {
            unavailable = [];
            items = defaultItems();
            renderGrid();
            flash('Default options restored');
        }).catch(function (error) { flash(error.message, true); });
    });

    // ---------- Instant search ----------
    var searchTimer = null;
    var controller = null;
    var cache = {};
    var activeIndex = -1;
    var lastQuery = '';
    var openOnResult = false; // Enter pressed (or a barcode scanned) before the results arrived.
    var highlight = function (text, query) {
        var safe = esc(text);
        var i = String(text).toLowerCase().indexOf(query.toLowerCase());
        if (i === -1 || !query) return safe;
        return esc(text.slice(0, i)) + '<mark>' + esc(text.slice(i, i + query.length)) + '</mark>' + esc(text.slice(i + query.length));
    };
    var renderResults = function (results, query) {
        activeIndex = results.length ? 0 : -1;
        if (openOnResult) {
            openOnResult = false;
            if (results.length) { window.location.href = results[0].url; return; }
        }
        if (!results.length) {
            resultsBox.innerHTML = '<div class="qp-result-msg"><i class="fas fa-search mr-1"></i> Nothing found for &ldquo;' + esc(query) + '&rdquo;</div>';
            return;
        }
        resultsBox.innerHTML = results.map(function (row, index) {
            return '<a class="qp-result' + (index === 0 ? ' qp-active' : '') + '" role="option" href="' + esc(row.url) + '">' +
                '<span class="qp-result-icon" style="background:' + esc(row.color) + '"><i class="' + esc(row.icon) + '"></i></span>' +
                '<span class="qp-result-text"><div class="qp-result-title">' + highlight(row.title, query) + '</div>' +
                '<div class="qp-result-sub">' + esc(row.subtitle) + '</div></span>' +
                '<span class="qp-result-type">' + esc(row.type) + '</span></a>';
        }).join('');
    };
    var runSearch = function () {
        var query = searchInput.value.trim();
        lastQuery = query;
        searchBox.classList.toggle('has-text', searchInput.value !== '');
        if (query.length < 2) {
            panel.classList.toggle('qp-searching', query.length > 0);
            resultsBox.innerHTML = query.length ? '<div class="qp-result-msg">Type at least 2 characters</div>' : '';
            return;
        }
        panel.classList.add('qp-searching');
        if (cache[query]) { renderResults(cache[query], query); return; }
        resultsBox.innerHTML = '<div class="qp-result-msg"><i class="fas fa-spinner fa-spin mr-1"></i> Searching&hellip;</div>';
        if (controller) controller.abort();
        controller = window.AbortController ? new AbortController() : null;
        fetch(config.api + '?action=search&q=' + encodeURIComponent(query), {credentials: 'same-origin', signal: controller ? controller.signal : undefined})
            .then(function (response) { return response.json(); })
            .then(function (json) {
                var results = (json && json.results) || [];
                cache[query] = results;
                if (query === lastQuery) renderResults(results, query);
            })
            .catch(function (error) {
                if (error && error.name === 'AbortError') return;
                if (query === lastQuery) resultsBox.innerHTML = '<div class="qp-result-msg">Search failed. Please try again.</div>';
            });
    };
    var clearSearch = function () {
        searchInput.value = '';
        runSearch();
    };
    searchInput.addEventListener('input', function () {
        openOnResult = false;
        if (editing && searchInput.value) setEditing(false);
        clearTimeout(searchTimer);
        searchTimer = setTimeout(runSearch, 220);
    });
    $('.qp-clear').addEventListener('click', function () { clearSearch(); searchInput.focus(); });
    searchInput.addEventListener('keydown', function (event) {
        var rows = resultsBox.querySelectorAll('.qp-result');
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            if (!rows.length) return;
            event.preventDefault();
            activeIndex = (activeIndex + (event.key === 'ArrowDown' ? 1 : -1) + rows.length) % rows.length;
            rows.forEach(function (row, i) { row.classList.toggle('qp-active', i === activeIndex); });
            rows[activeIndex].scrollIntoView({block: 'nearest'});
        } else if (event.key === 'Enter') {
            event.preventDefault();
            clearTimeout(searchTimer);
            if (searchInput.value.trim() !== lastQuery || resultsBox.querySelector('.fa-spinner')) {
                openOnResult = searchInput.value.trim().length >= 2;
                runSearch();
                return;
            }
            var row = rows[activeIndex] || rows[0];
            if (row) window.location.href = row.href;
        }
    });

    renderGrid();
})();
