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
    // forge's processPath would pass only parts[1] ('quotes') as tab_url,
    // which flips forge-nav onto the quotes tab and its onMenu clobbers the
    // URL to /nav/quotes (the list) before nav.resolveRoute can mount
    // quote-view — the dashboard→quote click landing on the list. Pass the
    // FULL 'quotes/<id>[/<tab>]' as tab_url so forge-nav navigates to the
    // deep URL, and resolveRoute mounts quote-view from it.
    if (authed && parts.length >= 3 && parts[0] === 'nav' && parts[1] === 'quotes') {
      MAIN.setComp(MAIN._startComp, { tab_url: parts.slice(1).join('/') });
      return;
    }
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
