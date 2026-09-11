<?php

	require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('/data/public_menus_class.php'));
	require_once(PathHelper::getIncludePath('/data/group_members_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);
	$session->set_return();

	if($_POST['action'] == 'remove'){
		$public_menu = new PublicMenu($_POST['pmu_public_menu_id'], TRUE);
		$public_menu->permanent_delete();
		header("Location: /admin/admin_public_menu");
		exit();
	}

	$menus = MultiPublicMenu::get_sorted_array();
	$menus2 = $menus;

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> NULL,
		'page_title' => 'Public Menu',
		'readable_title' => 'Public Menu',
		'breadcrumbs' => array(
			'Settings'=>'/admin/admin_settings',
			'Public Menus' => '',
		),
		'session' => $session,
	)
	);

	$headers = array("Menu", "Submenus", "Order", "Action");
	$altlinks = array();
	$altlinks += array('Add Menu Item'=> '/admin/admin_public_menu_edit');
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Public Menus',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);

	foreach ($menus as $menu){
		if($menu['parent']){
			$rowvalues = array();
			array_push($rowvalues, $menu['name']);
			if(empty($submenus)){
				array_push($rowvalues, 'None');
			}
			else{
				array_push($rowvalues, count($submenus));
			}
			array_push($rowvalues, $menu['order']);

			$delform = AdminPage::action_button('Delete', '/admin/admin_public_menu', [
				'hidden'  => ['action' => 'remove', 'pmu_public_menu_id' => $menu['id']],
				'confirm' => 'Delete this menu item?',
			]);
				$delform .= ' <a href="/admin/admin_public_menu_edit?pmu_public_menu_id='.$menu['id'].'">edit</a>';
			array_push($rowvalues, $delform);
			$page->disprow($rowvalues);

			$submenus = $menu['submenu'];
			if(!empty($submenus)){
				foreach ($submenus as $submenu){
					$rowvalues = array();
					array_push($rowvalues, '-------->');
					array_push($rowvalues, $submenu['name']);
					array_push($rowvalues, $submenu['order']);
					$delform = AdminPage::action_button('Delete', '/admin/admin_public_menu', [
						'hidden'  => ['action' => 'remove', 'pmu_public_menu_id' => $submenu['id']],
						'confirm' => 'Delete this menu item?',
					]);
					$delform .= ' <a href="/admin/admin_public_menu_edit?pmu_public_menu_id='.$submenu['id'].'">edit</a>';
					array_push($rowvalues, $delform);
					$page->disprow($rowvalues);

				}
			}
		}
	}
	$page->endtable($pager);

	$page->admin_footer();
?>

