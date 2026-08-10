<?php
/**
 * fabricate_forge/lib/svg.php — pass-through to forge's SVG icon server.
 * Usage: lib/svg.php?svg=thumbs-up
 * Without this, <forge-svg> icons fail to load (fetch hits ./lib/svg.php).
 */
require_once('/var/www/html/forge/php/svg.php');
