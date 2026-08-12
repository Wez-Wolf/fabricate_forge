/**
 * components/library-flanges — Flanges tab.
 * Table: Material (colour badge) | Grade | Type | DN | Rating | Mass | Unit Cost | Supplier
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
                { label: 'Type', type: 'function', func: function (row) { return self.esc(row.type); } },
                { label: 'DN', type: 'function', func: function (row) { return self.esc(row.dn); } },
                { label: 'Rating', type: 'function', func: function (row) { return self.esc(row.rating); } },
                { label: 'Mass', type: 'function', func: function (row) { return '<span class="num">' + self.esc(row.mass) + '</span>'; }, col_cls: 'C_right' },
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
                    type: d.type || '—',
                    dn: d.dn != null ? 'DN' + d.dn : '—',
                    rating: d.rating || d.schedule || '—',
                    mass: d.massKg != null ? d.massKg + ' kg' : '—',
                    unit_cost: m.unit_cost,
                    supplier: m.supplier_name || '—',
                };
            });
        },
    },
    methods: {
        badge(m) { return '<span style="display:inline-block;width:0.55rem;height:0.55rem;border-radius:999px;margin-right:0.4rem;vertical-align:middle;box-shadow:0 0 0 1px rgba(255,255,255,0.3);background:#ec4899"></span>'; },
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
