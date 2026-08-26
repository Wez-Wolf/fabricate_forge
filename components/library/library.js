/**
 * components/library — materials library shell.
 * Owns: tabs (one per material type), search, type counts, supplier list,
 * the material editor, and the "All" generic table.
 * Each type tab delegates its table to the shared generic component
 * `library-table` (kind selects columns + row mapping + colour badge).
 */

var comp = {
            data() {
        return {
            all: [],
            suppliers: [],
            prefabCount: 0,
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
                { key: 'prefabs',   name: 'Prefabs' },
            ],
            legend: [
                { label: 'Plate', c: '#3b82f6' }, { label: 'Sheet', c: '#60a5fa' },
                { label: 'Angle', c: '#f59e0b' }, { label: 'Channel', c: '#fbbf24' },
                { label: 'H/I-Beam', c: '#dc2626' }, { label: 'Bars', c: '#f97316' },
                { label: 'Pipe', c: '#14b8a6' }, { label: 'Tube', c: '#06b6d4' },
                { label: 'Fitting', c: '#8b5cf6' }, { label: 'Flange', c: '#ec4899' },
                { label: 'Fastener', c: '#22c55e' },
            ],
        };
    },
    created() {
        this.load();
        this.loadSuppliers();
    },
    computed: {
        // tab → component: all material tabs share `library-table`; prefabs is its own shell
        activeComp() {
            return this.activeTab === 'prefabs' ? 'prefabs' : 'library-table';
        },
        counts() {
            var c = { plates: 0, sections: 0, pipe: 0, tube: 0, fittings: 0, flanges: 0, fasteners: 0, prefabs: 0 };
            var groupToTab = {
                'Plates & Sheets': 'plates', 'Sections & Bars': 'sections',
                'Pipe': 'pipe', 'Tube': 'tube', 'Fittings': 'fittings',
                'Flanges': 'flanges', 'Fasteners': 'fasteners',
            };
            (this.all || []).forEach(function (m) {
                var g = matGroup(m);
                if (groupToTab[g]) c[groupToTab[g]]++;
            });
            c.prefabs = this.prefabCount;
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
            try {
                var pfs = await WEB.api('./api/prefabs.php', { action: 'list', input: {} });
                this.prefabCount = (Array.isArray(pfs) ? pfs : (pfs.data || [])).length || 0;
            } catch (e) { this.prefabCount = 0; }
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
        // (global AND user materials) via the material-edit component.
        edit(mat) {
            var self = this;
            POPUP.show((mat ? 'Edit Material' : 'New Material'), {
                comp: 'material-edit',
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

// ── shared helper ──
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
