# Phase 3: Plugin Validation - Complete Implementation Specification

## Overview
Phase 3 implements validation to ensure plugins can properly function as theme providers, including required files, route validation, and uninstall protection.

## 1. Plugin Validation Requirements

### 1.1 Theme Provider Plugin Requirements

For a plugin to work as a theme provider, it MUST have:

**Required Files:**
- `/plugins/[name]/plugin.json` - Plugin metadata with `"provides_theme": true`
- `/plugins/[name]/includes/PublicPage.php` - Base page class for theme
- `/plugins/[name]/includes/FormWriter.php` - Form generation class (can wrap system FormWriter)

**Required Route:**
- Must provide route: `/[plugin-name]` - Main dashboard/homepage route

**Route Restrictions:**
- Cannot define: `/login`, `/logout`, `/register` - System handles authentication

**Optional but Recommended:**
- `/plugins/[name]/views/index.php` - Homepage view
- `/plugins/[name]/assets/` - Theme assets directory

## 2. Validation Implementation in PluginHelper

### BEFORE: Current PluginHelper.php (no validation)

```php
// /var/www/html/joinerytest/public_html/includes/PluginHelper.php - Current version
class PluginHelper extends ComponentBase {
    
    // ... existing methods ...
    
    public function providesTheme() {
        $metadata = $this->getMetadata();
        return isset($metadata['provides_theme']) && $metadata['provides_theme'] === true;
    }
    
    // No validation methods exist
}
```

### AFTER: Enhanced PluginHelper.php with Validation

```php
// /var/www/html/joinerytest/public_html/includes/PluginHelper.php - Enhanced version
class PluginHelper extends ComponentBase {
    
    // ... existing methods ...
    
    /**
     * Check if plugin provides theme functionality
     * @return bool
     */
    public function providesTheme() {
        $metadata = $this->getMetadata();
        return isset($metadata['provides_theme']) && $metadata['provides_theme'] === true;
    }
    
    /**
     * Validate if plugin can serve as a theme provider
     * @return array ['valid' => bool, 'errors' => array, 'warnings' => array]
     */
    public function validateAsThemeProvider() {
        $plugin_dir = PathHelper::getIncludePath("plugins/{$this->pluginName}");
        $errors = [];
        $warnings = [];
        
        // Check for mandatory plugin.json
        if (!$this->metadataExists()) {
            $errors[] = "Missing required file: plugin.json - Plugin metadata is mandatory for theme providers";
        } else {
            // Check for provides_theme flag
            $metadata = $this->getMetadata();
            if (!isset($metadata['provides_theme']) || $metadata['provides_theme'] !== true) {
                $errors[] = "plugin.json must have 'provides_theme': true to serve as theme provider";
            }
        }
        
        // Check required files
        $required_files = [
            'includes/PublicPage.php' => 'PublicPage class is required for theme functionality',
            'includes/FormWriter.php' => 'FormWriter class is required for form generation'
        ];
        
        foreach ($required_files as $file => $error_message) {
            $file_path = $plugin_dir . '/' . $file;
            if (!file_exists($file_path)) {
                $errors[] = "Missing required file: {$file} - {$error_message}";
            }
        }
        
        // Check for main route in serve.php
        $serve_file = $plugin_dir . '/serve.php';
        if (file_exists($serve_file)) {
            $serve_content = file_get_contents($serve_file);
            
            // Check for main plugin route
            if (!preg_match("/['\"]\/?" . preg_quote($this->pluginName, '/') . "['\"]\\s*=>/", $serve_content)) {
                $warnings[] = "No main route '/{$this->pluginName}' found in serve.php";
            }
            
            // Check for restricted routes
            $restricted_routes = ['/login', '/logout', '/register'];
            foreach ($restricted_routes as $route) {
                if (preg_match("/['\"]" . preg_quote($route, '/') . "['\"]\\s*=>/", $serve_content)) {
                    $errors[] = "Plugin cannot define system route: {$route}";
                }
            }
        } else {
            $warnings[] = "No serve.php file found - plugin may not define any routes";
        }
        
        // Check for recommended files
        $recommended_files = [
            'views/index.php' => 'Homepage view recommended',
            'assets/css/style.css' => 'Theme styles recommended'
        ];
        
        foreach ($recommended_files as $file => $message) {
            $file_path = $plugin_dir . '/' . $file;
            if (!file_exists($file_path)) {
                $warnings[] = "Missing recommended file: {$file} - {$message}";
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }
    
    /**
     * Check if this plugin is currently the active theme provider
     * @return bool
     */
    public function isActiveThemeProvider() {
        $settings = Globalvars::get_instance();
        $theme_template = $settings->get_setting('theme_template');
        $active_plugin = $settings->get_setting('active_theme_plugin');
        
        return $theme_template === 'plugin' && $active_plugin === $this->pluginName;
    }
    
    /**
     * Get all plugins that can provide theme functionality
     * @return array Array of PluginHelper instances that are valid theme providers
     */
    public static function getValidThemeProviders() {
        $all_plugins = self::getAvailablePlugins();
        $theme_providers = [];
        
        foreach ($all_plugins as $plugin_name => $plugin_helper) {
            $validation = $plugin_helper->validateAsThemeProvider();
            if ($validation['valid']) {
                $theme_providers[$plugin_name] = $plugin_helper;
            }
        }
        
        return $theme_providers;
    }
}
```

## 3. Plugin Uninstall Protection

### BEFORE: Current admin_plugins.php (no protection)

```php
// /var/www/html/joinerytest/public_html/adm/admin_plugins.php - Current version (excerpt)
// Around uninstall handling section

if (isset($_POST['uninstall_plugin'])) {
    $plugin_to_uninstall = $_POST['plugin_name'];
    $plugin_dir = PathHelper::getIncludePath("plugins/{$plugin_to_uninstall}");
    
    // Directly proceeds with uninstall
    if (is_dir($plugin_dir)) {
        // Run uninstall script if exists
        $uninstall_file = $plugin_dir . '/uninstall.php';
        if (file_exists($uninstall_file)) {
            include $uninstall_file;
            $uninstall_function = $plugin_to_uninstall . '_uninstall';
            if (function_exists($uninstall_function)) {
                $uninstall_function();
            }
        }
        
        // Remove plugin directory
        // ... removal code ...
        
        echo '<div class="alert alert-success">Plugin uninstalled successfully</div>';
    }
}
```

### AFTER: Enhanced admin_plugins.php with Protection

```php
// /var/www/html/joinerytest/public_html/adm/admin_plugins.php - Enhanced version (excerpt)
// Around uninstall handling section

if (isset($_POST['uninstall_plugin'])) {
    $plugin_to_uninstall = $_POST['plugin_name'];
    $plugin_dir = PathHelper::getIncludePath("plugins/{$plugin_to_uninstall}");
    
    // Check if plugin is currently the active theme provider
    $plugin_helper = new PluginHelper($plugin_to_uninstall);
    if ($plugin_helper->isActiveThemeProvider()) {
        echo '<div class="alert alert-danger">';
        echo '<strong>Cannot Uninstall:</strong> ';
        echo "The plugin '{$plugin_to_uninstall}' is currently the active theme provider. ";
        echo 'Please select a different theme in <a href="/adm/admin_settings">Settings</a> before uninstalling.';
        echo '</div>';
    } else {
        // Safe to proceed with uninstall
        if (is_dir($plugin_dir)) {
            // Run uninstall script if exists
            $uninstall_file = $plugin_dir . '/uninstall.php';
            if (file_exists($uninstall_file)) {
                include $uninstall_file;
                $uninstall_function = $plugin_to_uninstall . '_uninstall';
                if (function_exists($uninstall_function)) {
                    $uninstall_function();
                }
            }
            
            // Remove plugin directory
            // ... removal code ...
            
            echo '<div class="alert alert-success">Plugin uninstalled successfully</div>';
        }
    }
}

// Also update the plugin list display to show active theme provider
// In the plugin listing loop:
foreach ($plugins as $plugin_name => $plugin_helper) {
    // ... existing plugin display code ...
    
    // Add badge if this is the active theme provider
    if ($plugin_helper->isActiveThemeProvider()) {
        echo ' <span class="badge badge-primary">Active Theme Provider</span>';
        // Disable uninstall button
        $can_uninstall = false;
    } else {
        $can_uninstall = true;
    }
    
    // Show uninstall button only if allowed
    if ($can_uninstall) {
        echo '<form method="post" style="display:inline;">';
        echo '<input type="hidden" name="plugin_name" value="' . $plugin_name . '">';
        echo '<button type="submit" name="uninstall_plugin" class="btn btn-danger btn-sm" ';
        echo 'onclick="return confirm(\'Are you sure you want to uninstall this plugin?\');">';
        echo 'Uninstall</button>';
        echo '</form>';
    } else {
        echo '<button class="btn btn-secondary btn-sm" disabled ';
        echo 'title="Cannot uninstall active theme provider">Uninstall</button>';
    }
}
```

