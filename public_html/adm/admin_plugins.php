<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/ErrorHandler.php');
PathHelper::requireOnce('includes/AdminPage.php');
PathHelper::requireOnce('includes/SessionControl.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('data/plugins_class.php');
PathHelper::requireOnce('data/users_class.php');

$session = SessionControl::get_instance();
$session->check_permission(10); // System admin only
$session->set_return();

$page = new AdminPage();
$message = '';
$message_type = '';

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
            if ($action === 'activate') {
                // Get or create plugin record
                $plugin = Plugin::get_by_plugin_name($plugin_name);
                if (!$plugin) {
                    $plugin = new Plugin(null);
                    $plugin->set('plg_name', $plugin_name);
                }
                
                try {
                    $plugin->activate();
                    $message = 'Plugin "' . htmlspecialchars($plugin_name) . '" activated successfully.';
                    $message_type = 'success';
                } catch (Exception $activate_error) {
                    $message = 'Failed to activate plugin "' . htmlspecialchars($plugin_name) . '": ' . $activate_error->getMessage();
                    $message_type = 'danger';
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
            
            <div class="card">
                <div class="card-header bg-body-tertiary">
                    <h6 class="mb-0">Plugin Status Overview</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Plugin</th>
                                    <th>Description</th>
                                    <th>Version</th>
                                    <th>Status</th>
                                    <th>Activation Time</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($plugins as $plugin): ?>
                                    <tr<?php if (!$plugin['directory_exists']): ?> class="table-warning"<?php endif; ?>>
                                        <td>
                                            <strong><?php echo htmlspecialchars($plugin['display_name']); ?></strong>
                                            <?php if ($plugin['display_name'] !== $plugin['name']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($plugin['name']); ?></small>
                                            <?php endif; ?>
                                            <?php if ($plugin['author']): ?>
                                                <br><small class="text-muted">by <?php echo htmlspecialchars($plugin['author']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($plugin['description']): ?>
                                                <?php echo htmlspecialchars($plugin['description']); ?>
                                            <?php else: ?>
                                                <em class="text-muted">No description available</em>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($plugin['version']): ?>
                                                <code><?php echo htmlspecialchars($plugin['version']); ?></code>
                                            <?php else: ?>
                                                <em class="text-muted">Unknown</em>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo $plugin['status_badge']; ?>
                                            <?php if (!$plugin['directory_exists']): ?>
                                                <br><small class="text-warning"><i class="fas fa-exclamation-triangle"></i> Directory missing</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            if ($plugin['plugin'] && $plugin['plugin']->get('plg_activated_time')) {
                                                echo $plugin['plugin']->get_timezone_corrected_time('plg_activated_time', $session, 'M j, Y g:i A');
                                            } else {
                                                echo '<em class="text-muted">Never activated</em>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php if ($plugin['directory_exists']): ?>
                                                <?php
                                                $formwriter = LibraryFunctions::get_formwriter_object('plugin_action_' . $plugin['name'], 'admin');
                                                
                                                if ($plugin['is_active']):
                                                    echo $formwriter->begin_form('plugin_action_' . $plugin['name'], 'POST', '', true);
                                                    echo $formwriter->hiddeninput('action', 'deactivate');
                                                    echo $formwriter->hiddeninput('plugin_name', $plugin['name']);
                                                    echo $formwriter->new_form_button('Deactivate', 'btn btn-outline-secondary btn-sm');
                                                    echo $formwriter->end_form(true);
                                                else:
                                                    echo $formwriter->begin_form('plugin_action_' . $plugin['name'], 'POST', '', true);
                                                    echo $formwriter->hiddeninput('action', 'activate');
                                                    echo $formwriter->hiddeninput('plugin_name', $plugin['name']);
                                                    echo $formwriter->new_form_button('Activate', 'btn btn-primary btn-sm');
                                                    echo $formwriter->end_form(true);
                                                endif;
                                                ?>
                                            <?php else: ?>
                                                <em class="text-muted">N/A</em>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <?php
            // Count active/inactive plugins
            $active_count = 0;
            $inactive_count = 0;
            $missing_count = 0;
            
            foreach ($plugins as $plugin) {
                if (!$plugin['directory_exists']) {
                    $missing_count++;
                } elseif ($plugin['is_active']) {
                    $active_count++;
                } else {
                    $inactive_count++;
                }
            }
            ?>
            
            <h5 class="mb-3 mt-4">Plugin Statistics</h5>
            
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="text-center p-3 bg-light rounded">
                        <h5 class="text-success mb-1"><?php echo $active_count; ?></h5>
                        <p class="mb-0">Active Plugins</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-center p-3 bg-light rounded">
                        <h5 class="text-secondary mb-1"><?php echo $inactive_count; ?></h5>
                        <p class="mb-0">Inactive Plugins</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-center p-3 bg-light rounded">
                        <h5 class="text-warning mb-1"><?php echo $missing_count; ?></h5>
                        <p class="mb-0">Missing Directories</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-center p-3 bg-light rounded">
                        <h5 class="text-primary mb-1"><?php echo count($plugins); ?></h5>
                        <p class="mb-0">Total Plugins</p>
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
                    <li><code>plugin.json</code> - Optional metadata file (name, description, version, author)</li>
                    <li><code>serve.php</code> - Optional custom routing</li>
                    <li><code>adm/</code> - Admin interface files</li>
                    <li><code>data/</code> - Data model classes</li>
                    <li><code>logic/</code> - Business logic</li>
                    <li><code>views/</code> - Template files</li>
                    <li><code>migrations/</code> - Database migrations</li>
                </ul>
                
                <h6 class="mt-3">Plugin.json Example</h6>
                <pre class="bg-light p-2 rounded"><code>{
    "name": "My Plugin",
    "description": "A sample plugin for demonstration",
    "version": "1.0.0",
    "author": "Plugin Developer",
    "requires": {
        "php": ">=7.4",
        "joinery": ">=1.0"
    }
}</code></pre>
            </div>
        </div>
        
    </div>
</div>

<?php
$page->admin_footer();
?>