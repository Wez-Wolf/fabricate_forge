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
        return { materials: [] };
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
    },
    methods: {
        materialNameTemplate(m) {
            if (!m || !m.id) return null;
            var label = m.name || '';
            if (m.grade && label.indexOf(m.grade) === -1) label += ' ' + m.grade;
            if (m.profile && label.indexOf(m.profile) === -1) label += ' ' + m.profile;
            return label;
        },
        onSelect(row) {
            this.$emit('input', row);
            POPUP.close();
        },
    },
};
