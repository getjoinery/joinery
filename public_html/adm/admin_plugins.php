<?php
/** @joinery-admin class=config */
// @version 1.8 - Host setup: each active plugin with a host installer shows its last install run and Run setup
//               again, which also replaces the sudo command on an unmet setup check
//               (plugin_installer_from_the_browser.md)
// @version 1.7 - Waiting for approval says why: a lapsed approval (access off) or a newer version asking for more
// @version 1.6 - a sandboxed plugin: the Sandboxed badge, Waiting for approval, Review and activate / What it may do
//               (admin_sandbox_approve) in place of Activate while it waits on an approval; never a fork
// @version 1.5 - the Edited since install badge naming the first changed file, and Keep my changes in place of Disable upgrade for an edited plugin (specs/local_edits_detected.md)
// @version 1.4 - Deactivate / Activate and the active count read plg_active (is_active()), never
//                plg_status: a running plugin whose status says 'stale' was offered Activate and Uninstall
// @version 1.3 - the replace panel for an upload of an installed name; the Local fork badge with the shipped version; Disable upgrade / Allow upgrade (specs/package_replace_on_upload.md WP2, WP3)
// @version 1.2 - the Uninstalled row: data removed, files being removed by the host, then Install (specs/post_release_fleet_defects.md B1)
// @version 1.1 - the Unsigned badge, the warning block and the request panel's hand-off (specs/package_signing.md WP6)

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
// name => the last host installer run (null: not run yet), for every active
// plugin with a host installer (PluginInstallerStatus).
$installer_status = $page_vars['installer_status'] ?? array();
// Run setup again queues a root request, which the API takes from a superadmin.
$can_run_installer = Access::permits(10, $session);
// What a plugin whose setup has not run yet is waiting for; read once.
$installer_next = RootRequest::actorState() === 'present'
    ? 'the server runs it shortly'
    : RootRequest::actorWarning();
// Names the upgrade source publishes; null when it was not asked or did not answer.
$published_plugins = $page_vars['published_plugins'] ?? null;
// Installing a plugin writes the code tree, which only root does here, so the
// page shows the queued request's progress rather than claiming it is finished
// (specs/read_only_tree.md).
$root_request_id = $page_vars['root_request_id'] ?? '';
$root_actor_notice = $page_vars['root_actor_notice'] ?? '';
// Root refused an uploaded package as not ours: the warning, and Install
// anyway (specs/package_signing.md WP6).
$unsigned_warning = $page_vars['unsigned_warning'] ?? null;
// An upload names an installed plugin: both copies side by side, Replace and
// Discard (specs/package_replace_on_upload.md).
$replace_panel = $page_vars['replace_panel'] ?? null;
// name => version the source ships, for the Local fork badge; null when unknown.
$fork_versions = $page_vars['fork_versions'] ?? null;
// name => the first file that differs from the signed listing, for a shipped
// plugin changed here since install (specs/local_edits_detected.md).
$edited = $page_vars['edited'] ?? array();
$edited_help = 'Changed on this site since Joinery shipped it (first difference: %s). The next deploy replaces it with the shipped version unless Keep my changes is pressed.';
$fork_help = 'Uploaded here. Deploys leave it alone, so it gets no updates from Joinery, including security fixes, until Allow upgrade is pressed.';

// Build Options dropdown links
$altlinks = array();
$altlinks['Sync with Filesystem'] = array('post' => '/admin/admin_plugins', 'hidden' => array('action' => 'sync_filesystem'));
$altlinks['Add New'] = '/admin/admin_marketplace';
$altlinks['Upload ZIP'] = '/admin/admin_plugins?show_upload=1';

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

        <?php echo $root_actor_notice; ?>
        <?php if ($unsigned_warning): ?>
            <?= PackageInstallPage::warning_html($unsigned_warning, '/admin/admin_plugins', $page) ?>
        <?php endif; ?>
        <?php if ($replace_panel): ?>
            <?= PackageInstallPage::replace_html($replace_panel, '/admin/admin_plugins', $page) ?>
        <?php endif; ?>
        <?php if ($root_request_id): ?>
            <?php echo AdminPage::root_request_panel($root_request_id,
                '/admin/admin_plugins?unsigned=' . rawurlencode($root_request_id)); ?>
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
                            Upload a ZIP of a plugin: a native one (with plugin.json), or a sandboxed one (manifest.json and one .wasm module).
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

                // Audience-scoped plugins are absent from other sites' marketplaces,
                // so the operator is told here instead.
                if (!empty($plugin['audience'])) {
                    $status_cell .= ' <span class="badge bg-info" title="Listed only for: '
                        . htmlspecialchars(implode(', ', $plugin['audience'])) . '">Unlisted</span>';
                }

                // A sandboxed plugin: never shipped by a release, so never a
                // fork, and never warned about (it can do only what an admin
                // approved). It says whether it waits on that approval.
                // With its files gone, the row's stored metadata still says.
                $is_sandboxed = $plugin['directory_exists']
                    ? SandboxPlugins::isSandboxed($plugin['name'])
                    : ($plugin['plugin'] && !empty(json_decode((string)$plugin['plugin']->get('plg_metadata'), true)['sandboxed']));
                $waits_on_approval = false;
                if ($is_sandboxed) {
                    $status_cell .= ' <span class="badge bg-info" title="A WebAssembly module that reaches the site only in the ways an admin approved.">Sandboxed</span>';
                    if ($plugin['directory_exists'] && $plugin['plugin'] && !$plugin['plugin']->is_uninstalled()) {
                        try {
                            $waits_on_approval = SandboxConsent::needsApproval($plugin['name']);
                        } catch (SandboxManifestException $e) {
                            $waits_on_approval = false;
                        }
                        if ($waits_on_approval && $plugin['plugin']->is_active()) {
                            // Two reasons it waits, and they leave it in different states.
                            $install = SandboxInstall::forPlugin($plugin['name']);
                            $wait_reason = ($install !== null && $install->get('sbi_consent_time') && SandboxConsent::approver($install) === null)
                                ? 'The admin who approved it can no longer give approval (demoted, disabled or deleted), so its access is off until an admin approves it again.'
                                : 'A newer version asks for more than was approved; until it is approved it keeps only what was.';
                            $status_cell .= ' <span class="badge bg-warning" title="' . htmlspecialchars($wait_reason) . '">Waiting for approval</span>';
                        }
                    }
                }

                // A local fork: the live manifest says receives_upgrades=false,
                // so deploys leave it alone. The badge says the cost, and how
                // far behind the shipped version it is when that is known.
                if ($plugin['plugin'] && !$is_sandboxed) {
                    if (!$plugin['plugin']->receives_upgrades()) {
                        $status_cell .= ' <span class="badge bg-warning" title="' . htmlspecialchars($fork_help) . '">Local fork</span>';
                        $shipped = is_array($fork_versions) ? (string)($fork_versions[$plugin['name']] ?? '') : '';
                        if ($shipped !== '' && $shipped !== (string)$plugin['version']) {
                            $status_cell .= '<br><small class="text-muted">shipped version is ' . htmlspecialchars($shipped) . '</small>';
                        }
                    }
                    // Shipped by Joinery and changed here since: the next deploy
                    // replaces it unless the operator keeps the changes.
                    if (isset($edited[$plugin['name']])) {
                        $status_cell .= ' <span class="badge bg-warning" title="' . htmlspecialchars(sprintf($edited_help, $edited[$plugin['name']])) . '">Edited since install</span>';
                    }
                    // Installed on the owner's acknowledgement of the warning:
                    // not built by Joinery, its host installer never runs, and
                    // the badge stays for as long as the row does.
                    if ((string)$plugin['plugin']->get('plg_trust') === 'unsigned') {
                        $status_cell .= ' <span class="badge bg-danger" title="Not built by Joinery; installed on a superadmin\'s acknowledgement of the warning. Its host installer is never run as root.">Unsigned</span>';
                    } elseif ((string)$plugin['plugin']->get('plg_trust') === 'unlogged') {
                        $status_cell .= ' <span class="badge bg-danger" title="Signed by Joinery but not in the public release log; installed on a superadmin\'s acknowledgement of the warning. Its host installer is never run as root.">Unlogged</span>';
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

                if ($plugin['plugin'] && $plugin['plugin']->is_uninstalled()) {
                    // The record uninstall leaves. Data is gone; the files are
                    // removed by the host on its next tick, and the request
                    // that asked for it is named so its transcript can be read.
                    if ($plugin['directory_exists']) {
                        $status_cell .= '<br><small class="text-muted">Data removed; files being removed by the host';
                        $remove_id = $plugin['plugin']->get_remove_request_id();
                        if ($remove_id !== '') {
                            $remove_state = RootRequest::status($remove_id);
                            $status_cell .= ' (request ' . htmlspecialchars($remove_state['state']);
                            if ($remove_state['state'] === 'failed') {
                                $status_cell .= ', exit ' . (int)$remove_state['exit_code'] . '; see logs/root_requests/' . htmlspecialchars($remove_id) . '.log';
                            }
                            $status_cell .= ')';
                        }
                        $status_cell .= '</small>';
                    } else {
                        $status_cell .= '<br><small class="text-muted">Data and files removed</small>';
                    }
                } elseif (!$plugin['directory_exists']) {
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

                // Host setup: what the plugin's installer did last time on this
                // server, and Run setup again.
                if (array_key_exists($plugin['name'], $installer_status)) {
                    $record = $installer_status[$plugin['name']];
                    $failed = $record !== null && PluginInstallerStatus::isFailure($record['outcome']);
                    $status_cell .= '<div class="plugin-host-setup' . ($failed ? ' plugin-host-setup-failed' : '') . '">';
                    $status_cell .= htmlspecialchars(PluginInstallerStatus::statusLine($record));
                    if ($record === null) {
                        $status_cell .= ' &mdash; ' . htmlspecialchars($installer_next);
                    }
                    if ($can_run_installer) {
                        $status_cell .= ' <button type="button" class="btn btn-sm btn-outline-secondary plugin-run-installer"'
                            . ' data-run-installer="' . htmlspecialchars($plugin['name']) . '">Run setup again</button>';
                    }
                    if ($failed && $record['tail'] !== '') {
                        $status_cell .= '<pre class="plugin-host-setup-log">' . htmlspecialchars($record['tail']) . '</pre>';
                    }
                    $status_cell .= '</div>';
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

                    $uninstall_warning = "This will drop all of this plugin\\'s tables and delete its data now. The host removes the plugin files within a few minutes. This cannot be undone.";

                    if ($plugin_status === Plugin::STATUS_UNINSTALLED) {
                        // Files still on disk: root is about to remove them, and
                        // an install racing that removal helps nobody. No action
                        // until the directory is gone.
                    } elseif (!$plugin['plugin'] || !$plugin_status) {
                        // Not installed (no database record) — files on disk, awaiting install.
                        // Post-uninstall lands here too, since uninstall removes the row.
                        $actions['Install'] = "javascript:submitPluginAction('install', '$plugin_name')";
                    } else {
                        // On or off is plg_active (is_active()). plg_status is a
                        // lifecycle note: 'stale' or 'error' can sit on a plugin
                        // that is switched on and running.
                        if ($plugin_status === 'error') {
                            $actions['Repair'] = "javascript:submitPluginAction('repair_plugin', '$plugin_name')";
                        }
                        if ($plugin['plugin']->is_active()) {
                            $actions['Deactivate'] = "javascript:submitPluginAction('deactivate', '$plugin_name')";
                            if ($is_sandboxed) {
                                $actions[$waits_on_approval ? 'Review new permissions' : 'What it may do']
                                    = htmlspecialchars(SandboxPluginManager::approvalUrl($plugin['name']));
                                $actions['Call log'] = htmlspecialchars('/admin/admin_sandbox_log?plugin=' . rawurlencode($plugin['name']));
                            }
                        } else {
                            if ($is_sandboxed && $waits_on_approval) {
                                // Activation is refused until approved: the
                                // review page approves and activates in one.
                                $actions['Review and activate'] = htmlspecialchars(SandboxPluginManager::approvalUrl($plugin['name']));
                            } elseif ($plugin_status !== 'error') {
                                $actions['Activate'] = "javascript:submitPluginAction('activate', '$plugin_name')";
                            }
                            if (!$is_active_theme_provider) {
                                $actions['Uninstall'] = "javascript:confirmPluginAction('uninstall', '$plugin_name', '$uninstall_warning')";
                            }
                        }
                    }

                    // The fork mark, for any plugin with a row. Allow upgrade
                    // ends a fork, and the next deploy replaces the files, so
                    // it asks first; Disable upgrade starts one and asks nothing.
                    if ($plugin['plugin'] && $plugin_status !== Plugin::STATUS_UNINSTALLED && !$is_sandboxed) {
                        if (isset($edited[$plugin['name']])) {
                            // The same mark as Disable upgrade, offered as what
                            // it means for an edited plugin, with its cost.
                            $keep_warning = "Deploys will leave $plugin_name alone, so it gets no updates from Joinery, including security fixes, until Allow upgrade is pressed.";
                            $actions['Keep my changes'] = "javascript:confirmPluginAction('mark_preserved', '$plugin_name', '$keep_warning')";
                        } elseif ($plugin['plugin']->receives_upgrades()) {
                            $actions['Disable upgrade'] = "javascript:submitPluginAction('mark_preserved', '$plugin_name')";
                        } else {
                            $allow_warning = "The next deploy will replace your copy of $plugin_name with the version Joinery ships. Your copy is not kept.";
                            $actions['Allow upgrade'] = "javascript:confirmPluginAction('mark_upgradable', '$plugin_name', '$allow_warning')";
                        }
                    }

                    if ($plugin_status === Plugin::STATUS_UNINSTALLED) {
                        $action_cell = '<span class="text-muted">Files being removed</span>';
                    } elseif (!empty($actions)) {
                        $action_cell = '<div class="dropdown">';
                        $action_cell .= '<button class="btn btn-soft-default dropdown-toggle" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Actions</button>';
                        $action_cell .= '<div class="dropdown-menu dropdown-menu-end py-0">';
                        foreach ($actions as $label => $action) {
                            $action_cell .= '<a href="' . $action . '" class="dropdown-item">' . $label . '</a>';
                        }
                        // Add disabled uninstall option with explanation for active theme providers
                        if ($is_active_theme_provider && $plugin['plugin'] && !$plugin['plugin']->is_active()) {
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
                } elseif ($plugin['plugin'] && $plugin['plugin']->is_uninstalled()) {
                    // Files gone: the one action is Install, the same root
                    // request that installs from the upgrade source - offered
                    // only while the source publishes the plugin.
                    $plugin_name = htmlspecialchars($plugin['name']);
                    if ($published_plugins === null || in_array($plugin['name'], $published_plugins, true)) {
                        $action_cell = '<div class="dropdown">';
                        $action_cell .= '<button class="btn btn-soft-default dropdown-toggle" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Actions</button>';
                        $action_cell .= '<div class="dropdown-menu dropdown-menu-end py-0">';
                        $action_cell .= '<a href="javascript:submitPluginAction(\'install\', \'' . $plugin_name . '\')" class="dropdown-item">Install</a>';
                        $action_cell .= '</div></div>';
                    } else {
                        $action_cell = '<span class="text-muted">Not published by the upgrade source</span>';
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
                if ($plugin['plugin'] && $plugin['plugin']->is_uninstalled()) {
                    // A record, not an installed plugin and not a missing one.
                    continue;
                }
                if (!$plugin['directory_exists']) {
                    $missing_count++;
                } else {
                    if ($plugin['plugin']) {
                        $installed_count++;
                        // Check for install errors regardless of status
                        if ($plugin['plugin']->get('plg_install_error')) {
                            $error_count++;
                        } elseif ($plugin['plugin']->is_active()) {
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

// Run setup again: queue the plugin's host installer as a root request, then
// show that request's panel. A second press while it waits returns the same one.
document.addEventListener('click', function(e) {
    var button = e.target.closest('[data-run-installer]');
    if (!button) { return; }
    e.preventDefault();
    button.disabled = true;
    button.textContent = 'Queuing…';
    joineryApi.post('plugin_installer_run', { plugin: button.getAttribute('data-run-installer') })
        .then(function(data) {
            if (!data || !data.request_id) { throw new Error('no request'); }
            window.location = '/admin/admin_plugins?installer_request=' + encodeURIComponent(data.request_id);
        })
        .catch(function(err) {
            button.disabled = false;
            button.textContent = 'Run setup again';
            JoineryModal.alert('Could not queue the setup run' + (err && err.message ? ': ' + err.message : '.'));
        });
});
</script>
<style>
.plugin-host-setup { margin-top: 4px; font-size: 0.8em; color: #495057; }
.plugin-host-setup-failed { color: #c82333; }
.plugin-host-setup .plugin-run-installer { font-size: 0.95em; padding: 1px 8px; margin-left: 4px; }
.plugin-host-setup-log { background: #212529; color: #f8f9fa; padding: 6px 8px; border-radius: 4px;
    margin: 4px 0 0; max-height: 14em; overflow: auto; white-space: pre-wrap; word-break: break-word; }
</style>

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
</style>
<script>
(function() {
    var nodes = document.querySelectorAll('[data-provisioning-plugin]');
    if (!nodes.length) { return; }
    var canRunInstaller = <?php echo $can_run_installer ? 'true' : 'false'; ?>;

    joineryApi.post('plugin_provisioning_check', {})
        .then(function(data) {
            if (!data || !data.plugins) { throw new Error('bad response'); }
            nodes.forEach(function(node) {
                var name = node.getAttribute('data-provisioning-plugin');
                render(node, name, data.plugins[name] || {});
            });
        })
        .catch(function() {
            nodes.forEach(function(node) {
                node.innerHTML = '<span class="prov-badge prov-unknown">Cannot check setup</span>';
            });
        });

    function render(node, pluginName, provisionersMap) {
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

        var panel = buildPanel(pluginName, items);
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

    function buildPanel(pluginName, items) {
        var panel = document.createElement('div');
        panel.className = 'prov-panel';
        panel.style.display = 'none';

        var offeredRun = false;
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

            // The plugin's own installer fixes this one: offer it once per panel.
            if (it.state === 'unmet' && it.fixed_by_installer && canRunInstaller && !offeredRun) {
                offeredRun = true;
                var run = document.createElement('button');
                run.type = 'button';
                run.className = 'btn btn-sm btn-outline-secondary plugin-run-installer';
                run.setAttribute('data-run-installer', pluginName);
                run.textContent = 'Run setup again';
                row.appendChild(run);
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