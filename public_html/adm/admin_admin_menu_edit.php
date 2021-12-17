<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/admin_menus_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	if (isset($_REQUEST['amu_admin_menu_id'])) {
		$admin_menu = new AdminMenu($_REQUEST['amu_admin_menu_id'], TRUE);
	} else {
		$admin_menu = new AdminMenu(NULL);
	}

	if($_POST){

		if($_POST['amu_parent_menu_id']){
			$admin_menu->set('amu_parent_menu_id', $_POST['amu_parent_menu_id']);
		}
		else{
			$admin_menu->set('amu_parent_menu_id', NULL);
		}
		
		$editable_fields = array('amu_menudisplay', 'amu_defaultpage', 'amu_order', 'amu_min_permission', 'amu_icon', 'amu_disable');

		foreach($editable_fields as $field) {
			$admin_menu->set($field, $_REQUEST[$field]);
		}

		$admin_menu->prepare();
		$admin_menu->save();
		LibraryFunctions::redirect('/admin/admin_admin_menu');
		exit;
	}


	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> NULL,
		'breadcrumbs' => array(
			'Settings'=>'/admin/admin_settings', 
			'Admin Menus'=>'/admin/admin_admin_menus', 
			'New Admin Menu Item' => '',
		),
		'session' => $session,
	)
	);	

	
	$pageoptions['title'] = "New Admin Menu Item";
	$page->begin_box($pageoptions);

	// Editing an existing email
	$formwriter = new FormWriterMaster('form1');
	
	$validation_rules = array();
	$validation_rules['amu_menudisplay']['required']['value'] = 'true';	
	$validation_rules['amu_min_permission']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);	



	echo $formwriter->begin_form('form', 'POST', '/admin/admin_admin_menu_edit');

	if($admin_menu->key){
		echo $formwriter->hiddeninput('amu_admin_menu_id', $admin_menu->key);
		echo $formwriter->hiddeninput('action', 'edit');
	}
	
	echo $formwriter->textinput('Menu name', 'amu_menudisplay', NULL, 100, $admin_menu->get('amu_menudisplay'), '', 255, '');	
	echo $formwriter->textinput('Default page', 'amu_defaultpage', NULL, 100, $admin_menu->get('amu_defaultpage'), '', 255, '');
	
	$menulist = new MultiAdminMenu(
		array('has_no_parent_menu_id'=>true),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$menulist->load();
	$optionvals = $menulist->get_dropdown_array();
	echo $formwriter->dropinput("Parent Menu", "amu_parent_menu_id", "ctrlHolder", $optionvals, $admin_menu->get('amu_parent_menu_id'), '', TRUE);		
	
	echo $formwriter->textinput('Order', 'amu_order', NULL, 4, $admin_menu->get('amu_order'), '', 255, '');
	echo $formwriter->textinput('Minimum permission', 'amu_min_permission', NULL, 4, $admin_menu->get('amu_min_permission'), '', 255, '');
	
	$optionvals = array("Enabled"=>0, 'Disabled' => 1);
	echo $formwriter->dropinput("Enabled", "amu_disable", "ctrlHolder", $optionvals, $admin_menu->get('amu_disable'), '', FALSE);
	
	echo $formwriter->textinput('Icon name', 'amu_icon', NULL, 100, $admin_menu->get('amu_icon'), '', 255, '');

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();

	$page->admin_footer();

?>
