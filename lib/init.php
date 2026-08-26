<?php
/**
 * fabricate_forge/lib/init.php
 * App initializer — loads forge JS modules in boot order.
 *
 * Ordering is load-bearing:
 *   1. forge core (_util.js → WEB/LS/UTIL, router.js), LS.pre
 *   2. forge_comp_js → comp.js, which defines `COMP` and calls COMP.initMain()
 *        at its end — THIS creates the root Vue app (`MAIN`) and mounts the
 *        `#main` shell. Everything that patches MAIN/processPath must run
 *        AFTER this block (MAIN actually exists).
 *   3. isReservedTag('nav') override — before Vue renders the shell.
 *   4. Router + auth-loss handling + landing-first routing patches.
 */
header('Content-Type: application/javascript');
$forgeDir = '/var/www/html/forge';

// ── Bundle freshness: ETag + short revalidate ────────────────
// The ?v= query only busts the COMPONENT loader (comp.php?comp=X&v=N); this
// bundle itself is governed by HTTP headers. Emit an ETag derived from the
// mtimes of everything this bundle embeds (forge core JS + project comps/api)
// so any edit invalidates it; browsers revalidate via 304 within max-age.
require_once($forgeDir . '/php/comp-js.php');
$etag = '"' . forge_comp_js_sig(
    [__DIR__ . '/../components', __DIR__ . '/../api'],
    __DIR__ . '/index.php',
    [
        $forgeDir . '/js/core/_util.js',
        $forgeDir . '/js/core/router.js',
        $forgeDir . '/js/core/comp.js',
        // lib/init.php embeds the ROUTER/processPath patches + the auth-loss
        // redirect + WEB.api auth wrapper directly in this bundle — its mtime
        // must bust the ETag too, or edits to this very file never invalidate
        // the client's cached copy.
        __DIR__ . '/init.php',
        __DIR__ . '/config.php',
        __DIR__ . '/vue.php',
    ]
) . '"';
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=600, must-revalidate');
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

// ── Forge core JS files (order matters) ──
// Forge core JS consolidated into forge/php/core-js.php
require_once($forgeDir . '/php/core-js.php');
echo forge_core_js('fabricate');

// Capture the original path BEFORE comp.js's initMain redirects (unconditional).
// Full path (not just the first segment) so reset-password/<token> survives.
// comp.js/forge_comp_js below calls MAIN.processPath(ROUTER.decodePath()) at
// boot and forge's native processPath bounces unauth single-segments to /login
// — so we must snapshot the real landing target BEFORE that happens.
echo "try { window._origPath = ROUTER.decodePath().join('/') || ''; } catch(e) { window._origPath = ''; }";  echo PHP_EOL;

// SVG cache invalidation — forge-svg caches fetched icons in localStorage
// (key 'svg_<path>', 7-day TTL) and in the in-memory LOADED map. Before
// lib/svg.php existed, the icon fetch returned the SPA index.html (Apache
// rewrite) which got cached as the icon — so browsers show broken icons and
// never re-fetch. Clear the stale cache once per version bump; the marker key
// prevents re-clearing every load.
echo "if (!LS.get('svg_clear_v1')) {";
echo "  var _ks = []; for (var i = 0; i < localStorage.length; i++) { var k = localStorage.key(i); if (k.indexOf('svg_') !== -1) _ks.push(k); }";
echo "  for (var j = 0; j < _ks.length; j++) localStorage.removeItem(_ks[j]);";
echo "  if (typeof LOADED !== 'undefined') { for (var k in LOADED) delete LOADED[k]; }";
echo "  LS.set('svg_clear_v1', '1');";

echo "}";  echo PHP_EOL;

// ── DDP Real-time Client Initialization ────────────
// Initialize the DDP client for pub/sub.
// This must live OUTSIDE the SVG-cache if{} block so it runs on every load.
// DDP endpoint/token are injected from the PHP environment (getenv) — the
// browser has no `process.env`, so we must NOT reference it in client JS.
$ddpEndpoint = getenv('DDP_ENDPOINT');
$ddpToken = getenv('DDP_TOKEN');
echo "window.DDP_ENDPOINT = " . json_encode($ddpEndpoint ?: null) . ";";
echo "window.DDP_TOKEN = " . json_encode($ddpToken ?: '') . ";";
echo "window.DDP = window.DDP || {}; window.DDP.subscribe = function(t,c) { var d = window.DDP || {}; return d.subscribe ? d.subscribe(t,c) : null; }; window.DDP.publish = function(t,d) { var ddp = window.DDP || {}; if (ddp.publish) ddp.publish(t,d); };";


// ── Forge comp registry + root app ─────────────────────────
// Emits comp.js (defines COMP + registered base), which calls COMP.initMain()
// at its end — creating MAIN (the #main Vue root). Subsequent patches rely on
// MAIN existing, so this MUST stay ahead of them.
require_once($forgeDir . '/php/comp-js.php');
echo forge_comp_js([__DIR__ . '/../components', __DIR__ . '/../api'], __DIR__ . '/../index.php'); echo PHP_EOL;

echo PHP_EOL;

// ROUTER.navigate to the HOME path (e.g. /nav/quotes — the current tab) hits
// ROUTER's replaceState branch, which SKIPS the popstate dispatch. SPA pages
// that restore on onPathChange (nav.js resolveRoute — quote-view → list) never
// hear about it: clicking "Back to Quotes" changed the URL but left the quote
// detail mounted. Dispatch popstate for the home-path case so onPathChange
// always fires.
echo <<<'JS'
(function(){
  var _nav = ROUTER.navigate.bind(ROUTER);
  ROUTER.navigate = function(path, props) {
    var isHome = this.path && path === this.path;
    _nav(path, props);
    if (isHome) {
      try { window.dispatchEvent(new PopStateEvent('popstate')); } catch(e) {}
    }
  };
})();
JS;
echo PHP_EOL;

// Session-loss 401 redirect is handled by forge core's WEB.gracefulLogout.
// Standardized: forge routes unauth users out on 401/error_code. For this
// landing-first app, point the (configurable) destination at the public /
// landing page instead of relying on the default /login path. No bespoke
// WEB.api wrapper / processClear needed anymore.
echo "window.FORGE_CONFIG = window.FORGE_CONFIG || {};"; echo PHP_EOL;
echo "window.FORGE_CONFIG.logoutRedirect = '/landing';"; echo PHP_EOL;
echo PHP_EOL;
// Welcome-first landing: unauth users land on the public /landing page.
// Patches processPath so single-segment /landing renders the landing comp,
// and /login /signup /join /onboard /forgot-password still work. Also keeps
// quote-detail deep links (/nav/quotes/<id>) mounting the quote view.
// MAIN is guaranteed to exist here (forge_comp_js/comp.js ran above).
echo <<<'JS'
(function(){
  var _pp = MAIN && MAIN.processPath ? MAIN.processPath.bind(MAIN) : null;
  var isAuthed = function(){ return LS && LS.get('auth_id') && LS.get('auth_id') !== '-100'; };
  MAIN.processPath = function(parts){
    var authed = isAuthed();
    // Password reset is a public multi-segment route: /reset-password/<token>
    if (!authed && parts.length >= 2 && parts[0] === 'reset-password') {
      MAIN.setComp('reset', {});
      return;
    }
    if (!authed && parts.length === 1) {
      var p = parts[0] || '';
      if (p === 'login' || p === 'signup' || p === 'join') {
        if (p === 'join') { MAIN.setComp('onboard', {}); return; }
        if (_pp) _pp(parts);
        return;
      }
      if (p === 'onboard') { MAIN.setComp('onboard', {}); return; }
      if (p === 'forgot-password') { MAIN.setComp('forgot', {}); return; }
      // any other single-segment while logged out → landing (welcome-first)
      MAIN.setComp('landing', {});
      return;
    }
    // Quote detail deep-link: /nav/quotes/<id> or /nav/quotes/<id>/<tab>.
    // Forge's OWN multi-segment branch (via _pp, below) already handles this
    // correctly: it sets
    //     this.comp     = _startComp;   // 'nav'
    //     this.props     = { default_tab, tab_url: parts[1] }   // tab_url = 'quotes'
    // WITHOUT navigating. That mounts the nav shell on the clean 'quotes' tab
    // (so forge-nav never sees the slashed 'quotes/<id>' tag and never crashes
    // with "Invalid component name") AND it preserves the <id> in the URL.
    // nav.js resolveRoute() then reads the full ['nav','quotes','<id>'] path
    // from ROUTER.decodePath() (300ms deferred, past forge-nav's tabUrl
    // watcher) and mounts the quote-view page via
    // forge-nav.setPage('quote-view', { tab_url: 'quotes/<id>' }).
    //
    // Do NOT special-case this with MAIN.setComp(_startComp, {tab_url:'quotes'}):
    // because _startComp === 'nav', setComp runs ROUTER.navigate('/nav/quotes'),
    // which — since /nav/quotes is the home path — does a replaceState that
    // STRIPS the <id> from the URL before resolveRoute runs, so quote-view
    // never mounts. Letting _pp handle it keeps the URL intact.
    if (_pp) _pp(parts);
  };

  // Init deferred a landing path captured at boot (before initMain redirected),
  // re-dispatch it now that the landing patch is in place.
  var op = window._origPath || '';
  if (!isAuthed() && op && (op === 'landing' || op === 'onboard' ||
      op === 'forgot-password' || op.indexOf('reset-password/') === 0)) {
    try { ROUTER.navigate('/' + op); } catch(e) {}
    MAIN.processPath(ROUTER.decodePath());
  }
})();
JS;
echo PHP_EOL;

// ── Shared app helpers (FAB) ────────────────────────────────
// Single implementation of the tiny format/escape helpers that used to be
// copy-pasted into ~20 components. Components reference them via thin
// delegates (this.esc / this.fmt / this.fmtMoney) so call sites stay put.
// Registered on window like FAB_EDIT_MIXIN so comp.php-loaded components
// can use them.
echo <<<'JS'
window.FAB = window.FAB || {};
FAB.esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
};
FAB.fmtMoney = function (v, currency) {
    try {
        return new Intl.NumberFormat('en-US', { style: 'currency', currency: currency || 'USD' }).format(parseFloat(v || 0));
    } catch (e) {
        return String(v || 0);
    }
};
JS;
echo PHP_EOL;

// ── Shared quote-edit mixin (FAB_EDIT_MIXIN) ─────────────────
// ONE save orchestration for every edit surface (Entities tab, Tree tab,
// and any future edit surface). Registered on window so comp.php-loaded
// components can mix it in. Self-loading tabs hook a post-save refresh via
// afterSave() (tree tab → loadTree); shell-driven tabs refresh via the
// 'changed' event + entities prop and define no afterSave.
echo <<<'JS'
window.FAB_EDIT_MIXIN = {
    data() {
        return {
            processTrades: ['boilermaking', 'welding', 'machining', 'painting', 'assembly', 'qualityControl', 'surfaceTreatment', 'cutting', 'drilling', 'grinding', 'bending'],
        };
    },
    methods: {
        // quote currency (falls back to USD)
        currency() {
            return (this.quote && this.quote.data && this.quote.data.currency) || 'USD';
        },
        fmtMoney(v) {
            return FAB.fmtMoney(v, this.currency());
        },
        findComponent(entity, type) {
            var comps = (entity && entity.components) || [];
            for (var i = 0; i < comps.length; i++) {
                if (comps[i].type === type) return comps[i];
            }
            return null;
        },
        toNumOrNull(v) {
            if (v === '' || v == null) return null;
            var n = parseFloat(v);
            return isNaN(n) ? null : n;
        },
        async saveEntity(entity, mat, proc, form) {
            try {
                // 1. Entity columns — name/type. Global BoQ qty is DRIVEN by the
                //    links (not editable here), so we don't overwrite it.
                await WEB.api('./api/entities.php', {
                    action: 'update',
                    input: {
                        id: entity.id,
                        type: form.type,
                        name: form.name,
                    }
                });

                // Assemblies are containers — costs roll up from their children,
                // so they never carry their own material/paint/process data.
                var isAssembly = form.type === 'assembly';

                // 2. Material component (non-assemblies only) — incl. material variables
                if (!isAssembly) {
                    var matData = {
                        materialLibraryId: form.material_id || null,
                        length: this.toNumOrNull(form.length),
                        // D1 green — extra length priced into material cost
                        length_secondary: this.toNumOrNull(form.length_secondary),
                        width: this.toNumOrNull(form.width),
                        thickness: this.toNumOrNull(form.thickness),
                        buttWeldQty: form.buttWeldQty != null && form.buttWeldQty !== '' ? parseInt(form.buttWeldQty, 10) : null,
                        costPerM: form.costPerM != null && form.costPerM !== '' ? parseFloat(form.costPerM) : null,
                        costPerEa: form.costPerEa != null && form.costPerEa !== '' ? parseFloat(form.costPerEa) : null,
                        shopHrsPerKg: form.shopHrsPerKg != null && form.shopHrsPerKg !== '' ? parseFloat(form.shopHrsPerKg) : null,
                        pipeWt: form.pipeWt != null && form.pipeWt !== '' ? parseFloat(form.pipeWt) : null,
                        weldSize: form.weldSize != null && form.weldSize !== '' ? parseFloat(form.weldSize) : null,
                        weldType: form.weldType || null,
                    };
                    if (mat) {
                        await WEB.api('./api/components.php', { action: 'update', input: { id: mat.id, data: matData } });
                    } else if (form.material_id || form.length || form.width || form.thickness) {
                        await WEB.api('./api/components.php', {
                            action: 'create',
                            input: { entity_id: entity.id, type: 'material', data: matData }
                        });
                    }

                    // 2b. Paint & lining options (in-house/sub-contract) → entity.data.onCosts.painting
                    var painting = {};
                    ['extPaint', 'intPaint', 'line', 'coating1', 'coating2', 'coating3', 'coating4', 'transportPerTon'].forEach(function (k) {
                        var v = parseFloat(form.painting && form.painting[k]);
                        painting[k] = isNaN(v) ? 0 : v;
                    });
                    painting.mode = (form.painting && form.painting.mode === 'subcontract') ? 'subcontract' : 'inhouse';
                    var curOnCosts = (entity.data && entity.data.onCosts) || {};
                    var data = { onCosts: Object.assign({}, curOnCosts, { painting: painting }) };
                    await WEB.api('./api/entities.php', { action: 'update', input: { id: entity.id, data: data } });
                }

                // 3. Process component — ALL entity types incl. assemblies
                //    (D3: a spool assembly carries its own welding process comps;
                //    only MATERIAL stays parts/fittings/fasteners-only).
                {
                    var ops = (form.process_ops && Array.isArray(form.process_ops))
                        ? form.process_ops.filter(function (o) { return (parseFloat(o.hours) || 0) > 0 && o.category; })
                        : [];
                    var procData = {
                        ops: ops.map(function (o) {
                            return { category: o.category, hours: parseFloat(o.hours) || 0, summary: (o.summary || '').trim() };
                        }),
                        note: (form.process_note || '').trim(),
                    };
                    // Also keep the flattened hours map (for legacy reads not yet
                    // on the ops-aware extractItems path).
                    var flat = {};
                    ops.forEach(function (o) { flat[o.category] = (parseFloat(o.hours) || 0) + (flat[o.category] || 0); });
                    Object.assign(procData, flat);
                    if (proc) {
                        await WEB.api('./api/components.php', { action: 'update', input: { id: proc.id, data: procData } });
                    } else if (ops.length || form.process_note) {
                        await WEB.api('./api/components.php', {
                            action: 'create',
                            input: { entity_id: entity.id, type: 'process', data: procData }
                        });
                    }
                }

                // 4. Link quantity: if the row is inside a parent (has a
                //    contains-link), update that parent's link.quantity.
                if (form.link_id && form.link_qty != null && form.link_qty !== '') {
                    var lq = parseFloat(form.link_qty);
                    await WEB.api('./api/links.php', {
                        action: 'update',
                        input: { id: form.link_id, quantity: isNaN(lq) ? 0 : lq }
                    });
                }

                await WEB.api('./api/systems.php', {
                    action: 'recalculate_entity',
                    input: { entity_id: this.quoteId }
                });
                this.$emit('changed');
                // Self-loading tabs refresh their own data (tree).
                if (typeof this.afterSave === 'function') this.afterSave();
                TOAST.show('Item saved — recalculating', 'success');
            } catch (e) {
                TOAST.show(e.message || 'Failed to save item', 'error');
            }
        },
    },
};
JS;
echo PHP_EOL;
