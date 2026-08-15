<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/settings_class.php'));

	require_once(PathHelper::getIncludePath('adm/logic/admin_settings_plugins_logic.php'));

	$page_vars = process_logic(admin_settings_plugins_logic(array_merge($_GET, $_POST)));

	$session = SessionControl::get_instance();
	$settings = Globalvars::get_instance();

	require_once(PathHelper::getIncludePath('includes/SettingsFieldRenderer.php'));
	$plugin_sources = $page_vars['plugin_sources'];
	$selected_plugin = $page_vars['selected_plugin'] ?? ($plugin_sources[0] ?? null);

	$plugin_label = function ($plugin) {
		return ucwords(str_replace('_', ' ', $plugin));
	};

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> NULL,
		'page_title' => 'Settings',
		'readable_title' => 'Settings',
		'breadcrumbs' => array(
			'Settings'=>'',
		),
		'session' => $session,
	)
	);

	$pageoptions['altlinks'] = array('Plugins'=>'/admin/admin_plugins');
	$pageoptions['altlinks'] += array('Public Menu'=>'/admin/admin_public_menu');
	$pageoptions['altlinks'] += array('Admin Menu'=>'/admin/admin_admin_menu');
	$pageoptions['altlinks'] += array('API Keys'=>'/admin/admin_api_keys');
	$pageoptions['altlinks'] += array('Upgrade'=>'/utils/upgrade');

	$pageoptions['title'] = "Settings";
	$page->begin_box($pageoptions);

	echo AdminPage::settings_tab_menu('Plugin Settings');

	if (empty($plugin_sources)) {
		echo '<p>No active plugin has settings to configure.</p>';
	}

	// One subtab per plugin; only the selected plugin's form renders. The
	// fields come from the plugin's declarations, so a plugin appears here
	// because it declares settings, not because it remembered to ship a form.
	if (!empty($plugin_sources)) {
		$subtabs = array();
		foreach ($plugin_sources as $plugin) {
			$subtabs[$plugin_label($plugin)] = '/admin/admin_settings_plugins?plugin=' . urlencode($plugin);
		}
		echo AdminPage::subtab_menu($subtabs, $plugin_label($selected_plugin));
	}

	if ($selected_plugin !== null) {
		$plugin = $selected_plugin;

		echo '<div class="plugin-settings-section" id="plugin-' . htmlspecialchars($plugin) . '">';
		echo '<h3>' . htmlspecialchars($plugin_label($plugin)) . ' Plugin</h3>';

		$formwriter = $page->getFormWriter('plugin_settings_' . $plugin);
		$formwriter->begin_form();

		// Names the plugin whose settings this save is allowed to write.
		$formwriter->hiddeninput('plugin_settings_target', '', ['value' => $plugin]);

		SettingsFieldRenderer::renderSource($formwriter, $plugin);

		$formwriter->submitbutton('submit_' . $plugin, 'Save ' . $plugin_label($plugin) . ' Settings');
		$formwriter->end_form();

		echo '</div>';
	}

	$page->end_box();

	$page->admin_footer();

?>
