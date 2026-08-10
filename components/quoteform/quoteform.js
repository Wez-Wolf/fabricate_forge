/**
 * components/quote-form — New/Edit Quote form (POPUP body).
 * Uses client-select (full-list picker with add) + forge-form for the rest.
 * Emits submit with the assembled quote payload.
 */
var comp = {
    mixins: [COMP.base],
    data() {
        return {
            form: {
                name: '',
                client_id: null,
                customerName: '',
                currency: '',
                dueDate: '',
                margin: null,
            },
            currencies: ['USD', 'EUR', 'GBP', 'ZAR', 'CAD', 'AUD'],
        };
    },
    created() {
        this.loadPrefs();
    },
    methods: {
        // Default currency + margin to the user's preferences from Settings
        async loadPrefs() {
            try {
                var res = await WEB.api('./api/user.php', {
                    action: 'get_preferences',
                    input: {}
                });
                var p = (res && res.data) || res || {};
                if (p.defaultCurrency) this.form.currency = p.defaultCurrency;
                if (p.defaultMarkupPercent != null) this.form.margin = parseFloat(p.defaultMarkupPercent);
            } catch (e) {
                this.form.currency = 'USD';
            }
        },
        // client-select emits the picked client object — store id + prefill name
        onClientPick(client) {
            if (client && client.id) {
                this.form.client_id = client.id;
                if (!this.form.customerName) {
                    this.form.customerName = client.company_name || '';
                }
            } else {
                this.form.client_id = null;
            }
        },
        submit() {
            if (!this.form.name) {
                TOAST.show('Quote name is required', 'error');
                return;
            }
            this.$emit('submit', this.form);
        },
        cancel() {
            this.$emit('cancel');
        },
    },
};
