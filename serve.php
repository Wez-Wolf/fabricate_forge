<?php
/**
 * fabricate_forge/serve.php — serve files stored in the DB file store.
 *
 * Delegates to Forge's FileServe endpoint (/var/www/html/forge/api/serve.php)
 * which streams from files_meta + files_data tables with auth checking.
 *
 * The Forge serve.php derives its .env path from dirname(SCRIPT_FILENAME, 2),
 * which resolves to /var/www/html for this proxy. We load the correct .env
 * first so the DbConnection has the right credentials.
 *
 * Usage: GET /serve.php?id=<files_meta.id>&auth_id=<auth_id>
 *        POST /serve.php {action, input}  → full FileStore API
 */
require_once('/var/www/html/forge/php/util/helpers.php');
\loadEnv(dirname(__FILE__) . '/.env');
require_once('/var/www/html/forge/api/serve.php');
