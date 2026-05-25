<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/themes_class.php'));
require_once(PathHelper::getIncludePath('includes/ThemeManager.php'));

require_once(PathHelper::getIncludePath('adm/logic/admin_themes_logic.php'));

$page_vars = process_logic(admin_themes_logic(array_merge($_GET, $_POST)));

$session = SessionControl::get_instance();

$message = $page_vars['message'];
$error = $page_vars['error'];
$themes = $page_vars['themes'];

$page = new AdminPage();

// Build Options dropdown links
$altlinks = array();
$altlinks['Sync with Filesystem'] = '/admin/admin_themes?action=sync_filesystem';
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
                    <hr>
                    <p class="mb-0">Or browse available themes in the <a href="/admin/admin_marketplace">Marketplace</a>.</p>
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
                                $receives_upgrades = $theme ? (bool)$theme->get('thm_receives_upgrades') : true;
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

                                // Get type badge - System and Preserved-on-deploy can co-appear.
                                $badges = array();
                                if ($is_system) {
                                    $badges[] = '<span class="badge bg-primary"><i class="fas fa-lock me-1"></i>System</span>';
                                }
                                if (!$receives_upgrades) {
                                    $badges[] = '<span class="badge bg-warning">Upgrades disabled</span>';
                                }
                                $type_badge = implode(' ', $badges);

                                if ($is_deprecated) {
                                    $type_badge .= ' <span class="badge bg-dark">Deprecated</span>';
                                    if ($superseded_by) {
                                        $type_badge .= '<br><small class="text-muted">Replaced by ' . htmlspecialchars($superseded_by) . '</small>';
                                    }
                                }

                                // Joinery version requirement check — error badge if unmet.
                                $req_joinery = $theme_data['requires_joinery'] ?? null;
                                if (!empty($req_joinery)) {
                                    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
                                    $jv = LibraryFunctions::get_joinery_version();
                                    $op = '>='; $ver = $req_joinery;
                                    if (preg_match('/^([><=]+)(.+)$/', $req_joinery, $rm)) { $op = $rm[1]; $ver = $rm[2]; }
                                    $req_ok = ($jv !== '' && version_compare($jv, $ver, $op));
                                    if (!$req_ok) {
                                        $type_badge .= '<br><span class="badge bg-danger">Requires Joinery ' . htmlspecialchars($req_joinery) . ' — this site is ' . htmlspecialchars($jv ?: 'unknown') . '</span>';
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
                                    // System themes cannot be deleted, but receives_upgrades is independent.
                                    if ($receives_upgrades) {
                                        $actions['Disable upgrade'] = "javascript:submitAction('mark_preserved', '$theme_name')";
                                    } else {
                                        $actions['Allow upgrade'] = "javascript:submitAction('mark_upgradable', '$theme_name')";
                                    }

                                    // Add delete option for non-system themes with missing files or inactive themes
                                    if (!$is_system && (!$files_exist || !$is_active)) {
                                        $msg_json = htmlspecialchars(json_encode('Delete theme "' . $display_name . '"?'));
                                        $name_json = htmlspecialchars(json_encode($theme_name));
                                        $actions['Permanently Delete'] = "javascript:JoineryModal.confirm($msg_json, function(){ submitAction('delete', $name_json); }, { confirmLabel: 'Delete' })";
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
    
</div>

<script>
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