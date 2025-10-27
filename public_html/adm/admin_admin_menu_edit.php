<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/admin_menus_class.php'));

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
		
		$editable_fields = array('amu_menudisplay', 'amu_defaultpage', 'amu_order', 'amu_min_permission', 'amu_icon', 'amu_disable', 'amu_slug', 'amu_setting_activate');

		foreach($editable_fields as $field) {
			$admin_menu->set($field, $_POST[$field]);
		}

		$admin_menu->prepare();
		$admin_menu->save();
		LibraryFunctions::redirect('/admin/admin_admin_menu');
		return;
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

	// Editing an existing admin menu item
	$formwriter = $page->getFormWriter('form1', 'v2', [
		'model' => $admin_menu
	]);

	echo $formwriter->begin_form();

	if($admin_menu->key){
		$formwriter->hiddeninput('amu_admin_menu_id', ['value' => $admin_menu->key]);
		$formwriter->hiddeninput('action', ['value' => 'edit']);
	}

	$formwriter->textinput('amu_menudisplay', 'Menu name');
	$formwriter->textinput('amu_slug', 'Slug');
	$formwriter->textinput('amu_defaultpage', 'Page (Full path starting with /)');

	$menulist = new MultiAdminMenu(
		array('has_no_parent_menu_id'=>true),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$menulist->load();
	$optionvals = $menulist->get_dropdown_array();
	$formwriter->dropinput('amu_parent_menu_id', 'Parent Menu', [
		'options' => $optionvals
	]);

	$formwriter->textinput('amu_order', 'Order');
	$formwriter->textinput('amu_min_permission', 'Minimum permission');

	$formwriter->dropinput('amu_disable', 'Enabled', [
		'options' => ['Enabled' => 0, 'Disabled' => 1]
	]);

	$formwriter->textinput('amu_icon', 'Icon name');

	$formwriter->textinput('amu_setting_activate', 'Activate on setting (optional)');

	$formwriter->submitbutton('btn_submit', 'Submit');
	echo $formwriter->end_form();

	$page->admin_footer();

?>
