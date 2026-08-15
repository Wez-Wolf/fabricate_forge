<?php
/**
 * fabricate_forge/lib/config.php — thin wrapper over forge's shared config loader.
 * Parsing (config.json + .env + defaults merge) lives in forge/php/config.php
 * (forge_load_config); this file supplies the app's path + defaults so all
 * forge apps share one loader.
 */
require_once('/var/www/html/forge/php/config.php');

function loadConfig($key = null) {
    static $config = null;
    if ($config === null) {
        $config = forge_load_config(__DIR__ . '/../config.json', 
        [
            'project_dir' => dirname(__DIR__),
            'forge_path'  => '/var/www/html/forge',
            'api_base'    => '/fabricate_forge/api',
            'node_bin'    => '/usr/bin/node',
        ]
);
    }
    if ($key === null) return $config;
    return $config[$key] ?? null;
}

