/**
 * components/library-pipe — Pipe tab.
 * Table: Material (colour badge) | Grade | DN | OD | WT | Sched | Mass/m | Unit Cost | Supplier
 * Emits: { edit: material }
 */
var comp = {
    props: ['items'],
    computed: {
        fields() {
            var self = this;
            return [
                { label: 'Material', type: 'function', func: function (row) { return self.badge(row._m) + '<span class="C_link">' + self.esc(row.name) + '</span>'; } },
                { label: 'Grade', type: 'function', func: function (row) { return self.esc(row.grade); } },
                { label: 'DN', type: 'function', func: function (row) { return self.esc(row.dn); } },
                { label: 'OD mm', type: 'function', func: function (row) { return '<span class="num">' + self.esc(row.od) + '</span>'; }, col_cls: 'C_right' },
                { label: 'WT mm', type: 'function', func: function (row) { return '<span class="num">' + self.esc(row.wt) + '</span>'; }, col_cls: 'C_right' },
                { label: 'Sched', type: 'function', func: function (row) { return self.esc(row.sched); } },
                { label: 'Mass/m', type: 'function', func: function (row) { return '<span class="num">' + self.esc(row.mass) + '</span>'; }, col_cls: 'C_right' },
                { label: 'Unit Cost', type: 'function', func: function (row) { return '<span class="num">' + self.fmt(row.unit_cost) + '</span>'; }, col_cls: 'C_right' },
                { label: 'Supplier', type: 'function', func: function (row) { return self.esc(row.supplier); } },
                { label: '', type: 'svg', path: 'pencil', cls: 'C_edit_icon' },
            ];
        },
        rows() {
            var self = this;
            return (this.items || []).map(function (m) {
                var d = (m.data && typeof m.data === 'object' && !Array.isArray(m.data)) ? m.data : {};
                return {
                    _m: m,
                    name: m.name || '—',
                    grade: m.grade || '—',
                    dn: d.dn != null ? 'DN' + d.dn : '—',
                    od: d.od != null ? d.od : '—',
                    wt: d.wt != null ? d.wt : '—',
                    sched: d.schedule || '—',
                    mass: m.mass_per_meter != null ? m.mass_per_meter + ' kg/m' : '—',
                    unit_cost: m.unit_cost,
                    supplier: m.supplier_name || '—',
                };
            });
        },
    },
    methods: {
        badge(m) { return '<span style="display:inline-block;width:0.55rem;height:0.55rem;border-radius:999px;margin-right:0.4rem;vertical-align:middle;box-shadow:0 0 0 1px rgba(255,255,255,0.3);background:#14b8a6"></span>'; },
        esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); },
        fmt(v) {
            try { return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(parseFloat(v || 0)); }
            catch (e) { return String(v || 0); }
        },
        noop() {},
        onSelect(row) { if (row && row._m) this.$emit('edit', row._m); },
        onSvg(ev) { if (ev && ev.row && ev.row._m) this.$emit('edit', ev.row._m); },
    },
};
