<?php
/**
 * Test utility for component system
 * Run: php utils/test_components.php
 */

require_once(__DIR__ . '/../includes/PathHelper.php');
PathHelper::requireOnce('includes/ThemeHelper.php');
PathHelper::requireOnce('includes/PluginHelper.php');

echo "Testing Component System\n";
echo "========================\n\n";

// Test 1: Discover all themes
echo "1. Discovering all themes...\n";
$allThemes = ThemeHelper::getAvailableThemes();
echo "   Found " . count($allThemes) . " themes\n";

foreach ($allThemes as $name => $theme) {
    echo "   - {$name}: {$theme->getDisplayName()}\n";
}

// Test 2: Discover all plugins
echo "\n2. Discovering all plugins...\n";
$allPlugins = PluginHelper::getAvailablePlugins();
echo "   Found " . count($allPlugins) . " plugins\n";

foreach ($allPlugins as $name => $plugin) {
    echo "   - {$name}: {$plugin->getDisplayName()}\n";
}

// Test 3: Validate all components
echo "\n3. Validating all components...\n";

$themeValidation = ThemeHelper::validateAll();
echo "   Themes:\n";
foreach ($themeValidation as $name => $result) {
    if ($result['valid']) {
        echo "   ✓ {$name}: Valid\n";
    } else {
        echo "   ✗ {$name}: " . implode(', ', $result['errors']) . "\n";
    }
}

$pluginValidation = PluginHelper::validateAll();
echo "   Plugins:\n";
foreach ($pluginValidation as $name => $result) {
    if ($result['valid']) {
        echo "   ✓ {$name}: Valid\n";
    } else {
        echo "   ✗ {$name}: " . implode(', ', $result['errors']) . "\n";
    }
}

// Test 4: Check active components
echo "\n4. Checking active components...\n";

try {
    $activeTheme = ThemeHelper::getInstance();
    echo "   Active theme: {$activeTheme->getName()}\n";
    echo "   Display name: {$activeTheme->getDisplayName()}\n";
    echo "   CSS Framework: " . ($activeTheme->getCssFramework() ?? 'not specified') . "\n";
    echo "   FormWriter Base: " . ($activeTheme->getFormWriterBase() ?? 'not specified') . "\n";
} catch (Exception $e) {
    echo "   Theme error: " . $e->getMessage() . "\n";
}

$activePlugins = PluginHelper::getActivePlugins();
echo "   Active plugins: " . count($activePlugins) . "\n";
foreach ($activePlugins as $name => $plugin) {
    echo "   - {$name}\n";
}

// Test 5: Test theme functionality
echo "\n5. Testing theme asset methods...\n";
try {
    $assetUrl = ThemeHelper::asset('css/theme.css');
    echo "   Theme asset URL: {$assetUrl}\n";
    
    $configValue = ThemeHelper::config('cssFramework', 'unknown');
    echo "   Theme CSS framework: {$configValue}\n";
} catch (Exception $e) {
    echo "   Asset test error: " . $e->getMessage() . "\n";
}

// Test 6: Statistics
echo "\n6. Component Statistics:\n";
$totalThemes = count($allThemes);
$totalPlugins = count($allPlugins);
$totalActive = 1 + count($activePlugins); // 1 theme + active plugins

echo "   Total themes: {$totalThemes}\n";
echo "   Total plugins: {$totalPlugins}\n";
echo "   Total components: " . ($totalThemes + $totalPlugins) . "\n";
echo "   Active components: {$totalActive}\n";

echo "\n✓ All tests completed!\n";