/**
 * components/clients — client/customer management.
 * forge-list table + New Client forge-form popup.
 */
var comp = {
    data() {
        var self = this;
        return {
            rows: [],           // forge-list rows: [company, contact, email, city, id]
            all: [],
            search: '',
            loading: false,
            error: '',
            fields: [
                { label: 'Company', type: 'function', func: function (row) { return '<span class="C_link">' + esc(row[0]) + '</span>'; } },
                { label: 'Contact', type: 'function', func: function (row) { return esc(row[1]); } },
                { label: 'Email', type: 'function', func: function (row) { return esc(row[2]); } },
                { label: 'Location', type: 'function', func: function (row) { return esc(row[3]); } },
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
                var res = await WEB.api('./api/clients.php', { action: 'list', input: { limit: 200 } });
                this.all = (res && res.data) || res || [];
                this.rebuild();
            } catch (e) {
                this.error = e.message || 'Failed to load clients';
            } finally {
                this.loading = false;
            }
        },
        rebuild() {
            var self = this;
            var q = (this.search || '').toLowerCase();
            var filtered = (this.all || []).filter(function (c) {
                if (!q) return true;
                return (c.company_name || '').toLowerCase().indexOf(q) !== -1 ||
                       (c.primary_contact || '').toLowerCase().indexOf(q) !== -1;
            });
            this.rows = filtered.map(function (c) {
                return [
                    c.company_name || '—',
                    c.primary_contact || '—',
                    c.email || '—',
                    [c.city, c.country].filter(Boolean).join(', ') || '—',
                    c.id,
                ];
            });
        },
        onFetch(request) { /* single load in created() */ },
        onSearch(val) {
            this.search = val || '';
            this.rebuild();
        },
        openNew() {
            var self = this;
            POPUP.show('New Client', {
                comp: 'forge-form',
                props: {
                    fields: {
                        company_name: { label: 'Company Name', placeholder: 'e.g. Acme Fabrication', required: true },
                        primary_contact: { label: 'Primary Contact', placeholder: 'Contact name' },
                        email: { label: 'Email', type: 'email', placeholder: 'contact@company.com' },
                        phone: { label: 'Phone', placeholder: '+27 ...' },
                        city: { label: 'City', placeholder: 'City' },
                        country: { label: 'Country', placeholder: 'ZA' },
                    },
                    button_label: 'Add Client',
                },
                events: {
                    submit: function (form) {
                        self.createClient(form);
                        POPUP.close();
                    },
                },
            });
        },
        async createClient(form) {
            try {
                await WEB.api('./api/clients.php', { action: 'create', input: form });
                TOAST.show('Client added', 'success');
                this.load();
            } catch (e) {
                TOAST.show(e.message || 'Failed to add client', 'error');
            }
        },
    },
};
