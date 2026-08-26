/**
 * components/materialselect — searchable material picker (client-select pattern).
 * Wraps forge-select; clicking opens a POPUP with a searchable material list
 * (material-list). Emits the picked material object via v-model / input.
 */
var comp = {
    mixins: [COMP.base],
    props: {
        value: { type: [String, Object, null], default: null },
        default_txt: { type: String, default: 'Select a material...' },
        readonly: Boolean,
    },
    data() {
        return { materials: [], current: null };
    },
    computed: {
        popupSub() {
            var self = this;
            return {
                comp: 'material-list',
                props: { is_select: true },
                class_body: 'C_sub',
                events: {
                    onSelect: function (row) { self.onSelect(row); },
                },
            };
        },
        popupHeading() { return 'Select Material'; },
        // Resolve the bound value (a library ID) to the library OBJECT so the
        // picker can NAME the current material on edit — forge-select's
        // name_template needs an object, not a bare id.
        displayValue() { return this.current || this.value; },
    },
    watch: {
        value() { this.resolveCurrent(); },
    },
    created() {
        this.loadMaterials();
    },
    methods: {
        materialNameTemplate(m) {
            if (!m || !m.id) return null;
            var label = m.name || '';
            if (m.grade && label.indexOf(m.grade) === -1) label += ' ' + m.grade;
            if (m.profile && label.indexOf(m.profile) === -1) label += ' ' + m.profile;
            return label;
        },
        async loadMaterials() {
            try {
                var res = await WEB.api('./api/materials.php', { action: 'list', input: { limit: 2000 } });
                this.materials = (res && res.data) || res || [];
                this.resolveCurrent();
            } catch (e) {
                this.materials = [];
            }
        },
        resolveCurrent() {
            this.current = null;
            var v = this.value;
            if (!v || typeof v !== 'string') return;
            for (var i = 0; i < this.materials.length; i++) {
                if (this.materials[i].id === v) { this.current = this.materials[i]; break; }
            }
        },
        onSelect(row) {
            this.$emit('input', row);
            POPUP.close();
        },
    },
};
