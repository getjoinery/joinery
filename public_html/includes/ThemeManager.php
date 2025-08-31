<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
PathHelper::requireOnce('includes/AbstractExtensionManager.php');
PathHelper::requireOnce('data/themes_class.php');

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
}
?>