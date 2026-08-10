/**
 * components/edititem — Edit Item form (POPUP body) for quoteview.
 * Replaces the forge-form popup: name/type/qty + materialselect
 * (searchable library picker) + dimensions + all process-trade hours.
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
            form: {
                name: '',
                type: 'part',
                quantity: 1,
                material_id: '',
                length: '',
                width: '',
                thickness: '',
                hours: {},
            },
        };
    },
    created() {
        var e = this.entity || {};
        var mat = this.findComponent(e, 'material');
        var matData = (mat && mat.data) || {};
        var proc = this.findComponent(e, 'process');
        var procData = (proc && proc.data) || {};

        this.form.name = e.name || '';
        this.form.type = e.type || 'part';
        this.form.quantity = parseInt(e.quantity, 10) || 1;
        this.form.material_id = matData.materialLibraryId || '';
        this.form.length = matData.length || '';
        this.form.width = matData.width || '';
        this.form.thickness = matData.thickness || '';

        var self = this;
        this.trades.forEach(function (t) {
            self.form.hours[t] = procData[t] != null ? parseFloat(procData[t]) : '';
        });
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
        onMaterialPick(m) {
            if (m && m.id) {
                this.form.material_id = m.id;
            } else {
                this.form.material_id = '';
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
