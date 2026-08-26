/**
 * components/client-list — full client list, used inside the client-select POPUP.
 * Searchable; rows emit onSelect when is_select. Includes an "Add Client" row
 * that opens the create form inline (progeny animal-list pattern).
 */
var comp = {
    props: ['is_select'],
    data() {
        return {
            clients: [],
            search: '',
            loading: false,
            adding: false,
            addForm: { company_name: '', email: '' },
        };
    },
    created() {
        this.load();
    },
    methods: {
        esc(s) { return FAB.esc(s); },
        async load() {
            this.loading = true;
            try {
                var res = await WEB.api('./api/clients.php', {
                    action: 'list',
                    input: { limit: 200, search: this.search || undefined },
                });
                this.clients = (res && res.data) || res || [];
            } catch (e) {
                this.clients = [];
            } finally {
                this.loading = false;
            }
        },
        onSearch(val) {
            this.search = val || '';
            this.load();
        },
        pick(row) {
            if (this.is_select && this.$emit) this.$emit('onSelect', row);
        },
        addMode() {
            this.adding = true;
        },
        cancelAdd() {
            this.adding = false;
        },
        async addClient(form) {
            try {
                await WEB.api('./api/clients.php', { action: 'create', input: form });
                this.adding = false;
                this.addForm = { company_name: '', email: '' };
                this.load();
            } catch (e) {
                TOAST.show(e.message || 'Failed to add client', 'error');
            }
        },
    },
};
