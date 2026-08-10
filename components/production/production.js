/**
 * components/production — production records + variance.
 * Summary cards + forge-list table; records created via POPUP form.
 */
var comp = {
    data() {
        var self = this;
        return {
            rows: [],           // [entity, trade, estimated, actual, variance, date, id]
            records: [],
            search: '',
            loading: false,
            error: '',
            fields: [
                { label: 'Entity', type: 'function', func: function (row) { return '<span class="C_link">' + esc(row[0]) + '</span>'; } },
                { label: 'Trade', type: 'function', func: function (row) { return '<span class="C_type_pill">' + esc(row[1]) + '</span>'; } },
                { label: 'Est. Hours', type: 'function', func: function (row) { return '<span class="num">' + esc(row[2]) + '</span>'; }, col_cls: 'C_right' },
                { label: 'Actual Hours', type: 'function', func: function (row) { return '<span class="num">' + esc(row[3]) + '</span>'; }, col_cls: 'C_right' },
                { label: 'Variance', type: 'function', func: function (row) { return '<span class="num ' + (row[4] > 0 ? 'C_var_pos' : row[4] < 0 ? 'C_var_neg' : '') + '">' + (row[4] > 0 ? '+' : '') + esc(row[4]) + '</span>'; }, col_cls: 'C_right' },
                { label: 'Completed', type: 'function', func: function (row) { return esc(row[5]); } },
            ],
        };
        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }
    },
    created() {
        this.load();
    },
    computed: {
        totalEst() {
            var s = 0;
            (this.records || []).forEach(function (r) { s += parseFloat(r.estimated_hours || 0); });
            return this.fmtHr(s);
        },
        totalAct() {
            var s = 0;
            (this.records || []).forEach(function (r) { s += parseFloat(r.actual_hours || 0); });
            return this.fmtHr(s);
        },
        variance() {
            return Math.round((this.totalAct - this.totalEst) * 100) / 100;
        },
        varianceClass() {
            return this.variance > 0 ? 'C_var_pos' : this.variance < 0 ? 'C_var_neg' : '';
        },
    },
    methods: {
        fmtHr(v) {
            return Math.round(parseFloat(v || 0) * 100) / 100;
        },
        fmtDate(d) {
            if (!d) return '—';
            var s = String(d).slice(0, 10);
            return s || '—';
        },
        async load() {
            this.loading = true;
            this.error = '';
            try {
                var res = await WEB.api('./api/production.php', { action: 'record_list', input: { limit: 200 } });
                this.records = (res && res.data) || res || [];
                this.rebuild();
            } catch (e) {
                this.error = e.message || 'Failed to load production records';
            } finally {
                this.loading = false;
            }
        },
        rebuild() {
            var self = this;
            var q = (this.search || '').toLowerCase();
            var filtered = this.records.filter(function (r) {
                if (!q) return true;
                return (r.entity_name || '').toLowerCase().indexOf(q) !== -1 ||
                       (r.trade || '').toLowerCase().indexOf(q) !== -1;
            });
            this.rows = filtered.map(function (r) {
                var est = parseFloat(r.estimated_hours || 0);
                var act = parseFloat(r.actual_hours || 0);
                var varH = Math.round((act - est) * 100) / 100;
                return [
                    r.entity_name || '—',
                    r.trade || '—',
                    self.fmtHr(est),
                    self.fmtHr(act),
                    varH,
                    self.fmtDate(r.date_completed),
                    r.id,
                ];
            });
        },
        onFetch(request) { /* single load in created() */ },
        onSearch(val) {
            this.search = val || '';
            this.rebuild();
        },
        openNew() {
            var self = this;
            POPUP.show('Log Production Record', {
                comp: 'forge-form',
                props: {
                    fields: {
                        entity_name: { label: 'Entity / Item Name', placeholder: 'e.g. Skid Frame', required: true },
                        trade: {
                            label: 'Trade', type: 'option',
                            options: {
                                boilermaking: 'Boilermaking', welding: 'Welding', machining: 'Machining',
                                painting: 'Painting', assembly: 'Assembly', cutting: 'Cutting',
                                drilling: 'Drilling', grinding: 'Grinding', bending: 'Bending',
                            },
                        },
                        estimated_hours: { label: 'Estimated Hours', type: 'number', placeholder: '0' },
                        actual_hours: { label: 'Actual Hours', type: 'number', placeholder: '0' },
                        date_completed: { label: 'Date Completed', type: 'date' },
                        notes: { label: 'Notes', type: 'textarea', rows: 2 },
                    },
                    button_label: 'Save Record',
                },
                events: {
                    submit: function (form) {
                        self.createRecord(form);
                        POPUP.close();
                    },
                },
            });
        },
        async createRecord(form) {
            try {
                var res = await WEB.api('./api/production.php', { action: 'record_create', input: form });
                var data = (res && res.data) || res || {};
                if (data.variance) {
                    var v = data.variance;
                    TOAST.show('Record saved — variance ' + (v.variance > 0 ? '+' : '') + v.variance + 'h (' + v.variance_percent + '%)', 'success');
                } else {
                    TOAST.show('Record saved', 'success');
                }
                this.load();
            } catch (e) {
                TOAST.show(e.message || 'Failed to save record', 'error');
            }
        },
    },
};
