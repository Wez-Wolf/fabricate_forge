var comp = {
    data() {
        return {
            invited: false,
            inviteLoading: false,
            invite: null,
            features: [
                { icon: 'file-text', title: 'Quotes & Pricing', desc: 'Build fabrication quotes with a 5-layer cost model — material, process, on-costs, transport, margin.' },
                { icon: 'boxes', title: 'BOM Management', desc: 'Import Excel BOMs, auto-match materials, and resolve assemblies through an entity-component system.' },
                { icon: 'factory', title: 'Material Library', desc: '100+ seeded plates, sections, pipes, and fasteners with density-based mass and cost calculation.' },
                { icon: 'bar-chart', title: 'Reports & Export', desc: 'Pricing schedules, PDF export, and pipeline/revenue tracking across your quote book.' },
            ],
        };
    },
    created() {
        // Invite-link visitor (fab_invite cookie set at boot) → resolve team info.
        var m = document.cookie.match(/(?:^|; )fab_invite=([A-Za-z0-9]+)/);
        if (m) {
            this.invited = true;
            this.inviteLoading = true;
            var self = this;
            WEB.api('./api/team.php', {
                action: 'preview_invite',
                input: { invite_code: m[1] },
            }).then(function (r) {
                var t = (r && (r.team || (r.data && r.data.team))) || null;
                self.invite = t;
            }).catch(function () {
                self.invite = null; // invalid/expired link state
            }).then(function () {
                self.inviteLoading = false;
            });
        }
    },
    methods: {
        goLogin() { ROUTER.navigate('/login'); },
        goForgot() { ROUTER.navigate('/forgot-password'); },
        goSignup() {
            // Invited visitors: carry the code explicitly to the onboard page.
            var m = document.cookie.match(/(?:^|; )fab_invite=([A-Za-z0-9]+)/);
            if (m) { ROUTER.navigate('/onboard?invite=' + m[1]); return; }
            ROUTER.navigate('/signup');
        },
    },
};
