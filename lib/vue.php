<?php
/**
 * fabricate_forge/lib/vue.php — Vue 2.6 runtime, served from forge.
 * Unified: forge/php/bundle.php's forge_vue() serves forge/js/core/vue.js with
 * gzip + ETag + 304 (via forge_bundle_*). One entry point for every forge app.
 */
require_once('/var/www/html/forge/php/bundle.php');
forge_vue();
