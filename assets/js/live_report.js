(() => {
    'use strict';
    const el = id => document.getElementById('live-' + id);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const number = value => Number(value || 0).toLocaleString(undefined, {maximumFractionDigits: 2});
    let data, busy = false, timer, lastSignature = '';
    const filterIds = ['category', 'sub-category', 'product', 'branch', 'metric'];
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
    const metrics = {on_hand:'On Hand',pos:'Total Stock',damaged:'Damaged',transit:'In Transit'};
    const value = (cell, metric) => metric === 'pos' ? Number(cell?.on_hand || 0) + Number(cell?.damaged || 0) : Number(cell?.[metric] || 0);
    function options(select, rows, first) {
        const current = select.value;
        select.replaceChildren(new Option(first, ''));
        rows.forEach(([id, label]) => select.add(new Option(label, String(id))));
        if ([...select.options].some(o => o.value === current)) select.value = current;
    }
    function filters() {
        options(el('category'), [...new Map(data.products.map(p => [p.category_id,p.category_name])).entries()], 'All categories');
        const categoryId = el('category').value;
        const subCategories = [...new Map(data.products.filter(p => !categoryId || String(p.category_id) === categoryId).filter(p => p.sub_category).map(p => [p.sub_category,p.sub_category])).entries()];
        options(el('sub-category'), subCategories, 'All sub categories');
        options(el('branch'), data.locations.map(b => [b.id,`${b.code} — ${b.branch_name}`]), 'All locations');
        options(el('product'), data.products.filter(p => (!categoryId || String(p.category_id) === categoryId) && (!el('sub-category').value || p.sub_category === el('sub-category').value)).map(p => [p.id,`${p.product_name} (${p.sku || 'No Code'})`]), 'All products');
        initializeSearchableFilters();
    }
    function render() {
        if (!data) return;
        const branches = data.locations.filter(b => !el('branch').value || String(b.id) === el('branch').value);
        const products = data.products.filter(p => (!el('category').value || String(p.category_id) === el('category').value) && (!el('sub-category').value || p.sub_category === el('sub-category').value) && (!el('product').value || String(p.id) === el('product').value));
        const metric = el('metric').value;
        const totals = {on_hand:0,pos:0,damaged:0,transit:0};
        products.forEach(p => p.variants.forEach(v => branches.forEach(b => Object.keys(totals).forEach(m => totals[m] += value(v.cells[b.id],m)))));
        let html = '<div class="live-summary">' + ['on_hand','damaged','pos','transit'].map(m => (m === 'damaged' || m === 'transit')
            ? `<div>${esc(metrics[m])}<button type="button" class="live-breakdown-summary live-${m === 'damaged' ? 'damage' : m}-summary" title="View ${esc(metrics[m].toLowerCase())} details" aria-label="View ${esc(metrics[m].toLowerCase())} details"><strong>${number(totals[m])}</strong></button></div>`
            : `<div>${esc(metrics[m])}<strong>${number(totals[m])}</strong></div>`).join('') + '</div>';
        const columns = branches.map(b => `<th class="${b.code === 'WH' ? 'wh' : ''}" title="${esc(b.branch_name)}">${esc(b.code)}</th>`).join('');
        let category = null;
        products.forEach(p => {
            const startsCategory = p.category_id !== category;
            if (startsCategory) {
                html += '<div class="live-category-start">';
                category = p.category_id;
                const categoryProducts = products.filter(x => x.category_id === category);
                const sizes = new Map();
                categoryProducts.forEach(x => x.variants.forEach(v => sizes.set(v.name || 'Standard', (sizes.get(v.name || 'Standard') || 0) + branches.reduce((sum,b) => sum + value(v.cells[b.id],metric),0))));
                const categoryTotal = [...sizes.values()].reduce((sum, qty) => sum + qty, 0);
                html += `<h4 class="live-category-title">${esc(p.category_name)}</h4><p class="small">${esc(metrics[metric])} ${[...sizes].map(([name,qty]) => `${esc(name)}: <strong>${number(qty)}</strong>`).join(' &nbsp; | &nbsp; ')} &nbsp; || &nbsp; Total: <strong>${number(categoryTotal)}</strong></p>`;
            }
            html += `<section class="live-product-block"><div class="live-product-head"><button type="button" class="live-photo-preview" data-photo-src="${esc(p.photo)}" data-photo-alt="${esc(p.product_name)}" title="Click to view larger photo"><img src="${esc(p.photo)}" alt="${esc(p.product_name)}"></button><div><strong>${esc(p.product_name)}</strong><div class="text-muted small">Code: ${esc(p.sku || '—')}</div></div></div><div class="live-scroll"><table class="live-matrix"><thead><tr><th>Variant</th>`;
            html += columns;
            html += '<th>Total Qty</th></tr></thead><tbody>';
            const rows = p.variants;
            rows.forEach(row => {
                const cells = branches.map(b => value(row.cells[b.id],metric));
                html += `<tr><td>${esc(row.name || 'Standard')}</td>${cells.map(n => `<td class="${metric === 'damaged' ? 'damage' : ''}">${number(n)}</td>`).join('')}<td><strong>${number(cells.reduce((a,b)=>a+b,0))}</strong></td></tr>`;
            });
            const sums = branches.map(b => p.variants.reduce((s,v)=>s+value(v.cells[b.id],metric),0));
            html += `</tbody><tfoot><tr><td>Total</td>${sums.map(n=>`<td>${number(n)}</td>`).join('')}<td>${number(sums.reduce((a,b)=>a+b,0))}</td></tr></tfoot></table></div></section>`;
            if (startsCategory) html += '</div>';
        });
        if (!products.length) html += '<div class="live-empty">No products match these filters.</div>';
        const content = el('content'), scroll = [...content.querySelectorAll('.live-scroll')].map(x=>x.scrollLeft);
        content.innerHTML = html;
        content.querySelectorAll('.live-scroll').forEach((x,i)=>x.scrollLeft=scroll[i] || 0);
        content.querySelector('.live-damage-summary')?.addEventListener('click', showDamageBreakdown);
        content.querySelector('.live-transit-summary')?.addEventListener('click', showTransitBreakdown);
    }
    async function refresh() {
        clearTimeout(timer);
        if (busy) return;
        busy = true;
        const controller = new AbortController(), timeout = setTimeout(()=>controller.abort(),20000);
        try {
            const response = await fetch('live_report.php?ajax=1', {cache:'no-store',signal:controller.signal});
            if (!response.ok) throw new Error('Report could not be refreshed');
            const next = await response.json();
            if (!Array.isArray(next.products)) throw new Error('Invalid report response');
            const signature = JSON.stringify([next.products,next.locations,next.transit_routes]);
            data = next;
            if (signature !== lastSignature) { filters(); render(); lastSignature = signature; }
            setStatus('live', 'Live');
        } catch (error) {
            setStatus('error', 'Disconnected', 'Displaying last loaded data. Reconnecting automatically.');
        } finally {
            clearTimeout(timeout); busy = false; el('content').setAttribute('aria-busy','false');
            if (!document.hidden) timer = setTimeout(refresh,5000);
        }
    }
    function markOffline() {
        clearTimeout(timer);
        setStatus('error', 'Offline', 'Internet connection is unavailable. Reconnecting automatically when it returns.');
        el('content').setAttribute('aria-busy','false');
    }
    filterIds.forEach(id=>el(id).addEventListener('change',()=>{if((id==='category' || id==='sub-category') && data) filters(); render();}));
    function showDamageBreakdown() {
        if (!data || !window.jQuery) return;
        const branches = data.locations.filter(branch => !el('branch').value || String(branch.id) === el('branch').value);
        const products = data.products.filter(product =>
            (!el('category').value || String(product.category_id) === el('category').value) &&
            (!el('sub-category').value || product.sub_category === el('sub-category').value) &&
            (!el('product').value || String(product.id) === el('product').value)
        );
        const rows = [];
        branches.forEach(branch => products.forEach(product => {
            const quantity = product.variants.reduce((total, variant) => total + value(variant.cells[branch.id], 'damaged'), 0);
            if (quantity > 0) rows.push({branch, product, quantity});
        }));
        const body = document.getElementById('live-damage-breakdown-body');
        body.innerHTML = rows.length
            ? rows.map(row => `<tr><td>${esc(row.branch.branch_name)} <span class="text-muted">[${esc(row.branch.code)}]</span></td><td>${esc(row.product.product_name)} <span class="text-muted">[${esc(row.product.sku || 'No Code')}]</span></td><td class="text-right font-weight-bold text-danger">${number(row.quantity)}</td></tr>`).join('')
            : '<tr><td colspan="3" class="text-center text-muted py-4">No damaged stock for the selected filters.</td></tr>';
        document.getElementById('live-damage-breakdown-total').textContent = number(rows.reduce((sum, row) => sum + row.quantity, 0));
        window.jQuery('#live-damage-breakdown-modal').modal('show');
    }
    function showTransitBreakdown() {
        if (!data || !window.jQuery) return;
        const selectedProducts = new Map(data.products.filter(product =>
            (!el('category').value || String(product.category_id) === el('category').value) &&
            (!el('sub-category').value || product.sub_category === el('sub-category').value) &&
            (!el('product').value || String(product.id) === el('product').value)
        ).map(product => [Number(product.id), product]));
        const routes = (data.transit_routes || []).filter(route =>
            selectedProducts.has(Number(route.product_id)) &&
            (!el('branch').value || String(route.to_branch_id) === el('branch').value)
        );
        const body = document.getElementById('live-transit-breakdown-body');
        body.innerHTML = routes.length ? routes.map(route => {
            const product = selectedProducts.get(Number(route.product_id));
            const variants = route.variant_summary ? `<small class="d-block text-muted">${esc(route.variant_summary)}</small>` : '';
            return `<tr><td class="text-nowrap">${esc(route.transfer_date || '—')}</td><td>${esc(route.from_name)} <span class="text-muted">[${esc(route.from_code)}]</span></td><td>${esc(route.to_name)} <span class="text-muted">[${esc(route.to_code)}]</span></td><td>${esc(product?.product_name || 'Product')} <span class="text-muted">[${esc(product?.sku || 'No Code')}]</span>${variants}</td><td class="text-right font-weight-bold text-primary">${number(route.quantity)}</td></tr>`;
        }).join('') : '<tr><td colspan="5" class="text-center text-muted py-4">No stock is in transit for the selected filters.</td></tr>';
        document.getElementById('live-transit-breakdown-total').textContent = number(routes.reduce((sum, route) => sum + Number(route.quantity || 0), 0));
        window.jQuery('#live-transit-breakdown-modal').modal('show');
    }
    document.addEventListener('click', event => {
        const button = event.target.closest('.live-photo-preview');
        if (!button || !window.jQuery) return;
        document.getElementById('live-photo-preview-image').setAttribute('src', button.dataset.photoSrc || '');
        document.getElementById('live-photo-preview-image').setAttribute('alt', button.dataset.photoAlt || 'Product photo');
        document.getElementById('live-photo-preview-title').textContent = button.dataset.photoAlt || 'Product Photo';
        window.jQuery('#live-photo-preview-modal').modal('show');
    });
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
    });
    el('print').addEventListener('click',()=>{document.getElementById('live-print-style').textContent='@page {size:A4 landscape;margin:8mm;}';window.print();});
    window.addEventListener('offline', markOffline);
    window.addEventListener('online', () => {
        setStatus('loading', 'Reconnecting…', 'Internet connection restored. Checking the server.');
        refresh();
    });
    document.addEventListener('visibilitychange',()=>{clearTimeout(timer);if(!document.hidden) navigator.onLine ? refresh() : markOffline();});
    navigator.onLine ? refresh() : markOffline();
})();
