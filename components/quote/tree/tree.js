/**
 * components/quote/tree — the Entity Hierarchy Tree tab.
 * Self-loading: fetches the tree (systems.entity_tree); material labels
 * ride on the composed rows, so no separate material-library call. Precomputes per-node enrichment/rollups,
 * and renders the recursive <forge-tree> component. The BOM hierarchy
 * (quote → assembly → part → fastener) is expressed as forge-tree's
 * self-recursion; this component owns the per-node BODY via the #entity
 * slot (identity + material/process/cost layers), plus search/filter,
 * enrichment, and node edit (edititem popup).
 */
// Trade → short label map + kind map — module-level (used by buildTreeMeta
// enrichment and the materialLabel/processSummary methods).
var PROMAP = { boilermaking: 'BM', welding: 'W', machining: 'M', painting: 'PT', assembly: 'AS', qualityControl: 'QC', surfaceTreatment: 'ST', cutting: 'CT', drilling: 'DR', grinding: 'GR', bending: 'BD' };
var KINDMAP = { pipe: 'pipe', flange: 'flange', fitting: 'fitting', material: 'mat' };

var comp = {
    mixins: [COMP.base, FAB_EDIT_MIXIN],
    props: ['quoteId', 'entities', 'quote'],
    data() {
        return {
            treeLoading: false,
            treeData: [],
            treeSearch: '',
            filteredTreeData: [],
            treeMeta: {},
            query: '',
            // bumped to force re-mount of all forge-tree roots (collapse/expand all)
            treeVersion: 0,
            expanded: {},   // id → bool, drives forge-tree root isExpanded via remount
        };
    },
    created() {
        this.loadTree();
        this.buildTreeMeta();
    },
    watch: {
        // shell refetches after a mutation → props update → rebuild enrichment
        entities() {
            this.injectComponentNodes(this.treeData);
            this.buildTreeMeta();
        },
    },
    methods: {
        // ── data loading ────────────────────────────────
        async loadTree() {
            if (!this.quoteId) return;
            this.treeLoading = true;
            try {
                var res = await WEB.api('./api/systems.php', {
                    action: 'entity_tree',
                    input: { entity_id: this.quoteId, depth: 20 }
                });
                var tree = (res && res.data) || res || {};
                this.treeData = tree.children || [];
                this.filteredTreeData = this.treeData;
                // Each entity node gets its material + process components injected
                // as expandable child nodes (the "dropdown per part").
                this.injectComponentNodes(this.treeData);
                this.buildTreeMeta();
            } catch (e) {
                this.treeData = [];
                this.filteredTreeData = [];
            } finally {
                this.treeLoading = false;
            }
        },
        // ── collapse / expand all ──────────────────────
        // forge-tree owns per-node expansion; these force a re-mount of all
        // roots with a fresh expanded map so every node collapses/expands.
        collapseAll() {
            var e = {};
            var self = this;
            (function walk(nodes) {
                (nodes || []).forEach(function (n) {
                    e[n.id] = false;
                    walk(n.children);
                });
            })(this.treeData);
            this.expanded = e;
            this.treeVersion++;
        },
        expandAll() {
            var e = {};
            var self = this;
            (function walk(nodes) {
                (nodes || []).forEach(function (n) {
                    e[n.id] = true;
                    walk(n.children);
                });
            })(this.treeData);
            this.expanded = e;
            this.treeVersion++;
        },
        // ── search / filter ─────────────────────────────
        onFilter() {
            this.filterTree(this.query);
        },
        filterTree(term) {
            if (typeof term === 'string') this.treeSearch = term;
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
        },
        // ── per-node enrichment (material + process) ──────
        buildTreeMeta() {
            var nodes = this.treeData || [];
            var entities = this.entities || [];

            // O(1) lookups
            var entById = {};
            for (var i = 0; i < entities.length; i++) entById[entities[i].id] = entities[i];

            function kindFor(entity) {
                if (!entity) return '';
                var comps = entity.components || [];
                for (var k = 0; k < comps.length; k++) {
                    if (comps[k].type === 'cost') {
                        var kind = (comps[k].data && comps[k].data.kind) || '';
                        return KINDMAP[kind] || '';
                    }
                }
                return '';
            }

            var meta = {};
            try {
            // post-order so a parent's rollup can read already-computed children
            function build(node) {
                var e = entById[node.id];
                // synthetic material/process nodes have no entity row — zero meta, no warnings
                if (!e && node.__synthetic) {
                    meta[node.id] = { cost: 0, mass: 0, kind: '', mat: '', proc: '', warnings: [] };
                    return meta[node.id];
                }
                // Use the systems.php ROLLED totals (own + all descendants × link
                // qty × entity qty). systems.php already computes these correctly
                // for every node, so we must NOT re-add children here — doing so
                // would double-count assemblies that carry quantity (e.g. Spool x91).
                var c = (e && e.cost) || {};
                var cost = (c.rolled_total != null) ? c.rolled_total : parseFloat(c.total || 0);
                var mass = (c.rolled_mass_kg != null) ? c.rolled_mass_kg : parseFloat(c.massKg || 0);
                // children are still walked (for warnings/kind) but do NOT contribute
                // cost/mass — rolled totals already include them.
                var childArray = node.children || [];
                for (var d = 0; d < childArray.length; d++) {
                    build(childArray[d]);
                }
                var warnings = [];
                if (cost <= 0) warnings.push({ type: 'zero-cost', msg: 'No cost calculated' });
                if (e) {
                    var hasMat = false;
                    for (var k2 = 0; k2 < (e.components || []).length; k2++) {
                        if (e.components[k2].type === 'material') { hasMat = true; break; }
                    }
                    if (!hasMat && e.type === 'part') warnings.push({ type: 'no-material', msg: 'Missing material' });
                }
                meta[node.id] = { cost: cost, mass: mass, kind: kindFor(e), mat: this.materialLabel(e), proc: this.processSummary(e) || '—', warnings: warnings };
                return meta[node.id];
            }
            for (var r = 0; r < nodes.length; r++) build(nodes[r]);
            } catch (err) { /* keep meta partial/best-effort — never let this break load */ }
            this.treeMeta = meta;
        },
        // ── component children (the "dropdown per part") ──
        // Inject each entity's material + process components as synthetic
        // child nodes, so every part is expandable and shows its material
        // and process as a sub-level. These are leaves (no recursion).
        injectComponentNodes(nodes) {
            if (!nodes) return;
            for (var i = 0; i < nodes.length; i++) {
                var n = nodes[i];
                if (!n || n.__synthetic) continue;
                var e = this.entityById(n.id);
                var subs = [];
                if (e) {
                    var mat = this.findComponent(e, 'material');
                    if (mat) {
                        subs.push({
                            id: 'mat-' + n.id,
                            link_id: 'mat-' + n.id,
                            name: this.materialLabel(e) || 'Material',
                            type: 'material',
                            quantity: 1,
                            children: [],
                            __synthetic: 'material'
                        });
                    }
                    var proc = this.findComponent(e, 'process');
                    if (proc) {
                        subs.push({
                            id: 'proc-' + n.id,
                            link_id: 'proc-' + n.id,
                            name: this.processSummary(e) || 'Process',
                            type: 'process',
                            quantity: 1,
                            children: [],
                            __synthetic: 'process'
                        });
                    }
                }
                // idempotent inject: update existing synthetic children's labels,
                // add missing ones (labels ride on component rows, arrive with the
                // entities refetch after the first build)
                var existing = n.children || [];
                subs.forEach(function (s) {
                    var found = existing.find(function (c) { return c.__synthetic && c.__synthetic === s.__synthetic && c.id === s.id; });
                    if (found) { found.name = s.name; }
                    else { existing.push(s); }
                });
                n.children = existing;
                // recurse into REAL children only (synthetic are leaves)
                for (var j = 0; j < n.children.length; j++) {
                    if (!n.children[j].__synthetic) this.injectComponentNodes([n.children[j]]);
                }
            }
        },
        // material label for a component child node
        materialLabel(entity) {
            if (!entity) return '';
            var comps = entity.components || [];
            var mat = null;
            for (var k = 0; k < comps.length; k++) if (comps[k].type === 'material') { mat = comps[k]; break; }
            if (!mat) return '';
            var d = mat.data || {};
            // material_label rides on the component row (attached by
            // api/components.php get_by_quote) — no client-side library fetch.
            var label = mat.material_label || '';
            if (!label && d.category) label = d.category;
            var dims = [d.length, d.width, d.thickness].filter(function (v) { return v != null && v !== ''; });
            if (dims.length) label += (label ? ' · ' : '') + dims.join('×') + (d.unit === 'm' || d.unit === 'm²' ? d.unit : 'mm');
            return label.trim();
        },
        // process summary for a component child node
        processSummary(entity) {
            if (!entity) return '';
            var comps = entity.components || [];
            var proc = null;
            for (var k = 0; k < comps.length; k++) if (comps[k].type === 'process') { proc = comps[k]; break; }
            if (!proc) return '';
            var d = proc.data || {};
            var parts = [];
            for (var t = 0; t < this.processTrades.length; t++) {
                var trade = this.processTrades[t];
                var v = parseFloat(d[trade]);
                if (v > 0) parts.push((PROMAP[trade] || trade) + ' ' + v.toFixed(1) + 'h');
            }
            return parts.length ? parts.join(' · ') : '';
        },
        // ── per-node enrichment accessors (called from the #entity slot template)
        sourceInfo(node) {
            var e = this.entityById(node.id);
            if (!e || !e.data) return {};
            return {
                item_no: e.data.boq_item_no || null,
                src_file: e.data.boq_source_file || null,
                desc: e.data.boq_desc || null,
                section: e.data.boq_section || null,
            };
        },
        viewSource(node) {
            var info = this.sourceInfo(node);
            if (!info.src_file) return TOAST.show('No source document linked', 'warning');
            // Open the original BoQ file at the entity's row. PDFs render inline;
            // Excel/CSV download. The file provenance traces back to the upload.
            window.open('/serve.php?id=' + info.src_file + '&auth_id=' + (this.$root.authId || LS.get('auth_id')), '_blank');
        },
        materialOf(node) {
            if (node && node.id && this.treeMeta[node.id]) return this.treeMeta[node.id].mat;
            return '';
        },
        processOf(node) {
            if (node && node.id && this.treeMeta[node.id]) return this.treeMeta[node.id].proc;
            return '—';
        },
        kindOf(node) {
            if (node && node.id && this.treeMeta[node.id]) return this.treeMeta[node.id].kind;
            return '';
        },
        costOf(node) {
            if (node && node.id && this.treeMeta[node.id]) return this.treeMeta[node.id].cost || 0;
            return 0;
        },
        massOf(node) {
            if (node && node.id && this.treeMeta[node.id]) return this.treeMeta[node.id].mass || 0;
            return 0;
        },
        warnOf(node) {
            if (node && node.id && this.treeMeta[node.id]) return this.treeMeta[node.id].warnings || [];
            return [];
        },
        abbr(type) {
            var m = { assembly: 'A', part: 'P', fastener: 'F', quote: 'Q' };
            return m[type] || (type ? type[0].toUpperCase() : '?');
        },
        iconFor(node) {
            var m = { assembly: 'layers', part: 'cube', fastener: 'link', quote: 'file-text' };
            return (node && m[node.type]) || 'circle';
        },
        entityById(id) {
            for (var i = 0; i < this.entities.length; i++) {
                if (this.entities[i].id === id) return this.entities[i];
            }
            return null;
        },
        // ── edit ─────────────────────────────────────────
        onEdit(node) {
            // material/process rows are component children, not editable entities
            if (node && node.__synthetic) return;
            this.editEntity(node);
        },
        editEntity(node) {
            var entity = this.entityById(node.id);
            if (!entity) {
                TOAST.show('Item not found in this quote — refresh and try again', 'error');
                return;
            }
            var mat = this.findComponent(entity, 'material');
            var proc = this.findComponent(entity, 'process');
            POPUP.show('Edit Item', {
                comp: 'edititem',
                props: {
                    entity: entity,
                    trades: this.processTrades,
                    link_id: node.link_id || entity.link_id || null,
                    parent_name: node.parent_name || entity.parent_name || '',
                    parent_qty: node.link_quantity != null ? node.link_quantity
                        : (entity.parent_qty != null ? entity.parent_qty : 1),
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
        // ── edit ─────────────────────────────────────────
        // FAB_EDIT_MIXIN.saveEntity (shared with the Entities tab) calls this
        // after a successful save — the tree is self-loading (fetches its own
        // tree from links.php), so it must refresh here.
        afterSave() {
            this.loadTree();
        },
    },
};
