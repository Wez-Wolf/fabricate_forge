<?php
/**
 * fabricate_forge/bootstrap.php
 * Security headers + session config for all PHP entry points.
 *
 * Consolidated: session-security + security-header logic now lives in
 * forge/php/bootstrap.php (forge_bootstrap()) — single source of truth.
 */
require_once('/var/www/html/forge/php/bootstrap.php');
forge_bootstrap();
