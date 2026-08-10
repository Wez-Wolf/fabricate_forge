/**
 * components/dashboard — quote stats overview.
 * Desktop cards: pipeline value, revenue, win rate, recent quotes.
 */
var comp = {
    name: 'dashboard',
    data() {
        return {
            quotes: [],
            loading: true,
            error: '',
            currency: 'USD',
        };
    },
    created() {
        this.loadPrefs();
        this.loadQuotes();
    },
    computed: {
        stats() {
            var q = this.quotes;
            return {
                draft: q.filter(function(x) { return x.status === 'draft'; }).length,
                submitted: q.filter(function(x) { return x.status === 'submitted'; }).length,
                approved: q.filter(function(x) { return x.status === 'approved'; }).length,
                invoiced: q.filter(function(x) { return x.status === 'invoiced'; }).length,
            };
        },
        // v-for data: status count cards
        statusCards() {
            var s = this.stats;
            return [
                { key: 'draft', label: 'Draft', value: s.draft },
                { key: 'submitted', label: 'Submitted', value: s.submitted },
                { key: 'approved', label: 'Approved', value: s.approved },
                { key: 'invoiced', label: 'Invoiced', value: s.invoiced },
            ];
        },
        // v-for data: financial cards
        finCards() {
            return [
                { label: 'Pipeline Value', value: this.fmtMoney(this.pipelineValue), hint: 'Submitted + Approved' },
                { label: 'Revenue', value: this.fmtMoney(this.revenue), hint: 'Invoiced', valueCls: 'C_green' },
                { label: 'Win Rate', value: this.winRate + '%', hint: 'Approved / Decided' },
            ];
        },
        pipelineValue() {
            var self = this;
            return this.quotes
                .filter(function(q) { return q.status === 'submitted' || q.status === 'approved'; })
                .reduce(function(sum, q) { return sum + self.num(q.total_cost); }, 0);
        },
        revenue() {
            var self = this;
            return this.quotes
                .filter(function(q) { return q.status === 'invoiced'; })
                .reduce(function(sum, q) { return sum + self.num(q.total_cost); }, 0);
        },
        winRate() {
            var decided = this.stats.approved + this.quotes.filter(function(q) { return q.status === 'rejected'; }).length;
            if (!decided) return 0;
            return Math.round((this.stats.approved / decided) * 100);
        },
        recentQuotes() {
            return this.quotes.slice(0, 5);
        },
    },
    methods: {
        num(v) { return parseFloat(v || 0); },
        fmtMoney(v) {
            try {
                return new Intl.NumberFormat('en-US', { style: 'currency', currency: this.currency }).format(this.num(v));
            } catch (e) {
                return '$' + this.num(v).toLocaleString();
            }
        },
        // Load user's preferred currency from Settings (user prefs)
        async loadPrefs() {
            try {
                var res = await WEB.api('./api/user.php', { action: 'get_preferences', input: {} });
                var data = (res && res.data) || res || {};
                if (data.defaultCurrency) this.currency = data.defaultCurrency;
            } catch (e) { /* keep USD default */ }
        },
        async loadQuotes() {
            this.loading = true;
            this.error = '';
            try {
                var res = await WEB.api('./api/systems.php', {
                    action: 'list_quotes',
                    input: { limit: 100 }
                });
                this.quotes = (res && res.data) || res || [];
            } catch (e) {
                this.error = e.message || 'Failed to load quotes';
            } finally {
                this.loading = false;
            }
        },
        openQuote(id) {
            ROUTER.navigate('/nav/quotes/' + (id || ''));
        },
        goQuotes() {
            ROUTER.navigate('/nav/quotes');
        },
    },
};
