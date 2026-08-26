/**
 * components/suppliers — material supplier management.
 * forge-list table + New/Edit Supplier forge-form popup.
 * Suppliers are referenced by material library rows (supplier_id) so the
 * take-off can split by supplier and show "last priced by X".
 */
var comp = {
    data() {
        var self = this;
        return {
            rows: [],           // forge-list rows: [company, contact, materials, email, id]
            all: [],
            search: '',
            loading: false,
            error: '',
            fields: [
                { label: 'Company', type: 'function', func: function (row) { return '<span class="C_link">' + esc(row[0]) + '</span>'; } },
                { label: 'Contact', type: 'function', func: function (row) { return esc(row[1]); } },
                { label: 'Materials', type: 'function', func: function (row) { return esc(row[2]); } },
                { label: 'Email', type: 'function', func: function (row) { return esc(row[3]); } },
                { label: '', type: 'svg', path: 'pencil', cls: 'C_edit_icon' },
            ],
        };
        function esc(s) {
            return FAB.esc(s);
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
                var res = await WEB.api('./api/suppliers.php', { action: 'list', input: { limit: 200 } });
                this.all = (res && res.data) || res || [];
                this.rebuild();
            } catch (e) {
                this.error = e.message || 'Failed to load suppliers';
            } finally {
                this.loading = false;
            }
        },
        rebuild() {
            var self = this;
            var q = (this.search || '').toLowerCase();
            var filtered = (this.all || []).filter(function (s) {
                if (!q) return true;
                return (s.company_name || '').toLowerCase().indexOf(q) !== -1 ||
                       (s.primary_contact || '').toLowerCase().indexOf(q) !== -1 ||
                       (s.materials_supplied || '').toLowerCase().indexOf(q) !== -1;
            });
            this.rows = filtered.map(function (s) {
                return [
                    s.company_name || '—',
                    s.primary_contact || '—',
                    s.materials_supplied || '—',
                    s.email || '—',
                    s.id,
                    s, // extra slots ignored by forge-list, used by handlers
                ];
            });
        },
        onFetch(request) { /* single load in created() */ },
        onSearch(val) {
            this.search = val || '';
            this.rebuild();
        },
        onSelect(row) {
            if (row && row[4]) this.edit(row[5] || row[4]);
        },
        onSvg(ev) {
            if (ev && ev.row && ev.row[4]) this.edit(ev.row[5] || ev.row[4]);
        },
        openNew() {
            this.edit(null);
        },
        edit(sup) {
            var self = this;
            var isNew = !sup;
            sup = sup || {};
            POPUP.show(isNew ? 'New Supplier' : 'Edit Supplier', {
                comp: 'forge-form',
                props: {
                    fields: {
                        company_name: { label: 'Company Name', placeholder: 'e.g. Steel Merchant (Pty) Ltd', required: true, default: sup.company_name || '' },
                        primary_contact: { label: 'Primary Contact', placeholder: 'Contact name', default: sup.primary_contact || '' },
                        email: { label: 'Email', type: 'email', placeholder: 'sales@supplier.com', default: sup.email || '' },
                        phone: { label: 'Phone', placeholder: '+27 ...', default: sup.phone || '' },
                        city: { label: 'City', placeholder: 'City', default: sup.city || '' },
                        country: { label: 'Country', placeholder: 'ZA', default: sup.country || '' },
                        materials_supplied: { label: 'Materials Supplied', placeholder: 'e.g. Plates & Sheets, Fasteners', default: sup.materials_supplied || '' },
                        lead_time_days: { label: 'Lead Time (days)', type: 'number', default: sup.lead_time_days != null ? sup.lead_time_days : '' },
                        payment_terms: { label: 'Payment Terms', placeholder: 'e.g. Net 30', default: sup.payment_terms || '' },
                        notes: { label: 'Notes', type: 'textarea', rows: 3, default: sup.notes || '' },
                    },
                    button_label: isNew ? 'Add Supplier' : 'Save Supplier',
                },
                events: {
                    submit: function (form) {
                        if (isNew) {
                            self.createSupplier(form);
                        } else {
                            self.updateSupplier(sup.id, form);
                        }
                        POPUP.close();
                    },
                },
            });
        },
        async createSupplier(form) {
            try {
                await WEB.api('./api/suppliers.php', { action: 'create', input: form });
                TOAST.show('Supplier added', 'success');
                this.load();
            } catch (e) {
                TOAST.show(e.message || 'Failed to add supplier', 'error');
            }
        },
        async updateSupplier(id, form) {
            try {
                await WEB.api('./api/suppliers.php', { action: 'update', input: Object.assign({ id: id }, form) });
                TOAST.show('Supplier updated', 'success');
                this.load();
            } catch (e) {
                TOAST.show(e.message || 'Failed to update supplier', 'error');
            }
        },
    },
};
