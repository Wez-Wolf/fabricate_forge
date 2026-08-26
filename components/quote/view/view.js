/**
 * components/quote/view — quote detail shell.
 * Thin: loads only the quote HEADER (name/status/customer/currency/margin),
 * renders the tabs, and passes quote-id down. Each sub-tab loads its own
 * domain data (entities, tree, takeoff, compat) via the quote id.
 */
var comp = {
    mixins: [COMP.base],
    props: ['tab_url'],
    data() {
        return {
            quoteId: '',
            quote: null,
            entities: [],
            costs: {},
            refreshToken: 0,
            totals: {},
            marginPercent: null,
            loading: false,
            error: '',
            activeTab: 'docs',
            prefCurrency: '',
            // Dirty-state guard (#3): child tabs report unsaved edits via
            // @dirty so we can warn before leaving/navigating away.
            dirty: false,
            // Top-level tabs, in the quote lifecycle order:
            //   Docs (what do they want us to quote on)
            //   Entities (what is it — the table)
            //   Materials (what we work with / what it's made from)
            //   Process (what we do to it)
            //   Cost (how much it costs)
            // Overview dropped: identity shows in the header, cost summary
            // lives in the Cost tab. Sub-views (Tree/BOM/RFQ) stay hosted
            // inside Entities/Materials; Process is promoted to top-level.
            tabs: [
                { key: 'docs',      tag: 'docs',      name: 'Docs',      svg: 'file-text' },
                { key: 'entities',  tag: 'entities',  name: 'Entities',  svg: 'package' },
                { key: 'materials', tag: 'materials', name: 'Materials', svg: 'boxes' },
                { key: 'process',   tag: 'process',   name: 'Process',   svg: 'wrench' },
                { key: 'cost',      tag: 'cost',      name: 'Cost',      svg: 'calculator' },
            ],
        };
    },
    components: {
        // Breadcrumb trail
        'quote-breadcrumb': function (resolve) { COMP.externLoadComponent(resolve, null, 'quote-breadcrumb'); },
        // Each tab is its own lazy-loaded component under components/quote/<tab>/
        'quote-entities': function (resolve) { COMP.externLoadComponent(resolve, null, 'quote-entities'); },
        'quote-bom': function (resolve) { COMP.externLoadComponent(resolve, null, 'quote-bom'); },
        'quote-materials': function (resolve) { COMP.externLoadComponent(resolve, null, 'quote-materials'); },
        'quote-tree': function (resolve) { COMP.externLoadComponent(resolve, null, 'quote-tree'); },
        'quote-process': function (resolve) { COMP.externLoadComponent(resolve, null, 'quote-process'); },
        'quote-rfq': function (resolve) { COMP.externLoadComponent(resolve, null, 'quote-rfq'); },
        'quote-docs': function (resolve) { COMP.externLoadComponent(resolve, null, 'quote-docs'); },
        'quote-cost': function (resolve) { COMP.externLoadComponent(resolve, null, 'quote-cost'); },
    },
    watch: {
        // tab_url arrives as 'quotes/<id>' or 'quotes/<id>/<tab>' (nav.resolveRoute).
        // Catches the prop update (initial mount may get tab_url='quotes').
        tab_url(nv) {
            var prev = this.quoteId;
            this.parseRoute();
            if (this.quoteId && this.quoteId !== 'quotes' && this.quoteId !== prev) this.load();
        },
    },
    beforeRouteLeave: undefined, // (custom Forge router — no Vue Router guards)
    created() {
        this.parseRoute();
        this.loadPrefs();
        // Docs is the default tab (what they want us to quote on); only a
        // URL deep-link selects another (parseRoute above sets it).
        // Guard against losing unsaved edits on tab close / refresh (#3):
        // native beforeunload is the reliable hook in this custom-router SPA.
        this._onBeforeUnload = (e) => {
            if (this.dirty) {
                e.preventDefault();
                e.returnValue = ''; // triggers the browser's native confirm
            }
        };
        window.addEventListener('beforeunload', this._onBeforeUnload);
        if (this.quoteId && this.quoteId !== 'quotes') this.load();
    },
    beforeDestroy() {
        if (this._onBeforeUnload) window.removeEventListener('beforeunload', this._onBeforeUnload);
    },
    computed: {
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
    },
    methods: {
        async loadPrefs() {
            try {
                var res = await WEB.api('./api/user.php', { action: 'get_preferences', input: {} });
                var p = (res && res.data) || res || {};
                if (p.defaultCurrency) this.prefCurrency = p.defaultCurrency;
            } catch (e) { /* keep empty → falls back to USD */ }
        },
        async load() {
            if (!this.quoteId) return;
            this.loading = true;
            this.error = '';
            try {
                // Systems-mediated reads (ADR): two pure calls compose the
                // whole page — summary via quotes.get (= systems.overview) and
                // member rows via systems.entity_items (link-table structure,
                // persisted cost comp embedded per row). No ECS-core calls, no
                // recalc: reads never execute systems.
                var [ov, items] = await Promise.all([
                    WEB.api('./api/quotes.php', { action: 'get', input: { quote_id: this.quoteId } }),
                    WEB.api('./api/systems.php', { action: 'entity_items', input: { entity_id: this.quoteId, lens: 'entity', limit: 4000 } }),
                ]);
                var rows = (items && (items.items || items)) || [];
                var costs = {};
                var entities = rows.map(function (e) {
                    e.quantity = parseFloat(e.quantity) || 1;
                    e.data = e.data || {};
                    e.components = e.components || [];
                    e.cost = e.cost || {};
                    costs[e.id] = e.cost;
                    return e;
                });

                this.quote = ov.quote || null;
                this.entities = entities;
                this.costs = costs;
                this.totals = ov.totals || {};
                this.marginPercent = ov.margin_percent != null ? parseFloat(ov.margin_percent) : null;
                this.refreshToken++; // tabs with self-loaded data refetch
            } catch (e) {
                this.error = e.message || 'Failed to load quote';
            } finally {
                this.loading = false;
            }
        },
        // Parse quote id + active tab from tab_url ('quotes/<id>' or
        // 'quotes/<id>/<tab>'). Deep-linked sub-tabs survive refresh (issue:27).
        parseRoute() {
            var seg = (this.tab_url || '').split('/').filter(Boolean);
            this.quoteId = seg[1] || '';
            if (seg[2] && this.tabs.some((t) => t.tag === seg[2])) this.activeTab = seg[2];
        },
        setTab(tag) {
            this.activeTab = tag;
            // Keep the URL in sync so the current sub-tab deep-links and
            // survives refresh. Overview is the default → bare '/nav/quotes/<id>'.
            var id = this.quoteId;
            if (!id) return;
            var target = '/nav/quotes/' + id + (tag && tag !== 'overview' ? '/' + tag : '');
            if (location.pathname !== target) ROUTER.navigate(target);
        },
        onTabSelect(tag) {
            this.setTab(tag);
        },
        // ── dirty-state guard (#3) ──────────────────
        markDirty(v) {
            this.dirty = !!v;
        },
        confirmLeave() {
            // Used by goBack + route transitions when dirty.
            if (this.dirty && !window.confirm('Discard your unsaved changes in this quote?')) return false;
            this.dirty = false;
            return true;
        },
        goBack() {
            if (!this.confirmLeave()) return;
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
        // quote-global margin editor (default from Settings, per-quote override)
        openMarginEditor() {
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
                    submit: (form) => {
                        this.saveQuoteMargin(form);
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
                    action: 'recalculate_entity',
                    input: { entity_id: this.quoteId }
                });
                this.load();
                TOAST.show('Margin updated', 'success');
            } catch (e) {
                TOAST.show(e.message || 'Failed to save margin', 'error');
            }
        },
    },
};
