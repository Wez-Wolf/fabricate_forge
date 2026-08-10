/**
 * components/admin — user management (admin only).
 * List users + change roles (admin/editor/viewer).
 */
var comp = {
    data() {
        return {
            users: [],
            loading: false,
            error: '',
            msg: '',
        };
    },
    created() {
        this.load();
    },
    methods: {
        esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        },
        fmtDate(d) {
            if (!d) return '—';
            return new Date(d).toLocaleDateString();
        },
        async load() {
            this.loading = true;
            this.error = '';
            try {
                var res = await WEB.api('./api/admin.php', { action: 'list_users', input: {} });
                this.users = (res && res.data) || res || [];
                if (this.users.error) { this.error = this.users.error; this.users = []; }
            } catch (e) {
                this.error = e.message || 'Failed to load users (admin only)';
            } finally {
                this.loading = false;
            }
        },
        async setRole(userId, role) {
            this.msg = '';
            this.error = '';
            try {
                await WEB.api('./api/admin.php', {
                    action: 'set_user_role',
                    input: { user_id: userId, role: role },
                });
                this.msg = 'Role updated';
                TOAST.show('Role updated', 'success');
            } catch (e) {
                this.error = e.message || 'Failed to update role';
            }
        },
    },
};
