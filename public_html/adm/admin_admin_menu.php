<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/admin_menus_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/group_members_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(10);
	$session->set_return();

	if($_POST['action'] == 'remove'){
		$admin_menu = new AdminMenu($_POST['amu_admin_menu_id'], TRUE);
		$admin_menu->permanent_delete();
		header("Location: /admin/admin_admin_menu");
		exit();				
	}

	$admin_menu = MultiAdminMenu::getadminmenu(10, NULL, TRUE); 
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



	$headers = array("Menu", "Default Page", "Icon", "Order", "Action");
	$altlinks = array();
	$altlinks += array('Add Menu Item'=> '/admin/admin_admin_menu_edit');
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Admin Menus',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);	
				
	foreach ($admin_menu as $menu_id=>$menu_info){	
		$menu_obj = new AdminMenu($menu_id, TRUE);
		
		if(!$menu_info['parent']){
			
			$disablednote = '';
			if($menu_obj->get('amu_disable')){
				$disablednote = '<b>disabled</b>';
			}
			
			$rowvalues = array();
			array_push($rowvalues, $menu_info['display'] . ' ' . $disablednote);
			array_push($rowvalues, $menu_info['defaultpage']);
			array_push($rowvalues, $menu_info['icon']);
			array_push($rowvalues, $menu_obj->get('amu_order'));
			$delform = '<a href="/admin/admin_admin_menu_edit?amu_admin_menu_id='.$menu_id.'">edit</a>';
			array_push($rowvalues, $delform);
			$page->disprow($rowvalues);	
		}
		
		if($menu_info['has_subs']){	
			$rowvalues = array();
			foreach ($iterate_menu as $iterate_menu_id=>$iterate_menu_info){
				if($iterate_menu_info['parent'] == $menu_id){
					$menu_obj = new AdminMenu($iterate_menu_id, TRUE);
					
					$disablednote = '';
					if($menu_obj->get('amu_disable')){
						$disablednote = '<b>disabled</b>';
					}
					
					$rowvalues = array();
					array_push($rowvalues, '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;'.$iterate_menu_info['display']. ' ' .$disablednote);
					array_push($rowvalues, $iterate_menu_info['defaultpage']);
					array_push($rowvalues, $iterate_menu_info['icon']);
					array_push($rowvalues, $menu_obj->get('amu_order'));
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


