/**
 * components/quote/entities — add/edit/delete quote items tab.
 * Row management: add / batch-add / prefab / edit / delete. BOM import lives
 * on the BOM tab (components/quote/bom).
 * The table itself is forge-table (sort / filter / tree hybrid); this tab
 * supplies the column definitions + row actions.
 */

var comp = {
    mixins: [COMP.base, FAB_EDIT_MIXIN],
    components: {
        'quote-tree': function (resolve) { COMP.externLoadComponent(resolve, null, 'quote-tree'); },
        'quote-process': function (resolve) { COMP.externLoadComponent(resolve, null, 'quote-process'); },
    },
    props: ['quoteId', 'quote', 'entities', 'token'],
    data() {
        return {
            // Two lenses (D5): 'catalog' = one row per singular entity with
            // total demand; 'usage' = one row per placement. Default catalog.
            lens: 'catalog',
            selfRows: [],
            serverTotal: 0,
            loadingRows: false,
            actions: [
                { key: 'edit', icon: 'pencil', title: 'Edit' },
                { key: 'delete', icon: 'trash', title: 'Delete' },
            ],
        };
    },
    created() {
        this.loadItems();
    },
    watch: {
        lens() { this.loadItems(); },
        // shell bumps token after any mutation/refetch
        token() { this.loadItems(); },
    },
    computed: {
        // Self-loaded rows (systems.entity_items) win; shell prop is fallback.
        rows() {
            return (this.selfRows && this.selfRows.length)
                ? this.selfRows : (this.entities || []);
        },
        // forge-table column defs — derived values ride on func, sortable
        // columns use the same func so sorting follows what the user sees.
        columns() {
            var self = this;
            var tc = this.typeCounts;
            var cols = [];
            if (this.lens === 'catalog') cols.push({ key: 'catalog_no', label: 'No.', sortable: true, cls: 'C_mono' });
            cols.push(
                { key: 'name', label: 'Item', sortable: true, cls: 'C_strong', width: '28rem' },
                { key: 'description', label: 'Description', sortable: true, cls: 'C_desc', width: '22rem' },
                {
                    key: 'type', label: 'Type', sortable: true, badge: true,
                    filter: {
                        options: [
                            { value: 'assembly', label: 'Assemblies (' + tc.assembly + ')' },
                            { value: 'part', label: 'Parts (' + tc.part + ')' },
                            { value: 'fitting', label: 'Fittings (' + tc.fitting + ')' },
                            { value: 'fastener', label: 'Fasteners (' + tc.fastener + ')' },
                        ],
                    },
                },
                { key: 'material', label: 'Material', sortable: true, func: function (row) { return self.materialName(row); } }
            );
            if (this.lens === 'catalog') {
                cols.push(
                    { key: 'used_in', label: 'Used In', sortable: true, numeric: true },
                    { key: 'total_qty', label: 'Total Qty', sortable: true, numeric: true },
                    { key: 'process_hours', label: 'Process Hrs', sortable: true, numeric: true, func: function (row) { return self.totalProcessHours(row); } },
                    { key: 'total', label: 'Unit Cost Basis', sortable: true, numeric: true, func: function (row) { return self.fmtMoney((row.cost || {}).total); } });
            } else {
                cols.push(
                    { key: 'quantity', label: 'Qty', sortable: true, numeric: true },
                    { key: 'process_hours', label: 'Process Hrs', sortable: true, numeric: true, func: function (row) { return self.totalProcessHours(row); } },
                    { key: 'total', label: 'Total', sortable: true, numeric: true, func: function (row) { return self.fmtMoney((row.cost || {}).total); }, sumFunc: function (row) { return parseFloat((row.cost || {}).total) || 0; } },
                    { key: 'parent', label: 'Belongs to', sortable: true, func: function (row) { return row.parent_name || ''; } });
            }
            return cols;
        },
        // Totals row pinned to the TOP of the table (per user). Quantity sums
        // every row; Total sums top-level rows only in tree mode (children roll
        // up into their parents) — handled inside forge-table via compute:'sum'.
        summary() {
            var ents = this.rows;
            var totalCount = this.serverTotal > ents.length ? this.serverTotal : ents.length;
            var qtyKey = this.lens === 'catalog' ? 'total_qty' : 'quantity';
            var qty = ents.reduce(function (s, e) { return s + (parseFloat(e[qtyKey]) || 0); }, 0);
            var label = this.lens === 'catalog'
                ? 'Catalog (' + ents.length + ' unique items)'
                : 'Totals (' + ents.length + ' of ' + totalCount + ' placements)';
            return [
                { key: 'name', label: label },
                { key: this.lens === 'catalog' ? 'total_qty' : 'quantity', value: Math.round(qty * 100) / 100 },
            ];
        },
        typeCounts() {
            var c = { assembly: 0, part: 0, fitting: 0, fastener: 0 };
            this.rows.forEach(function (e) { if (c[e.type] != null) c[e.type]++; });
            return c;
        },
    },
    methods: {
        async loadItems() {
            if (!this.quoteId) return;
            this.loadingRows = true;
            try {
                var res = await WEB.api('./api/systems.php', {
                    action: 'entity_items',
                    input: { entity_id: this.quoteId, lens: this.lens, limit: 4000 }
                });
                var items = (res && (res.items || res)) || [];
                this.serverTotal = (res && res.total) || items.length;
                items.forEach(function (e) {
                    e.quantity = parseFloat(e.quantity) || 1;
                    e.total_qty = e.total_qty != null ? parseFloat(e.total_qty) : undefined;
                    e.used_in = e.used_in != null ? parseInt(e.used_in, 10) : undefined;
                    e.components = e.components || [];
                    e.cost = e.cost || {};
                });
                this.selfRows = items;
            } catch (e) {
                TOAST.show(e.message || 'Failed to load items', 'error');
            } finally {
                this.loadingRows = false;
            }
        },
        // forge-table action buttons → CRUD
        onAction(ev) {
            if (!ev || !ev.row) return;
            if (ev.action === 'edit') this.editEntity(ev.row);
            if (ev.action === 'delete') this.deleteEntity(ev.row);
        },
        materialName(entity) {
            var mat = this.findComponent(entity, 'material');
            if (!mat || !mat.data || !mat.data.materialLibraryId) return '—';
            // material_label rides on the component row (attached by
            // api/components.php get_by_quote) — no client-side library fetch.
            return mat.material_label || '—';
        },
        openAddEntity() {
            POPUP.show('Add Item', {
                comp: 'forge-form',
                props: {
                    fields: {
                        name: { label: 'Item Name', placeholder: 'e.g. Base Plate', required: true },
                        type: {
                            label: 'Type',
                            type: 'option',
                            options: { part: 'Part', assembly: 'Assembly', fitting: 'Fitting (bought-in)', fastener: 'Fastener' },
                            default: 'part',
                        },
                        // NO quantity — an entity is ONE unique item (singular).
                        // Quantity is LINK data: set it on the Link tab / in the tree.
                    },
                    button_label: 'Add Item',
                },
                events: {
                    submit: (form) => {
                        this.addEntity(form);
                        POPUP.close();
                    },
                },
            });
        },
        async addEntity(form) {
            try {
                await WEB.api('./api/entities.php', {
                    action: 'create',
                    input: {
                        type: form.type || 'part',
                        name: form.name,
                        // singular entity — quantity is link data, never set here
                        quote_id: this.quoteId,
                    }
                });
                TOAST.show('Item added', 'success');
                this.$emit('changed');
            } catch (e) {
                TOAST.show(e.message || 'Failed to add item', 'error');
            }
        },
        openBatchAdd() {
            POPUP.show('Add Items', {
                comp: 'quote-items',
                props: {},
                class_body: 'popup_body_lg',
                events: {
                    submit: (form) => {
                        this.addItems(form);
                        POPUP.close();
                    },
                    cancel: () => {
                        POPUP.close();
                    },
                },
            });
        },
        async addItems(form) {
            if (!form || !form.items || !form.items.length) return;
            try {
                var res = await WEB.api('./api/quotes.php', {
                    action: 'add_items',
                    input: { quote_id: this.quoteId, items: form.items }
                });
                if (res && res.error) throw new Error(res.error);
                TOAST.show((res.items_created || 0) + ' items added', 'success');
                this.$emit('changed');
            } catch (e) {
                TOAST.show(e.message || 'Failed to add items', 'error');
            }
        },
        openPrefabPicker() {
            POPUP.show('Add from Prefab', {
                comp: 'prefab-picker',
                props: { is_select: true },
                class_body: 'popup_body_lg',
                events: {
                    onSelect: (p) => {
                        this.instantiatePrefab(p);
                        POPUP.close();
                    },
                },
            });
        },
        async instantiatePrefab(prefab) {
            try {
                var res = await WEB.api('./api/prefabs.php', {
                    action: 'instantiate',
                    input: { prefab_id: prefab.id, quote_id: this.quoteId },
                });
                var data = (res && res.data) || res || {};
                if (data.root_entity_id) {
                    TOAST.show('Prefab added — ' + (data.child_ids || []).length + ' items', 'success');
                    this.$emit('changed');
                } else {
                    TOAST.show(data.error || 'Failed to instantiate', 'error');
                }
            } catch (e) {
                TOAST.show(e.message || 'Failed to instantiate prefab', 'error');
            }
        },
        editEntity(entity) {
            var mat = this.findComponent(entity, 'material');
            var proc = this.findComponent(entity, 'process');
            POPUP.show('Edit Item', {
                comp: 'edititem',
                props: {
                    entity: entity,
                    trades: this.processTrades,
                    link_id: entity.link_id || null,
                    parent_name: entity.parent_name || '',
                    parent_qty: entity.parent_qty != null ? entity.parent_qty : 1,
                },
                events: {
                    submit: (f) => {
                        this.saveEntity(entity, mat, proc, f);
                        POPUP.close();
                    },
                    cancel: () => {
                        POPUP.close();
                    },
                },
            });
        },
        deleteEntity(entity) {
            POPUP.confirm('Delete Item', 'Delete "' + entity.name + '" from this quote? Its cost is removed from the totals.', () => {
                this.doDeleteEntity(entity);
            });
        },
        async doDeleteEntity(entity) {
            try {
                await WEB.api('./api/entities.php', {
                    action: 'delete',
                    input: { id: entity.id }
                });
                await WEB.api('./api/systems.php', {
                    action: 'recalculate_entity',
                    input: { entity_id: this.quoteId }
                });
                this.$emit('changed');
                TOAST.show('Item deleted', 'success');
            } catch (e) {
                TOAST.show(e.message || 'Failed to delete item', 'error');
            }
        },
        totalProcessHours(row) {
            if (!row || !row.components) return '—';
            var proc = row.components.find(c => c.type === 'process');
            if (!proc || !proc.data) return '—';
            var d = proc.data;
            var total = (parseFloat(d.boilerHrs) || 0) + (parseFloat(d.weldHrs) || 0) + (parseFloat(d.machHrs) || 0);
            return total > 0 ? total.toFixed(1) + ' h' : '—';
        },
    },
};
