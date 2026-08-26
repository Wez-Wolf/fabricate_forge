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
                { tag: 'quotes',    name: 'Quotes',    svg: 'file-text',        comp: 'quote-list' },
                { tag: 'clients',     name: 'Clients',     svg: 'users',         comp: 'clients' },
                { tag: 'library',     name: 'Library',     svg: 'library',       comp: 'library' },
                { tag: 'shop-floor',  name: 'Shop Floor',  svg: 'factory',       comp: 'shop-floor' },
                { tag: 'reports',     name: 'Reports',     svg: 'bar-chart',     comp: 'reports' },
                { tag: 'admin',       name: 'Admin',       svg: 'shield',        comp: 'admin' },
            ],
        };
    },
    created() {
        // Register the route listener FIRST — the early returns below (auth
        // gate, login redirect) skip the rest of created(). If we only register
        // after them, a boot on /login (the common auth flow) leaves the
        // listener unregistered for the whole session, so SPA navigations
        // (open quote / Back to Quotes) never re-resolve the route.
        this._onPathChange = () => { this.resolveRoute(); };
        this.$root.$on('onPathChange', this._onPathChange);

        // Auth gate — redirect to the public landing page if not authenticated
        // (welcome-first app; /login is reachable from the landing's Sign in)
        if (!LS || !LS.get('auth_id') || LS.get('auth_id') === '-100') {
            ROUTER.navigate('/landing');
            return;
        }
        this.loadUser();
        this.$root.$on('user-updated', () => { this.loadUser(); });
        // Authed user opened an invite link → join the team, consume the cookie.
        this.consumeInvite();
        // Sync theme state from DOM (theme-init already applied it pre-paint)
        this.isDark = document.documentElement.classList.contains('dark');
        // Authed user landing on /login → redirect to default tab
        if (this.tab_url === 'login' || this.tab_url === 'signup') {
            ROUTER.navigate('/nav/dashboard');
            return;
        }
        this.resolveRoute();
        // (onPathChange listener registered at the TOP of created() so it
        // survives the early returns — see the auth/login redirects above.)
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
        if (this._onPathChange) this.$root.$off('onPathChange', this._onPathChange);
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
        // Invite link (?invite=CODE): authed user joining a team.
        // Cookie was set at boot (index.php) before the router navigated.
        consumeInvite() {
            var m = document.cookie.match(/(?:^|; )fab_invite=([A-Za-z0-9]+)/);
            if (m) {
                WEB.api('./api/team.php', {
                    action: 'join',
                    input: { invite_code: m[1] },
                }).then((r) => {
                    var d = (r && r.data) || r || {};
                    if (d.status === 'joined') {
                        TOAST.show('You joined ' + (d.team_name || 'the team'), 'success');
                    }
                }).catch(() => {});
            }
            // Post-signup welcome: server set fab_joined=<team> when the new
            // account auto-joined via an invite link.
            var j = document.cookie.match(/(?:^|; )fab_joined=([^;]+)/);
            if (j) {
                try { document.cookie = 'fab_joined=; path=/; max-age=0'; } catch (e) {}
                TOAST.show('Welcome to ' + decodeURIComponent(j[1]) + '!', 'success');
            }
        },
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
        // Open the settings/about slide-out panel (replaces the Settings + About tabs)
        openSettingsPanel() {
            POPUP.show('Settings', {
                comp: 'settings',
                props: {},
                width: '600px',
                height: 'auto',
            });
        },
        // Route /nav/<tab>[/<id>] — a second segment (quote id) routes to the
        // detail component via forge-nav.setPage, everything else is a tab.
        // Deferred past forge-nav's own tabUrl watcher (which would overwrite
        // pageComp with the unresolved 'quotes/<id>' tag).
        resolveRoute() {
            setTimeout(() => { // 300ms: outlast forge-nav's tabUrl watcher
                var parts = [];
                try { parts = ROUTER.decodePath(); } catch (e) { parts = []; }
                // tab_url only carries the first segment (e.g. 'quotes');
                // decodePath carries the full route (['nav','quotes','<id>'])
                if (parts.length === 0) {
                    parts = (this.tab_url || '').split('/').filter(Boolean);
                } else {
                    parts = parts.filter(function(p) { return p !== 'nav'; });
                }
                if (parts.length >= 2 && parts[0] === 'quotes') {
                    if (this.$refs.nav) {
                        this.$refs.nav.setPage('quote-view', { tab_url: parts.join('/') });
                    }
                } else if (this.$refs.nav && this.$refs.nav.pageComp === 'quote-view') {
                    // Left the quote detail (back/forward/tab click) — restore
                    // the tab page so the detail doesn't linger on the list URL.
                    var tag = parts[0] || this.tab_url || 'dashboard';
                    var tab = this.tabs.find(function (t) { return t.tag === tag; });
                    this.$refs.nav.setPage(tab ? tab.comp : tag, { tab_url: parts.join('/') });
                }
            }, 300);
        },
        loadUser() {
            var authId = LS.get('auth_id');
            if (!authId) return;
            WEB.api('./api/user.php', {
                action: 'get_preferences',
                input: { auth_id: authId }
            }).then((res) => {
                var data = (res && res.data) || res || {};
                if (data.name) this.userName = data.name;
                if (data.role) this.userRole = data.role;
            }).catch(() => {});
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
