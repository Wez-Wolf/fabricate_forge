/**
 * components/prefabpicker — prefab template picker (POPUP body).
 * Lists global + user prefab templates; emits onSelect with the chosen one.
 */
var comp = {
    props: ['is_select'],
    data() {
        return {
            prefabs: [],
            loading: false,
        };
    },
    created() {
        this.load();
    },
    methods: {
        async load() {
            this.loading = true;
            try {
                var res = await WEB.api('./api/prefabs.php', { action: 'list', input: {} });
                this.prefabs = (res && res.data) || res || [];
            } catch (e) {
                this.prefabs = [];
            } finally {
                this.loading = false;
            }
        },
        pick(p) {
            if (this.is_select && this.$emit) this.$emit('onSelect', p);
        },
    },
};
