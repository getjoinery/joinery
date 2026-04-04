<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class Theme extends SystemBase {    public static $prefix = 'thm';
    public static $tablename = 'thm_themes';
    public static $pkey_column = 'thm_theme_id';

    /**
    
     * Field specifications define database column properties and validation rules
    
     * 
    
     * Database schema properties (used by update_database):
    
     *   'type' => 'varchar(255)' | 'int4' | 'int8' | 'text' | 'timestamp' | 'bool' | etc.
    
     *   'is_nullable' => true/false - Whether NULL values are allowed
    
     *   'serial' => true/false - Auto-incrementing field
    
     * 
    
     * Validation and behavior properties (used by SystemBase):
    
     *   'required' => true/false - Field must have non-empty value on save
    
     *   'default' => mixed - Default value for new records (applied on INSERT only)
    
     *   'zero_on_create' => true/false - Set to 0 when creating if NULL (INSERT only)
    
     * 
    
     * Note: Timestamp fields are auto-detected based on type for smart_get() and export_as_array()
    
     */
    
    public static $field_specifications = array(
    
        'thm_theme_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
    
        'thm_name' => array('type'=>'varchar(50)', 'is_nullable'=>false, 'required'=>true, 'unique'=>true),
    
        'thm_display_name' => array('type'=>'varchar(100)'),
    
        'thm_description' => array('type'=>'text'),
    
        'thm_version' => array('type'=>'varchar(20)'),
    
        'thm_author' => array('type'=>'varchar(100)'),
    
        'thm_is_active' => array('type'=>'bool', 'default'=>false),
    
        'thm_is_stock' => array('type'=>'bool', 'default'=>true),

        'thm_is_system' => array('type'=>'bool', 'default'=>false),

        'thm_status' => array('type'=>'varchar(20)', 'default'=>'installed'),
    
        'thm_metadata' => array('type'=>'jsonb'),
    
        'thm_installed_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
    
        'thm_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
    
        'thm_update_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),

        'thm_install_error' => array('type'=>'text', 'is_nullable'=>true),

    );

    public static $json_vars = array('thm_metadata');

    /**
     * Get theme by name
     */
    public static function get_by_theme_name($theme_name) {
        return static::GetByColumn('thm_name', $theme_name);
    }

    /**
     * Get all themes from filesystem with their database status.
     * Scans the theme/ directory and merges with database records.
     * Unregistered filesystem themes appear with metadata from theme.json.
     *
     * @return array Array of theme data arrays (name, directory_exists, theme, is_active, etc.)
     */
    public static function get_all_themes_with_status() {
        $themes_dir = PathHelper::getAbsolutePath('theme');
        $themes = array();

        if (!is_dir($themes_dir)) {
            return $themes;
        }

        // Load all DB records at once
        $all_themes = new MultiTheme(array(), array('thm_name' => 'ASC'));
        $all_themes->load();

        $themes_lookup = array();
        foreach ($all_themes as $theme) {
            $themes_lookup[$theme->get('thm_name')] = $theme;
        }

        // Process each theme directory
        $dirs = array_diff(scandir($themes_dir), array('.', '..'));
        foreach ($dirs as $theme_name) {
            $theme_path = $themes_dir . '/' . $theme_name;
            if (!is_dir($theme_path)) continue;

            $theme_data = array(
                'name' => $theme_name,
                'directory_exists' => true,
            );

            if (isset($themes_lookup[$theme_name])) {
                $theme = $themes_lookup[$theme_name];
                $theme_data['theme'] = $theme;
                $theme_data['is_active'] = (bool)$theme->get('thm_is_active');
                $theme_data['display_name'] = $theme->get('thm_display_name') ?: $theme_name;
                $theme_data['description'] = $theme->get('thm_description');
                $theme_data['version'] = $theme->get('thm_version') ?: '1.0.0';
                $theme_data['author'] = $theme->get('thm_author') ?: 'Unknown';
            } else {
                // Not in DB — read from theme.json directly
                $theme_data['theme'] = null;
                $theme_data['is_active'] = false;
                $manifest_path = $theme_path . '/theme.json';
                if (file_exists($manifest_path)) {
                    $metadata = json_decode(file_get_contents($manifest_path), true);
                    if (json_last_error() === JSON_ERROR_NONE && $metadata) {
                        $theme_data['display_name'] = $metadata['name'] ?? $theme_name;
                        $theme_data['description'] = $metadata['description'] ?? null;
                        $theme_data['version'] = $metadata['version'] ?? '1.0.0';
                        $theme_data['author'] = $metadata['author'] ?? 'Unknown';
                    } else {
                        $theme_data['display_name'] = $theme_name;
                        $theme_data['description'] = null;
                        $theme_data['version'] = null;
                        $theme_data['author'] = null;
                    }
                } else {
                    $theme_data['display_name'] = $theme_name;
                    $theme_data['description'] = null;
                    $theme_data['version'] = null;
                    $theme_data['author'] = null;
                }
            }

            $themes[] = $theme_data;
        }

        // Add orphaned DB records (in DB but not on filesystem)
        foreach ($themes_lookup as $theme_name => $theme) {
            $theme_path = $themes_dir . '/' . $theme_name;
            if (!is_dir($theme_path)) {
                $themes[] = array(
                    'name' => $theme_name,
                    'directory_exists' => false,
                    'theme' => $theme,
                    'is_active' => (bool)$theme->get('thm_is_active'),
                    'display_name' => $theme->get('thm_display_name') ?: $theme_name,
                    'description' => 'Theme directory not found',
                    'version' => $theme->get('thm_version'),
                    'author' => $theme->get('thm_author'),
                );
            }
        }

        usort($themes, function($a, $b) {
            return strcasecmp($a['display_name'], $b['display_name']);
        });

        return $themes;
    }

    /**
     * Check if theme directory exists
     */
    public function theme_files_exist() {
        $theme_name = $this->get('thm_name');
        $theme_path = PathHelper::getAbsolutePath("theme/$theme_name");
        return is_dir($theme_path);
    }

    /**
     * Check if this is a stock theme (auto-updated)
     * @return bool True if stock theme
     */
    public function is_stock() {
        return (bool)$this->get('thm_is_stock');
    }

    /**
     * Permanently delete theme - removes database record and files
     * Note: Themes don't have uninstall scripts (no database components to clean up)
     * @return array Results with success, errors, messages
     */
    public function permanent_delete_with_files() {
        $results = array(
            'success' => false,
            'errors' => array(),
            'messages' => array(),
            'warnings' => array()
        );

        try {
            $theme_name = $this->get('thm_name');
            $theme_dir = PathHelper::getAbsolutePath('theme/' . $theme_name);

            // Check if system theme
            if ($this->get('thm_is_system')) {
                $results['errors'][] = "Cannot delete system theme '$theme_name'. System themes are required for the platform to function.";
                return $results;
            }

            // Check if active theme
            $settings = Globalvars::get_instance();
            if ($settings->get_setting('theme_template') === $theme_name) {
                $results['errors'][] = "Cannot delete active theme '$theme_name'. Switch to another theme first.";
                return $results;
            }

            // Pre-flight check: verify we can delete files before making any changes
            if (is_dir($theme_dir)) {
                require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
                $perm_check = LibraryFunctions::check_directory_deletable($theme_dir);
                if (!$perm_check['can_delete']) {
                    $results['errors'][] = "Permission denied. Cannot delete: " . implode(', ', array_slice($perm_check['errors'], 0, 3));
                    if (count($perm_check['errors']) > 3) {
                        $results['errors'][0] .= ' (and ' . (count($perm_check['errors']) - 3) . ' more)';
                    }
                    return $results;
                }
            }

            // Delete files first
            if (is_dir($theme_dir)) {
                if (LibraryFunctions::delete_directory($theme_dir)) {
                    $results['messages'][] = "Deleted theme directory";
                } else {
                    $results['errors'][] = "Failed to delete theme directory";
                    return $results;
                }
            } else {
                $results['messages'][] = "Theme directory already removed";
            }

            // Delete theme database record only after files are confirmed deleted
            $this->permanent_delete();
            $results['messages'][] = "Removed theme from database";
            $results['success'] = true;

        } catch (Exception $e) {
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }
}

class MultiTheme extends SystemMultiBase {
	protected static $model_class = 'Theme';
    public static $table_name = 'thm_themes';
    public static $table_primary_key = 'thm_theme_id';
    protected static $default_options = array();
    
    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['thm_is_active'])) {
            $filters['thm_is_active'] = [$this->options['thm_is_active'], PDO::PARAM_BOOL];
        }
        
        if (isset($this->options['thm_is_stock'])) {
            $filters['thm_is_stock'] = [$this->options['thm_is_stock'], PDO::PARAM_BOOL];
        }

        if (isset($this->options['thm_is_system'])) {
            $filters['thm_is_system'] = [$this->options['thm_is_system'], PDO::PARAM_BOOL];
        }

        if (isset($this->options['thm_status'])) {
            $filters['thm_status'] = [$this->options['thm_status'], PDO::PARAM_STR];
        }
        
        return $this->_get_resultsv2('thm_themes', $filters, $this->order_by, $only_count, $debug);
    }
}
?>