/**
 * components/materialedit — full material editor (new + edit).
 * All library fields: specs, physical, kind variables (pipe / fitting /
 * flange), pricing (unit cost + supplier), with a live mass preview.
 * Emits: { submit: { isNew, id?, payload } } or { cancel }.
 */
var comp = {
    mixins: [COMP.base],
    props: {
        material: { type: Object, default: function () { return {}; } },
        suppliers: { type: Array, default: function () { return []; } },
    },
    data() {
        var m = this.material || {};
        var d = (m.data && typeof m.data === 'object' && !Array.isArray(m.data)) ? m.data : {};
        return {
            materialTypes: ['Carbon Steel', 'Stainless Steel', 'Aluminum', 'Copper', 'Brass', 'Titanium', 'Plastic', 'Other'],
            cats: ['plate', 'section', 'pipe', 'tube', 'fitting', 'fastener', 'other'],
            form: {
                name: m.name || '',
                grade: m.grade || '',
                profile: m.profile || '',
                material_type: m.material_type || 'Carbon Steel',
                category: m.category || 'Carbon Steel',
                library_category: m.library_category || 'material',
                density: m.density != null ? m.density : '',
                thickness: m.thickness != null ? m.thickness : '',
                mass_per_meter: m.mass_per_meter != null ? m.mass_per_meter : '',
                mass_per_area: m.mass_per_area != null ? m.mass_per_area : '',
                // pipe/fitting/flange columns
                od: m.od != null ? m.od : '',
                wt: m.wt != null ? m.wt : '',
                schedule: m.schedule || '',
                nb: m.nb != null ? m.nb : '',
                nps: m.nps || '',
                mass_kg: m.mass_kg != null ? m.mass_kg : (d.massKg != null ? d.massKg : ''),
                paint_area_per_m: m.paint_area_per_m != null ? m.paint_area_per_m : (d.paintAreaPerM != null ? d.paintAreaPerM : ''),
                ext_area: m.ext_area != null ? m.ext_area : (d.extArea != null ? d.extArea : ''),
                // data JSONB keys (kind variables)
                dn: d.dn != null ? d.dn : '',
                type: d.type || '',
                rating: d.rating || d.series || '',
                pipeOd: d.pipeOd != null ? d.pipeOd : '',
                // pricing
                unit_cost: m.unit_cost != null ? m.unit_cost : '',
                supplier_id: m.supplier_id || '',
                price_updated_at: m.price_updated_at || '',
            },
        };
    },
    computed: {
        isNew() { return !(this.material && this.material.id); },
        isPipeProfile() { return String(this.form.profile || '').toLowerCase() === 'pipe'; },
        // Live per-unit mass preview from the entered fields
        massPreview() {
            var f = this.form;
            var prof = String(f.profile || '').toLowerCase();
            var dens = parseFloat(f.density) || 7850;
            var label = '';
            var kg = null;
            if (f.library_category === 'fitting' || f.library_category === 'flange') {
                kg = parseFloat(f.mass_kg);
                label = kg ? kg + ' kg/item' : '';
            } else if (prof === 'pipe') {
                var mpm = parseFloat(f.mass_per_meter);
                if (mpm) { kg = mpm; label = kg + ' kg/m'; }
                else if (f.od && f.wt) {
                    var od = parseFloat(f.od), wt = parseFloat(f.wt);
                    kg = Math.PI / 4 * (Math.pow(od, 2) - Math.pow(od - 2 * wt, 2)) / 1e6 * dens;
                    label = kg.toFixed(2) + ' kg/m (calc)';
                }
            } else if (prof === 'plate' || prof === 'sheet') {
                var mpa = parseFloat(f.mass_per_area);
                if (mpa) { kg = mpa; label = kg + ' kg/m²'; }
                else if (f.thickness) {
                    kg = parseFloat(f.thickness) * dens / 1000;
                    label = kg.toFixed(2) + ' kg/m² (calc)';
                }
            } else if (prof === 'flat bar' || prof === 'round bar' || prof === 'square bar') {
                var bm = parseFloat(f.mass_per_meter);
                if (bm) { kg = bm; label = kg + ' kg/m'; }
                else if (f.od || f.thickness) {
                    if (prof === 'round bar' && f.od) kg = Math.PI / 4 * Math.pow(parseFloat(f.od) / 1000, 2) * dens;
                    else if (f.od && f.thickness) kg = parseFloat(f.od) * parseFloat(f.thickness) / 1e6 * dens;
                    else if (f.thickness) kg = Math.pow(parseFloat(f.thickness) / 1000, 2) * dens;
                    if (kg) label = kg.toFixed(2) + ' kg/m (calc)';
                }
            } else if (parseFloat(f.mass_per_meter)) {
                kg = parseFloat(f.mass_per_meter);
                label = kg + ' kg/m';
            }
            return label || '—';
        },
    },
    methods: {
        onCategoryChange() {
            // when switching to a pipe/flange/fitting, hint the profile
            var f = this.form;
            if (f.library_category === 'flange' && !f.profile) f.profile = 'Flange';
            else if (f.library_category === 'fitting' && !f.profile) f.profile = 'Fitting';
            else if (f.library_category === 'fastener' && !f.profile) f.profile = 'Fastener';
        },
        buildPayload() {
            var f = this.form;
            var kind = null;
            if (f.library_category === 'flange') kind = 'flange';
            else if (f.library_category === 'fitting') kind = 'fitting';
            else if (this.isPipeProfile) kind = 'pipe';
            var data = {};
            if (kind) data.kind = kind;
            if (f.dn !== '') data.dn = parseFloat(f.dn);
            if (f.type) data.type = f.type;
            if (f.rating) data.rating = f.rating;
            if (f.pipeOd !== '') data.pipeOd = parseFloat(f.pipeOd);
            if (f.mass_kg !== '') data.massKg = parseFloat(f.mass_kg);
            if (f.paint_area_per_m !== '') data.paintAreaPerM = parseFloat(f.paint_area_per_m);
            if (f.ext_area !== '') data.extArea = parseFloat(f.ext_area);
            if (f.schedule) data.schedule = f.schedule;

            return {
                name: f.name,
                grade: f.grade,
                profile: f.profile,
                material_type: f.material_type,
                category: f.category,
                library_category: f.library_category,
                density: f.density !== '' ? f.density : null,
                thickness: f.thickness !== '' ? f.thickness : null,
                mass_per_meter: f.mass_per_meter !== '' ? f.mass_per_meter : null,
                mass_per_area: f.mass_per_area !== '' ? f.mass_per_area : null,
                od: f.od !== '' ? f.od : null,
                wt: f.wt !== '' ? f.wt : null,
                schedule: f.schedule || null,
                nb: f.nb !== '' ? f.nb : null,
                nps: f.nps || null,
                mass_kg: f.mass_kg !== '' ? f.mass_kg : null,
                paint_area_per_m: f.paint_area_per_m !== '' ? f.paint_area_per_m : null,
                ext_area: f.ext_area !== '' ? f.ext_area : null,
                unit_cost: f.unit_cost !== '' ? f.unit_cost : null,
                supplier_id: f.supplier_id || null,
                data: data,
            };
        },
        submit() {
            if (!this.form.name || !String(this.form.name).trim()) {
                TOAST.show('Material name is required', 'error');
                return;
            }
            this.$emit('submit', { isNew: this.isNew, id: this.material && this.material.id, payload: this.buildPayload() });
        },
        cancel() {
            this.$emit('cancel');
        },
    },
};
