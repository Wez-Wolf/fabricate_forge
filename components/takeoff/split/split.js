/**
 * components/takeoffsplit — split the material take-off by supplier group.
 * Each group gets a checkbox + a supplier assignment; "Generate RFQ CSVs"
 * downloads one CSV per supplier containing their checked groups.
 *
 * Props: { groups: [{ name, materials, totals }], suppliers: [{ id, company_name }], quote: {} }
 * Emits: { cancel }
 */
var comp = {
    mixins: [COMP.base],
    props: {
        groups: { type: Array, default: function () { return []; } },
        suppliers: { type: Array, default: function () { return []; } },
        quote: { type: Object, default: function () { return {}; } },
    },
    data() {
        var sel = {};
        (this.groups || []).forEach(function (g) {
            sel[g.name] = { checked: true, supplier_id: '' };
        });
        return { sel: sel };
    },
    computed: {
        selectedCount() {
            var n = 0;
            var self = this;
            (this.groups || []).forEach(function (g) { if (self.sel[g.name] && self.sel[g.name].checked) n++; });
            return n;
        },
        // distinct recipients: supplier ids + 'unassigned'
        fileCount() {
            var ids = {};
            var self = this;
            (this.groups || []).forEach(function (g) {
                if (self.sel[g.name] && self.sel[g.name].checked) {
                    ids[self.sel[g.name].supplier_id || '__unassigned__'] = true;
                }
            });
            return Object.keys(ids).length;
        },
    },
    methods: {
        fmt(n) {
            try {
                return new Intl.NumberFormat('en-US', { style: 'currency', currency: (this.quote.data && this.quote.data.currency) || 'USD' }).format(parseFloat(n || 0));
            } catch (e) { return String(n || 0); }
        },
        supplierName(id) {
            if (!id) return 'Unassigned';
            for (var i = 0; i < this.suppliers.length; i++) {
                if (this.suppliers[i].id === id) return this.suppliers[i].company_name;
            }
            return 'Supplier';
        },
        generate() {
            var self = this;
            var byRecipient = {};
            (this.groups || []).forEach(function (g) {
                if (!self.sel[g.name] || !self.sel[g.name].checked) return;
                var key = self.sel[g.name].supplier_id || '__unassigned__';
                if (!byRecipient[key]) byRecipient[key] = { name: self.supplierName(key), materials: [] };
                byRecipient[key].materials = byRecipient[key].materials.concat(g.materials);
            });

            var q = this.quote || {};
            Object.keys(byRecipient).forEach(function (key) {
                var r = byRecipient[key];
                var csv = self.buildCsv(q, r.materials, r.name);
                self.downloadCsv(csv, q.name, r.name);
            });
            TOAST.show(Object.keys(byRecipient).length + ' RFQ CSV' + (Object.keys(byRecipient).length === 1 ? '' : 's') + ' generated', 'success');
            this.$emit('done');
        },
        buildCsv(q, materials, recipient) {
            var rows = [
                ['RFQ / Quote', (q.name || '').replace(/,/g, ' ')],
                ['Customer', (((q.data || {}).customerName) || '').replace(/,/g, ' ')],
                ['Supplier', recipient.replace(/,/g, ' ')],
                ['Date', new Date().toISOString().slice(0, 10)],
                [],
                ['Group', 'Material', 'Grade', 'Size (LxWxT mm)', 'Unit', 'Qty', 'Unit Cost', 'Extended Cost'],
            ];
            var self = this;
            materials.forEach(function (m) {
                rows.push([
                    m.group || '',
                    (m.name || '').replace(/,/g, ' '),
                    (m.grade || '').replace(/,/g, ' '),
                    m.dims || '',
                    m.unit || '',
                    m.qty,
                    m.unit_cost,
                    m.extended_cost,
                ]);
            });
            var total = materials.reduce(function (s, m) { return s + (parseFloat(m.extended_cost) || 0); }, 0);
            rows.push([]);
            rows.push(['SUBTOTAL', '', '', '', '', '', '', total.toFixed(2)]);
            return rows.map(function (r) { return r.join(','); }).join('\n');
        },
        downloadCsv(csv, quoteName, recipient) {
            var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            var fname = (quoteName || 'quote').replace(/[^a-z0-9]+/gi, '-').replace(/-+/g, '-').toLowerCase();
            var rname = recipient.replace(/[^a-z0-9]+/gi, '-').replace(/-+/g, '-').toLowerCase();
            a.href = url;
            a.download = fname + '-' + rname + '-rfq.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        },
        cancel() {
            this.$emit('cancel');
        },
    },
};
