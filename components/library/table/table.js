/**
 * components/library-table — generic material table for the library shell.
 *
 * Replaces the 7 former per-type tables (plates, sections, pipe, tube,
 * fittings, flanges, fasteners) with one component. `kind` selects the column
 * set + row mapping; badge, esc, fmt and emit wiring are shared once.
 *
 * Emits: { edit: material }
 */

// Weld size rule mirrors api/weldmodel.php: next size UP from actual WT.
var WELD_SIZES = [3, 4, 5, 6, 8, 10, 12, 16, 20, 25, 30, 35, 40, 45, 50];
function weldSizeFor(wt) {
    if (wt == null || wt <= 0) return '—';
    for (var i = 0; i < WELD_SIZES.length; i++) { if (wt <= WELD_SIZES[i]) return WELD_SIZES[i]; }
    return WELD_SIZES[WELD_SIZES.length - 1];
}
function uniqueJoin(arr) {
    if (!Array.isArray(arr)) return arr != null ? String(arr) : '—';
    var uniq = [];
    for (var i = 0; i < arr.length; i++) {
        if (arr[i] != null && uniq.indexOf(arr[i]) === -1) uniq.push(arr[i]);
    }
    return uniq.length ? uniq.join('×') : '—';
}

// Single source of truth for material colour badges.
function matColor(m) {
    var prof = String(m.profile || '').toLowerCase();
    var map = {
        plate: '#3b82f6', sheet: '#60a5fa', angle: '#f59e0b', channel: '#fbbf24',
        'i-beam': '#ef4444', 'h-beam': '#dc2626', 'flat bar': '#d97706',
        'round bar': '#f97316', 'square bar': '#fb923c', pipe: '#14b8a6',
        tube: '#06b6d4', fitting: '#8b5cf6', flange: '#ec4899', fastener: '#22c55e',
    };
    if (map[prof]) return map[prof];
    return map[String(m.library_category || '').toLowerCase()] || '#94a3b8';
}

function dims(m) { return (m.data && typeof m.data === 'object' && !Array.isArray(m.data)) ? m.data : {}; }
function sizeFromName(m) { return (m.name || '').match(/\d+(?:\.\d+)?\s*x\s*\d+(?:\.\d+)?(?:\s*x\s*\d+(?:\.\d+)?)?/)?.[0] || '—'; }

var comp = {
    props: ['items', 'kind'],
    computed: {
        fields() {
            var self = this;
            function mat() { return { label: 'Material', type: 'function', func: function (row) { return self.badge(row._m) + '<span class="C_link">' + self.esc(row.name) + '</span>'; } }; }
            function esc(label, key) { return { label: label, type: 'function', func: function (row) { return self.esc(row[key]); } }; }
            function num(label, key) { return { label: label, type: 'function', func: function (row) { return '<span class="num">' + self.esc(row[key]) + '</span>'; }, col_cls: 'C_right' }; }
            function money() { return { label: 'Unit Cost', type: 'function', func: function (row) { return '<span class="num">' + self.fmt(row.unit_cost) + '</span>'; }, col_cls: 'C_right' }; }
            function pencil() { return { label: '', type: 'svg', path: 'pencil', cls: 'C_edit_icon' }; }

            var k = this.kind;
            var cols = [mat(), esc('Grade', 'grade')];
            if (k === 'plates')       cols.push(num('Thickness', 'thk'), esc('Size', 'size'), num('Mass', 'mass'));
            else if (k === 'sections') cols.push(esc('Profile', 'profile'), esc('Size', 'size'), num('Mass', 'mass'));
            else if (k === 'pipe')    cols.push(esc('DN', 'dn'), num('OD mm', 'od'), num('WT mm', 'wt'), esc('Sched', 'sched'), num('Mass/m', 'mass'));
            else if (k === 'tube')    cols.push(esc('Size', 'size'), num('Mass/m', 'mass'));
            else if (k === 'fittings') cols.push(esc('Type', 'type'), esc('Size / DN', 'size'), esc('OD ends', 'od'), num('Mass', 'mass'), num('Weld', 'weld'));
            else if (k === 'flanges') cols.push(esc('Type', 'type'), esc('DN', 'dn'), esc('Rating', 'rating'), num('Mass', 'mass'));
            else if (k === 'fasteners') cols.push(esc('Size', 'size'), esc('Type', 'type'), num('Mass', 'mass'));

            cols.push(money(), esc('Supplier', 'supplier'));
            if (k === 'plates' || k === 'fasteners') cols.push(num('Price Updated', 'updated'));
            cols.push(pencil());
            return cols;
        },
        rows() {
            var self = this;
            var k = this.kind;
            return (this.items || []).map(function (m) {
                if (k === 'plates') return self.plateRow(m);
                if (k === 'sections') return self.sectionRow(m);
                if (k === 'pipe') return self.pipeRow(m);
                if (k === 'tube') return self.tubeRow(m);
                if (k === 'fittings') return self.fittingRow(m);
                if (k === 'flanges') return self.flangeRow(m);
                if (k === 'fasteners') return self.fastenerRow(m);
                return { _m: m, name: m.name || '—', grade: m.grade || '—', unit_cost: m.unit_cost, supplier: m.supplier_name || '—' };
            });
        },
    },
    methods: {
        plateRow(m) {
            var d = dims(m);
            var t = m.thickness != null ? m.thickness : (d.wt != null ? d.wt : null);
            var dens = m.density != null ? m.density : null;
            var mass = m.mass_per_area != null ? m.mass_per_area + ' kg/m²'
                : (t != null && dens ? (t * dens / 1000).toFixed(2) + ' kg/m²' : '—');
            return {
                _m: m, name: m.name || '—', grade: m.grade || '—',
                thk: t != null ? t + ' mm' : '—', size: sizeFromName(m), mass: mass,
                unit_cost: m.unit_cost, supplier: m.supplier_name || '—',
                updated: (m.price_updated_at || m.updated_at || '').toString().slice(0, 10) || '—',
            };
        },
        sectionRow(m) {
            var prof = String(m.profile || '').toLowerCase();
            var dens = m.density != null ? m.density : 7850;
            var mass = m.mass_per_meter != null ? m.mass_per_meter + ' kg/m' : this.barMass(m, prof, dens);
            return {
                _m: m, name: m.name || '—', grade: m.grade || '—', profile: m.profile || '—',
                size: sizeFromName(m), mass: mass, unit_cost: m.unit_cost, supplier: m.supplier_name || '—',
            };
        },
        pipeRow(m) {
            var d = dims(m);
            return {
                _m: m, name: m.name || '—', grade: m.grade || '—', dn: d.dn != null ? 'DN' + d.dn : '—',
                od: d.od != null ? d.od : '—', wt: d.wt != null ? d.wt : '—', sched: d.schedule || '—',
                mass: m.mass_per_meter != null ? m.mass_per_meter + ' kg/m' : '—',
                unit_cost: m.unit_cost, supplier: m.supplier_name || '—',
            };
        },
        tubeRow(m) {
            return {
                _m: m, name: m.name || '—', grade: m.grade || '—', size: sizeFromName(m),
                mass: m.mass_per_meter != null ? m.mass_per_meter + ' kg/m' : '—',
                unit_cost: m.unit_cost, supplier: m.supplier_name || '—',
            };
        },
        fittingRow(m) {
            var d = dims(m);
            var od = d.od && Array.isArray(d.od) ? uniqueJoin(d.od) : (d.od != null ? d.od : (d.pipeOd != null ? d.pipeOd : '—'));
            var wtRef = Array.isArray(d.wt) ? (d.wt[0] != null ? d.wt[0] : null) : (d.wt != null ? d.wt : null);
            return {
                _m: m, name: m.name || '—', grade: m.grade || '—', type: d.type || '—',
                size: d.catalogueSize != null ? String(d.catalogueSize).replace(/\s*x\s*/g, '×') : (d.dn != null ? 'DN' + d.dn : '—'),
                od: od, mass: d.massKg != null ? d.massKg + ' kg' : '—', weld: weldSizeFor(wtRef),
                unit_cost: m.unit_cost, supplier: m.supplier_name || '—',
            };
        },
        flangeRow(m) {
            var d = dims(m);
            return {
                _m: m, name: m.name || '—', grade: m.grade || '—', type: d.type || '—',
                dn: d.dn != null ? 'DN' + d.dn : '—', rating: d.rating || d.schedule || '—',
                mass: d.massKg != null ? d.massKg + ' kg' : '—',
                unit_cost: m.unit_cost, supplier: m.supplier_name || '—',
            };
        },
        fastenerRow(m) {
            var d = dims(m);
            var kg = d.massKg != null ? d.massKg : (m.mass_kg != null ? m.mass_kg : null);
            return {
                _m: m, name: m.name || '—', grade: m.grade || '—',
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
                mass: kg != null ? kg + ' kg' : '—',
                unit_cost: m.unit_cost, supplier: m.supplier_name || '—',
                updated: (m.price_updated_at || m.updated_at || '').toString().slice(0, 10) || '—',
            };
        },
        // Compute kg/m for bars from name dims when mass_per_meter is missing:
        //   flat bar W×T, round bar Ø, square bar S (density default 7850).
        barMass(m, prof, dens) {
            var name = String(m.name || '');
            var nums = (name.match(/\d+(?:\.\d+)?/g) || []).map(parseFloat);
            if (!nums.length) return '—';
            var kg;
            if (prof.indexOf('flat') !== -1 && nums.length >= 2) {
                kg = nums[0] * nums[1] / 1e6 * dens;
            } else if (prof.indexOf('round') !== -1) {
                kg = Math.PI / 4 * Math.pow(nums[0] / 1000, 2) * dens;
            } else if (prof.indexOf('square') !== -1) {
                kg = Math.pow(nums[0] / 1000, 2) * dens;
            } else {
                return '—';
            }
            return kg.toFixed(2) + ' kg/m';
        },
        badge(m) {
            return '<span style="display:inline-block;width:0.55rem;height:0.55rem;border-radius:999px;margin-right:0.4rem;vertical-align:middle;box-shadow:0 0 0 1px rgba(255,255,255,0.3);background:' + matColor(m) + '"></span>';
        },
        esc(s) { return FAB.esc(s); },
        fmt(v) { return FAB.fmtMoney(v); },
        noop() {},
        onSelect(row) { if (row && row._m) this.$emit('edit', row._m); },
        onSvg(ev) { if (ev && ev.row && ev.row._m) this.$emit('edit', ev.row._m); },
    },
};
