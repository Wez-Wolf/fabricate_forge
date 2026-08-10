/**
 * components/prefabs — reusable assembly templates.
 * forge-list of templates; Instantiate materializes the template's ECS tree
 * into a chosen quote (server-side recalc); Bake saves a quote's assembly.
 */
var comp = {
    data() {
        var self = this;
        return {
            rows: [],           // [name, type, items, processes, id]
            all: [],
            quotes: [],         // for instantiate/bake quote select
            loading: false,
            error: '',
            fields: [
                { label: 'Prefab', type: 'function', func: function (row) { return '<span class="C_link">' + esc(row[0]) + '</span>'; } },
                { label: 'Type', type: 'function', func: function (row) { return '<span class="C_type_pill">' + esc(row[1]) + '</span>'; } },
                { label: 'Items', type: 'function', func: function (row) { return '<span class="num">' + esc(row[2]) + '</span>'; }, col_cls: 'C_right' },
                { label: 'Processes', type: 'function', func: function (row) { return '<span class="num">' + esc(row[3]) + '</span>'; }, col_cls: 'C_right' },
                { label: '', type: 'svg', path: 'play', cls: 'C_play_icon' },
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
    methods: {
        async load() {
            this.loading = true;
            this.error = '';
            try {
                var res = await WEB.api('./api/prefabs.php', { action: 'list', input: {} });
                this.all = (res && res.data) || res || [];
                this.rebuild();
                this.loadQuotes();
            } catch (e) {
                this.error = e.message || 'Failed to load prefabs';
            } finally {
                this.loading = false;
            }
        },
        async loadQuotes() {
            try {
                var res = await WEB.api('./api/systems.php', { action: 'list_quotes', input: { limit: 100 } });
                this.quotes = (res && res.data) || res || [];
            } catch (e) { /* optional */ }
        },
        quoteOptions() {
            var opts = {};
            (this.quotes || []).forEach(function (q) {
                opts[q.id] = q.name || q.id;
            });
            return opts;
        },
        rebuild() {
            var self = this;
            this.rows = (this.all || []).map(function (p) {
                var td = p.template_data || {};
                var items = (td.items || []).length;
                var procs = (td.processes || []).length;
                return [p.name || 'Prefab', p.type || 'assembly', items, procs, p.id, p];
            });
        },
        // play icon → instantiate
        onSvg(ev) {
            if (ev && ev.row && ev.row[5]) this.instantiate(ev.row[5]);
        },
        openNew() {
            var self = this;
            POPUP.show('New Prefab', {
                comp: 'forge-form',
                props: {
                    fields: {
                        name: { label: 'Name', placeholder: 'e.g. Pipe Skid', required: true },
                        description: { label: 'Description', type: 'textarea', rows: 2 },
                    },
                    button_label: 'Create Prefab',
                },
                events: {
                    submit: function (form) {
                        self.createPrefab(form);
                        POPUP.close();
                    },
                },
            });
        },
        async createPrefab(form) {
            try {
                var input = {
                    name: form.name,
                    description: form.description || '',
                    template_data: {
                        root: { id: 'root', type: 'assembly', name: form.name },
                        items: [],
                        processes: [{ id: 'default', name: 'Assemble', trade: 'assembly', durationHours: 1, consumables: [] }],
                        consumables: [],
                    },
                };
                await WEB.api('./api/prefabs.php', { action: 'create', input: input });
                TOAST.show('Prefab created', 'success');
                this.load();
            } catch (e) {
                TOAST.show(e.message || 'Failed to create prefab', 'error');
            }
        },
        instantiate(prefab) {
            var self = this;
            if (!this.quotes.length) {
                TOAST.show('Create a quote first', 'error');
                return;
            }
            POPUP.show('Instantiate: ' + prefab.name, {
                comp: 'forge-form',
                props: {
                    fields: {
                        quote_id: { label: 'Target Quote', type: 'option', options: self.quoteOptions(), required: true },
                    },
                    button_label: 'Instantiate',
                },
                events: {
                    submit: function (form) {
                        self.doInstantiate(prefab, form.quote_id);
                        POPUP.close();
                    },
                },
            });
        },
        async doInstantiate(prefab, quoteId) {
            try {
                var res = await WEB.api('./api/prefabs.php', {
                    action: 'instantiate',
                    input: { prefab_id: prefab.id, quote_id: quoteId },
                });
                var data = (res && res.data) || res || {};
                if (data.root_entity_id) {
                    TOAST.show('Prefab instantiated — ' + (data.child_ids || []).length + ' items', 'success');
                    setTimeout(function () { ROUTER.navigate('/nav/quotes/' + quoteId); }, 800);
                } else {
                    TOAST.show('Failed to instantiate prefab', 'error');
                }
            } catch (e) {
                TOAST.show(e.message || 'Failed to instantiate prefab', 'error');
            }
        },
        openBake() {
            var self = this;
            if (!this.quotes.length) {
                TOAST.show('Create a quote first', 'error');
                return;
            }
            POPUP.show('Bake Prefab from Quote', {
                comp: 'forge-form',
                props: {
                    fields: {
                        quote_id: { label: 'Quote', type: 'option', options: self.quoteOptions(), required: true },
                        name: { label: 'Prefab Name', placeholder: 'e.g. Skid Frame Base' },
                    },
                    button_label: 'Bake',
                },
                events: {
                    submit: function (form) {
                        self.doBake(form);
                        POPUP.close();
                    },
                },
            });
        },
        async doBake(form) {
            try {
                // Use the quote's first assembly as the root (server re-validates)
                var assemblyId = null;
                var quote = null;
                var self = this;
                this.quotes.forEach(function (q) { if (q.id === form.quote_id) quote = q; });
                var qid = form.quote_id;
                if (quote && quote.entity_id) assemblyId = quote.entity_id;
                if (!assemblyId) {
                    // Load the quote to find an assembly root
                    var lq = await WEB.api('./api/systems.php', { action: 'load_quote', input: { quote_id: qid } });
                    var data = (lq && lq.data) || lq || {};
                    var entities = data.entities || [];
                    var assembly = entities.filter(function (e) { return e.type === 'assembly'; })[0];
                    if (assembly) assemblyId = assembly.id;
                }
                if (!assemblyId) {
                    TOAST.show('No assembly in that quote to bake', 'error');
                    return;
                }
                var res = await WEB.api('./api/prefabs.php', {
                    action: 'bake_from_quote',
                    input: { quote_id: qid, assembly_id: assemblyId, name: form.name || '' },
                });
                var d = (res && res.data) || res || {};
                if (d.id) {
                    TOAST.show('Prefab baked with ' + ((d.template_data && d.template_data.items) || []).length + ' items', 'success');
                    this.load();
                } else {
                    TOAST.show('Bake failed', 'error');
                }
            } catch (e) {
                TOAST.show(e.message || 'Bake failed', 'error');
            }
        },
    },
};
