/**
 * components/_help-tooltip — lightweight (i) icon with tooltip.
 * Shows on hover. Used throughout for contextual help.
 */
var comp = {
    mixins: [COMP.base],
    props: {
        text: String,           // tooltip content
        position: {             // tooltip position: top, right, bottom, left
            type: String,
            default: 'top'
        },
        icon: {                 // SVG name or 'info' (default)
            type: String,
            default: 'info'
        }
    },
    data() {
        return {
            show: false
        };
    },
    methods: {
        toggleShow() {
            this.show = !this.show;
        },
        hide() {
            this.show = false;
        }
    }
};
