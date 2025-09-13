<?php
/**
 * Pre-Phase 2 Validation Script and Component System Test
 * Ensures all themes and plugins have valid manifests before Phase 2 deployment
 * Run: php utils/test_components.php
 */

require_once(__DIR__ . '/../includes/PathHelper.php');
PathHelper::requireOnce('includes/ThemeHelper.php');
PathHelper::requireOnce('includes/PluginHelper.php');

echo "Phase 2 Pre-Deployment Validation & Component System Test\n";
echo "=========================================================\n\n";

$errors = [];
$warnings = [];

// Check all themes have manifests
echo "1. Checking theme manifests...\n";
// Get themes from both sources
$directory_themes = ThemeHelper::getAvailableThemes();
$plugins = PluginHelper::getActivePlugins();

$themes = array();

// Add directory themes
foreach($directory_themes as $theme_name => $theme_helper) {
    $themes[] = array(
        'name' => $theme_name,
        'type' => 'directory',
        'path' => PathHelper::getIncludePath('theme/' . $theme_name)
    );
}

// Add plugin themes
foreach($plugins as $plugin_name => $plugin) {
    $themes[] = array(
        'name' => $plugin_name,
        'type' => 'plugin', 
        'path' => PathHelper::getIncludePath('plugins/' . $plugin_name)
    );
}

if (count($themes) > 0) {
    foreach ($themes as $theme) {
        $themeName = $theme['name'];
        $themeType = $theme['type'];
        $themePath = $theme['path'];
        
        if (substr($themeName, 0, 1) === '.') {
            continue; // Skip hidden directories
        }
        
        $manifestPath = $themePath . '/theme.json';
        if (!file_exists($manifestPath)) {
            $errors[] = "Theme '{$themeName}' ({$themeType}) missing required theme.json manifest";
            continue;
        }
        
        // Validate manifest content
        try {
            $theme = ThemeHelper::getInstance($themeName);
            $validation = $theme->validate();
            
            if ($validation === true) {
                echo "   ✓ {$themeName} ({$themeType}): Valid manifest\n";
            } else {
                $errors[] = "Theme '{$themeName}' ({$themeType}) manifest invalid: " . implode(', ', $validation);
            }
        } catch (Exception $e) {
            $errors[] = "Theme '{$themeName}' ({$themeType}) error: " . $e->getMessage();
        }
    }
}

// Check all plugins have manifests
echo "\n2. Checking plugin manifests...\n";
$pluginDir = PathHelper::getIncludePath('plugins');
if (is_dir($pluginDir)) {
    $directories = glob($pluginDir . '/*', GLOB_ONLYDIR);
    foreach ($directories as $dir) {
        $pluginName = basename($dir);
        
        if (substr($pluginName, 0, 1) === '.') {
            continue; // Skip hidden directories
        }
        
        $manifestPath = $dir . '/plugin.json';
        if (!file_exists($manifestPath)) {
            $errors[] = "Plugin '{$pluginName}' missing required plugin.json manifest";
            continue;
        }
        
        // Validate manifest content
        try {
            $plugin = PluginHelper::getInstance($pluginName);
            $validation = $plugin->validate();
            
            if ($validation === true) {
                echo "   ✓ {$pluginName}: Valid manifest\n";
            } else {
                $errors[] = "Plugin '{$pluginName}' manifest invalid: " . implode(', ', $validation);
            }
        } catch (Exception $e) {
            $errors[] = "Plugin '{$pluginName}' error: " . $e->getMessage();
        }
    }
}

// Check for legacy FormWriter usage
echo "\n3. Checking for legacy FormWriter patterns...\n";
$legacyPatterns = [
    'PathHelper::getThemeFilePath(' => 'Should use ThemeHelper methods instead',
    'get_formwriter_object(' => 'Will work but could be enhanced with manifest-based selection'
];

$checkDirs = ['adm', 'views', 'logic', 'theme', 'plugins'];
foreach ($checkDirs as $checkDir) {
    $fullDir = PathHelper::getIncludePath($checkDir);
    if (is_dir($fullDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fullDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $content = file_get_contents($file->getPathname());
                foreach ($legacyPatterns as $pattern => $message) {
                    if (strpos($content, $pattern) !== false) {
                        $relativePath = str_replace(PathHelper::getIncludePath(''), '', $file->getPathname());
                        $warnings[] = "File '{$relativePath}' uses legacy pattern '{$pattern}': {$message}";
                    }
                }
            }
        }
    }
}

// Report validation results
echo "\n4. Validation Results:\n";
echo "======================\n";

if (empty($errors)) {
    echo "✓ All components have valid manifests - READY FOR PHASE 2!\n";
} else {
    echo "✗ ERRORS FOUND - Phase 2 cannot proceed until these are resolved:\n";
    foreach ($errors as $error) {
        echo "   - {$error}\n";
    }
}

if (!empty($warnings)) {
    echo "\n⚠ WARNINGS (non-blocking but recommended to address):\n";
    foreach ($warnings as $warning) {
        echo "   - {$warning}\n";
    }
}

// Additional testing (if no blocking errors)
if (empty($errors)) {
    echo "\n5. Additional Component Testing:\n";
    echo "=================================\n";

    // Test active theme
    try {
        $activeTheme = ThemeHelper::getInstance();
        echo "   Active theme: {$activeTheme->getName()}\n";
        echo "   Display name: {$activeTheme->getDisplayName()}\n";
        echo "   CSS Framework: " . ($activeTheme->getCssFramework() ?? 'not specified') . "\n";
        echo "   FormWriter Base: " . ($activeTheme->getFormWriterBase() ?? 'not specified') . "\n";
    } catch (Exception $e) {
        echo "   Theme error: " . $e->getMessage() . "\n";
    }

    // Test theme functionality
    try {
        $assetUrl = PathHelper::getThemeFilePath('theme.css', 'assets/css', 'web');
        echo "   Theme asset URL: {$assetUrl}\n";

        $configValue = ThemeHelper::config('cssFramework', 'unknown');
        echo "   Theme CSS framework config: {$configValue}\n";
    } catch (Exception $e) {
        echo "   Asset test error: " . $e->getMessage() . "\n";
    }

    // Get statistics
    $allThemes = ThemeHelper::getAvailableThemes();
    $allPlugins = PluginHelper::getAvailablePlugins();
    
    echo "\n6. Component Statistics:\n";
    echo "========================\n";
    echo "   Total themes discovered: " . count($allThemes) . "\n";
    echo "   Total plugins discovered: " . count($allPlugins) . "\n";
    echo "   Total components: " . (count($allThemes) + count($allPlugins)) . "\n";
    
    // List discovered themes
    if (!empty($allThemes)) {
        echo "\n   Discovered themes:\n";
        foreach ($allThemes as $name => $theme) {
            echo "   - {$name}: {$theme->getDisplayName()}\n";
        }
    }
    
    // List discovered plugins
    if (!empty($allPlugins)) {
        echo "\n   Discovered plugins:\n";
        foreach ($allPlugins as $name => $plugin) {
            echo "   - {$name}: {$plugin->getDisplayName()}\n";
        }
    }
}

echo "\nValidation complete.\n";

// Exit with error code if there are blocking issues
exit(empty($errors) ? 0 : 1);