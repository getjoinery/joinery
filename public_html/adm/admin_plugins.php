<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/plugins_class.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('includes/PluginManager.php'));

require_once(PathHelper::getIncludePath('adm/logic/admin_plugins_logic.php'));

$page_vars = process_logic(admin_plugins_logic(array_merge($_GET, $_POST)));

$session = SessionControl::get_instance();

$page = new AdminPage();
$message = $page_vars['message'];
$message_type = $page_vars['message_type'];
$system_health = $page_vars['system_health'];
$plugins = $page_vars['plugins'];
$provisioning_plugins = $page_vars['provisioning_plugins'] ?? array();

// Build Options dropdown links
$altlinks = array();
$altlinks['Sync with Filesystem'] = '/admin/admin_plugins?action=sync_filesystem';
$altlinks['Add New'] = '/admin/admin_plugins?show_upload=1';

$page->admin_header(array(
    'menu-id' => 'system-plugins',
    'page_title' => 'Plugin Management',
    'readable_title' => 'Plugin Management',
    'breadcrumbs' => array(
        'Settings' => '/admin/admin_settings',
        'Plugins' => '',
    ),
    'session' => $session,
));

$page->begin_box(array('altlinks' => $altlinks));
?>

<div class="row">
    <div class="col-12">

        <?php if ($system_health && ($system_health['overall_status'] === 'needs_repair' || $system_health['overall_status'] === 'error')): ?>
            <div class="alert alert-danger" role="alert">
                <h6 class="alert-heading mb-2"><i class="fas fa-exclamation-triangle"></i> System Configuration Issue</h6>
                <p><strong>The plugin system is not properly configured.</strong> Please address the following issues:</p>
                <ul class="mb-3">
                    <?php foreach ($system_health['issues'] as $issue): ?>
                        <li><?php echo htmlspecialchars($issue); ?></li>
                    <?php endforeach; ?>
                </ul>
                <p class="mb-0"><strong>Recommended action:</strong></p>
                <ul class="mb-0">
                    <?php foreach ($system_health['recommendations'] as $recommendation): ?>
                        <li><?php echo htmlspecialchars($recommendation); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>" role="alert">
                <?php echo $message; ?>
                <button type="button" class="alert-close" aria-label="Close">&times;</button>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['show_upload'])): ?>
        <!-- Upload Plugin Form -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Upload New Plugin</h5>
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
                            <i class="fas fa-upload"></i> Upload Plugin
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if (empty($plugins)): ?>
            <div class="alert alert-warning">
                <strong>No plugins found.</strong> Create plugin directories in <code>/plugins/</code> to manage them here.
            </div>
        <?php else: ?>

            <?php
            // Set up table headers
            $headers = array('Plugin', 'Description', 'Version', 'Status', 'Actions');

            // Set up table options
            $table_options = array(
                'title' => 'Plugin Status Overview'
            );

            // Start the table
            $page->tableheader($headers, $table_options);

            // Display each plugin row
            foreach ($plugins as $plugin) {
                $rowvalues = array();

                // Plugin name column
                $plugin_cell = '<strong>' . htmlspecialchars($plugin['display_name']) . '</strong>';
                if ($plugin['display_name'] !== $plugin['name']) {
                    $plugin_cell .= '<br><small class="text-muted">' . htmlspecialchars($plugin['name']) . '</small>';
                }
                if ($plugin['author']) {
                    $plugin_cell .= '<br><small class="text-muted">by ' . htmlspecialchars($plugin['author']) . '</small>';
                }
                array_push($rowvalues, $plugin_cell);

                // Description column
                if ($plugin['description']) {
                    array_push($rowvalues, htmlspecialchars($plugin['description']));
                } else {
                    array_push($rowvalues, '<em class="text-muted">No description available</em>');
                }

                // Version column
                $version_cell = '';
                if ($plugin['version']) {
                    $version_cell = '<code>' . htmlspecialchars($plugin['version']) . '</code>';
                } else {
                    $version_cell = '<em class="text-muted">Unknown</em>';
                }
                array_push($rowvalues, $version_cell);

                // Status column
                $status_cell = $plugin['status_badge'];

                // Maturity badge from the manifest status field. Labels only —
                // an experimental plugin installs and updates like a stable one.
                $maturity_badges = array(
                    'experimental' => '<span class="badge bg-warning">Experimental</span>',
                    'beta' => '<span class="badge bg-info">Beta</span>',
                    'deprecated' => '<span class="badge bg-dark">Deprecated</span>',
                );
                $maturity = $plugin['maturity'] ?? null;
                if ($maturity && isset($maturity_badges[$maturity])
                    && !($maturity === 'deprecated' && !empty($plugin['deprecated']))) {
                    $status_cell .= ' ' . $maturity_badges[$maturity];
                }

                // Add Preserved-on-deploy badge only when receives_upgrades=false
                if ($plugin['plugin']) {
                    if (!$plugin['plugin']->receives_upgrades()) {
                        $status_cell .= ' <span class="badge bg-warning">Preserved on deploy</span>';
                    }

                    // Check if this is the active theme provider
                    try {

                        $plugin_helper = PluginHelper::getInstance($plugin['name']);
                        if ($plugin_helper->isActiveThemeProvider()) {
                            $status_cell .= ' <span class="badge bg-primary">Active Theme Provider</span>';
                        }
                    } catch (Exception $e) {
                        // Plugin helper not available - skip theme provider check
                    }
                }

                if (!empty($plugin['deprecated'])) {
                    $status_cell .= ' <span class="badge bg-dark">Deprecated</span>';
                    if (!empty($plugin['superseded_by'])) {
                        $status_cell .= '<br><small class="text-muted">Replaced by ' . htmlspecialchars($plugin['superseded_by']) . '</small>';
                    }
                }

                // Joinery version requirement check — show error badge if this plugin's
                // requires.joinery is not satisfied by the current Joinery version.
                if (!empty($plugin['requires_joinery'])) {
                    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
                    $jv = LibraryFunctions::get_joinery_version();
                    $req = $plugin['requires_joinery'];
                    $op = '>='; $ver = $req;
                    if (preg_match('/^([><=]+)(.+)$/', $req, $rm)) { $op = $rm[1]; $ver = $rm[2]; }
                    $req_ok = ($jv !== '' && version_compare($jv, $ver, $op));
                    if (!$req_ok) {
                        $status_cell .= '<br><span class="badge bg-danger">Requires Joinery ' . htmlspecialchars($req) . ' — this site is ' . htmlspecialchars($jv ?: 'unknown') . '</span>';
                    }
                }

                if (!$plugin['directory_exists']) {
                    $status_cell .= '<br><small class="text-warning"><i class="fas fa-exclamation-triangle"></i> Directory missing</small>';
                }

                // Show install error if any
                if ($plugin['plugin'] && $plugin['plugin']->get('plg_install_error')) {
                    $error_msg = htmlspecialchars($plugin['plugin']->get('plg_install_error'));
                    $status_cell .= '<br><div class="alert alert-danger alert-sm p-1 mt-1 mb-0" style="font-size: 0.8em;">';
                    $status_cell .= '<i class="fas fa-exclamation-circle"></i> <strong>Error:</strong><br>';
                    $status_cell .= '<span class="text-wrap" style="word-break: break-word;">' . $error_msg . '</span>';
                    $status_cell .= '</div>';
                }

                // Warn if an active plugin's settings are all still blank or
                // at their factory default — a plugin declares settings, so
                // having any is what makes the check meaningful.
                if (
                    $plugin['plugin'] &&
                    $plugin['plugin']->get('plg_status') === 'active'
                ) {
                    try {
                        $ph = PluginHelper::getInstance($plugin['name']);
                        $declared = $ph->getDeclaredSettings();
                        if (!empty($declared)) {
                            $all_default = true;
                            foreach ($declared as $s) {
                                $current = $settings->get_setting($s['name'], true, true);
                                $default = $s['default'] ?? '';
                                if ($current !== '' && $current !== null && (string)$current !== (string)$default) {
                                    $all_default = false;
                                    break;
                                }
                            }
                            if ($all_default) {
                                $status_cell .= '<br><div class="alert alert-warning p-1 mt-1 mb-0" style="font-size: 0.8em;">';
                                $status_cell .= '⚠ <a href="/admin/admin_settings#plugin-settings">Settings not configured</a>';
                                $status_cell .= '</div>';
                            }
                        }
                    } catch (Exception $e) {
                        // Skip if plugin helper unavailable
                    }
                }

                // Provisioning setup indicator — resolved asynchronously by
                // the script at the bottom of the page.
                if (in_array($plugin['name'], $provisioning_plugins, true)) {
                    $status_cell .= '<div class="plugin-provisioning" data-provisioning-plugin="'
                        . htmlspecialchars($plugin['name']) . '">';
                    $status_cell .= '<span class="prov-badge prov-unknown">Checking setup&hellip;</span>';
                    $status_cell .= '</div>';
                }

                array_push($rowvalues, $status_cell);

                // Actions column
                if ($plugin['directory_exists']) {
                    $plugin_name = htmlspecialchars($plugin['name']);
                    $plugin_status = $plugin['plugin'] ? $plugin['plugin']->get('plg_status') : null;

                    // Check if this plugin is the active theme provider
                    $is_active_theme_provider = false;
                    try {

                        $plugin_helper = PluginHelper::getInstance($plugin['name']);
                        $is_active_theme_provider = $plugin_helper->isActiveThemeProvider();
                    } catch (Exception $e) {
                        // Plugin helper not available
                    }

                    // Build actions array
                    $actions = array();

                    $uninstall_warning = "This will drop all of this plugin\\'s tables, delete its data, and remove all plugin files from disk. This cannot be undone.";

                    if (!$plugin['plugin'] || !$plugin_status) {
                        // Not installed (no database record) — files on disk, awaiting install.
                        // Post-uninstall lands here too, since uninstall removes the row.
                        $actions['Install'] = "javascript:submitPluginAction('install', '$plugin_name')";
                    } elseif ($plugin_status === 'active') {
                        $actions['Deactivate'] = "javascript:submitPluginAction('deactivate', '$plugin_name')";
                    } elseif ($plugin_status === 'inactive' || $plugin_status === 'installed' || $plugin_status === 'stale') {
                        $actions['Activate'] = "javascript:submitPluginAction('activate', '$plugin_name')";
                        if (!$is_active_theme_provider) {
                            $actions['Uninstall'] = "javascript:confirmPluginAction('uninstall', '$plugin_name', '$uninstall_warning')";
                        }
                    } elseif ($plugin_status === 'error') {
                        $actions['Repair'] = "javascript:submitPluginAction('repair_plugin', '$plugin_name')";
                        if (!$is_active_theme_provider) {
                            $actions['Uninstall'] = "javascript:confirmPluginAction('uninstall', '$plugin_name', '$uninstall_warning')";
                        }
                    }

                    if (!empty($actions)) {
                        $action_cell = '<div class="dropdown">';
                        $action_cell .= '<button class="btn btn-soft-default dropdown-toggle" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Actions</button>';
                        $action_cell .= '<div class="dropdown-menu dropdown-menu-end py-0">';
                        foreach ($actions as $label => $action) {
                            $action_cell .= '<a href="' . $action . '" class="dropdown-item">' . $label . '</a>';
                        }
                        // Add disabled uninstall option with explanation for active theme providers
                        if ($is_active_theme_provider && ($plugin_status === 'inactive' || $plugin_status === 'installed' || $plugin_status === 'error')) {
                            $action_cell .= '<a href="#" class="dropdown-item disabled" onclick="return false;" title="Cannot uninstall active theme provider">';
                            $action_cell .= '<span class="text-muted">Uninstall (Active Theme)</span>';
                            $action_cell .= '</a>';
                        }
                        $action_cell .= '</div>';
                        $action_cell .= '</div>';
                    } else {
                        $action_cell = '<span class="text-muted">No actions</span>';
                    }

                    array_push($rowvalues, $action_cell);
                } else {
                    array_push($rowvalues, '<em class="text-muted">N/A</em>');
                }

                // Display the row
                $page->disprow($rowvalues);
            }

            // End the table
            $page->endtable();
            ?>

            <?php
            // Count plugin statistics
            $active_count = 0;
            $inactive_count = 0;
            $missing_count = 0;
            $installed_count = 0;
            $not_installed_count = 0;
            $error_count = 0;

            foreach ($plugins as $plugin) {
                if (!$plugin['directory_exists']) {
                    $missing_count++;
                } else {
                    if ($plugin['plugin']) {
                        $installed_count++;
                        $status = $plugin['plugin']->get('plg_status');

                        // Check for install errors regardless of status
                        if ($plugin['plugin']->get('plg_install_error')) {
                            $error_count++;
                        } elseif ($status === 'active') {
                            $active_count++;
                        } else {
                            $inactive_count++;
                        }
                    } else {
                        $not_installed_count++;
                    }
                }
            }
            ?>

            <h5 class="mb-3 mt-4">Plugin Statistics</h5>

            <div class="row g-3">
                <div class="col-md-2">
                    <div class="text-center p-3 bg-light rounded">
                        <h5 class="text-success mb-1"><?php echo $active_count; ?></h5>
                        <p class="mb-0">Active</p>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="text-center p-3 bg-light rounded">
                        <h5 class="text-secondary mb-1"><?php echo $inactive_count; ?></h5>
                        <p class="mb-0">Inactive</p>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="text-center p-3 bg-light rounded">
                        <h5 class="text-info mb-1"><?php echo $not_installed_count; ?></h5>
                        <p class="mb-0">Not Installed</p>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="text-center p-3 bg-light rounded">
                        <h5 class="text-danger mb-1"><?php echo $error_count; ?></h5>
                        <p class="mb-0">Errors</p>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="text-center p-3 bg-light rounded">
                        <h5 class="text-warning mb-1"><?php echo $missing_count; ?></h5>
                        <p class="mb-0">Missing</p>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="text-center p-3 bg-light rounded">
                        <h5 class="text-primary mb-1"><?php echo count($plugins); ?></h5>
                        <p class="mb-0">Total</p>
                    </div>
                </div>
            </div>

        <?php endif; ?>

    </div>
</div>

<script>
function submitPluginAction(action, pluginName) {
    var form = document.createElement('form');
    form.method = 'post';
    form.style.display = 'none';

    var actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = action;
    form.appendChild(actionInput);

    var pluginInput = document.createElement('input');
    pluginInput.type = 'hidden';
    pluginInput.name = 'plugin_name';
    pluginInput.value = pluginName;
    form.appendChild(pluginInput);

    document.body.appendChild(form);
    form.submit();
}

function confirmPluginAction(action, pluginName, message) {
    JoineryModal.confirm(message, function() {
        submitPluginAction(action, pluginName);
    });
}
</script>

<?php if (!empty($provisioning_plugins)): ?>
<style>
.plugin-provisioning { margin-top: 4px; }
.prov-badge { display: inline-block; padding: 2px 8px; border-radius: 10px;
    font-size: 0.78em; font-weight: 600; cursor: pointer; }
.prov-verified  { background: #1e7e34; color: #fff; }
.prov-reachable { background: #0f8b8d; color: #fff; }
.prov-unmet     { background: #e0a800; color: #212529; }
.prov-error     { background: #c82333; color: #fff; }
.prov-unknown   { background: #6c757d; color: #fff; }
.prov-panel { margin-top: 6px; padding: 6px 8px; border: 1px solid #dee2e6;
    border-radius: 4px; background: #f8f9fa; font-size: 0.8em; }
.prov-item { padding: 4px 0; border-bottom: 1px solid #e9ecef; }
.prov-item:last-child { border-bottom: none; }
.prov-item-label { font-weight: 600; }
.prov-item-details { color: #6c757d; }
.prov-item-status { margin-top: 2px; }
.prov-item-unmet .prov-item-status { color: #9a7400; }
.prov-item-error .prov-item-status { color: #c82333; }
.prov-item-command { background: #212529; color: #f8f9fa; padding: 6px 8px;
    border-radius: 4px; margin: 4px 0 0; white-space: pre-wrap; word-break: break-all; }
</style>
<script>
(function() {
    var nodes = document.querySelectorAll('[data-provisioning-plugin]');
    if (!nodes.length) { return; }

    joineryApi.post('plugin_provisioning_check', {})
        .then(function(data) {
            if (!data || !data.plugins) { throw new Error('bad response'); }
            nodes.forEach(function(node) {
                var name = node.getAttribute('data-provisioning-plugin');
                render(node, data.plugins[name] || {});
            });
        })
        .catch(function() {
            nodes.forEach(function(node) {
                node.innerHTML = '<span class="prov-badge prov-unknown">Cannot check setup</span>';
            });
        });

    function render(node, provisionersMap) {
        var items = Object.keys(provisionersMap).map(function(k) { return provisionersMap[k]; });
        if (!items.length) { node.innerHTML = ''; return; }

        var states = items.map(function(i) { return i.state; });
        var roll = states.indexOf('error') !== -1 ? 'error'
                 : states.indexOf('unmet') !== -1 ? 'unmet'
                 : states.indexOf('reachable') !== -1 ? 'reachable'
                 : 'verified';

        var labels = {
            verified:  'Setup complete',
            reachable: 'Reachable — not fully verified',
            unmet:     'Needs setup',
            error:     'Check failed'
        };

        node.innerHTML = '';
        var badge = document.createElement('span');
        badge.className = 'prov-badge prov-' + roll;
        badge.textContent = labels[roll];
        badge.setAttribute('role', 'button');
        badge.tabIndex = 0;
        badge.title = 'Click for details';

        var panel = buildPanel(items);
        node.appendChild(badge);
        node.appendChild(panel);

        function toggle() {
            panel.style.display = (panel.style.display === 'none') ? 'block' : 'none';
        }
        badge.addEventListener('click', toggle);
        badge.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); }
        });
    }

    function buildPanel(items) {
        var panel = document.createElement('div');
        panel.className = 'prov-panel';
        panel.style.display = 'none';

        var seenCommands = {};
        items.forEach(function(it) {
            var row = document.createElement('div');
            row.className = 'prov-item prov-item-' + it.state;

            var label = document.createElement('div');
            label.className = 'prov-item-label';
            label.textContent = it.label;
            row.appendChild(label);

            if (it.details) {
                var details = document.createElement('div');
                details.className = 'prov-item-details';
                details.textContent = it.details;
                row.appendChild(details);
            }

            var status = document.createElement('div');
            status.className = 'prov-item-status';
            if (it.state === 'verified') {
                status.textContent = 'Verified';
            } else if (it.state === 'reachable') {
                status.textContent = 'Reachable (listening, not verified)';
            } else if (it.state === 'unmet') {
                status.textContent = 'Needs setup — ' + (it.reason || '');
            } else {
                status.textContent = 'Check failed — ' + (it.reason || '');
            }
            row.appendChild(status);

            if (it.state === 'unmet' && it.script_command && !seenCommands[it.script_command]) {
                seenCommands[it.script_command] = true;
                var cmd = document.createElement('pre');
                cmd.className = 'prov-item-command';
                cmd.textContent = it.script_command;
                row.appendChild(cmd);
            }
            panel.appendChild(row);
        });
        return panel;
    }
})();
</script>
<?php endif; ?>

<?php
$page->end_box();
$page->admin_footer();
?>