/**
 * components/procurement — purchase orders / supplier quotes / received goods.
 * forge-tabs + forge-list per tab + POPUP create forms.
 */
var comp = {
    data() {
        var self = this;
        return {
            activeTab: 'pos',
            tabs: [
                { key: 'pos', tag: 'pos', name: 'Purchase Orders' },
                { key: 'sqs', tag: 'sqs', name: 'Supplier Quotes' },
                { key: 'rgs', tag: 'rgs', name: 'Received Goods' },
            ],
            error: '',
            poStatuses: ['draft', 'quoted', 'ordered', 'received'],
            poFilter: '',
            poRows: [], poAll: [], loadingPos: false,
            sqRows: [], loadingSqs: false,
            rgRows: [], loadingRgs: false,
            pos: [], sqs: [], rgs: [], // raw rows for reference
            poFields: [
                { label: 'Supplier', type: 'function', func: function (row) { return '<span class="C_link">' + esc(row[0]) + '</span>'; } },
                { label: 'Status', type: 'function', func: function (row) { return '<span class="status-pill ' + (row[1] || 'draft') + '">' + esc(row[1] || 'draft') + '</span>'; } },
                { label: 'Total', type: 'function', func: function (row) { return '<span class="num">' + esc(row[2]) + '</span>'; }, col_cls: 'C_right' },
                { label: 'Expected', type: 'function', func: function (row) { return esc(row[3]); } },
                { label: '', type: 'svg', path: 'pencil', cls: 'C_edit_icon' },
            ],
            sqFields: [
                { label: 'Supplier', type: 'function', func: function (row) { return '<span class="C_link">' + esc(row[0]) + '</span>'; } },
                { label: 'Unit Price', type: 'function', func: function (row) { return '<span class="num">' + esc(row[1]) + '</span>'; }, col_cls: 'C_right' },
                { label: 'Min Order Qty', type: 'function', func: function (row) { return esc(row[2]); } },
                { label: 'Lead Time', type: 'function', func: function (row) { return esc(row[3]); } },
                { label: 'Valid Until', type: 'function', func: function (row) { return esc(row[4]); } },
            ],
            rgFields: [
                { label: 'PO', type: 'function', func: function (row) { return esc(row[0]); } },
                { label: 'Items', type: 'function', func: function (row) { return esc(row[1]); } },
                { label: 'Received', type: 'function', func: function (row) { return esc(row[2]); } },
            ],
        };
        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }
    },
    created() {
        this.loadAll();
    },
    methods: {
        async loadAll() {
            await Promise.all([this.loadPos(), this.loadSqs(), this.loadRgs()]);
        },
        onTab(t) { this.activeTab = t; },
        fmtMoney(v) {
            return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(parseFloat(v || 0));
        },
        fmtDate(d) {
            if (!d) return '—';
            var s = String(d).slice(0, 10);
            return s || '—';
        },
        // ── Purchase Orders ──
        async loadPos() {
            this.loadingPos = true;
            try {
                var res = await WEB.api('./api/procurement.php', { action: 'po_list', input: { limit: 200 } });
                this.poAll = (res && res.data) || res || [];
                this.rebuildPos();
            } catch (e) {
                this.error = e.message || 'Failed to load purchase orders';
            } finally {
                this.loadingPos = false;
            }
        },
        rebuildPos() {
            var self = this;
            var f = this.poFilter;
            var filtered = this.poAll.filter(function (x) { return !f || x.status === f; });
            this.poRows = filtered.map(function (x) {
                return [
                    x.supplier_name || '—',
                    x.status || 'draft',
                    self.fmtMoney(x.total_value),
                    self.fmtDate(x.expected_date),
                    x.id,
                    x,
                ];
            });
        },
        setPoFilter(s) {
            this.poFilter = (this.poFilter === s) ? '' : s;
            this.rebuildPos();
        },
        onPoSvg(ev) {
            if (ev && ev.row && ev.row[5]) this.editPo(ev.row[5]);
        },
        openPo() {
            var self = this;
            POPUP.show('New Purchase Order', {
                comp: 'forge-form',
                props: {
                    fields: {
                        supplier_name: { label: 'Supplier Name', placeholder: 'e.g. SteelCo', required: true },
                        total_value: { label: 'Total Value', type: 'number', placeholder: '0.00' },
                        expected_date: { label: 'Expected Date', type: 'date' },
                        notes: { label: 'Notes', type: 'textarea', rows: 2 },
                    },
                    button_label: 'Create PO',
                },
                events: {
                    submit: function (form) {
                        self.createPo(form);
                        POPUP.close();
                    },
                },
            });
        },
        async createPo(form) {
            try {
                await WEB.api('./api/procurement.php', { action: 'po_create', input: form });
                TOAST.show('Purchase order created', 'success');
                this.loadPos();
            } catch (e) {
                TOAST.show(e.message || 'Failed to create PO', 'error');
            }
        },
        editPo(po) {
            var self = this;
            POPUP.show(po.supplier_name || 'Purchase Order', {
                comp: 'forge-form',
                props: {
                    fields: {
                        supplier_name: { label: 'Supplier Name', required: true },
                        status: {
                            label: 'Status', type: 'option',
                            options: (function () {
                                var o = {};
                                self.poStatuses.forEach(function (s) { o[s] = s; });
                                return o;
                            })(),
                        },
                        total_value: { label: 'Total Value', type: 'number' },
                        expected_date: { label: 'Expected Date', type: 'date' },
                        notes: { label: 'Notes', type: 'textarea', rows: 3 },
                    },
                    button_label: 'Save',
                    value: {
                        supplier_name: po.supplier_name || '',
                        status: po.status || 'draft',
                        total_value: parseFloat(po.total_value || 0),
                        expected_date: po.expected_date ? String(po.expected_date).slice(0, 10) : '',
                        notes: po.notes || '',
                    },
                },
                events: {
                    submit: function (form) {
                        self.savePo(po.id, form);
                        POPUP.close();
                    },
                },
            });
        },
        async savePo(id, form) {
            try {
                var payload = {
                    id: id,
                    supplier_name: form.supplier_name,
                    total_value: form.total_value,
                    expected_date: form.expected_date,
                    notes: form.notes,
                };
                if (form.status) payload.status = form.status;
                await WEB.api('./api/procurement.php', { action: 'po_update', input: payload });
                TOAST.show('Purchase order updated', 'success');
                this.loadPos();
            } catch (e) {
                TOAST.show(e.message || 'Failed to update PO', 'error');
            }
        },
        // ── Supplier Quotes ──
        async loadSqs() {
            this.loadingSqs = true;
            try {
                var res = await WEB.api('./api/procurement.php', { action: 'sq_list', input: { limit: 200 } });
                this.sqs = (res && res.data) || res || [];
                var self = this;
                this.sqRows = this.sqs.map(function (x) {
                    return [
                        x.supplier_name || '—',
                        self.fmtMoney(x.unit_price),
                        String(x.min_order_qty),
                        (x.lead_time_days != null ? x.lead_time_days + ' days' : '—'),
                        self.fmtDate(x.valid_until),
                    ];
                });
            } catch (e) {
                this.error = e.message || 'Failed to load supplier quotes';
            } finally {
                this.loadingSqs = false;
            }
        },
        openSq() {
            var self = this;
            POPUP.show('New Supplier Quote', {
                comp: 'forge-form',
                props: {
                    fields: {
                        supplier_name: { label: 'Supplier Name', placeholder: 'e.g. FastenerWholesale', required: true },
                        unit_price: { label: 'Unit Price', type: 'number', placeholder: '0.00' },
                        min_order_qty: { label: 'Min Order Qty', type: 'number', placeholder: '1' },
                        lead_time_days: { label: 'Lead Time (days)', type: 'number', placeholder: '14' },
                        valid_until: { label: 'Valid Until', type: 'date' },
                        notes: { label: 'Notes', type: 'textarea', rows: 2 },
                    },
                    button_label: 'Add Quote',
                },
                events: {
                    submit: function (form) {
                        self.createSq(form);
                        POPUP.close();
                    },
                },
            });
        },
        async createSq(form) {
            try {
                await WEB.api('./api/procurement.php', { action: 'sq_create', input: form });
                TOAST.show('Supplier quote added', 'success');
                this.loadSqs();
            } catch (e) {
                TOAST.show(e.message || 'Failed to add supplier quote', 'error');
            }
        },
        // ── Received Goods ──
        async loadRgs() {
            this.loadingRgs = true;
            try {
                var res = await WEB.api('./api/procurement.php', { action: 'rg_list', input: { limit: 200 } });
                this.rgs = (res && res.data) || res || [];
                var self = this;
                this.rgRows = this.rgs.map(function (x) {
                    var items = (x.items || []).length;
                    return [
                        x.purchase_order_id ? String(x.purchase_order_id).slice(0, 8) : '—',
                        items + ' item' + (items === 1 ? '' : 's'),
                        self.fmtDate(x.received_date),
                    ];
                });
            } catch (e) {
                this.error = e.message || 'Failed to load received goods';
            } finally {
                this.loadingRgs = false;
            }
        },
        openRg() {
            var self = this;
            POPUP.show('Log Received Goods', {
                comp: 'forge-form',
                props: {
                    fields: {
                        purchase_order_id: { label: 'Purchase Order ID' },
                        notes: { label: 'Notes', type: 'textarea', rows: 2 },
                    },
                    button_label: 'Log Receipt',
                },
                events: {
                    submit: function (form) {
                        self.createRg(form);
                        POPUP.close();
                    },
                },
            });
        },
        async createRg(form) {
            try {
                var input = { notes: form.notes, items: [] };
                if (form.purchase_order_id) input.purchase_order_id = form.purchase_order_id;
                await WEB.api('./api/procurement.php', { action: 'rg_create', input: input });
                TOAST.show('Receipt logged', 'success');
                this.loadRgs();
            } catch (e) {
                TOAST.show(e.message || 'Failed to log receipt', 'error');
            }
        },
    },
};
