/**
 * components/admin — user management + teams (admin only).
 * Users: list + change roles (admin/editor/viewer).
 * Teams: create, invite by email, members, remove, revoke.
 */
var comp = {
    data() {
        return {
            users: [],
            loading: false,
            creating: false, // synchronous double-click guard for Create Team
            error: '',
            msg: '',
            // teams
            teams: [],
            activeTeam: null,
            membersByTeam: {},
            newTeamName: '',
            inviteEmail: '',
        };
    },
    created() {
        this.load();
        this.loadTeams();
    },
    computed: {
        inviteLink() {
            if (!this.activeTeam || !this.activeTeam.invite_code) return '';
            return location.origin + '/onboard?invite=' + this.activeTeam.invite_code;
        },
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

        async copyInviteLink() {
            var link = this.inviteLink;
            if (!link) return;
            try {
                await navigator.clipboard.writeText(link);
            } catch (e) {
                // Clipboard API unavailable (http) — select the readonly input.
                var inp = document.querySelector('.C_invite_link');
                if (inp) { inp.select(); document.execCommand('copy'); }
            }
            this.msg = 'Invite link copied';
            TOAST.show('Invite link copied', 'success');
        },

        // ── Teams ───────────────────────────────────────
        async loadTeams() {
            try {
                var r = await WEB.api('./api/team.php', { action: 'list', input: {} });
                var list = ((r && r.data) || r || []);
                this.teams = Array.isArray(list) ? list : (list.teams || []);
                if (this.teams.length && !this.activeTeam) {
                    this.selectTeam(this.teams[0].id);
                }
            } catch (e) {
                this.error = e.message || 'Failed to load teams';
            }
        },
        async createTeam() {
            if (this.creating) return; // synchronous double-click guard (reactive loading updates async)
            var name = (this.newTeamName || '').trim();
            if (!name) { this.error = 'Enter a team name first.'; return; }
            this.creating = true;
            this.loading = true;
            this.msg = '';
            this.error = '';
            try {
                var r = await WEB.api('./api/team.php', { action: 'create', input: { name: name } });
                if (r && r.error) throw new Error(r.error);
                this.newTeamName = '';
                await this.loadTeams();
                var td = (r && r.data) || r || {};
                if (td.id) this.selectTeam(td.id);
                this.msg = 'Team created — invite people below.';
                TOAST.show('Team created', 'success');
            } catch (e) {
                this.error = e.message || 'Failed to create team';
                TOAST.show(this.error, 'error');
            } finally {
                this.creating = false;
                this.loading = false;
            }
        },
        async selectTeam(id) {
            try {
                var r = await WEB.api('./api/team.php', { action: 'members', input: { team_id: id } });
                var d = (r && r.data) || r || {};
                this.activeTeam = { id: id };
                var team = this.teams.find(function (t) { return t.id === id; });
                if (team) {
                    this.activeTeam.name = team.name;
                    this.activeTeam.invite_code = team.invite_code;
                }
                this.activeTeam.members = d.members || [];
                this.activeTeam.pending = d.pending || [];
                var byTeam = this.membersByTeam;
                byTeam[id] = this.activeTeam.members;
                this.membersByTeam = byTeam;
            } catch (e) {
                this.error = e.message || 'Failed to load team members';
            }
        },
        async invite() {
            var email = (this.inviteEmail || '').trim();
            if (!this.activeTeam) { this.error = 'Select a team first.'; return; }
            if (!email) { this.error = 'Enter an email to invite.'; return; }
            this.loading = true;
            this.msg = '';
            this.error = '';
            try {
                await WEB.api('./api/team.php', {
                    action: 'invite',
                    input: { team_id: this.activeTeam.id, email: email },
                });
                this.inviteEmail = '';
                await this.selectTeam(this.activeTeam.id);
                this.msg = 'Invited ' + email;
                TOAST.show('Invited ' + email, 'success');
            } catch (e) {
                this.error = e.message || 'Failed to invite';
            } finally {
                this.loading = false;
            }
        },
        async revokeInvite(id) {
            try {
                await WEB.api('./api/team.php', { action: 'revoke_invite', input: { invite_id: id } });
                await this.selectTeam(this.activeTeam.id);
            } catch (e) {
                this.error = e.message || 'Failed to revoke invite';
            }
        },
        async removeMember(userId) {
            if (!confirm('Remove this member from the team?')) return;
            try {
                await WEB.api('./api/team.php', {
                    action: 'remove_member',
                    input: { team_id: this.activeTeam.id, user_id: userId },
                });
                await this.selectTeam(this.activeTeam.id);
                TOAST.show('Member removed', 'success');
            } catch (e) {
                this.error = e.message || 'Failed to remove member';
            }
        },
    },
};
