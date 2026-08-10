/**
 * components/materiallist — searchable material list for the materialselect
 * POPUP. Mirrors the library page's search + category chips; rows emit
 * onSelect when is_select.
 */
var comp = {
    props: ['is_select'],
    data() {
        return {
            all: [],
            filtered: [],
            search: '',
            cat: '',
            cats: [],
            loading: false,
        };
    },
    created() {
        this.load();
    },
    computed: {
        rows() {
            var self = this;
            return this.filtered.map(function (m) {
                var label = m.name || '';
                if (m.grade && label.indexOf(m.grade) === -1) label += ' ' + m.grade;
                if (m.profile && label.indexOf(m.profile) === -1) label += ' ' + m.profile;
                return {
                    id: m.id,
                    label: label,
                    meta: [m.library_category || '', m.mass_per_meter ? (m.mass_per_meter + ' kg/m') : (m.thickness ? (m.thickness + 'mm') : '')]
                        .filter(Boolean).join(' · '),
                    unit_cost: m.unit_cost,
                    raw: m,
                };
            });
        },
    },
    methods: {
        async load() {
            this.loading = true;
            try {
                var res = await WEB.api('./api/materials.php', { action: 'list', input: { limit: 500 } });
                this.all = (res && res.data) || res || [];
                var c = {};
                this.all.forEach(function (m) { var k = m.library_category || 'material'; if (!c[k]) c[k] = 0; c[k]++; });
                this.cats = Object.keys(c);
                this.applyFilter();
            } catch (e) {
                this.all = [];
            } finally {
                this.loading = false;
            }
        },
        applyFilter() {
            var q = (this.search || '').toLowerCase();
            var cat = this.cat;
            this.filtered = this.all.filter(function (m) {
                if (cat && (m.library_category || 'material') !== cat) return false;
                if (!q) return true;
                var s = (m.name || '') + ' ' + (m.grade || '') + ' ' + (m.profile || '') + ' ' + (m.category || '');
                return s.toLowerCase().indexOf(q) !== -1;
            });
        },
        onSearch(val) {
            this.search = val || '';
            this.applyFilter();
        },
        setCat(c) {
            this.cat = (this.cat === c) ? '' : c;
            this.applyFilter();
        },
        pick(row) {
            if (this.is_select && this.$emit) this.$emit('onSelect', row.raw);
        },
    },
};
