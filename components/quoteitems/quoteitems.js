/**
 * components/quoteitems — batch line-item entry grid for a quote.
 * Rows of Item Name / Type / Qty / Description; emits submit with {items}.
 */
var comp = {
    mixins: [COMP.base],
    data() {
        return {
            types: [
                { v: 'part', label: 'Part' },
                { v: 'assembly', label: 'Assembly' },
                { v: 'fastener', label: 'Fastener' },
            ],
            items: [],
        };
    },
    created() {
        this.addRow();
    },
    computed: {
        validCount() {
            var n = 0;
            for (var i = 0; i < this.items.length; i++) {
                if (this.items[i].name && String(this.items[i].name).trim()) n++;
            }
            return n;
        },
    },
    methods: {
        addRow() {
            this.items.push({ name: '', type: 'part', quantity: 1, description: '' });
        },
        removeRow(i) {
            this.items.splice(i, 1);
            if (!this.items.length) this.addRow();
        },
        submit() {
            var valid = this.items.filter(function (r) {
                return r.name && String(r.name).trim();
            });
            if (!valid.length) {
                TOAST.show('Enter at least one item name', 'error');
                return;
            }
            this.$emit('submit', { items: valid });
        },
        cancel() {
            this.$emit('cancel');
        },
    },
};
