(() => {
    'use strict';
    // Stock Live Report with lazy loading:
    // - the page opens with a small batch of products (PAGE_SIZE)
    // - more products are fetched automatically while the user scrolls
    // - the live refresh only re-fetches totals and the products on screen
    const PAGE_SIZE = 20;
    const REFRESH_MS = 5000;
    const REFRESH_CHUNK = 150;
    const OPTIONS_EVERY = 6; // refresh filter dropdowns every ~30 seconds

    const el = id => document.getElementById('live-' + id);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const number = value => Number(value || 0).toLocaleString(undefined, {maximumFractionDigits: 2});
    const filterIds = ['category', 'sub-category', 'product', 'branch', 'metric'];
    const metrics = {on_hand:'On Hand',pos:'Total Stock',damaged:'Damaged',transit:'In Transit'};
    const value = (cell, metric) => metric === 'pos' ? Number(cell?.on_hand || 0) + Number(cell?.damaged || 0) : Number(cell?.[metric] || 0);

    let meta = null, filterOptions = null, metaSignature = '', locationSignature = '';
    let products = [], productSignatures = new Map();
    let total = 0, offset = 0, lastCategory = null;
    let generation = 0, pagePromise = null, pageError = false;
    let refreshBusy = false, refreshCount = 0, timer, backgroundTimer;

    function initializeSearchableFilters() {
        if (!window.jQuery || !window.jQuery.fn.select2) return;
        filterIds.forEach(id => {
            const $select = window.jQuery(el(id));
            if ($select.hasClass('select2-hidden-accessible')) {
                $select.trigger('change.select2');
                return;
            }
            const config = {
                theme: 'bootstrap4',
                width: '100%',
                minimumResultsForSearch: 0,
                dropdownParent: window.jQuery('#live-report')
            };
            if (id !== 'metric') {
                config.placeholder = el(id).options[0]?.textContent || 'Select option';
                config.allowClear = true;
            }
            $select.select2(config);
        });
    }
    function setStatus(state, label, title = '') {
        const status = el('status');
        status.dataset.state = state;
        el('status-label').textContent = label;
        status.title = title;
    }
    function setOptions(select, rows, first) {
        const current = select.value;
        select.replaceChildren(new Option(first, ''));
        rows.forEach(([id, label]) => select.add(new Option(label, String(id))));
        if ([...select.options].some(o => o.value === current)) select.value = current;
    }

    function buildUrl(params) {
        const query = new URLSearchParams({ajax: '1'});
        Object.entries(params).forEach(([key, val]) => query.set(key, String(val)));
        if (el('category').value) query.set('category', el('category').value);
        if (el('sub-category').value) query.set('sub_category', el('sub-category').value);
        if (el('product').value) query.set('product', el('product').value);
        return 'live_report.php?' + query.toString();
    }
    async function getJson(url) {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 20000);
        try {
            const response = await fetch(url, {cache: 'no-store', signal: controller.signal});
            if (!response.ok) throw new Error('Report could not be refreshed');
            const json = await response.json();
            if (json && json.error) throw new Error(json.error);
            return json;
        } finally {
            clearTimeout(timeout);
        }
    }

    // ---------- filters ----------
    function fillFilters() {
        if (!filterOptions) return;
        setOptions(el('category'), filterOptions.categories.map(c => [c.id, c.name]), 'All categories');
        const categoryId = el('category').value;
        const subCategories = [...new Map(filterOptions.sub_categories
            .filter(s => categoryId !== '' && String(s.category_id) === categoryId)
            .map(s => [s.name, s.name])).entries()];
        // Sub categories belong to one category: with "All categories" only "All sub categories" is offered.
        // Locked only for "All categories" or a category that has no sub categories.
        const noSubs = categoryId !== '' && subCategories.length === 0;
        setOptions(el('sub-category'), subCategories, noSubs ? 'No sub category' : 'All sub categories');
        if (categoryId === '') el('sub-category').value = '';
        const lockSub = categoryId === '' || noSubs;
        el('sub-category').disabled = lockSub;
        if (window.jQuery) window.jQuery(el('sub-category')).prop('disabled', lockSub).trigger('change.select2');
        const subCategory = el('sub-category').value;
        setOptions(el('product'), filterOptions.products
            .filter(p => (!categoryId || String(p.category_id) === categoryId) && (!subCategory || p.sub_category === subCategory))
            .map(p => [p.id, `${p.product_name} (${p.sku || 'No Code'})`]), 'All products');
        initializeSearchableFilters();
    }
    function fillBranches() {
        if (!meta) return;
        setOptions(el('branch'), meta.locations.map(b => [b.id, `${b.code} — ${b.branch_name}`]), 'All locations');
        initializeSearchableFilters();
    }
    const selectedBranches = () => (meta?.locations || []).filter(b => !el('branch').value || String(b.id) === el('branch').value);

    // ---------- rendering ----------
    function renderSummary() {
        const box = el('summary-box');
        if (!meta) { box.innerHTML = ''; return; }
        const branches = selectedBranches();
        const totals = {on_hand:0,pos:0,damaged:0,transit:0};
        meta.category_totals.forEach(c => c.variants.forEach(v => branches.forEach(b => Object.keys(totals).forEach(m => totals[m] += value(v.cells[b.id], m)))));
        box.innerHTML = '<div class="live-summary">' + ['on_hand','damaged','pos','transit'].map(m => (m === 'damaged' || m === 'transit')
            ? `<div>${esc(metrics[m])}<button type="button" class="live-breakdown-summary live-${m === 'damaged' ? 'damage' : m}-summary" title="View ${esc(metrics[m].toLowerCase())} details" aria-label="View ${esc(metrics[m].toLowerCase())} details"><strong>${number(totals[m])}</strong></button></div>`
            : `<div>${esc(metrics[m])}<strong>${number(totals[m])}</strong></div>`).join('') + '</div>';
    }
    function categoryTotalsHtml(categoryId) {
        const metric = el('metric').value;
        const branches = selectedBranches();
        const category = meta?.category_totals.find(c => String(c.id) === String(categoryId));
        const sizes = new Map();
        (category?.variants || [{name: '', cells: {}}]).forEach(v => {
            const name = v.name || 'Standard';
            sizes.set(name, (sizes.get(name) || 0) + branches.reduce((sum, b) => sum + value(v.cells[b.id], metric), 0));
        });
        const categoryTotal = [...sizes.values()].reduce((sum, qty) => sum + qty, 0);
        return `${esc(metrics[metric])} ${[...sizes].map(([name, qty]) => `${esc(name)}: <strong>${number(qty)}</strong>`).join(' &nbsp; | &nbsp; ')} &nbsp; || &nbsp; Total: <strong>${number(categoryTotal)}</strong>`;
    }
    function categoryHeaderHtml(p) {
        return `<div class="live-category-start" data-category-id="${esc(p.category_id)}"><h4 class="live-category-title">${esc(p.category_name)}</h4><p class="small" data-category-totals="${esc(p.category_id)}">${categoryTotalsHtml(p.category_id)}</p></div>`;
    }
    function updateCategoryHeaders() {
        el('products').querySelectorAll('[data-category-totals]').forEach(node => {
            node.innerHTML = categoryTotalsHtml(node.dataset.categoryTotals);
        });
    }
    function productHtml(p) {
        const metric = el('metric').value;
        const branches = selectedBranches();
        const columns = branches.map(b => `<th class="${b.code === 'WH' ? 'wh' : ''}" title="${esc(b.branch_name)}">${esc(b.code)}</th>`).join('');
        let html = `<section class="live-product-block" data-product-id="${esc(p.id)}"><div class="live-product-head"><button type="button" class="live-photo-preview" data-photo-src="${esc(p.photo)}" data-photo-alt="${esc(p.product_name)}" title="Click to view larger photo"><img src="${esc(p.photo)}" alt="${esc(p.product_name)}" loading="lazy"></button><div><strong>${esc(p.product_name)}</strong><div class="text-muted small">Code: ${esc(p.sku || '—')}</div></div></div><div class="live-scroll"><table class="live-matrix"><thead><tr><th>Variant</th>${columns}<th>Total Qty</th></tr></thead><tbody>`;
        p.variants.forEach(row => {
            const cells = branches.map(b => value(row.cells[b.id], metric));
            html += `<tr><td>${esc(row.name || 'Standard')}</td>${cells.map(n => `<td class="${metric === 'damaged' ? 'damage' : ''}">${number(n)}</td>`).join('')}<td><strong>${number(cells.reduce((a, b) => a + b, 0))}</strong></td></tr>`;
        });
        const sums = branches.map(b => p.variants.reduce((s, v) => s + value(v.cells[b.id], metric), 0));
        html += `</tbody><tfoot><tr><td>Total</td>${sums.map(n => `<td>${number(n)}</td>`).join('')}<td>${number(sums.reduce((a, b) => a + b, 0))}</td></tr></tfoot></table></div></section>`;
        return html;
    }
    function rememberScroll(container) {
        return new Map([...container.querySelectorAll('.live-product-block')].map(s => [s.dataset.productId, s.querySelector('.live-scroll')?.scrollLeft || 0]));
    }
    function restoreScroll(container, scroll) {
        container.querySelectorAll('.live-product-block').forEach(s => {
            const left = scroll.get(s.dataset.productId);
            const box = s.querySelector('.live-scroll');
            if (left && box) box.scrollLeft = left;
        });
    }
    function renderAllProducts() {
        const container = el('products');
        const scroll = rememberScroll(container);
        let html = '', category = null;
        products.forEach(p => {
            if (p.category_id !== category) { category = p.category_id; html += categoryHeaderHtml(p); }
            html += productHtml(p);
        });
        lastCategory = category;
        container.innerHTML = html;
        restoreScroll(container, scroll);
    }
    function appendProducts(list) {
        let html = '';
        list.forEach(p => {
            if (productSignatures.has(p.id)) return; // already shown
            productSignatures.set(p.id, JSON.stringify(p));
            products.push(p);
            if (p.category_id !== lastCategory) { lastCategory = p.category_id; html += categoryHeaderHtml(p); }
            html += productHtml(p);
        });
        if (html) el('products').insertAdjacentHTML('beforeend', html);
    }
    function replaceProduct(p) {
        const section = el('products').querySelector(`.live-product-block[data-product-id="${CSS.escape(String(p.id))}"]`);
        if (!section) return;
        const left = section.querySelector('.live-scroll')?.scrollLeft || 0;
        section.insertAdjacentHTML('afterend', productHtml(p));
        const fresh = section.nextElementSibling;
        section.remove();
        if (left && fresh) fresh.querySelector('.live-scroll').scrollLeft = left;
    }
    // Loading happens silently in the background, so no loading text is shown.
    function renderMore() {
        el('more').innerHTML = (meta && total === 0) ? '<div class="live-empty">No products match these filters.</div>' : '';
    }
    function scheduleBackgroundLoad(delay = 150) {
        clearTimeout(backgroundTimer);
        if (!meta || offset >= total) return;
        const gen = generation;
        backgroundTimer = setTimeout(() => {
            if (gen !== generation) return;
            const run = () => { if (gen === generation) loadNextPage().catch(() => {}); };
            if ('requestIdleCallback' in window) window.requestIdleCallback(run, {timeout: 1000}); else run();
        }, delay);
    }

    // ---------- data loading ----------
    async function loadMeta(withOptions) {
        const gen = generation;
        const next = await getJson(buildUrl({mode: 'meta', options: withOptions ? '1' : '0'}));
        if (gen !== generation) return;
        if (next.options) { filterOptions = next.options; fillFilters(); }
        meta = next;
        total = Number(next.total || 0);
        const locSig = JSON.stringify(next.locations);
        const sig = JSON.stringify([next.locations, next.category_totals, next.total]);
        if (locSig !== locationSignature) {
            locationSignature = locSig;
            fillBranches();
            renderAllProducts();
        }
        if (sig !== metaSignature) {
            metaSignature = sig;
            renderSummary();
            updateCategoryHeaders();
        }
        if (!pagePromise) renderMore();
    }
    function loadNextPage() {
        if (pagePromise) return pagePromise;
        if (!meta || offset >= total) return Promise.resolve();
        const gen = generation;
        pageError = false;
        pagePromise = (async () => {
            try {
                const page = await getJson(buildUrl({mode: 'page', offset, limit: PAGE_SIZE}));
                if (gen !== generation) return;
                const list = Array.isArray(page.products) ? page.products : [];
                offset += list.length;
                total = Number(page.total ?? total);
                if (!list.length) offset = total; // nothing more on the server
                appendProducts(list);
            } catch (error) {
                if (gen === generation) pageError = true;
                throw error;
            } finally {
                if (gen === generation) {
                    pagePromise = null;
                    renderMore();
                    // Keep loading the rest quietly; retry a little later after an error.
                    scheduleBackgroundLoad(pageError ? 3000 : 150);
                }
            }
        })();
        return pagePromise;
    }
    function maybeLoadMore() {
        if (pagePromise || !meta || offset >= total) return;
        const rect = el('more').getBoundingClientRect();
        if (rect.top < window.innerHeight + 600) loadNextPage().catch(() => {});
    }
    async function loadAllPages() {
        const gen = generation;
        while (gen === generation && meta && offset < total) {
            await loadNextPage();
        }
    }
    function visibleProductIds() {
        const top = -window.innerHeight, bottom = window.innerHeight * 2;
        const ids = [];
        el('products').querySelectorAll('.live-product-block').forEach(section => {
            if (ids.length >= REFRESH_CHUNK) return;
            const rect = section.getBoundingClientRect();
            if (rect.bottom >= top && rect.top <= bottom) ids.push(Number(section.dataset.productId));
        });
        return ids;
    }
    async function refreshProducts(ids, gen) {
        if (!ids.length) return;
        const returned = new Map();
        for (let i = 0; i < ids.length; i += REFRESH_CHUNK) {
            const chunk = ids.slice(i, i + REFRESH_CHUNK);
            const res = await getJson(buildUrl({mode: 'products', ids: chunk.join(',')}));
            if (gen !== generation) return;
            (res.products || []).forEach(p => returned.set(Number(p.id), p));
        }
        let rebuild = false;
        const changed = [];
        ids.forEach(id => {
            const index = products.findIndex(p => Number(p.id) === id);
            if (index < 0) return;
            const next = returned.get(id);
            if (!next) { // deleted or no longer matches the filters
                products.splice(index, 1);
                productSignatures.delete(id);
                rebuild = true;
                return;
            }
            const sig = JSON.stringify(next);
            if (sig === productSignatures.get(id)) return;
            if (products[index].category_id !== next.category_id || products[index].category_name !== next.category_name) rebuild = true;
            products[index] = next;
            productSignatures.set(id, sig);
            changed.push(next);
        });
        if (rebuild) renderAllProducts(); else changed.forEach(replaceProduct);
    }
    function scheduleRefresh() {
        clearTimeout(timer);
        if (!document.hidden && navigator.onLine) timer = setTimeout(refresh, REFRESH_MS);
    }
    async function refresh() {
        clearTimeout(timer);
        if (refreshBusy) return scheduleRefresh();
        refreshBusy = true;
        const gen = generation;
        try {
            refreshCount++;
            await loadMeta(!filterOptions || refreshCount % OPTIONS_EVERY === 0);
            if (gen === generation) await refreshProducts(visibleProductIds(), gen);
            if (gen === generation) setStatus('live', 'Live');
            maybeLoadMore();
        } catch (error) {
            if (gen === generation) setStatus('error', 'Disconnected', 'Displaying last loaded data. Reconnecting automatically.');
        } finally {
            refreshBusy = false;
            scheduleRefresh();
        }
    }
    async function resetAndLoad(withOptions = false) {
        generation++;
        clearTimeout(timer);
        pagePromise = null; pageError = false;
        products = []; productSignatures = new Map();
        offset = 0; total = 0; lastCategory = null; metaSignature = '';
        el('products').innerHTML = '';
        clearTimeout(backgroundTimer);
        el('content').setAttribute('aria-busy', 'true');
        el('more').innerHTML = '';
        setStatus('loading', 'Live');
        try {
            await loadMeta(withOptions);
            await loadNextPage();
            setStatus('live', 'Live');
        } catch (error) {
            setStatus('error', 'Disconnected', 'Displaying last loaded data. Reconnecting automatically.');
            renderMore();
        } finally {
            el('content').setAttribute('aria-busy', 'false');
            scheduleRefresh();
        }
    }
    function markOffline() {
        clearTimeout(timer);
        setStatus('error', 'Offline', 'Internet connection is unavailable. Reconnecting automatically when it returns.');
        el('content').setAttribute('aria-busy', 'false');
    }

    // ---------- events ----------
    // Remember the last Category / Sub Category per company and user.
    const filterKey = document.getElementById('live-report').dataset.filterKey || 'you2biz.liveReport';
    function readSavedFilters() {
        try { return JSON.parse(window.localStorage.getItem(filterKey) || 'null'); } catch (e) { return null; }
    }
    function saveFilters() {
        try { window.localStorage.setItem(filterKey, JSON.stringify({category: el('category').value, sub: el('sub-category').value})); } catch (e) { /* storage unavailable */ }
    }
    // First visit: first category and its first sub category. Later: the last choice.
    function applyStartingFilters() {
        if (!filterOptions) return;
        // Populate the select before assigning a saved/default value: assigning
        // a value to an option that does not exist yet clears the selection.
        fillFilters();
        const saved = readSavedFilters();
        const categories = filterOptions.categories.map(c => String(c.id));
        let category = '';
        let sub = '';
        if (saved && (saved.category === '' || categories.includes(String(saved.category)))) {
            category = String(saved.category || '');
            sub = category === '' ? '' : String(saved.sub || '');
        } else if (categories.length) {
            category = categories[0];
            const firstSub = filterOptions.sub_categories.find(s => String(s.category_id) === category);
            sub = firstSub ? firstSub.name : '';
        }
        el('category').value = category;
        fillFilters();
        const subOk = [...el('sub-category').options].some(o => o.value === sub);
        el('sub-category').value = subOk ? sub : '';
        fillFilters();
        saveFilters();
    }
    let started = false;
    async function startReport() {
        if (started) return;
        started = true;
        setStatus('loading', 'Live');
        try {
            const first = await getJson(buildUrl({mode: 'meta', options: '1'}));
            if (first.options) filterOptions = first.options;
            applyStartingFilters();
        } catch (e) {
            // Load with the current (empty) filters if the options request fails.
        }
        resetAndLoad(false);
    }
    function onFilterChange(id) {
        if (id === 'branch' || id === 'metric') {
            renderSummary();
            renderAllProducts();
            return;
        }
        // Rebuild dependent options before choosing the new category's first sub category.
        if (id === 'category') {
            el('sub-category').value = '';
            el('product').value = '';
            fillFilters();
            el('sub-category').value = el('sub-category').options[1]?.value || '';
        }
        if (id === 'sub-category') el('product').value = '';
        if (id === 'category' || id === 'sub-category') { fillFilters(); saveFilters(); }
        resetAndLoad(false);
    }
    // Select2 fires jQuery change events, so bind through jQuery when available.
    filterIds.forEach(id => {
        if (window.jQuery) window.jQuery(el(id)).on('change', () => onFilterChange(id));
        else el(id).addEventListener('change', () => onFilterChange(id));
    });
    if ('IntersectionObserver' in window) {
        new IntersectionObserver(entries => {
            if (entries.some(entry => entry.isIntersecting)) maybeLoadMore();
        }, {rootMargin: '600px 0px'}).observe(el('more'));
    }
    window.addEventListener('scroll', maybeLoadMore, {passive: true});
    document.getElementById('live-report').addEventListener('scroll', maybeLoadMore, {passive: true}); // full screen mode
    document.addEventListener('click', event => {
        if (event.target.closest('#live-load-more')) { pageError = false; loadNextPage().catch(() => {}); return; }
        if (event.target.closest('.live-damage-summary')) { showDamageBreakdown(); return; }
        if (event.target.closest('.live-transit-summary')) { showTransitBreakdown(); return; }
        const button = event.target.closest('.live-photo-preview');
        if (!button || !window.jQuery) return;
        document.getElementById('live-photo-preview-image').setAttribute('src', button.dataset.photoSrc || '');
        document.getElementById('live-photo-preview-image').setAttribute('alt', button.dataset.photoAlt || 'Product photo');
        document.getElementById('live-photo-preview-title').textContent = button.dataset.photoAlt || 'Product Photo';
        window.jQuery('#live-photo-preview-modal').modal('show');
    });

    async function showDamageBreakdown() {
        if (!meta || !window.jQuery) return;
        const body = document.getElementById('live-damage-breakdown-body');
        body.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-4">Loading…</td></tr>';
        document.getElementById('live-damage-breakdown-total').textContent = '…';
        window.jQuery('#live-damage-breakdown-modal').modal('show');
        try {
            const res = await getJson(buildUrl({mode: 'damage'}));
            const branches = selectedBranches();
            const order = new Map(branches.map((b, i) => [Number(b.id), i]));
            const rows = (res.rows || [])
                .filter(row => order.has(Number(row.branch_id)))
                .sort((a, b) => order.get(Number(a.branch_id)) - order.get(Number(b.branch_id)) || String(a.product_name).localeCompare(String(b.product_name)));
            const branchById = new Map(branches.map(b => [Number(b.id), b]));
            body.innerHTML = rows.length
                ? rows.map(row => { const branch = branchById.get(Number(row.branch_id)); return `<tr><td>${esc(branch.branch_name)} <span class="text-muted">[${esc(branch.code)}]</span></td><td>${esc(row.product_name)} <span class="text-muted">[${esc(row.sku || 'No Code')}]</span></td><td class="text-right font-weight-bold text-danger">${number(row.quantity)}</td></tr>`; }).join('')
                : '<tr><td colspan="3" class="text-center text-muted py-4">No damaged stock for the selected filters.</td></tr>';
            document.getElementById('live-damage-breakdown-total').textContent = number(rows.reduce((sum, row) => sum + Number(row.quantity || 0), 0));
        } catch (error) {
            body.innerHTML = '<tr><td colspan="3" class="text-center text-danger py-4">Damaged stock could not be loaded. Please retry.</td></tr>';
            document.getElementById('live-damage-breakdown-total').textContent = '0';
        }
    }
    function showTransitBreakdown() {
        if (!meta || !window.jQuery) return;
        const routes = (meta.transit_routes || []).filter(route => !el('branch').value || String(route.to_branch_id) === el('branch').value);
        const body = document.getElementById('live-transit-breakdown-body');
        body.innerHTML = routes.length ? routes.map(route => {
            const variants = route.variant_summary ? `<small class="d-block text-muted">${esc(route.variant_summary)}</small>` : '';
            return `<tr><td class="text-nowrap">${esc(route.transfer_date || '—')}</td><td>${esc(route.from_name)} <span class="text-muted">[${esc(route.from_code)}]</span></td><td>${esc(route.to_name)} <span class="text-muted">[${esc(route.to_code)}]</span></td><td>${esc(route.product_name || 'Product')} <span class="text-muted">[${esc(route.sku || 'No Code')}]</span>${variants}</td><td class="text-right font-weight-bold text-primary">${number(route.quantity)}</td></tr>`;
        }).join('') : '<tr><td colspan="5" class="text-center text-muted py-4">No stock is in transit for the selected filters.</td></tr>';
        document.getElementById('live-transit-breakdown-total').textContent = number(routes.reduce((sum, route) => sum + Number(route.quantity || 0), 0));
        window.jQuery('#live-transit-breakdown-modal').modal('show');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeSearchableFilters);
    } else {
        initializeSearchableFilters();
    }
    window.addEventListener('beforeprint', () => {
        const filters = ['category','sub-category','product','branch','metric'].map(id => el(id).selectedOptions[0]?.textContent || '').filter(Boolean);
        document.getElementById('live-print-meta').textContent = filters.join('  |  ') + ' — Printed: ' + new Date().toLocaleString('en-GB');
    });
    const report = document.getElementById('live-report');
    const fullscreenButton = el('fullscreen');
    fullscreenButton.addEventListener('click', async () => {
        el('fullscreen-error').textContent = '';
        try {
            if (document.fullscreenElement === report) {
                await document.exitFullscreen();
            } else {
                await report.requestFullscreen();
            }
        } catch (error) {
            el('fullscreen-error').textContent = 'Full screen could not be opened. Please try again.';
        }
    });
    document.addEventListener('fullscreenchange', () => {
        const active = document.fullscreenElement === report;
        if (window.jQuery && window.jQuery.fn.select2) {
            filterIds.forEach(id => window.jQuery(el(id)).select2('close'));
        }
        fullscreenButton.textContent = active ? 'Exit Full Screen (Esc)' : 'Full Screen';
        fullscreenButton.setAttribute('aria-pressed', String(active));
        if (!active) fullscreenButton.focus({preventScroll: true});
        requestAnimationFrame(maybeLoadMore);
    });
    // Print needs every matching product, so load the remaining pages first.
    el('print').addEventListener('click', async () => {
        const button = el('print');
        const label = button.textContent;
        button.disabled = true;
        button.textContent = 'Preparing…';
        try {
            await loadAllPages();
            await refreshProducts(products.map(p => Number(p.id)), generation);
        } catch (error) {
            // Print whatever is already loaded.
        } finally {
            button.disabled = false;
            button.textContent = label;
        }
        document.getElementById('live-print-style').textContent = '@page {size:A4 landscape;margin:8mm;}';
        window.print();
    });
    window.addEventListener('offline', markOffline);
    window.addEventListener('online', () => {
        setStatus('loading', 'Reconnecting…', 'Internet connection restored. Checking the server.');
        started ? refresh() : startReport();
    });
    document.addEventListener('visibilitychange', () => { clearTimeout(timer); if (!document.hidden) navigator.onLine ? (started ? refresh() : startReport()) : markOffline(); });
    navigator.onLine ? startReport() : markOffline();
})();
