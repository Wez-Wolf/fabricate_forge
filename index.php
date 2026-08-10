<?php require_once(__DIR__ . '/bootstrap.php'); ?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <script data-theme-key="fabricate_theme"><?php readfile('/var/www/html/forge/js/core/theme-init.js'); ?></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <base href="<?= rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') ?>/" />
    <script>window.FORGE_COMP_BASE = '<?= rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') ?>/';</script>
    <link rel="stylesheet" href="<?= rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') ?>/style.css" type="text/css">
    <link rel="icon" href="<?= rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') ?>/favicon.ico" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <title>Fabricate · Costing System</title>
</head>
<body>
    <div id="main" start_comp="nav" default_tab="dashboard"></div>
    <script defer src="./lib/vue.php"></script>
    <script defer src="./lib/init.php?v=<?= filemtime(__DIR__ . '/lib/init.php') ?>"></script>
    <script>
      window.addEventListener('error', function(e){console.warn('[fabricate] error:',e.message||e);return false;});
      window.addEventListener('unhandledrejection', function(e){console.warn('[fabricate] rejection:',e.reason);});
    </script>
</body>
</html>
