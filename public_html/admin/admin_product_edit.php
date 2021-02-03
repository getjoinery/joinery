<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/email_templates_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/products_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/product_groups_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/order_items_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	if (isset($_REQUEST['p'])) {
		$product = new Product($_REQUEST['p'], TRUE);
	} else {
		$product = new Product(NULL);
	}



	if ($_POST || $_REQUEST['action']) {
		if ($_REQUEST['action'] == 'edit') {

			//PREVENT PRODUCTS FROM BEING ADDED TO MULTIPLE EVENTS
			if($_REQUEST['pro_evt_event_id']){

				$products = new MultiProduct(
				array('event_id' => $_REQUEST['pro_evt_event_id'], 'product_id_is_not' => $product->key));
				if($products->count_all()){
					$products->load();
					$otherproducts = '';
					foreach ($products as $product){
						$otherproducts = $product->get('pro_name');
					}
					throw new SystemDisplayableError('An event cannot be attached to two different products:  It is currently attached to: <a href="/admin/admin_products?p='. $product->key. '">'.$otherproducts.'</a>');
					exit();
				}
			}	
			
			if($_REQUEST['pro_evt_event_id'] == '' || $_REQUEST['pro_evt_event_id'] == 0){
				$product->set('pro_evt_event_id', NULL);

			}
			else{
				$product->set('pro_evt_event_id', intval($_REQUEST['pro_evt_event_id']));
			}
			
			//PRICE MUST BE INTEGER
			if($_REQUEST['pro_price']){
				$_REQUEST['pro_price'] = (int)$_REQUEST['pro_price'];
			}
	
			$editable_fields = array('pro_name', 'pro_price', 'pro_description', 'pro_max_purchase_count', 'pro_after_purchase_message', 'pro_initial_odi_status', 'pro_prg_product_group_id', 'pro_requirements','pro_is_active', 'pro_receipt_body', 'pro_receipt_template', 'pro_receipt_subject');

			foreach($editable_fields as $field) {
				$product->set($field, $_REQUEST[$field]);
			}

			$product->save();
			$product->load();
		} else if ($_REQUEST['action'] == 'add') {
			
			//PREVENT PRODUCTS FROM BEING ADDED TO MULTIPLE EVENTS
			if($_REQUEST['pro_evt_event_id']){
				$products = new MultiProduct(
				array('event_id' => $_REQUEST['pro_evt_event_id'], 'product_id_is_not' => $product->key));
				if($products->count_all()){
					$products->load();
					$otherproducts = '';
					foreach ($products as $product){
						$otherproducts = $product->get('pro_name');
					}
					throw new SystemDisplayableError('An event cannot be attached to two different products:  '. $otherproducts);
					exit();
				}
			}	

			if($_REQUEST['pro_evt_event_id'] == '' || $_REQUEST['pro_evt_event_id'] == 0){
				$_REQUEST['pro_evt_event_id'] = NULL;
			}
			else{
				$product->set('pro_evt_event_id', intval($_REQUEST['pro_evt_event_id']));
			}
			
			$editable_fields = array('pro_name', 'pro_price', 'pro_description', 'pro_max_purchase_count', 'pro_after_purchase_message', 'pro_initial_odi_status', 'pro_prg_product_group_id', 'pro_requirements','pro_is_active', 'pro_receipt_body', 'pro_receipt_template', 'pro_receipt_subject');

			foreach($editable_fields as $field) {
				$product->set($field, $_REQUEST[$field]);
			}

			$product->save();
			$product->load();
			
		} else if ($_REQUEST['action'] == 'new_version') {
			$product->add_product_version($_REQUEST['version_name'], $_REQUEST['version_price'], $_REQUEST['version_deposit']);
		} else if ($_REQUEST['action'] == 'remove_version') {
			$product->change_product_version_status($_REQUEST['v'], ProductVersion::INACTIVE);
		} else if ($_REQUEST['action'] == 'activate_version') {
			$product->change_product_version_status($_REQUEST['v'], ProductVersion::ACTIVE); 
		}
		
		LibraryFunctions::redirect('/admin/admin_product?pro_product_id='. $product->key);
		exit;		
	} 

	if ($product->key) {
		$options['title'] = 'Product Edit - '. $product->get('pro_name');
		$breadcrumb = 'Product '.$product->get('pro_name');
	}
	else{
		$options['title'] = 'New Product';
		$breadcrumb = 'New Product';
	}

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 5,
		'page_title' => 'Products',
		'readable_title' => 'Products',
		'breadcrumbs' => array(
			'Products'=>'/admin/admin_products', 
			$breadcrumb => '',
			'Product Edit'=>'',
		),
		'session' => $session,
	)
	);


	$page->begin_box($options);

	// Editing an existing product
	$formwriter = new FormWriterMaster('form1');
	
	$validation_rules = array();
	$validation_rules['pro_name']['required']['value'] = 'true';
	$validation_rules['pro_price']['required']['value'] = 'true';
	$validation_rules['pro_max_purchase_count']['required']['value'] = 'true';
	$validation_rules['pro_prg_product_group_id']['required']['value'] = 'true';
	$validation_rules['pro_requirements']['required']['value'] = 'true';
	
	echo $formwriter->set_validate($validation_rules);			
	
	echo $formwriter->begin_form('form1', 'POST', '/admin/admin_product_edit');

	if($product->key){
		$action = 'edit';
		echo $formwriter->hiddeninput('p', $product->key);
		echo $formwriter->hiddeninput('action', 'edit');
		$product_status = $product->get('pro_is_active');
	}
	else{
		$action = 'add';
		echo $formwriter->hiddeninput('action', 'add');
		$product_status = 1;
	}
	

	$optionvals = array("Active"=>1, "Disabled"=>0 );
	echo $formwriter->dropinput("Active?", "pro_is_active", "ctrlHolder", $optionvals, $product_status, '', FALSE);
	echo $formwriter->textinput('Product Name', 'pro_name', NULL, 100, $product->get('pro_name'), '', 255, '');


	$events = new MultiEvent(
		array('deleted'=>false, 'past'=>false),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$events->load();
	$optionvals = $events->get_dropdown_array();
	echo $formwriter->dropinput("Event registration?", "pro_evt_event_id", "ctrlHolder", $optionvals, $product->get('pro_evt_event_id'), '', TRUE);	


	echo $formwriter->textinput('Minimum Purchase Price', 'pro_price', 'ctrlHolder', 100, $product->get('pro_price'), '', 255, '');
	echo $formwriter->textinput('Max Number that can be added to cart:', 'pro_max_purchase_count', 'ctrlHolder', 100, $product->get('pro_max_purchase_count'), '', 255, '');
	

	
	$pgs = new MultiProductGroup(
		array(),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$pgs->load();
	$optionvals = $pgs->get_dropdown_array();
	echo $formwriter->dropinput("Product Group", "pro_prg_product_group_id", "ctrlHolder", $optionvals, $product->get('pro_prg_product_group_id'), '', TRUE);	
	

	/*
	echo 'Product requirement options: <br />
	Full name - 1<br />
	Phone Number - 2<br />
	Date of Birth - 4<br />
	Address - 8<br />
	GDPR Notice - 16<br />
	Recording consent - 32<br />
	Email - 64 <br />
	User input price - 128<br />
	';
	echo $formwriter->textinput('Product Requirements', 'pro_requirements', 'ctrlHolder', 100, $product->get('pro_requirements'), '', 255, '');
	*/
	
	$optionvals = array(
	"Name, email, "=>65, 
	"Name, email, newsletter signup, "=>321, 
	"Name, email, recording consent"=>97,
	"Name, email, recording consent, newsletter signup"=>353,
	"Name, email, user input price"=> 193,
	"Name, email, user input price, newsletter signup"=> 449,
	"Name, email, user input price, comment"=> 705,
	"Name, email, user input price, newsletter signup, comment"=> 961,	
	);
	echo $formwriter->dropinput("Info to collect", "pro_requirements", "ctrlHolder", $optionvals, $product->get('pro_requirements'), '', FALSE);

	echo $formwriter->textinput('Product Description', 'pro_description', 'ctrlHolder', 100, $product->get('pro_description'), '', 255, '');

	//echo $formwriter->textinput('After Purchase Message', 'pro_after_purchase_message', 'ctrlHolder', 100, $product->get('pro_after_purchase_message'), '', 255);

/*
	//REMOVED
	$templates = new MultiEmailTemplateStore(
		array('template_type' => EmailTemplateStore::TEMPLATE_TYPE_INNER),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$templates->load();
	$optionvals = $templates->get_dropdown_array();
	echo $formwriter->dropinput("Receipt template", "pro_receipt_template", "ctrlHolder", $optionvals, $product->get('pro_receipt_template'), '', TRUE);	

	echo $formwriter->textinput('Receipt subject (if no template chosen)', 'pro_receipt_subject', NULL, 100, $product->get('pro_receipt_subject'), '', 255, '');
	echo $formwriter->textbox('Receipt body  (if no template chosen)', 'pro_receipt_body', 'ctrlHolder', 10, 80, $product->get('pro_receipt_body'), '');
*/

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();
	
	$page->end_box();

	$page->admin_footer();

?>
