/**
 * components/reset — reset-password page (public).
 * Token comes from the route: /reset-password/<token>.
 * Calls api/auth.php reset_password → sets the new password.
 */
var comp = {
    data() {
        return {
            token: '',
            pass: '',
            pass2: '',
            error: '',
            success: '',
            loading: false,
        };
    },
    created() {
        // Route shape: ['nav'?] / 'reset-password' / <token>  — or bare segments.
        try {
            var parts = ROUTER.decodePath();
            parts = parts.filter(function (p) { return p !== 'nav' && p !== 'reset-password'; });
            if (parts.length) this.token = parts[parts.length - 1];
        } catch (e) { /* keep empty */ }
        if (!this.token) this.error = 'Missing reset token. Use the link from your email.';
    },
    methods: {
        async reset() {
            if (!this.token) {
                this.error = 'Missing reset token.';
                return;
            }
            if (!this.pass || this.pass.length < 6) {
                this.error = 'Password must be at least 6 characters.';
                return;
            }
            if (this.pass !== this.pass2) {
                this.error = 'Passwords do not match.';
                return;
            }
            this.loading = true;
            this.error = '';
            try {
                var res = await WEB.api('./api/auth.php', {
                    action: 'reset_password',
                    input: { token: this.token, pass: this.pass },
                });
                var data = (res && res.data) || res || {};
                if (data.success) {
                    this.success = data.message || 'Password updated.';
                } else if (res && res.error) {
                    this.error = res.error;
                }
            } catch (e) {
                this.error = e.message || 'Failed to reset password';
            } finally {
                this.loading = false;
            }
        },
        goLogin() {
            ROUTER.navigate('/login');
        },
    },
};
