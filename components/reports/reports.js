/**
 * components/reports — quote reports.
 * Summary of all quotes (status, totals) + export options.
 */
var comp = {
    data() {
        var self = this;
        return {
            quotes: [],
            loading: false,
            error: '',
            margin: { avgMarginPercent: null, totalQuoteValue: 0, totalEstimatedMargin: 0, effectiveMarginRate: 0, quoteCount: 0 },
            byClient: [],
            monthly: [],
            byTrade: { trades: [], total: 0 },
            fields: [
                { label: 'Quote', type: 'function', func: function (row) { return '<span class="C_link">' + esc(row[0]) + '</span>'; } },
                { label: 'Customer', type: 'function', func: function (row) { return esc(row[1]); } },
                { label: 'Status', type: 'function', func: function (row) { return '<span class="status-pill ' + (row[2] || 'draft') + '">' + (row[2] || 'draft') + '</span>'; } },
                { label: 'Total', type: 'function', func: function (row) { return '<span class="num">' + esc(row[3]) + '</span>'; }, col_cls: 'C_right' },
                { label: '', type: 'svg', path: 'file-down', cls: 'C_export_icon' },
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
    computed: {
        totals() {
            var self = this;
            return {
                count: this.quotes.length,
                pipeline: this.quotes
                    .filter(function (q) { return q.status === 'submitted' || q.status === 'approved'; })
                    .reduce(function (s, q) { return s + self.num(q.total_cost); }, 0),
                revenue: this.quotes
                    .filter(function (q) { return q.status === 'invoiced'; })
                    .reduce(function (s, q) { return s + self.num(q.total_cost); }, 0),
            };
        },
    },
    methods: {
        num(v) { return parseFloat(v || 0); },
        fmtMoney(v) {
            return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(this.num(v));
        },
        async load() {
            this.loading = true;
            this.error = '';
            try {
                var res = await WEB.api('./api/systems.php', { action: 'list_quotes', input: { limit: 200 } });
                this.quotes = (res && res.data) || res || [];
                this.rebuild();
                this.loadAnalytics();
            } catch (e) {
                this.error = e.message || 'Failed to load reports';
            } finally {
                this.loading = false;
            }
        },
        async loadAnalytics() {
            try {
                var self = this;
                var calls = [
                    WEB.api('./api/reports.php', { action: 'margin_summary', input: {} }),
                    WEB.api('./api/reports.php', { action: 'cost_by_client', input: {} }),
                    WEB.api('./api/reports.php', { action: 'monthly_summary', input: {} }),
                    WEB.api('./api/reports.php', { action: 'cost_by_trade', input: {} }),
                ];
                var results = await Promise.all(calls);
                this.margin = (results[0] && results[0].data) || results[0] || this.margin;
                this.byClient = (results[1] && results[1].data) || results[1] || [];
                this.monthly = (results[2] && results[2].data) || results[2] || [];
                this.byTrade = (results[3] && results[3].data) || results[3] || { trades: [], total: 0 };
            } catch (e) { /* reports are best-effort */ }
        },
        rebuild() {
            var self = this;
            this.rows = (this.quotes || []).map(function (q) {
                return [
                    q.name || 'Quote',
                    (q.data && q.data.customerName) || '—',
                    q.status || 'draft',
                    self.fmtMoney(q.total_cost),
                    q.id,
                ];
            });
        },
        onFetch(request) { /* single load in created() */ },
        onSvg(ev) {
            if (ev && ev.row && ev.row[4]) this.exportPdf(ev.row[4]);
        },
        async exportPdf(quoteId) {
            try {
                var res = await WEB.api('./api/quotes.php', {
                    action: 'export_pdf',
                    input: { quote_id: quoteId },
                });
                if (res && res.html) {
                    var win = window.open('', '_blank');
                    if (win) { win.document.write(res.html); win.document.close(); win.focus(); }
                }
            } catch (e) {
                TOAST.show(e.message || 'Failed to export PDF', 'error');
            }
        },
    },
};
