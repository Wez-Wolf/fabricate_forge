/**
 * components/tools — material + process calculators.
 * Math runs server-side (api/tools.php) so results are consistent with the
 * cost engine; the UI just renders inputs → results.
 */
var comp = {
    data() {
        return {
            loading: false,
            calculating: false,
            error: '',
            materialModes: ['plate', 'section', 'general'],
            processModes: ['welding', 'machining', 'assembly'],
            matMode: 'plate',
            procMode: 'welding',
            // Material inputs (mirror MaterialCalculator.vue defaults)
            plate: { materialType: 'steel', thickness: 10, length: 1000, width: 500, quantity: 1, materialRate: 25, wasteFactor: 12.5 },
            section: { weightPerMeter: 20, length: 3000, quantity: 5, materialRate: 25, wasteFactor: 10 },
            general: { weight: 100, materialRate: 25, processingFactor: 1.2 },
            // Process inputs (mirror ProcessCalculator.vue defaults)
            welding: { weldType: 'fillet', weldLength: 1000, quantity: 1, materialThickness: 6, qualityFactor: 1, laborRate: 90, consumableRate: 2, equipmentRate: 25 },
            machining: { operationType: 'drilling', materialType: 'steel', setupTime: 30, quantity: 10, complexityFactor: 1, laborRate: 90, toolWearRate: 5, machineRate: 60 },
            assembly: { componentCount: 20, timePerComponent: 2, complexityFactor: 1, inspectionTime: 15, laborRate: 90, fixtureCost: 0 },
            // Tank + pipe inputs
            tank: { diameter: 1200, length: 3000, thickness: 10, heads: 2, quantity: 1, materialRate: 25, wasteFactor: 10, materialType: 'steel' },
            pipe: { nominalSize: '50', schedule: '40', lengthM: 6, quantity: 1, materialRate: 25, materialType: 'steel' },
            pipeSizes: ['15', '20', '25', '32', '40', '50', '65', '80', '100', '150', '200', '250', '300'],
            matResult: null,
            procResult: null,
            tankResult: null,
            pipeResult: null,
        };
    },
    methods: {
        async calcMaterial() {
            this.calculating = true;
            this.error = '';
            try {
                var inputs = this[this.matMode];
                var tool = 'material_' + this.matMode;
                var res = await WEB.api('./api/tools.php', { action: 'calculate', input: { tool: tool, inputs: inputs } });
                this.matResult = (res && res.data) || res || {};
            } catch (e) {
                this.error = e.message || 'Calculation failed';
            } finally {
                this.calculating = false;
            }
        },
        async calcProcess() {
            this.calculating = true;
            this.error = '';
            try {
                var inputs = this[this.procMode];
                var tool = 'process_' + this.procMode;
                var res = await WEB.api('./api/tools.php', { action: 'calculate', input: { tool: tool, inputs: inputs } });
                this.procResult = (res && res.data) || res || {};
            } catch (e) {
                this.error = e.message || 'Calculation failed';
            } finally {
                this.calculating = false;
            }
        },
        async calcTank() {
            this.calculating = true;
            this.error = '';
            try {
                var res = await WEB.api('./api/tools.php', { action: 'calculate', input: { tool: 'tank', inputs: this.tank } });
                this.tankResult = (res && res.data) || res || {};
            } catch (e) {
                this.error = e.message || 'Calculation failed';
            } finally {
                this.calculating = false;
            }
        },
        async calcPipe() {
            this.calculating = true;
            this.error = '';
            try {
                var res = await WEB.api('./api/tools.php', { action: 'calculate', input: { tool: 'pipe', inputs: this.pipe } });
                this.pipeResult = (res && res.data) || res || {};
            } catch (e) {
                this.error = e.message || 'Calculation failed';
            } finally {
                this.calculating = false;
            }
        },
        prettyKey(k) {
            return String(k).replace(/([A-Z])/g, ' $1').replace(/^./, function (c) { return c.toUpperCase(); });
        },
        formatVal(v) {
            if (typeof v === 'number') {
                if (v >= 100 || v === 0) return v.toLocaleString(undefined, { maximumFractionDigits: 2 });
                return v.toLocaleString(undefined, { maximumFractionDigits: 4 });
            }
            return String(v == null ? '' : v);
        },
    },
};
