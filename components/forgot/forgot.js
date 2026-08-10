/**
 * components/forgot — forgot-password page (public).
 * Calls api/auth.php forgot_password → returns a dev-visible reset link.
 */
var comp = {
    data() {
        return {
            email: '',
            error: '',
            success: '',
            token: '',
            loading: false,
        };
    },
    computed: {
        resetUrl() {
            return this.token ? '/reset-password/' + this.token : '';
        },
    },
    methods: {
        async send() {
            if (!this.email) {
                this.error = 'Enter your email address.';
                return;
            }
            this.loading = true;
            this.error = '';
            try {
                var res = await WEB.api('./api/auth.php', {
                    action: 'forgot_password',
                    input: { email: this.email },
                });
                var data = (res && res.data) || res || {};
                if (data.sent) {
                    this.success = 'If that email exists, a reset link has been created.';
                    this.token = data.token || '';
                } else if (res && res.error) {
                    this.error = res.error;
                }
            } catch (e) {
                this.error = e.message || 'Failed to send reset link';
            } finally {
                this.loading = false;
            }
        },
        goLogin() {
            ROUTER.navigate('/login');
        },
    },
};
