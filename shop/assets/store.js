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

    // Mobile search toggle
    var searchBox = $('[data-search]');
    $$('[data-search-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            searchBox.classList.toggle('is-open');
            if (searchBox.classList.contains('is-open')) $('[data-search-input]', searchBox).focus();
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
            });
        });
    });

    // A variant's own stock limits the quantity.
    $$('[data-variant-select]').forEach(function (select) {
        var field = $('[data-qty-input]', select.form);
        var sync = function () {
            var available = parseInt(select.options[select.selectedIndex].dataset.available || '1', 10);
            field.max = Math.max(1, available);
            if (parseInt(field.value, 10) > available) field.value = Math.max(1, available);
        };
        select.addEventListener('change', sync); sync();
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
        var format = function (amount) { return '৳' + Math.round(amount).toLocaleString('en-US'); };
        $$('input[name="delivery_zone_id"]', checkout).forEach(function (radio) {
            radio.addEventListener('change', function () {
                var charge = parseFloat(radio.dataset.charge || '0');
                $('[data-delivery]', checkout).textContent = format(charge);
                $('[data-total]', checkout).textContent = format(subtotal + charge);
            });
        });
    }

    // Toast fades out on its own.
    var toast = $('[data-toast]');
    if (toast) setTimeout(function () { toast.classList.add('is-hiding'); setTimeout(function () { toast.remove(); }, 500); }, 3000);
})();
