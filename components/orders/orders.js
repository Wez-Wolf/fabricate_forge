/**
 * components/orders — order management.
 * forge-list table + status filter chips + New Order forge-form popup.
 * Row status is a dropdown (forge-select) so transitions stay server-validated.
 */
var comp = {
    data() {
        var self = this;
        return {
            rows: [],           // [title, status, total, delivery, id]
            all: [],
            quotes: [],         // for the New Order quote select
            search: '',
            statusFilter: '',
            loading: false,
            error: '',
            statuses: ['draft', 'sent', 'won', 'order', 'in-progress', 'complete', 'lost'],
            fields: [
                { label: 'Order',  type: 'function', func: function (row) { return '<span class="C_link">' + esc(row[0]) + '</span>'; } },
                { label: 'Status', type: 'function', func: function (row) { return '<span class="status-pill ' + (row[1] || 'draft') + '">' + esc(row[1] || 'draft') + '</span>'; } },
                { label: 'Total',  type: 'function', func: function (row) { return '<span class="num">' + esc(row[2]) + '</span>'; }, col_cls: 'C_right' },
                { label: 'Delivery', type: 'function', func: function (row) { return esc(row[3]); } },
                { label: '', type: 'svg', path: 'pencil', cls: 'C_edit_icon' },
            ],
        };
        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }
    },
    created() {
        this.load();
    },
    methods: {
        async load() {
            this.loading = true;
            this.error = '';
            try {
                var res = await WEB.api('./api/orders.php', { action: 'list', input: { limit: 200 } });
                this.all = (res && res.data) || res || [];
                this.loadQuotes();
                this.rebuild();
            } catch (e) {
                this.error = e.message || 'Failed to load orders';
            } finally {
                this.loading = false;
            }
        },
        async loadQuotes() {
            try {
                var res = await WEB.api('./api/systems.php', { action: 'list_quotes', input: { limit: 100 } });
                this.quotes = (res && res.data) || res || [];
            } catch (e) { /* optional */ }
        },
        quoteOptions() {
            var opts = {};
            (this.quotes || []).forEach(function (q) {
                opts[q.id] = q.name || q.id;
            });
            return opts;
        },
        fmtMoney(v) {
            return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(parseFloat(v || 0));
        },
        rebuild() {
            var self = this;
            var q = (this.search || '').toLowerCase();
            var f = this.statusFilter;
            var filtered = (this.all || []).filter(function (x) {
                if (f && x.status !== f) return false;
                if (!q) return true;
                return (x.title || '').toLowerCase().indexOf(q) !== -1 ||
                       (x.description || '').toLowerCase().indexOf(q) !== -1;
            });
            this.rows = filtered.map(function (x) {
                return [
                    x.title || 'Order',
                    x.status || 'draft',
                    self.fmtMoney(x.total_value),
                    x.delivery_date || '—',
                    x.id,
                    x,
                ];
            });
        },
        onFetch(request) { /* single load in created() */ },
        onSearch(val) {
            this.search = val || '';
            this.rebuild();
        },
        setStatus(s) {
            this.statusFilter = (this.statusFilter === s) ? '' : s;
            this.rebuild();
        },
        // pencil → edit popup with status select
        onSvg(ev) {
            if (ev && ev.row && ev.row[5]) this.editOrder(ev.row[5]);
        },
        onSelect(row) {
            if (row && row[5]) this.editOrder(row[5]);
        },
        openNew() {
            var self = this;
            POPUP.show('New Order', {
                comp: 'forge-form',
                props: {
                    fields: {
                        title: { label: 'Title', placeholder: 'e.g. Skid Frame Order', required: true },
                        quote_id: { label: 'Quote', type: 'option', options: self.quoteOptions() },
                        total_value: { label: 'Total Value', type: 'number', placeholder: '0.00' },
                        delivery_date: { label: 'Delivery Date', type: 'date' },
                        notes: { label: 'Notes', type: 'textarea', rows: 2 },
                    },
                    button_label: 'Create Order',
                },
                events: {
                    submit: function (form) {
                        self.createOrder(form);
                        POPUP.close();
                    },
                },
            });
        },
        async createOrder(form) {
            try {
                await WEB.api('./api/orders.php', { action: 'create', input: form });
                TOAST.show('Order created', 'success');
                this.load();
            } catch (e) {
                TOAST.show(e.message || 'Failed to create order', 'error');
            }
        },
        editOrder(order) {
            var self = this;
            POPUP.show(order.title || 'Order', {
                comp: 'forge-form',
                props: {
                    fields: {
                        title: { label: 'Title', required: true },
                        status: {
                            label: 'Status', type: 'option',
                            options: (function () {
                                var o = {};
                                self.statuses.forEach(function (s) { o[s] = s; });
                                return o;
                            })(),
                        },
                        total_value: { label: 'Total Value', type: 'number' },
                        delivery_date: { label: 'Delivery Date', type: 'date' },
                        notes: { label: 'Notes', type: 'textarea', rows: 3 },
                    },
                    button_label: 'Save',
                    value: {
                        title: order.title || '',
                        status: order.status || 'draft',
                        total_value: parseFloat(order.total_value || 0),
                        delivery_date: order.delivery_date ? String(order.delivery_date).slice(0, 10) : '',
                        notes: order.notes || '',
                    },
                },
                events: {
                    submit: function (form) {
                        self.saveOrder(order.id, form);
                        POPUP.close();
                    },
                },
            });
        },
        async saveOrder(id, form) {
            try {
                var payload = {
                    id: id,
                    title: form.title,
                    total_value: form.total_value,
                    delivery_date: form.delivery_date,
                    notes: form.notes,
                };
                if (form.status) payload.status = form.status;
                await WEB.api('./api/orders.php', { action: 'update', input: payload });
                TOAST.show('Order updated', 'success');
                this.load();
            } catch (e) {
                TOAST.show(e.message || 'Failed to update order', 'error');
            }
        },
    },
};
