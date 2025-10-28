<?php

	require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('/data/public_menus_class.php'));
	require_once(PathHelper::getIncludePath('/data/pages_class.php'));
	require_once(PathHelper::getIncludePath('/data/page_contents_class.php'));

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

	// Editing an existing public menu
	$formwriter = $page->getFormWriter('form1', 'v2', [
		'model' => $public_menu,
		'edit_primary_key_value' => $public_menu->key
	]);

	$formwriter->begin_form();

	$formwriter->textinput('pmu_name', 'Menu name', [
		'validation' => ['required' => true]
	]);

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
		$formwriter->dropinput("pmu_link_choose", "Link existing page", [
			'options' => $optionvals
		]);
	}

	$settings = Globalvars::get_instance();
	$webDir = $settings->get_setting('webDir');

	$formwriter->textinput('pmu_link', 'Or type in a link', [
		'prepend' => $webDir
	]);

	$menulist = new MultiPublicMenu(
		array('has_no_parent_menu_id'=>true),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$menulist->load();
	$optionvals = $menulist->get_dropdown_array();
	$formwriter->dropinput("pmu_parent_menu_id", "Parent Menu", [
		'options' => $optionvals
	]);

	$formwriter->textinput('pmu_order', 'Order');

	$formwriter->submitbutton('btn_submit', 'Submit');
	$formwriter->end_form();

	$page->end_box();

	$page->admin_footer();

?>
