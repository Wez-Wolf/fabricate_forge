/**
 * components/library-plates — Plates & Sheets tab.
 * Table: Material (colour badge) | Grade | Thickness | Size | Mass | Unit Cost | Supplier | Price Updated
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
                { label: 'Thickness', type: 'function', func: function (row) { return '<span class="num">' + self.esc(row.thk) + '</span>'; }, col_cls: 'C_right' },
                { label: 'Size', type: 'function', func: function (row) { return self.esc(row.size); } },
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
                var d = (m.data && typeof m.data === 'object' && !Array.isArray(m.data)) ? m.data : {};
                var t = m.thickness != null ? m.thickness : (d.wt != null ? d.wt : null);
                var dens = m.density != null ? m.density : null;
                // kg/m² = thickness(mm) × density(kg/m³) / 1000; fall back to any stored mass
                var mass = m.mass_per_area != null ? m.mass_per_area + ' kg/m²'
                    : (t != null && dens ? (t * dens / 1000).toFixed(2) + ' kg/m²' : '—');
                return {
                    _m: m,
                    name: m.name || '—',
                    grade: m.grade || '—',
                    thk: t != null ? t + ' mm' : '—',
                    size: (m.name || '').match(/\d+(?:\.\d+)?\s*x\s*\d+(?:\.\d+)?(?:\s*x\s*\d+(?:\.\d+)?)?/)?.[0] || '—',
                    mass: mass,
                    unit_cost: m.unit_cost,
                    supplier: m.supplier_name || '—',
                    updated: (m.price_updated_at || m.updated_at || '').toString().slice(0, 10) || '—',
                };
            });
        },
    },
    methods: {
        badge(m) { return '<span style="display:inline-block;width:0.55rem;height:0.55rem;border-radius:999px;margin-right:0.4rem;vertical-align:middle;box-shadow:0 0 0 1px rgba(255,255,255,0.3);background:' + this.color(m) + '"></span>'; },
        color(m) {
            var prof = String(m.profile || '').toLowerCase();
            return (prof === 'sheet' ? '#60a5fa' : '#3b82f6');
        },
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
