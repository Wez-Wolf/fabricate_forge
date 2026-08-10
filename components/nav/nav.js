/**
 * components/nav — fabricate_forge shell.
 * Desktop-focused: left sidebar with forge-menu, content area to the right.
 * Wraps forge-nav for tab resolution + page mounting.
 *
 * Tabs (mirror the original app's routes):
 *   dashboard   — quote stats
 *   quotes      — quotes list (the main table view)
 *   library     — materials / parts library
 *   reports     — pricing schedules + cost reports
 *   settings    — user prefs, company settings
 *   admin       — user management (admin only)
 */
var comp = {
    name: 'nav',
    props: ['tab_url', 'default_tab', 'user_id'],
    data() {
        return {
            projectName: 'Fabricate',
            logoPath: 'factory',
            userName: '',
            userRole: '',
            isDark: true,
            tabs: [
                { tag: 'dashboard', name: 'Dashboard', svg: 'layout-dashboard', comp: 'dashboard' },
                { tag: 'quotes',    name: 'Quotes',    svg: 'file-text',        comp: 'quotes' },
                { tag: 'clients',   name: 'Clients',   svg: 'users',            comp: 'clients' },
                { tag: 'library',   name: 'Library',   svg: 'library',          comp: 'library' },
                { tag: 'tools',     name: 'Tools',     svg: 'calculator',       comp: 'tools' },
                { tag: 'prefabs',   name: 'Prefabs',   svg: 'boxes',            comp: 'prefabs' },
                { tag: 'orders',    name: 'Orders',    svg: 'clipboard-list',   comp: 'orders' },
                { tag: 'procurement', name: 'Procurement', svg: 'truck',        comp: 'procurement' },
                { tag: 'production',  name: 'Production',  svg: 'activity',     comp: 'production' },
                { tag: 'reports',   name: 'Reports',   svg: 'bar-chart',        comp: 'reports' },
                { tag: 'settings',  name: 'Settings',  svg: 'settings',         comp: 'settings' },
                { tag: 'admin',     name: 'Admin',     svg: 'shield',           comp: 'admin' },
            ],
        };
    },
    created() {
        var self = this;
        // Auth gate — redirect to the public landing page if not authenticated
        // (welcome-first app; /login is reachable from the landing's Sign in)
        if (!LS || !LS.get('auth_id') || LS.get('auth_id') === '-100') {
            ROUTER.navigate('/landing');
            return;
        }
        this.loadUser();
        this.$root.$on('user-updated', function() { self.loadUser(); });
        // Sync theme state from DOM (theme-init already applied it pre-paint)
        this.isDark = document.documentElement.classList.contains('dark');
        // Authed user landing on /login → redirect to default tab
        if (this.tab_url === 'login' || this.tab_url === 'signup') {
            ROUTER.navigate('/nav/dashboard');
            return;
        }
        if (typeof MAIN !== 'undefined' && MAIN && !MAIN.processClear) {
            MAIN.processClear = function() { ROUTER.navigate('/login'); };
        }
        this.resolveRoute();
    },
    watch: {
        // Same-route navigation / browser back-forward with a new ?user_id=
        // doesn't remount the page — forward the prop so the page watcher reloads.
        tab_url(nv) {
            this.resolveRoute();
        },
    },
    beforeDestroy() {
        this.$root.$off('user-updated');
    },
    computed: {
        // Admin tab only for admin users
        visibleTabs() {
            var t = this.tabs.filter(function(tab) { return tab.tag !== 'admin'; });
            if (this.userRole === 'admin') t.push(this.tabs[this.tabs.length - 1]);
            return t;
        },
    },
    methods: {
        // Brand click → default tab
        onHome() {
            ROUTER.navigate('/nav/dashboard');
        },
        toggleDark() {
            var html = document.documentElement;
            html.classList.toggle('dark');
            this.isDark = html.classList.contains('dark');
            if (LS) LS.set('theme', this.isDark ? 'dark' : 'light');
        },
        // Route /nav/<tab>[/<id>] — a second segment (quote id) routes to the
        // detail component via forge-nav.setPage, everything else is a tab.
        // Deferred past forge-nav's own tabUrl watcher (which would overwrite
        // pageComp with the unresolved 'quotes/<id>' tag).
        resolveRoute() {
            var self = this;
            setTimeout(function() { // 300ms: outlast forge-nav's tabUrl watcher
                var parts = [];
                try { parts = ROUTER.decodePath(); } catch (e) { parts = []; }
                // tab_url only carries the first segment (e.g. 'quotes');
                // decodePath carries the full route (['nav','quotes','<id>'])
                if (parts.length === 0) {
                    parts = (self.tab_url || '').split('/').filter(Boolean);
                } else {
                    parts = parts.filter(function(p) { return p !== 'nav'; });
                }
                if (parts.length >= 2 && parts[0] === 'quotes') {
                    if (self.$refs.nav) {
                        self.$refs.nav.setPage('quoteview', { tab_url: parts.join('/') });
                    }
                }
            }, 300);
        },
        loadUser() {
            var self = this;
            var authId = LS.get('auth_id');
            if (!authId) return;
            WEB.api('./api/user.php', {
                action: 'get_preferences',
                input: { auth_id: authId }
            }).then(function(res) {
                var data = (res && res.data) || res || {};
                if (data.name) self.userName = data.name;
                if (data.role) self.userRole = data.role;
            }).catch(function() {});
        },
        onLogout() {
            var id = LS.get('auth_id');
            if (id) {
                WEB.api('./api/auth.php', { action: 'logout', input: { auth_id: id } });
                LS.remove('auth_id');
            }
            ROUTER.navigate('/login');
        },
    },
};
