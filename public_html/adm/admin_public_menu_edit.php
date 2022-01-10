<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/public_menus_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	if (isset($_REQUEST['pmu_public_menu_id'])) {
		$public_menu = new PublicMenu($_REQUEST['pmu_public_menu_id'], TRUE);
	} else {
		$public_menu = new PublicMenu(NULL);
	}

	if($_POST){
		$_POST['pmu_link'] = preg_replace("/[^a-zA-Z0-9-]/", "", $_POST['pmu_link']);
		$public_menu->set('pmu_parent_menu_id', (int)$_REQUEST['pmu_parent_menu_id']);
		$public_menu->set('pmu_order', (int)$_REQUEST['pmu_order']);
		
		$editable_fields = array('pmu_name', 'pmu_link');

		foreach($editable_fields as $field) {
			$public_menu->set($field, $_REQUEST[$field]);
		}

		$public_menu->prepare();
		$public_menu->save();
		LibraryFunctions::redirect('/admin/admin_public_menu');
		exit;
	}


	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> NULL,
		'breadcrumbs' => array(
			'PublicMenus'=>'/admin/admin_public_menus', 
			'New PublicMenu' => '',
		),
		'session' => $session,
	)
	);	

	
	$pageoptions['title'] = "New PublicMenu";
	$page->begin_box($pageoptions);

	// Editing an existing email
	$formwriter = new FormWriterMaster('form1');
	
	$validation_rules = array();
	$validation_rules['pmu_name']['required']['value'] = 'true';	
	$validation_rules['pmu_link']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);	



	echo $formwriter->begin_form('form', 'POST', '/admin/admin_public_menu_edit');

	if($public_menu->key){
		echo $formwriter->hiddeninput('pmu_public_menu_id', $public_menu->key);
		echo $formwriter->hiddeninput('action', 'edit');
	}
	
	echo $formwriter->textinput('Menu name', 'pmu_name', NULL, 100, $public_menu->get('pmu_name'), '', 255, '');	
	echo $formwriter->textinput('Menu link', 'pmu_link', NULL, 100, $public_menu->get('pmu_link'), '', 255, '');
	
	$menulist = new MultiPublicMenu(
		array('has_no_parent_menu_id'=>true),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$menulist->load();
	$optionvals = $menulist->get_dropdown_array();
	echo $formwriter->dropinput("Parent Menu", "pmu_parent_menu_id", "ctrlHolder", $optionvals, $public_menu->get('pmu_parent_menu_id'), '', TRUE);	
	
	echo $formwriter->textinput('Order', 'pmu_order', NULL, 4, $public_menu->get('pmu_order'), '', 255, '');

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();

	$page->admin_footer();

?>
