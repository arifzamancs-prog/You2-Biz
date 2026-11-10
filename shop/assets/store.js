// Progressive enhancement only: every page works without this script.
(function () {
    'use strict';
    var $ = function (selector, root) { return (root || document).querySelector(selector); };
    var $$ = function (selector, root) { return Array.prototype.slice.call((root || document).querySelectorAll(selector)); };

    // Mobile menu drawer
    var drawer = $('[data-drawer]');
    $$('[data-menu-open]').forEach(function (button) {
        button.addEventListener('click', function () { if (drawer) { drawer.hidden = false; document.body.style.overflow = 'hidden'; } });
    });
    $$('[data-menu-close]').forEach(function (button) {
        button.addEventListener('click', function () { drawer.hidden = true; document.body.style.overflow = ''; });
    });

    // Header search panel
    var searchBox = $('[data-search]');
    var searchPanel = $('[data-search-panel]');
    $$('[data-search-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            searchPanel.hidden = !searchPanel.hidden;
            button.setAttribute('aria-expanded', String(!searchPanel.hidden));
            if (!searchPanel.hidden) $('[data-search-input]', searchBox).focus();
        });
    });

    // Side cart drawer
    var sideCart = $('[data-side-cart]');
    var setCart = function (open) {
        if (!sideCart) return;
        sideCart.hidden = !open;
        document.body.style.overflow = open ? 'hidden' : '';
    };
    $$('[data-cart-open]').forEach(function (link) {
        link.addEventListener('click', function (event) { if (sideCart) { event.preventDefault(); setCart(true); } });
    });
    $$('[data-cart-close]').forEach(function (button) { button.addEventListener('click', function () { setCart(false); }); });
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        setCart(false);
        if (searchPanel) searchPanel.hidden = true;
    });
    if (document.body.hasAttribute('data-open-cart')) setCart(true);

    // Tabs: collection filter pills and product detail tabs
    $$('[role="tablist"]').forEach(function (list) {
        var scope = list.parentElement;
        var tabs = $$('[data-tab]', list);
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                tabs.forEach(function (other) {
                    var active = other === tab;
                    other.classList.toggle('is-active', active);
                    other.setAttribute('aria-selected', String(active));
                });
                $$('[data-tab-pane]', scope).forEach(function (pane) { pane.hidden = pane.dataset.tabPane !== tab.dataset.tab; });
            });
        });
    });

    // Live search suggestions
    var input = $('[data-search-input]');
    var results = $('[data-search-results]');
    if (input && results && window.fetch) {
        var timer = null, lastQuery = '';
        var escape = function (text) { var div = document.createElement('div'); div.textContent = text; return div.innerHTML; };
        input.addEventListener('input', function () {
            clearTimeout(timer);
            var query = input.value.trim();
            if (query.length < 2) { results.hidden = true; return; }
            timer = setTimeout(function () {
                lastQuery = query;
                fetch(input.dataset.suggestUrl + '?q=' + encodeURIComponent(query), { headers: { Accept: 'application/json' } })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (query !== lastQuery) return;
                        results.innerHTML = data.items.length ? data.items.map(function (item) {
                            return '<a href="' + escape(item.url) + '">' + (item.image ? '<img src="' + escape(item.image) + '" alt="">' : '') + '<span>' + escape(item.name) + '</span><b>' + escape(item.price) + '</b></a>';
                        }).join('') : '<div class="empty">No products found</div>';
                        results.hidden = false;
                    })
                    .catch(function () { results.hidden = true; });
            }, 250);
        });
        document.addEventListener('click', function (event) { if (!searchBox.contains(event.target)) results.hidden = true; });
        input.addEventListener('keydown', function (event) { if (event.key === 'Escape') results.hidden = true; });
    }

    // Banner slider
    $$('[data-slider]').forEach(function (slider) {
        var slides = $$('[data-slide]', slider), dots = $$('[data-slide-dot]', slider), current = 0, timer = null;
        if (slides.length < 2) return;
        var show = function (index) {
            current = (index + slides.length) % slides.length;
            slides.forEach(function (slide, i) { slide.classList.toggle('is-active', i === current); });
            dots.forEach(function (dot, i) { dot.classList.toggle('is-active', i === current); });
        };
        var start = function () { clearInterval(timer); timer = setInterval(function () { show(current + 1); }, 5000); };
        var prev = $('[data-slide-prev]', slider), next = $('[data-slide-next]', slider);
        if (prev) prev.addEventListener('click', function () { show(current - 1); start(); });
        if (next) next.addEventListener('click', function () { show(current + 1); start(); });
        dots.forEach(function (dot) { dot.addEventListener('click', function () { show(parseInt(dot.dataset.slideDot, 10)); start(); }); });
        slider.addEventListener('mouseenter', function () { clearInterval(timer); });
        slider.addEventListener('mouseleave', start);
        start();
    });

    // Product gallery
    $$('[data-gallery]').forEach(function (gallery) {
        var main = $('[data-gallery-main]', gallery);
        $$('[data-gallery-thumb]', gallery).forEach(function (thumb, index, all) {
            thumb.addEventListener('click', function () {
                main.src = thumb.dataset.galleryThumb;
                all.forEach(function (other) { other.classList.toggle('is-active', other === thumb); });
            });
        });
    });

    // Quantity steppers respect the input's min/max.
    $$('[data-qty]').forEach(function (box) {
        var field = $('[data-qty-input]', box);
        $$('[data-qty-step]', box).forEach(function (button) {
            button.addEventListener('click', function () {
                var min = parseInt(field.min || '1', 10), max = parseInt(field.max || '99', 10);
                var value = (parseInt(field.value, 10) || min) + parseInt(button.dataset.qtyStep, 10);
                field.value = Math.max(min, Math.min(max, value));
                field.dispatchEvent(new Event('input', { bubbles: true }));
            });
        });
    });

    // Cart page: totals follow quantity instantly; each change is saved in the background.
    var cartPage = $('[data-cart-page]');
    if (cartPage) {
        var money = function (amount) { return '৳' + Math.round(amount).toLocaleString('en-US'); };
        var delivery = parseFloat(cartPage.dataset.delivery || '0');
        var recalc = function () {
            var subtotal = 0, items = 0;
            $$('[data-cart-item]', cartPage).forEach(function (item) {
                var field = $('[data-qty-input]', item);
                var qty = Math.max(1, Math.min(parseInt(field.max || '99', 10), parseInt(field.value, 10) || 1));
                var line = qty * parseFloat(item.dataset.price || '0');
                $('[data-cart-line]', item).textContent = money(line);
                subtotal += line; items += qty;
            });
            $('[data-cart-subtotal]', cartPage).textContent = money(subtotal);
            $('[data-cart-total]', cartPage).textContent = money(subtotal + delivery);
            var countText = $('[data-cart-count-text]');
            if (countText) countText.textContent = items + ' item' + (items === 1 ? '' : 's');
            $$('[data-cart-open] .count').forEach(function (badge) { badge.textContent = items; });
        };
        $$('[data-cart-update]', cartPage).forEach(function (button) { button.hidden = true; });
        $$('[data-cart-form]', cartPage).forEach(function (form) {
            var field = $('[data-qty-input]', form), timer = null;
            var save = function () {
                var data = new FormData(form);
                data.set('action', 'update');
                data.set('quantity', Math.max(1, parseInt(field.value, 10) || 1));
                fetch(window.location.href, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } })
                    .then(function (response) { return response.json(); })
                    .then(function (result) {
                        if (result.ok && result.quantity > 0 && String(result.quantity) !== field.value) { field.value = result.quantity; recalc(); }
                    })
                    .catch(function () { window.location.reload(); });
            };
            field.addEventListener('input', function () {
                recalc();
                clearTimeout(timer); timer = setTimeout(save, 400);
            });
            field.addEventListener('change', function () {
                var max = parseInt(field.max || '99', 10);
                field.value = Math.max(1, Math.min(max, parseInt(field.value, 10) || 1));
                recalc();
            });
        });
    }

    // A variant's own stock limits the quantity.
    $$('[data-variant-chips]').forEach(function (group) {
        var field = $('[data-qty-input]', group.form);
        var sync = function () {
            var checked = $('input:checked', group);
            if (!checked) return;
            var available = parseInt(checked.dataset.available || '1', 10);
            field.max = Math.max(1, available);
            if (parseInt(field.value, 10) > available) field.value = Math.max(1, available);
        };
        group.addEventListener('change', sync); sync();
    });

    // Filters and sort apply immediately.
    $$('[data-auto-submit]').forEach(function (control) {
        control.addEventListener('change', function () { control.form.submit(); });
    });
    var filters = $('[data-filters]');
    $$('[data-filter-open]').forEach(function (button) { button.addEventListener('click', function () { filters.classList.add('is-open'); }); });
    $$('[data-filter-close]').forEach(function (button) { button.addEventListener('click', function () { filters.classList.remove('is-open'); }); });
    if (filters) filters.addEventListener('click', function (event) { if (event.target === filters) filters.classList.remove('is-open'); });

    // Checkout total follows the chosen delivery area.
    var checkout = $('[data-checkout]');
    if (checkout) {
        var subtotal = parseFloat($('[data-subtotal]', checkout).dataset.subtotal || '0');
        var deliveryCell = $('[data-delivery]', checkout);
        var charge = parseFloat(deliveryCell.dataset.charge || '0');
        var submitButton = $('.summary .btn-gold', checkout);
        var pendingSaves = 0;
        var format = function (amount) { return '৳' + Math.round(amount).toLocaleString('en-US'); };
        var refresh = function () {
            deliveryCell.textContent = format(charge);
            $('[data-total]', checkout).textContent = format(subtotal + charge);
        };
        $$('input[name="delivery_zone_id"]', checkout).forEach(function (radio) {
            radio.addEventListener('change', function () { charge = parseFloat(radio.dataset.charge || '0'); refresh(); });
        });

        // Order summary quantities: totals change instantly; the cart is saved before the order can be sent.
        var csrf = $('input[name="csrf"]', checkout).value;
        var recalcItems = function () {
            subtotal = 0;
            $$('[data-checkout-item]', checkout).forEach(function (item) {
                var field = $('[data-checkout-qty]', item);
                var qty = Math.max(1, Math.min(parseInt(field.max || '99', 10), parseInt(field.value, 10) || 1));
                var line = qty * parseFloat(item.dataset.price || '0');
                $('[data-checkout-line]', item).textContent = format(line);
                subtotal += line;
            });
            $('[data-subtotal]', checkout).textContent = format(subtotal);
            refresh();
        };
        $$('[data-checkout-item]', checkout).forEach(function (item) {
            var field = $('[data-checkout-qty]', item), timer = null;
            var save = function () {
                var data = new FormData();
                data.append('csrf', csrf); data.append('action', 'update');
                data.append('product_id', item.dataset.product); data.append('variant_name', item.dataset.variant || '');
                data.append('quantity', Math.max(1, parseInt(field.value, 10) || 1));
                pendingSaves++; if (submitButton) submitButton.disabled = true;
                fetch(window.location.href, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } })
                    .then(function (response) { return response.json(); })
                    .then(function (result) {
                        if (result.ok && result.quantity > 0 && String(result.quantity) !== field.value) { field.value = result.quantity; recalcItems(); }
                    })
                    .catch(function () { window.location.reload(); })
                    .finally(function () { pendingSaves--; if (!pendingSaves && submitButton) submitButton.disabled = false; });
            };
            field.addEventListener('input', function () {
                recalcItems();
                if (submitButton) submitButton.disabled = true;
                if (timer) { clearTimeout(timer); pendingSaves--; }
                pendingSaves++;
                timer = setTimeout(function () { timer = null; pendingSaves--; save(); }, 400);
            });
            field.addEventListener('change', function () {
                field.value = Math.max(1, Math.min(parseInt(field.max || '99', 10), parseInt(field.value, 10) || 1));
                recalcItems();
            });
        });
        // Quantity inputs belong to the cart, not to the order form, so Enter must not send the order.
        $$('[data-checkout-qty]', checkout).forEach(function (field) {
            field.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); field.blur(); } });
        });
    }

    // Toast fades out on its own.
    var toast = $('[data-toast]');
    if (toast) setTimeout(function () { toast.classList.add('is-hiding'); setTimeout(function () { toast.remove(); }, 500); }, 3000);
})();
