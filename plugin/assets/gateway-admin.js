/* SyndaMorph client access administration; all credentials remain server-side except the once-issued client key. */
(() => {
    'use strict';
    const cfg = window.SyndaMorphGatewayAdmin;
    const root = document.getElementById('syndamorph-gateway-root');
    const status = document.getElementById('syndamorph-gateway-status');
    if (!cfg || !root || !status) return;
    let overview = { customers: [], credentials: [], packages: [], leases: [] };
    let customerId = '';
    let revealedKey = null;
    const element = (tag, value, attrs = {}) => {
        const e = document.createElement(tag);
        if (value !== undefined && value !== null) e.textContent = String(value);
        for (const [key, v] of Object.entries(attrs)) {
            if (key === 'className') e.className = v;
            else if (key === 'type') e.type = v;
            else e.setAttribute(key, v);
        }
        return e;
    };
    const attach = (parent, ...children) => { for (const child of children) parent.append(child); return parent; };
    const button = (label, handler, secondary = false) => {
        const b = element('button', label, { type: 'button', className: secondary ? 'button' : 'button button-primary' });
        b.addEventListener('click', handler);
        return b;
    };
    const section = (title) => {
        const node = element('section', null, { className: 'sm-gateway-panel' });
        attach(node, element('h2', title));
        return node;
    };
    const field = (label, value = '', type = 'text') => {
        const wrapper = element('label', null, { className: 'sm-gateway-field' });
        attach(wrapper, element('span', label));
        const control = element('input', null, { type });
        control.value = value;
        wrapper.append(control);
        return { wrapper, control };
    };
    const select = (items, selected, label) => {
        const wrapper = element('label', null, { className: 'sm-gateway-field' });
        wrapper.append(element('span', label));
        const box = element('select');
        for (const item of items) {
            const opt = element('option', item.label);
            opt.value = item.value;
            if (item.value === selected) opt.selected = true;
            box.append(opt);
        }
        wrapper.append(box);
        return { wrapper, control: box };
    };
    const notice = (message, error = false) => {
        status.replaceChildren(element('p', message, { className: 'notice inline ' + (error ? 'notice-error' : 'notice-success') }));
    };
    async function call(operation, payload = {}) {
        const form = new URLSearchParams({
            action: 'syndamorph_gateway_admin',
            nonce: cfg.nonce,
            operation,
            payload: JSON.stringify(payload),
        });
        const response = await fetch(cfg.ajaxUrl, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: form,
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.data?.error || 'Gateway request failed');
        return result.data;
    }
    async function refresh() {
        try {
            overview = await call('overview');
            if (!overview.customers.some(c => c.id === customerId)) customerId = overview.customers[0]?.id || '';
            render();
        } catch (error) { notice(error.message, true); }
    }
    async function perform(operation, payload, { message = 'Saved', reveal = false } = {}) {
        try {
            const result = await call(operation, payload);
            if (reveal) revealedKey = result.token;
            else revealedKey = null;
            notice(message);
            await refresh();
        } catch (error) { notice(error.message, true); }
    }
    const current = () => overview.customers.find(c => c.id === customerId);
    const row = (...cells) => attach(element('div', null, { className: 'sm-gateway-row' }), ...cells);
    function render() {
        root.replaceChildren();
        const picker = section('Clients');
        const create = field('New client name');
        const createRow = row(create.wrapper, button('Register client', async () => {
            const name = create.control.value.trim();
            if (!name) return notice('Client name is required', true);
            try {
                const result = await call('create_customer', { name });
                customerId = result.id;
                revealedKey = null;
                notice('Client registered');
                await refresh();
            } catch (error) { notice(error.message, true); }
        }));
        picker.append(createRow);
        if (overview.customers.length) {
            const choices = overview.customers.map(c => ({ value: c.id, label: c.name + ' · ' + c.status }));
            const selected = select(choices, customerId, 'Manage client');
            selected.control.addEventListener('change', () => { customerId = selected.control.value; revealedKey = null; render(); });
            picker.append(selected.wrapper);
        }
        root.append(picker);
        const owner = current();
        if (!owner) return;
        const clientPanel = section('Client access');
        attach(clientPanel,
            row(element('strong', owner.name), element('span', 'Status: ' + owner.status)),
            button(owner.status === 'active' ? 'Suspend client' : 'Resume client', () => {
                if (!window.confirm('Change access for ' + owner.name + '?')) return;
                perform('set_customer_status', { customerId, status: owner.status === 'active' ? 'suspended' : 'active' }, { message: 'Client status updated' });
            }, true)
        );
        const keys = overview.credentials.filter(c => c.customerId === customerId);
        clientPanel.append(element('h3', 'API credentials'));
        for (const key of keys) {
            const info = element('span', key.prefix + '… · ' + key.status);
            clientPanel.append(row(info, key.status === 'active'
                ? button('Revoke', () => {
                    if (window.confirm('Revoke this credential? Existing installations using it will lose Gateway access.')) {
                        perform('revoke_key', { customerId, keyId: key.id }, { message: 'Credential revoked' });
                    }
                }, true)
                : element('span', 'Revoked')));
        }
        if (owner.status === 'active') clientPanel.append(button('Issue new client API key', () => {
            if (window.confirm('Generate a new client credential? It will be displayed only once.')) {
                perform('issue_key', { customerId }, { message: 'Key issued. Copy it now.', reveal: true });
            }
        }));
        if (revealedKey) {
            const secret = section('New API key — shown once');
            attach(secret, element('p', 'Copy this credential now. It is not stored in the browser or retrievable from the Gateway.'),
                element('code', revealedKey),
                button('Copy key', () => navigator.clipboard?.writeText(revealedKey).then(() => notice('Key copied')).catch(() => notice('Copy unavailable', true)), true),
                button('Dismiss', () => { revealedKey = null; render(); }, true));
            clientPanel.append(secret);
        }
        root.append(clientPanel);
        const packagesPanel = section('Client packages');
        const addPackage = field('New package name');
        packagesPanel.append(row(addPackage.wrapper, button('Create package', () => {
            if (!addPackage.control.value.trim()) return notice('Package name is required', true);
            perform('create_package', { customerId, name: addPackage.control.value.trim() }, { message: 'Package created' });
        })));
        const packages = overview.packages.filter(p => p.customerId === customerId);
        for (const pkg of packages) {
            const card = section(pkg.name);
            card.append(element('p', pkg.versions.length + ' published version(s) · ' + pkg.id));
            for (const version of pkg.versions) card.append(element('p', 'v' + version.version + ' · ' + version.status));
            const lease = overview.leases.find(l => l.packageId === pkg.id && l.status !== 'revoked');
            if (!lease) {
                const expiry = field('Lease expiry (optional)', '', 'date');
                card.append(row(expiry.wrapper, button('Assign lease', () => {
                    const expiresAt = expiry.control.value ? new Date(expiry.control.value + 'T23:59:59Z').toISOString() : null;
                    perform('create_lease', { customerId, packageId: pkg.id, expiresAt }, { message: 'Package lease assigned' });
                })));
            } else {
                card.append(element('p', 'Lease: ' + lease.effectiveStatus + (lease.expiresAt ? ' · Expires ' + lease.expiresAt.slice(0, 10) : ' · No expiration')));
                const actions = element('div', null, { className: 'sm-gateway-actions' });
                if (lease.status === 'active' || lease.status === 'suspended') {
                    actions.append(button(lease.status === 'active' ? 'Suspend lease' : 'Resume lease', () => perform('update_lease', {
                        customerId, leaseId: lease.id, status: lease.status === 'active' ? 'suspended' : 'active', expiresAt: lease.expiresAt,
                    }, { message: 'Lease updated' }), true));
                    const changeDate = field('Renew / change expiry', lease.expiresAt?.slice(0, 10) || '', 'date');
                    actions.append(changeDate.wrapper);
                    actions.append(button('Save expiry', () => perform('update_lease', {
                        customerId, leaseId: lease.id, status: lease.status,
                        expiresAt: changeDate.control.value ? new Date(changeDate.control.value + 'T23:59:59Z').toISOString() : null,
                    }, { message: 'Lease expiration updated' }), true));
                    actions.append(button('Revoke lease', () => {
                        if (window.confirm('Permanently revoke this lease? Installed content will remain available.')) {
                            perform('update_lease', { customerId, leaseId: lease.id, status: 'revoked', expiresAt: lease.expiresAt }, { message: 'Lease revoked' });
                        }
                    }, true));
                }
                card.append(actions);
            }
            packagesPanel.append(card);
        }
        if (!packages.length) packagesPanel.append(element('p', 'No packages yet.'));
        root.append(packagesPanel);
    }
    if (cfg.configured) {
        const style = element('style');
        style.textContent = '#syndamorph-gateway-root{max-width:1040px;display:grid;gap:18px;margin-top:18px}.sm-gateway-panel{background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:18px;display:grid;gap:12px}.sm-gateway-panel h2,.sm-gateway-panel h3{margin:0}.sm-gateway-field{display:grid;gap:5px;min-width:200px;flex:1}.sm-gateway-field input,.sm-gateway-field select{width:100%;max-width:400px}.sm-gateway-row,.sm-gateway-actions{display:flex;flex-wrap:wrap;align-items:end;gap:12px}.sm-gateway-panel code{overflow-wrap:anywhere;padding:10px;background:#f6f7f7}.sm-gateway-panel p{margin:0}';
        root.before(style);
        refresh();
    }
})();
