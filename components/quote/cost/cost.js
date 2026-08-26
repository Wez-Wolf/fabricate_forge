/**
 * components/quote/cost — the "How much does it cost" tab.
 * Rolled-up quote totals (material / process / on-costs / margin / grand
 * total + mass + trade hours) plus a per-entity cost breakdown.
 * Data comes from the shell's `totals` map (systems.overview persisted sums)
 * and `entities`/`costs` (per-entity cost comp) — reads only.
 */
var comp = {
    mixins: [COMP.base],
    props: ['quoteId', 'quote', 'entities', 'costs', 'totals', 'marginPercent'],
    components: {
        'help-tooltip': function (resolve) { COMP.externLoadComponent(resolve, null, 'help-tooltip'); },
    },
    computed: {
        currency() {
            return (this.quote && this.quote.data && this.quote.data.currency) || 'USD';
        },
        onCostsTotal() {
            var t = this.totals || {};
            return ['consumables', 'services', 'ndt', 'lining', 'paint', 'transport']
                .reduce(function (s, k) { return s + (parseFloat(t[k]) || 0); }, 0);
        },
        onCostRows() {
            var t = this.totals || {};
            return [
                { label: 'Consumables', value: t.consumables },
                { label: 'Services', value: t.services },
                { label: 'NDT', value: t.ndt },
                { label: 'Lining', value: t.lining },
                { label: 'Paint', value: t.paint },
                { label: 'Transport', value: t.transport },
            ];
        },
        rows() {
            var self = this;
            return (this.entities || []).map(function (e) {
                var c = self.costs[e.id] || {};
                return {
                    id: e.id,
                    name: e.name || 'Item',
                    type: e.type || '',
                    material: parseFloat(c.material) || 0,
                    process: parseFloat(c.processTotal) || 0,
                    total: parseFloat(c.total) || 0,
                };
            });
        },
    },
    methods: {
        money(v) {
            if (v == null || isNaN(v)) return '—';
            return (this.currency === 'ZAR' ? 'R' : '$') + Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
        hours(v) {
            if (v == null || isNaN(v)) return '—';
            return Number(v).toLocaleString(undefined, { maximumFractionDigits: 1 }) + ' h';
        },
        mass(v) {
            if (v == null || isNaN(v)) return '—';
            return Number(v).toLocaleString(undefined, { maximumFractionDigits: 1 }) + ' kg';
        },
    },
};
