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

	// One independent form per plugin, each with its own Save. A field one plugin
	// cannot validate must not be able to block saving another — which is why
	// these are siblings and not one page-wide form.
	//
	// The fields come from the plugin's declarations, so a plugin appears here
	// because it declares settings, not because it remembered to ship a form.
	foreach ($plugin_sources as $plugin) {

		echo '<div class="plugin-settings-section" id="plugin-' . htmlspecialchars($plugin) . '">';
		echo '<h3>' . htmlspecialchars(ucfirst($plugin)) . ' Plugin</h3>';

		$formwriter = $page->getFormWriter('plugin_settings_' . $plugin);
		$formwriter->begin_form();

		// Names the plugin whose settings this save is allowed to write.
		$formwriter->hiddeninput('plugin_settings_target', '', ['value' => $plugin]);

		SettingsFieldRenderer::renderSource($formwriter, $plugin);

		$formwriter->submitbutton('submit_' . $plugin, 'Save ' . ucfirst($plugin) . ' Settings');
		$formwriter->end_form();

		echo '</div>';
		echo '<hr>';
	}

	$page->end_box();

	$page->admin_footer();

?>
