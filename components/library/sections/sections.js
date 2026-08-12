/**
 * components/library-sections — Sections & Bars tab.
 * Table: Material (colour badge) | Grade | Profile | Size | Mass/m | Unit Cost | Supplier
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
                { label: 'Profile', type: 'function', func: function (row) { return self.esc(row.profile); } },
                { label: 'Size', type: 'function', func: function (row) { return self.esc(row.size); } },
                { label: 'Mass', type: 'function', func: function (row) { return '<span class="num">' + self.esc(row.mass) + '</span>'; }, col_cls: 'C_right' },
                { label: 'Unit Cost', type: 'function', func: function (row) { return '<span class="num">' + self.fmt(row.unit_cost) + '</span>'; }, col_cls: 'C_right' },
                { label: 'Supplier', type: 'function', func: function (row) { return self.esc(row.supplier); } },
                { label: '', type: 'svg', path: 'pencil', cls: 'C_edit_icon' },
            ];
        },
        rows() {
            var self = this;
            return (this.items || []).map(function (m) {
                var prof = String(m.profile || '').toLowerCase();
                var dens = m.density != null ? m.density : 7850;
                var mass = m.mass_per_meter != null ? m.mass_per_meter + ' kg/m' : self.barMass(m, prof, dens);
                return {
                    _m: m,
                    name: m.name || '—',
                    grade: m.grade || '—',
                    profile: m.profile || '—',
                    size: (m.name || '').match(/\d+(?:\.\d+)?\s*x\s*\d+(?:\.\d+)?(?:\s*x\s*\d+(?:\.\d+)?)?/)?.[0] || '—',
                    mass: mass,
                    unit_cost: m.unit_cost,
                    supplier: m.supplier_name || '—',
                };
            });
        },
    },
    methods: {
        // Compute kg/m for bars from name dims when mass_per_meter is missing:
        //   flat bar W×T, round bar Ø, square bar S (density default 7850).
        barMass(m, prof, dens) {
            var name = String(m.name || '');
            var nums = (name.match(/\d+(?:\.\d+)?/g) || []).map(parseFloat);
            if (!nums.length) return '—';
            var kg;
            if (prof.indexOf('flat') !== -1 && nums.length >= 2) {
                kg = nums[0] * nums[1] / 1e6 * dens;          // W(mm)×T(mm) → m² × ρ
            } else if (prof.indexOf('round') !== -1) {
                kg = Math.PI / 4 * Math.pow(nums[0] / 1000, 2) * dens;  // π/4 D² × ρ
            } else if (prof.indexOf('square') !== -1) {
                kg = Math.pow(nums[0] / 1000, 2) * dens;       // S² × ρ
            } else {
                return '—';
            }
            return kg.toFixed(2) + ' kg/m';
        },
        color(m) {
            var p = String(m.profile || '').toLowerCase();
            var map = { angle: '#f59e0b', channel: '#fbbf24', 'i-beam': '#ef4444', 'h-beam': '#dc2626', 'flat bar': '#d97706', 'round bar': '#f97316', 'square bar': '#fb923c' };
            return map[p] || '#f97316';
        },
        badge(m) { return '<span style="display:inline-block;width:0.55rem;height:0.55rem;border-radius:999px;margin-right:0.4rem;vertical-align:middle;box-shadow:0 0 0 1px rgba(255,255,255,0.3);background:' + this.color(m) + '"></span>'; },
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
