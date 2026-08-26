/**
 * components/edititem — Edit Item form (POPUP body) for the quote detail.
 * Tabbed: Detail / Link / Material & Paint / Process.
 *  - Detail: name/type + READ-ONLY global BoQ qty (driven by links, not editable here).
 *  - Link: the SINGLE parent this row is linked to + the quantity in THAT parent
 *    (this parent's contains-link quantity, editable → links.update).
 *  - Material & Paint: material-select + dims + kind vars + paint/lining.
 *  - Process: free-text note + a buildable ops list [{category, hours, summary}].
 * Emits submit with the form payload (incl. link id/qty + ops + note).
 */
var comp = {
    mixins: [COMP.base],
    props: {
        entity: { type: Object, required: true },
        trades: { type: Array, default: function () {
            return ['boilermaking', 'welding', 'machining', 'painting', 'assembly', 'qualityControl', 'surfaceTreatment', 'cutting', 'drilling', 'grinding', 'bending'];
        } },
        // Per-parent link context: the contains-link the current row came from.
        link_id: { type: String, default: null },
        parent_name: { type: String, default: '' },
        parent_qty: { type: [Number, String], default: 1 },
    },
    data() {
        return {
            activeTab: 'detail',
            tabs: [
                { key: 'detail', name: 'Detail' },
                { key: 'link',    name: 'Link' },
                { key: 'material',name: 'Material' },
                { key: 'process', name: 'Process' },
            ],
            kind: '',            // 'pipe' | 'flange' | 'fitting' | 'material'
            materials: [],       // library rows (to resolve kind on load)
            process_ops: [],     // [{category, hours, summary}]
            linkQty: 1,          // editable: quantity in the current parent
            // Declarative type list — same options as quote-items/Add Item.
            typeOptions: [
                { v: 'part', label: 'Part' },
                { v: 'assembly', label: 'Assembly' },
                { v: 'fitting', label: 'Fitting (bought-in)' },
                { v: 'fastener', label: 'Fastener' },
            ],
            form: {
                name: '',
                type: 'part',
                quantity: 1,     // global BoQ qty — READ-ONLY
                material_id: '',
                length: '',
                length_secondary: '',   // D1 green — extra length on top of cut length
                width: '',
                thickness: '',
                buttWeldQty: '',
                costPerM: '',
                costPerEa: '',
                shopHrsPerKg: '',
                pipeWt: '',
                weldSize: '',
                weldType: '',   // flange weld-type override (WN/SO/SW/BLIND/LOOSE)
                painting: {
                    mode: 'inhouse',
                    extPaint: '',
                    intPaint: '',
                    line: '',
                    coating1: '',
                    coating2: '',
                    coating3: '',
                    coating4: '',
                    transportPerTon: '',
                },
                process_note: '',
                process_ops: [],
                // link update payload
                link_id: null,
                link_qty: null,
            },
        };
    },
    computed: {
        isAssembly() { return this.form.type === 'assembly'; },
        hasMaterial() { return this.findComponent(this.entity, 'material') != null; },
        hasProcess() { return this.findComponent(this.entity, 'process') != null; },
        // Human-readable process statement from the ops list, e.g.
        // "BM 0.10h — fit-up the flange to the pipe · W 0.15h — fillet weld".
        processStatement() {
            return this.process_ops
                .filter(function (op) { return (parseFloat(op.hours) || 0) > 0; })
                .map(function (op) {
                    var abbr = (op.category || '')[0] ? op.category[0].toUpperCase() : '';
                    var hrs = parseFloat(op.hours).toFixed(2) + 'h';
                    var sum = (op.summary || '').trim();
                    return (abbr + ' ' + hrs + (sum ? ' — ' + sum : '')).trim();
                })
                .join(' · ');
        },
        // Material section: never for assemblies (containers roll up from
        // children); always for items that already carry a material component
        // (material-only items); else for part/fastener/fitting so one can be
        // added (fittings are bought-in pipe hardware — a flange/elbow/tee).
        showMaterial() {
            if (this.isAssembly) return false;
            if (this.hasMaterial) return true;
            return this.form.type === 'part' || this.form.type === 'fastener' || this.form.type === 'fitting';
        },
        // Process section: ALWAYS available — parts, fittings AND assemblies
        // (D3: a spool assembly carries its own welding process comps while
        // its parts/fittings roll up beneath it).
        showProcess() {
            return true;
        },
        // Derived mass: the part's mass comes from its MATERIAL + dimensions
        // (same rules as cost.php calcMass), NOT entered by hand.
        massPreview() {
            var f = this.form;
            if (!f.material_id) return '—';
            var m = null;
            for (var i = 0; i < this.materials.length; i++) {
                if (this.materials[i].id === f.material_id) { m = this.materials[i]; break; }
            }
            if (!m) return '—';
            var d = (m.data && typeof m.data === 'object' && !Array.isArray(m.data)) ? m.data : {};
            var prof = String(m.profile || '').toLowerCase();
            var cat = String(m.library_category || '').toLowerCase();
            var len = parseFloat(f.length) || 0;
            var lenTotal = len + (parseFloat(f.length_secondary) || 0); // D1 green included
            var wid = parseFloat(f.width) || 0;
            var thk = parseFloat(f.thickness) || parseFloat(m.thickness) || 0;
            var dens = parseFloat(m.density) || 0;
            var kg = 0;
            var unit = '';

            if (cat === 'fastener') {
                kg = d.massKg != null ? parseFloat(d.massKg) : 0;
                unit = 'kg/item';
            } else if (cat === 'fitting' || cat === 'flange') {
                kg = d.massKg != null ? parseFloat(d.massKg) : 0;
                unit = 'kg/item';
            } else if (prof === 'pipe' || prof === 'tube') {
                var mpm = parseFloat(m.mass_per_meter) || 0;
                kg = mpm * (lenTotal / 1000);
                unit = 'kg (pipe run)';
            } else {
                var mpm2 = parseFloat(m.mass_per_meter) || 0;
                if (mpm2 > 0 && lenTotal > 0) {
                    kg = mpm2 * (lenTotal / 1000);
                    unit = 'kg (profile length)';
                } else if (lenTotal > 0 && wid > 0 && thk > 0 && dens > 0) {
                    kg = lenTotal * wid * thk / 1e9 * dens;
                    unit = 'kg (L×W×T)';
                } else if (d.massKg != null) {
                    kg = parseFloat(d.massKg);
                    unit = 'kg/item';
                }
            }
            if (!kg) return '—';
            return kg.toFixed(2) + ' ' + unit;
        },
    },
    created() {
        var self = this;
        var e = this.entity || {};
        var mat = this.findComponent(e, 'material');
        var matData = (mat && mat.data) || {};
        var proc = this.findComponent(e, 'process');
        var procData = (proc && proc.data) || {};
        var onCosts = (e.data && e.data.onCosts) || {};
        var painting = onCosts.painting || {};

        this.form.name = e.name || '';
        this.form.type = e.type || 'part';
        this.form.quantity = parseInt(e.quantity, 10) || 1;
        this.form.material_id = matData.materialLibraryId || '';
        // Keep 0 (not just truthy) — 0-length dims are valid input
        this.form.length = matData.length != null ? matData.length : '';
        this.form.length_secondary = matData.length_secondary != null ? matData.length_secondary : '';
        this.form.width = matData.width != null ? matData.width : '';
        this.form.thickness = matData.thickness != null ? matData.thickness : '';
        this.form.buttWeldQty = matData.buttWeldQty != null ? matData.buttWeldQty : '';
        this.form.costPerM = matData.costPerM != null ? matData.costPerM : '';
        this.form.costPerEa = matData.costPerEa != null ? matData.costPerEa : '';
        this.form.shopHrsPerKg = matData.shopHrsPerKg != null ? matData.shopHrsPerKg : '';
        this.form.pipeWt = matData.pipeWt != null ? matData.pipeWt : '';
        this.form.weldSize = matData.weldSize != null ? matData.weldSize : '';
        this.form.weldType = matData.weldType != null ? matData.weldType : '';

        // Paint & lining defaults mirror the engine's PAINT_RATES consts
        var mode = painting.mode || 'inhouse';
        this.form.painting.mode = mode;
        var d = mode === 'subcontract' ? { ext: 65, int: 55 } : { ext: 45, int: 35 };
        this.form.painting.extPaint = painting.extPaint != null ? painting.extPaint : '';
        this.form.painting.intPaint = painting.intPaint != null ? painting.intPaint : '';
        this.form.painting.line = painting.line != null ? painting.line : '';
        ['coating1', 'coating2', 'coating3', 'coating4'].forEach(function (k) {
            self.form.painting[k] = painting[k] != null ? painting[k] : '';
        });
        this.form.painting.transportPerTon = painting.transportPerTon != null ? painting.transportPerTon : '';

        this.trades.forEach(function (t) {
            self.form.hours[t] = procData[t] != null ? parseFloat(procData[t]) : '';
        });

        // Process note + buildable ops list (new format). If the component still
        // uses the named-field map ({trade: hrs}), seed ops from it so the list
        // is populated; if it already has ops, load them as-is.
        this.form.process_note = procData.note || '';
        var ops = (procData.ops && Array.isArray(procData.ops)) ? procData.ops : [];
        if (!ops.length) {
            ops = [];
            this.trades.forEach(function (t) {
                var hrs = parseFloat(procData[t]);
                if (hrs > 0) ops.push({ category: t, hours: hrs, summary: '' });
            });
        }
        this.process_ops = ops;
        this.form.process_ops = ops;

        // Link context: the single parent this row is linked to + qty in it.
        this.linkQty = this.parent_qty != null ? parseFloat(this.parent_qty) : 1;
        this.form.link_id = this.link_id;
        this.form.link_qty = this.linkQty;

        // Resolve the picked material's kind for field visibility
        this.loadMaterials();
    },
    methods: {
        findComponent(entity, type) {
            var comps = (entity && entity.components) || [];
            for (var i = 0; i < comps.length; i++) {
                if (comps[i].type === type) return comps[i];
            }
            return null;
        },
        tradeLabel(t) {
            return (t.charAt(0).toUpperCase() + t.slice(1)).replace(/([A-Z])/g, ' $1').trim() + ' (h)';
        },
        async loadMaterials() {
            try {
                var res = await WEB.api('./api/materials.php', { action: 'list', input: { limit: 2000 } });
                this.materials = (res && res.data) || res || [];
                this.resolveKind();
            } catch (e) {
                this.materials = [];
            }
        },
        kindOf(m) {
            if (!m) return 'material';
            if (m.data && m.data.kind) return m.data.kind;
            if (m.library_category === 'flange') return 'flange';
            if (m.library_category === 'fitting') return 'fitting';
            if ((m.profile || '').toLowerCase() === 'pipe') return 'pipe';
            return 'material';
        },
        resolveKind() {
            var self = this;
            var found = null;
            this.materials.forEach(function (m) {
                if (!found && m.id === self.form.material_id) found = m;
            });
            if (found) { this.kind = this.kindOf(found); return; }
            // No library row (custom/imported material) — fall back to the
            // material component's own data, same as the cost engine reads it
            // (data.category / data.profile / data.kind).
            var mat = this.findComponent(this.entity, 'material');
            var md = (mat && mat.data) || {};
            if (md.kind) { this.kind = md.kind; return; }
            var cat = String(md.category || '').toLowerCase();
            if (cat === 'flange') { this.kind = 'flange'; return; }
            if (cat === 'fitting') { this.kind = 'fitting'; return; }
            if (cat === 'pipe' || String(md.profile || '').toLowerCase() === 'pipe') { this.kind = 'pipe'; return; }
            this.kind = 'material';
        },
        onMaterialPick(m) {
            if (m && m.id) {
                this.form.material_id = m.id;
                this.kind = this.kindOf(m);
                // sensible defaults for the new kind
                if (this.kind === 'pipe' && this.form.costPerM === '') this.form.costPerM = m.unit_cost || '';
                if ((this.kind === 'flange' || this.kind === 'fitting') && this.form.costPerEa === '') this.form.costPerEa = m.unit_cost || '';
            } else {
                this.form.material_id = '';
                this.kind = 'material';
            }
        },
        // ── Link tab ───────────────────────────────────────
        onTab(tab) {
            this.activeTab = tab;
        },
        // Mirror the editable link qty into the submit payload.
        setLinkQty(v) {
            this.linkQty = parseFloat(v) || 0;
            this.form.link_qty = this.linkQty;
        },
        // ── Process ops list ───────────────────────────────
        addOp() {
            this.process_ops.push({ category: this.form.type === 'assembly' ? '' : 'welding', hours: '', summary: '' });
        },
        removeOp(i) {
            this.process_ops.splice(i, 1);
        },
        onOpCategory(i, val) {
            this.$set(this.process_ops[i], 'category', val);
        },
        submit() {
            if (!this.form.name) {
                TOAST.show('Item name is required', 'error');
                return;
            }
            // Finalize ops + process note into the payload.
            this.form.process_ops = this.process_ops
                .filter(function (op) { return op.hours != null && op.hours !== '' && parseFloat(op.hours) > 0; })
                .map(function (op) { return { category: op.category || '', hours: parseFloat(op.hours) || 0, summary: op.summary || '' }; });
            this.form.link_qty = this.linkQty;
            this.$emit('submit', this.form);
        },
        cancel() {
            this.$emit('cancel');
        },
    },
};
