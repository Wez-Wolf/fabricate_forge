/**
 * components/quote/docs — client document inbox (per quote) + cell-based
 * source picker. Lists uploaded docs (PDF/Excel/CSV/…), and when a
 * spreadsheet is opened, shows its cells and lets the user map columns →
 * item fields, then build those rows into the quote as entities (lineage
 * back to each source cell via handle_build_from_map).
 */
var comp = {
    mixins: [COMP.base],
    props: ['quoteId'],
    data() {
        return {
            documents: [],
            file: null,
            uploading: false,
            selected: null,
            // cell viewer state
            cells: null,
            sheets: [],
            activeSheet: '',
            headerRow: 8,
            headerLabels: {},
            previewRows: [],
            dataRowCount: 0,
            inspect: null,
            // mapping
            fieldOrder: ['abc_no', 'description', 'size', 'size_kind', 'qty', 'uom', 'cls', 'lining', 'section', 'unique'],
            mapping: {},
            useFilter: false,
            filterCol: '',
            filterVal: '',
            itemType: 'part',
            building: false,
            result: null,
            loadingCells: false,
            askingPi: false,
            autoMapNote: '',
            matchMaterials: true,
        };
    },
    computed: {
        hasKeyField() { return !!this.mapping.description || !!this.mapping.abc_no; },
        previewCols() { return Object.keys(this.headerLabels); },
        // forge-option takes {key: label} maps. colOptions carries a '' key so
        // a mapped column can be cleared back to none.
        colOptions() {
            var out = { '': '— none —' };
            for (var k in this.headerLabels) out[k] = k + ' · ' + this.headerLabels[k];
            return out;
        },
        sheetOptions() {
            var out = {};
            (this.sheets || []).forEach(function (s) { out[s] = s; });
            return out;
        },
        typeOptions() { return { part: 'part', fitting: 'fitting', fastener: 'fastener', assembly: 'assembly' }; },
    },
    mounted() { this.loadDocuments(); },
    methods: {
        fieldLabel(f) {
            return ({
                abc_no: 'ABC / item no', description: 'Description', size: 'Size (number)',
                size_kind: 'Size kind (NB/DN/OD)', qty: 'Quantity', uom: 'Unit', cls: 'Class (CS/HDPE)',
                lining: 'Lining', section: 'Section / type descriptor', unique: 'Unique ref',
            })[f] || f;
        },
        // Where each mapped column LANDS in our system — the translation
        // contract, shown under every select so client→system mapping is explicit.
        fieldLands(f) {
            return ({
                abc_no: '→ item number (boq_item_no) + name prefix',
                description: '→ entity name + description',
                size: '→ parsed size number on the material spec (spec_dn)',
                size_kind: '→ combines with Size ⇒ spec_kind (NB/DN/OD)',
                qty: '→ quantity on the contains-link (D5)',
                uom: '→ unit of measure (m / ea / kg)',
                cls: '→ material class — drives material matching later',
                lining: '→ lining specification',
                section: '→ type refinement (FLANGE/TEE/… ⇒ fitting)',
                unique: '→ unique-reference flag',
            })[f] || '';
        },
        isMapped(col) { return this.fieldOrder.some((f) => this.mapping[f] === col); },
        // ── Heuristic auto-map (C-flow step 1): fuzzy header labels + value
        // shape sniffing. Fills this.mapping; returns a note for the user.
        guessMapping() {
            var self = this;
            var labels = this.headerLabels || {};
            if (!Object.keys(labels).length) return '';
            // Sample values per column from the extracted cells.
            var byCol = {};
            var hdr = this.headerRow || 0;
            (this.cells || []).forEach(function (c) {
                if (c.r <= hdr) return;
                (byCol[c.c] = byCol[c.c] || []).push(String(c.v == null ? '' : c.v));
            });
            function norm(s) { return String(s || '').toLowerCase().replace(/[^a-z0-9]/g, ''); }
            function shape(col) {
                var vals = (byCol[col] || []).slice(0, 20);
                var numeric = 0, meterish = 0, cshdpe = 0;
                vals.forEach(function (v) {
                    var t = v.trim();
                    if (/^\d{2,4}(\.\d+)?$/.test(t)) numeric++;
                    if (/^(met(er)?|m|ea|each|no|kg)$/i.test(t)) meterish++;
                    if (/^(cs|hdpe)$/i.test(t)) cshdpe++;
                });
                var n = Math.max(vals.length, 1);
                return { numeric: numeric / n, meterish: meterish / n, cshdpe: cshdpe / n, sample: vals };
            }
            var patterns = {
                abc_no: /abc|^no$|^item(no|number)?$|^pos(ition)?$|^mark$/,
                description: /desc/,
                size: /(size|diameter|^dia$|^nb$|^od$)/,
                size_kind: /^odnb$|od,nb|^kind$/,
                qty: /qty|quantity|forecast/,
                uom: /unit|uom|measure/,
                cls: /class|^csorhdpe$|^cs$|hdpe/,
                lining: /lining|lined/,
                section: /section|descriptor|specificat|type/,
                unique: /unique/,
            };
            var pairs = [];
            Object.keys(labels).forEach(function (col) {
                var nl = norm(labels[col]);
                var sh = shape(col);
                Object.keys(patterns).forEach(function (f) {
                    var score = 0;
                    if (patterns[f].test(nl)) score += 2;
                    if ((f === 'qty' && sh.numeric > 0.6) || (f === 'uom' && sh.meterish > 0.6)
                        || (f === 'cls' && sh.cshdpe > 0.6)) score += 1.5;
                    if (score > 0) pairs.push({ f: f, col: col, s: score });
                });
            });
            pairs.sort(function (a, b) { return b.s - a.s; });
            var usedCols = {}, usedFields = {}, assigned = [];
            pairs.forEach(function (p) {
                if (usedCols[p.col] || usedFields[p.f]) return;
                // 'type descriptor'-style columns beat generic 'Specifications'? Keep first (highest) only.
                usedCols[p.col] = true; usedFields[p.f] = true;
                assigned.push(p);
            });
            assigned.forEach(function (p) { self.mapping[p.f] = p.col; });
            var mapped = assigned.length;
            return mapped ? ('Auto-mapped ' + mapped + ' fields from headers — review before building.') : '';
        },
        // ── Pi RPC fallback (C-flow step 2): ask pi via localhost bridge.
        async askPi() {
            if (!this.selected || this.askingPi) return;
            this.askingPi = true;
            try {
                var res = await WEB.api('./api/rfq.php', {
                    action: 'smart_map',
                    input: { file_id: this.selected.file_id, sheet: this.activeSheet || undefined },
                });
                if (res && res.error) { TOAST.show(res.error, 'error'); return; }
                var f = res.fields || {}, any = false;
                this.fieldOrder.forEach(function (k) { if (f[k]) { this.mapping[k] = f[k]; any = true; } }, this);
                this.autoMapNote = any ? 'Pi proposed a mapping — review before building.' : 'Pi could not map this sheet confidently.';
                this.rebuildPreview();
            } catch (e) { TOAST.show(e.message || 'pi map failed', 'error'); }
            finally { this.askingPi = false; }
        },
        fileIcon(mime) {
            if (!mime) return '📄';
            if (mime.indexOf('pdf') !== -1) return '📕';
            if (mime.indexOf('sheet') !== -1 || mime.indexOf('excel') !== -1) return '📊';
            if (mime.indexOf('csv') !== -1) return '📋';
            if (mime.indexOf('image') !== -1) return '🖼️';
            return '📎';
        },
        formatSize(n) {
            if (!n) return '';
            if (n < 1024) return n + ' B';
            if (n < 1048576) return (n / 1024).toFixed(0) + ' KB';
            return (n / 1048576).toFixed(1) + ' MB';
        },
        async loadDocuments() {
            try {
                var res = await WEB.api('./api/rfq_documents.php', {
                    action: 'list', input: { quote_id: this.quoteId },
                });
                this.documents = (res && !res.error && Array.isArray(res)) ? res : [];
            } catch (e) { this.documents = []; }
        },
        onFileChange(e) { this.file = e.target.files && e.target.files[0]; },
        async upload() {
            if (!this.file || !this.quoteId) return;
            this.uploading = true;
            try {
                var b64 = await this.readBase64(this.file);
                var res = await WEB.api('./api/rfq.php', {
                    action: 'upload',
                    input: { quote_id: this.quoteId, filename: this.file.name, file_base64: b64 },
                });
                if (res && res.error) { TOAST.show(res.error, 'error'); }
                else { TOAST.show('Uploaded ' + (res.filename || this.file.name), 'success'); this.loadDocuments(); }
                this.file = null;
                if (this.$refs && this.$refs) { var inp = document.getElementById('C_docs_file'); if (inp) inp.value = ''; }
            } catch (e) { TOAST.show(e.message || 'Upload failed', 'error'); }
            finally { this.uploading = false; }
        },
        readBase64(file) {
            return new Promise(function (resolve, reject) {
                var r = new FileReader();
                r.onload = function () { resolve(r.result); };
                r.onerror = reject;
                r.readAsDataURL(file);
            });
        },
        async openDoc(doc) {
            this.selected = doc;
            this.cells = null; this.previewRows = []; this.result = null;
            this.mapping = {};
            // Only spreadsheets can be shown as cells.
            var m = (doc.mime_type || '').toLowerCase();
            if (m.indexOf('pdf') !== -1) { TOAST.show('PDF opens in a new tab (no cell view).', 'info'); window.open(doc.serve_url, '_blank'); return; }
            if (m.indexOf('sheet') === -1 && m.indexOf('excel') === -1 && m.indexOf('csv') === -1) {
                TOAST.show('This file type has no cell view.', 'info'); return;
            }
            await this.loadCells(doc);
        },
        async loadCells(doc) {
            this.loadingCells = true;
            try {
                // Cap the viewer to a manageable grid (looks like Excel, stays
                // responsive). Build re-extracts the full sheet server-side, so
                // no data is lost — only the on-screen preview is truncated.
                var res = await WEB.api('./api/rfq.php', {
                    action: 'cells',
                    input: { file_id: this.selected.file_id, sheet: this.activeSheet || undefined, max_rows: 400 },
                });
                if (res && res.error) { TOAST.show(res.error, 'error'); return; }
                this.cells = res.cells || [];
                this.sheets = res.sheets || [];
                this.activeSheet = res.active || (this.sheets[0] || '');
                this.headerRow = res.headerRow || this.headerRow;
                this.headerLabels = res.headerLabels || {};
                this.autoMapNote = this.guessMapping();
                this.rebuildPreview();
            } catch (e) { TOAST.show(e.message || 'Could not read cells', 'error'); }
            finally { this.loadingCells = false; }
        },
        rebuildPreview() {
            if (!this.cells) return;
            // Index cells by coord.
            var byCoord = {};
            for (var i = 0; i < this.cells.length; i++) byCoord[this.cells[i].coord] = this.cells[i].v;
            var cols = Object.keys(this.headerLabels);
            var rowsSeen = {};
            var data = [];
            for (var j = 0; j < this.cells.length; j++) {
                var c = this.cells[j];
                if (c.r <= this.headerRow) continue;
                if (rowsSeen[c.r]) continue;
                rowsSeen[c.r] = true;
                var row = { r: c.r, cells: {} };
                for (var k = 0; k < cols.length; k++) row.cells[cols[k]] = byCoord[cols[k] + c.r] || '';
                data.push(row);
            }
            data.sort(function (a, b) { return a.r - b.r; });
            this.dataRowCount = data.length;
            this.previewRows = data.slice(0, 120);
        },
        async build() {
            if (!this.hasKeyField || !this.selected) return;
            this.building = true; this.result = null;
            try {
                var payload = {
                    quote_id: this.quoteId,
                    file_id: this.selected.file_id,
                    sheet: this.activeSheet,
                    header_row: this.headerRow,
                    fields: this.mapping,
                    type: this.itemType,
                    match_materials: this.matchMaterials,
                };
                if (this.useFilter && this.filterCol && this.filterVal !== '') {
                    payload.row_filter = { col: this.filterCol, equals: this.filterVal };
                }
                var res = await WEB.api('./api/rfq.php', { action: 'build_from_map', input: payload });
                if (res && res.error) { TOAST.show(res.error, 'error'); return; }
                this.result = res;
                TOAST.show('Created ' + (res.created || 0) + ' items', 'success');
                this.$emit('changed');
            } catch (e) { TOAST.show(e.message || 'Build failed', 'error'); }
            finally { this.building = false; }
        },
        async deleteDoc(doc) {
            if (!confirm('Delete ' + doc.filename + '?')) return;
            try {
                var res = await WEB.api('./api/rfq_documents.php', { action: 'delete', input: { file_id: doc.file_id } });
                if (res && res.error) TOAST.show(res.error, 'error');
                else { TOAST.show('Deleted', 'success'); if (this.selected && this.selected.file_id === doc.file_id) { this.cells = null; this.selected = null; } this.loadDocuments(); }
            } catch (e) { TOAST.show(e.message || 'Delete failed', 'error'); }
        },
        get authId() {
            // Reuse the session auth id from the Forge global if present.
            return (window.AUTH_ID || (this.$root && this.$root.authId) || '');
        },
    },
};
