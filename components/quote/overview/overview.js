/**
 * components/quote/overview — Quote Stats tab.
 * Rolled-up totals only: money (material/process/on-costs/margin/grand total),
 * mass, hours (boilermaking/welding/machining/labor), on-costs detail, and a
 * per-type / per-material-category breakdown. No item list — the Entities,
 * Tree and BOM tabs own the line items.
 *
 * Data: the shell's `totals` map (from systems.overview — persisted per-column
 * sums incl. massKg) + entities/costs for the type + category breakdowns.
 */
var comp = {
    mixins: [COMP.base],
    props: ['quoteId', 'quote', 'entities', 'costs', 'totals', 'marginPercent'],
    computed: {
        currency() {
            return (this.quote && this.quote.data && this.quote.data.currency) || 'USD';
        },
        status() {
            return (this.quote && this.quote.data && this.quote.data.status) || 'draft';
        },
        validity() {
            return (this.quote && this.quote.data && this.quote.data.validityDays) || null;
        },
        entityCount() {
            return (this.entities || []).length;
        },
        // Σ of the on-cost columns (consumables → transport)
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
        // Count + cost share per entity type
        typeRows() {
            var self = this;
            var byType = {};
            (this.entities || []).forEach(function (e) {
                if (!byType[e.type]) byType[e.type] = { count: 0, cost: 0 };
                byType[e.type].count++;
                byType[e.type].cost += parseFloat((self.costs[e.id] || {}).total) || 0;
            });
            var out = [];
            Object.keys(byType).forEach(function (t) {
                out.push({ label: t + ' (' + byType[t].count + ')', value: byType[t].cost });
            });
            out.sort(function (a, b) { return b.value - a.value; });
            return out;
        },
        // Material cost grouped by the material's library category
        materialGroupRows() {
            var self = this;
            var groups = {};
            (this.entities || []).forEach(function (e) {
                var comps = (e.components || []).concat([{ type: 'material', data: (self.costs[e.id] || {}).materials || null }]);
                var mat = null;
                for (var i = 0; i < comps.length; i++) {
                    if (comps[i] && comps[i].type === 'material') { mat = comps[i]; break; }
                }
                if (!mat) return;
                var cat = (mat.data && mat.data.category) || 'other';
                groups[cat] = (groups[cat] || 0) + (parseFloat((self.costs[e.id] || {}).material) || 0);
            });
            var out = Object.keys(groups).map(function (c) { return { label: c, value: groups[c] }; });
            out.sort(function (a, b) { return b.value - a.value; });
            return out;
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
