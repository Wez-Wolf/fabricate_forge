var comp = {
    data() {
        return {
            features: [
                { icon: 'file-text', title: 'Quotes & Pricing', desc: 'Build fabrication quotes with a 5-layer cost model — material, process, on-costs, transport, margin.' },
                { icon: 'boxes', title: 'BOM Management', desc: 'Import Excel BOMs, auto-match materials, and resolve assemblies through an entity-component system.' },
                { icon: 'factory', title: 'Material Library', desc: '100+ seeded plates, sections, pipes, and fasteners with density-based mass and cost calculation.' },
                { icon: 'bar-chart', title: 'Reports & Export', desc: 'Pricing schedules, PDF export, and pipeline/revenue tracking across your quote book.' },
            ],
        };
    },
    methods: {
        goLogin() { ROUTER.navigate('/login'); },
        goForgot() { ROUTER.navigate('/forgot-password'); },
        goSignup() { ROUTER.navigate('/signup'); },
    },
};
