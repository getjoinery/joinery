<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/admin_menus_class.php'));
	require_once(PathHelper::getIncludePath('data/group_members_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);
	$session->set_return();

	// Active tab — controls which location is being viewed/managed.
	$location = $_GET['location'] ?? 'admin_sidebar';
	if (!in_array($location, AdminMenu::LOCATIONS, true)) {
		$location = 'admin_sidebar';
	}

	if(($_POST['action'] ?? '') == 'remove'){
		$admin_menu = new AdminMenu($_POST['amu_admin_menu_id'], TRUE);
		$slug = $admin_menu->get('amu_slug');
		// Refuse delete on core-* rows. Admins can disable / rename / reorder, not remove.
		if ($slug && strpos($slug, 'core-') === 0) {
			$session->save_message(new DisplayMessage(
				'Core menu items cannot be deleted. Disable the row instead.',
				'Delete refused',
				NULL,
				DisplayMessage::MESSAGE_ERROR
			));
			header("Location: /admin/admin_admin_menu?location=" . urlencode($location));
			exit();
		}
		$admin_menu->permanent_delete();
		header("Location: /admin/admin_admin_menu?location=" . urlencode($location));
		exit();
	}

	$admin_menu = MultiAdminMenu::getadminmenu(10, NULL, TRUE, $location);
	$iterate_menu = $admin_menu;

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> NULL,
		'page_title' => 'Admin Menu',
		'readable_title' => 'Admin Menu',
		'breadcrumbs' => array(
			'Settings'=>'/admin/admin_settings',
			'Admin Menus' => '',
		),
		'session' => $session,
	)
	);

	// Tabs — Admin Sidebar / User Dropdown
	$tabs = array(
		'Admin Sidebar' => '/admin/admin_admin_menu?location=admin_sidebar',
		'User Dropdown' => '/admin/admin_admin_menu?location=user_dropdown',
	);
	$current_tab = ($location === 'user_dropdown') ? 'User Dropdown' : 'Admin Sidebar';
	echo $page->tab_menu($tabs, $current_tab);

	$show_visibility_column = ($location === 'user_dropdown');
	$headers = array("Menu", "Default Page", "Icon", "Order");
	if ($show_visibility_column) {
		$headers[] = "Visibility";
	}
	$headers[] = "Owner";
	$headers[] = "Action";

	$altlinks = array();
	$altlinks += array('Add Menu Item'=> '/admin/admin_admin_menu_edit?location=' . urlencode($location));
	$pager = new Pager(array('numrecords'=>$numrecords ?? null, 'numperpage'=> $numperpage ?? null));
	$table_options = array(
		'altlinks' => $altlinks,
		'title' => 'Admin Menus — ' . $current_tab,
	);
	$page->tableheader($headers, $table_options, $pager);

	// Build slug → owner-plugin map from plg_metadata._menu_slugs.
	// Only slugs that the plugin actually claims are tagged as plugin-owned.
	$plugin_slug_owner = array();
	$dblink = DbConnector::get_instance()->get_db_link();
	$qm = $dblink->prepare("SELECT plg_name, plg_metadata FROM plg_plugins WHERE plg_metadata IS NOT NULL");
	$qm->execute();
	while ($row = $qm->fetch(PDO::FETCH_ASSOC)) {
		$meta = json_decode($row['plg_metadata'], true);
		if (is_array($meta) && !empty($meta['_menu_slugs'])) {
			foreach ($meta['_menu_slugs'] as $s) {
				$plugin_slug_owner[$s] = $row['plg_name'];
			}
		}
	}

	$owner_for_slug = function($slug) use ($plugin_slug_owner) {
		if (!$slug) return 'admin';
		if (strpos($slug, 'core-') === 0) return 'core';
		if (isset($plugin_slug_owner[$slug])) return 'plugin: ' . $plugin_slug_owner[$slug];
		return 'admin';
	};

	foreach ($admin_menu as $menu_id=>$menu_info){
		$menu_obj = new AdminMenu($menu_id, TRUE);

		if(!$menu_info['parent']){

			$disablednote = '';
			if($menu_obj->get('amu_disable')){
				$disablednote = '<b>disabled</b>';
			}

			$rowvalues = array();
			array_push($rowvalues, htmlspecialchars($menu_info['display']) . ' ' . $disablednote);
			array_push($rowvalues, htmlspecialchars($menu_info['defaultpage']));
			array_push($rowvalues, htmlspecialchars($menu_info['icon'] ?? ''));
			array_push($rowvalues, (int)$menu_obj->get('amu_order'));
			if ($show_visibility_column) {
				array_push($rowvalues, htmlspecialchars($menu_obj->get('amu_visibility') ?? ''));
			}
			$slug = $menu_obj->get('amu_slug');
			array_push($rowvalues, htmlspecialchars($owner_for_slug($slug)));
			$delform = '<a href="/admin/admin_admin_menu_edit?amu_admin_menu_id='.$menu_id.'">edit</a>';
			array_push($rowvalues, $delform);
			$page->disprow($rowvalues);
		}

		if($menu_info['has_subs']){
			foreach ($iterate_menu as $iterate_menu_id=>$iterate_menu_info){
				if($iterate_menu_info['parent'] == $menu_id){
					$child_obj = new AdminMenu($iterate_menu_id, TRUE);

					$disablednote = '';
					if($child_obj->get('amu_disable')){
						$disablednote = '<b>disabled</b>';
					}

					$rowvalues = array();
					array_push($rowvalues, '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;'.htmlspecialchars($iterate_menu_info['display']) . ' ' . $disablednote);
					array_push($rowvalues, htmlspecialchars($iterate_menu_info['defaultpage']));
					array_push($rowvalues, htmlspecialchars($iterate_menu_info['icon'] ?? ''));
					array_push($rowvalues, (int)$child_obj->get('amu_order'));
					if ($show_visibility_column) {
						array_push($rowvalues, htmlspecialchars($child_obj->get('amu_visibility') ?? ''));
					}
					$child_slug = $child_obj->get('amu_slug');
					array_push($rowvalues, htmlspecialchars($owner_for_slug($child_slug)));
					$delform = '<a href="/admin/admin_admin_menu_edit?amu_admin_menu_id='.$iterate_menu_id.'">edit</a>';
					array_push($rowvalues, $delform);
					$page->disprow($rowvalues);
				}
			}
		}
	}

	$page->endtable($pager);

	$page->admin_footer();
?>
