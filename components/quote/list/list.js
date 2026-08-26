/**
 * components/quote/list — quote list (the main table view).
 * Desktop-focused: forge-search filter + status filter + forge-list table.
 * New Quote opens a forge-form popup.
 */
var comp = {
    data() {
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
                { label: 'Quote',   type: 'function', func: function (row) { return '<span class="C_link">' + esc(row[0]) + '</span>'; }, max_width: '28rem' },
                { label: 'Customer', type: 'function', func: function (row) { return esc(row[1]); } },
                { label: 'Status',  type: 'function', func: function (row) { return '<span class="status-pill ' + (row[2] || 'draft') + '">' + (row[2] || 'draft') + '</span>'; } },
                { label: 'Total',   type: 'function', func: function (row) { return '<span class="num">' + esc(row[3]) + '</span>'; }, col_cls: 'C_right' },
                { label: '',        type: 'svg', path: 'trash', cls: 'C_row_trash', title: 'Delete quote' },
                { label: '',        type: 'svg', path: 'arrow_right', cls: 'C_row_arrow' },
            ],
        };
        function esc(s) {
            return FAB.esc(s);
        }
    },
    created() {
        // Restore the user's last filter state so back-navigation returns to
        // the same filtered view (#5), not a blank list.
        var saved = this.restoreFilters();
        if (saved) this.rebuild();
        this.loadQuotes();
        this._ready = true;
    },
    beforeDestroy() {
        this.saveFilters();
    },
    methods: {
        fmtMoney(v, currency) { return FAB.fmtMoney(v, currency || 'USD'); },
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
            var q = (this.search || '').toLowerCase();
            var f = this.statusFilter;
            var filtered = this.all.filter((x) => {
                if (f && x.status !== f) return false;
                if (!q) return true;
                var name = (x.name || '').toLowerCase();
                var cust = ((x.data && x.data.customerName) || '').toLowerCase();
                return name.indexOf(q) !== -1 || cust.indexOf(q) !== -1;
            });
            this.rows = filtered.map((x) => {
                return [
                    x.name || 'Quote',
                    (x.data && x.data.customerName) || '—',
                    x.status || 'draft',
                    this.fmtMoney(x.total_cost, x.data && x.data.currency),
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
        // forge-list svg click — index 4 = trash (delete), index 5 = open
        onSvg(ev) {
            if (!ev || !ev.row) return;
            if (ev.field === 4) {
                this.removeQuote(ev.row);
                return;
            }
            if (ev.row[4]) ROUTER.navigate('/nav/quotes/' + ev.row[4]);
        },
        removeQuote(row) {
            var name = row[0] || 'This quote';
            POPUP.confirm('Delete Quote', 'Delete "' + name + '"?\nThe quote and all its items are removed permanently.', () => {
                this.doRemoveQuote(row);
            });
        },
        async doRemoveQuote(row) {
            try {
                await WEB.api('./api/quotes.php', {
                    action: 'delete',
                    input: { id: row[4] }
                });
                TOAST.show('Quote deleted', 'success');
                this.loadQuotes();
            } catch (e) {
                TOAST.show(e.message || 'Failed to delete quote', 'error');
            }
        },
        onSearch(val) {
            this.search = val || '';
            this.rebuild();
        },
        setStatus(s) {
            this.statusFilter = (this.statusFilter === s) ? '' : s;
            this.rebuild();
        },
        // ── filter-state persistence (#5) ────────────
        // Persist search + status filter to LS so navigating into a quote and
        // back restores the exact filtered view instead of a blank unfiltered list.
        restoreFilters() {
            try {
                var saved = LS.get('fab_quotes_filter_v1');
                if (!saved) return false;
                var p = typeof saved === 'object' ? saved : JSON.parse(saved);
                var changed = false;
                if (p.search) { this.search = p.search; changed = true; }
                if (p.status && this.statuses.indexOf(p.status) !== -1) { this.statusFilter = p.status; changed = true; }
                return changed;
            } catch (e) { return false; }
        },
        saveFilters() {
            try {
                LS.set('fab_quotes_filter_v1', JSON.stringify({
                    search: this.search || '',
                    status: this.statusFilter || '',
                }));
            } catch (e) { /* non-fatal */ }
        },
        openNew() {
            POPUP.show('New Quote', {
                comp: 'quote-form',
                props: {},
                events: {
                    submit: (form) => {
                        this.createQuote(form);
                        POPUP.close();
                    },
                    cancel: () => {
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
                        client_id: form.client_id || null,
                        customer_name: form.customerName || '',
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
