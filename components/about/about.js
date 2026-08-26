/**
 * components/about — system overview.
 */
var comp = {
    data() {
        return {
            features: [
                { icon: 'boxes', title: 'ECS Framework', desc: 'Entity-Component-System for flexible entity properties.' },
                { icon: 'clipboard-list', title: 'BOM Management', desc: 'Bill of Materials creation, import and costing.' },
                { icon: 'layers', title: 'Assembly Tracking', desc: 'Hierarchical assembly structure management.' },
                { icon: 'package', title: 'Prefab Library', desc: 'Reusable assemblies instantiated into quotes.' },
                { icon: 'file-text', title: 'Quote Workflow', desc: 'Complete customer request to quote process.' },
                { icon: 'timer', title: 'Process Time Budgets', desc: 'Manufacturing time tracking and variance.' },
                { icon: 'calculator', title: 'Cost Calculators', desc: 'Material and process cost estimation tools.' },
                { icon: 'truck', title: 'Procurement & Orders', desc: 'Purchase orders, suppliers, and delivery tracking.' },
            ],
            stack: ['PHP', 'PostgreSQL', 'Vue 2.6', 'Forge Framework', 'JSONB ECS', 'REST API'],
        };
    },
};
