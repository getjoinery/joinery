<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/AbstractExtensionManager.php'));
require_once(PathHelper::getIncludePath('data/themes_class.php'));

/**
 * ThemeManager - Manages theme installation, activation, and configuration
 */
class ThemeManager extends AbstractExtensionManager {
    
    private static $instance = null;
    
    public function __construct() {
        parent::__construct();
        $this->extension_type = 'theme';
        $this->extension_dir = 'theme';
        $this->manifest_filename = 'theme.json';
        $this->table_prefix = 'thm';
        $this->model_class = 'Theme';
        $this->multi_model_class = 'MultiTheme';
    }
    
    /**
     * Get singleton instance
     * @return ThemeManager
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Get additional reserved names for themes
     * @return array
     */
    protected function getAdditionalReservedNames() {
        return array('plugins', 'plugin');
    }
    
    /**
     * Get default status for new themes
     * @return string
     */
    protected function getDefaultStatus() {
        return 'installed';
    }
    
    /**
     * Find and validate theme manifest
     * @param string $temp_dir Temporary directory containing extracted files
     * @return array Contains 'root', 'manifest', and 'name'
     */
    protected function findAndValidateManifest($temp_dir) {
        $manifest_path = null;
        $theme_name = null;
        $theme_root = null;
        
        // Check root directory first
        if (file_exists("$temp_dir/theme.json")) {
            $manifest_path = "$temp_dir/theme.json";
            $theme_root = $temp_dir;
        } else {
            // Look in first subdirectory
            $dirs = scandir($temp_dir);
            foreach ($dirs as $dir) {
                if ($dir == '.' || $dir == '..') continue;
                if (is_dir("$temp_dir/$dir") && file_exists("$temp_dir/$dir/theme.json")) {
                    $manifest_path = "$temp_dir/$dir/theme.json";
                    $theme_root = "$temp_dir/$dir";
                    $theme_name = $dir;
                    break;
                }
            }
        }
        
        if (!$manifest_path) {
            throw new Exception("No theme.json found in uploaded file");
        }
        
        $manifest = json_decode(file_get_contents($manifest_path), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid theme.json: " . json_last_error_msg());
        }
        
        // Determine theme name from manifest if not from directory
        if (!$theme_name && isset($manifest['name'])) {
            $theme_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($manifest['name']));
            if (!preg_match('/^[a-zA-Z]/', $theme_name)) {
                $theme_name = 'theme_' . $theme_name;
            }
        }
        
        if (!$theme_name) {
            throw new Exception("Could not determine theme name");
        }
        
        return array(
            'root' => $theme_root,
            'manifest' => $manifest,
            'name' => $theme_name
        );
    }
    
    /**
     * Handle existing theme when installing
     * @param string $path Path to existing theme
     */
    protected function handleExistingExtension($path) {
        throw new Exception("Theme already exists. Please uninstall the existing version first.");
    }
    
    /**
     * Post-installation tasks for themes
     * @param string $name Theme name
     * @param array $manifest Theme manifest data
     */
    protected function postInstall($name, $manifest) {
        // Sync themes with database
        $this->sync();
        
        // Mark as custom (not stock) since it was uploaded
        $theme = Theme::get_by_theme_name($name);
        if ($theme) {
            $theme->set('thm_is_stock', false);
            $theme->save();
        }
    }
    
    /**
     * Load metadata from theme.json into model
     * @param Theme $model Theme model object
     * @param string $name Theme name
     */
    protected function loadMetadataIntoModel($model, $name) {
        $manifest_path = $this->getExtensionPath($name) . '/theme.json';
        if (!file_exists($manifest_path)) return;
        
        $metadata = json_decode(file_get_contents($manifest_path), true);
        if (json_last_error() === JSON_ERROR_NONE && $metadata) {
            $model->set('thm_metadata', json_encode($metadata));
            $model->set('thm_display_name', $metadata['name'] ?? $name);
            $model->set('thm_description', $metadata['description'] ?? '');
            $model->set('thm_version', $metadata['version'] ?? '1.0.0');
            $model->set('thm_author', $metadata['author'] ?? 'Unknown');
            $model->set('thm_is_stock', $metadata['is_stock'] ?? true);
        }
    }
    
    /**
     * Update existing theme metadata
     * @param Theme $model Theme model object
     * @param string $name Theme name
     */
    protected function updateExistingMetadata($model, $name) {
        $manifest_path = $this->getExtensionPath($name) . '/theme.json';
        if (!file_exists($manifest_path)) return false;
        
        $metadata = json_decode(file_get_contents($manifest_path), true);
        if (json_last_error() === JSON_ERROR_NONE && $metadata && isset($metadata['is_stock'])) {
            // Only update stock status if it's explicitly defined in manifest
            $current_stock = $model->get('thm_is_stock');
            $manifest_stock = $metadata['is_stock'];
            
            if ($current_stock != $manifest_stock) {
                $model->set('thm_is_stock', $manifest_stock);
                $model->save();
                return true; // Was updated
            }
        }
        return false; // No update needed
    }
    
    // Theme-specific public methods
    
    /**
     * Get the currently active theme
     * @return Theme|null Active theme or null if none
     */
    public function getActiveTheme() {
        $active_themes = new MultiTheme(array('thm_is_active' => true));
        $active_themes->load();
        
        if ($active_themes->count() > 0) {
            return $active_themes->get(0);
        }
        
        return null;
    }
    
    /**
     * Set the active theme
     * @param string $theme_name Theme name to activate
     * @return bool Success status
     */
    public function setActiveTheme($theme_name) {
        $theme = Theme::get_by_theme_name($theme_name);
        
        if (!$theme) {
            throw new Exception("Theme not found: $theme_name");
        }
        
        return $theme->activate();
    }
    
    /**
     * Install theme (alias for installFromZip for backward compatibility)
     * @param string $zip_path Path to ZIP file
     * @return string Theme name
     */
    public function installTheme($zip_path) {
        return $this->installFromZip($zip_path);
    }

    /**
     * Override sync to include component type discovery
     * @return array Result with theme and component sync counts
     */
    public function sync() {
        $result = parent::sync();
        $result['components'] = $this->syncComponentTypes();
        return $result;
    }

    /**
     * Sync component types from theme JSON files
     *
     * Scans base /views/components/ and active theme's views/components/
     * for JSON definition files with matching PHP templates.
     *
     * @return array Summary: ['created' => n, 'updated' => n, 'unchanged' => n, 'deactivated' => n]
     */
    public function syncComponentTypes() {
        require_once(PathHelper::getIncludePath('data/components_class.php'));

        $discovered = array();

        // Get active theme's CSS framework
        $settings = Globalvars::get_instance();
        $active_theme = $settings->get_setting('theme_template');
        $theme_framework = null;

        if ($active_theme) {
            $theme_manifest_path = PathHelper::getIncludePath("theme/{$active_theme}/theme.json");
            if (file_exists($theme_manifest_path)) {
                $theme_manifest = json_decode(file_get_contents($theme_manifest_path), true);
                if ($theme_manifest && isset($theme_manifest['cssFramework'])) {
                    $theme_framework = $theme_manifest['cssFramework'];
                }
            }
        }

        // Scan base /views/components/ first
        $base_path = PathHelper::getIncludePath('views/components');
        $discovered = $this->scanComponentDirectory($base_path, $discovered, $theme_framework);

        // Then scan active theme (overrides base)
        if ($active_theme) {
            $theme_path = PathHelper::getIncludePath("theme/{$active_theme}/views/components");
            $discovered = $this->scanComponentDirectory($theme_path, $discovered, $theme_framework);
        }

        // Sync to database
        $summary = array('created' => 0, 'updated' => 0, 'unchanged' => 0, 'deactivated' => 0);
        $seen_keys = array();

        foreach ($discovered as $type_key => $def) {
            $seen_keys[] = $type_key;
            $existing = Component::GetByColumn('com_type_key', $type_key);

            if ($existing) {
                $changed = false;
                $fields = array('title', 'description', 'category', 'icon', 'template_file',
                               'logic_function', 'requires_plugin', 'css_framework', 'order');

                foreach ($fields as $field) {
                    $db_value = $existing->get('com_' . $field);
                    $new_value = $def[$field];
                    if ($db_value !== $new_value) {
                        $existing->set('com_' . $field, $new_value);
                        $changed = true;
                    }
                }

                // Check config schema
                $existing_schema = $existing->get('com_config_schema');
                if (json_encode($existing_schema) !== json_encode($def['config_schema'])) {
                    $existing->set('com_config_schema', $def['config_schema']);
                    $changed = true;
                }

                // Reactivate if needed
                if (!$existing->get('com_is_active')) {
                    $existing->set('com_is_active', true);
                    $changed = true;
                }

                // Undelete if soft-deleted (filesystem is source of truth)
                if ($existing->get('com_delete_time')) {
                    $existing->undelete();
                    $changed = true;
                }

                if ($changed) {
                    $existing->save();
                    $summary['updated']++;
                } else {
                    $summary['unchanged']++;
                }
            } else {
                // Create new
                $component = new Component(null);
                $component->set('com_type_key', $type_key);
                $component->set('com_title', $def['title']);
                $component->set('com_description', $def['description']);
                $component->set('com_category', $def['category']);
                $component->set('com_icon', $def['icon']);
                $component->set('com_template_file', $def['template_file']);
                $component->set('com_config_schema', $def['config_schema']);
                $component->set('com_logic_function', $def['logic_function']);
                $component->set('com_requires_plugin', $def['requires_plugin']);
                $component->set('com_css_framework', $def['css_framework']);
                $component->set('com_order', $def['order']);
                $component->set('com_is_active', true);
                $component->save();
                $summary['created']++;
            }
        }

        // Deactivate component types whose templates no longer exist or framework doesn't match
        $all_types = new MultiComponent(array('active' => true, 'deleted' => false), array());
        $all_types->load();

        foreach ($all_types as $type) {
            $type_key = $type->get('com_type_key');

            // Skip if we just processed this one
            if (in_array($type_key, $seen_keys)) {
                continue;
            }

            // Check if template exists
            $template = $type->get('com_template_file');
            $template_path = PathHelper::getThemeFilePath($template, 'views/components');

            $should_deactivate = false;

            if (!file_exists($template_path)) {
                $should_deactivate = true;
            } else {
                // Check framework compatibility
                $component_framework = $type->get('com_css_framework');
                if ($component_framework && $theme_framework && $component_framework !== $theme_framework) {
                    $should_deactivate = true;
                }
            }

            if ($should_deactivate) {
                $type->set('com_is_active', false);
                $type->save();
                $summary['deactivated']++;
            }
        }

        return $summary;
    }

    /**
     * Scan a directory for component JSON files
     *
     * @param string $directory Path to scan
     * @param array $discovered Already discovered components (to merge/override)
     * @param string|null $theme_framework Active theme's CSS framework
     * @return array Updated discovered components array
     */
    protected function scanComponentDirectory($directory, $discovered, $theme_framework = null) {
        if (!is_dir($directory)) {
            return $discovered;
        }

        $json_files = glob($directory . '/*.json');

        foreach ($json_files as $json_file) {
            $type_key = basename($json_file, '.json');
            $template_file = $directory . '/' . $type_key . '.php';

            // Skip if no matching template
            if (!file_exists($template_file)) {
                continue;
            }

            $metadata = json_decode(file_get_contents($json_file), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("Component sync: Invalid JSON in {$json_file}");
                continue;
            }

            if (empty($metadata['title']) || empty($metadata['config_schema'])) {
                error_log("Component sync: Missing required fields in {$json_file}");
                continue;
            }

            // Check framework compatibility
            $component_framework = $metadata['css_framework'] ?? null;
            if ($component_framework && $theme_framework && $component_framework !== $theme_framework) {
                // Framework doesn't match, skip this component
                continue;
            }

            // Theme definitions override base (since we process base first)
            $discovered[$type_key] = array(
                'type_key' => $type_key,
                'title' => $metadata['title'],
                'description' => $metadata['description'] ?? null,
                'category' => $metadata['category'] ?? 'custom',
                'icon' => $metadata['icon'] ?? 'bx bx-cube',
                'template_file' => $type_key . '.php',
                'config_schema' => $metadata['config_schema'],
                'logic_function' => $metadata['logic_function'] ?? null,
                'requires_plugin' => $metadata['requires_plugin'] ?? null,
                'css_framework' => $component_framework,
                'order' => $metadata['order'] ?? 100,
            );
        }

        return $discovered;
    }
}
?>