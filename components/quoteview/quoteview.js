/**
 * components/quote-detail — single quote view.
 * Loads via systems.load_quote (one call): quote + entities + costs.
 * Tabs: Overview (cost breakdown) | BOM (entity table) | Process.
 */
var comp = {
    mixins: [COMP.base],
    props: ['tab_url'],
    data() {
        return {
            quoteId: '',
            quote: null,
            entities: [],
            costs: {},
            totalCost: 0,
            loading: false,
            error: '',
            activeTab: 'overview',
            prefCurrency: '',
            tabs: [
                { key: 'overview', tag: 'overview', name: 'Overview', svg: 'layout-dashboard' },
                { key: 'bom',      tag: 'bom',      name: 'BOM',      svg: 'list' },
                { key: 'tree',     tag: 'tree',     name: 'Tree',     svg: 'git-branch' },
                { key: 'process',  tag: 'process',  name: 'Process',  svg: 'timer' },
            ],
            // 12-column cost breakdown shown on the quote (user requirement)
            costColumns: [
                { key: 'material',    label: 'Mat',      type: 'money' },
                { key: 'boilerHrs',   label: 'Bm hrs',   type: 'hours' },
                { key: 'weldHrs',     label: 'W hrs',    type: 'hours' },
                { key: 'machHrs',     label: 'M hrs',    type: 'hours' },
                { key: 'labor',       label: 'Lab',      type: 'money' },
                { key: 'consumables', label: 'Cons',     type: 'money' },
                { key: 'services',    label: 'Serve',    type: 'money' },
                { key: 'ndt',         label: 'NDT',      type: 'money' },
                { key: 'lining',      label: 'Lining',   type: 'money' },
                { key: 'paint',       label: 'Paint',    type: 'money' },
                { key: 'transport',   label: 'Transport', type: 'money' },
                { key: 'total',       label: 'Total',    type: 'money' },
            ],
            // editable per-item on-costs (stored on entity.data.onCosts)
            editCostFields: {
                consumables: { label: 'Consumables (Cons)', type: 'number', step: '0.01' },
                services:    { label: 'Services (Serve)',  type: 'number', step: '0.01' },
                ndt:         { label: 'NDT',               type: 'number', step: '0.01' },
                lining:      { label: 'Lining',            type: 'number', step: '0.01' },
                paint:       { label: 'Painting',          type: 'number', step: '0.01' },
                transport:   { label: 'Transport',         type: 'number', step: '0.01' },
            },
            totals: {},
            marginPercent: null,
            // forge-tabs may drive via v-model; fall back to click handler
            selectedTab: 'overview',
            // entity tree state
            treeData: [],
            treeOpen: {},
            treeLoading: false,
            materials: [],
            processTrades: ['boilermaking', 'welding', 'machining', 'painting', 'assembly', 'qualityControl', 'surfaceTreatment', 'cutting', 'drilling', 'grinding', 'bending'],
        };
    },
    components: {
        'tree-node': {
            name: 'tree-node',
            props: ['node', 'depth', 'open', 'fmt', 'abbr', 'costOf'],
            template: '<div class="C_tree_row" :style="{ paddingLeft: (depth * 22) + \'px\' }">' +
                '<div class="C_node_row" @click="hasChildren ? toggle() : edit()">' +
                '<span class="C_node_expand">{{ hasChildren ? (open[node.id] ? \'▼\' : \'▶\') : \'\' }}</span>' +
                '<span class="C_node_badge" :class="\'t-\' + node.type">{{ abbr(node.type) }}</span>' +
                '<span class="C_node_name">{{ node.name }}</span>' +
                '<span class="C_node_type">({{ node.type }})</span>' +
                '<span v-if="node.quantity > 1" class="C_qty">×{{ node.quantity }}</span>' +
                '<span class="C_node_cost num">{{ fmt(costOf(node)) }}</span>' +
                '</div>' +
                '<div v-if="hasChildren && open[node.id]" class="C_tree_children">' +
                '<tree-node v-for="c in node.children" :key="c.id" :node="c" :depth="depth + 1" :open="open" :fmt="fmt" :abbr="abbr" :cost-of="costOf" @edit="edit($event)" @toggle="toggle($event)" />' +
                '</div>' +
                '</div>',
            computed: {
                hasChildren() { return this.node.children && this.node.children.length; },
            },
            methods: {
                toggle() { this.$emit('toggle', this.node); },
                edit() { this.$emit('edit', this.node); },
            },
        },
    },
    watch: {
        // Reload the tree when entities change (post-edit/import)
        entitiesLen(nv, ov) {
            if (nv !== ov) this.loadTree();
        },
    },
    created() {
        // quoteId comes from the route: /nav/quotes/<id> → tab_url = quotes/<id>
        var parts = (this.tab_url || '').split('/');
        this.quoteId = parts[1] || parts[0] || '';
        this.loadPrefs();
        this.load();
    },
    computed: {
        entitiesLen() { return this.entities.length; },
        currency() {
            // Per-quote currency first; else the user's pref; else USD.
            return (this.quote && this.quote.data && this.quote.data.currency)
                || this.prefCurrency
                || 'USD';
        },
        status() {
            return (this.quote && this.quote.data && this.quote.data.status) || 'draft';
        },
        statusHistory() {
            return (this.quote && this.quote.data && this.quote.data.statusHistory) || [];
        },
        // aggregate process hours across entities
        processTotal() {
            var self = this;
            return this.entities.reduce(function (sum, e) {
                var c = self.costs[e.id] || {};
                return sum + (parseFloat(c.processTotal) || 0);
            }, 0);
        },
        materialTotal() {
            var self = this;
            return this.entities.reduce(function (sum, e) {
                var c = self.costs[e.id] || {};
                return sum + (parseFloat(c.material) || 0);
            }, 0);
        },
        // v-for data: overview summary cards
        overviewCards() {
            return [
                { label: 'Material', value: this.fmtMoney(this.materialTotal), cls: '' },
                { label: 'Process', value: this.fmtMoney(this.processTotal), cls: '' },
                { label: 'Grand Total', value: this.fmtMoney(this.totalCost), cls: 'C_card_total' },
            ];
        },
    },
    methods: {
        fmtMoney(v) {
            try {
                return new Intl.NumberFormat('en-US', { style: 'currency', currency: this.currency }).format(parseFloat(v || 0));
            } catch (e) {
                return String(v || 0);
            }
        },
        // format a cost-cell: money vs hours
        fmtCell(v, type) {
            if (type === 'hours') {
                var n = parseFloat(v || 0);
                return (Math.round(n * 100) / 100).toFixed(1);
            }
            return this.fmtMoney(v);
        },
        // per-row on-cost editor (Cons/Serve/NDT/Lining/Paint/Transport + margin override)
        openCostEditor(entity) {
            var self = this;
            var onCosts = (entity.data && entity.data.onCosts) || {};
            var fields = {};
            Object.keys(this.editCostFields).forEach(function (k) {
                var f = Object.assign({}, self.editCostFields[k]);
                f.default = onCosts[k] != null ? parseFloat(onCosts[k]) : 0;
                fields[k] = f;
            });
            // Line-item margin override: blank/0 → use quote-global margin
            fields.marginPercent = {
                label: 'Margin % (0 = quote default ' + (this.marginPercent != null ? this.marginPercent : 30) + '%)',
                type: 'number',
                step: '0.1',
                default: entity.data && entity.data.marginPercent != null ? parseFloat(entity.data.marginPercent) : 0,
            };
            POPUP.show('Edit Costs — ' + entity.name, {
                comp: 'forge-form',
                props: {
                    fields: fields,
                    button_label: 'Save Costs',
                },
                events: {
                    submit: function (form) {
                        self.saveOnCosts(entity, form);
                        POPUP.close();
                    },
                },
            });
        },
        async saveOnCosts(entity, form) {
            try {
                var onCosts = {};
                Object.keys(this.editCostFields).forEach(function (k) {
                    var v = parseFloat(form[k]);
                    onCosts[k] = isNaN(v) ? 0 : v;
                });
                // margin override: explicit number → set; blank/0 → clear (use quote default)
                var mv = parseFloat(form.marginPercent);
                var data = { onCosts: onCosts };
                if (!isNaN(mv) && mv > 0) {
                    data.marginPercent = mv;
                } else {
                    data.marginPercent = null;
                }
                await WEB.api('./api/entities.php', {
                    action: 'update',
                    input: { id: entity.id, data: data }
                });
                // recalc clears cached cost components, then load() recomputes
                await WEB.api('./api/systems.php', {
                    action: 'recalculate_quote',
                    input: { quote_id: this.quoteId }
                });
                this.load();
                TOAST.show('Costs saved', 'success');
            } catch (e) {
                TOAST.show(e.message || 'Failed to save costs', 'error');
            }
        },
        // quote-global margin editor (default from Settings, per-quote override)
        openMarginEditor() {
            var self = this;
            POPUP.show('Quote Margin', {
                comp: 'forge-form',
                props: {
                    fields: {
                        margin_percent: {
                            label: 'Margin % (applies to all items unless overridden)',
                            type: 'number',
                            step: '0.1',
                            min: 0,
                            max: 100,
                            default: this.marginPercent != null ? this.marginPercent : 30,
                        },
                    },
                    button_label: 'Save Margin',
                },
                events: {
                    submit: function (form) {
                        self.saveQuoteMargin(form);
                        POPUP.close();
                    },
                },
            });
        },
        async saveQuoteMargin(form) {
            try {
                var mv = parseFloat(form.margin_percent);
                await WEB.api('./api/quotes.php', {
                    action: 'update',
                    input: { id: this.quoteId, margin_percent: isNaN(mv) ? null : mv }
                });
                await WEB.api('./api/systems.php', {
                    action: 'recalculate_quote',
                    input: { quote_id: this.quoteId }
                });
                this.load();
                TOAST.show('Margin updated', 'success');
            } catch (e) {
                TOAST.show(e.message || 'Failed to save margin', 'error');
            }
        },
        esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        },
        async loadPrefs() {
            try {
                var res = await WEB.api('./api/user.php', {
                    action: 'get_preferences',
                    input: {}
                });
                var p = (res && res.data) || res || {};
                if (p.defaultCurrency) this.prefCurrency = p.defaultCurrency;
            } catch (e) {
                // keep empty → falls back to USD
            }
        },
        async load() {
            if (!this.quoteId) return;
            this.loading = true;
            this.error = '';
            try {
                var res = await WEB.api('./api/systems.php', {
                    action: 'load_quote',
                    input: { quote_id: this.quoteId }
                });
                this.quote = res.quote || null;
                this.entities = (res.entities || []).map(function (e) {
                    return { id: e.id, name: e.name, type: e.type, quantity: e.quantity, data: e.data || {}, cost: e.cost || {} };
                });
                this.costs = res.costs || {};
                this.totals = res.totals || {};
                this.marginPercent = res.margin_percent != null ? parseFloat(res.margin_percent) : null;
                this.totalCost = res.total_cost || 0;
            } catch (e) {
                this.error = e.message || 'Failed to load quote';
            } finally {
                this.loading = false;
            }
        },
        setTab(tag) {
            this.activeTab = tag;
        },
        onTabSelect(tag) {
            this.setTab(tag);
        },
        goBack() {
            ROUTER.navigate('/nav/quotes');
        },
        async changeStatus(status) {
            try {
                await WEB.api('./api/quotes.php', {
                    action: 'update_status',
                    input: { quote_id: this.quoteId, status: status }
                });
                this.load();
            } catch (e) {
                TOAST.show(e.message || 'Failed to update status', 'error');
            }
        },
        async exportPdf() {
            try {
                var res = await WEB.api('./api/quotes.php', {
                    action: 'export_pdf',
                    input: { quote_id: this.quoteId }
                });
                if (res && res.html) {
                    var win = window.open('', '_blank');
                    if (win) { win.document.write(res.html); win.document.close(); win.focus(); }
                }
            } catch (e) {
                TOAST.show(e.message || 'Failed to export PDF', 'error');
            }
        },
        openAddEntity() {
            var self = this;
            POPUP.show('Add Item', {
                comp: 'forge-form',
                props: {
                    fields: {
                        name: { label: 'Item Name', placeholder: 'e.g. Base Plate', required: true },
                        type: {
                            label: 'Type',
                            type: 'option',
                            options: { part: 'Part', assembly: 'Assembly', fastener: 'Fastener' },
                            default: 'part',
                        },
                        quantity: { label: 'Quantity', type: 'number', default: 1 },
                    },
                    button_label: 'Add Item',
                },
                events: {
                    submit: function (form) {
                        self.addEntity(form);
                        POPUP.close();
                    },
                },
            });
        },
        openPrefabPicker() {
            var self = this;
            POPUP.show('Add from Prefab', {
                comp: 'prefabpicker',
                props: { is_select: true },
                class_body: 'popup_body_lg',
                events: {
                    onSelect: function (p) {
                        self.instantiatePrefab(p);
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
                    this.load();
                    this.loadTree();
                } else {
                    TOAST.show(data.error || 'Failed to instantiate', 'error');
                }
            } catch (e) {
                TOAST.show(e.message || 'Failed to instantiate prefab', 'error');
            }
        },
        openBatchAdd() {
            var self = this;
            POPUP.show('Add Items', {
                comp: 'quoteitems',
                props: {},
                class_body: 'popup_body_lg',
                events: {
                    submit: function (form) {
                        self.addItems(form);
                        POPUP.close();
                    },
                    cancel: function () {
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
                this.load();
            } catch (e) {
                TOAST.show(e.message || 'Failed to add items', 'error');
            }
        },
        async addEntity(form) {
            try {
                await WEB.api('./api/entities.php', {
                    action: 'create',
                    input: {
                        type: form.type || 'part',
                        name: form.name,
                        quantity: parseInt(form.quantity, 10) || 1,
                        quote_id: this.quoteId,
                    }
                });
                TOAST.show('Item added', 'success');
                this.load();
            } catch (e) {
                TOAST.show(e.message || 'Failed to add item', 'error');
            }
        },
        // ── BOM import (Excel-style rows → hierarchy + material match) ──
        openBomImport() {
            var self = this;
            POPUP.show('Import BOM', {
                comp: 'forge-form',
                props: {
                    fields: {
                        rows: {
                            label: 'BOM Rows',
                            type: 'textarea',
                            rows: 12,
                            placeholder: 'One line per item — paste from Excel\n\nitem_number, description, material, quantity, length, width, thickness\n\n1, Skid Frame, , 1\n1.1, Mounting Plate, A36, 4, 1200, 400, 10\n1.1.1, M12 Bolt, bolt, 16',
                        },
                    },
                    button_label: 'Import',
                },
                events: {
                    submit: function (form) {
                        self.doBomImport(form.rows);
                        POPUP.close();
                    },
                },
            });
        },
        // Parse Excel-style rows into boms.php import payload
        parseBomRows(text) {
            var rows = [];
            var lines = String(text || '').split(/\r?\n/);
            for (var i = 0; i < lines.length; i++) {
                var line = lines[i].trim();
                if (!line || line.charAt(0) === '#') continue;
                var parts = line.split(/[\t;,]/).map(function (s) { return s.trim(); });
                var itemNumber = parts[0];
                var desc = parts[1];
                if (!itemNumber && !desc) continue;
                var row = { item_number: itemNumber, description: desc || 'Item' };
                if (parts[2]) row.material = parts[2];
                if (parts[3]) row.quantity = parseInt(parts[3], 10) || 1;
                if (parts[4]) row.length = parseFloat(parts[4]);
                if (parts[5]) row.width = parseFloat(parts[5]);
                if (parts[6]) row.thickness = parseFloat(parts[6]);
                rows.push(row);
            }
            return rows;
        },
        async doBomImport(text) {
            var rows = this.parseBomRows(text);
            if (!rows.length) {
                TOAST.show('No valid rows to import', 'error');
                return;
            }
            try {
                var res = await WEB.api('./api/boms.php', {
                    action: 'import',
                    input: { quote_id: this.quoteId, rows: rows },
                });
                var data = (res && res.data) || res || {};
                if (data.error) throw new Error(data.error);
                TOAST.show(data.imported + ' items imported (hierarchy + materials matched)', 'success');
                await WEB.api('./api/systems.php', {
                    action: 'recalculate_quote',
                    input: { quote_id: this.quoteId },
                });
                this.load();
                this.loadTree();
            } catch (e) {
                TOAST.show(e.message || 'BOM import failed', 'error');
            }
        },
        // ── Entity tree ──────────────────────────────────
        async loadTree() {
            if (!this.quoteId) return;
            this.treeLoading = true;
            try {
                var res = await WEB.api('./api/links.php', {
                    action: 'tree',
                    input: { entity_id: this.quoteId, depth: 10 }
                });
                var tree = (res && res.data) || res || {};
                this.treeData = tree.children || [];
                // expand the first two levels by default
                var self = this;
                this.treeData.forEach(function (n) {
                    self.treeOpen[n.id] = true;
                    (n.children || []).forEach(function (c) { self.treeOpen[c.id] = true; });
                });
            } catch (e) {
                this.treeData = [];
            } finally {
                this.treeLoading = false;
            }
        },
        toggleTree(node) {
            if (node && node.id) this.$set(this.treeOpen, node.id, !this.treeOpen[node.id]);
        },
        treeCost(node) {
            if (!node || !node.id) return 0;
            for (var i = 0; i < this.entities.length; i++) {
                if (this.entities[i].id === node.id) {
                    return (this.entities[i].cost && this.entities[i].cost.total) || 0;
                }
            }
            return 0;
        },
        abbr(type) {
            var m = { assembly: 'A', part: 'P', fastener: 'F', quote: 'Q' };
            return m[type] || (type ? type[0].toUpperCase() : '?');
        },
        // ── Entity editor ────────────────────────────────
        async loadMaterials() {
            try {
                var res = await WEB.api('./api/materials.php', { action: 'list', input: { limit: 300 } });
                this.materials = (res && res.data) || res || [];
            } catch (e) { /* optional */ }
        },
        materialOptions() {
            var opts = { '': '— No material —' };
            (this.materials || []).forEach(function (m) {
                var label = m.name || '';
                if (m.grade) label += ' ' + m.grade;
                if (m.profile) label += ' ' + m.profile;
                opts[m.id] = label;
            });
            return opts;
        },
        findComponent(entity, type) {
            var comps = (entity && entity.components) || [];
            for (var i = 0; i < comps.length; i++) {
                if (comps[i].type === type) return comps[i];
            }
            return null;
        },
        editEntity(node) {
            var self = this;
            var entity = null;
            for (var i = 0; i < this.entities.length; i++) {
                if (this.entities[i].id === node.id) { entity = this.entities[i]; break; }
            }
            if (!entity) return;

            var mat = this.findComponent(entity, 'material');
            var proc = this.findComponent(entity, 'process');

            POPUP.show('Edit Item', {
                comp: 'edititem',
                props: { entity: entity, trades: this.processTrades },
                events: {
                    submit: function (f) {
                        self.saveEntity(entity, mat, proc, f);
                        POPUP.close();
                    },
                    cancel: function () {
                        POPUP.close();
                    },
                },
            });
        },
        async saveEntity(entity, mat, proc, form) {
            try {
                // 1. Entity columns
                await WEB.api('./api/entities.php', {
                    action: 'update',
                    input: {
                        id: entity.id,
                        type: form.type,
                        name: form.name,
                        quantity: parseInt(form.quantity, 10) || 1,
                    }
                });
                // 2. Material component (upsert)
                var matData = {
                    materialLibraryId: form.material_id || null,
                    length: form.length || null,
                    width: form.width || null,
                    thickness: form.thickness || null,
                };
                if (mat) {
                    await WEB.api('./api/components.php', { action: 'update', input: { id: mat.id, data: matData } });
                } else if (form.material_id || form.length) {
                    await WEB.api('./api/components.php', {
                        action: 'create',
                        input: { entity_id: entity.id, type: 'material', data: matData }
                    });
                }
                // 3. Process component (upsert, per-trade hours)
                var procData = {};
                var self = this;
                this.processTrades.forEach(function (t) {
                    if (form.hours && form.hours[t]) procData[t] = parseFloat(form.hours[t]);
                    else if (form[t]) procData[t] = parseFloat(form[t]); // legacy flat shape
                });
                if (proc) {
                    await WEB.api('./api/components.php', { action: 'update', input: { id: proc.id, data: procData } });
                } else if (Object.keys(procData).length) {
                    await WEB.api('./api/components.php', {
                        action: 'create',
                        input: { entity_id: entity.id, type: 'process', data: procData }
                    });
                }
                TOAST.show('Item saved — recalculating', 'success');
                this.load();
            } catch (e) {
                TOAST.show(e.message || 'Failed to save item', 'error');
            }
        },
    },
};
