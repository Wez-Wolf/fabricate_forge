<?php
/**
 * fabricate_forge/lib/vue.php
 * Vue 2.6 runtime — served from forge.
 */
header('Content-Type: application/javascript');
$cfg = include_once(__DIR__ . '/config.php');
$forgeDir = loadConfig('forge_path');
readfile($forgeDir . '/js/core/vue.js');
