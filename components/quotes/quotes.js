/**
 * components/quotes — quote list (the main table view).
 * Desktop-focused: forge-search filter + status filter + forge-list table.
 * New Quote opens a forge-form popup.
 */
var comp = {
    data() {
        var self = this;
        return {
            rows: [],           // forge-list rows: [name, customer, status, total, id]
            all: [],            // raw quotes for search/filter
            search: '',
            statusFilter: '',
            loading: false,
            error: '',
            statuses: ['draft', 'submitted', 'approved', 'invoiced', 'rejected'],
            // NOTE: field.func is invoked by forge-list WITHOUT `this` binding,
            // so escape via a local helper closure, never `this.esc`.
            fields: [
                { label: 'Quote',   type: 'function', func: function (row) { return '<span class="C_link">' + esc(row[0]) + '</span>'; } },
                { label: 'Customer', type: 'function', func: function (row) { return esc(row[1]); } },
                { label: 'Status',  type: 'function', func: function (row) { return '<span class="status-pill ' + (row[2] || 'draft') + '">' + (row[2] || 'draft') + '</span>'; } },
                { label: 'Total',   type: 'function', func: function (row) { return '<span class="num">' + esc(row[3]) + '</span>'; }, col_cls: 'C_right' },
                { label: '',        type: 'svg', path: 'arrow_right', cls: 'C_row_arrow' },
            ],
        };
        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }
    },
    created() {
        this.loadQuotes();
        this._ready = true;
    },
    methods: {
        fmtMoney(v) {
            return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(parseFloat(v || 0));
        },
        async loadQuotes() {
            this.loading = true;
            this.error = '';
            try {
                var res = await WEB.api('./api/systems.php', {
                    action: 'list_quotes',
                    input: { limit: 200 }
                });
                this.all = (res && res.data) || res || [];
                this.rebuild();
            } catch (e) {
                this.error = e.message || 'Failed to load quotes';
            } finally {
                this.loading = false;
            }
        },
        rebuild() {
            var self = this;
            var q = (self.search || '').toLowerCase();
            var f = self.statusFilter;
            var filtered = self.all.filter(function (x) {
                if (f && x.status !== f) return false;
                if (!q) return true;
                var name = (x.name || '').toLowerCase();
                var cust = ((x.data && x.data.customerName) || '').toLowerCase();
                return name.indexOf(q) !== -1 || cust.indexOf(q) !== -1;
            });
            this.rows = filtered.map(function (x) {
                return [
                    x.name || 'Quote',
                    (x.data && x.data.customerName) || '—',
                    x.status || 'draft',
                    self.fmtMoney(x.total_cost),
                    x.id,
                    x, // extra slots ignored by forge-list, used by handlers
                ];
            });
        },
        // forge-list fetch hook — forge-list emits onFetch on mount + paging.
        // created() already loads; skip the immediate mount emit (same tick)
        // to avoid a double-fetch race, but honor paging/search-driven emits.
        onFetch(request) {
            // forge-list emits onFetch on mount; created() already loads.
            // NOOP here — :value drives the table, avoids the double-load race.
        },
        // forge-list select — row is the array; id at index 4
        onSelect(row) {
            if (row && row[4]) ROUTER.navigate('/nav/quotes/' + row[4]);
        },
        // forge-list svg click (arrow) — open the quote
        onSvg(ev) {
            if (ev && ev.row && ev.row[4]) ROUTER.navigate('/nav/quotes/' + ev.row[4]);
        },
        onSearch(val) {
            this.search = val || '';
            this.rebuild();
        },
        setStatus(s) {
            this.statusFilter = (this.statusFilter === s) ? '' : s;
            this.rebuild();
        },
        openNew() {
            var self = this;
            POPUP.show('New Quote', {
                comp: 'forge-form',
                props: {
                    fields: {
                        name: { label: 'Quote Name', placeholder: 'e.g. Skid Frame Build', required: true },
                        customerName: { label: 'Customer', placeholder: 'Customer name' },
                        currency: { label: 'Currency', type: 'select', options: ['USD', 'EUR', 'GBP', 'ZAR'] },
                        dueDate: { label: 'Due Date', type: 'date' },
                    },
                    button_label: 'Create Quote',
                },
                events: {
                    submit: function (form) {
                        self.createQuote(form);
                        POPUP.close();
                    },
                },
            });
        },
        async createQuote(form) {
            try {
                await WEB.api('./api/quotes.php', {
                    action: 'create',
                    input: {
                        name: form.name,
                        customer_name: form.customerName,
                        currency: form.currency || 'USD',
                        due_date: form.dueDate,
                    }
                });
                this.loadQuotes();
            } catch (e) {
                TOAST.show(e.message || 'Failed to create quote', 'error');
            }
        },
    },
};
