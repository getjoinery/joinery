# Theme and Plugin Management System Upgrade - Phase 2
**Version:** 2.0 - Admin Interface Implementation

## Overview

Phase 2 implements the admin interface components for theme and plugin management. This phase builds on Phase 1's deployment system and adds:

- Database models for theme/plugin registry
- Admin interface for theme management
- Admin interface for plugin management  
- Upload functionality for custom themes/plugins
- Stock/Custom status management

**Prerequisites:** Phase 1 must be completed (deploy script and database integration).

## Phase 2: Admin Interface Implementation

### 2.1 Database Models

#### Theme Model Enhancement

**File:** `/data/themes_class.php`

```php
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
        'thm_is_active' => array('type'=>'bool', 'default'=>false),
        'thm_is_stock' => array('type'=>'bool', 'default'=>true),
        'thm_status' => array('type'=>'varchar(20)', 'default'=>'installed'),
        'thm_metadata' => array('type'=>'jsonb'),
        'thm_installed_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'thm_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'thm_update_time' => array('type'=>'timestamp(6)', 'default'=>'now()')
    );
    
    public static $json_vars = array('thm_metadata');
    public static $timestamp_fields = array('thm_create_time', 'thm_update_time', 'thm_installed_time');
    public static $required_fields = array('thm_name');
    
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
        
        $sql = "UPDATE thm_themes SET thm_is_active = false";
        $q = $dblink->prepare($sql);
        $q->execute();
        
        // Activate this theme
        $this->set('thm_is_active', true);
        $this->set('thm_status', 'active');
        $this->save();
        
        // Update global theme setting
        $settings = Globalvars::get_instance();
        $settings->set_setting('theme_template', $this->get('thm_name'));
        
        return true;
    }
    
    /**
     * Load metadata from theme.json file
     */
    public function load_metadata() {
        $theme_name = $this->get('thm_name');
        $theme_path = PathHelper::getAbsolutePath("theme/$theme_name");
        $manifest_path = "$theme_path/theme.json";
        
        if (file_exists($manifest_path)) {
            $metadata = json_decode(file_get_contents($manifest_path), true);
            if ($metadata) {
                $this->set('thm_metadata', $metadata);
                $this->set('thm_display_name', $metadata['name'] ?? $theme_name);
                $this->set('thm_description', $metadata['description'] ?? '');
                $this->set('thm_version', $metadata['version'] ?? '1.0.0');
                $this->set('thm_author', $metadata['author'] ?? 'Unknown');
                $this->set('thm_is_stock', $metadata['is_stock'] ?? true);
            }
        }
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
```

#### Plugin Model Enhancement

**File:** `/data/plugins_class.php`

```php
<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
PathHelper::requireOnce('includes/SystemClass.php');

class Plugin extends SystemBase {
    public static $prefix = 'plg';
    public static $tablename = 'plg_plugins';
    public static $pkey_column = 'plg_plugin_id';
    
    public static $fields = array(
        'plg_plugin_id' => 'Primary key - Plugin ID',
        'plg_name' => 'Plugin folder name',
        'plg_display_name' => 'Display name for admin interface',
        'plg_description' => 'Plugin description', 
        'plg_version' => 'Plugin version',
        'plg_author' => 'Plugin author',
        'plg_is_active' => 'Is plugin active?',
        'plg_is_stock' => 'Is this a stock plugin (auto-updated)?',
        'plg_status' => 'Status: installed, active, inactive, error',
        'plg_metadata' => 'JSON metadata from plugin.json',
        'plg_installed_time' => 'When plugin was installed',
        'plg_create_time' => 'Record creation time',
        'plg_update_time' => 'Record update time'
    );
    
    public static $field_specifications = array(
        'plg_plugin_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
        'plg_name' => array('type'=>'varchar(50)', 'is_nullable'=>false, 'unique'=>true),
        'plg_display_name' => array('type'=>'varchar(100)'),
        'plg_description' => array('type'=>'text'),
        'plg_version' => array('type'=>'varchar(20)'),
        'plg_author' => array('type'=>'varchar(100)'),
        'plg_is_active' => array('type'=>'bool', 'default'=>false),
        'plg_is_stock' => array('type'=>'bool', 'default'=>true),
        'plg_status' => array('type'=>'varchar(20)', 'default'=>'installed'),
        'plg_metadata' => array('type'=>'jsonb'),
        'plg_installed_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'plg_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'plg_update_time' => array('type'=>'timestamp(6)', 'default'=>'now()')
    );
    
    public static $json_vars = array('plg_metadata');
    public static $timestamp_fields = array('plg_create_time', 'plg_update_time', 'plg_installed_time');
    public static $required_fields = array('plg_name');
    
    /**
     * Get plugin by name  
     */
    public static function get_by_plugin_name($plugin_name) {
        return static::GetByColumn('plg_name', $plugin_name);
    }
    
    /**
     * Activate plugin
     */
    public function activate() {
        $this->set('plg_is_active', true);
        $this->set('plg_status', 'active');
        $this->save();
        return true;
    }
    
    /**
     * Deactivate plugin
     */  
    public function deactivate() {
        $this->set('plg_is_active', false);
        $this->set('plg_status', 'inactive');
        $this->save();
        return true;
    }
    
    /**
     * Load metadata from plugin.json file
     */
    public function load_metadata() {
        $plugin_name = $this->get('plg_name');
        $plugin_path = PathHelper::getAbsolutePath("plugins/$plugin_name");
        $manifest_path = "$plugin_path/plugin.json";
        
        if (file_exists($manifest_path)) {
            $metadata = json_decode(file_get_contents($manifest_path), true);
            if ($metadata) {
                $this->set('plg_metadata', $metadata);
                $this->set('plg_display_name', $metadata['name'] ?? $plugin_name);
                $this->set('plg_description', $metadata['description'] ?? '');
                $this->set('plg_version', $metadata['version'] ?? '1.0.0');
                $this->set('plg_author', $metadata['author'] ?? 'Unknown');
                $this->set('plg_is_stock', $metadata['is_stock'] ?? true);
            }
        }
    }
    
    /**
     * Check if plugin directory exists
     */
    public function plugin_files_exist() {
        $plugin_name = $this->get('plg_name');
        $plugin_path = PathHelper::getAbsolutePath("plugins/$plugin_name");
        return is_dir($plugin_path);
    }
}

class MultiPlugin extends SystemMultiBase {
    public static $table_name = 'plg_plugins';
    public static $table_primary_key = 'plg_plugin_id';
    protected static $default_options = array();
    
    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['plg_is_active'])) {
            $filters['plg_is_active'] = [$this->options['plg_is_active'], PDO::PARAM_BOOL];
        }
        
        if (isset($this->options['plg_is_stock'])) {
            $filters['plg_is_stock'] = [$this->options['plg_is_stock'], PDO::PARAM_BOOL];
        }
        
        if (isset($this->options['plg_status'])) {
            $filters['plg_status'] = [$this->options['plg_status'], PDO::PARAM_STR];
        }
        
        return $this->_get_resultsv2('plg_plugins', $filters, $this->order_by, $only_count, $debug);
    }
    
    function load($debug = false) {
        parent::load();
        $q = $this->getMultiResults(false, $debug);
        foreach($q->fetchAll() as $row) {
            $child = new Plugin($row->plg_plugin_id);
            $child->load_from_data($row, array_keys(Plugin::$fields));
            $this->add($child);
        }
    }
    
    function count_all($debug = false) {
        $q = $this->getMultiResults(TRUE, $debug);
        return $q;
    }
}
?>
```

### 2.2 Theme Management Interface

**File:** `/adm/admin_themes.php`

```php
<?php
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/FormWriterMaster.php');
PathHelper::requireOnce('includes/SessionControl.php');
PathHelper::requireOnce('data/themes_class.php');
PathHelper::requireOnce('includes/ThemeManager.php');

$session = SessionControl::get_instance();

// Verify permissions (system admin only)
if ($session->check_permission(10) === false) {
    throw new SystemException("User does not have sufficient permissions", 403);
}

$theme_manager = new ThemeManager();
$message = '';
$error = '';

// Handle form submissions
if ($_POST) {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'activate':
                    $theme_name = $_POST['theme_name'];
                    $theme = Theme::get_by_theme_name($theme_name);
                    if ($theme) {
                        $theme->activate();
                        $message = "Theme '$theme_name' activated successfully.";
                    } else {
                        $error = "Theme not found.";
                    }
                    break;
                    
                case 'mark_stock':
                    $theme_name = $_POST['theme_name'];
                    $theme = Theme::get_by_theme_name($theme_name);
                    if ($theme) {
                        $theme->set('thm_is_stock', true);
                        $theme->save();
                        $message = "Theme '$theme_name' marked as stock.";
                    }
                    break;
                    
                case 'mark_custom':
                    $theme_name = $_POST['theme_name'];
                    $theme = Theme::get_by_theme_name($theme_name);
                    if ($theme) {
                        $theme->set('thm_is_stock', false);
                        $theme->save();
                        $message = "Theme '$theme_name' marked as custom.";
                    }
                    break;
                    
                case 'sync':
                    $synced = $theme_manager->sync();
                    $message = "Synced " . count($synced) . " themes from filesystem.";
                    break;
                    
                case 'upload':
                    if (isset($_FILES['theme_zip']) && $_FILES['theme_zip']['error'] === UPLOAD_ERR_OK) {
                        $theme_name = $theme_manager->installTheme($_FILES['theme_zip']['tmp_name']);
                        $message = "Theme '$theme_name' installed successfully.";
                    } else {
                        $error = "Upload failed. Please check the file and try again.";
                    }
                    break;
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Load current themes
$themes = new MultiTheme(array(), array('thm_name' => 'ASC'));
$themes->load();

$page = new AdminPage();
$page->admin_header("Theme Management", false, "", false, false);
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <h1>Theme Management</h1>
            
            <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <!-- Upload Theme Form -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3>Upload New Theme</h3>
                </div>
                <div class="card-body">
                    <form method="post" enctype="multipart/form-data" class="row g-3">
                        <div class="col-md-8">
                            <input type="file" name="theme_zip" class="form-control" accept=".zip" required>
                            <div class="form-text">
                                Upload a ZIP file containing theme files with theme.json manifest.
                            </div>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" name="action" value="upload" class="btn btn-primary">
                                Upload Theme
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Sync Button -->
            <div class="mb-3">
                <form method="post" style="display: inline;">
                    <button type="submit" name="action" value="sync" class="btn btn-info">
                        Sync with Filesystem
                    </button>
                </form>
                <small class="text-muted ms-2">
                    Scan theme directory and update database registry
                </small>
            </div>
            
            <!-- Themes Table -->
            <div class="card">
                <div class="card-header">
                    <h3>Installed Themes (<?= $themes->count() ?>)</h3>
                </div>
                <div class="card-body">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Theme</th>
                                <th>Version</th>
                                <th>Author</th>
                                <th>Status</th>
                                <th>Type</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            foreach ($themes as $theme) {
                                $theme_name = $theme->get('thm_name');
                                $display_name = $theme->get('thm_display_name') ?: $theme_name;
                                $description = $theme->get('thm_description');
                                $version = $theme->get('thm_version') ?: '1.0.0';
                                $author = $theme->get('thm_author') ?: 'Unknown';
                                $is_active = $theme->get('thm_is_active');
                                $is_stock = $theme->get('thm_is_stock');
                                $files_exist = $theme->theme_files_exist();
                                
                                // Get status badge
                                if (!$files_exist) {
                                    $status_badge = '<span class="badge bg-danger">Missing Files</span>';
                                } elseif ($is_active) {
                                    $status_badge = '<span class="badge bg-success">Active</span>';
                                } else {
                                    $status_badge = '<span class="badge bg-secondary">Inactive</span>';
                                }
                                
                                // Get type badge
                                $type_badge = $is_stock ? 
                                    '<span class="badge bg-info">Stock</span>' : 
                                    '<span class="badge bg-warning">Custom</span>';
                                
                                echo '<tr>';
                                echo '<td>';
                                echo '<strong>' . htmlspecialchars($display_name) . '</strong>';
                                if ($description) {
                                    echo '<br><small class="text-muted">' . htmlspecialchars($description) . '</small>';
                                }
                                echo '</td>';
                                echo '<td>' . htmlspecialchars($version) . '</td>';
                                echo '<td>' . htmlspecialchars($author) . '</td>';
                                echo '<td>' . $status_badge . '</td>';
                                echo '<td>' . $type_badge . '</td>';
                                echo '<td>';
                                
                                echo '<form method="post" style="display: inline;">';
                                echo '<input type="hidden" name="theme_name" value="' . htmlspecialchars($theme_name) . '">';
                                
                                if (!$is_active && $files_exist) {
                                    echo '<button type="submit" name="action" value="activate" class="btn btn-sm btn-success me-1">Activate</button>';
                                }
                                
                                if ($is_stock) {
                                    echo '<button type="submit" name="action" value="mark_custom" class="btn btn-sm btn-outline-warning me-1" title="Mark as Custom">→ Custom</button>';
                                } else {
                                    echo '<button type="submit" name="action" value="mark_stock" class="btn btn-sm btn-outline-info" title="Mark as Stock">→ Stock</button>';
                                }
                                
                                echo '</form>';
                                echo '</td>';
                                echo '</tr>';
                            }
                            
                            if ($themes->count() === 0) {
                                echo '<tr><td colspan="6" class="text-center">No themes installed</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <div class="mt-3">
        <p class="text-muted">
            <strong>Note:</strong> Stock themes are automatically updated during deployments. 
            Custom themes are preserved during deployments.
        </p>
    </div>
</div>

<?php
$page->admin_footer();
?>
```

### 2.3 Plugin Management Interface

**File:** `/adm/admin_plugins.php`

```php
<?php
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/FormWriterMaster.php');
PathHelper::requireOnce('includes/SessionControl.php');
PathHelper::requireOnce('data/plugins_class.php');
PathHelper::requireOnce('includes/PluginManager.php');

$session = SessionControl::get_instance();

// Verify permissions (system admin only)
if ($session->check_permission(10) === false) {
    throw new SystemException("User does not have sufficient permissions", 403);
}

$plugin_manager = new PluginManager();
$message = '';
$error = '';

// Handle form submissions
if ($_POST) {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'activate':
                    $plugin_name = $_POST['plugin_name'];
                    $plugin = Plugin::get_by_plugin_name($plugin_name);
                    if ($plugin) {
                        $plugin->activate();
                        $message = "Plugin '$plugin_name' activated successfully.";
                    } else {
                        $error = "Plugin not found.";
                    }
                    break;
                    
                case 'deactivate':
                    $plugin_name = $_POST['plugin_name'];
                    $plugin = Plugin::get_by_plugin_name($plugin_name);
                    if ($plugin) {
                        $plugin->deactivate();
                        $message = "Plugin '$plugin_name' deactivated successfully.";
                    }
                    break;
                    
                case 'mark_stock':
                    $plugin_name = $_POST['plugin_name'];
                    $plugin = Plugin::get_by_plugin_name($plugin_name);
                    if ($plugin) {
                        $plugin->set('plg_is_stock', true);
                        $plugin->save();
                        $message = "Plugin '$plugin_name' marked as stock.";
                    }
                    break;
                    
                case 'mark_custom':
                    $plugin_name = $_POST['plugin_name'];
                    $plugin = Plugin::get_by_plugin_name($plugin_name);
                    if ($plugin) {
                        $plugin->set('plg_is_stock', false);
                        $plugin->save();
                        $message = "Plugin '$plugin_name' marked as custom.";
                    }
                    break;
                    
                case 'sync':
                    $synced = $plugin_manager->sync();
                    $message = "Synced " . count($synced) . " plugins from filesystem.";
                    break;
                    
                case 'upload':
                    if (isset($_FILES['plugin_zip']) && $_FILES['plugin_zip']['error'] === UPLOAD_ERR_OK) {
                        $plugin_name = $plugin_manager->installPlugin($_FILES['plugin_zip']['tmp_name']);
                        $message = "Plugin '$plugin_name' installed successfully.";
                    } else {
                        $error = "Upload failed. Please check the file and try again.";
                    }
                    break;
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Load current plugins
$plugins = new MultiPlugin(array(), array('plg_name' => 'ASC'));
$plugins->load();

$page = new AdminPage();
$page->admin_header("Plugin Management", false, "", false, false);
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <h1>Plugin Management</h1>
            
            <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <!-- Upload Plugin Form -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3>Upload New Plugin</h3>
                </div>
                <div class="card-body">
                    <form method="post" enctype="multipart/form-data" class="row g-3">
                        <div class="col-md-8">
                            <input type="file" name="plugin_zip" class="form-control" accept=".zip" required>
                            <div class="form-text">
                                Upload a ZIP file containing plugin files with plugin.json manifest.
                            </div>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" name="action" value="upload" class="btn btn-primary">
                                Upload Plugin
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Sync Button -->
            <div class="mb-3">
                <form method="post" style="display: inline;">
                    <button type="submit" name="action" value="sync" class="btn btn-info">
                        Sync with Filesystem
                    </button>
                </form>
                <small class="text-muted ms-2">
                    Scan plugins directory and update database registry
                </small>
            </div>
            
            <!-- Plugins Table -->
            <div class="card">
                <div class="card-header">
                    <h3>Installed Plugins (<?= $plugins->count() ?>)</h3>
                </div>
                <div class="card-body">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Plugin</th>
                                <th>Version</th>
                                <th>Author</th>
                                <th>Status</th>
                                <th>Type</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            foreach ($plugins as $plugin) {
                                $plugin_name = $plugin->get('plg_name');
                                $display_name = $plugin->get('plg_display_name') ?: $plugin_name;
                                $description = $plugin->get('plg_description');
                                $version = $plugin->get('plg_version') ?: '1.0.0';
                                $author = $plugin->get('plg_author') ?: 'Unknown';
                                $is_active = $plugin->get('plg_is_active');
                                $is_stock = $plugin->get('plg_is_stock');
                                $files_exist = $plugin->plugin_files_exist();
                                
                                // Get status badge
                                if (!$files_exist) {
                                    $status_badge = '<span class="badge bg-danger">Missing Files</span>';
                                } elseif ($is_active) {
                                    $status_badge = '<span class="badge bg-success">Active</span>';
                                } else {
                                    $status_badge = '<span class="badge bg-secondary">Inactive</span>';
                                }
                                
                                // Get type badge
                                $type_badge = $is_stock ? 
                                    '<span class="badge bg-info">Stock</span>' : 
                                    '<span class="badge bg-warning">Custom</span>';
                                
                                echo '<tr>';
                                echo '<td>';
                                echo '<strong>' . htmlspecialchars($display_name) . '</strong>';
                                if ($description) {
                                    echo '<br><small class="text-muted">' . htmlspecialchars($description) . '</small>';
                                }
                                echo '</td>';
                                echo '<td>' . htmlspecialchars($version) . '</td>';
                                echo '<td>' . htmlspecialchars($author) . '</td>';
                                echo '<td>' . $status_badge . '</td>';
                                echo '<td>' . $type_badge . '</td>';
                                echo '<td>';
                                
                                echo '<form method="post" style="display: inline;">';
                                echo '<input type="hidden" name="plugin_name" value="' . htmlspecialchars($plugin_name) . '">';
                                
                                if (!$is_active && $files_exist) {
                                    echo '<button type="submit" name="action" value="activate" class="btn btn-sm btn-success me-1">Activate</button>';
                                }
                                
                                if ($is_active && $files_exist) {
                                    echo '<button type="submit" name="action" value="deactivate" class="btn btn-sm btn-warning me-1">Deactivate</button>';
                                }
                                
                                if ($is_stock) {
                                    echo '<button type="submit" name="action" value="mark_custom" class="btn btn-sm btn-outline-warning me-1" title="Mark as Custom">→ Custom</button>';
                                } else {
                                    echo '<button type="submit" name="action" value="mark_stock" class="btn btn-sm btn-outline-info" title="Mark as Stock">→ Stock</button>';
                                }
                                
                                echo '</form>';
                                echo '</td>';
                                echo '</tr>';
                            }
                            
                            if ($plugins->count() === 0) {
                                echo '<tr><td colspan="6" class="text-center">No plugins installed</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <div class="mt-3">
        <p class="text-muted">
            <strong>Note:</strong> Stock plugins are automatically updated during deployments. 
            Custom plugins are preserved during deployments.
        </p>
    </div>
</div>

<?php
$page->admin_footer();
?>
```

### 2.4 Manager Helper Classes

#### Theme Manager

**File:** `/includes/ThemeManager.php`

```php
<?php
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('data/themes_class.php');

class ThemeManager {
    
    /**
     * Sync filesystem themes with database registry
     */
    public function sync() {
        $theme_dir = PathHelper::getAbsolutePath('theme');
        if (!is_dir($theme_dir)) {
            mkdir($theme_dir, 0775, true);
            return array();
        }
        
        $synced = array();
        $dirs = scandir($theme_dir);
        
        foreach ($dirs as $dir) {
            if ($dir == '.' || $dir == '..') continue;
            
            $path = "$theme_dir/$dir";
            if (!is_dir($path)) continue;
            
            // Check if exists in database
            $existing = Theme::get_by_theme_name($dir);
            
            if (!$existing) {
                // New theme, add to database
                $theme = new Theme(null);
                $theme->set('thm_name', $dir);
                $theme->set('thm_status', 'installed');
                $theme->set('thm_installed_time', 'now()');
                
                // Load metadata from theme.json
                $theme->load_metadata();
                $theme->save();
                
                $synced[] = $dir;
            }
        }
        
        return $synced;
    }
    
    /**
     * Get active theme
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
     * Set active theme
     */
    public function setActiveTheme($theme_name) {
        $theme = Theme::get_by_theme_name($theme_name);
        
        if (!$theme) {
            throw new Exception("Theme not found: $theme_name");
        }
        
        return $theme->activate();
    }
    
    /**
     * Install theme from uploaded ZIP
     */
    public function installTheme($zip_path) {
        // Create temp directory
        $temp_dir = sys_get_temp_dir() . '/theme_' . uniqid();
        mkdir($temp_dir);
        
        try {
            // Extract ZIP
            $zip = new ZipArchive();
            if ($zip->open($zip_path) !== TRUE) {
                throw new Exception("Failed to open ZIP file");
            }
            
            $zip->extractTo($temp_dir);
            $zip->close();
            
            // Find theme.json and determine theme name
            $theme_name = null;
            $theme_root = null;
            $manifest_path = null;
            
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
            if (!$manifest) {
                throw new Exception("Invalid theme.json");
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
            
            // Validate theme name
            if (!$this->validateName($theme_name)) {
                throw new Exception("Invalid theme name: $theme_name");
            }
            
            // Check if theme already exists
            $target_path = PathHelper::getAbsolutePath("theme/$theme_name");
            if (is_dir($target_path)) {
                // Backup existing theme
                $backup_path = $target_path . '_backup_' . date('YmdHis');
                rename($target_path, $backup_path);
            }
            
            // Move theme to final location
            if (!rename($theme_root, $target_path)) {
                throw new Exception("Failed to install theme files");
            }
            
            // Set permissions
            $this->setPermissions($target_path);
            
            // Update database registry
            $this->sync();
            
            // Clean up
            $this->cleanup($temp_dir);
            
            return $theme_name;
            
        } catch (Exception $e) {
            $this->cleanup($temp_dir);
            throw $e;
        }
    }
    
    /**
     * Validate theme name
     */
    private function validateName($name) {
        if (empty($name)) return false;
        
        // Length check
        if (strlen($name) > 50 || strlen($name) < 3) {
            return false;
        }
        
        // Must start with letter, then alphanumeric, underscore, dash
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $name)) {
            return false;
        }
        
        // Reserved names check
        $reserved_names = array(
            'admin', 'api', 'includes', 'data', 'ajax', 'assets',
            'utils', 'adm', 'logic', 'views', 'migrations', 'specs',
            'plugins', 'plugin'
        );
        if (in_array(strtolower($name), $reserved_names)) {
            return false;
        }
        
        // Path traversal check
        if (strpos($name, '..') !== false || strpos($name, '/') !== false 
            || strpos($name, '\\') !== false) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Set proper permissions
     */
    private function setPermissions($dir) {
        @chown($dir, 'www-data');
        @chgrp($dir, 'user1');
        @chmod($dir, 0775);
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $path) {
            @chown($path, 'www-data');
            @chgrp($path, 'user1');
            if ($path->isDir()) {
                @chmod($path, 0775);
            } else {
                @chmod($path, 0664);
            }
        }
    }
    
    /**
     * Clean up temporary directory
     */
    private function cleanup($dir) {
        if (!is_dir($dir)) return;
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $path) {
            $path->isDir() ? rmdir($path) : unlink($path);
        }
        
        rmdir($dir);
    }
}
?>
```

#### Plugin Manager

**File:** `/includes/PluginManager.php`

```php
<?php
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('data/plugins_class.php');

class PluginManager {
    
    /**
     * Sync filesystem plugins with database registry
     */
    public function sync() {
        $plugin_dir = PathHelper::getAbsolutePath('plugins');
        if (!is_dir($plugin_dir)) {
            mkdir($plugin_dir, 0775, true);
            return array();
        }
        
        $synced = array();
        $dirs = scandir($plugin_dir);
        
        foreach ($dirs as $dir) {
            if ($dir == '.' || $dir == '..') continue;
            
            $path = "$plugin_dir/$dir";
            if (!is_dir($path)) continue;
            
            // Check if exists in database
            $existing = Plugin::get_by_plugin_name($dir);
            
            if (!$existing) {
                // New plugin, add to database
                $plugin = new Plugin(null);
                $plugin->set('plg_name', $dir);
                $plugin->set('plg_status', 'installed');
                $plugin->set('plg_installed_time', 'now()');
                
                // Load metadata from plugin.json
                $plugin->load_metadata();
                $plugin->save();
                
                $synced[] = $dir;
            }
        }
        
        return $synced;
    }
    
    /**
     * Install plugin from uploaded ZIP
     */
    public function installPlugin($zip_path) {
        // Create temp directory
        $temp_dir = sys_get_temp_dir() . '/plugin_' . uniqid();
        mkdir($temp_dir);
        
        try {
            // Extract ZIP
            $zip = new ZipArchive();
            if ($zip->open($zip_path) !== TRUE) {
                throw new Exception("Failed to open ZIP file");
            }
            
            $zip->extractTo($temp_dir);
            $zip->close();
            
            // Find plugin.json and determine plugin name
            $plugin_name = null;
            $plugin_root = null;
            $manifest_path = null;
            
            if (file_exists("$temp_dir/plugin.json")) {
                $manifest_path = "$temp_dir/plugin.json";
                $plugin_root = $temp_dir;
            } else {
                // Look in first subdirectory
                $dirs = scandir($temp_dir);
                foreach ($dirs as $dir) {
                    if ($dir == '.' || $dir == '..') continue;
                    if (is_dir("$temp_dir/$dir") && file_exists("$temp_dir/$dir/plugin.json")) {
                        $manifest_path = "$temp_dir/$dir/plugin.json";
                        $plugin_root = "$temp_dir/$dir";
                        $plugin_name = $dir;
                        break;
                    }
                }
            }
            
            if (!$manifest_path) {
                throw new Exception("No plugin.json found in uploaded file");
            }
            
            $manifest = json_decode(file_get_contents($manifest_path), true);
            if (!$manifest) {
                throw new Exception("Invalid plugin.json");
            }
            
            // Determine plugin name from manifest if not from directory
            if (!$plugin_name && isset($manifest['name'])) {
                $plugin_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($manifest['name']));
                if (!preg_match('/^[a-zA-Z]/', $plugin_name)) {
                    $plugin_name = 'plugin_' . $plugin_name;
                }
            }
            
            if (!$plugin_name) {
                throw new Exception("Could not determine plugin name");
            }
            
            // Validate plugin name
            if (!$this->validateName($plugin_name)) {
                throw new Exception("Invalid plugin name: $plugin_name");
            }
            
            // Check if plugin already exists
            $target_path = PathHelper::getAbsolutePath("plugins/$plugin_name");
            if (is_dir($target_path)) {
                throw new Exception("Plugin already exists. Please uninstall first.");
            }
            
            // Move plugin to final location
            if (!rename($plugin_root, $target_path)) {
                throw new Exception("Failed to install plugin files");
            }
            
            // Set permissions
            $this->setPermissions($target_path);
            
            // Update database registry
            $this->sync();
            
            // Clean up
            $this->cleanup($temp_dir);
            
            return $plugin_name;
            
        } catch (Exception $e) {
            $this->cleanup($temp_dir);
            throw $e;
        }
    }
    
    /**
     * Validate plugin name
     */
    private function validateName($name) {
        if (empty($name)) return false;
        
        // Length check
        if (strlen($name) > 50 || strlen($name) < 3) {
            return false;
        }
        
        // Must start with letter, then alphanumeric, underscore, dash
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $name)) {
            return false;
        }
        
        // Reserved names check
        $reserved_names = array(
            'admin', 'api', 'includes', 'data', 'ajax', 'assets',
            'utils', 'adm', 'logic', 'views', 'migrations', 'specs',
            'theme', 'themes', 'core', 'system'
        );
        if (in_array(strtolower($name), $reserved_names)) {
            return false;
        }
        
        // Path traversal check
        if (strpos($name, '..') !== false || strpos($name, '/') !== false 
            || strpos($name, '\\') !== false) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Set proper permissions
     */
    private function setPermissions($dir) {
        @chown($dir, 'www-data');
        @chgrp($dir, 'user1');
        @chmod($dir, 0775);
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $path) {
            @chown($path, 'www-data');
            @chgrp($path, 'user1');
            if ($path->isDir()) {
                @chmod($path, 0775);
            } else {
                @chmod($path, 0664);
            }
        }
    }
    
    /**
     * Clean up temporary directory
     */
    private function cleanup($dir) {
        if (!is_dir($dir)) return;
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $path) {
            $path->isDir() ? rmdir($path) : unlink($path);
        }
        
        rmdir($dir);
    }
}
?>
```

### 2.5 Database Migration

**File:** `/migrations/theme_plugin_registry_sync.php`

```php
<?php
function theme_plugin_registry_sync() {
    PathHelper::requireOnce('data/themes_class.php');
    PathHelper::requireOnce('data/plugins_class.php');
    PathHelper::requireOnce('includes/ThemeManager.php');
    PathHelper::requireOnce('includes/PluginManager.php');
    
    echo "Syncing themes with database registry...\n";
    $theme_manager = new ThemeManager();
    $synced_themes = $theme_manager->sync();
    echo "Synced " . count($synced_themes) . " themes.\n";
    
    echo "Syncing plugins with database registry...\n";
    $plugin_manager = new PluginManager();
    $synced_plugins = $plugin_manager->sync();
    echo "Synced " . count($synced_plugins) . " plugins.\n";
    
    // Set current theme as active in database
    PathHelper::requireOnce('includes/Globalvars.php');
    $settings = Globalvars::get_instance();
    $current_theme = $settings->get_setting('theme_template');
    
    if ($current_theme) {
        echo "Setting current theme '$current_theme' as active...\n";
        
        $theme = Theme::get_by_theme_name($current_theme);
        if ($theme) {
            $theme->activate();
            echo "Theme '$current_theme' activated.\n";
        } else {
            echo "Warning: Current theme '$current_theme' not found in registry.\n";
        }
    }
    
    return true;
}
?>
```

**Migration entry for migrations.php:**
```php
// Add this to migrations.php:
$migration = array();
$migration['database_version'] = '2.XX';  // Use appropriate version
$migration['test'] = "SELECT count(1) as count FROM information_schema.tables WHERE table_name = 'thm_themes'";
$migration['migration_file'] = 'theme_plugin_registry_sync.php';
$migration['migration_sql'] = NULL;
$migrations[] = $migration;
```

### 2.6 Admin Navigation Integration

**Update navigation (add to appropriate admin navigation file):**
```php
// Add to admin navigation array
if ($session->check_permission(10)) {  // System admin only
    $nav_items[] = array(
        'url' => '/adm/admin_themes.php',
        'title' => 'Themes',
        'icon' => 'bi-palette',  // Bootstrap icon
        'section' => 'system'
    );
    
    $nav_items[] = array(
        'url' => '/adm/admin_plugins.php', 
        'title' => 'Plugins',
        'icon' => 'bi-puzzle',  // Bootstrap icon
        'section' => 'system'
    );
}
```

## Phase 2 Implementation Checklist

### ✅ Required Files to Create:
- [ ] `/data/themes_class.php` - Theme database model
- [ ] `/data/plugins_class.php` - Plugin database model  
- [ ] `/includes/ThemeManager.php` - Theme management helper
- [ ] `/includes/PluginManager.php` - Plugin management helper
- [ ] `/adm/admin_themes.php` - Theme admin interface
- [ ] `/adm/admin_plugins.php` - Plugin admin interface
- [ ] `/migrations/theme_plugin_registry_sync.php` - Database sync migration

### ✅ Features to Implement:
- [ ] Theme registry database integration
- [ ] Plugin registry database integration  
- [ ] Upload functionality for custom themes
- [ ] Upload functionality for custom plugins
- [ ] Stock/Custom status management
- [ ] Theme activation/deactivation
- [ ] Plugin activation/deactivation
- [ ] Filesystem sync functionality
- [ ] Admin navigation integration

### ✅ Testing Requirements:
- [ ] Upload and install custom theme via admin
- [ ] Upload and install custom plugin via admin
- [ ] Mark themes as stock/custom and verify deployment behavior
- [ ] Mark plugins as stock/custom and verify deployment behavior
- [ ] Activate/deactivate themes and verify settings update
- [ ] Activate/deactivate plugins and verify functionality
- [ ] Sync filesystem with database after manual file changes
- [ ] Verify database schema creation and migrations

## Dependencies

**Phase 1 Prerequisites:**
- ✅ Deploy script with automatic rollback (Phase 1 completed)
- ✅ Database migration system working
- ✅ Theme/plugin deployment from external repository

**System Requirements:**
- PHP with ZipArchive extension
- File upload permissions configured
- Database models working with SystemBase/SystemMultiBase
- Admin interface framework available

## Notes

**Stock/Custom Logic:**
- Items with `"is_stock": true` in manifests are overwritten on deploy
- Items with `"is_stock": false` are preserved during deployments
- Admin interface allows toggling stock/custom status
- Database registry tracks all installed themes/plugins

**Security Considerations:**
- File uploads restricted to system admins (permission level 10)
- ZIP extraction validates manifest files
- Name validation prevents path traversal attacks
- File permissions set appropriately after installation

**Integration Points:**
- Theme activation updates `theme_template` setting
- Plugin system hooks into existing admin navigation
- Filesystem sync keeps database in sync with file system
- Migration ensures existing themes/plugins are registered