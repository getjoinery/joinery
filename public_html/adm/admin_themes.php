<?php

PathHelper::requireOnce('includes/AdminPage.php');

PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('data/themes_class.php');
PathHelper::requireOnce('includes/ThemeManager.php');

$session = SessionControl::get_instance();
$session->check_permission(10); // System admin only
$session->set_return();

$theme_manager = ThemeManager::getInstance();
$message = '';
$error = '';

// Handle form submissions and GET actions
$action = $_POST['action'] ?? $_GET['action'] ?? null;
if ($action || $_POST) {
    try {
        if ($action) {
            switch ($action) {
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

                case 'delete':
                    $theme_name = $_POST['theme_name'];
                    $theme = Theme::get_by_theme_name($theme_name);
                    if ($theme) {
                        // Only allow deletion if theme files are missing or it's not active
                        if (!$theme->theme_files_exist() || !$theme->get('thm_is_active')) {
                            $theme->permanent_delete();
                            $message = "Theme '$theme_name' has been deleted from the database.";
                        } else {
                            $error = "Cannot delete an active theme with existing files.";
                        }
                    } else {
                        $error = "Theme not found.";
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

// Build Options dropdown links
$altlinks = array();
$altlinks['Add New'] = '/admin/admin_themes?show_upload=1';
$altlinks['Sync with Filesystem'] = '/admin/admin_themes?action=sync';
$altlinks['Check for Updates'] = '/admin/admin_themes?action=check_updates';

$page->admin_header(array(
    'menu-id' => 'system-themes',
    'page_title' => 'Theme Management',
    'readable_title' => 'Theme Management',
    'breadcrumbs' => array(
        'Settings' => '/admin/admin_settings',
        'Themes' => '',
    ),
    'session' => $session,
));

$page->begin_box(array('altlinks' => $altlinks));
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <?php if (isset($_GET['show_upload'])): ?>
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
            <?php endif; ?>
            
            <!-- Themes Table -->
            <h3>Installed Themes (<?= $themes->count() ?>)</h3>
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
                                
                                // Build actions array
                                $actions = array();

                                if (!$is_active && $files_exist) {
                                    $actions['Activate'] = "javascript:submitAction('activate', '$theme_name')";
                                }

                                if ($is_stock) {
                                    $actions['Mark as Custom'] = "javascript:submitAction('mark_custom', '$theme_name')";
                                } else {
                                    $actions['Mark as Stock'] = "javascript:submitAction('mark_stock', '$theme_name')";
                                }

                                // Add delete option for themes with missing files or inactive themes
                                if (!$files_exist || !$is_active) {
                                    $actions['Delete'] = "javascript:showDeleteModal('$theme_name', '" . htmlspecialchars($display_name, ENT_QUOTES) . "')";
                                }
                                
                                if (!empty($actions)) {
                                    echo '<div class="dropdown">';
                                    echo '<button class="btn btn-falcon-default dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Actions</button>';
                                    echo '<div class="dropdown-menu dropdown-menu-end py-0">';
                                    foreach ($actions as $label => $action) {
                                        echo '<a href="' . $action . '" class="dropdown-item">' . $label . '</a>';
                                    }
                                    echo '</div>';
                                    echo '</div>';
                                } else {
                                    echo '<span class="text-muted">No actions</span>';
                                }
                                
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
    
    <div class="mt-3">
        <p class="text-muted">
            <strong>Note:</strong> Stock themes are automatically updated during deployments. 
            Custom themes are preserved during deployments.
        </p>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteThemeModal" tabindex="-1" aria-labelledby="deleteThemeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteThemeModalLabel">Confirm Theme Deletion</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the theme "<span id="deleteThemeName"></span>"?</p>
                <p class="text-danger"><strong>This action cannot be undone.</strong></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete Theme</button>
            </div>
        </div>
    </div>
</div>

<script>
var themeToDelete = '';

function showDeleteModal(themeName, displayName) {
    themeToDelete = themeName;
    document.getElementById('deleteThemeName').textContent = displayName;
    var modal = new bootstrap.Modal(document.getElementById('deleteThemeModal'));
    modal.show();
}

document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
    if (themeToDelete) {
        submitAction('delete', themeToDelete);
    }
});

function submitAction(action, themeName) {
    var form = document.createElement('form');
    form.method = 'post';
    form.style.display = 'none';
    
    var actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = action;
    form.appendChild(actionInput);
    
    var themeInput = document.createElement('input');
    themeInput.type = 'hidden';
    themeInput.name = 'theme_name';
    themeInput.value = themeName;
    form.appendChild(themeInput);
    
    document.body.appendChild(form);
    form.submit();
}

function syncThemes() {
    if (confirm('Sync themes with filesystem? This will update the database registry with any changes.')) {
        var form = document.createElement('form');
        form.method = 'post';
        form.style.display = 'none';
        
        var actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'sync';
        form.appendChild(actionInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

function checkUpdates() {
    alert('Check for updates functionality will be implemented in a future update.');
}
</script>

<?php
$page->end_box();
$page->admin_footer();
?>