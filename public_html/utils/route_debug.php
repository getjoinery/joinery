<?php
/**
 * Route Debugging Utility
 * Diagnose routing issues, plugin activation, and route matching
 */

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
require_once(PathHelper::getIncludePath('includes/ThemeHelper.php'));
require_once(PathHelper::getIncludePath('includes/RouteHelper.php'));

// Require admin permission
$session = SessionControl::get_instance();
$session->check_permission(10);

$settings = Globalvars::get_instance();

// Get test URL from form
$test_url = $_POST['test_url'] ?? $_GET['test_url'] ?? '';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Route Debug Utility</title>
    <style>
        body { font-family: monospace; margin: 20px; background: #1e1e1e; color: #d4d4d4; }
        h1, h2, h3 { color: #569cd6; }
        .section { background: #252526; padding: 15px; margin: 15px 0; border-radius: 5px; }
        .ok { color: #4ec9b0; }
        .error { color: #f14c4c; }
        .warning { color: #cca700; }
        .info { color: #9cdcfe; }
        pre { background: #1e1e1e; padding: 10px; overflow-x: auto; }
        table { border-collapse: collapse; width: 100%; }
        th, td { text-align: left; padding: 8px; border-bottom: 1px solid #3c3c3c; }
        th { background: #3c3c3c; }
        input[type="text"] { width: 400px; padding: 8px; background: #3c3c3c; border: 1px solid #569cd6; color: #d4d4d4; }
        input[type="submit"] { padding: 8px 16px; background: #569cd6; border: none; color: #1e1e1e; cursor: pointer; }
        input[type="submit"]:hover { background: #4ec9b0; }
        .match { background: #2d4f2d; }
        .no-match { background: #4f2d2d; }
    </style>
</head>
<body>

<h1>Route Debug Utility</h1>

<div class="section">
    <h2>Test a URL</h2>
    <form method="post">
        <input type="text" name="test_url" value="<?= htmlspecialchars($test_url) ?>" placeholder="/profile/filters_edit">
        <input type="submit" value="Test Route">
    </form>
</div>

<?php if ($test_url): ?>
<div class="section">
    <h2>Route Match Results for: <?= htmlspecialchars($test_url) ?></h2>
    <?php
    // Normalize the test URL
    $normalized_url = $test_url;
    if ($normalized_url[0] !== '/') {
        $normalized_url = '/' . $normalized_url;
    }

    // Load main routes from serve.php (without executing processRoutes)
    // We need to extract just the $routes array
    $serve_file = PathHelper::getIncludePath('serve.php');
    $serve_content = file_get_contents($serve_file);

    // Extract the routes array by finding it in the file
    // This is a bit hacky but avoids executing the file
    preg_match('/\$routes\s*=\s*\[/s', $serve_content, $matches, PREG_OFFSET_CAPTURE);
    if ($matches) {
        // The routes are defined, load them by including a wrapper
        ob_start();
        $routes = [];

        // Create temp file that just defines routes without calling processRoutes
        $temp_content = preg_replace('/RouteHelper::processRoutes\s*\([^;]+;/', '// processRoutes disabled', $serve_content);
        $temp_file = sys_get_temp_dir() . '/route_debug_serve_' . md5($serve_content) . '.php';
        file_put_contents($temp_file, $temp_content);

        // Capture any output and discard
        include($temp_file);
        ob_end_clean();

        // Now use the actual route processing with match_only mode
        $match_result = RouteHelper::processRoutes($routes, ltrim($normalized_url, '/'), true);

        echo "<h3>Match Result (using actual route processing):</h3>";
        echo "<table>";
        echo "<tr><th>Property</th><th>Value</th></tr>";

        $matched_class = $match_result['matched'] ? 'ok' : 'error';
        echo "<tr><td>Matched</td><td class='$matched_class'>" . ($match_result['matched'] ? 'Yes' : 'No') . "</td></tr>";
        echo "<tr><td>Type</td><td class='info'>" . htmlspecialchars($match_result['type'] ?? 'none') . "</td></tr>";
        echo "<tr><td>Pattern</td><td class='info'>" . htmlspecialchars($match_result['pattern'] ?? 'none') . "</td></tr>";
        echo "<tr><td>Source</td><td class='info'>" . htmlspecialchars($match_result['source'] ?? 'none') . "</td></tr>";

        if ($match_result['config'] && $match_result['config'] !== '[Closure]') {
            $config_display = is_array($match_result['config']) ? json_encode($match_result['config'], JSON_PRETTY_PRINT) : $match_result['config'];
            echo "<tr><td>Config</td><td><pre>" . htmlspecialchars($config_display) . "</pre></td></tr>";
        } else {
            echo "<tr><td>Config</td><td>" . htmlspecialchars($match_result['config'] ?? 'none') . "</td></tr>";
        }

        if (!empty($match_result['params'])) {
            echo "<tr><td>Params</td><td><pre>" . htmlspecialchars(print_r($match_result['params'], true)) . "</pre></td></tr>";
        }
        echo "</table>";

        // Clean up temp file
        @unlink($temp_file);
    } else {
        echo "<p class='error'>Could not parse serve.php routes</p>";
    }
    ?>
</div>
<?php endif; ?>

<div class="section">
    <h2>1. Theme Configuration</h2>
    <?php
    $theme_template = $settings->get_setting('theme_template');
    $active_theme_plugin = $settings->get_setting('active_theme_plugin');
    $active_theme = ThemeHelper::getActive();
    ?>
    <table>
        <tr><td>theme_template</td><td class="info"><?= htmlspecialchars($theme_template ?: '(not set)') ?></td></tr>
        <tr><td>active_theme_plugin</td><td class="info"><?= htmlspecialchars($active_theme_plugin ?: '(not set)') ?></td></tr>
        <tr><td>ThemeHelper::getActive()</td><td class="info"><?= htmlspecialchars($active_theme) ?></td></tr>
    </table>
</div>

<div class="section">
    <h2>2. Available Plugins</h2>
    <table>
        <tr><th>Plugin</th><th>Active?</th><th>Theme Provider?</th><th>Has serve.php?</th><th>Routes</th></tr>
        <?php
        try {
            $availablePlugins = PluginHelper::getAvailablePlugins();
            foreach ($availablePlugins as $name => $plugin) {
                $isActive = $plugin->isActive();
                $isThemeProvider = $plugin->isActiveThemeProvider();
                $serveFile = $plugin->getIncludePath('serve.php');
                $hasServe = file_exists($serveFile);

                $routeCount = 0;
                if ($hasServe) {
                    $routes = [];
                    include $serveFile;
                    $routeCount = count($routes['dynamic'] ?? []) + count($routes['static'] ?? []) + count($routes['custom'] ?? []);
                }

                $activeClass = $isActive ? 'ok' : 'error';
                $themeClass = $isThemeProvider ? 'ok' : '';

                echo "<tr>";
                echo "<td>" . htmlspecialchars($name) . "</td>";
                echo "<td class='$activeClass'>" . ($isActive ? 'Yes' : 'No') . "</td>";
                echo "<td class='$themeClass'>" . ($isThemeProvider ? 'Yes' : 'No') . "</td>";
                echo "<td>" . ($hasServe ? 'Yes' : 'No') . "</td>";
                echo "<td>$routeCount</td>";
                echo "</tr>";
            }
        } catch (Exception $e) {
            echo "<tr><td colspan='5' class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</td></tr>";
        }
        ?>
    </table>
</div>

<div class="section">
    <h2>3. Active Plugin Routes</h2>
    <?php
    try {
        $activePlugins = PluginHelper::getActivePlugins();

        if (empty($activePlugins)) {
            echo "<p class='warning'>No active plugins found!</p>";
        }

        foreach ($activePlugins as $name => $plugin) {
            $serveFile = $plugin->getIncludePath('serve.php');
            if (file_exists($serveFile)) {
                $routes = [];
                include $serveFile;

                echo "<h3>$name</h3>";

                if (!empty($routes['dynamic'])) {
                    echo "<p><strong>Dynamic routes:</strong></p><ul>";
                    foreach (array_keys($routes['dynamic']) as $route) {
                        echo "<li>" . htmlspecialchars($route) . "</li>";
                    }
                    echo "</ul>";
                }

                if (!empty($routes['static'])) {
                    echo "<p><strong>Static routes:</strong></p><ul>";
                    foreach (array_keys($routes['static']) as $route) {
                        echo "<li>" . htmlspecialchars($route) . "</li>";
                    }
                    echo "</ul>";
                }

                if (!empty($routes['custom'])) {
                    echo "<p><strong>Custom routes:</strong></p><ul>";
                    foreach (array_keys($routes['custom']) as $route) {
                        echo "<li>" . htmlspecialchars($route) . "</li>";
                    }
                    echo "</ul>";
                }
            }
        }
    } catch (Exception $e) {
        echo "<p class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    ?>
</div>

<div class="section">
    <h2>4. Plugin Database Status</h2>
    <table>
        <tr><th>Plugin</th><th>plg_active</th><th>Activated</th></tr>
        <?php
        try {
            $dbconnector = DbConnector::get_instance();
            $dblink = $dbconnector->get_db_link();
            $sql = "SELECT plg_name, plg_active, plg_activated_time FROM plg_plugins ORDER BY plg_name";
            $q = $dblink->prepare($sql);
            $q->execute();
            $results = $q->fetchAll(PDO::FETCH_ASSOC);

            foreach ($results as $row) {
                $activeClass = $row['plg_active'] ? 'ok' : 'error';
                echo "<tr>";
                echo "<td>" . htmlspecialchars($row['plg_name']) . "</td>";
                echo "<td class='$activeClass'>" . ($row['plg_active'] ? 'Yes' : 'No') . "</td>";
                echo "<td>" . htmlspecialchars($row['plg_activated_time'] ?? '') . "</td>";
                echo "</tr>";
            }

            if (empty($results)) {
                echo "<tr><td colspan='3' class='warning'>No plugins in database</td></tr>";
            }
        } catch (PDOException $e) {
            echo "<tr><td colspan='3' class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</td></tr>";
        }
        ?>
    </table>
</div>

<div class="section">
    <h2>5. Route Processing Order</h2>
    <p class="info">Routes are processed in this order:</p>
    <ol>
        <li>Static routes (assets, CSS, JS, images)</li>
        <li>Theme routes (from theme/[theme]/serve.php)</li>
        <li>Plugin routes (from active plugins, prepended to main routes)</li>
        <li>Custom routes (closures for complex logic)</li>
        <li>Dynamic routes (view-based and model-based)</li>
        <li>View directory fallback (automatic theme-aware lookup)</li>
        <li>404</li>
    </ol>
    <p class="warning">Note: Plugin routes are prepended, so they take priority over main routes with the same pattern.</p>
</div>

</body>
</html>
