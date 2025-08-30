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

**Note:** The system already has a comprehensive plugin system with `plugins_class.php`, `plugin_dependencies_class.php`, `plugin_versions_class.php`, and `plugin_migrations_class.php`. We only need to add stock/custom functionality.

**File:** `/data/plugins_class.php` - Add these fields to existing class:

```php
// Add these fields to existing Plugin::$fields array:
'plg_is_stock' => 'Is this a stock plugin (auto-updated)?',
'plg_create_time' => 'Record creation time',
'plg_update_time' => 'Record update time'

// Add these to existing Plugin::$field_specifications array:
'plg_is_stock' => array('type'=>'bool'),
'plg_create_time' => array('type'=>'timestamp(6)'),
'plg_update_time' => array('type'=>'timestamp(6)')

// Add these to existing Plugin::$timestamp_fields array:
// (if timestamp_fields doesn't exist, create it)
public static $timestamp_fields = array('plg_create_time', 'plg_update_time', 
    'plg_installed_time', 'plg_activated_time', 'plg_last_activated_time', 
    'plg_last_deactivated_time', 'plg_uninstalled_time');

// Add these to existing Plugin::$initial_default_values array:
// (if initial_default_values is empty, replace with:)
public static $initial_default_values = array(
    'plg_is_stock' => true,
    'plg_create_time' => 'now()',
    'plg_update_time' => 'now()'
);

// Add these methods to existing Plugin class:

/**
 * Check if plugin is stock (auto-updated)
 * @return bool True if stock plugin
 */
public function is_stock() {
    return (bool)$this->get('plg_is_stock');
}

/**
 * Mark plugin as stock
 */
public function mark_as_stock() {
    $this->set('plg_is_stock', true);
    $this->save();
}

/**
 * Mark plugin as custom
 */
public function mark_as_custom() {
    $this->set('plg_is_stock', false);
    $this->save();
}

/**
 * Load stock status from plugin.json metadata
 */
public function load_stock_status() {
    $metadata = $this->get_plugin_metadata();
    if ($metadata && isset($metadata['is_stock'])) {
        $this->set('plg_is_stock', $metadata['is_stock']);
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

### 2.3 Plugin Management Interface Enhancement

**Note:** The system already has a comprehensive `/adm/admin_plugins.php` page. We only need to add stock/custom functionality and upload capability.

**File:** `/adm/admin_plugins.php` - Add these enhancements to existing page:

#### Add Upload Section (after existing system health alerts)

```php
// Add after line ~215 where existing alerts are shown:

<!-- Upload Plugin Form -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-upload"></i> Upload New Plugin</h5>
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
                <input type="hidden" name="action" value="upload">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-upload"></i> Upload Plugin
                </button>
            </div>
        </form>
        
        <!-- Sync Button -->
        <div class="mt-3">
            <form method="post" style="display: inline;">
                <input type="hidden" name="action" value="sync">
                <button type="submit" class="btn btn-info">
                    <i class="fas fa-sync"></i> Sync with Filesystem
                </button>
            </form>
            <small class="text-muted ms-2">
                Scan plugins directory and update database registry
            </small>
        </div>
    </div>
</div>
```

#### Add Stock/Custom Actions (in the action handling section)

```php
// Add these cases to the existing switch statement around line 110:

case 'mark_stock':
    $plugin = Plugin::get_by_plugin_name($plugin_name);
    if ($plugin) {
        $plugin->mark_as_stock();
        $message = "Plugin '$plugin_name' marked as stock.";
        $message_type = 'success';
    } else {
        $message = 'Plugin record not found.';
        $message_type = 'warning';
    }
    break;
    
case 'mark_custom':
    $plugin = Plugin::get_by_plugin_name($plugin_name);
    if ($plugin) {
        $plugin->mark_as_custom();
        $message = "Plugin '$plugin_name' marked as custom.";
        $message_type = 'success';
    } else {
        $message = 'Plugin record not found.';
        $message_type = 'warning';
    }
    break;
    
case 'sync':
    $plugin_manager = new PluginManager();
    $synced = $plugin_manager->syncWithFilesystem();
    $message = "Synced " . count($synced) . " plugins from filesystem.";
    $message_type = 'info';
    break;
    
case 'upload':
    if (isset($_FILES['plugin_zip']) && $_FILES['plugin_zip']['error'] === UPLOAD_ERR_OK) {
        $plugin_manager = new PluginManager();
        $plugin_name = $plugin_manager->installFromZip($_FILES['plugin_zip']['tmp_name']);
        $message = "Plugin '$plugin_name' uploaded and installed successfully.";
        $message_type = 'success';
    } else {
        $message = "Upload failed. Please check the file and try again.";
        $message_type = 'danger';
    }
    break;
```

#### Add Stock/Custom Column (modify the table structure)

```php
// Update the headers array around line 227:
$headers = array('Plugin', 'Description', 'Version', 'Status', 'Type', 'Actions');

// Add Type column in the foreach loop around line 276:
// After the version column, add:

// Type column (Stock/Custom)
$type_cell = '';
if ($plugin['plugin']) {
    $is_stock = $plugin['plugin']->is_stock();
    $type_cell = $is_stock ? 
        '<span class="badge bg-info">Stock</span>' : 
        '<span class="badge bg-warning">Custom</span>';
} else {
    $type_cell = '<span class="badge bg-info">Stock</span>'; // Default for uninstalled
}
array_push($rowvalues, $type_cell);

// Add Stock/Custom toggle buttons in the action section:
// After the existing action buttons, add:

// Stock/Custom toggle (only if plugin is in database)
if ($plugin['plugin']) {
    $is_stock = $plugin['plugin']->is_stock();
    
    if ($is_stock) {
        $action_cell .= ' ';
        $action_cell .= $formwriter->begin_form('plugin_mark_custom_' . $plugin['name'], 'POST', '', true);
        $action_cell .= $formwriter->hiddeninput('action', 'mark_custom');
        $action_cell .= $formwriter->hiddeninput('plugin_name', $plugin['name']);
        $action_cell .= $formwriter->new_form_button('→ Custom', 'btn btn-outline-warning btn-sm', '', '', 'Mark as Custom');
        $action_cell .= $formwriter->end_form(true);
    } else {
        $action_cell .= ' ';
        $action_cell .= $formwriter->begin_form('plugin_mark_stock_' . $plugin['name'], 'POST', '', true);
        $action_cell .= $formwriter->hiddeninput('action', 'mark_stock');
        $action_cell .= $formwriter->hiddeninput('plugin_name', $plugin['name']);
        $action_cell .= $formwriter->new_form_button('→ Stock', 'btn btn-outline-info btn-sm', '', '', 'Mark as Stock');
        $action_cell .= $formwriter->end_form(true);
    }
}
```

#### Update Plugin.json Documentation

```php
// Update the plugin.json example around line 500 to include is_stock:
<pre class="bg-light p-2 rounded"><code>{
    "name": "My Plugin",
    "description": "A sample plugin for demonstration",
    "version": "1.0.0",
    "author": "Plugin Developer",
    "is_stock": false,
    "requires": {
        "php": ">=8.0",
        "joinery": ">=1.0",
        "extensions": ["pdo", "json"]
    },
    "depends": {
        "core-plugin": ">=1.0"
    },
    "conflicts": ["old-plugin-name"]
}</code></pre>

// Add explanation of is_stock field:
<h6 class="mt-3">Stock vs Custom Plugins</h6>
<ul>
    <li><strong>Stock plugins</strong> (<code>"is_stock": true</code>) are automatically updated during deployments</li>
    <li><strong>Custom plugins</strong> (<code>"is_stock": false</code>) are preserved during deployments</li>
    <li>Default is <code>true</code> if not specified</li>
    <li>Can be changed via admin interface after installation</li>
</ul>
```

### 2.4 Manager Helper Classes

#### Theme Manager

**File:** `/includes/ThemeManager.php`

```php
<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('data/themes_class.php');

class ThemeManager {
    
    /**
     * Sync filesystem themes with database registry
     * @return array Array of newly synced theme names
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
     * @return Theme|null Active theme object or null
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
     * @param string $theme_name Theme folder name
     * @return bool Success status
     */
    public function setActiveTheme($theme_name) {
        $theme = Theme::get_by_theme_name($theme_name);
        
        if (!$theme) {
            throw new Exception("Theme not found: $theme_name");
        }
        
        // Deactivate all other themes first
        $all_themes = new MultiTheme();
        $all_themes->load();
        
        foreach ($all_themes as $other_theme) {
            if ($other_theme->get('thm_name') !== $theme_name && $other_theme->get('thm_is_active')) {
                $other_theme->set('thm_is_active', false);
                $other_theme->set('thm_status', 'installed');
                $other_theme->save();
            }
        }
        
        // Now activate the selected theme
        return $theme->activate();
    }
    
    /**
     * Install theme from uploaded ZIP file
     * @param string $zip_path Path to uploaded ZIP file
     * @return string Theme name that was installed
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
     * @param string $name Theme name to validate
     * @return bool True if valid
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
     * Set proper permissions on theme directory
     * @param string $dir Directory path
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
     * @param string $dir Directory to remove
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

#### Plugin Manager Enhancement

**Note:** The existing `/includes/PluginManager.php` already exists with comprehensive plugin management. We only need to add sync and upload methods.

**File:** `/includes/PluginManager.php` - Add these methods to existing class:

```php
// Add these methods to existing PluginManager class:

/**
 * Sync filesystem plugins with database registry  
 * @return array Array of newly synced plugin names
 */
public function syncWithFilesystem() {
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
        
        // Skip invalid plugin names
        if (!Plugin::is_valid_plugin_name($dir)) continue;
        
        // Check if exists in database
        $existing = Plugin::get_by_plugin_name($dir);
        
        if (!$existing) {
            // New plugin, add to database
            $plugin = new Plugin(null);
            $plugin->set('plg_name', $dir);
            $plugin->set('plg_status', 'inactive');
            $plugin->set('plg_installed_time', date('Y-m-d H:i:s'));
            
            // Load stock status and metadata from plugin.json
            $plugin->load_stock_status();
            
            // Store metadata
            $metadata = $plugin->get_plugin_metadata();
            if ($metadata) {
                $plugin->set('plg_metadata', json_encode($metadata));
            }
            
            $plugin->save();
            $synced[] = $dir;
        } else {
            // Existing plugin - update stock status from filesystem
            $existing->load_stock_status();
            $existing->save();
        }
    }
    
    return $synced;
}

/**
 * Install plugin from uploaded ZIP file
 * @param string $zip_path Path to uploaded ZIP file
 * @return string Plugin name that was installed
 */
public function installFromZip($zip_path) {
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
        
        // Use existing Plugin validation
        if (!Plugin::is_valid_plugin_name($plugin_name)) {
            throw new Exception("Invalid plugin name: $plugin_name");
        }
        
        // Check if plugin already exists
        $target_path = PathHelper::getAbsolutePath("plugins/$plugin_name");
        if (is_dir($target_path)) {
            // Backup existing plugin
            $backup_path = $target_path . '_backup_' . date('YmdHis');
            rename($target_path, $backup_path);
        }
        
        // Move plugin to final location
        if (!rename($plugin_root, $target_path)) {
            throw new Exception("Failed to install plugin files");
        }
        
        // Set permissions (same as ThemeManager)
        $this->setPermissions($target_path);
        
        // Sync with database (will create plugin record)
        $this->syncWithFilesystem();
        
        // Clean up
        $this->cleanup($temp_dir);
        
        return $plugin_name;
        
    } catch (Exception $e) {
        $this->cleanup($temp_dir);
        throw $e;
    }
}

/**
 * Set proper permissions (same as ThemeManager)
 * @param string $dir Directory path
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
 * Clean up temporary directory (same as ThemeManager)
 * @param string $dir Directory to remove
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
    
    echo "Syncing plugins with stock/custom status...\n";
    $plugin_manager = new PluginManager();
    $synced_plugins = $plugin_manager->syncWithFilesystem();
    echo "Synced " . count($synced_plugins) . " new plugins.\n";
    
    // Update existing plugins with stock/custom status from filesystem
    $existing_plugins = new MultiPlugin();
    $existing_plugins->load();
    $updated_count = 0;
    
    foreach ($existing_plugins as $plugin) {
        $old_stock_status = $plugin->get('plg_is_stock');
        $plugin->load_stock_status();
        $new_stock_status = $plugin->get('plg_is_stock');
        
        // Only save if something changed
        if ($old_stock_status != $new_stock_status) {
            $plugin->save();
            $updated_count++;
            echo "Updated stock status for plugin: " . $plugin->get('plg_name') . "\n";
        }
    }
    
    echo "Updated stock/custom status for $updated_count existing plugins.\n";
    
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

## Phase 2 Implementation Checklist

### ✅ Required Files to Create:
- [ ] `/data/themes_class.php` - NEW: Theme database model
- [ ] `/includes/ThemeManager.php` - NEW: Theme management helper
- [ ] `/adm/admin_themes.php` - NEW: Theme admin interface
- [ ] `/migrations/theme_plugin_registry_sync.php` - NEW: Database sync migration

### ✅ Required Updates to Existing Files:
- [ ] `/data/plugins_class.php` - Add `plg_is_stock`, `plg_create_time`, `plg_update_time` fields and methods
- [ ] `/includes/PluginManager.php` - Add `syncWithFilesystem()` and `installFromZip()` methods
- [ ] `/adm/admin_plugins.php` - Add stock/custom functionality and upload capability
- [ ] `/migrations/migrations.php` - Add migration entry

### ✅ Features to Implement:
- [ ] Theme registry database integration
- [ ] Stock/Custom status for existing plugin system
- [ ] Upload functionality for custom themes  
- [ ] Upload functionality for custom plugins (integrate with existing system)
- [ ] Stock/Custom status management for both themes and plugins
- [ ] Theme activation/deactivation (only one theme active at a time)
- [ ] Filesystem sync functionality for both themes and plugins

### ✅ Deployment Notes:
1. Deploy data class files - tables will be created automatically by update_database system
2. Run migration to sync filesystem with database registry
3. Current theme will be set as active during sync

---

## Summary

This Phase 2 implementation provides:

1. **Complete Theme Management System** - Database-backed theme registry with stock/custom tracking
2. **Enhanced Plugin System** - Adds stock/custom functionality to existing comprehensive plugin infrastructure
3. **Upload Capabilities** - ZIP upload for both themes and plugins
4. **Deployment Safety** - Stock items auto-update, custom items are preserved
5. **Admin Interface** - Full management UI for themes and enhanced UI for plugins
6. **Filesystem Sync** - Automatic discovery and registration of themes/plugins

The implementation leverages existing infrastructure where possible (plugins system) while adding new capabilities (themes system) in a consistent manner.