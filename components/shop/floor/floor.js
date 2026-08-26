/**
 * components/shop-floor — consolidated manufacturing workflow view.
 * Sub-tabs: Orders | Procurement | Production
 * Replaces the separate orders, procurement, production nav tabs.
 * Data loads lazily — only the active tab's API is called.
 */
var comp = {
    mixins: [COMP.base],
    props: ['tab_url'],
    data() {
        return {
            activeSubTab: 'orders',
            subTabs: [
                { key: 'orders',      name: 'Orders' },
                { key: 'procurement', name: 'Procurement' },
                { key: 'production',  name: 'Production' },
            ],
            ordersLoading: false,
            poLoading: false,
            prodLoading: false,
            orders: [],
            purchaseOrders: [],
            productionRecords: [],
        };
    },
    created() {
        var sub = (this.tab_url || '').split('/')[1] || 'orders';
        this._bootTab = true; // suppress the watcher for the boot-time assignment
        this.activeSubTab = ['orders', 'procurement', 'production'].includes(sub) ? sub : 'orders';
    },
    watch: {
        // Lazy-load on tab switches after boot; the initial load happens in
        // mounted() (created()'s assignment is suppressed via _bootTab so the
        // same tab isn't fetched twice).
        activeSubTab(tab) {
            if (this._bootTab) { this._bootTab = false; return; }
            this.loadTab(tab);
        },
    },
    mounted() {
        this.loadTab(this.activeSubTab);
    },
    methods: {
        async loadTab(tab) {
            if (tab === 'orders') {
                this.ordersLoading = true;
                try {
                    var res = await WEB.api('./api/orders.php', { action: 'list', input: { auth_id: LS.get('auth_id') } });
                    this.orders = (res && res.data) || [];
                } catch (e) {
                    TOAST.show(e.message || 'Failed to load orders', 'error');
                } finally {
                    this.ordersLoading = false;
                }
            } else if (tab === 'procurement') {
                this.poLoading = true;
                try {
                    var res2 = await WEB.api('./api/procurement.php', { action: 'po_list', input: { auth_id: LS.get('auth_id') } });
                    this.purchaseOrders = (res2 && res2.data) || [];
                } catch (e) {
                    TOAST.show(e.message || 'Failed to load purchase orders', 'error');
                } finally {
                    this.poLoading = false;
                }
            } else if (tab === 'production') {
                this.prodLoading = true;
                try {
                    var res3 = await WEB.api('./api/production.php', { action: 'record_list', input: { auth_id: LS.get('auth_id') } });
                    this.productionRecords = (res3 && res3.data) || [];
                } catch (e) {
                    TOAST.show(e.message || 'Failed to load production records', 'error');
                } finally {
                    this.prodLoading = false;
                }
            }
        },
        fmtMoney(v) {
            if (v == null || v === '') return '—';
            var num = parseFloat(v);
            if (isNaN(num)) return '—';
            var fmt = new Intl.NumberFormat(navigator.language || 'en-US', {
                style: 'decimal', minimumFractionDigits: 2,
            });
            return fmt.format(num);
        },
    },
};