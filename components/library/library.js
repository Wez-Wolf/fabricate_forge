/**
 * components/library — materials library.
 * The seeded global library (102 items) + user materials.
 * forge-search + category filter chips + forge-list table.
 */
var comp = {
    data() {
        var self = this;
        return {
            rows: [],           // forge-list rows: [name, grade/profile, category, density, unit_cost, id]
            all: [],            // raw materials
            search: '',
            catFilter: '',
            loading: false,
            error: '',
            cats: ['material', 'fastener', 'fitting'],
            fields: [
                { label: 'Material', type: 'function', func: function (row) { return '<span class="C_link">' + esc(row[0]) + '</span>'; } },
                { label: 'Grade / Profile', type: 'function', func: function (row) { return esc(row[1]); } },
                { label: 'Type', type: 'function', func: function (row) { return '<span class="C_type_pill">' + esc(row[2]) + '</span>'; } },
                { label: 'Density', type: 'function', func: function (row) { return '<span class="num">' + esc(row[3]) + '</span>'; }, col_cls: 'C_right' },
                { label: 'Unit Cost', type: 'function', func: function (row) { return '<span class="num">' + esc(row[4]) + '</span>'; }, col_cls: 'C_right' },
            ],
        };
        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }
    },
    created() {
        this.load();
    },
    computed: {
        counts() {
            var c = {};
            var self = this;
            this.cats.forEach(function (k) { c[k] = 0; });
            (this.all || []).forEach(function (m) { var k = m.library_category; if (k in c) c[k]++; });
            return c;
        },
    },
    methods: {
        fmtMoney(v) {
            return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(parseFloat(v || 0));
        },
        async load() {
            this.loading = true;
            this.error = '';
            try {
                var res = await WEB.api('./api/materials.php', {
                    action: 'list',
                    input: { limit: 500 }
                });
                this.all = (res && res.data) || res || [];
                this.rebuild();
            } catch (e) {
                this.error = e.message || 'Failed to load materials';
            } finally {
                this.loading = false;
            }
        },
        rebuild() {
            var self = this;
            var q = (self.search || '').toLowerCase();
            var f = self.catFilter;
            var filtered = (self.all || []).filter(function (m) {
                if (f && m.library_category !== f) return false;
                if (!q) return true;
                var name = (m.name || '').toLowerCase();
                var grade = (m.grade || '').toLowerCase();
                return name.indexOf(q) !== -1 || grade.indexOf(q) !== -1;
            });
            this.rows = filtered.map(function (m) {
                var profile = m.profile ? m.profile : (m.grade || '—');
                return [
                    m.name || '—',
                    (m.grade ? m.grade + (m.profile ? ' · ' + m.profile : '') : (m.profile || '—')),
                    m.library_category || 'material',
                    m.density != null ? m.density : '—',
                    self.fmtMoney(m.unit_cost),
                    m.id,
                ];
            });
        },
        onFetch(request) {
            // created() loads; NOOP here (same single-load pattern as quotes)
        },
        onSearch(val) {
            this.search = val || '';
            this.rebuild();
        },
        setCat(c) {
            this.catFilter = (this.catFilter === c) ? '' : c;
            this.rebuild();
        },
    },
};
