/**
 * components/edititem — Edit Item form (POPUP body) for quote-view.
 * Replaces the forge-form popup: name/type/qty + material-select
 * (searchable library picker) + dimensions + material variables
 * (butt welds, cost R/m, R/ea, shop handling, weld size, pipe WT)
 * + paint & lining (in-house / sub-contract, coatings 1-4, transport)
 * + all process-trade hours.
 * Emits submit with the form payload.
 */
var comp = {
    mixins: [COMP.base],
    props: {
        entity: { type: Object, required: true },
        trades: { type: Array, default: function () {
            return ['boilermaking', 'welding', 'machining', 'painting', 'assembly', 'qualityControl', 'surfaceTreatment', 'cutting', 'drilling', 'grinding', 'bending'];
        } },
    },
    data() {
        return {
            kind: '',            // 'pipe' | 'flange' | 'fitting' | 'material'
            materials: [],       // library rows (to resolve kind on load)
            // Declarative type list — same options as quote-items/Add Item.
            typeOptions: [
                { v: 'part', label: 'Part' },
                { v: 'assembly', label: 'Assembly' },
                { v: 'fastener', label: 'Fastener' },
            ],
            form: {
                name: '',
                type: 'part',
                quantity: 1,
                material_id: '',
                length: '',
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
                hours: {},
            },
        };
    },
    computed: {
        isAssembly() { return this.form.type === 'assembly'; },
        hasMaterial() { return this.findComponent(this.entity, 'material') != null; },
        hasProcess() { return this.findComponent(this.entity, 'process') != null; },
        // Material section: never for assemblies (containers roll up from
        // children); always for items that already carry a material component
        // (material-only items); else for part/fastener so one can be added.
        showMaterial() {
            if (this.isAssembly) return false;
            if (this.hasMaterial) return true;
            return this.form.type === 'part' || this.form.type === 'fastener';
        },
        // Process section: same rules — process-only items keep it visible
        // even though their material section is blank.
        showProcess() {
            if (this.isAssembly) return false;
            if (this.hasProcess) return true;
            return this.form.type === 'part' || this.form.type === 'fastener';
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
                kg = mpm * (len / 1000);
                unit = 'kg (pipe run)';
            } else {
                var mpm2 = parseFloat(m.mass_per_meter) || 0;
                if (mpm2 > 0 && len > 0) {
                    kg = mpm2 * (len / 1000);
                    unit = 'kg (profile length)';
                } else if (len > 0 && wid > 0 && thk > 0 && dens > 0) {
                    kg = len * wid * thk / 1e9 * dens;
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
        submit() {
            if (!this.form.name) {
                TOAST.show('Item name is required', 'error');
                return;
            }
            this.$emit('submit', this.form);
        },
        cancel() {
            this.$emit('cancel');
        },
    },
};
