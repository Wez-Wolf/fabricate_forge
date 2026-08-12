/**
 * components/library-fasteners — Fasteners tab (bolts, nuts, washers...).
 * Table: Material (colour badge) | Grade | Size | Type | Unit Cost | Supplier | Price Updated
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
                { label: 'Type', type: 'function', func: function (row) { return self.esc(row.type); } },
                { label: 'Mass', type: 'function', func: function (row) { return '<span class="num">' + self.esc(row.mass) + '</span>'; }, col_cls: 'C_right' },
                { label: 'Unit Cost', type: 'function', func: function (row) { return '<span class="num">' + self.fmt(row.unit_cost) + '</span>'; }, col_cls: 'C_right' },
                { label: 'Supplier', type: 'function', func: function (row) { return self.esc(row.supplier); } },
                { label: 'Price Updated', type: 'function', func: function (row) { return '<span class="num">' + self.esc(row.updated) + '</span>'; }, col_cls: 'C_right' },
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
                    size: (m.name || '').match(/M\d+(?:\.\d+)?/)?.[0] || '—',
                    type: (function () {
                        var n = String(m.name || '').toLowerCase();
                        if (n.indexOf('nut') !== -1) return 'Nut';
                        if (n.indexOf('washer') !== -1) return 'Washer';
                        if (n.indexOf('screw') !== -1) return 'Screw';
                        if (n.indexOf('bolt') !== -1) return 'Bolt';
                        if (n.indexOf('stud') !== -1) return 'Stud';
                        return '—';
                    })(),
                    mass: self.massLabel(m),
                    unit_cost: m.unit_cost,
                    supplier: m.supplier_name || '—',
                    updated: (m.price_updated_at || m.updated_at || '').toString().slice(0, 10) || '—',
                };
            });
        },
        // per-item mass when the fastener row carries one
        massLabel(m) {
            var d = (m.data && typeof m.data === 'object' && !Array.isArray(m.data)) ? m.data : {};
            var kg = d.massKg != null ? d.massKg : (m.mass_kg != null ? m.mass_kg : null);
            return kg != null ? kg + ' kg' : '—';
        },
    },
    methods: {
        badge(m) { return '<span style="display:inline-block;width:0.55rem;height:0.55rem;border-radius:999px;margin-right:0.4rem;vertical-align:middle;box-shadow:0 0 0 1px rgba(255,255,255,0.3);background:#22c55e"></span>'; },
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
