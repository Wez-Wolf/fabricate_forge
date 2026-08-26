/**
 * components/quote/bom — Bill of Materials tab of the quote detail.
 * Where you view AND import the BOM for the quote. Pasting/uploading a
 * spreadsheet of lines (item numbers, description, material, qty, dims, hours)
 * builds the whole quote structure. Emits 'changed' so the shell reloads its
 * shared entities/costs after an import.
 */

// BOM CSV column aliases — file scope (not on the component: a plain object
// on the component options would be treated as a method by Vue's merge).
var BOM_HEADERS = {
    item: 'item_number', itemnumber: 'item_number', itemno: 'item_number', no: 'item_number', num: 'item_number', number: 'item_number', pos: 'item_number', position: 'item_number',
    description: 'description', desc: 'description', name: 'description', part: 'description', partname: 'description', itemname: 'description', partdescription: 'description',
    material: 'material', mat: 'material', grade: 'material', spec: 'material', materialname: 'material', matname: 'material', materialgrade: 'material', materialspec: 'material',
    quantity: 'quantity', qty: 'quantity', q: 'quantity', qtyper: 'quantity', qtyrequired: 'quantity',
    length: 'length', len: 'length', l: 'length', lengthmm: 'length',
    width: 'width', w: 'width', widthmm: 'width',
    thickness: 'thickness', thick: 'thickness', t: 'thickness', thk: 'thickness', wall: 'thickness', wallthickness: 'thickness', thicknessmm: 'thickness',
    mass: 'mass', kg: 'mass', weight: 'mass', kgperitem: 'mass', kgperunit: 'mass',
    weld: 'welding', welding: 'welding', weldhrs: 'welding', weldinghrs: 'welding', weldinghours: 'welding',
    machine: 'machining', machining: 'machining', machinehrs: 'machining', machininghrs: 'machining', machininghours: 'machining', mill: 'machining', turn: 'machining',
    boiler: 'boilermaking', boilermaking: 'boilermaking', boilerhrs: 'boilermaking', boilermakinghrs: 'boilermaking', bm: 'boilermaking', bmhrs: 'boilermaking', fit: 'boilermaking', fitting: 'boilermaking',
    cut: 'cutting', cutting: 'cutting', cuthrs: 'cutting', cuttinghrs: 'cutting', plasma: 'cutting', laser: 'cutting',
    drill: 'drilling', drilling: 'drilling', drillhrs: 'drilling', drillinghrs: 'drilling',
    grind: 'grinding', grinding: 'grinding', grindhrs: 'grinding', grindinghrs: 'grinding',
    bend: 'bending', bending: 'bending', bendhrs: 'bending',
    assemble: 'assembly', assembly: 'assembly', assemblyhrs: 'assembly', assemblehrs: 'assembly',
    qc: 'qualityControl', quality: 'qualityControl', qualitycontrol: 'qualityControl', qchrs: 'qualityControl', inspect: 'qualityControl', inspection: 'qualityControl',
    paint: 'painting', painting: 'painting', paintarea: 'paintarea',
    costperm: 'costPerM', costpm: 'costPerM', costm: 'costPerM', rateperm: 'costPerM', permeter: 'costPerM', costpermeter: 'costPerM',
    costperea: 'costPerEa', costpea: 'costPerEa', costea: 'costPerEa', perea: 'costPerEa', costperitem: 'costPerEa', costeach: 'costPerEa', rateperea: 'costPerEa',
    unitcost: 'unitCost', costkg: 'unitCost', costperkg: 'unitCost', rate: 'unitCost', price: 'unitCost', unitprice: 'unitCost',
    type: 'type', itemtype: 'type', kind: 'type', entitytype: 'type',
    note: 'note', notes: 'note', remark: 'note', remarks: 'note',
};

var comp = {
    mixins: [COMP.base],
    props: ['entities', 'quote', 'quoteId'],
    computed: {
        // Read-only view of the BOM as a flat list (the hierarchy lives in the
        // Tree tab; this tab is for import + review).
        rows() {
            return (this.entities || []).map((e) => [
                e.name || 'Item',
                e.type,
                e.quantity,
                this.fmtMoney(e.cost && e.cost.unitCost),
            ]);
        },
        fields() {
            return [
                { label: 'Item', col_cls: 'C_strong', max_width: '28rem' },
                { label: 'Type' },
                { label: 'Qty' },
                { label: 'Unit Total', col_cls: 'C_right' },
            ];
        },
    },
    methods: {
        fmtMoney(v) { return FAB.fmtMoney(v, (this.quote && this.quote.data && this.quote.data.currency) || 'USD'); },

        // ── BOM import ────────────────────────────────────
        openBomImport() {
            POPUP.show('Import BOM', {
                comp: 'forge-form',
                props: {
                    fields: {
                        rows: {
                            label: 'BOM Rows',
                            type: 'textarea',
                            rows: 14,
                            placeholder: 'Paste from Excel (comma, tab, or semicolon separated).\nHeader row optional — any of these columns work:\n\nitem, description, material, qty, length, width, thickness,\nweld_hrs, machine_hrs, boiler_hrs, cut_hrs, drill_hrs, grind_hrs,\nassemble_hrs, qc_hrs, cost_per_m, cost_per_ea, unit_cost, type\n\nExample (nested item numbers = sub-assemblies):\nitem,description,material,qty,length,width,thickness,weld_hrs,type\n1,Skid Frame,,,,,,,assembly\n1.1,Mounting Plate,S235JR Plate 10mm,4,1200,400,10,2.5,part\n1.1.1,M12 Bolt,bolt,16,,,,,fastener',
                        },
                    },
                    button_label: 'Import',
                },
                events: {
                    submit: (form) => {
                        this.doBomImport(form.rows);
                        POPUP.close();
                    },
                },
            });
        },
        normalizeHeader(s) {
            return String(s || '').toLowerCase().replace(/[^a-z0-9]/g, '');
        },
        isHeaderRow(parts) {
            var hits = 0;
            for (var i = 0; i < parts.length; i++) {
                if (BOM_HEADERS[this.normalizeHeader(parts[i])]) hits++;
            }
            return hits >= 2;
        },
        parseBomRows(text) {
            var lines = String(text || '').split(/\r?\n/).map(function (s) { return s.trim(); });
            var rows = [];
            var headerMap = null;
            var start = 0;

            // Header detection on the first non-empty, non-comment line
            for (var i = 0; i < lines.length; i++) {
                var line = lines[i];
                if (!line || line.charAt(0) === '#') continue;
                var parts = line.split(/[\t;,]/).map(function (s) { return s.trim(); });
                if (this.isHeaderRow(parts)) {
                    headerMap = [];
                    for (var j = 0; j < parts.length; j++) {
                        headerMap.push(BOM_HEADERS[this.normalizeHeader(parts[j])] || null);
                    }
                    start = i + 1;
                }
                break;
            }

            for (var k = start; k < lines.length; k++) {
                var ln = lines[k];
                if (!ln || ln.charAt(0) === '#') continue;
                var cells = ln.split(/[\t;,]/).map(function (s) { return s.trim(); });
                if (!cells.length || (!cells[0] && !cells[1])) continue;

                var row = {};
                if (headerMap) {
                    // Named columns: map by index
                    for (var c = 0; c < cells.length && c < headerMap.length; c++) {
                        var key = headerMap[c];
                        if (!key || cells[c] === '' || cells[c] == null) continue;
                        row[key] = cells[c];
                    }
                } else {
                    // Positional: item, desc, material, qty, len, w, thick
                    row.item_number = cells[0];
                    row.description = cells[1] || 'Item';
                    if (cells[2]) row.material = cells[2];
                    if (cells[3]) row.quantity = cells[3];
                    if (cells[4]) row.length = cells[4];
                    if (cells[5]) row.width = cells[5];
                    if (cells[6]) row.thickness = cells[6];
                }

                // Normalize numeric fields
                row.description = row.description || 'Item';
                if (row.quantity != null && row.quantity !== '') row.quantity = parseInt(row.quantity, 10) || 1;
                ['length', 'width', 'thickness', 'mass', 'costPerM', 'costPerEa', 'unitCost',
                 'welding', 'machining', 'boilermaking', 'cutting', 'drilling', 'grinding', 'bending', 'assembly', 'qualityControl', 'painting'].forEach(function (f) {
                    if (row[f] != null && row[f] !== '') {
                        var n = parseFloat(row[f]);
                        row[f] = isNaN(n) ? null : n;
                    }
                });
                // Type column: normalize to valid value
                if (row.type) {
                    var t = String(row.type).toLowerCase();
                    if (['part', 'assembly', 'fitting', 'fastener'].indexOf(t) === -1) delete row.type;
                }
                if (row.item_number != null || row.description) rows.push(row);
            }
            return rows;
        },
        async doBomImport(text) {
            var rows = this.parseBomRows(text);
            if (!rows.length) {
                TOAST.show('No valid rows to import', 'error');
                return;
            }
            try {
                var res = await WEB.api('./api/boms.php', {
                    action: 'import',
                    input: { quote_id: this.quoteId, rows: rows },
                });
                var data = (res && res.data) || res || {};
                if (data.error) throw new Error(data.error);

                // Material-match feedback: warn about unmatched rows so the
                // user can fix the material column and re-import.
                var unmatched = (data.matches || []).filter(function (m) { return !m.matched; });
                var msg = data.imported + ' items imported';
                if (unmatched.length) {
                    msg += ' · ' + unmatched.length + ' material' + (unmatched.length === 1 ? '' : 's') + ' unmatched: ';
                    msg += unmatched.slice(0, 3).map(function (m) { return m.description || m.material || m.item_number; }).join(', ');
                    if (unmatched.length > 3) msg += ' …';
                    TOAST.show(msg, 'warning');
                } else {
                    TOAST.show(msg + ' (all materials matched)', 'success');
                }
                await WEB.api('./api/systems.php', {
                    action: 'recalculate_entity',
                    input: { entity_id: this.quoteId },
                });
                this.$emit('changed');
            } catch (e) {
                TOAST.show(e.message || 'BOM import failed', 'error');
            }
        },
    },
};
