<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/themes_class.php'));
require_once(PathHelper::getIncludePath('includes/ThemeManager.php'));

require_once(PathHelper::getIncludePath('adm/logic/admin_themes_logic.php'));

$page_vars = process_logic(admin_themes_logic($_GET, $_POST));

$session = SessionControl::get_instance();

$message = $page_vars['message'];
$error = $page_vars['error'];
$themes = $page_vars['themes'];

$page = new AdminPage();

// Build Options dropdown links
$altlinks = array();
$altlinks['Add New'] = '/admin/admin_themes?show_upload=1';
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
            <h3>Installed Themes (<?= count($themes) ?>)</h3>
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
                            foreach ($themes as $theme_data) {
                                $theme_name = $theme_data['name'];
                                $theme = $theme_data['theme']; // Theme model or null
                                $display_name = $theme_data['display_name'] ?: $theme_name;
                                $description = $theme_data['description'] ?? null;
                                $version = $theme_data['version'] ?: '1.0.0';
                                $author = $theme_data['author'] ?: 'Unknown';
                                $is_active = $theme_data['is_active'] ?? false;
                                $is_stock = $theme ? (bool)$theme->get('thm_is_stock') : true;
                                $is_system = $theme ? (bool)$theme->get('thm_is_system') : false;
                                $is_deprecated = !empty($theme_data['deprecated']);
                                $superseded_by = $theme_data['superseded_by'] ?? null;
                                $files_exist = $theme_data['directory_exists'];

                                // Get status badge
                                if (!$files_exist) {
                                    $status_badge = '<span class="badge bg-danger">Missing Files</span>';
                                } elseif ($is_active) {
                                    $status_badge = '<span class="badge bg-success">Active</span>';
                                } else {
                                    $status_badge = '<span class="badge bg-secondary">Inactive</span>';
                                }

                                // Get type badge - system themes get special badge
                                if ($is_system) {
                                    $type_badge = '<span class="badge bg-primary"><i class="fas fa-lock me-1"></i>System</span>';
                                } elseif ($is_stock) {
                                    $type_badge = '<span class="badge bg-info">Stock</span>';
                                } else {
                                    $type_badge = '<span class="badge bg-warning">Custom</span>';
                                }

                                if ($is_deprecated) {
                                    $type_badge .= ' <span class="badge bg-dark">Deprecated</span>';
                                    if ($superseded_by) {
                                        $type_badge .= '<br><small class="text-muted">Replaced by ' . htmlspecialchars($superseded_by) . '</small>';
                                    }
                                }

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

                                // Actions below require a DB record
                                if ($theme) {
                                    // System themes cannot be marked as custom or deleted
                                    if (!$is_system) {
                                        if ($is_stock) {
                                            $actions['Mark as Custom'] = "javascript:submitAction('mark_custom', '$theme_name')";
                                        } else {
                                            $actions['Mark as Stock'] = "javascript:submitAction('mark_stock', '$theme_name')";
                                        }

                                        // Add delete option for themes with missing files or inactive themes
                                        if (!$files_exist || !$is_active) {
                                            $is_stock_theme = $is_stock ? 'true' : 'false';
                                            $actions['Permanently Delete'] = "javascript:showDeleteModal('$theme_name', '" . htmlspecialchars($display_name, ENT_QUOTES) . "', $is_stock_theme)";
                                        }
                                    }
                                }

                                if (!empty($actions)) {
                                    echo '<div class="dropdown">';
                                    echo '<button class="btn btn-soft-default dropdown-toggle" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Actions</button>';
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
                            
                            if (count($themes) === 0) {
                                echo '<tr><td colspan="6" class="text-center">No themes installed</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
        </div>
    </div>
    
    <div class="mt-3">
        <p class="text-muted">
            <strong>Notes:</strong>
        </p>
        <ul class="text-muted small">
            <li><strong>System themes</strong> (lock icon) are protected and cannot be deleted or marked as custom. They always receive updates during deployment.</li>
            <li><strong>Stock themes</strong> are automatically updated during deployments.</li>
            <li><strong>Custom themes</strong> are preserved during deployments and will not receive automatic updates.</li>
            <li>Deleting a theme permanently removes both the theme files and database record. The theme will not return on deployment.</li>
        </ul>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<style>
.jy-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    align-items: center;
    justify-content: center;
    z-index: 9999;
}
.jy-modal-overlay.active { display: flex; }
.jy-modal {
    background: var(--white);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    width: 100%;
    max-width: 500px;
    margin: 1rem;
}
.jy-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--border);
}
.jy-modal-header h5 { margin: 0; font-size: 1.1rem; }
.jy-modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    line-height: 1;
    cursor: pointer;
    color: var(--muted);
    padding: 0;
}
.jy-modal-close:hover { color: var(--body-color); }
.jy-modal-body { padding: 1.5rem; }
.jy-modal-body p { margin-bottom: 0.75rem; }
.jy-modal-body ul { list-style: disc; padding-left: 1.5rem; margin-bottom: 0.75rem; }
.jy-modal-body ul li { list-style: disc; margin-bottom: 0.25rem; }
.jy-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--border);
}
</style>

<div id="deleteThemeModal" class="jy-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="deleteThemeModalLabel">
    <div class="jy-modal">
        <div class="jy-modal-header">
            <h5 id="deleteThemeModalLabel">Confirm Theme Deletion</h5>
            <button type="button" class="jy-modal-close" onclick="closeDeleteModal()" aria-label="Close">&times;</button>
        </div>
        <div class="jy-modal-body">
            <p>Are you sure you want to permanently delete the theme "<strong><span id="deleteThemeName"></span></strong>"?</p>
            <p>This will:</p>
            <ul>
                <li>Remove all theme files from the server</li>
                <li>Delete the theme's database record</li>
            </ul>
            <div id="stockThemeWarning" class="alert alert-info" style="display:none;">
                This is a <strong>stock theme</strong>. It can be re-downloaded later via the upgrade system.
            </div>
            <div id="customThemeWarning" class="alert alert-danger" style="display:none;">
                <strong>WARNING:</strong> This is a <strong>custom theme</strong>. Custom themes cannot be recovered once deleted!
            </div>
            <p style="color:var(--danger);"><strong>This action cannot be undone.</strong></p>
        </div>
        <div class="jy-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Permanently Delete Theme</button>
        </div>
    </div>
</div>

<script>
var themeToDelete = '';

function showDeleteModal(themeName, displayName, isStock) {
    themeToDelete = themeName;
    document.getElementById('deleteThemeName').textContent = displayName;

    if (isStock) {
        document.getElementById('stockThemeWarning').style.display = 'block';
        document.getElementById('customThemeWarning').style.display = 'none';
    } else {
        document.getElementById('stockThemeWarning').style.display = 'none';
        document.getElementById('customThemeWarning').style.display = 'block';
    }

    document.getElementById('deleteThemeModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeDeleteModal() {
    document.getElementById('deleteThemeModal').classList.remove('active');
    document.body.style.overflow = '';
}

document.getElementById('deleteThemeModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});

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

function checkUpdates() {
    alert('Check for updates functionality will be implemented in a future update.');
}
</script>

<?php
$page->end_box();
$page->admin_footer();
?>