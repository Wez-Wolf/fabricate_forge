/**
 * components/quote/_breadcrumb — navigation trail component.
 * Shows: Quotes / Customer / Quote#ID / CurrentTab
 */
var comp = {
    mixins: [COMP.base],
    props: ['quote', 'quote_id', 'active_tab', 'tabs'],
    data() {
        return {};
    },
    computed: {
        breadcrumbs() {
            var items = [
                { label: 'Quotes', href: '#/nav/quotes' },
            ];

            if (this.quote) {
                var customer = (this.quote.data && this.quote.data.customerName) || 'Unnamed';
                items.push({
                    label: customer,
                    href: '#/nav/quotes'
                });

                var quoteName = this.quote.name || ('Quote #' + (this.quote_id || ''));
                items.push({
                    label: quoteName,
                    href: '#/nav/quotes/' + this.quote_id
                });
            }

            if (this.active_tab && this.tabs) {
                var tab = this.tabs.find(t => t.key === this.active_tab);
                if (tab) {
                    items.push({
                        label: tab.name,
                        current: true
                    });
                }
            }

            return items;
        }
    },
    methods: {
        navigate(href) {
            if (href) {
                // Use the Forge router if available, else fallback to hash
                if (window.ROUTER && ROUTER.push) {
                    ROUTER.push(href);
                } else {
                    window.location.hash = href;
                }
            }
        }
    }
};
