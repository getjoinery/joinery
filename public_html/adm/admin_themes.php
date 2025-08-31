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

$theme_manager = ThemeManager::getInstance();
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
                    $sync_result = $theme_manager->sync();
                    $parts = array();
                    if (!empty($sync_result['added'])) {
                        $parts[] = count($sync_result['added']) . " added";
                    }
                    if (!empty($sync_result['updated'])) {
                        $parts[] = count($sync_result['updated']) . " updated";
                    }
                    
                    if (empty($parts)) {
                        $message = "Filesystem sync completed. All themes are already up to date.";
                    } else {
                        $message = "Filesystem sync completed: " . implode(", ", $parts) . ".";
                    }
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
$page->admin_header(array(
    'menu-id' => 'themes',
    'page_title' => 'Theme Management',
    'readable_title' => 'Theme Management',
    'breadcrumbs' => array(
        'Settings' => '/admin/admin_settings',
        'Themes' => '',
    ),
    'session' => $session,
));
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