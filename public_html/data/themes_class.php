<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
PathHelper::requireOnce('includes/SystemClass.php');

class Theme extends SystemBase {
    public static $prefix = 'thm';
    public static $tablename = 'thm_themes';
    public static $pkey_column = 'thm_theme_id';
    
    public static $fields = array(
        'thm_theme_id' => 'Primary key - Theme ID',
        'thm_name' => 'Theme folder name (e.g. falcon, tailwind)',
        'thm_display_name' => 'Display name for admin interface', 
        'thm_description' => 'Theme description',
        'thm_version' => 'Theme version',
        'thm_author' => 'Theme author',
        'thm_is_active' => 'Is this the active theme?',
        'thm_is_stock' => 'Is this a stock theme (auto-updated)?',
        'thm_status' => 'Status: installed, active, inactive, error',
        'thm_metadata' => 'JSON metadata from theme.json',
        'thm_installed_time' => 'When theme was installed',
        'thm_create_time' => 'Record creation time',
        'thm_update_time' => 'Record update time'
    );
    
    public static $field_specifications = array(
        'thm_theme_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
        'thm_name' => array('type'=>'varchar(50)', 'is_nullable'=>false, 'unique'=>true),
        'thm_display_name' => array('type'=>'varchar(100)'),
        'thm_description' => array('type'=>'text'),
        'thm_version' => array('type'=>'varchar(20)'),
        'thm_author' => array('type'=>'varchar(100)'),
        'thm_is_active' => array('type'=>'bool'),
        'thm_is_stock' => array('type'=>'bool'),
        'thm_status' => array('type'=>'varchar(20)'),
        'thm_metadata' => array('type'=>'jsonb'),
        'thm_installed_time' => array('type'=>'timestamp(6)'),
        'thm_create_time' => array('type'=>'timestamp(6)'),
        'thm_update_time' => array('type'=>'timestamp(6)')
    );
    
    public static $json_vars = array('thm_metadata');
    public static $timestamp_fields = array('thm_create_time', 'thm_update_time', 'thm_installed_time');
    public static $required_fields = array('thm_name');
    public static $zero_variables = array();
    public static $field_constraints = array();
    public static $permanent_delete_actions = array();
    public static $initial_default_values = array(
        'thm_is_active' => false,
        'thm_is_stock' => true,
        'thm_status' => 'installed',
        'thm_installed_time' => 'now()',
        'thm_create_time' => 'now()',
        'thm_update_time' => 'now()'
    );
    
    /**
     * Get theme by name
     */
    public static function get_by_theme_name($theme_name) {
        return static::GetByColumn('thm_name', $theme_name);
    }
    
    /**
     * Activate this theme
     */
    public function activate() {
        // Deactivate all other themes
        $dbconnector = DbConnector::get_instance();
        $dblink = $dbconnector->get_db_link();
        
        $sql = "UPDATE thm_themes SET thm_is_active = false, thm_status = 'installed' WHERE thm_is_active = true";
        $q = $dblink->prepare($sql);
        $q->execute();
        
        // Activate this theme
        $this->set('thm_is_active', true);
        $this->set('thm_status', 'active');
        $this->save();
        
        // Update global theme setting
        PathHelper::requireOnce('data/settings_class.php');
        
        // Try to find existing theme_template setting
        $existing_setting = Setting::GetByColumn('stg_name', 'theme_template');
        
        if ($existing_setting) {
            // Update existing setting
            $existing_setting->set('stg_value', $this->get('thm_name'));
            $existing_setting->save();
        } else {
            // Create new setting
            $new_setting = new Setting(null);
            $new_setting->set('stg_name', 'theme_template');
            $new_setting->set('stg_value', $this->get('thm_name'));
            $new_setting->set('stg_group_name', 'theme');
            $new_setting->save();
        }
        
        return true;
    }
    
    /**
     * Check if theme directory exists
     */
    public function theme_files_exist() {
        $theme_name = $this->get('thm_name');
        $theme_path = PathHelper::getAbsolutePath("theme/$theme_name");
        return is_dir($theme_path);
    }
}

class MultiTheme extends SystemMultiBase {
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
        
        if (isset($this->options['thm_status'])) {
            $filters['thm_status'] = [$this->options['thm_status'], PDO::PARAM_STR];
        }
        
        return $this->_get_resultsv2('thm_themes', $filters, $this->order_by, $only_count, $debug);
    }
    
    function load($debug = false) {
        parent::load();
        $q = $this->getMultiResults(false, $debug);
        foreach($q->fetchAll() as $row) {
            $child = new Theme($row->thm_theme_id);
            $child->load_from_data($row, array_keys(Theme::$fields));
            $this->add($child);
        }
    }
    
    function count_all($debug = false) {
        $q = $this->getMultiResults(TRUE, $debug);
        return $q;
    }
}
?>