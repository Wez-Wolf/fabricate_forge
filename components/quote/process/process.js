/**
 * components/quote/process — per-entity process tab.
 * Receives entities + costs from the shell (loaded once with the quote) and
 * renders the summary cards + per-entity process hours grid.
 */
var comp = {
    mixins: [COMP.base],
    props: ['entities', 'costs', 'quote'],
    data() {
        return {
        };
    },
    computed: {
        // Aggregate process HOURS across leaf entities (boilermaking + welding +
        // machining — the three trades the cost engine tracks by hour). Assemblies
        // are containers: their OWN hour columns are 0 (children carry the work),
        // so summing all entities already avoids double-counting.
        hours() {
            var total = 0;
            (this.entities || []).forEach((e) => {
                var c = (e.cost && e.cost) || {};
                if (e.type === 'assembly') return;   // rollup rows, not leaf work
                total += parseFloat(c.boilerHrs) || 0;
                total += parseFloat(c.weldHrs) || 0;
                total += parseFloat(c.machHrs) || 0;
            });
            return Math.round(total * 100) / 100;
        },
        // leaf process cost (money) — assemblies roll up children, don't re-add
        total() {
            var t = 0;
            (this.entities || []).forEach((e) => {
                if (e.type === 'assembly') return;
                t += parseFloat((e.cost && e.cost.processTotal) || 0);
            });
            return t;
        },
        rows() {
            return (this.entities || []).map((e) => [
                e.name || 'Item',
                this.fmtHrs(this.rowHours(e, 'boilerHrs')),
                this.fmtHrs(this.rowHours(e, 'weldHrs')),
                this.fmtHrs(this.rowHours(e, 'machHrs')),
                this.fmtMoney(e.type === 'assembly' ? (e.cost && e.cost.rolled_columns && e.cost.rolled_columns.processTotal) : (e.cost && e.cost.processTotal)),
            ]);
        },
        fields() {
            return [
                { label: 'Item', col_cls: 'C_strong' },
                { label: 'BM (h)', col_cls: 'C_right' },
                { label: 'W (h)', col_cls: 'C_right' },
                { label: 'M (h)', col_cls: 'C_right' },
                { label: 'Process Cost', col_cls: 'C_right' },
            ];
        },
    },
    methods: {
        // Hours for a grid row: assemblies show their ROLLED (children) hours so
        // the container row is meaningful; leaves show their own.
        rowHours(e, key) {
            var c = (e && e.cost) || {};
            if (e.type === 'assembly' && c.rolled_columns && c.rolled_columns[key] != null) {
                return parseFloat(c.rolled_columns[key]) || 0;
            }
            return parseFloat(c[key]) || 0;
        },
        fmtHrs(v) {
            var n = parseFloat(v || 0);
            return (Math.round(n * 100) / 100).toFixed(2);
        },
        fmtMoney(v) { return FAB.fmtMoney(v, (this.quote && this.quote.data && this.quote.data.currency) || 'USD'); },
    },
};
