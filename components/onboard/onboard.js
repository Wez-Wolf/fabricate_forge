/**
 * components/onboard — invite-link signup page.
 *
 * The invite code is carried EXPLICITLY: it lives in the URL (?invite=CODE,
 * set by the admin's Copy Link) and is passed as invite_code in the signup
 * API call — no cookie magic. The fab_invite cookie (set at boot) is only a
 * fallback for visitors who reached signup without the URL param.
 */
var comp = {
    data() {
        return {
            code: '',
            invite: null,     // {name, owner_name, owner_email}
            name: '',
            email: '',
            pass: '',
            error: '',
            loading: true,
            saving: false,
        };
    },
    created() {
        // 1. explicit: from the URL (?invite=CODE)
        var q = ROUTER.decodeQuery();
        this.code = (q.invite || '').toUpperCase();
        // 2. fallback: cookie set at boot (index.php) before navigation
        if (!this.code) {
            var m = document.cookie.match(/(?:^|; )fab_invite=([A-Za-z0-9]+)/);
            if (m) this.code = m[1];
        }
        if (this.code) {
            var self = this;
            WEB.api('./api/team.php', {
                action: 'preview_invite',
                input: { invite_code: this.code },
            }).then(function (r) {
                self.invite = (r && (r.team || (r.data && r.data.team))) || null;
            }).catch(function () {
                self.invite = null;
            }).then(function () {
                self.loading = false;
            });
        } else {
            this.loading = false;
        }
    },
    methods: {
        async doSignup() {
            this.error = '';
            if (!this.email || !this.pass) {
                this.error = 'Email and password are required';
                return;
            }
            if (this.pass.length < 6) {
                this.error = 'Password must be at least 6 characters';
                return;
            }
            this.saving = true;
            try {
                var res = await WEB.api('./api/user.php', {
                    action: 'signup',
                    input: {
                        name: this.name,
                        email: this.email,
                        pass: this.pass,
                        // The tie: the invite code rides with the signup itself.
                        invite_code: this.code,
                    },
                });
                if (res && res.auth_id) {
                    LS.set('auth_id', res.auth_id);
                    // Reload → nav boots → welcome toast (fab_joined cookie)
                    location.href = '/nav/dashboard';
                } else {
                    this.error = (res && res.error) || 'Signup failed';
                }
            } catch (e) {
                this.error = 'Connection error — please try again';
            } finally {
                this.saving = false;
            }
        },
        goLogin() { ROUTER.navigate('/login'); },
    },
};
