(() => {
    'use strict';

    const messageHost = () => {
        let host = document.getElementById('ajax-action-message');
        if (host) return host;
        host = document.createElement('div');
        host.id = 'ajax-action-message';
        host.setAttribute('role', 'status');
        const content = document.querySelector('.content-wrapper .content')
            || document.querySelector('.content-wrapper')
            || document.querySelector('main')
            || document.body;
        content.insertBefore(host, content.firstChild);
        return host;
    };

    function showMessage(message, success) {
        const host = messageHost();
        host.className = `alert alert-${success ? 'success' : 'danger'} mx-3 mt-3`;
        host.textContent = message || (success ? 'Action completed successfully.' : 'Action could not be completed.');
        host.scrollIntoView({behavior: 'smooth', block: 'nearest'});
        clearTimeout(showMessage.timer);
        if (success) showMessage.timer = setTimeout(() => host.remove(), 4500);
    }
    window.showAjaxActionMessage = showMessage;

    function responseMessage(text, response) {
        const contentType = response.headers.get('content-type') || '';
        if (contentType.includes('application/json')) {
            try {
                const json = JSON.parse(text);
                return {success: response.ok && json.success !== false && json.ok !== false, message: json.message || '', json};
            } catch (error) {
                return {success: false, message: 'Invalid server response.'};
            }
        }
        const documentResult = new DOMParser().parseFromString(text, 'text/html');
        const alert = documentResult.querySelector('.alert-danger, .alert-warning, .alert-success, .alert-info');
        const failed = !response.ok || (alert && alert.classList.contains('alert-danger'));
        return {
            success: !failed,
            message: alert ? alert.textContent.trim() : (failed ? 'Action could not be completed.' : 'Action completed successfully.'),
            html: text
        };
    }

    function removeCompletedRow(source, action) {
        if (!['delete', 'delete_manager', 'delete_customer_access', 'approve', 'reject', 'receive_at_warehouse'].includes(action)) return;
        const row = source.closest('tr');
        if (!row) return;
        const table = row.closest('table');
        if (table && window.jQuery && window.jQuery.fn.DataTable
            && window.jQuery.fn.DataTable.isDataTable(table)) {
            window.jQuery(table).DataTable().row(row).remove().draw(false);
        } else {
            row.remove();
        }
    }

    document.addEventListener('submit', async event => {
        const form = event.target.closest('form');
        if (!form || event.defaultPrevented || form.dataset.noAjax === 'true' || form.id === 'product-setup-form') return;
        if ((form.method || 'get').toLowerCase() !== 'post') return;

        event.preventDefault();
        const submitter = event.submitter;
        const buttons = [...form.querySelectorAll('button[type="submit"],input[type="submit"]')];
        const data = new FormData(form);
        if (submitter && submitter.name) data.set(submitter.name, submitter.value);
        const action = String(data.get('action') || '');
        buttons.forEach(button => { button.disabled = true; });

        try {
            // A field named "action" shadows the form.action DOM property.
            const response = await fetch(form.getAttribute('action') || window.location.href, {
                method: 'POST',
                body: data,
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            });
            const result = responseMessage(await response.text(), response);
            if (!result.success) throw new Error(result.message);
            showMessage(result.message, true);
            removeCompletedRow(form, action);
            if (form.dataset.ajaxReset === 'true') form.reset();
            form.dispatchEvent(new CustomEvent('ajax-action-success', {
                detail: {result, action},
                bubbles: true
            }));
            // Deleted rows are detached; their form events cannot reach document.
            document.dispatchEvent(new CustomEvent('ajax-page-action-complete', {
                detail: {result, action, source: form}
            }));
        } catch (error) {
            showMessage(error.message || 'Action could not be completed.', false);
        } finally {
            buttons.forEach(button => { button.disabled = false; });
        }
    });

    document.addEventListener('click', async event => {
        const link = event.target.closest('a[data-ajax-action-link="true"]');
        if (!link || event.defaultPrevented) return;
        if (link.dataset.confirm && !window.confirm(link.dataset.confirm)) return;
        event.preventDefault();
        link.classList.add('disabled');
        link.setAttribute('aria-disabled', 'true');
        try {
            const response = await fetch(link.href, {headers: {'X-Requested-With': 'XMLHttpRequest'}});
            const result = responseMessage(await response.text(), response);
            if (!result.success) throw new Error(result.message);
            showMessage(result.message, true);
            if (link.dataset.ajaxStatus) {
                const row = link.closest('tr');
                const badge = row && row.querySelector('.ajax-status-badge');
                const active = link.dataset.ajaxStatus === 'active';
                if (badge) {
                    badge.textContent = active ? 'Active' : 'Inactive';
                    badge.className = `badge ajax-status-badge ${active ? 'badge-success' : 'badge-secondary'}`;
                }
                const nextStatus = active ? 'inactive' : 'active';
                const url = new URL(link.href, window.location.href);
                url.searchParams.set('status', nextStatus);
                link.href = url.toString();
                link.dataset.ajaxStatus = nextStatus;
                link.classList.toggle('btn-success', !active);
                link.classList.toggle('btn-warning', active);
                link.title = active ? 'Deactivate Access' : 'Activate Access';
                link.setAttribute('aria-label', link.title);
                link.innerHTML = active ? '<i class="fas fa-ban"></i>' : '<i class="fas fa-check"></i>';
                link.classList.remove('disabled');
                link.removeAttribute('aria-disabled');
            }
            if (link.dataset.ajaxRemove === 'true') {
                const row = link.closest('tr');
                if (row) row.remove();
            }
            document.dispatchEvent(new CustomEvent('ajax-page-action-complete', {
                detail: {result, action: 'status', source: link}
            }));
        } catch (error) {
            showMessage(error.message || 'Action could not be completed.', false);
            link.classList.remove('disabled');
            link.removeAttribute('aria-disabled');
        }
    });
})();
