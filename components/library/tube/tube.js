/**
 * components/library-tube — Tube tab (CHS/SHS/RHS).
 * Table: Material (colour badge) | Grade | Size | Mass/m | Unit Cost | Supplier
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
                { label: 'Size', type: 'function', func: function (row) { return self.esc(row.size); } },
                { label: 'Mass/m', type: 'function', func: function (row) { return '<span class="num">' + self.esc(row.mass) + '</span>'; }, col_cls: 'C_right' },
                { label: 'Unit Cost', type: 'function', func: function (row) { return '<span class="num">' + self.fmt(row.unit_cost) + '</span>'; }, col_cls: 'C_right' },
                { label: 'Supplier', type: 'function', func: function (row) { return self.esc(row.supplier); } },
                { label: '', type: 'svg', path: 'pencil', cls: 'C_edit_icon' },
            ];
        },
        rows() {
            var self = this;
            return (this.items || []).map(function (m) {
                return {
                    _m: m,
                    name: m.name || '—',
                    grade: m.grade || '—',
                    size: (m.name || '').match(/\d+(?:\.\d+)?\s*x\s*\d+(?:\.\d+)?(?:\s*x\s*\d+(?:\.\d+)?)?/)?.[0] || '—',
                    mass: m.mass_per_meter != null ? m.mass_per_meter + ' kg/m' : '—',
                    unit_cost: m.unit_cost,
                    supplier: m.supplier_name || '—',
                };
            });
        },
    },
    methods: {
        badge(m) { return '<span style="display:inline-block;width:0.55rem;height:0.55rem;border-radius:999px;margin-right:0.4rem;vertical-align:middle;box-shadow:0 0 0 1px rgba(255,255,255,0.3);background:#06b6d4"></span>'; },
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
