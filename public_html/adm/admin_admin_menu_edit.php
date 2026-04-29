<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/admin_menus_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	if (isset($_POST['edit_primary_key_value'])) {
		$admin_menu = new AdminMenu($_POST['edit_primary_key_value'], TRUE);
	} elseif (isset($_GET['amu_admin_menu_id'])) {
		$admin_menu = new AdminMenu($_GET['amu_admin_menu_id'], TRUE);
	} else {
		$admin_menu = new AdminMenu(NULL);
	}

	$is_new = empty($admin_menu->key);
	$existing_location = $admin_menu->get('amu_location');

	// Pick location: form value on POST, query string on create, existing on edit, default sidebar.
	if (!empty($_POST)) {
		$location = $_POST['amu_location'] ?? $existing_location ?? 'admin_sidebar';
	} elseif ($is_new) {
		$location = $_GET['location'] ?? 'admin_sidebar';
	} else {
		$location = $existing_location ?: 'admin_sidebar';
	}
	if (!in_array($location, AdminMenu::LOCATIONS, true)) {
		$location = 'admin_sidebar';
	}

	$is_user_dropdown = ($location === 'user_dropdown');

	if($_POST){

		if(!empty($_POST['amu_parent_menu_id'])){
			$admin_menu->set('amu_parent_menu_id', $_POST['amu_parent_menu_id']);
		}
		else{
			$admin_menu->set('amu_parent_menu_id', NULL);
		}

		$editable_fields = array('amu_menudisplay', 'amu_defaultpage', 'amu_order', 'amu_min_permission', 'amu_icon', 'amu_disable', 'amu_slug', 'amu_setting_activate');

		foreach($editable_fields as $field) {
			$admin_menu->set($field, $_POST[$field]);
		}

		// Location is settable only on create
		if ($is_new) {
			$admin_menu->set('amu_location', $location);
		}

		// Visibility: respect submitted value for user_dropdown rows; force 'in' for sidebar
		if ($location === 'user_dropdown') {
			$visibility = $_POST['amu_visibility'] ?? 'in';
			if (!in_array($visibility, AdminMenu::VISIBILITIES, true)) {
				$visibility = 'in';
			}
			$admin_menu->set('amu_visibility', $visibility);
		} else {
			$admin_menu->set('amu_visibility', 'in');
		}

		$admin_menu->prepare();
		$admin_menu->save();
		LibraryFunctions::redirect('/admin/admin_admin_menu?location=' . urlencode($location));
		return;
	}

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> NULL,
		'breadcrumbs' => array(
			'Settings'=>'/admin/admin_settings',
			'Admin Menus'=>'/admin/admin_admin_menu?location=' . urlencode($location),
			($is_new ? 'New Admin Menu Item' : 'Edit Admin Menu Item') => '',
		),
		'session' => $session,
	)
	);

	$pageoptions['title'] = $is_new ? 'New Admin Menu Item' : 'Edit Admin Menu Item';
	$page->begin_box($pageoptions);

	// Plugin-managed warning
	$slug = $admin_menu->get('amu_slug');
	if ($slug && strpos($slug, 'core-') !== 0) {
		// Check whether any plugin currently claims this slug in its plg_metadata._menu_slugs.
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare("SELECT plg_name, plg_metadata FROM plg_plugins WHERE plg_metadata IS NOT NULL");
		$q->execute();
		$claiming_plugin = null;
		while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
			$meta = json_decode($row['plg_metadata'], true);
			if (is_array($meta) && !empty($meta['_menu_slugs']) && in_array($slug, $meta['_menu_slugs'], true)) {
				$claiming_plugin = $row['plg_name'];
				break;
			}
		}
		if ($claiming_plugin) {
			echo '<div class="alert alert-warning">This menu item is managed by plugin <b>' . htmlspecialchars($claiming_plugin) . '</b>. Edits here will be overwritten on the plugin\'s next sync.</div>';
		}
	}
	if ($slug && strpos($slug, 'core-') === 0) {
		echo '<div class="alert alert-info">Core menu item — disable / rename / reorder is allowed; deletion is not.</div>';
	}

	$formwriter = $page->getFormWriter('form1', [
		'model' => $admin_menu,
		'edit_primary_key_value' => $admin_menu->key
	]);

	echo $formwriter->begin_form();

	// Location: dropdown on create, read-only label on edit
	if ($is_new) {
		$formwriter->dropinput('amu_location', 'Location', [
			'options' => ['admin_sidebar' => 'Admin Sidebar', 'user_dropdown' => 'User Dropdown'],
			'value' => $location,
		]);
	} else {
		echo '<div class="form-group"><label>Location</label><div class="form-static">' . htmlspecialchars($location) . ' <em>(fixed after creation)</em></div></div>';
		echo '<input type="hidden" name="amu_location" value="' . htmlspecialchars($location) . '">';
	}

	$formwriter->textinput('amu_menudisplay', 'Menu name');
	$formwriter->textinput('amu_slug', 'Slug');
	$formwriter->textinput('amu_defaultpage', 'Page (Full path starting with /)');

	if ($location === 'admin_sidebar') {
		$menulist = new MultiAdminMenu(
			array('has_no_parent_menu_id'=>true),
			NULL, NULL, NULL);
		$menulist->load();
		$optionvals = $menulist->get_dropdown_array();
		$formwriter->dropinput('amu_parent_menu_id', 'Parent Menu', [
			'options' => $optionvals
		]);
	}

	$formwriter->textinput('amu_order', 'Order');
	$formwriter->textinput('amu_min_permission', 'Minimum permission');

	// Visibility — only meaningful on user_dropdown rows
	if ($is_user_dropdown) {
		$formwriter->dropinput('amu_visibility', 'Visibility', [
			'options' => [
				'in'   => 'Logged in only',
				'out'  => 'Logged out only',
				'both' => 'Both',
			]
		]);
	}

	$formwriter->dropinput('amu_disable', 'Enabled', [
		'options' => ['0' => 'Enabled', '1' => 'Disabled']
	]);

	$formwriter->textinput('amu_icon', 'Icon name');

	$formwriter->textinput('amu_setting_activate', 'Activate on setting (optional)');

	$formwriter->submitbutton('btn_submit', 'Submit');
	echo $formwriter->end_form();

	$page->admin_footer();

?>
