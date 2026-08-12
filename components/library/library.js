/**
 * components/library — materials library shell.
 * Owns: tabs (one per material type), search, type counts, supplier list,
 * the material editor, and the "All" generic table.
 * Each type tab delegates its table to its own sub-component
 * (library-plates, library-sections, library-pipe, ...) which renders
 * type-specific columns + colour badges.
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

var comp = {
            data() {
        return {
            all: [],
            suppliers: [],
            search: '',
            activeTab: 'plates',
            loading: false,
            error: '',
            tabs: [
                { key: 'plates',    name: 'Plates & Sheets' },
                { key: 'sections',  name: 'Sections & Bars' },
                { key: 'pipe',      name: 'Pipe' },
                { key: 'tube',      name: 'Tube' },
                { key: 'fittings',  name: 'Fittings' },
                { key: 'flanges',   name: 'Flanges' },
                { key: 'fasteners', name: 'Fasteners' },
            ],
        };
    },
    created() {
        this.load();
        this.loadSuppliers();
    },
    computed: {
        // tag → sub-component name
        activeComp() {
            var map = {
                plates: 'library-plates', sections: 'library-sections', pipe: 'library-pipe',
                tube: 'library-tube', fittings: 'library-fittings', flanges: 'library-flanges',
                fasteners: 'library-fasteners',
            };
            return map[this.activeTab] || '';
        },
        counts() {
            var c = { plates: 0, sections: 0, pipe: 0, tube: 0, fittings: 0, flanges: 0, fasteners: 0 };
            var groupToTab = {
                'Plates & Sheets': 'plates', 'Sections & Bars': 'sections',
                'Pipe': 'pipe', 'Tube': 'tube', 'Fittings': 'fittings',
                'Flanges': 'flanges', 'Fasteners': 'fasteners',
            };
            (this.all || []).forEach(function (m) {
                var g = matGroup(m);
                if (groupToTab[g]) c[groupToTab[g]]++;
            });
            return c;
        },
        // items passed to the active type sub-component (search + tab filtered)
        tabItems() {
            var self = this;
            var q = (self.search || '').toLowerCase();
            var f = this.tabFilter(this.activeTab);
            return (self.all || []).filter(function (m) {
                if (!f(m)) return false;
                if (!q) return true;
                var name = (m.name || '').toLowerCase();
                var grade = (m.grade || '').toLowerCase();
                return name.indexOf(q) !== -1 || grade.indexOf(q) !== -1;
            });
        },
    },
    methods: {
        esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        },
        async loadSuppliers() {
            try {
                var res = await WEB.api('./api/suppliers.php', { action: 'list', input: { limit: 200 } });
                this.suppliers = (res && res.data) || res || [];
            } catch (e) { this.suppliers = []; }
        },
        supplierOptions() {
            var opts = { '': '— No supplier —' };
            (this.suppliers || []).forEach(function (s) { opts[s.id] = s.company_name; });
            return opts;
        },
        async load() {
            this.loading = true;
            this.error = '';
            try {
                var res = await WEB.api('./api/materials.php', { action: 'list', input: { limit: 2000 } });
                this.all = (res && res.data) || res || [];
            } catch (e) {
                this.error = e.message || 'Failed to load materials';
            } finally {
                this.loading = false;
            }
        },
        tabFilter(tab) {
            return function (m) {
                if (tab === 'all') return true;
                var prof = String(m.profile || '').toLowerCase();
                var cat = String(m.library_category || '').toLowerCase();
                if (tab === 'plates') return prof === 'plate' || prof === 'sheet';
                if (tab === 'sections') return ['angle', 'channel', 'i-beam', 'h-beam', 'flat bar', 'round bar', 'square bar'].indexOf(prof) !== -1;
                if (tab === 'pipe') return prof === 'pipe';
                if (tab === 'tube') return prof === 'tube';
                if (tab === 'fittings') return cat === 'fitting';
                if (tab === 'flanges') return cat === 'flange';
                if (tab === 'fasteners') return cat === 'fastener';
                return true;
            };
        },
        onFetch(request) { /* created() loads; NOOP */ },
        onSearch(val) {
            this.search = val || '';
        },
        setTab(t) {
            this.activeTab = t;
        },
        openNew() {
            this.edit(null);
        },
        // Material editor: full specs + kind variables + supplier pricing
        // (global AND user materials) via the materialedit component.
        edit(mat) {
            var self = this;
            POPUP.show((mat ? 'Edit Material' : 'New Material'), {
                comp: 'materialedit',
                props: { material: mat || null, suppliers: this.suppliers },
                class_body: 'popup_body_lg',
                events: {
                    submit: function (r) {
                        if (r.isNew) {
                            self.createMaterial(r.payload);
                        } else {
                            self.updateMaterial(r.id, r.payload);
                        }
                        POPUP.close();
                    },
                    cancel: function () { POPUP.close(); },
                },
            });
        },
        async createMaterial(form) {
            try {
                await WEB.api('./api/materials.php', { action: 'create', input: form });
                TOAST.show('Material added', 'success');
                this.load();
            } catch (e) {
                TOAST.show(e.message || 'Failed to add material', 'error');
            }
        },
        async updateMaterial(id, form) {
            try {
                await WEB.api('./api/materials.php', { action: 'update', input: Object.assign({ id: id }, form) });
                TOAST.show('Material saved', 'success');
                this.load();
            } catch (e) {
                TOAST.show(e.message || 'Failed to update material', 'error');
            }
        },
    },
};

// ── shared helpers (also used by the per-type sub-components' rows) ──
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
function matGroup(m) {
    var prof = String(m.profile || '').toLowerCase();
    var cat = String(m.library_category || 'material').toLowerCase();
    if (cat === 'fastener') return 'Fasteners';
    if (cat === 'flange') return 'Flanges';
    if (cat === 'fitting') return 'Fittings';
    if (prof === 'pipe') return 'Pipe';
    if (prof === 'tube') return 'Tube';
    if (prof === 'plate' || prof === 'sheet') return 'Plates & Sheets';
    return 'Sections & Bars';
}
