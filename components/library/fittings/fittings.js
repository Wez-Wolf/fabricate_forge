/**
 * components/library-fittings — Fittings tab (elbows, tees, reducers...).
 * Table: Material (colour badge) | Grade | Type | Size/DN | OD ends | Mass | Weld size | Unit Cost | Supplier
 * Emits: { edit: material }
 */
function uniqueJoin(arr) {
    if (!Array.isArray(arr)) return arr != null ? String(arr) : '—';
    var uniq = [];
    for (var i = 0; i < arr.length; i++) {
        if (arr[i] != null && uniq.indexOf(arr[i]) === -1) uniq.push(arr[i]);
    }
    return uniq.length ? uniq.join('×') : '—';
}
var WELD_SIZES = [3, 4, 5, 6, 8, 10, 12, 16, 20, 25, 30, 35, 40, 45, 50];
function weldSizeFor(wt) {
    if (wt == null || wt <= 0) return '—';
    for (var i = 0; i < WELD_SIZES.length; i++) { if (wt <= WELD_SIZES[i]) return WELD_SIZES[i]; }
    return WELD_SIZES[WELD_SIZES.length - 1];
}

var comp = {
    props: ['items'],
    computed: {
        fields() {
            var self = this;
            return [
                { label: 'Material', type: 'function', func: function (row) { return self.badge(row._m) + '<span class="C_link">' + self.esc(row.name) + '</span>'; } },
                { label: 'Grade', type: 'function', func: function (row) { return self.esc(row.grade); } },
                { label: 'Type', type: 'function', func: function (row) { return self.esc(row.type); } },
                { label: 'Size / DN', type: 'function', func: function (row) { return self.esc(row.size); } },
                { label: 'OD ends', type: 'function', func: function (row) { return self.esc(row.od); } },
                { label: 'Mass', type: 'function', func: function (row) { return '<span class="num">' + self.esc(row.mass) + '</span>'; }, col_cls: 'C_right' },
                { label: 'Weld', type: 'function', func: function (row) { return '<span class="num">' + self.esc(row.weld) + '</span>'; }, col_cls: 'C_right' },
                { label: 'Unit Cost', type: 'function', func: function (row) { return '<span class="num">' + self.fmt(row.unit_cost) + '</span>'; }, col_cls: 'C_right' },
                { label: 'Supplier', type: 'function', func: function (row) { return self.esc(row.supplier); } },
                { label: '', type: 'svg', path: 'pencil', cls: 'C_edit_icon' },
            ];
        },
        rows() {
            var self = this;
            return (this.items || []).map(function (m) {
                var d = (m.data && typeof m.data === 'object' && !Array.isArray(m.data)) ? m.data : {};
                var od = d.od && Array.isArray(d.od) ? uniqueJoin(d.od)
                    : (d.od != null ? d.od : (d.pipeOd != null ? d.pipeOd : '—'));
                var wtRef = Array.isArray(d.wt) ? (d.wt[0] != null ? d.wt[0] : null) : (d.wt != null ? d.wt : null);
                return {
                    _m: m,
                    name: m.name || '—',
                    grade: m.grade || '—',
                    type: d.type || '—',
                    size: d.catalogueSize != null ? String(d.catalogueSize).replace(/\s*x\s*/g, '×') : (d.dn != null ? 'DN' + d.dn : '—'),
                    od: od,
                    mass: d.massKg != null ? d.massKg + ' kg' : '—',
                    weld: weldSizeFor(wtRef),
                    unit_cost: m.unit_cost,
                    supplier: m.supplier_name || '—',
                };
            });
        },
    },
    methods: {
        badge(m) { return '<span style="display:inline-block;width:0.55rem;height:0.55rem;border-radius:999px;margin-right:0.4rem;vertical-align:middle;box-shadow:0 0 0 1px rgba(255,255,255,0.3);background:#8b5cf6"></span>'; },
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
