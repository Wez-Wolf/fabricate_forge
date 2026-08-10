/**
 * components/quote-detail — single quote view.
 * Loads via systems.load_quote (one call): quote + entities + costs.
 * Tabs: Overview (cost breakdown) | BOM (entity table) | Process.
 */
var comp = {
    mixins: [COMP.base],
    props: ['tab_url'],
    data() {
        return {
            quoteId: '',
            quote: null,
            entities: [],
            costs: {},
            totalCost: 0,
            loading: false,
            error: '',
            activeTab: 'overview',
            tabs: [
                { key: 'overview', tag: 'overview', name: 'Overview', svg: 'layout-dashboard' },
                { key: 'bom',      tag: 'bom',      name: 'BOM',      svg: 'list' },
                { key: 'process',  tag: 'process',  name: 'Process',  svg: 'timer' },
            ],
            // forge-tabs may drive via v-model; fall back to click handler
            selectedTab: 'overview',
        };
    },
    created() {
        // quoteId comes from the route: /nav/quotes/<id> → tab_url = quotes/<id>
        var parts = (this.tab_url || '').split('/');
        this.quoteId = parts[1] || parts[0] || '';
        this.load();
    },
    computed: {
        currency() {
            return (this.quote && this.quote.data && this.quote.data.currency) || 'USD';
        },
        status() {
            return (this.quote && this.quote.data && this.quote.data.status) || 'draft';
        },
        statusHistory() {
            return (this.quote && this.quote.data && this.quote.data.statusHistory) || [];
        },
        // aggregate process hours across entities
        processTotal() {
            var self = this;
            return this.entities.reduce(function (sum, e) {
                var c = self.costs[e.id] || {};
                return sum + (parseFloat(c.processTotal) || 0);
            }, 0);
        },
        materialTotal() {
            var self = this;
            return this.entities.reduce(function (sum, e) {
                var c = self.costs[e.id] || {};
                return sum + (parseFloat(c.material) || 0);
            }, 0);
        },
        // v-for data: overview summary cards
        overviewCards() {
            return [
                { label: 'Material', value: this.fmtMoney(this.materialTotal), cls: '' },
                { label: 'Process', value: this.fmtMoney(this.processTotal), cls: '' },
                { label: 'Grand Total', value: this.fmtMoney(this.totalCost), cls: 'C_card_total' },
            ];
        },
    },
    methods: {
        fmtMoney(v) {
            try {
                return new Intl.NumberFormat('en-US', { style: 'currency', currency: this.currency }).format(parseFloat(v || 0));
            } catch (e) {
                return String(v || 0);
            }
        },
        esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        },
        async load() {
            if (!this.quoteId) return;
            this.loading = true;
            this.error = '';
            try {
                var res = await WEB.api('./api/systems.php', {
                    action: 'load_quote',
                    input: { quote_id: this.quoteId }
                });
                this.quote = res.quote || null;
                this.entities = (res.entities || []).map(function (e) {
                    return { id: e.id, name: e.name, type: e.type, quantity: e.quantity, cost: e.cost || {} };
                });
                this.costs = res.costs || {};
                this.totalCost = res.total_cost || 0;
            } catch (e) {
                this.error = e.message || 'Failed to load quote';
            } finally {
                this.loading = false;
            }
        },
        setTab(tag) {
            this.activeTab = tag;
        },
        onTabSelect(tag) {
            this.setTab(tag);
        },
        goBack() {
            ROUTER.navigate('/nav/quotes');
        },
        async changeStatus(status) {
            try {
                await WEB.api('./api/quotes.php', {
                    action: 'update_status',
                    input: { quote_id: this.quoteId, status: status }
                });
                this.load();
            } catch (e) {
                TOAST.show(e.message || 'Failed to update status', 'error');
            }
        },
        async exportPdf() {
            try {
                var res = await WEB.api('./api/quotes.php', {
                    action: 'export_pdf',
                    input: { quote_id: this.quoteId }
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
