<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/plugins_class.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('includes/PluginManager.php'));

require_once(PathHelper::getIncludePath('adm/logic/admin_plugins_logic.php'));

$page_vars = process_logic(admin_plugins_logic($_GET, $_POST));

$session = SessionControl::get_instance();

$page = new AdminPage();
$message = $page_vars['message'];
$message_type = $page_vars['message_type'];
$system_health = $page_vars['system_health'];
$plugins = $page_vars['plugins'];

// Plugin data loaded successfully

// Plugin updates will be checked by the deployment system
// No need for version checking here anymore

// Build Options dropdown links
$altlinks = array();
$altlinks['Add New'] = '/admin/admin_plugins?show_upload=1';
$altlinks['Sync with Filesystem'] = '/admin/admin_plugins?action=sync';
$altlinks['Check for Updates'] = '/admin/admin_plugins?action=check_updates';

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
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
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

                // Show update available
                if (isset($plugin['update_available']) && $plugin['update_available']) {
                    $version_cell .= '<br><span class="badge bg-warning">Update: ' . htmlspecialchars($plugin['available_version']) . '</span>';
                }
                array_push($rowvalues, $version_cell);

                // Status column
                $status_cell = $plugin['status_badge'];

                // Add stock/custom badge
                if ($plugin['plugin']) {
                    $is_stock = $plugin['plugin']->is_stock();
                    if ($is_stock) {
                        $status_cell .= ' <span class="badge bg-info">Stock</span>';
                    } else {
                        $status_cell .= ' <span class="badge bg-warning">Custom</span>';
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

                    if (!$plugin['plugin'] || !$plugin_status) {
                        // Not installed
                        $actions['Install'] = "javascript:submitPluginAction('install', '$plugin_name')";
                    } elseif ($plugin_status === 'uninstalled') {
                        // Uninstalled - could be legacy plugin or failed installation
                        if ($plugin['plugin']->get('plg_install_error')) {
                            $actions['Repair'] = "javascript:submitPluginAction('repair_plugin', '$plugin_name')";
                        } else {
                            $actions['Install'] = "javascript:submitPluginAction('install', '$plugin_name')";
                        }
                    } elseif ($plugin_status === 'active') {
                        // Active
                        $actions['Deactivate'] = "javascript:submitPluginAction('deactivate', '$plugin_name')";
                    } elseif ($plugin_status === 'inactive' || $plugin_status === 'installed') {
                        // Inactive
                        $actions['Activate'] = "javascript:submitPluginAction('activate', '$plugin_name')";
                        // Only allow uninstall if not active theme provider
                        if (!$is_active_theme_provider) {
                            $actions['Uninstall'] = "javascript:confirmPluginAction('uninstall', '$plugin_name', 'Are you sure you want to uninstall this plugin?')";
                        }
                    } elseif ($plugin_status === 'error') {
                        // Error
                        $actions['Repair'] = "javascript:submitPluginAction('repair_plugin', '$plugin_name')";
                        // Only allow uninstall if not active theme provider
                        if (!$is_active_theme_provider) {
                            $actions['Uninstall'] = "javascript:confirmPluginAction('uninstall', '$plugin_name', 'Are you sure you want to uninstall this plugin?')";
                        }
                    }

                    if (!empty($actions)) {
                        $action_cell = '<div class="dropdown">';
                        $action_cell .= '<button class="btn btn-falcon-default dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Actions</button>';
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

            <?php if (!empty($plugin_updates)): ?>
            <div class="alert alert-warning mt-4">
                <h6 class="alert-heading"><i class="fas fa-download"></i> Updates Available</h6>
                <p class="mb-0">The following plugins have updates available:</p>
                <ul class="mb-0 mt-2">
                    <?php foreach ($plugin_updates as $name => $info): ?>
                        <li><strong><?php echo htmlspecialchars($name); ?></strong> - Version <?php echo htmlspecialchars($info['available_version']); ?> available (currently <?php echo htmlspecialchars($info['installed_version']); ?>)</li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

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

        <div class="card mt-4">
            <div class="card-header bg-body-tertiary">
                <h6 class="mb-0">Plugin Development Guidelines</h6>
            </div>
            <div class="card-body">
                <h6>Plugin Structure</h6>
                <p>Plugins should be created in <code>/plugins/[plugin-name]/</code> with the following structure:</p>
                <ul>
                    <li><code>plugin.json</code> - <strong>Required</strong> metadata file (name, description, version, author)</li>
                    <li><code>serve.php</code> - Optional custom routing</li>
                    <li><code>uninstall.php</code> - Optional uninstall script</li>
                    <li><code>adm/</code> - Admin interface files</li>
                    <li><code>data/</code> - Data model classes</li>
                    <li><code>logic/</code> - Business logic</li>
                    <li><code>views/</code> - Template files</li>
                    <li><code>migrations/</code> - Database migrations</li>
                </ul>

                <h6 class="mt-3">Plugin Lifecycle</h6>
                <ol>
                    <li><strong>Install</strong> - Creates database tables and runs migrations</li>
                    <li><strong>Activate</strong> - Enables plugin routing and functionality</li>
                    <li><strong>Deactivate</strong> - Disables plugin but keeps data</li>
                    <li><strong>Uninstall</strong> - Removes plugin data and tables</li>
                </ol>

                <h6 class="mt-3">Plugin.json Example</h6>
                <pre class="bg-light p-2 rounded"><code>{
    "name": "My Plugin",
    "description": "A sample plugin for demonstration",
    "version": "1.0.0",
    "author": "Plugin Developer",
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
            </div>
        </div>

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
    if (confirm(message)) {
        submitPluginAction(action, pluginName);
    }
}
</script>

<?php
$page->end_box();
$page->admin_footer();
?>