/**
 * components/quote-detail — single quote view.
 * Loads via systems.load_quote (one call): quote + entities + costs.
 * Tabs: Overview (cost breakdown) | BOM (entity table) | Process.
 */

// BOM CSV column aliases — file scope (not on the component: a plain object
// on the component options would be treated as a method by Vue's merge).
var BOM_HEADERS = {
    item: 'item_number', itemnumber: 'item_number', itemno: 'item_number', no: 'item_number', num: 'item_number', number: 'item_number', pos: 'item_number', position: 'item_number',
    description: 'description', desc: 'description', name: 'description', part: 'description', partname: 'description', itemname: 'description', partdescription: 'description',
    material: 'material', mat: 'material', grade: 'material', spec: 'material', materialname: 'material', matname: 'material', materialgrade: 'material', materialspec: 'material',
    quantity: 'quantity', qty: 'quantity', q: 'quantity', qtyper: 'quantity', qtyrequired: 'quantity',
    length: 'length', len: 'length', l: 'length', lengthmm: 'length',
    width: 'width', w: 'width', widthmm: 'width',
    thickness: 'thickness', thick: 'thickness', t: 'thickness', thk: 'thickness', wall: 'thickness', wallthickness: 'thickness', thicknessmm: 'thickness',
    mass: 'mass', kg: 'mass', weight: 'mass', kgperitem: 'mass', kgperunit: 'mass',
    weld: 'welding', welding: 'welding', weldhrs: 'welding', weldinghrs: 'welding', weldinghours: 'welding',
    machine: 'machining', machining: 'machining', machinehrs: 'machining', machininghrs: 'machining', machininghours: 'machining', mill: 'machining', turn: 'machining',
    boiler: 'boilermaking', boilermaking: 'boilermaking', boilerhrs: 'boilermaking', boilermakinghrs: 'boilermaking', bm: 'boilermaking', bmhrs: 'boilermaking', fit: 'boilermaking', fitting: 'boilermaking',
    cut: 'cutting', cutting: 'cutting', cuthrs: 'cutting', cuttinghrs: 'cutting', plasma: 'cutting', laser: 'cutting',
    drill: 'drilling', drilling: 'drilling', drillhrs: 'drilling', drillinghrs: 'drilling',
    grind: 'grinding', grinding: 'grinding', grindhrs: 'grinding', grindinghrs: 'grinding',
    bend: 'bending', bending: 'bending', bendhrs: 'bending',
    assemble: 'assembly', assembly: 'assembly', assemblyhrs: 'assembly', assemblehrs: 'assembly',
    qc: 'qualityControl', quality: 'qualityControl', qualitycontrol: 'qualityControl', qchrs: 'qualityControl', inspect: 'qualityControl', inspection: 'qualityControl',
    paint: 'painting', painting: 'painting', paintarea: 'paintarea',
    costperm: 'costPerM', costpm: 'costPerM', costm: 'costPerM', rateperm: 'costPerM', permeter: 'costPerM', costpermeter: 'costPerM',
    costperea: 'costPerEa', costpea: 'costPerEa', costea: 'costPerEa', perea: 'costPerEa', costperitem: 'costPerEa', costeach: 'costPerEa', rateperea: 'costPerEa',
    unitcost: 'unitCost', costkg: 'unitCost', costperkg: 'unitCost', rate: 'unitCost', price: 'unitCost', unitprice: 'unitCost',
    type: 'type', itemtype: 'type', kind: 'type', entitytype: 'type',
    note: 'note', notes: 'note', remark: 'note', remarks: 'note',
};

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
                { key: 'overview',  tag: 'overview',  name: 'Overview',  svg: 'layout-dashboard' },
                { key: 'entities',  tag: 'entities',  name: 'Entities',  svg: 'package' },
                { key: 'bom',       tag: 'bom',       name: 'BOM',       svg: 'list' },
                { key: 'materials', tag: 'materials', name: 'Materials', svg: 'boxes' },
                { key: 'tree',      tag: 'tree',      name: 'Tree',      svg: 'git-branch' },
                { key: 'checks',    tag: 'checks',    name: 'Checks',    svg: 'shield' },
                { key: 'process',   tag: 'process',   name: 'Process',   svg: 'timer' },
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
                // NEW: paint mode indicator (shows inhouse/subcontract)
                { key: 'paintMode',   label: 'Paint Mode', type: 'text' },
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
            // supplier material take-off (Materials tab)
            takeoff: [],
            takeoffTotals: { total_mass_kg: 0, total_cost: 0, distinct: 0 },
            takeoffLoading: false,
            // pipe ↔ flange compatibility (Checks tab)
            compatIssues: [],
            compatSuggestions: [],
            compatLoading: false,
            // forge-tabs may drive via v-model; fall back to click handler
            selectedTab: 'overview',
            // entity tree state
            treeData: [],
            treeOpen: {},
            treeLoading: false,
            // tree search/filter
            treeSearch: '',
            filteredTreeData: [],
            // collapse/expand state
            treeCollapsedAll: false,
            treeExpandedAll: false,
            materials: [],
            processTrades: ['boilermaking', 'welding', 'machining', 'painting', 'assembly', 'qualityControl', 'surfaceTreatment', 'cutting', 'drilling', 'grinding', 'bending'],
        };
    },
    components: {
        'tree-node': {
            name: 'tree-node',
            props: ['node', 'depth', 'open', 'fmt', 'abbr', 'costOf', 'massOf', 'materialOf', 'processOf', 'treeSearch', 'warnOf', 'kindOf'],
            template: '<div class="C_tree_row" :style="{ paddingLeft: (depth * 22) + \'px\' }">' +
                // search highlight
                '<span v-if="treeSearch && treeSearch.length > 0" class="C_tree_search_highlight" v-if="nodeName.includes(treeSearch)" style="color: var(--color-primary); background: var(--color-primary-light); padding: 0 1px; border-radius: 2px; font-size: 0.7rem;">·</span>' +
                '<div class="C_node_row" @click="edit()" @keydown.enter="onEnter" tabindex="0" aria-label="Edit {{ nodeName }} (Enter)">' +
                '<span v-if="hasChildren" class="C_node_expand" @click.stop="toggle()">{{ open[node.id] ? \'▼\' : \'▶\' }}</span>' +
                '<span v-else class="C_node_expand"></span>' +
                // depth indicator
                '<span v-if="depth > 0" class="C_node_depth" title="Depth {{ depth }} of hierarchy">{{ depth }}</span>' +
                '<span class="C_node_badge" :class="\'t-\' + node.type">{{ abbr(node.type) }}</span>' +
                '<span class="C_node_name" :title="nodeName">{{ nodeName }}</span>' +
                '<span class="C_node_type">({{ node.type }})</span>' +
                '<span class="C_qty" :class="{ C_qty_one: !(node.quantity > 1) }">×{{ node.quantity }}</span>' +
                '<span class="C_node_mass num" v-if="massOf(node) > 0">{{ massOf(node) }} kg</span>' +
                '<span class="C_node_cost num">' +
                '<span v-if="hasWarning" class="C_node_warning" title="Cost or material issues detected">⚠</span>' +
                '{{ fmt(costOf(node)) }}' +
                '</span>' +
                '</div>' +
                // per-node detail line: kind + material + process hours + weld info
                '<div class="C_node_detail" title="Material: {{ materialOf(node) || \'none\' }} | Process: {{ processOf(node) || \'none\' }}">' +
                '<span v-if="kindOf(node)" class="C_node_kind">{{ kindOf(node) }}</span>' +
                '<span v-if="materialOf(node)" class="C_node_mat">{{ materialOf(node) }}</span>' +
                '<span v-else class="C_node_mat C_muted">no material</span>' +
                '<span class="C_node_proc">{{ processOf(node) }}</span>' +
                '</div>' +
                '<div v-if="hasChildren && open[node.id]" class="C_tree_children">' +
                '<tree-node v-for="c in node.children" :key="c.id" :node="c" :depth="depth + 1" :open="open" :fmt="fmt" :abbr="abbr" :cost-of="costOf" :mass-of="massOf" :material-of="materialOf" :process-of="processOf" :treeSearch="treeSearch" :warn-of="warnOf" :kind-of="kindOf" @edit="edit($event)" @toggle="toggle($event)" />' +
                '</div>' +
                '</div>',
            computed: {
                hasChildren() { return this.node.children && this.node.children.length; },
                nodeName() { return this.node.name || this.node.item_number || ''; },
                // has any warnings (zero-cost, missing material, etc.)
                hasWarning() {
                    if (typeof this.warnOf === 'function') {
                        var w = this.warnOf(this.node);
                        return w && w.length > 0;
                    }
                    return false;
                },
            },
            // filtered tree data (search-aware) — NOT used by tree-node;
            // the parent quoteview computes filteredTreeData via filterTree()
            methods: {
                // n === undefined → called from own row click (use own node);
                // n set → re-emit the CHILD's node from the recursive handler
                // (fixes edit opening the parent's entity instead of the child's).
                toggle(n) { this.$emit('toggle', n === undefined ? this.node : n); },
                edit(n) { this.$emit('edit', n === undefined ? this.node : n); },
                // handle Enter key on tree node
                onEnter() { this.edit(); },
            },
        },
    },
    watch: {
        // Reload the tree when entities change (post-edit/import)
        entitiesLen(nv, ov) {
            if (nv !== ov) this.loadTree();
        },
        // tab_url is set by nav.resolveRoute() AFTER a 300ms defer (to outlast
        // forge-nav's own tabUrl watcher). When navigating from the quotes list,
        // quoteview mounts with tab_url='quotes' (no ID) → created() runs with
        // an empty quoteId. This watcher catches the prop update and loads the
        // quote properly. Without it: clicking a quote from the list shows nothing;
        // only page reload works.
        tab_url(nv, ov) {
            if (nv === ov) return;
            var parts = (nv || '').split('/');
            var newId = (parts[1] || parts[0] || '').trim();
            if (newId && newId !== this.quoteId && newId !== 'quotes') {
                this.quoteId = newId;
                this.load();
            }
        },
    },
    created() {
        // quoteId comes from the route: /nav/quotes/<id> → tab_url = quotes/<id>
        var parts = (this.tab_url || '').split('/');
        this.quoteId = parts[1] || parts[0] || '';
        this.loadPrefs();
        this.load();
        this.loadMaterials();
    },
    computed: {
        entitiesLen() { return this.entities.length; },
        // Materials tab: group the take-off by supplier group (Fasteners,
        // Flanges, Pipe, ...) with per-group subtotals — a supplier can
        // price just the materials they carry.
        takeoffGroups() {
            var order = ['Plates & Sheets', 'Sections & Bars', 'Pipe', 'Tube', 'Fittings', 'Flanges', 'Fasteners', 'Other'];
            var groups = {};
            (this.takeoff || []).forEach(function (m) {
                var g = m.group || 'Other';
                if (!groups[g]) groups[g] = [];
                groups[g].push(m);
            });
            var reduce = function (mats) {
                return {
                    cost: mats.reduce(function (s, m) { return s + (parseFloat(m.extended_cost) || 0); }, 0),
                    mass: mats.reduce(function (s, m) { return s + (parseFloat(m.qty_kg) || 0); }, 0),
                    ea: mats.reduce(function (s, m) { return s + (parseFloat(m.qty_ea) || 0); }, 0),
                    m: mats.reduce(function (s, m) { return s + (parseFloat(m.qty_m) || 0); }, 0),
                };
            };
            var out = [];
            order.forEach(function (g) {
                if (groups[g]) out.push({ name: g, materials: groups[g], totals: reduce(groups[g]) });
            });
            Object.keys(groups).forEach(function (g) {
                if (order.indexOf(g) === -1) out.push({ name: g, materials: groups[g], totals: reduce(groups[g]) });
            });
            return out;
        },
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
            if (type === 'text') {
                return v || '';
            }
            return this.fmtMoney(v);
        },
        // kind badge helpers — map cost kind to visual badge
        kindAbbr(kind) {
            const map = { pipe: 'P', flange: 'F', fitting: 'Fit', material: 'M', unknown: '?' };
            return map[kind] || map.unknown;
        },
        kindTextColor(kind) {
            const light = ['material', 'unknown'];
            return light.includes(kind) ? '#111827' : '#ffffff';
        },
        kindBgColor(kind) {
            const pipe = '#d1fae5';
            const flange = '#fcd34d';
            const fitting = '#e9eefb';
            const material = '#e5e7eb';
            const unknown = '#9ca3af';
            const map = { pipe, flange, fitting, material, unknown };
            return map[kind] || unknown;
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
                // NOTE: components must be kept — edititem prefills material/
                // process/paint fields from entity.components; dropping it made
                // the edit form always open empty (and kind never resolved).
                this.entities = (res.entities || []).map(function (e) {
                    return { id: e.id, name: e.name, type: e.type, quantity: e.quantity, data: e.data || {}, components: e.components || [], cost: e.cost || {} };
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
            // fresh tree whenever the tab opens (not just on entity-count changes)
            if (tag === 'tree') this.loadTree();
            if (tag === 'materials') this.loadTakeoff();
            if (tag === 'checks') this.loadCompat();
        },
        // ── Pipe ↔ flange compatibility (Checks tab) ──
        async loadCompat() {
            if (!this.quoteId) return;
            this.compatLoading = true;
            try {
                var res = await WEB.api('./api/boms.php', {
                    action: 'compat',
                    input: { quote_id: this.quoteId },
                });
                var data = (res && res.data) || res || {};
                this.compatIssues = data.issues || [];
                this.compatSuggestions = data.suggestions || [];
            } catch (e) {
                this.compatIssues = [];
                this.compatSuggestions = [];
            } finally {
                this.compatLoading = false;
            }
        },
        // Add a suggested flange to the quote (linked under the pipe)
        async addSuggestedFlange(sugg, flange) {
            var self = this;
            try {
                // 1. Find the pipe entity id by name
                var pipeEntity = null;
                for (var i = 0; i < this.entities.length; i++) {
                    if (this.entities[i].name === sugg.pipe) { pipeEntity = this.entities[i]; break; }
                }
                if (!pipeEntity) { TOAST.show('Pipe not found in this quote', 'error'); return; }
                // 2. Create the flange part
                var created = await WEB.api('./api/entities.php', {
                    action: 'create',
                    input: { type: 'part', name: flange.name, quote_id: this.quoteId, quantity: 1 },
                });
                var ent = (created && created.data) || created || {};
                var entId = ent.id;
                if (!entId) { TOAST.show('Failed to create flange', 'error'); return; }
                // 3. Attach the material
                await WEB.api('./api/components.php', {
                    action: 'create',
                    input: { entity_id: entId, type: 'material', data: { materialLibraryId: flange.id } },
                });
                // 4. Link under the pipe
                await WEB.api('./api/links.php', {
                    action: 'create',
                    input: { from_id: pipeEntity.id, to_id: entId, type: 'contains', quantity: 1 },
                });
                await WEB.api('./api/systems.php', {
                    action: 'recalculate_quote',
                    input: { quote_id: this.quoteId },
                });
                this.load();
                this.loadCompat();
                TOAST.show('Added ' + flange.name, 'success');
            } catch (e) {
                TOAST.show(e.message || 'Failed to add flange', 'error');
            }
        },
        // ── Supplier material take-off (Materials tab) ──
        async loadTakeoff() {
            if (!this.quoteId) return;
            this.takeoffLoading = true;
            try {
                // Ensure cost components are fresh (takeoff reads library + matData)
                var res = await WEB.api('./api/boms.php', {
                    action: 'takeoff',
                    input: { quote_id: this.quoteId },
                });
                var data = (res && res.data) || res || {};
                this.takeoff = data.materials || [];
                this.takeoffTotals = data.totals || { total_mass_kg: 0, total_cost: 0, distinct: 0 };
            } catch (e) {
                this.takeoff = [];
            } finally {
                this.takeoffLoading = false;
            }
        },
        // Split the take-off by supplier group → one RFQ CSV per supplier
        openSplitTakeoff() {
            var self = this;
            if (!this.takeoff.length) {
                TOAST.show('No materials to split', 'error');
                return;
            }
            WEB.api('./api/suppliers.php', { action: 'list', input: { limit: 200 } })
                .then(function (res) {
                    var suppliers = (res && res.data) || res || [];
                    POPUP.show('Send to Suppliers', {
                        comp: 'takeoff-split',
                        props: { groups: self.takeoffGroups, suppliers: suppliers, quote: self.quote },
                        class_body: 'popup_body_lg',
                        events: {
                            cancel: function () { POPUP.close(); },
                            done: function () { POPUP.close(); },
                        },
                    });
                })
                .catch(function () {
                    TOAST.show('Could not load suppliers', 'error');
                });
        },
        // Supplier CSV — flat, paste-ready for a quote request
        exportTakeoffCsv() {
            if (!this.takeoff.length) {
                TOAST.show('No materials to export', 'error');
                return;
            }
            var q = this.quote || {};
            var rows = [
                ['RFQ / Quote', (q.name || '').replace(/,/g, ' ')],
                ['Customer', (((q.data || {}).customerName) || '').replace(/,/g, ' ')],
                ['Date', new Date().toISOString().slice(0, 10)],
                [],
                ['Group', 'Material', 'Grade', 'Size (LxWxT mm)', 'Unit', 'Qty', 'Unit Cost', 'Extended Cost'],
            ];
            var self = this;
            this.takeoffGroups.forEach(function (grp) {
                rows.push([String(grp.name).toUpperCase(), '', '', '', '', '', '', '']);
                grp.materials.forEach(function (m) {
                    rows.push([
                        grp.name,
                        (m.name || '').replace(/,/g, ' '),
                        (m.grade || '').replace(/,/g, ' '),
                        m.dims || '',
                        m.unit || '',
                        m.qty,
                        m.unit_cost,
                        m.extended_cost,
                    ]);
                });
                rows.push(['', String(grp.name) + ' subtotal', '', '', '', '', '', grp.totals.cost.toFixed(2)]);
                rows.push([]);
            });
            rows.push(['GRAND TOTAL', '', '', '', '', '', '', self.takeoffTotals.total_cost]);
            rows.push(['Total Mass (kg)', '', '', '', '', self.takeoffTotals.total_mass_kg]);

            var csv = rows.map(function (r) { return r.join(','); }).join('\n');
            var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            var fname = (q.name || 'quote').replace(/[^a-z0-9]+/gi, '-').replace(/-+/g, '-').toLowerCase();
            a.href = url;
            a.download = fname + '-materials.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            TOAST.show('Materials CSV downloaded', 'success');
        },
        // Supplier PDF — styled pricing schedule for the take-off list
        exportTakeoffPdf() {
            if (!this.takeoff.length) {
                TOAST.show('No materials to export', 'error');
                return;
            }
            var q = this.quote || {};
            var data = q.data || {};
            var currency = data.currency || 'USD';
            var self = this;
            var fmt = function (n) {
                try { return new Intl.NumberFormat('en-US', { style: 'currency', currency: currency }).format(parseFloat(n || 0)); }
                catch (e) { return String(n); }
            };
            var rows = this.takeoff.map(function (m) {
                return '<tr>'
                    + '<td>' + self.esc(m.name || '') + '</td>'
                    + '<td>' + self.esc(m.grade || '') + '</td>'
                    + '<td>' + self.esc(m.profile || '') + '</td>'
                    + '<td>' + self.esc(m.dims || '') + '</td>'
                    + '<td>' + self.esc(m.unit || '') + '</td>'
                    + '<td class="num">' + m.qty + '</td>'
                    + '<td class="num">' + fmt(m.unit_cost) + '</td>'
                    + '<td class="num">' + fmt(m.extended_cost) + '</td>'
                    + '</tr>';
            }).join('');
            var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + self.esc(q.name || 'Quote') + ' — Materials</title>'
                + '<style>body{font-family:sans-serif;padding:2rem;color:#1e293b}h1{font-size:1.4rem;margin-bottom:.25rem}.meta{color:#64748b;font-size:.85rem;margin-bottom:1.5rem}'
                + 'table{width:100%;border-collapse:collapse}th,td{padding:.5rem;border-bottom:1px solid #cbd5e1;text-align:left}td.num,th.num{text-align:right}'
                + 'th{background:#f1f5f9;font-size:.75rem;text-transform:uppercase}tfoot td{font-weight:700;border-top:2px solid #1e293b}'
                + '.total{margin-top:1rem;font-size:1.15rem;font-weight:700;text-align:right}</style></head><body>'
                + '<h1>' + self.esc(q.name || 'Quote') + ' — Material Take-off</h1>'
                + '<div class="meta">Customer: ' + self.esc(data.customerName || '—') + ' &nbsp; Date: ' + new Date().toISOString().slice(0, 10) + ' &nbsp; ' + self.takeoffTotals.distinct + ' materials</div>'
                + '<table><thead><tr><th>Material</th><th>Grade</th><th>Profile</th><th>Size (mm)</th><th>Unit</th><th class="num">Qty</th><th class="num">Unit Cost</th><th class="num">Extended</th></tr></thead>'
                + '<tbody>' + rows + '</tbody>'
                + '<tfoot><tr><td colspan="6">Total Material (' + self.takeoffTotals.total_mass_kg + ' kg)</td><td></td><td class="num">' + fmt(self.takeoffTotals.total_cost) + '</td></tr></tfoot></table>'
                + '</body></html>';
            var win = window.open('', '_blank');
            if (win) { win.document.write(html); win.document.close(); win.focus(); }
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
                comp: 'prefab-picker',
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
                comp: 'quote-items',
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
                            rows: 14,
                            placeholder: 'Paste from Excel (comma, tab, or semicolon separated).\nHeader row optional — any of these columns work:\n\nitem, description, material, qty, length, width, thickness,\nweld_hrs, machine_hrs, boiler_hrs, cut_hrs, drill_hrs, grind_hrs,\nassemble_hrs, qc_hrs, cost_per_m, cost_per_ea, unit_cost, type\n\nExample (nested item numbers = sub-assemblies):\nitem,description,material,qty,length,width,thickness,weld_hrs,type\n1,Skid Frame,,,,,,,assembly\n1.1,Mounting Plate,S235JR Plate 10mm,4,1200,400,10,2.5,part\n1.1.1,M12 Bolt,bolt,16,,,,,fastener',
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
        // Column-name aliases for header-row CSV detection (normalized: lower,
        // non-alphanumerics stripped) → payload key.
        // NOTE: BOM_HEADERS is a file-scope const (see top of file) — a plain
        // object on the component would confuse Vue's methods/options merge.
        normalizeHeader(s) {
            return String(s || '').toLowerCase().replace(/[^a-z0-9]/g, '');
        },
        // Detect whether the first line is a header row: at least 2 cells must
        // resolve to known column aliases. (BOM_HEADERS lives on $options — a
        // plain const on the component, not reactive data.)
        isHeaderRow(parts) {
            var hits = 0;
            for (var i = 0; i < parts.length; i++) {
                if (BOM_HEADERS[this.normalizeHeader(parts[i])]) hits++;
            }
            return hits >= 2;
        },
        // Parse Excel-style rows into boms.php import payload.
        // Supports: (a) header row with any of the named columns, or
        // (b) plain positional rows: item, desc, material, qty, len, w, thick.
        parseBomRows(text) {
            var self = this;
            var lines = String(text || '').split(/\r?\n/).map(function (s) { return s.trim(); });
            var rows = [];
            var headerMap = null;
            var start = 0;

            // Header detection on the first non-empty, non-comment line
            for (var i = 0; i < lines.length; i++) {
                var line = lines[i];
                if (!line || line.charAt(0) === '#') continue;
                var parts = line.split(/[\t;,]/).map(function (s) { return s.trim(); });
                if (this.isHeaderRow(parts)) {
                    headerMap = [];
                    for (var j = 0; j < parts.length; j++) {
                        headerMap.push(BOM_HEADERS[this.normalizeHeader(parts[j])] || null);
                    }
                    start = i + 1;
                }
                break;
            }

            for (var k = start; k < lines.length; k++) {
                var ln = lines[k];
                if (!ln || ln.charAt(0) === '#') continue;
                var cells = ln.split(/[\t;,]/).map(function (s) { return s.trim(); });
                if (!cells.length || (!cells[0] && !cells[1])) continue;

                var row = {};
                if (headerMap) {
                    // Named columns: map by index
                    for (var c = 0; c < cells.length && c < headerMap.length; c++) {
                        var key = headerMap[c];
                        if (!key || cells[c] === '' || cells[c] == null) continue;
                        row[key] = cells[c];
                    }
                } else {
                    // Positional: item, desc, material, qty, len, w, thick
                    row.item_number = cells[0];
                    row.description = cells[1] || 'Item';
                    if (cells[2]) row.material = cells[2];
                    if (cells[3]) row.quantity = cells[3];
                    if (cells[4]) row.length = cells[4];
                    if (cells[5]) row.width = cells[5];
                    if (cells[6]) row.thickness = cells[6];
                }

                // Normalize numeric fields
                row.description = row.description || 'Item';
                if (row.quantity != null && row.quantity !== '') row.quantity = parseInt(row.quantity, 10) || 1;
                ['length', 'width', 'thickness', 'mass', 'costPerM', 'costPerEa', 'unitCost',
                 'welding', 'machining', 'boilermaking', 'cutting', 'drilling', 'grinding', 'bending', 'assembly', 'qualityControl', 'painting'].forEach(function (f) {
                    if (row[f] != null && row[f] !== '') {
                        var n = parseFloat(row[f]);
                        row[f] = isNaN(n) ? null : n;
                    }
                });
                // Type column: normalize to valid value
                if (row.type) {
                    var t = String(row.type).toLowerCase();
                    if (['part', 'assembly', 'fastener'].indexOf(t) === -1) delete row.type;
                }
                if (row.item_number != null || row.description) rows.push(row);
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

                // Material-match feedback: warn about unmatched rows so the
                // user can fix the material column and re-import.
                var unmatched = (data.matches || []).filter(function (m) { return !m.matched; });
                var msg = data.imported + ' items imported'; 
                if (unmatched.length) {
                    msg += ' · ' + unmatched.length + ' material' + (unmatched.length === 1 ? '' : 's') + ' unmatched: ';
                    msg += unmatched.slice(0, 3).map(function (m) { return m.description || m.material || m.item_number; }).join(', ');
                    if (unmatched.length > 3) msg += ' …';
                    TOAST.show(msg, 'warning');
                } else {
                    TOAST.show(msg + ' (all materials matched)', 'success');
                }
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
                this.filteredTreeData = this.treeData;
                // expand the first two levels by default — replace the WHOLE
                // object: $set on keys is not enough because the recursive
                // tree-node receives `open` as a PROP and Vue 2 diff is
                // reference-only — in-place mutation never re-renders children.
                var open = {};
                this.treeData.forEach(function (n) {
                    open[n.id] = true;
                    (n.children || []).forEach(function (c) { open[c.id] = true; });
                });
                this.treeOpen = open;
            } catch (e) {
                this.treeData = [];
                this.treeOpen = {};
            } finally {
                this.treeLoading = false;
            }
        },
        toggleTree(node) {
            // Immutable toggle — new object reference so the recursive
            // tree-nodes (which receive `open` as a prop) actually re-render.
            if (!node || !node.id) return;
            var open = Object.assign({}, this.treeOpen);
            open[node.id] = !open[node.id];
            this.treeOpen = open;
        },
        // ── tree control methods ─────────────────────────────
        collapseAll() {
            this.treeCollapsedAll = true;
            this.treeExpandedAll = false;
            // set all nodes collapsed
            var open = {};
            this._collapseAll(this.treeData, open);
            this.treeOpen = open;
        },
        expandAll() {
            this.treeCollapsedAll = false;
            this.treeExpandedAll = true;
            // set all nodes expanded
            var open = {};
            this._expandAll(this.treeData, open);
            this.treeOpen = open;
        },
        _collapseAll(nodes, open, depth) {
            if (!nodes) return;
            depth = depth || 0;
            for (var i = 0; i < nodes.length; i++) {
                var n = nodes[i];
                open[n.id] = false;
            }
            for (var j = 0; j < nodes.length; j++) {
                if (nodes[j].children && nodes[j].children.length) {
                    this._collapseAll(nodes[j].children, open, depth + 1);
                }
            }
        },
        _expandAll(nodes, open, depth) {
            if (!nodes) return;
            depth = depth || 0;
            for (var i = 0; i < nodes.length; i++) {
                var n = nodes[i];
                open[n.id] = true;
            }
            for (var j = 0; j < nodes.length; j++) {
                if (nodes[j].children && nodes[j].children.length) {
                    this._expandAll(nodes[j].children, open, depth + 1);
                }
            }
        },
        filterTree() {
            if (!this.treeSearch) {
                this.filteredTreeData = this.treeData;
                return;
            }
            var search = this.treeSearch.toLowerCase();
            // recursive filter: keep node if match or has matching descendant
            function keepNode(n) {
                var match = n.name && n.name.toLowerCase().includes(search);
                var hasMatch = false;
                if (!match && n.children) {
                    hasMatch = n.children.some(keepNode);
                }
                return match || hasMatch;
            }
            this.filteredTreeData = this.treeData.filter(keepNode);
            // reset open state for filtered nodes
            this.treeOpen = {};
        },
        // ── per-node enrichment (material + process) ──────
        // Material label from the entity's material component + library row.
        materialOf(node) {
            var e = this.entityById(node.id);
            if (!e) return '';
            var mat = this.findComponent(e, 'material');
            if (!mat) return '';
            var d = mat.data || {};
            var label = '';
            if (d.materialLibraryId) {
                for (var i = 0; i < this.materials.length; i++) {
                    if (this.materials[i].id === d.materialLibraryId) {
                        var m = this.materials[i];
                        label = m.name || '';
                        if (m.od) label += ' Ø' + m.od;
                        if (m.schedule) label += ' ' + m.schedule;
                        break;
                    }
                }
            }
            if (!label && d.category) label = d.category;
            // dimensions: length × width × thickness
            var dims = [d.length, d.width, d.thickness].filter(function (v) { return v != null && v !== ''; });
            if (dims.length) label += (label ? ' · ' : '') + dims.join('×') + (d.unit === 'm' || d.unit === 'm²' ? d.unit : 'mm');
            return label.trim();
        },
        // Process hours summary: trades with non-zero hours, e.g. "W 3.0h · M 1.5h · BM 5.0h"
        processOf(node) {
            var e = this.entityById(node.id);
            if (!e) return '—';
            var proc = this.findComponent(e, 'process');
            if (!proc) return '—';
            var d = proc.data || {};
            var parts = [];
            var abbrev = { boilermaking: 'BM', welding: 'W', machining: 'M', painting: 'PT', assembly: 'AS', qualityControl: 'QC', surfaceTreatment: 'ST', cutting: 'CT', drilling: 'DR', grinding: 'GR', bending: 'BD' };
            this.processTrades.forEach(function (t) {
                var v = parseFloat(d[t]);
                if (v > 0) parts.push((abbrev[t] || t) + ' ' + v.toFixed(1) + 'h');
            });
            return parts.length ? parts.join(' · ') : '—';
        },
        // Kind abbreviation for tree node (pipe/fitting/flange/material)
        kindOf(node) {
            if (!node || !node.id) return '';
            var e = this.entityById(node.id);
            if (!e) return '';
            var costComp = this.findComponent(e, 'cost');
            if (!costComp) return '';
            var kind = (costComp.data && costComp.data.kind) || '';
            if (!kind) return '';
            var map = { pipe: 'pipe', flange: 'flange', fitting: 'fitting', material: 'mat' };
            return map[kind] || '';
        },
        entityById(id) {
            for (var i = 0; i < this.entities.length; i++) {
                if (this.entities[i].id === id) return this.entities[i];
            }
            return null;
        },
        // Roll-up cost for a tree node: for assemblies, sum children's costs × qty.
        // For leaf nodes, return the entity's own cost.
        treeCost(node) {
            if (!node || !node.id) return 0;
            var e = this.entityById(node.id);
            if (!e) return 0;
            var ownCost = (e.cost && e.cost.total) || 0;
            var children = (e.children || node.children || []);
            if (!children || !children.length) return ownCost;
            // assembly: roll up child totals × child quantity
            var rolled = 0;
            for (var i = 0; i < children.length; i++) {
                var childCost = this.treeCost(children[i]);
                var childQty = children[i].quantity || 1;
                rolled += childCost * childQty;
            }
            return ownCost + rolled;
        },
        // Roll-up mass for a tree node: assembly = Σ child mass × qty + own mass
        treeMass(node) {
            if (!node || !node.id) return 0;
            var e = this.entityById(node.id);
            if (!e) return 0;
            var c = e.cost || {};
            var ownMass = c.rolled_mass_kg != null ? c.rolled_mass_kg : (c.massKg || 0);
            ownMass = parseFloat(ownMass) || 0;
            var children = (e.children || node.children || []);
            if (!children || !children.length) return ownMass;
            var rolled = 0;
            for (var i = 0; i < children.length; i++) {
                var childMass = this.treeMass(children[i]);
                var childQty = children[i].quantity || 1;
                rolled += childMass * childQty;
            }
            return ownMass + rolled;
        },
        // WARNING check for a tree node: zero cost, missing material
        nodeWarnings(node) {
            if (!node || !node.id) return [];
            var e = this.entityById(node.id);
            if (!e) return [];
            var warnings = [];
            var cost = (e.cost && e.cost.total) || 0;
            if (cost <= 0) warnings.push({ type: 'zero-cost', msg: 'No cost calculated' });
            var mat = this.findComponent(e, 'material');
            if (!mat) {
                if (e.type === 'part' || e.type === 'assembly') {
                    warnings.push({ type: 'no-material', msg: 'Missing material' });
                }
            }
            return warnings;
        },
        abbr(type) {
            var m = { assembly: 'A', part: 'P', fastener: 'F', quote: 'Q' };
            return m[type] || (type ? type[0].toUpperCase() : '?');
        },
        // NEW: kind abbreviation for tree detail line
        kindAbbr(kind) {
            var map = { pipe: 'pipe', flange: 'flange', fitting: 'fitting', material: 'mat', unknown: '' };
            return map[kind] || map.unknown;
        },
        // ── Entity editor ────────────────────────────────
        async loadMaterials() {
            try {
                var res = await WEB.api('./api/materials.php', { action: 'list', input: { limit: 2000 } });
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
            if (!entity) {
                TOAST.show('Item not found in this quote — refresh and try again', 'error');
                return;
            }

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
        toNumOrNull(v) {
            if (v === '' || v == null) return null;
            var n = parseFloat(v);
            return isNaN(n) ? null : n;
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

                // Assemblies are containers — costs roll up from their children,
                // so they never carry their own material/paint/process data.
                var isAssembly = form.type === 'assembly';

                // 2. Material component (non-assemblies only) — incl. material variables
                if (!isAssembly) {
                    var matData = {
                        materialLibraryId: form.material_id || null,
                        length: this.toNumOrNull(form.length),
                        width: this.toNumOrNull(form.width),
                        thickness: this.toNumOrNull(form.thickness),
                        buttWeldQty: form.buttWeldQty != null && form.buttWeldQty !== '' ? parseInt(form.buttWeldQty, 10) : null,
                        costPerM: form.costPerM != null && form.costPerM !== '' ? parseFloat(form.costPerM) : null,
                        costPerEa: form.costPerEa != null && form.costPerEa !== '' ? parseFloat(form.costPerEa) : null,
                        shopHrsPerKg: form.shopHrsPerKg != null && form.shopHrsPerKg !== '' ? parseFloat(form.shopHrsPerKg) : null,
                        pipeWt: form.pipeWt != null && form.pipeWt !== '' ? parseFloat(form.pipeWt) : null,
                        weldSize: form.weldSize != null && form.weldSize !== '' ? parseFloat(form.weldSize) : null,
                        weldType: form.weldType || null,
                    };
                    if (mat) {
                        await WEB.api('./api/components.php', { action: 'update', input: { id: mat.id, data: matData } });
                    } else if (form.material_id || form.length || form.width || form.thickness) {
                        await WEB.api('./api/components.php', {
                            action: 'create',
                            input: { entity_id: entity.id, type: 'material', data: matData }
                        });
                    }

                    // 2b. Paint & lining options (in-house/sub-contract) → entity.data.onCosts.painting
                    var painting = {};
                    ['extPaint', 'intPaint', 'line', 'coating1', 'coating2', 'coating3', 'coating4', 'transportPerTon'].forEach(function (k) {
                        var v = parseFloat(form.painting && form.painting[k]);
                        painting[k] = isNaN(v) ? 0 : v;
                    });
                    painting.mode = (form.painting && form.painting.mode === 'subcontract') ? 'subcontract' : 'inhouse';
                    var curOnCosts = (entity.data && entity.data.onCosts) || {};
                    var data = { onCosts: Object.assign({}, curOnCosts, { painting: painting }) };
                    await WEB.api('./api/entities.php', { action: 'update', input: { id: entity.id, data: data } });
                }

                // 3. Process component (non-assemblies only, per-trade hours)
                //    Existing comps are ALWAYS updated (even to all-zero) so
                //    clearing hours actually clears — a {} merge would no-op.
                if (!isAssembly) {
                    var procData = {};
                    var self = this;
                    var hasHours = false;
                    this.processTrades.forEach(function (t) {
                        var raw = (form.hours && form.hours[t]) != null ? form.hours[t] : (form[t] != null ? form[t] : '');
                        var n = parseFloat(raw);
                        procData[t] = isNaN(n) ? 0 : n;
                        if (procData[t] > 0) hasHours = true;
                    });
                    if (proc) {
                        await WEB.api('./api/components.php', { action: 'update', input: { id: proc.id, data: procData } });
                    } else if (hasHours) {
                        await WEB.api('./api/components.php', {
                            action: 'create',
                            input: { entity_id: entity.id, type: 'process', data: procData }
                        });
                    }
                }

                TOAST.show('Item saved — recalculating', 'success');
                this.load();
                this.loadTree();
            } catch (e) {
                TOAST.show(e.message || 'Failed to save item', 'error');
            }
        },
        // ── Entities tab ──────────────────────────────────
        materialName(entity) {
            var mat = this.findComponent(entity, 'material');
            if (!mat || !mat.data || !mat.data.materialLibraryId) return '—';
            var id = mat.data.materialLibraryId;
            for (var i = 0; i < this.materials.length; i++) {
                if (this.materials[i].id === id) {
                    var m = this.materials[i];
                    var label = m.name || '';
                    if (m.grade && label.indexOf(m.grade) === -1) label += ' ' + m.grade;
                    if (m.profile && label.indexOf(m.profile) === -1) label += ' ' + m.profile;
                    return label;
                }
            }
            return '—';
        },
        deleteEntity(entity) {
            var self = this;
            POPUP.confirm('Delete Item', 'Delete "' + entity.name + '" from this quote? Its cost is removed from the totals.', function () {
                self.doDeleteEntity(entity);
            });
        },
        async doDeleteEntity(entity) {
            try {
                await WEB.api('./api/entities.php', {
                    action: 'delete',
                    input: { id: entity.id }
                });
                await WEB.api('./api/systems.php', {
                    action: 'recalculate_quote',
                    input: { quote_id: this.quoteId }
                });
                this.load();
                this.loadTree();
                TOAST.show('Item deleted', 'success');
            } catch (e) {
                TOAST.show(e.message || 'Failed to delete item', 'error');
            }
        },
    },
};
