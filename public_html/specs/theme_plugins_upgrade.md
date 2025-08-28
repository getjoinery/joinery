# Theme and Plugin Management System Upgrade

## Directory Structure Changes

**Remove:**
- `/var/www/html/[sitename]/theme/`
- `/var/www/html/[sitename]/plugins/`
- `/var/www/html/[sitename]/theme_stage/`
- `/var/www/html/[sitename]/plugins_stage/`

**Keep Only:**
- `/var/www/html/[sitename]/public_html/theme/`
- `/var/www/html/[sitename]/public_html/plugins/`

## Phase 1: Deploy Script & Manifests

### 1.1 Manifest Files

**theme/[name]/theme.json:**
```json
{
  "name": "Theme Name",
  "version": "1.0.0",
  "description": "Theme description",
  "author": "Author Name",
  "is_stock": true
}
```

**plugins/[name]/plugin.json (add is_stock field):**
```json
{
  "existing_fields": "...",
  "is_stock": true
}
```

### 1.2 Deploy Script Changes

**Remove functions:**
- `deploy_theme_plugin()`
- `merge_themes_plugins_to_public_html()`

**Remove variables:**
- `IS_THEME_ONLY`
- All `--theme-only` flag handling

**Replace theme/plugin deployment (after line ~600):**
```bash
# Smart theme merge: update stock items, skip custom items
if [[ -d "public_html_stage/theme" ]]; then
    mkdir -p public_html/theme
    for theme_dir in public_html_stage/theme/*/; do
        if [[ -d "$theme_dir" ]]; then
            theme_name=$(basename "$theme_dir")
            manifest_file="$theme_dir/theme.json"
            
            # Check if theme is stock by reading manifest directly
            if [[ -f "$manifest_file" ]]; then
                is_stock=$(jq -r '.is_stock // false' "$manifest_file" 2>/dev/null)
            else
                is_stock="false"
            fi
            
            if [[ ! -d "public_html/theme/$theme_name" ]]; then
                echo "Adding new theme: $theme_name (stock: $is_stock)"
                cp -r "$theme_dir" "public_html/theme/"
            elif [[ "$is_stock" == "true" ]]; then
                echo "Updating stock theme: $theme_name"
                rm -rf "public_html/theme/$theme_name"
                cp -r "$theme_dir" "public_html/theme/"
            else
                echo "Skipping custom theme: $theme_name (use admin interface to upgrade)"
            fi
        fi
    done
fi

# Smart plugin merge: update stock items, skip custom items
if [[ -d "public_html_stage/plugins" ]]; then
    mkdir -p public_html/plugins  
    for plugin_dir in public_html_stage/plugins/*/; do
        if [[ -d "$plugin_dir" ]]; then
            plugin_name=$(basename "$plugin_dir")
            manifest_file="$plugin_dir/plugin.json"
            
            # Check if plugin is stock by reading manifest directly
            if [[ -f "$manifest_file" ]]; then
                is_stock=$(jq -r '.is_stock // false' "$manifest_file" 2>/dev/null)
            else
                is_stock="false"
            fi
            
            if [[ ! -d "public_html/plugins/$plugin_name" ]]; then
                echo "Adding new plugin: $plugin_name (stock: $is_stock)"
                cp -r "$plugin_dir" "public_html/plugins/"
            elif [[ "$is_stock" == "true" ]]; then
                echo "Updating stock plugin: $plugin_name"
                rm -rf "public_html/plugins/$plugin_name"
                cp -r "$plugin_dir" "public_html/plugins/"
            else
                echo "Skipping custom plugin: $plugin_name (use admin interface to upgrade)"
            fi
        fi
    done
fi
```

### 1.3 First Deployment Transition Logic

**Expected behavior on first deploy:**
- Existing themes without theme.json → treated as stock, will be updated
- Existing plugins without is_stock field → treated as stock, will be updated
- After first deploy, all items have proper manifests

## Phase 2: Admin Interface

### 2.1 Database Model

The theme management system requires a new database table to track installed themes. The table is created automatically by the SystemBase class when update_database.php is run.

**Table: thm_themes**
- `thm_theme_id` (int8, serial, primary key)
- `thm_name` (varchar(255), unique)
- `thm_display_name` (varchar(255))
- `thm_description` (text)
- `thm_version` (varchar(20))
- `thm_author` (varchar(255))
- `thm_is_active` (bool, default false)
- `thm_is_stock` (bool, default false)
- `thm_status` (varchar(20), default 'installed')
- `thm_installed_time` (timestamp)
- `thm_delete_time` (timestamp)

### 2.2 Theme Model

**/data/themes_class.php:**
```php
<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SystemClass.php');

class ThemeException extends SystemClassException {}
class ThemeNotSentException extends ThemeException {};

class Theme extends SystemBase {
    public static $prefix = 'thm';
    public static $tablename = 'thm_themes';
    public static $pkey_column = 'thm_theme_id';
    public static $permanent_delete_actions = array();
    
    public static $fields = array(
        'thm_theme_id' => 'Primary key - Theme ID',
        'thm_name' => 'Name of the theme',
        'thm_version' => 'Theme version',
        'thm_description' => 'Theme description',
        'thm_author' => 'Theme author',
        'thm_is_active' => 'Active status (1/0)',
        'thm_is_stock' => 'Stock theme flag (1/0)',
        'thm_installed_time' => 'Installation time',
        'thm_last_activated_time' => 'Last activation time',
        'thm_last_deactivated_time' => 'Last deactivation time',
        'thm_uninstalled_time' => 'Uninstall time',
        'thm_status' => 'Theme status (installed/active/inactive/error)',
        'thm_install_error' => 'Installation error message',
        'thm_metadata' => 'Theme metadata JSON',
        'thm_delete_time' => 'Soft delete timestamp'
    );
    
    public static $field_specifications = array(
        'thm_name' => array('type'=>'varchar(255)', 'is_nullable'=>false, 'unique'=>true),
        'thm_version' => array('type'=>'varchar(50)'),
        'thm_description' => array('type'=>'text'),
        'thm_author' => array('type'=>'varchar(255)'),
        'thm_is_active' => array('type'=>'boolean', 'is_nullable'=>false),
        'thm_is_stock' => array('type'=>'boolean', 'is_nullable'=>false),
        'thm_installed_time' => array('type'=>'timestamp'),
        'thm_last_activated_time' => array('type'=>'timestamp'),
        'thm_last_deactivated_time' => array('type'=>'timestamp'),
        'thm_uninstalled_time' => array('type'=>'timestamp'),
        'thm_status' => array('type'=>'varchar(50)', 'is_nullable'=>false),
        'thm_install_error' => array('type'=>'text'),
        'thm_metadata' => array('type'=>'jsonb')
    );
    
    public static $initial_default_values = array(
        'thm_is_active' => false,
        'thm_is_stock' => false,
        'thm_status' => 'installed'
    );
    
    public static $timestamp_fields = array('thm_installed_time', 
                                           'thm_last_activated_time', 'thm_last_deactivated_time',
                                           'thm_uninstalled_time');
    public static $required_fields = array('thm_name');
    public static $json_vars = array('thm_metadata');
    
    /**
     * Get theme by name
     */
    public static function get_by_theme_name($theme_name) {
        if (!$theme_name) return null;
        
        return static::GetByColumn('thm_name', $theme_name);
    }
    
    /**
     * Check if theme name is valid
     */
    public static function is_valid_theme_name($theme_name, $check_exists = true) {
        if (empty($theme_name)) return false;
        
        // Length check
        if (strlen($theme_name) > 50 || strlen($theme_name) < 3) {
            return false;
        }
        
        // Must start with letter, then alphanumeric, underscore, dash
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $theme_name)) {
            return false;
        }
        
        // Reserved names check
        $reserved = array('admin', 'api', 'includes', 'data', 'ajax', 'assets', 
                         'utils', 'adm', 'logic', 'views', 'migrations', 'specs');
        if (in_array(strtolower($theme_name), $reserved)) {
            return false;
        }
        
        // Path traversal check
        if (strpos($theme_name, '..') !== false || strpos($theme_name, '/') !== false 
            || strpos($theme_name, '\\') !== false) {
            return false;
        }
        
        // Check if directory exists (optional, for existing themes)
        if ($check_exists) {
            $theme_path = PathHelper::getAbsolutePath("theme/$theme_name");
            return is_dir($theme_path);
        }
        
        return true;
    }
    
    /**
     * Activate theme
     */
    public function activate() {
        $dbconnector = DbConnector::get_instance();
        $dblink = $dbconnector->get_db_link();
        
        try {
            $dblink->beginTransaction();
            
            // Deactivate all other themes first
            $active_themes = new MultiTheme(array('thm_is_active' => true));
            $active_themes->load();
            
            foreach ($active_themes as $theme) {
                $theme->set('thm_is_active', false);
                $theme->set('thm_status', 'inactive');
                $theme->save();
            }
            
            // Activate this theme
            $this->set('thm_is_active', true);
            $this->set('thm_status', 'active');
            $this->set('thm_last_activated_time', 'now()');
            $result = $this->save();
            
            $dblink->commit();
            return $result;
            
        } catch (Exception $e) {
            $dblink->rollBack();
            throw $e;
        }
    }
    
    /**
     * Deactivate theme
     */
    public function deactivate() {
        $this->set('thm_is_active', false);
        $this->set('thm_status', 'inactive');
        $this->set('thm_last_deactivated_time', 'now()');
        
        return $this->save();
    }
    
    /**
     * Load theme metadata from theme.json
     */
    public function load_metadata() {
        $theme_name = $this->get('thm_name');
        if (!$theme_name) return false;
        
        $manifest_path = PathHelper::getAbsolutePath("theme/$theme_name/theme.json");
        if (!file_exists($manifest_path)) return false;
        
        $metadata = json_decode(file_get_contents($manifest_path), true);
        if ($metadata) {
            $this->set('thm_metadata', $metadata);
            
            // Update fields from metadata
            if (isset($metadata['version'])) $this->set('thm_version', $metadata['version']);
            if (isset($metadata['description'])) $this->set('thm_description', $metadata['description']);
            if (isset($metadata['author'])) $this->set('thm_author', $metadata['author']);
            if (isset($metadata['is_stock'])) $this->set('thm_is_stock', $metadata['is_stock']);
            
            return true;
        }
        
        return false;
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
        
        if (isset($this->options['deleted'])) {
            $filters['thm_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }
        
        return $this->_get_resultsv2('thm_themes', $filters, $this->order_by, $only_count, $debug);
    }
    
    function load($debug = false) {
        parent::load();
        $q = $this->getMultiResults(false, $debug);
        foreach($q->fetchAll() as $row) {
            $child = new Theme($row->thm_theme_id);
            $child->load_from_data($row);
            $this->add($child);
        }
    }
    
    function count_all() {
        $results = $this->getMultiResults(true, false);
        return intval($results);
    }
}
```

### 2.3 Theme Manager

**/includes/ThemeManager.php:**
```php
<?php
require_once(__DIR__ . '/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('data/themes_class.php');
PathHelper::requireOnce('includes/Globalvars.php');

class ThemeManager {
    private static $instance = null;
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Scan theme directory and sync with database
     */
    public function syncThemes() {
        $theme_dir = PathHelper::getAbsolutePath('theme');
        if (!is_dir($theme_dir)) {
            mkdir($theme_dir, 0775, true);
            return array();
        }
        
        $synced = array();
        $dirs = scandir($theme_dir);
        
        foreach ($dirs as $dir) {
            if ($dir == '.' || $dir == '..') continue;
            
            $theme_path = "$theme_dir/$dir";
            if (!is_dir($theme_path)) continue;
            
            // Check if theme exists in database
            $theme = Theme::get_by_theme_name($dir);
            
            if (!$theme) {
                // New theme, add to database
                $theme = new Theme(null);
                $theme->set('thm_name', $dir);
                $theme->set('thm_status', 'installed');
                $theme->set('thm_installed_time', 'now()');
            }
            
            // Load metadata from theme.json
            $theme->load_metadata();
            $theme->save();
            
            $synced[] = $dir;
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
            
            // Find theme.json
            $theme_json_path = null;
            $theme_name = null;
            
            // Check if ZIP contains theme directly or in subdirectory
            if (file_exists("$temp_dir/theme.json")) {
                $theme_json_path = "$temp_dir/theme.json";
                $theme_root = $temp_dir;
            } else {
                // Look in first subdirectory
                $dirs = scandir($temp_dir);
                foreach ($dirs as $dir) {
                    if ($dir == '.' || $dir == '..') continue;
                    if (is_dir("$temp_dir/$dir") && file_exists("$temp_dir/$dir/theme.json")) {
                        $theme_json_path = "$temp_dir/$dir/theme.json";
                        $theme_root = "$temp_dir/$dir";
                        $theme_name = $dir;
                        break;
                    }
                }
            }
            
            if (!$theme_json_path) {
                throw new Exception("No theme.json found in uploaded file");
            }
            
            // Read theme.json
            $metadata = json_decode(file_get_contents($theme_json_path), true);
            if (!$metadata) {
                throw new Exception("Invalid theme.json");
            }
            
            // Determine theme name
            if (!$theme_name) {
                // Use name from metadata or generate from ZIP filename
                if (isset($metadata['name'])) {
                    $theme_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($metadata['name']));
                    // Ensure it starts with a letter
                    if (!preg_match('/^[a-zA-Z]/', $theme_name)) {
                        $theme_name = 'theme_' . $theme_name;
                    }
                } else {
                    $theme_name = 'theme_' . uniqid();
                }
            }
            
            // Validate theme name
            if (!Theme::is_valid_theme_name($theme_name, false)) {
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
            
            // Set proper permissions
            exec("chown -R www-data:user1 " . escapeshellarg($target_path));
            exec("chmod -R 775 " . escapeshellarg($target_path));
            
            // Sync with database
            $this->syncThemes();
            
            // Clean up temp directory
            exec("rm -rf " . escapeshellarg($temp_dir));
            
            return $theme_name;
            
        } catch (Exception $e) {
            // Clean up on error
            exec("rm -rf " . escapeshellarg($temp_dir));
            throw $e;
        }
    }
    
    /**
     * Uninstall theme
     */
    public function uninstallTheme($theme_name) {
        // Don't allow uninstalling active theme
        $active_theme = $this->getActiveTheme();
        if ($active_theme && $active_theme->get('thm_name') == $theme_name) {
            throw new Exception("Cannot uninstall active theme");
        }
        
        // Get theme record
        $theme = Theme::get_by_theme_name($theme_name);
        if (!$theme) {
            throw new Exception("Theme not found");
        }
        
        // Don't allow uninstalling stock themes
        if ($theme->get('thm_is_stock')) {
            throw new Exception("Cannot uninstall stock theme");
        }
        
        // Remove theme directory
        $theme_path = PathHelper::getAbsolutePath("theme/$theme_name");
        if (is_dir($theme_path)) {
            exec("rm -rf " . escapeshellarg($theme_path));
        }
        
        // Mark as uninstalled in database
        $theme->set('thm_uninstalled_time', 'now()');
        $theme->set('thm_status', 'uninstalled');
        $theme->save();
        
        return true;
    }
}
```

### 2.4 Admin Interface

**/adm/admin_themes.php:**
```php
<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/AdminPage.php');
PathHelper::requireOnce('includes/SessionControl.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('data/themes_class.php');
PathHelper::requireOnce('includes/ThemeManager.php');

$session = SessionControl::get_instance();
$session->check_permission(10); // System admin only
$session->set_return();

$page = new AdminPage();
$message = '';
$message_type = '';

$themeManager = ThemeManager::getInstance();

// Sync themes with database
$themeManager->syncThemes();

// Handle form submissions
if ($_POST) {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $theme_name = isset($_POST['theme_name']) ? $_POST['theme_name'] : '';
    
    // Validate theme name
    if (!$theme_name || !Theme::is_valid_theme_name($theme_name)) {
        $message = 'Invalid theme name.';
        $message_type = 'danger';
    } else {
        try {
            $theme = Theme::get_by_theme_name($theme_name);
            
            if ($action === 'activate') {
                if ($theme) {
                    $theme->activate();
                    $message = "Theme '$theme_name' has been activated.";
                    $message_type = 'success';
                    
                    // Update theme_template setting in database
                    $theme_settings = new MultiSetting(array('setting_name' => 'theme_template'));
                    $theme_settings->load();
                    if ($theme_settings->count() > 0) {
                        $theme_setting = $theme_settings->get(0);
                        $theme_setting->set('stg_value', $theme_name);
                        $theme_setting->set('stg_update_time', 'NOW()');
                        $theme_setting->set('stg_usr_user_id', $session->get_user_id());
                        $theme_setting->prepare();
                        $theme_setting->save();
                    }
                } else {
                    $message = "Theme '$theme_name' not found in database.";
                    $message_type = 'danger';
                }
            } elseif ($action === 'deactivate') {
                if ($theme) {
                    $theme->deactivate();
                    $message = "Theme '$theme_name' has been deactivated.";
                    $message_type = 'warning';
                }
            } elseif ($action === 'uninstall') {
                try {
                    $themeManager->uninstallTheme($theme_name);
                    $message = "Theme '$theme_name' has been uninstalled.";
                    $message_type = 'success';
                } catch (Exception $e) {
                    $message = "Failed to uninstall theme: " . $e->getMessage();
                    $message_type = 'danger';
                }
            } elseif ($action === 'mark_stock') {
                if ($theme) {
                    $theme->set('thm_is_stock', true);
                    $theme->save();
                    $message = "Theme '$theme_name' marked as stock.";
                    $message_type = 'success';
                }
            } elseif ($action === 'mark_custom') {
                if ($theme) {
                    $theme->set('thm_is_stock', false);
                    $theme->save();
                    $message = "Theme '$theme_name' marked as custom.";
                    $message_type = 'success';
                }
            }
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

// Handle file uploads
if (isset($_FILES['theme_zip']) && $_FILES['theme_zip']['error'] === UPLOAD_ERR_OK) {
    try {
        $uploaded_file = $_FILES['theme_zip']['tmp_name'];
        $theme_name = $themeManager->installTheme($uploaded_file);
        $message = "Theme '$theme_name' has been installed successfully.";
        $message_type = 'success';
    } catch (Exception $e) {
        $message = 'Failed to install theme: ' . $e->getMessage();
        $message_type = 'danger';
    }
}

// Get current active theme
$active_theme = $themeManager->getActiveTheme();
$active_theme_name = $active_theme ? $active_theme->get('thm_name') : 'None';

// Load all themes
$themes = new MultiTheme(array('deleted' => false), array('thm_name' => 'ASC'));
$themes->load();

// Start output
$page->set_title("Theme Management");
$page->write_header("", true);

// Show message if any
if ($message) {
    echo '<div class="alert alert-' . $message_type . ' alert-dismissible fade show" role="alert">';
    echo htmlspecialchars($message);
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
    echo '</div>';
}
?>

<div class="container-fluid">
    <div class="row mb-3">
        <div class="col">
            <h1>Theme Management</h1>
            <p>Current Active Theme: <strong><?php echo htmlspecialchars($active_theme_name); ?></strong></p>
        </div>
    </div>
    
    <!-- Upload New Theme -->
    <div class="card mb-4">
        <div class="card-header">
            <h5>Upload New Theme</h5>
        </div>
        <div class="card-body">
            <form method="post" enctype="multipart/form-data">
                <div class="row">
                    <div class="col-md-8">
                        <input type="file" name="theme_zip" class="form-control" accept=".zip" required>
                        <small class="form-text text-muted">Upload a ZIP file containing the theme files and theme.json</small>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary">Upload Theme</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Installed Themes -->
    <div class="card">
        <div class="card-header">
            <h5>Installed Themes</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
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
                            $is_active = $theme->get('thm_is_active');
                            $is_stock = $theme->get('thm_is_stock');
                            $theme_name = $theme->get('thm_name');
                            $version = $theme->get('thm_version') ?: 'Unknown';
                            $author = $theme->get('thm_author') ?: 'Unknown';
                            $description = $theme->get('thm_description') ?: '';
                            
                            // Get status badge
                            if ($is_active) {
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
                            echo '<strong>' . htmlspecialchars($theme_name) . '</strong>';
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
                            
                            if (!$is_active) {
                                echo '<button type="submit" name="action" value="activate" class="btn btn-sm btn-success me-1">Activate</button>';
                            } else {
                                echo '<button type="submit" name="action" value="deactivate" class="btn btn-sm btn-warning me-1" disabled>Deactivate</button>';
                            }
                            
                            if (!$is_stock && !$is_active) {
                                echo '<button type="submit" name="action" value="uninstall" class="btn btn-sm btn-danger me-1" onclick="return confirm(\'Are you sure you want to uninstall this theme?\')">Uninstall</button>';
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

### 2.5 Plugin Upload Enhancement

**Add upload functionality to /adm/admin_plugins.php:**

At the top of the file after other includes:
```php
PathHelper::requireOnce('includes/PluginManager.php');
```

Add upload form above existing plugin list:
```php
<!-- Upload Plugin Form -->
<div class="card mb-4">
    <div class="card-header">
        <h3>Upload New Plugin</h3>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label for="plugin_zip">Plugin ZIP File:</label>
                <input type="file" name="plugin_zip" id="plugin_zip" class="form-control" accept=".zip" required>
                <small class="form-text text-muted">
                    Upload a ZIP file containing the plugin files with plugin.json in the root directory
                </small>
            </div>
            <button type="submit" name="upload_plugin" class="btn btn-primary">Upload Plugin</button>
        </form>
    </div>
</div>
```

Add upload handler before the existing plugin list code:
```php
// Handle plugin upload
if (isset($_POST['upload_plugin']) && isset($_FILES['plugin_zip'])) {
    try {
        $pluginManager = new PluginManager();
        $result = $pluginManager->installPlugin($_FILES['plugin_zip']['tmp_name']);
        
        echo '<div class="alert alert-success">
            Plugin "' . htmlspecialchars($result['name']) . '" installed successfully!
        </div>';
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">
            Error installing plugin: ' . htmlspecialchars($e->getMessage()) . '
        </div>';
    }
}
```

**Add to existing /includes/PluginManager.php:**
```php
// Add a new PluginManager class to the existing file:

class PluginManager {
    
    private $plugins_dir;
    
    /**
     * Validate plugin name
     * @param string $plugin_name Name to validate
     * @return bool True if valid
     */
    public static function is_valid_plugin_name($plugin_name) {
        if (empty($plugin_name)) return false;
        
        // Length check
        if (strlen($plugin_name) > 50 || strlen($plugin_name) < 3) {
            return false;
        }
        
        // Must start with letter, then alphanumeric, underscore, dash
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $plugin_name)) {
            return false;
        }
        
        // Reserved names check
        $reserved = array('admin', 'api', 'includes', 'data', 'ajax', 'assets', 
                         'utils', 'adm', 'logic', 'views', 'migrations', 'specs',
                         'theme', 'themes', 'plugin', 'plugins', 'core', 'system');
        if (in_array(strtolower($plugin_name), $reserved)) {
            return false;
        }
        
        // Path traversal check
        if (strpos($plugin_name, '..') !== false || strpos($plugin_name, '/') !== false 
            || strpos($plugin_name, '\\') !== false) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Install a plugin from a ZIP file
     * @param string $zip_file Path to uploaded ZIP file
     * @return array Plugin information
     * @throws Exception on installation failure
     */
    public function installPlugin($zip_file) {
        if (!isset($this->plugins_dir)) {
            $this->plugins_dir = PathHelper::getBasePath() . '/plugins';
        }
        // Validate ZIP file
        $zip = new ZipArchive();
        if ($zip->open($zip_file) !== true) {
            throw new Exception('Invalid ZIP file');
        }
        
        // Check for plugin.json in root
        $manifest_content = $zip->getFromName('plugin.json');
        if ($manifest_content === false) {
            // Check if it's in a subdirectory
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (preg_match('/^[^\/]+\/plugin\.json$/', $filename)) {
                    $manifest_content = $zip->getFromIndex($i);
                    break;
                }
            }
            
            if ($manifest_content === false) {
                $zip->close();
                throw new Exception('plugin.json not found in ZIP file');
            }
        }
        
        // Parse manifest
        $manifest = json_decode($manifest_content, true);
        if (!$manifest || !isset($manifest['name'])) {
            $zip->close();
            throw new Exception('Invalid plugin.json file');
        }
        
        $plugin_name = $manifest['name'];
        
        // Validate plugin name
        if (!self::is_valid_plugin_name($plugin_name)) {
            $zip->close();
            throw new Exception('Invalid plugin name: ' . $plugin_name . '. Plugin names must be 3-50 characters, start with a letter, and contain only letters, numbers, underscores, and dashes.');
        }
        
        $target_dir = $this->plugins_dir . '/' . $plugin_name;
        
        // Check if plugin already exists
        if (file_exists($target_dir)) {
            $zip->close();
            throw new Exception('Plugin "' . $plugin_name . '" already exists');
        }
        
        // Create temp directory for extraction
        $temp_dir = sys_get_temp_dir() . '/plugin_' . uniqid();
        if (!mkdir($temp_dir, 0755, true)) {
            $zip->close();
            throw new Exception('Failed to create temp directory');
        }
        
        // Extract ZIP
        $zip->extractTo($temp_dir);
        $zip->close();
        
        // Find the plugin root directory
        $plugin_root = $temp_dir;
        if (!file_exists($temp_dir . '/plugin.json')) {
            // Plugin is in a subdirectory
            $dirs = glob($temp_dir . '/*', GLOB_ONLYDIR);
            if (count($dirs) == 1 && file_exists($dirs[0] . '/plugin.json')) {
                $plugin_root = $dirs[0];
            } else {
                $this->cleanup($temp_dir);
                throw new Exception('Invalid plugin structure');
            }
        }
        
        // Set is_stock to false for uploaded plugins
        $manifest['is_stock'] = false;
        file_put_contents(
            $plugin_root . '/plugin.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        
        // Move to plugins directory
        if (!rename($plugin_root, $target_dir)) {
            $this->cleanup($temp_dir);
            throw new Exception('Failed to install plugin');
        }
        
        // Clean up temp directory
        $this->cleanup($temp_dir);
        
        // Set proper permissions
        $this->setPermissions($target_dir);
        
        return $manifest;
    }
    
    /**
     * Set proper permissions for plugin directory
     */
    private function setPermissions($dir) {
        // Set ownership to www-data:user1
        @chown($dir, 'www-data');
        @chgrp($dir, 'user1');
        @chmod($dir, 0775);
        
        // Recursively set permissions
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
```

### 2.6 Migration

**/migrations/theme_registry_sync.php:**
```php
<?php
/**
 * Migration to sync existing themes with the new theme registry
 * This should be run once after Phase 2 deployment
 */

function theme_registry_sync() {
    echo "Syncing themes with registry...\n";
    
    PathHelper::requireOnce('includes/ThemeManager.php');
    PathHelper::requireOnce('data/themes_class.php');
    
    $themeManager = ThemeManager::getInstance();
    
    // Scan and sync all themes
    $synced = $themeManager->syncThemes();
    
    echo "Found and synced " . count($synced) . " themes:\n";
    foreach ($synced as $theme_name) {
        echo "  - $theme_name\n";
    }
    
    // Get current theme from settings
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

// Add this to migrations.php:
$migration = array();
$migration['database_version'] = '2.XX';  // Use appropriate version
$migration['test'] = "SELECT count(1) as count FROM information_schema.tables WHERE table_name = 'thm_themes'";
$migration['migration_file'] = 'theme_registry_sync.php';
$migration['migration_sql'] = NULL;
$migrations[] = $migration;
```

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
}
```

## Notes

**Stock/Custom Logic:**
- Items with `"is_stock": true` in manifests are overwritten on deploy
- Items with `"is_stock": false` or missing manifests are preserved
- Admin interface allows toggling stock/custom status

**Breaking Changes:**
- `--theme-only` flag removed
- External directories removed (`/var/www/html/[site]/theme/`, `/var/www/html/[site]/plugins/`)