/*
 * Dashboard layout: drag summary cards between the top and bottom rows (a card takes the
 * shape of the row it is dropped in), drag panels by their header to reorder them, and
 * drag a panel's right edge / bottom edge / corner to change its width and height.
 * Needs jQuery UI (sortable + resizable). The layout is kept in this browser.
 *
 * Sidebar menu links can also be dropped on the header bar as shortcut buttons.
 *
 * DashboardLayout.init({ storageKey: "...", resetLink: "#dashLayoutReset",
 *     shortcutBar: "#dashShortcutBar", shortcutList: "#dashShortcuts" })
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
            '.dash-shortcut{cursor:grab}.dash-shortcut.dash-shortcut-moving{opacity:.4}';
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
        var saved = read(key);

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
            saved.panels.forEach(function (panel) {
                var $panel = $panels.children('.dash-panel[data-panel="' + panel.key + '"]');
                if (!$panel.length) return;
                $panel.appendTo($panels);
                if (panel.cols) setCols($panel, panel.cols);
                if (panel.height) setTargetHeight(targetOf($panel), panel.height);
            });
        }

        function save() {
            var layout = { cards: {}, panels: [] };
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
            items: '> .dash-card',
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
            items: '> .dash-panel',
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

        if (options.shortcutBar) initShortcuts(key + '.shortcuts', $(options.shortcutBar), $(options.shortcutList));

        $(options.resetLink).on('click', function (event) {
            event.preventDefault();
            try { window.localStorage.removeItem(key); } catch (e) { /* ignore */ }
            window.location.reload();
        });
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
