<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

// ErrorHandler.php no longer needed - using new ErrorManager system
PathHelper::requireOnce('includes/AdminPage.php');
PathHelper::requireOnce('includes/SessionControl.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('data/plugins_class.php');
PathHelper::requireOnce('data/users_class.php');
PathHelper::requireOnce('includes/PluginManager.php');

$session = SessionControl::get_instance();
$session->check_permission(10); // System admin only
$session->set_return();

$page = new AdminPage();
$message = '';
$message_type = '';

// Check if plugin system is properly set up
$system_health = null;
try {
    $repair = new PluginSystemRepair();
    $system_health = $repair->healthCheck();
} catch (Exception $e) {
    $system_health = [
        'overall_status' => 'error',
        'issues' => ['Failed to check system health: ' . $e->getMessage()],
        'recommendations' => ['Contact system administrator']
    ];
}

// Handle form submissions
if ($_POST) {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $plugin_name = isset($_POST['plugin_name']) ? $_POST['plugin_name'] : '';
    
    // Validate plugin name
    if (!$plugin_name || !Plugin::is_valid_plugin_name($plugin_name)) {
        $message = 'Invalid plugin name.';
        $message_type = 'danger';
    } else {
        try {
            if ($action === 'install') {
                // Get or create plugin record
                $plugin = Plugin::get_by_plugin_name($plugin_name);
                if (!$plugin) {
                    $plugin = new Plugin(null);
                    $plugin->set('plg_name', $plugin_name);
                }
                
                $result = $plugin->install();
                if ($result['success']) {
                    $message = implode('<br>', $result['messages']);
                    $message_type = 'success';
                } else {
                    $message = 'Installation failed:<br>' . implode('<br>', $result['errors']);
                    $message_type = 'danger';
                }
                
            } elseif ($action === 'activate') {
                // Get plugin record
                $plugin = Plugin::get_by_plugin_name($plugin_name);
                if (!$plugin) {
                    $message = 'Plugin must be installed first.';
                    $message_type = 'warning';
                } else {
                    try {
                        $plugin->activate();
                        $message = 'Plugin "' . htmlspecialchars($plugin_name) . '" activated successfully.';
                        $message_type = 'success';
                    } catch (Exception $activate_error) {
                        $message = 'Failed to activate plugin "' . htmlspecialchars($plugin_name) . '": ' . $activate_error->getMessage();
                        $message_type = 'danger';
                    }
                }
                
            } elseif ($action === 'deactivate') {
                $plugin = Plugin::get_by_plugin_name($plugin_name);
                if ($plugin) {
                    try {
                        $plugin->deactivate();
                        $message = 'Plugin "' . htmlspecialchars($plugin_name) . '" deactivated successfully.';
                        $message_type = 'success';
                    } catch (Exception $deactivate_error) {
                        $message = 'Failed to deactivate plugin "' . htmlspecialchars($plugin_name) . '": ' . $deactivate_error->getMessage();
                        $message_type = 'danger';
                    }
                } else {
                    $message = 'Plugin record not found.';
                    $message_type = 'warning';
                }
                
            } elseif ($action === 'uninstall') {
                $plugin = Plugin::get_by_plugin_name($plugin_name);
                if ($plugin) {
                    $result = $plugin->uninstall();
                    if ($result['success']) {
                        $message = implode('<br>', $result['messages']);
                        $message_type = 'success';
                    } else {
                        $message = 'Uninstall failed:<br>' . implode('<br>', $result['errors']);
                        $message_type = 'danger';
                    }
                } else {
                    $message = 'Plugin record not found.';
                    $message_type = 'warning';
                }
                
            } elseif ($action === 'check_updates') {
                $version_detector = new PluginVersionDetector();
                $updates = $version_detector->checkAllPlugins();
                if (empty($updates)) {
                    $message = 'All plugins are up to date.';
                    $message_type = 'info';
                } else {
                    $message = 'Updates available for: ' . implode(', ', array_keys($updates));
                    $message_type = 'warning';
                }
                
            } elseif ($action === 'repair_plugin') {
                // Run repair for specific plugin
                $plugin = Plugin::get_by_plugin_name($plugin_name);
                if (!$plugin) {
                    $message = 'Plugin record not found.';
                    $message_type = 'warning';
                } else {
                    // Clear the install error and reset status to allow retry
                    $plugin->set('plg_install_error', null);
                    $plugin->set('plg_status', 'inactive');
                    $plugin->save();
                    
                    // Run the installation process again
                    $result = $plugin->install();
                    if ($result['success']) {
                        $message = 'Plugin "' . htmlspecialchars($plugin_name) . '" repaired successfully:<br>' . implode('<br>', $result['messages']);
                        $message_type = 'success';
                    } else {
                        $message = 'Plugin repair failed:<br>' . implode('<br>', $result['errors']);
                        $message_type = 'danger';
                    }
                }
                
            } else {
                $message = 'Invalid action.';
                $message_type = 'danger';
            }
        } catch (Exception $e) {
            $message = 'Error: ' . htmlspecialchars($e->getMessage());
            $message_type = 'danger';
        }
    }
}

// Get all plugins with their status
$plugins = MultiPlugin::get_all_plugins_with_status();

// Plugin data loaded successfully

// Check for updates
$version_detector = new PluginVersionDetector();
$plugin_updates = array();
foreach ($plugins as &$plugin) {
    if ($plugin['directory_exists'] && $plugin['plugin']) {
        $update_info = $version_detector->checkForUpdate($plugin['name']);
        $plugin['update_available'] = $update_info['update_available'];
        $plugin['available_version'] = $update_info['available_version'];
        if ($update_info['update_available']) {
            $plugin_updates[$plugin['name']] = $update_info;
        }
    }
}
unset($plugin); // Critical: Unset the reference to prevent corruption

// Update checking completed

$page->admin_header(array(
    'menu-id' => 'plugins',
    'page_title' => 'Plugin Management',
    'readable_title' => 'Plugin Management',
    'breadcrumbs' => array(
        'Settings' => '/admin/admin_settings',
        'Plugins' => '',
    ),
    'session' => $session,
));
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
        
        <h5 class="mb-3">Available Plugins</h5>
        
        <?php if (empty($plugins)): ?>
            <div class="alert alert-warning">
                <strong>No plugins found.</strong> Create plugin directories in <code>/plugins/</code> to manage them here.
            </div>
        <?php else: ?>
            
            <?php
            // Set up table headers
            $headers = array('Plugin', 'Description', 'Version', 'Status', 'Actions');
            
            // Set up alt links for table header buttons
            $altlinks = array(
                'Check for Updates' => 'javascript:void(0);',
            );
            
            // Set up table options
            $table_options = array(
                'title' => 'Plugin Status Overview',
                'altlinks' => $altlinks
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
                    $action_cell = '<div class="btn-group" role="group">';
                    $formwriter = LibraryFunctions::get_formwriter_object('plugin_action_' . $plugin['name'], 'admin');
                    
                    // Get plugin status
                    $plugin_status = $plugin['plugin'] ? $plugin['plugin']->get('plg_status') : null;
                    
                    if (!$plugin['plugin'] || !$plugin_status) {
                        // Not installed - show Install button
                        $action_cell .= $formwriter->begin_form('plugin_install_' . $plugin['name'], 'POST', '', true);
                        $action_cell .= $formwriter->hiddeninput('action', 'install');
                        $action_cell .= $formwriter->hiddeninput('plugin_name', $plugin['name']);
                        $action_cell .= $formwriter->new_form_button('Install', 'btn btn-success btn-sm');
                        $action_cell .= $formwriter->end_form(true);
                        
                    } elseif ($plugin_status === 'uninstalled') {
                        // Uninstalled - could be legacy plugin or failed installation
                        if ($plugin['plugin']->get('plg_install_error')) {
                            // Has install error - show Repair button
                            $action_cell .= $formwriter->begin_form('plugin_repair_' . $plugin['name'], 'POST', '', true);
                            $action_cell .= $formwriter->hiddeninput('action', 'repair_plugin');
                            $action_cell .= $formwriter->hiddeninput('plugin_name', $plugin['name']);
                            $action_cell .= $formwriter->new_form_button('Repair', 'btn btn-warning btn-sm');
                            $action_cell .= $formwriter->end_form(true);
                        } else {
                            // No error - treat as fresh install
                            $action_cell .= $formwriter->begin_form('plugin_install_' . $plugin['name'], 'POST', '', true);
                            $action_cell .= $formwriter->hiddeninput('action', 'install');
                            $action_cell .= $formwriter->hiddeninput('plugin_name', $plugin['name']);
                            $action_cell .= $formwriter->new_form_button('Install', 'btn btn-success btn-sm');
                            $action_cell .= $formwriter->end_form(true);
                        }
                        
                    } elseif ($plugin_status === 'active') {
                        // Active - show Deactivate button
                        $action_cell .= $formwriter->begin_form('plugin_deactivate_' . $plugin['name'], 'POST', '', true);
                        $action_cell .= $formwriter->hiddeninput('action', 'deactivate');
                        $action_cell .= $formwriter->hiddeninput('plugin_name', $plugin['name']);
                        $action_cell .= $formwriter->new_form_button('Deactivate', 'btn btn-outline-secondary btn-sm');
                        $action_cell .= $formwriter->end_form(true);
                        
                    } elseif ($plugin_status === 'inactive' || $plugin_status === 'installed') {
                        // Inactive - show Activate and Uninstall buttons
                        $action_cell .= $formwriter->begin_form('plugin_activate_' . $plugin['name'], 'POST', '', true);
                        $action_cell .= $formwriter->hiddeninput('action', 'activate');
                        $action_cell .= $formwriter->hiddeninput('plugin_name', $plugin['name']);
                        $action_cell .= $formwriter->new_form_button('Activate', 'btn btn-primary btn-sm');
                        $action_cell .= $formwriter->end_form(true);
                        
                        $action_cell .= ' ';
                        
                        $action_cell .= $formwriter->begin_form('plugin_uninstall_' . $plugin['name'], 'POST', '', true);
                        $action_cell .= $formwriter->hiddeninput('action', 'uninstall');
                        $action_cell .= $formwriter->hiddeninput('plugin_name', $plugin['name']);
                        $action_cell .= $formwriter->new_form_button('Uninstall', 'btn btn-danger btn-sm', '', 'return confirm(\'Are you sure you want to uninstall this plugin?\');');
                        $action_cell .= $formwriter->end_form(true);
                        
                    } elseif ($plugin_status === 'error') {
                        // Error - show Repair and Uninstall buttons
                        $action_cell .= $formwriter->begin_form('plugin_repair_' . $plugin['name'], 'POST', '', true);
                        $action_cell .= $formwriter->hiddeninput('action', 'repair_plugin');
                        $action_cell .= $formwriter->hiddeninput('plugin_name', $plugin['name']);
                        $action_cell .= $formwriter->new_form_button('Repair', 'btn btn-warning btn-sm');
                        $action_cell .= $formwriter->end_form(true);
                        
                        $action_cell .= ' ';
                        
                        $action_cell .= $formwriter->begin_form('plugin_uninstall_' . $plugin['name'], 'POST', '', true);
                        $action_cell .= $formwriter->hiddeninput('action', 'uninstall');
                        $action_cell .= $formwriter->hiddeninput('plugin_name', $plugin['name']);
                        $action_cell .= $formwriter->new_form_button('Uninstall', 'btn btn-danger btn-sm', '', 'return confirm(\'Are you sure you want to uninstall this plugin?\');');
                        $action_cell .= $formwriter->end_form(true);
                        
                    }
                    
                    $action_cell .= '</div>';
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
// Handle "Check for Updates" button click
document.addEventListener('DOMContentLoaded', function() {
    // Find the Check for Updates link and make it submit the form
    const updateLink = document.querySelector('a[href="javascript:void(0);"]');
    if (updateLink && updateLink.textContent.includes('Check for Updates')) {
        updateLink.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Create and submit a form to check for updates
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = window.location.href;
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'check_updates';
            
            form.appendChild(actionInput);
            document.body.appendChild(form);
            form.submit();
        });
    }
});
</script>

<?php
$page->admin_footer();
?>