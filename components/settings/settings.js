/**
 * components/settings — user preferences + company settings.
 * Two forge-form sections: personal prefs (markup, currency) and company
 * process rates (the 8 trades the cost engine prices against).
 */
var comp = {
    data() {
        return {
            prefs: {
                defaultMarkupPercent: 30,
                defaultCurrency: 'USD',
            },
            company: {
                companyName: '',
                defaultMarkupPercent: 30,
            },
            rates: {},
            loading: false,
            saving: false,
            error: '',
            msg: '',
            // process trade rates — 8 core trades the cost engine reads
            rateFields: [
                'boilermaking', 'welding', 'machining', 'cutting',
                'drilling', 'grinding', 'bending', 'assembly',
            ],
            currencies: ['USD', 'EUR', 'GBP', 'ZAR', 'CAD', 'AUD'],
            // team membership (management lives in Admin)
            myTeam: null,
        };
    },
    created() {
        this.load();
        this.loadTeam();
    },
    computed: {
        prefsFields() {
            var self = this;
            return {
                defaultMarkupPercent: {
                    label: 'Default Markup %', type: 'number', min: 0, max: 100,
                    placeholder: '30',
                    func: function (v) { return v; },
                },
                defaultCurrency: {
                    label: 'Default Currency', type: 'select',
                    options: self.currencies,
                },
            };
        },
        companyFields() {
            return {
                companyName: { label: 'Company Name', type: 'text', placeholder: 'Your company' },
                defaultMarkupPercent: { label: 'Company Markup %', type: 'number', min: 0, max: 100, placeholder: '30' },
            };
        },
    },
    methods: {
        fmtNum(v) { return parseFloat(v || 0); },
        async load() {
            this.loading = true;
            this.error = '';
            try {
                var p = await WEB.api('./api/user.php', { action: 'get_preferences', input: {} });
                var pdata = (p && p.data) || p || {};
                if (pdata.defaultMarkupPercent) this.prefs.defaultMarkupPercent = pdata.defaultMarkupPercent;
                if (pdata.defaultCurrency) this.prefs.defaultCurrency = pdata.defaultCurrency;

                var c = await WEB.api('./api/admin.php', { action: 'get_settings', input: {} });
                var cdata = (c && c.data) || c || {};
                if (cdata.companyName) this.company.companyName = cdata.companyName;
                if (cdata.defaultMarkupPercent) this.company.defaultMarkupPercent = cdata.defaultMarkupPercent;

                // rates come as {welding: 90, ...} — flatten into the editable map
                var rates = cdata.defaultRates || {};
                var self = this;
                this.rateFields.forEach(function (t) {
                    self.rates[t] = rates[t] != null ? rates[t] : '';
                });
            } catch (e) {
                this.error = e.message || 'Failed to load settings';
            } finally {
                this.loading = false;
            }
        },
        async savePrefs() {
            this.saving = true;
            this.msg = '';
            this.error = '';
            try {
                await WEB.api('./api/user.php', {
                    action: 'update_preferences',
                    input: {
                        data: {
                            defaultMarkupPercent: this.fmtNum(this.prefs.defaultMarkupPercent),
                            defaultCurrency: this.prefs.defaultCurrency,
                        },
                    },
                });
                this.msg = 'Preferences saved';
                TOAST.show('Preferences saved', 'success');
            } catch (e) {
                this.error = e.message || 'Failed to save';
            } finally {
                this.saving = false;
            }
        },
        async saveCompany() {
            this.saving = true;
            this.msg = '';
            this.error = '';
            try {
                var rates = {};
                var self = this;
                this.rateFields.forEach(function (t) {
                    var v = parseFloat(self.rates[t]);
                    if (!isNaN(v)) rates[t] = v;
                });
                await WEB.api('./api/admin.php', {
                    action: 'update_settings',
                    input: {
                        data: {
                            companyName: this.company.companyName,
                            defaultMarkupPercent: this.fmtNum(this.company.defaultMarkupPercent),
                            defaultRates: rates,
                        },
                    },
                });
                this.msg = 'Company settings saved';
                TOAST.show('Company settings saved', 'success');
            } catch (e) {
                this.error = e.message || 'Failed to save';
            } finally {
                this.saving = false;
            }
        },
        fmtDate(d) { return d ? String(d).slice(0, 10) : ''; },
        async loadTeam() {
            try {
                var self = this;
                var t = await WEB.api('./api/team.php', { action: 'my_team', input: {} });
                var td = (t && t.data) || t || {};
                this.myTeam = { team: td.team || null };
            } catch (e) {
                this.myTeam = { team: null };
            }
        },
    },
};
