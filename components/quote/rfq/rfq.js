/**
 * components/quote/rfq — the quote's intake surface.
 * Upload a client BoQ → it is parsed immediately (api/rfq.php upload = parse;
 * the document is transient — nothing is stored). Review the normalized +
 * flagged rows in the grid, fix the unclear bits, then import the valid rows
 * into the quote.
 */
var comp = {
    mixins: [COMP.base],
    props: ['quoteId'],
    data() {
        return {
            file: null,
            rows: [],
            summary: null,
            loading: false,
            importing: false,
            fileId: null,          // persisted BoQ document file id
            serveUrl: null,        // URL to view the stored doc
            documents: [],         // list of persisted docs for this quote
            docsLoaded: false,
            // Any cell edit in the review grid makes the tab dirty until import
            // (the shell's #3 leave-guard warns before navigation loses edits).
            gridDirty: false,
        };
    },
    watch: {
        // Reflect dirty state up to the shell (@dirty="markDirty").
        gridDirty(v) {
            this.$emit('dirty', !!v);
        },
    },
    created() {
        this.loadDocuments();
    },
    methods: {
        onFileChange(e) {
            this.file = (e.target.files || [])[0] || null;
        },
        // Upload + parse in one call — the doc is persisted to the DB file store
        // and the file_id is kept so imported entities carry lineage.
        async upload() {
            if (!this.file || !this.quoteId) return;
            this.loading = true;
            try {
                var b64 = await this.readBase64(this.file);
                var res = await WEB.api('./api/rfq.php', {
                    action: 'upload',
                    input: { quote_id: this.quoteId, filename: this.file.name, file_base64: b64 },
                });
                if (res && res.error) { TOAST.show(res.error, 'error'); this.rows = []; this.summary = null; return; }
                this.rows = (res.rows || []).slice();
                this.summary = res.counts || null;
                this.fileId = res.file_id || null;
                this.serveUrl = res.serve_url || null;
                this.file = null;
                this.gridDirty = false;
                if (res.file_persisted === false) {
                    TOAST.show('Parsed ' + (this.rows.length || 0) + ' lines, but file was not saved (' + (res.file_error || 'unknown reason') + ')', 'warning');
                } else {
                    TOAST.show('Parsed ' + (this.rows.length || 0) + ' lines — review the flags. Document saved.', 'success');
                }
            } catch (e) {
                TOAST.show(e.message || 'Upload failed', 'error');
            } finally {
                this.loading = false;
            }
        },
        readBase64(file) {
            return new Promise(function (resolve, reject) {
                var r = new FileReader();
                r.onload = function () { resolve(r.result); };
                r.onerror = reject;
                r.readAsDataURL(file);
            });
        },
        async importRows() {
            var clean = this.cleanRows();
            if (!clean.length) { TOAST.show('No valid rows to import', 'error'); return; }
            this.importing = true;
            try {
                var res = await WEB.api('./api/rfq.php', {
                    action: 'import',
                    input: { quote_id: this.quoteId, file_id: this.fileId, rows: clean },
                });
                if (res && res.error) { TOAST.show(res.error, 'error'); return; }
                TOAST.show('Imported ' + (res.imported || 0) + ' items', 'success');
                this.$emit('changed');
                this.rows = [];
                this.summary = null;
                this.fileId = null;
                this.serveUrl = null;
                this.gridDirty = false;
                this.$emit('dirty', false);
                this.loadDocuments();
            } catch (e) {
                TOAST.show(e.message || 'Import failed', 'error');
            } finally {
                this.importing = false;
            }
        },
        // Rows that pass review: not skipped, no errors (unclear is OK — the
        // human has reviewed them in the grid).
        cleanRows() {
            var self = this;
            return this.rows.filter(function (r) {
                if (r.type === 'skip') return false;
                return !self.hasLevel(r, 'error');
            });
        },
        cleanCount() { return this.cleanRows().length; },
        hasLevel(r, level) {
            return (r.flags || []).some(function (f) { return f.level === level; });
        },
        specText(spec) {
            if (!spec) return '';
            return [spec.grade, spec.standard, spec.schedule, spec.coating].filter(Boolean).join(' ');
        },
        // Mark the grid dirty on any cell edit (so the shell warns on leave).
        markCellEdit() {
            this.gridDirty = true;
        },
        // Called when the shell navigates away; clears dirty so the guard
        // doesn't re-prompt next time (import or explicit clear handles the rest).
        clearDirty() {
            this.gridDirty = false;
        },
        // ── Document management ──

        async loadDocuments() {
            this.docsLoaded = false;
            try {
                var res = await WEB.api('./api/rfq_documents.php', {
                    action: 'list',
                    input: { quote_id: this.quoteId },
                });
                this.documents = (res && !res.error && Array.isArray(res)) ? res : [];
            } catch (e) {
                this.documents = [];
            } finally {
                this.docsLoaded = true;
            }
        },
        async viewDocument(fileId) {
            var doc = this.documents.find(function (d) { return d.file_id === fileId; });
            if (!doc) return;
            if (doc.mime_type === 'application/pdf') {
                // PDFs render inline in the browser.
                window.open(doc.serve_url, '_blank');
            } else if (doc.mime_type.indexOf('spreadsheet') !== -1 || doc.mime_type.indexOf('excel') !== -1 || doc.mime_type === 'text/csv') {
                // Excel/CSV: open the parsed rows in the same grid for review.
                var res = await WEB.api('./api/rfq_documents.php', {
                    action: 'get',
                    input: { file_id: fileId },
                });
                if (res && res.error) { TOAST.show(res.error, 'error'); return; }
                this.rows = (res.parsed_rows || []).slice();
                this.summary = res.flag_counts || null;
                this.fileId = fileId;
                this.serveUrl = res.serve_url;
                TOAST.show('Loaded ' + (this.rows.length || 0) + ' rows from saved document', 'info');
            } else {
                // Other files (models, etc.): download.
                window.open(doc.serve_url, '_blank');
            }
        },
        formatSize(bytes) {
            if (!bytes || bytes === 0) return '—';
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
            if (bytes < 104857600) return (bytes / 1048576).toFixed(1) + ' MB';
            return (bytes / 104857600).toFixed(1) + ' GB';
        },
        fileIcon(mime) {
            if (mime === 'application/pdf') return '📄';
            if (mime.indexOf('spreadsheet') !== -1 || mime.indexOf('excel') !== -1 || mime === 'text/csv') return '📊';
            if (mime === 'application/octet-stream') return '📁';
            return '📎';
        },
    },
};
