<?php
// @version 1.3 - the replace panel for an upload of an installed name; the Local fork badge with the shipped version; Allow upgrade asks first (specs/package_replace_on_upload.md WP2, WP3)
// @version 1.2 - the Kind column, the two slot lines, Apply and Remove for a style theme; the Unsigned badge on page themes only (specs/style_themes.md WP4)
// @version 1.1 - the Unsigned badge, the warning block and the request panel's hand-off (specs/package_signing.md WP6)

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/themes_class.php'));
require_once(PathHelper::getIncludePath('includes/ThemeManager.php'));

require_once(PathHelper::getIncludePath('adm/logic/admin_themes_logic.php'));

$page_vars = process_logic(admin_themes_logic(array_merge($_GET, $_POST)));

$session = SessionControl::get_instance();

$message = $page_vars['message'];
$error = $page_vars['error'];
// Installing a theme, and the preserve/upgradable flag, write the code tree —
// only root does that here, so the page shows the queued request's progress
// rather than claiming it is finished (specs/read_only_tree.md).
$root_request_id = $page_vars['root_request_id'] ?? '';
$root_actor_notice = $page_vars['root_actor_notice'] ?? '';
// Root refused an uploaded package as not ours: the warning, and Install
// anyway (specs/package_signing.md WP6).
$unsigned_warning = $page_vars['unsigned_warning'] ?? null;
$themes = $page_vars['themes'];
// The two slots (specs/style_themes.md): the page theme, and the look
// layered over it, or none.
$page_theme = (string)($page_vars['page_theme'] ?? '');
$look = (string)($page_vars['look'] ?? '');
$look_help = 'A styling theme changes colours, fonts and images on the pages you already have. Your page theme stays active.';
// An upload names an installed theme: both copies side by side, Replace and
// Discard (specs/package_replace_on_upload.md).
$replace_panel = $page_vars['replace_panel'] ?? null;
// name => version the source ships, for the Local fork badge; null when unknown.
$fork_versions = $page_vars['fork_versions'] ?? null;
$fork_help = 'Uploaded here. Deploys leave it alone, so it gets no updates from Joinery, including security fixes, until Allow upgrade is pressed.';

$page = new AdminPage();

// Build Options dropdown links
$altlinks = array();
$altlinks['Sync with Filesystem'] = '/admin/admin_themes?action=sync_filesystem';
$altlinks['Add New'] = '/admin/admin_marketplace';
$altlinks['Upload ZIP'] = '/admin/admin_themes?show_upload=1';
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

            <?= $root_actor_notice ?>
            <?php if ($unsigned_warning): ?>
                <?= PackageInstallPage::warning_html($unsigned_warning, '/admin/admin_themes', $page) ?>
            <?php endif; ?>
            <?php if ($replace_panel): ?>
                <?= PackageInstallPage::replace_html($replace_panel, '/admin/admin_themes', $page) ?>
            <?php endif; ?>
            <?php if ($root_request_id): ?>
                <?= AdminPage::root_request_panel($root_request_id,
                    '/admin/admin_themes?unsigned=' . rawurlencode($root_request_id)) ?>
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
            <p class="mb-3">
                Pages: <strong><?= htmlspecialchars($page_theme !== '' ? $page_theme : 'none') ?></strong><br>
                Look: <strong><?= htmlspecialchars($look !== '' ? $look : 'none') ?></strong>
            </p>
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Theme</th>
                        <th>Kind</th>
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
                                // A style theme is stylesheets, fonts and images with no
                                // pages: it is applied as the look, never activated.
                                $is_style = (($theme_data['kind'] ?? 'page') === 'style');
                                $is_look = $is_style && $look !== '' && $look === $theme_name;

                                // Get status badge
                                if (!$files_exist) {
                                    $status_badge = '<span class="badge bg-danger">Missing Files</span>';
                                } elseif ($is_look) {
                                    $status_badge = '<span class="badge bg-success">Applied</span>';
                                } elseif ($is_active) {
                                    $status_badge = '<span class="badge bg-success">Active</span>';
                                } else {
                                    $status_badge = '<span class="badge bg-secondary">Inactive</span>';
                                }
                                $kind_cell = $is_style
                                    ? '<span title="' . htmlspecialchars($look_help) . '">Styling</span>'
                                    : '<span title="Pages, logic and styling: the whole site. Activate it to use it.">Pages</span>';

                                // Maturity badge from the manifest status field. Labels only —
                                // an experimental theme installs and activates like a stable one.
                                $maturity_badges = array(
                                    'experimental' => '<span class="badge bg-warning">Experimental</span>',
                                    'beta' => '<span class="badge bg-info">Beta</span>',
                                );
                                $maturity = $theme_data['status'] ?? 'stable';

                                // Get type badge - System and Preserved-on-deploy can co-appear.
                                $badges = array();
                                if (isset($maturity_badges[$maturity])) {
                                    $badges[] = $maturity_badges[$maturity];
                                }
                                // Audience-scoped themes are absent from other sites'
                                // marketplaces, so the operator is told here instead.
                                $audience = $theme_data['audience'] ?? array();
                                if (!empty($audience)) {
                                    $badges[] = '<span class="badge bg-info" title="Listed only for: '
                                        . htmlspecialchars(implode(', ', $audience)) . '">Unlisted</span>';
                                }
                                if ($is_system) {
                                    $badges[] = '<span class="badge bg-primary"><i class="fas fa-lock me-1"></i>System</span>';
                                }
                                // A local fork: the live manifest says receives_upgrades=false,
                                // so deploys leave it alone. The badge says the cost, and how far
                                // behind the shipped version it is when that is known.
                                if (!$receives_upgrades) {
                                    $badges[] = '<span class="badge bg-warning" title="' . htmlspecialchars($fork_help) . '">Local fork</span>';
                                    $shipped = is_array($fork_versions) ? (string)($fork_versions[$theme_name] ?? '') : '';
                                    if ($shipped !== '' && $shipped !== (string)$version) {
                                        $badges[] = '<small class="text-muted">shipped version is ' . htmlspecialchars($shipped) . '</small>';
                                    }
                                }
                                // Installed on the owner's acknowledgement of
                                // the warning: not built by Joinery. Stays for
                                // as long as the row does. A style theme's row
                                // records the same verdict and shows nothing
                                // red: nothing in it runs.
                                if ($theme && !$is_style && (string)$theme->get('thm_trust') === 'unsigned') {
                                    $badges[] = '<span class="badge bg-danger" title="Not built by Joinery; installed on a superadmin\'s acknowledgement of the warning.">Unsigned</span>';
                                } elseif ($theme && !$is_style && (string)$theme->get('thm_trust') === 'unlogged') {
                                    $badges[] = '<span class="badge bg-danger" title="Signed by Joinery but not in the public release log; installed on a superadmin\'s acknowledgement of the warning.">Unlogged</span>';
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
                                echo '<td>' . $kind_cell . '</td>';
                                echo '<td>' . htmlspecialchars($version) . '</td>';
                                echo '<td>' . htmlspecialchars($author) . '</td>';
                                echo '<td>' . $status_badge . '</td>';
                                echo '<td>' . $type_badge . '</td>';
                                echo '<td>';

                                // Build actions array
                                $actions = array();
                                $action_note = '';

                                if ($is_style) {
                                    if ($is_look) {
                                        $actions['Remove'] = "javascript:submitAction('remove_look', '$theme_name')";
                                    } elseif ($files_exist && $theme) {
                                        $actions['Apply'] = "javascript:submitAction('apply_look', '$theme_name')";
                                        $action_note = $look_help;
                                    }
                                } elseif (!$is_active && $files_exist) {
                                    $actions['Activate'] = "javascript:submitAction('activate', '$theme_name')";
                                }

                                // Actions below require a DB record
                                if ($theme) {
                                    // The fork mark. A system theme is pulled fresh by every
                                    // deploy whatever the flag says, so it has no toggle. Allow
                                    // upgrade ends a fork and the next deploy replaces the
                                    // files, so it asks first.
                                    if (!$is_system) {
                                        if ($receives_upgrades) {
                                            $actions['Disable upgrade'] = "javascript:submitAction('mark_preserved', '$theme_name')";
                                        } else {
                                            $allow_json = htmlspecialchars(json_encode('The next deploy will replace your copy of ' . $theme_name
                                                . ' with the version Joinery ships. Your copy is not kept.'));
                                            $name_json = htmlspecialchars(json_encode($theme_name));
                                            $actions['Allow upgrade'] = "javascript:JoineryModal.confirm($allow_json, function(){ submitAction('mark_upgradable', $name_json); }, { confirmLabel: 'Allow upgrade' })";
                                        }
                                    }

                                    // Add delete option for non-system themes with missing files or inactive themes
                                    if (!$is_system && (!$files_exist || (!$is_active && !$is_look))) {
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
                                if ($action_note !== '') {
                                    echo '<br><small class="text-muted">' . htmlspecialchars($action_note) . '</small>';
                                }

                                echo '</td>';
                                echo '</tr>';
                            }

                            if (count($themes) === 0) {
                                echo '<tr><td colspan="7" class="text-center">No themes installed</td></tr>';
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