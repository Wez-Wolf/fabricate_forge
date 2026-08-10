<?php
/**
 * fabricate_forge/lib/config.php
 * Central config loader — reads .env + config.json, returns values.
 */
function loadConfig($key = null) {
    static $config = null;

    if ($config === null) {
        $config = [];
        $path = __DIR__ . '/../config.json';
        if (file_exists($path)) {
            $raw = file_get_contents($path);
            $parsed = json_decode($raw, true);
            if ($parsed) $config = $parsed;
        }
        // Load .env
        $envPath = __DIR__ . '/../.env';
        if (file_exists($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) continue;
                if (strpos($line, '=') === false) continue;
                list($k, $v) = explode('=', $line, 2);
                $config[strtoupper(trim($k))] = trim($v);
            }
        }
        // Defaults
        $defaults = [
            'project_dir' => dirname(__DIR__),
            'forge_path'  => '/var/www/html/forge',
            'api_base'    => '/fabricate_forge/api',
            'node_bin'    => '/usr/bin/node',
        ];
        foreach ($defaults as $k => $v) {
            if (!isset($config[$k])) $config[$k] = $v;
        }
    }

    if ($key === null) return $config;
    return $config[$key] ?? null;
}
