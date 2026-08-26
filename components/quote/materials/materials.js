/**
 * components/quote/materials — Supplier Material Take-off tab.
 * Self-loading: fetches its own take-off (boms.php) and owns the group
 * subtotals + CSV/PDF exports + the supplier split popup.
 */

// HTML-escape for the generated PDF (raw HTML in a new window — Vue escaping
// doesn't apply there, so this is the legitimate use case for esc()).
function esc(s) {
    return FAB.esc(s);
}

var comp = {
    mixins: [COMP.base],
    components: {
        'quote-bom': function (resolve) { COMP.externLoadComponent(resolve, null, 'quote-bom'); },
        'quote-rfq': function (resolve) { COMP.externLoadComponent(resolve, null, 'quote-rfq'); },
    },
    props: ['quoteId', 'quote', 'entities'],
    data() {
        return {
            subTab: 'takeoff',
            subTabs: { takeoff: 'Take-off', bom: 'BOM', rfq: 'RFQ' },
            takeoffLoading: false,
            takeoff: [],
            takeoffTotals: { total_mass_kg: 0, total_cost: 0, distinct: 0 },
        };
    },
    created() {
        this.load();
    },
    computed: {
        // Group the take-off by supplier group (Fasteners, Flanges, Pipe, ...)
        // with per-group subtotals — a supplier can price just their materials.
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
    },
    methods: {
        async load() {
            if (!this.quoteId) return;
            this.takeoffLoading = true;
            try {
                var res = await WEB.api('./api/boms.php', {
                    action: 'takeoff',
                    input: { quote_id: this.quoteId },
                });
                var data = (res && res.data) || res || {};
                this.takeoff = data.materials || [];
                this.takeoffTotals = data.totals || { total_mass_kg: 0, total_cost: 0, distinct: 0 };
                if (!this.quote) this.quote = data.quote || null;
            } catch (e) {
                this.takeoff = [];
            } finally {
                this.takeoffLoading = false;
            }
        },
        // quote currency (falls back to USD)
        currency() {
            return (this.quote && this.quote.data && this.quote.data.currency) || 'USD';
        },
        fmtMoney(v) { return FAB.fmtMoney(v, this.currency()); },
        // Split the take-off by supplier group → one RFQ CSV per supplier
        openSplitTakeoff() {
            if (!this.takeoff.length) {
                TOAST.show('No materials to split', 'error');
                return;
            }
            WEB.api('./api/suppliers.php', { action: 'list', input: { limit: 200 } })
                .then((res) => {
                    var suppliers = (res && res.data) || res || [];
                    POPUP.show('Send to Suppliers', {
                        comp: 'takeoff-split',
                        props: { groups: this.takeoffGroups, suppliers: suppliers, quote: this.quote },
                        class_body: 'popup_body_lg',
                        events: {
                            cancel: () => { POPUP.close(); },
                            done: () => { POPUP.close(); },
                        },
                    });
                })
                .catch(() => {
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
            this.takeoffGroups.forEach((grp) => {
                rows.push([String(grp.name).toUpperCase(), '', '', '', '', '', '', '']);
                grp.materials.forEach((m) => {
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
            rows.push(['GRAND TOTAL', '', '', '', '', '', '', this.takeoffTotals.total_cost]);
            rows.push(['Total Mass (kg)', '', '', '', '', this.takeoffTotals.total_mass_kg]);

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
            var fmt = function (n) { return FAB.fmtMoney(n, currency); };
            var rows = this.takeoff.map((m) => {
                return '<tr>'
                    + '<td>' + esc(m.name || '') + '</td>'
                    + '<td>' + esc(m.grade || '') + '</td>'
                    + '<td>' + esc(m.profile || '') + '</td>'
                    + '<td>' + esc(m.dims || '') + '</td>'
                    + '<td>' + esc(m.unit || '') + '</td>'
                    + '<td class="num">' + m.qty + '</td>'
                    + '<td class="num">' + fmt(m.unit_cost) + '</td>'
                    + '<td class="num">' + fmt(m.extended_cost) + '</td>'
                    + '</tr>';
            }).join('');
            var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + esc(q.name || 'Quote') + ' — Materials</title>'
                + '<style>body{font-family:sans-serif;padding:2rem;color:#1e293b}h1{font-size:1.4rem;margin-bottom:.25rem}.meta{color:#64748b;font-size:.85rem;margin-bottom:1.5rem}'
                + 'table{width:100%;border-collapse:collapse}th,td{padding:.5rem;border-bottom:1px solid #cbd5e1;text-align:left}td.num,th.num{text-align:right}'
                + 'th{background:#f1f5f9;font-size:.75rem;text-transform:uppercase}tfoot td{font-weight:700;border-top:2px solid #1e293b}'
                + '.total{margin-top:1rem;font-size:1.15rem;font-weight:700;text-align:right}</style></head><body>'
                + '<h1>' + esc(q.name || 'Quote') + ' — Material Take-off</h1>'
                + '<div class="meta">Customer: ' + esc(data.customerName || '—') + ' &nbsp; Date: ' + new Date().toISOString().slice(0, 10) + ' &nbsp; ' + this.takeoffTotals.distinct + ' materials</div>'
                + '<table><thead><tr><th>Material</th><th>Grade</th><th>Profile</th><th>Size (mm)</th><th>Unit</th><th class="num">Qty</th><th class="num">Unit Cost</th><th class="num">Extended</th></tr></thead>'
                + '<tbody>' + rows + '</tbody>'
                + '<tfoot><tr><td colspan="6">Total Material (' + this.takeoffTotals.total_mass_kg + ' kg)</td><td></td><td class="num">' + fmt(this.takeoffTotals.total_cost) + '</td></tr></tfoot></table>'
                + '</body></html>';
            var win = window.open('', '_blank');
            if (win) { win.document.write(html); win.document.close(); win.focus(); }
        },
    },
};
