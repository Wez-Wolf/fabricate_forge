/**
 * components/client-select — full-list client picker (progeny animal-select pattern).
 * Wraps forge-select: trigger shows the picked client; clicking opens a POPUP
 * with a searchable client list + an "Add Client" action. Emits the picked
 * client object (or {id, company_name}) via v-model / input.
 */
var comp = {
    mixins: [COMP.base],
    props: {
        value: { type: [String, Object, null], default: null },
        default_txt: { type: String, default: 'Select a client...' },
        readonly: Boolean,
    },
    data() {
        return { clients: [] };
    },
    computed: {
        popupSub() {
            var self = this;
            return {
                comp: 'client-list',
                props: { is_select: true },
                class_body: 'C_sub',
                events: {
                    onSelect: function (row) { self.onSelect(row); },
                },
            };
        },
        popupHeading() { return 'Select Client'; },
    },
    methods: {
        // name_template for forge-select: show company name
        clientNameTemplate(client) {
            if (!client || !client.id) return null;
            return client.company_name || 'Unnamed client';
        },
        onSelect(row) {
            this.$emit('input', row);
            POPUP.close();
        },
    },
};
