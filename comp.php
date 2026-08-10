<?php
/**
 * fabricate_forge component resolver — pure proxy to forge's resolver.
 *
 * forge/php/comp.php resolves:
 *   1. Project components: any tag → {project}/components/{name}/{name}.{html,js,css}
 *      (nav → fabricate_forge/components/nav/nav, dashboard → components/dashboard/dashboard)
 *   2. Forge components:   forge-* → forge/components/{name}/{name}
 *   3. Category fallback:  login / signup → forge/components/auth/{name}/{name}
 */
header('Content-Type: application/javascript');
$forgeDir = dirname(__DIR__) . '/forge';
require_once($forgeDir . '/php/comp.php');
