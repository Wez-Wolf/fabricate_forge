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
            clients: [],        // for the New Quote client select
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
        this.loadClients();
        this.loadQuotes();
        this._ready = true;
    },
    methods: {
        // Load clients for the New Quote client select (forge-option map)
        async loadClients() {
            try {
                var res = await WEB.api('./api/clients.php', { action: 'list', input: { limit: 200 } });
                this.clients = (res && res.data) || res || [];
            } catch (e) { /* optional */ }
        },
        clientOptions() {
            var opts = {};
            (this.clients || []).forEach(function (c) {
                opts[c.id] = c.company_name || c.id;
            });
            return opts;
        },
        fmtMoney(v, currency) {
            try {
                return new Intl.NumberFormat('en-US', { style: 'currency', currency: currency || 'USD' }).format(parseFloat(v || 0));
            } catch (e) {
                return '$' + parseFloat(v || 0).toLocaleString();
            }
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
                    self.fmtMoney(x.total_cost, x.data && x.data.currency),
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
                comp: 'quoteform',
                props: {},
                events: {
                    submit: function (form) {
                        self.createQuote(form);
                        POPUP.close();
                    },
                    cancel: function () {
                        POPUP.close();
                    },
                },
            });
        },
        async createQuote(form) {
            try {
                // If a client was selected, use its name as the customer
                var selectedClient = null;
                var cid = form.client_id;
                if (cid) {
                    for (var i = 0; i < this.clients.length; i++) {
                        if (this.clients[i].id === cid) { selectedClient = this.clients[i]; break; }
                    }
                }
                await WEB.api('./api/quotes.php', {
                    action: 'create',
                    input: {
                        name: form.name,
                        client_id: cid || null,
                        customer_name: form.customerName || (selectedClient ? selectedClient.company_name : ''),
                        customer_email: selectedClient ? selectedClient.email : '',
                        currency: form.currency || 'USD',
                        due_date: form.dueDate,
                        margin_percent: form.margin != null && form.margin !== '' ? parseFloat(form.margin) : undefined,
                    }
                });
                this.loadQuotes();
            } catch (e) {
                TOAST.show(e.message || 'Failed to create quote', 'error');
            }
        },
    },
};
