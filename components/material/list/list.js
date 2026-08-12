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
                var d = (m.data && typeof m.data === 'object' && !Array.isArray(m.data)) ? m.data : {};
                function odLabel(d) {
                    if (d.od && Array.isArray(d.od)) {
                        var uniq = [];
                        for (var i = 0; i < d.od.length; i++) {
                            if (d.od[i] != null && uniq.indexOf(d.od[i]) === -1) uniq.push(d.od[i]);
                        }
                        return uniq.length ? uniq.join('×') : '';
                    }
                    return d.od != null ? d.od : (d.pipeOd != null ? d.pipeOd : '');
                }
                var metaMass = m.mass_per_meter != null ? (m.mass_per_meter + ' kg/m')
                    : (d.massKg != null ? (d.massKg + ' kg')
                    : (m.thickness != null ? (m.thickness + 'mm') : ''));
                var od = odLabel(d);
                return {
                    id: m.id,
                    label: label,
                    meta: [
                        m.library_category || '',
                        // Pipe/fitting/flange attributes (od · sched · mass · paint)
                        od ? (od + 'mm' + (d.schedule || d.series ? ' · ' + (d.schedule || d.series) : '')) : (d.schedule || d.series || ''),
                        metaMass,
                        d.paintAreaPerM != null ? ('paint ' + d.paintAreaPerM + '/m') : (d.extArea != null ? ('paint ' + d.extArea + 'm²') : (d.paintArea != null ? ('paint ' + d.paintArea + 'm²') : '')),
                    ].filter(Boolean).join(' · '),
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
                var res = await WEB.api('./api/materials.php', { action: 'list', input: { limit: 2000 } });
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
