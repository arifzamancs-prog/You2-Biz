/*
 * Dashboard layout: drag summary cards between the top and bottom rows (a card takes the
 * shape of the row it is dropped in), drag panels by their header to reorder them, and
 * drag a panel's right edge / bottom edge / corner to change its width and height.
 * Needs jQuery UI (sortable + resizable). The layout is kept in this browser.
 *
 * Sidebar menu links can also be dropped on the header bar as shortcut buttons.
 *
 * The Edit button (placed after the Reset link) shows an x on every card, panel and
 * shortcut button; pressing it removes that item. Reset Layout brings cards and charts back.
 *
 * DashboardLayout.init({ storageKey: "...", resetLink: "#dashLayoutReset",
 *     shortcutBar: "#dashShortcutBar", shortcutList: "#dashShortcuts",
 *     defaultPanelOrder: ["first_panel", "second_panel"] })
 */
(function ($, window, document) {
    'use strict';

    var MIN_COLS = 3;
    var MIN_TARGET_HEIGHT = 120;

    function injectStyle() {
        if (document.getElementById('dashboard-layout-style')) return;
        var style = document.createElement('style');
        style.id = 'dashboard-layout-style';
        style.textContent =
            '.dash-card-row[data-row=top] .dash-card-bottom,.dash-card-row[data-row=bottom] .dash-card-top{display:none}' +
            '.dash-card-row{min-height:8px}' +
            '.dash-card{cursor:grab}.dash-card a{-webkit-user-drag:none}' +
            'body.dash-sorting .dash-card-row{min-height:96px;outline:2px dashed #cbd5e1;outline-offset:-4px;border-radius:6px;margin-bottom:12px}' +
            '.dash-card-placeholder>div{height:100%;border:2px dashed #94a3b8;border-radius:6px;background:rgba(148,163,184,.12)}' +
            '.dash-panel>.card>.card-header{cursor:grab}' +
            '.dash-panel-placeholder>div{height:100%;border:2px dashed #94a3b8;border-radius:6px;background:rgba(148,163,184,.12);margin-bottom:1rem}' +
            '.ui-sortable-helper{cursor:grabbing;opacity:.9;box-shadow:0 12px 28px rgba(0,0,0,.18)}' +
            '.dash-panel>.card.ui-resizable{position:relative}' +
            '.dash-panel .ui-resizable-handle{position:absolute;display:block;z-index:5;touch-action:none}' +
            '.dash-panel .ui-resizable-e{cursor:ew-resize;width:10px;right:-5px;top:0;height:100%}' +
            '.dash-panel .ui-resizable-s{cursor:ns-resize;height:10px;bottom:-5px;left:0;width:100%}' +
            '.dash-panel .ui-resizable-se{cursor:nwse-resize;width:16px;height:16px;right:2px;bottom:2px;' +
            'background:linear-gradient(135deg,transparent 50%,#94a3b8 50%,#94a3b8 60%,transparent 60%,transparent 70%,#94a3b8 70%,#94a3b8 80%,transparent 80%)}' +
            '.dash-panel>.card.ui-resizable-resizing{outline:2px dashed #2563eb;z-index:10}' +
            // No transition here: on page load it would fade a dark outline out, a visible flash.
            '#dashShortcutBar{border-radius:6px;outline:0 none;outline-offset:2px}' +
            'body.dash-shortcut-dragging #dashShortcutBar{outline:2px dashed #94a3b8}' +
            'body.dash-shortcut-dragging #dashShortcutBar{outline-color:#94a3b8;background:rgba(148,163,184,.08)}' +
            'body.dash-shortcut-dragging #dashShortcutBar.dash-shortcut-over{outline-color:#2563eb;background:rgba(37,99,235,.08)}' +
            '.dash-shortcut{cursor:grab;position:relative}.dash-shortcut.dash-shortcut-moving{opacity:.4}' +
            '.dash-shortcut-remove{display:none;position:absolute;top:-9px;right:-9px;z-index:5;width:20px;height:20px;border:2px solid #fff;border-radius:50%;' +
            'background:#e03131;color:#fff;font-size:10px;line-height:1;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 1px 4px rgba(0,0,0,.3)}' +
            '.dash-shortcut-remove:hover{background:#c92a2a}' +
            'body.dash-editing .dash-shortcut-remove{display:flex}' +
            'body.dash-editing .dash-shortcut{outline:2px dashed #94a3b8;outline-offset:2px}' +
            '.dash-hidden{display:none!important}' +
            '.dash-remove{display:none;position:absolute;top:-9px;right:0;z-index:20;width:26px;height:26px;padding:0;border:2px solid #fff;border-radius:50%;' +
            'background:#e03131;color:#fff;font-size:12px;line-height:1;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 2px 6px rgba(0,0,0,.3)}' +
            '.dash-remove:hover,.dash-remove:focus{background:#c92a2a;outline:none;transform:scale(1.1)}' +
            'body.dash-editing .dash-remove{display:flex}' +
            'body.dash-editing .dash-card>div>a>div,body.dash-editing .dash-card>div>div,body.dash-editing .dash-panel>.card{outline:2px dashed #94a3b8;outline-offset:2px}' +
            'body.dash-editing .dash-card a{cursor:grab}' +
            '#dashLayoutEdit{text-decoration:none}' +
            '#dashLayoutEdit.dash-edit-active{color:#fff!important;background:#2563eb;border-radius:4px}';
        document.head.appendChild(style);
    }

    function colsOf($panel) {
        var match = /(?:^|\s)col-lg-(\d+)(?:\s|$)/.exec($panel.attr('class') || '');
        return match ? parseInt(match[1], 10) : 12;
    }

    function setCols($panel, cols) {
        cols = Math.max(MIN_COLS, Math.min(12, cols));
        $panel.removeClass(function (index, className) {
            return (className.match(/(^|\s)col-lg-\d+/g) || []).join(' ');
        }).addClass('col-lg-' + cols);
    }

    function targetOf($panel) {
        return $panel.find('.dash-resize-target').first();
    }

    function setTargetHeight($target, height) {
        if (!$target.length || !height) return;
        $target.css('height', Math.max(MIN_TARGET_HEIGHT, Math.round(height)) + 'px');
        if ($target.hasClass('card-body')) $target.css('overflow-y', 'auto');
    }

    function read(key) {
        try { return JSON.parse(window.localStorage.getItem(key) || 'null'); } catch (e) { return null; }
    }

    function init(options) {
        if (!$.fn.sortable || !$.fn.resizable) return;
        injectStyle();

        var key = options.storageKey;
        var $cardRows = $('.dash-card-row');
        var $panels = $('#dashPanels');
        var defaultPanelOrder = Array.isArray(options.defaultPanelOrder)
            ? options.defaultPanelOrder
            : $panels.children('.dash-panel').map(function () { return $(this).data('panel'); }).get();

        // Establish the page's intended desktop layout before optionally restoring a
        // personal saved layout. Reset Layout therefore returns to this order.
        defaultPanelOrder.forEach(function (panelKey) {
            var $panel = $panels.children('.dash-panel[data-panel="' + panelKey + '"]');
            if ($panel.length) $panel.appendTo($panels);
        });
        var saved = read(key);

        // Cards and panels the user removed stay hidden until Reset Layout.
        if (saved && saved.hidden) {
            (saved.hidden.cards || []).forEach(function (cardKey) {
                $('.dash-card[data-card="' + cardKey + '"]').addClass('dash-hidden');
            });
            (saved.hidden.panels || []).forEach(function (panelKey) {
                $panels.children('.dash-panel[data-panel="' + panelKey + '"]').addClass('dash-hidden');
            });
        }

        // Restore a saved layout; cards or panels it does not know stay where they are.
        if (saved && saved.cards) {
            ['top', 'bottom'].forEach(function (row) {
                var $row = $cardRows.filter('[data-row=' + row + ']');
                (saved.cards[row] || []).forEach(function (cardKey) {
                    $('.dash-card[data-card="' + cardKey + '"]').appendTo($row);
                });
            });
        }
        if (saved && saved.panels) {
            var savedPanelKeys = [];
            saved.panels.forEach(function (panel) {
                var $panel = $panels.children('.dash-panel[data-panel="' + panel.key + '"]');
                if (!$panel.length) return;
                savedPanelKeys.push(panel.key);
                $panel.appendTo($panels);
                if (panel.cols) setCols($panel, panel.cols);
                if (panel.height) setTargetHeight(targetOf($panel), panel.height);
            });
            // Panels added after this layout was saved: right after the panel they follow on the page.
            defaultPanelOrder.forEach(function (panelKey, index) {
                if (savedPanelKeys.indexOf(panelKey) !== -1) return;
                var $panel = $panels.children('.dash-panel[data-panel="' + panelKey + '"]');
                for (var i = index - 1; i >= 0; i--) {
                    var $before = $panels.children('.dash-panel[data-panel="' + defaultPanelOrder[i] + '"]');
                    if ($before.length) { $panel.insertAfter($before); return; }
                }
                $panel.prependTo($panels);
            });
        }

        function save() {
            var layout = { cards: {}, panels: [], hidden: { cards: [], panels: [] } };
            $('.dash-card.dash-hidden').each(function () { layout.hidden.cards.push($(this).data('card')); });
            $panels.children('.dash-panel.dash-hidden').each(function () { layout.hidden.panels.push($(this).data('panel')); });
            $cardRows.each(function () {
                layout.cards[$(this).data('row')] = $(this).children('.dash-card').map(function () {
                    return $(this).data('card');
                }).get();
            });
            $panels.children('.dash-panel').each(function () {
                var $panel = $(this);
                var $target = targetOf($panel);
                layout.panels.push({
                    key: $panel.data('panel'),
                    cols: colsOf($panel),
                    height: $target.length && $target[0].style.height ? parseInt($target[0].style.height, 10) : null
                });
            });
            try { window.localStorage.setItem(key, JSON.stringify(layout)); } catch (e) { /* storage full or blocked */ }
        }

        $cardRows.find('a').attr('draggable', 'false');

        $cardRows.sortable({
            connectWith: '.dash-card-row',
            items: '> .dash-card:not(.dash-hidden)',
            distance: 8,
            tolerance: 'pointer',
            placeholder: 'col-lg-3 col-6 dash-card-placeholder',
            start: function (event, ui) {
                $('body').addClass('dash-sorting');
                ui.placeholder.height(ui.item.outerHeight()).html('<div></div>');
            },
            stop: function () {
                $('body').removeClass('dash-sorting');
                save();
            }
        });

        $panels.sortable({
            items: '> .dash-panel:not(.dash-hidden)',
            handle: '.card-header',
            cancel: 'button, a, input, select, label, .btn, .badge',
            distance: 8,
            tolerance: 'pointer',
            placeholder: 'dash-panel-placeholder',
            start: function (event, ui) {
                ui.placeholder.addClass('col-lg-' + colsOf(ui.item)).height(ui.item.outerHeight()).html('<div></div>');
            },
            stop: save
        });

        $panels.children('.dash-panel').each(function () {
            var $panel = $(this);
            var $card = $panel.children('.card');
            var $target = targetOf($panel);
            var startTargetHeight = 0;

            $card.resizable({
                handles: 'e, s, se',
                minHeight: 150,
                start: function () {
                    startTargetHeight = $target.length ? $target.outerHeight() : 0;
                    $card.resizable('option', 'minWidth', $panels.width() * MIN_COLS / 12 - 30);
                    $card.resizable('option', 'maxWidth', $panels.width() - 30);
                },
                resize: function (event, ui) {
                    // Height goes to the chart / table area so the card grows with it.
                    if ($target.length) {
                        setTargetHeight($target, startTargetHeight + ui.size.height - ui.originalSize.height);
                        $card.css('height', '');
                    }
                },
                stop: function (event, ui) {
                    // Width snaps to the nearest grid column (3 to 12 of 12).
                    var unit = $panels.width() / 12;
                    setCols($panel, Math.round((ui.size.width + 30) / unit));
                    $card.css({ width: '', height: '', left: '', top: '' });
                    save();
                }
            });
        });

        initEditing($(options.resetLink), $cardRows, $panels, save);

        if (options.shortcutBar) initShortcuts(key + '.shortcuts', $(options.shortcutBar), $(options.shortcutList));

        $(options.resetLink).on('click', function (event) {
            event.preventDefault();
            try { window.localStorage.removeItem(key); } catch (e) { /* ignore */ }
            window.location.reload();
        });
    }

    /*
     * Edit mode: an x on every card and panel removes it from the dashboard.
     */
    function initEditing($reset, $cardRows, $panels, save) {
        if (!$reset.length) return;

        var $edit = $('<a href="#" id="dashLayoutEdit" class="btn btn-link btn-sm text-muted mr-1 agent-dashboard-clickable" role="button"></a>');
        $edit.insertAfter($reset);

        function hiddenCount() {
            return $('.dash-card.dash-hidden').length + $panels.children('.dash-panel.dash-hidden').length;
        }

        function editing() {
            return document.body.classList.contains('dash-editing');
        }

        function paint() {
            var on = editing();
            var hidden = hiddenCount();
            $edit.toggleClass('dash-edit-active', on)
                .html(on ? '<i class="fas fa-check mr-1"></i>Done' : '<i class="fas fa-pen"></i>')
                .attr('title', on
                    ? 'Finish editing'
                    : 'Edit dashboard: remove cards and charts' + (hidden ? ' (' + hidden + ' removed; Reset Layout brings them back)' : ''))
                .attr('aria-label', on ? 'Finish editing the dashboard' : 'Edit dashboard');
        }

        function setEditing(on) {
            document.body.classList.toggle('dash-editing', on);
            paint();
        }

        function removeButton(title) {
            return $('<button type="button" class="dash-remove"><i class="fas fa-times"></i></button>')
                .attr('title', 'Remove ' + title)
                .attr('aria-label', 'Remove ' + title);
        }

        $cardRows.children('.dash-card').each(function () {
            var $card = $(this);
            var title = $.trim($card.find('.info-box-text').first().text()) || 'card';
            $card.append(removeButton(title));
        });
        $panels.children('.dash-panel').each(function () {
            var $panel = $(this);
            var title = $.trim($panel.find('.card-title').first().text()) || 'chart';
            $panel.children('.card').first().before(removeButton(title));
        });

        $(document).on('click', '.dash-remove', function (event) {
            event.preventDefault();
            event.stopPropagation();
            var $item = $(this).closest('.dash-card, .dash-panel');
            $item.addClass('dash-hidden');
            save();
            paint();
            // Charts in the remaining panels redraw for their new width.
            window.dispatchEvent(new Event('resize'));
        });

        // While editing, a card is not opened by a click.
        $(document).on('click', '.dash-card a', function (event) {
            if (editing()) event.preventDefault();
        });

        $edit.on('click', function (event) {
            event.preventDefault();
            setEditing(!editing());
        });

        $(document).on('keydown', function (event) {
            if (event.key === 'Escape' && editing()) setEditing(false);
        });

        paint();
    }

    /*
     * Shortcut buttons: drag a sidebar menu link onto the bar to add it as a button,
     * drag a button along the bar to reorder it, drag it out of the bar to remove it.
     */
    function initShortcuts(key, $bar, $list) {
        if (!$bar.length || !$list.length) return;

        // Path of a link, to compare links written differently. Case is ignored: the
        // browser may show /you2-wallet/ while the menu links to /You2-wallet/.
        function pathOf(href) {
            try { var url = new URL(href, window.location.href); return (url.pathname + url.search).toLowerCase(); } catch (e) { return ''; }
        }

        // Buttons already on the bar (such as Create Invoice) are not added again.
        function fixedOnBar(href) {
            var path = pathOf(href);
            return $bar.find('a[href]').not('.dash-shortcut').filter(function () {
                return pathOf(this.getAttribute('href')) === path;
            }).length > 0;
        }

        var shortcuts = (read(key) || []).filter(function (shortcut) { return !fixedOnBar(shortcut.href); });
        var fromSidebar = null;   // { href, label, icon } of a sidebar link being dragged
        var moving = null;        // shortcut button being dragged
        var droppedInBar = false;

        function store() {
            try { window.localStorage.setItem(key, JSON.stringify(shortcuts)); } catch (e) { /* ignore */ }
        }

        function render() {
            $list.empty();
            shortcuts.forEach(function (shortcut, index) {
                var $button = $('<a draggable="true" class="btn btn-outline-secondary btn-sm mr-1 mb-1 dash-shortcut agent-dashboard-clickable"></a>')
                    .attr('href', shortcut.href)
                    .attr('title', 'Drag out of this line to remove')
                    .attr('data-index', index);
                $('<i class="mr-1"></i>').addClass(shortcut.icon || 'fas fa-link').appendTo($button);
                $('<span></span>').text(shortcut.label).appendTo($button);
                // Shown in Edit mode: removes the button (dragging it out of the line still works too).
                $('<span class="dash-shortcut-remove" role="button"><i class="fas fa-times"></i></span>')
                    .attr('title', 'Remove ' + shortcut.label)
                    .attr('aria-label', 'Remove ' + shortcut.label)
                    .appendTo($button);
                $list.append($button);
            });
        }

        function sidebarShortcut(link) {
            var raw = link.getAttribute('href') || '';
            if (raw === '' || /^#|^javascript:/i.test(raw)) return null;
            var url;
            try { url = new URL(raw, window.location.href); } catch (e) { return null; }
            if (url.origin !== window.location.origin) return null;

            var $p = $(link).children('p').first().clone();
            $p.find('.badge, .right, i').remove();
            var label = $.trim($p.text() || $(link).text());
            if (!label) return null;

            // Sub-menu items use a plain circle; borrow the parent menu's icon instead.
            var $icon = $(link).children('.nav-icon').first();
            if (!$icon.length || $icon.hasClass('fa-circle')) {
                var $parentIcon = $(link).closest('.nav-treeview').closest('.nav-item').children('.nav-link').children('.nav-icon').first();
                if ($parentIcon.length) $icon = $parentIcon;
            }
            var icon = $.trim(($icon.attr('class') || '').replace(/\bnav-icon\b/, '')) || 'fas fa-link';

            return { href: url.pathname + url.search, label: label, icon: icon };
        }

        // Position the dropped button takes, from the pointer position on the bar.
        function dropIndex(clientX, clientY) {
            var index = shortcuts.length;
            $list.children('.dash-shortcut').each(function (i) {
                var rect = this.getBoundingClientRect();
                if (clientY < rect.bottom && clientX < rect.left + rect.width / 2) { index = i; return false; }
            });
            return index;
        }

        document.addEventListener('dragstart', function (event) {
            var link = event.target.closest && event.target.closest('.main-sidebar a.nav-link');
            if (!link) return;
            fromSidebar = sidebarShortcut(link);
            if (fromSidebar) document.body.classList.add('dash-shortcut-dragging');
        });

        // On the document: a button dropped back on the bar is re-rendered (detached) before
        // its dragend fires, so the event would not bubble through the bar any more.
        document.addEventListener('dragend', function () {
            if (!fromSidebar && !moving) return;
            if (moving && !droppedInBar) {
                var index = parseInt(moving.getAttribute('data-index'), 10);
                if (!isNaN(index)) {
                    shortcuts.splice(index, 1);   // dropped outside the bar: remove it
                    store();
                }
                render();
            }
            fromSidebar = null;
            moving = null;
            document.body.classList.remove('dash-shortcut-dragging');
            $bar.removeClass('dash-shortcut-over');
        });

        $list.on('click', '.dash-shortcut', function (event) {
            var remove = event.target.closest && event.target.closest('.dash-shortcut-remove');
            if (!remove && !document.body.classList.contains('dash-editing')) return;
            event.preventDefault();   // while editing a button is not opened
            if (!remove) return;
            var index = parseInt(this.getAttribute('data-index'), 10);
            if (!isNaN(index)) {
                shortcuts.splice(index, 1);
                store();
                render();
            }
        });

        $list.on('dragstart', '.dash-shortcut', function (event) {
            moving = this;
            droppedInBar = false;
            event.originalEvent.dataTransfer.effectAllowed = 'move';
            event.originalEvent.dataTransfer.setData('text/plain', this.getAttribute('href'));
            $(this).addClass('dash-shortcut-moving');
            document.body.classList.add('dash-shortcut-dragging');
        });

        $bar.on('dragover', function (event) {
            if (!fromSidebar && !moving) return;
            event.preventDefault();
            event.originalEvent.dataTransfer.dropEffect = moving ? 'move' : 'copy';
            $bar.addClass('dash-shortcut-over');
        });

        $bar.on('dragleave', function (event) {
            if (!$bar[0].contains(event.originalEvent.relatedTarget)) $bar.removeClass('dash-shortcut-over');
        });

        $bar.on('drop', function (event) {
            if (!fromSidebar && !moving) return;
            event.preventDefault();
            $bar.removeClass('dash-shortcut-over');
            var target = dropIndex(event.originalEvent.clientX, event.originalEvent.clientY);

            if (moving) {
                droppedInBar = true;
                var from = parseInt(moving.getAttribute('data-index'), 10);
                var item = shortcuts.splice(from, 1)[0];
                shortcuts.splice(target > from ? target - 1 : target, 0, item);
            } else {
                var exists = fixedOnBar(fromSidebar.href) || shortcuts.some(function (shortcut) { return pathOf(shortcut.href) === pathOf(fromSidebar.href); });
                if (!exists) shortcuts.splice(target, 0, fromSidebar);
            }
            store();
            render();
        });

        store();   // drops saved copies of buttons the bar already has
        render();
    }

    window.DashboardLayout = { init: init };
})(jQuery, window, document);
