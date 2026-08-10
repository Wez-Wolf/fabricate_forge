<?php
/**
 * fabricate_forge/lib/init.php
 * App initializer — loads forge JS modules in boot order.
 */
header('Content-Type: application/javascript');
$forgeDir = '/var/www/html/forge';

// ── Forge core JS files (order matters) ──
readfile($forgeDir . '/js/core/_util.js');  echo PHP_EOL;
readfile($forgeDir . '/js/core/router.js');  echo PHP_EOL;
echo "LS.pre = 'fabricate';";  echo PHP_EOL;

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

// Welcome-first landing: unauth users land on the public /landing page.
// The nav shell redirects to /landing when logged out (nav.js auth gate);
// this patches processPath so single-segment /landing renders the landing
// comp, and /login//signup still work. Runs after comp.js (MAIN exists).
echo <<<'JS'
(function(){
  // Capture the original path BEFORE initMain redirects (unconditional).
  // Full path (not just the first segment) so reset-password/<token> survives.
  try { window._origPath = ROUTER.decodePath().join('/') || ''; } catch(e) { window._origPath = ''; }
  if (typeof MAIN === 'undefined') { window._landingPending = true; return; }
  var _pp = MAIN.processPath ? MAIN.processPath.bind(MAIN) : null;
  MAIN.processPath = function(parts){
    var authed = LS && LS.get('auth_id') && LS.get('auth_id') !== '-100';
    // Password reset is a public multi-segment route: /reset-password/<token>
    if (!authed && parts.length >= 2 && parts[0] === 'reset-password') {
      MAIN.setComp('reset', {});
      return;
    }
    if (!authed && parts.length === 1) {
      var p = parts[0] || '';
      if (p === 'login' || p === 'signup' || p === 'join' || p === 'forgot-password') {
        if (p === 'forgot-password') { MAIN.setComp('forgot', {}); return; }
        if (_pp) _pp(parts);
        return;
      }
      if (p === 'landing') {
        MAIN.setComp('landing', {});
        return;
      }
      // any other single-segment while logged out → landing (welcome-first)
      MAIN.setComp('landing', {});
      return;
    }
    if (_pp) _pp(parts);
  };
})();
JS;
echo PHP_EOL;

// MAIN.processClear patch (same as skilled/pikan — forge core calls it on
// 401/error_code but never defines it).
echo "if (typeof MAIN !== 'undefined' && MAIN && !MAIN.processClear) { MAIN.processClear = function(){ ROUTER.navigate('/landing'); }; }";
echo PHP_EOL;

// Bare `nav` shell:// If the landing patch ran before MAIN existed, apply it now (post-initMain)
// and re-dispatch the current path so the landing shows.
echo "if (window._landingPending && typeof MAIN !== 'undefined') {";
echo "  window._landingPending = false;";
echo "  var _pp2 = MAIN.processPath ? MAIN.processPath.bind(MAIN) : null;";
echo "  MAIN.processPath = function(parts){";
echo "    var authed = LS && LS.get('auth_id') && LS.get('auth_id') !== '-100';";
echo "    if (!authed && parts.length >= 2 && parts[0] === 'reset-password') { MAIN.setComp('reset', {}); return; }";
echo "    if (!authed && parts.length === 1) {";
echo "      var p = parts[0] || '';";
echo "      if (p === 'login' || p === 'signup' || p === 'join') { if (_pp2) _pp2(parts); return; }";
echo "      if (p === 'forgot-password') { MAIN.setComp('forgot', {}); return; }";
echo "      MAIN.setComp('landing', {}); return;";
echo "    }";
echo "    if (_pp2) _pp2(parts);";
echo "  };";
echo "  var c = ROUTER.decodePath();";
echo "  var op = window._origPath || '';";
echo "  if (!(LS && LS.get('auth_id') && LS.get('auth_id') !== '-100') && op && (op === 'landing' || op === 'forgot-password' || op.indexOf('reset-password/') === 0)) {";
echo "    try { ROUTER.navigate('/' + op); } catch(e) {}";
echo "    MAIN.processPath(ROUTER.decodePath());";
echo "  }";
echo "}";
echo PHP_EOL;

// Bare `nav` shell: Vue treats `nav` as a native HTML element (isHTMLTag), so
// Shared component cache-bust: emit forge comp.js with `v` derived from the
// latest modification time of fabricate_forge's component dirs.
require_once($forgeDir . '/php/comp-js.php');
echo forge_comp_js([__DIR__ . '/../components', __DIR__ . '/../api'], __DIR__ . '/../index.php'); echo PHP_EOL;

// MAIN.processClear patch (same as skilled/pikan — forge core calls it on
// 401/error_code but never defines it).
echo "if (typeof MAIN !== 'undefined' && MAIN && !MAIN.processClear) { MAIN.processClear = function(){ ROUTER.navigate('/login'); }; }";
echo PHP_EOL;

// If the landing patch ran before MAIN existed, apply it now (post-initMain)
// and re-dispatch the current path so the landing shows.
echo "if (window._landingPending && typeof MAIN !== 'undefined') {";
echo "  window._landingPending = false;";
echo "  var _pp2 = MAIN.processPath ? MAIN.processPath.bind(MAIN) : null;";
echo "  MAIN.processPath = function(parts){";
echo "    var authed = LS && LS.get('auth_id') && LS.get('auth_id') !== '-100';";
echo "    if (!authed && parts.length >= 2 && parts[0] === 'reset-password') { MAIN.setComp('reset', {}); return; }";
echo "    if (!authed && parts.length === 1) {";
echo "      var p = parts[0] || '';";
echo "      if (p === 'login' || p === 'signup' || p === 'join') { if (_pp2) _pp2(parts); return; }";
echo "      if (p === 'forgot-password') { MAIN.setComp('forgot', {}); return; }";
echo "      MAIN.setComp('landing', {}); return;";
echo "    }";
echo "    if (_pp2) _pp2(parts);";
echo "  };";
echo "  var c = ROUTER.decodePath();";
echo "  var op = window._origPath || '';";
echo "  if (!(LS && LS.get('auth_id') && LS.get('auth_id') !== '-100') && op && (op === 'landing' || op === 'forgot-password' || op.indexOf('reset-password/') === 0)) {";
echo "    try { ROUTER.navigate('/' + op); } catch(e) {}";
echo "    MAIN.processPath(ROUTER.decodePath());";
echo "  }";
echo "}";
echo PHP_EOL;

// Bare `nav` shell: Vue treats `nav` as a native HTML element (isHTMLTag), so
// `<component :is="'nav'">` renders an empty <nav>. Override isReservedTag so
// the shell resolves via comp.php like any other component.
echo "var _forgeReservedTag = Vue.config.isReservedTag;";
echo "Vue.config.isReservedTag = function(tag) { return (tag === 'nav') ? false : _forgeReservedTag(tag); };";
echo PHP_EOL;
