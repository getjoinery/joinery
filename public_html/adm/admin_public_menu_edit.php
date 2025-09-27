<?php
	
	PathHelper::requireOnce('/includes/AdminPage.php');
	
	PathHelper::requireOnce('/includes/LibraryFunctions.php');

	PathHelper::requireOnce('/data/public_menus_class.php');
	PathHelper::requireOnce('/data/pages_class.php');
	PathHelper::requireOnce('/data/page_contents_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	if (isset($_REQUEST['pmu_public_menu_id'])) {
		$public_menu = new PublicMenu($_REQUEST['pmu_public_menu_id'], TRUE);
	} else {
		$public_menu = new PublicMenu(NULL);
	}

	if($_POST){
		if(empty($_POST['pmu_link_choose']) && empty($_POST['pmu_link'])){
			throw new SystemDisplayableError('You must either choose a page from the drop down or type in a link.');
			exit();
		}
		
		if(!empty($_POST['pmu_link_choose'])){
			$public_menu->set('pmu_link', $_POST['pmu_link_choose']);
		}
		else{
			$public_menu->set('pmu_link', preg_replace("/[^a-zA-Z0-9-\/]/", "", $_POST['pmu_link']));
		}
		$public_menu->set('pmu_parent_menu_id', (int)$_POST['pmu_parent_menu_id']);
		$public_menu->set('pmu_order', (int)$_POST['pmu_order']);
		
		$editable_fields = array('pmu_name');

		foreach($editable_fields as $field) {
			$public_menu->set($field, $_POST[$field]);
		}

		$public_menu->prepare();
		$public_menu->save();
		LibraryFunctions::redirect('/admin/admin_public_menu');
		return;
	}

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> NULL,
		'breadcrumbs' => array(
			'PublicMenus'=>'/admin/admin_public_menus', 
			'New Public Menu' => '',
		),
		'session' => $session,
	)
	);	

	?>
	<script type="text/javascript">
	
		function set_pricing_choices(){
			var value = $("#pmu_link_choose").val();
			if(value == ''){  //ONE PRICE	
				$("#pmu_link_container").show();
			}	
			else{  //MULTIPLE PRICES
				$("#pmu_link_container").hide();				
			}		
		}

		$(document).ready(function() {
			set_pricing_choices();
			$("#pmu_link_choose").change(function() {	
				set_pricing_choices();
			});	
		});

	</script>
	<?php
	
	$pageoptions['title'] = "New Public Menu";
	$page->begin_box($pageoptions);

	// Editing an existing email
	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
	
	$validation_rules = array();
	$validation_rules['pmu_name']['required']['value'] = 'true';	
	//$validation_rules['pmu_link']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);	

	echo $formwriter->begin_form('form', 'POST', '/admin/admin_public_menu_edit');

	if($public_menu->key){
		echo $formwriter->hiddeninput('pmu_public_menu_id', $public_menu->key);
		echo $formwriter->hiddeninput('action', 'edit');
	}
	
	echo $formwriter->textinput('Menu name', 'pmu_name', NULL, 100, $public_menu->get('pmu_name'), '', 255, '');

	$search_criteria = array('deleted' => false);
	$pages = new MultiPage(
		$search_criteria,
		NULL,
		NULL,
		NULL);	
	$numrecords = $pages->count_all();	
	if($numrecords){
		$pages->load();
		$optionvals = $pages->get_dropdown_array_link();
		echo $formwriter->dropinput("Link existing page", "pmu_link_choose", "ctrlHolder", $optionvals, $public_menu->get('pmu_link'), '', TRUE);	
	}

	$settings = Globalvars::get_instance();
	$webDir = $settings->get_setting('webDir'); 
	
	echo $formwriter->textinput('Or type in a link ('.$webDir.')', 'pmu_link', NULL, 100, $public_menu->get('pmu_link'), '', 255, '');
	
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
	
	$page->end_box();

	$page->admin_footer();

?>
