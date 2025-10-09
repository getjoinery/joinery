<?php
	
	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/products_class.php'));
	require_once(PathHelper::getIncludePath('data/product_requirements_class.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));
	require_once(PathHelper::getIncludePath('data/questions_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return(); 

	if (isset($_REQUEST['prq_product_requirement_id'])) {
		$product_requirement = new ProductRequirement($_REQUEST['prq_product_requirement_id'], TRUE);
	} else {
		$product_requirement = new ProductRequirement(NULL);
	}

	if ($_POST || $_POST['action']) {
		
		if ($_POST['action'] == 'add' || $_POST['action'] == 'edit') {

			//MUST BE INTEGER
			//$product_requirement->set('pro_prg_product_group_id', (int)$_POST['pro_prg_product_group_id']);

			$editable_fields = array('prq_title', 'prq_text', 'prq_link', 'prq_is_default_checked', 'prq_is_required', 'prq_fil_file_id', 'prq_qst_question_id');

			foreach($editable_fields as $field) {
				$product_requirement->set($field, $_POST[$field]);
			}

			$product_requirement->prepare();
			$product_requirement->save();
			$product_requirement->load();

		} 

		LibraryFunctions::redirect('/admin/admin_product_requirements?pro_product_requirement_id='. $product_requirement->key);
		return;		
	} 

	if ($product_requirement->key) {
		$options['title'] = 'Product Requirement Edit';
		$breadcrumb = 'Product Requirement Edit';
	}
	else{
		$options['title'] = 'New Product Requirement';
		$breadcrumb = 'New Product Requirement';
	}

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'product-requirements',
		'page_title' => 'Product Requirements',
		'readable_title' => 'Product Requirements',
		'breadcrumbs' => array(
			'Products'=>'/admin/admin_products', 
			$breadcrumb =>'',
		),
		'session' => $session,
	)
	);

	$page->begin_box($options);

	// Editing an existing product
	$formwriter = $page->getFormWriter('form1');
	
	$validation_rules = array();
	$validation_rules['prq_title']['required']['value'] = 'true';
	$validation_rules['prq_qst_question_id']['required']['value'] = 'true';
	
	echo $formwriter->set_validate($validation_rules);			

	echo $formwriter->begin_form('form1', 'POST', '/admin/admin_product_requirement_edit');

	if($product_requirement->key){
		$action = 'edit';
		echo $formwriter->hiddeninput('prq_product_requirement_id', $product_requirement->key);
		echo $formwriter->hiddeninput('action', 'edit');
	}
	else{
		$action = 'add';
		echo $formwriter->hiddeninput('action', 'add');
	}

	echo $formwriter->textinput('Name for this requirement', 'prq_title', NULL, 100, $product_requirement->get('prq_title'), '', 255, '');

	$questions = new MultiQuestion(
		array('deleted'=>false),
		array('question_id'=>'DESC'),		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$questions->load();
	$optionvals = $questions->get_dropdown_array();
	echo $formwriter->dropinput("Question", "prq_qst_question_id", "ctrlHolder", $optionvals, $product_requirement->get('prq_qst_question_id'), '', TRUE);		

	echo $formwriter->textinput('Link (optional):', 'prq_link', NULL, 100, $product_requirement->get('prq_link'), '', 255, '');	

	/*
	echo $formwriter->textbox('Product Requirement Text', 'prq_text', 'ctrlHolder', 5, 80, $product_requirement->get('prq_text'), '', 'no');

	$optionvals = array("Yes"=>1, 'No' => 0);
	if($product_requirement->get('prq_is_required')){
		$recurring=1;
	}
	else{
		$recurring=0;
	}
	echo $formwriter->dropinput("Required?", "prq_is_required", "ctrlHolder", $optionvals, $recurring, '', FALSE);	

	$optionvals = array("Yes"=>1, 'No' => 0);
	if($product_requirement->get('prq_is_default_checked')){
		$recurring=1;
	}
	else{
		$recurring=0;
	}
	echo $formwriter->dropinput("Checked by default?", "prq_is_default_checked", "ctrlHolder", $optionvals, $recurring, '', FALSE);	
	*/

	$files = new MultiFile(
		array('deleted'=>false, 'past'=>false),
		array('file_id'=>'DESC'),		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$files->load();
	$optionvals = $files->get_file_dropdown_array();
	echo $formwriter->dropinput("Attach a file (optional)", "prq_fil_file_id", "ctrlHolder", $optionvals, $product_requirement->get('prq_fil_file_id'), '', 'None');	
/*
	$groups = new MultiGroup(
		array('category'=>'event'),  //SEARCH CRITERIA
		NULL,  //SORT AND DIRECTION array($usrsort=>$usrsdirection)
		NULL,  //NUM PER PAGE
		NULL,  //OFFSET
	);
	$numbundles = $groups->count_all();
	if($numbundles){
		$groups->load();
		$optionvals = $groups->get_dropdown_array();
		echo $formwriter->dropinput("Event Bundle", "pro_grp_group_id", "ctrlHolder", $optionvals, $product_requirement->get('pro_grp_group_id'), '', TRUE);

*/

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();
	
	$page->end_box();

	$page->admin_footer();

?>
