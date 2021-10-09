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
	
	$settings = Globalvars::get_instance();
	$currency_code = $settings->get_setting('site_currency');
	$currency_symbol = Product::$currency_symbols[$currency_code];

	if (isset($_REQUEST['p'])) {
		$product = new Product($_REQUEST['p'], TRUE);
	} else {
		$product = new Product(NULL);
	}



	if ($_POST || $_REQUEST['action']) {
		
		if ($_REQUEST['action'] == 'add' || $_REQUEST['action'] == 'edit') {
		
			if($_REQUEST['pro_requirements']){
				$total_value = 0;
				foreach ($_REQUEST['pro_requirements'] as $choice => $value){
					$total_value += $value;			
				}
				$product->set('pro_requirements', $total_value);
			}
			
			if($_REQUEST['pro_evt_event_id'] == '' || $_REQUEST['pro_evt_event_id'] == 0){
				$product->set('pro_evt_event_id', NULL);

			}
			else{
				$product->set('pro_evt_event_id', intval($_REQUEST['pro_evt_event_id']));
			}
			
			//MUST BE INTEGER
			$product->set('pro_expires', (int)$_REQUEST['pro_expires']);
			$product->set('pro_prg_product_group_id', (int)$_REQUEST['pro_prg_product_group_id']);
			
			//PRICE MUST BE INTEGER
			if($_REQUEST['pro_price']){
				$_REQUEST['pro_price'] = (int)$_REQUEST['pro_price'];
			}
	
			//PRICE MUST BE INTEGER
			if($_REQUEST['pro_grp_group_id']){
				$_REQUEST['pro_grp_group_id'] = (int)$_REQUEST['pro_grp_group_id'];
			}
			else{
				$_REQUEST['pro_grp_group_id'] = NULL;
			}

	
			//SET RECURRING VALUE
			if($_REQUEST['pro_recurring']){
				$product->set('pro_recurring', 'month');
			}
			else{
				$product->set('pro_recurring', NULL);
			}
			
			$editable_fields = array('pro_name', 'pro_price', 'pro_description', 'pro_max_purchase_count', 'pro_max_cart_count', 'pro_after_purchase_message','pro_is_active', 'pro_receipt_body', 'pro_receipt_template', 'pro_receipt_subject', 'pro_price_type', 'pro_grp_group_id');

			foreach($editable_fields as $field) {
				$product->set($field, $_REQUEST[$field]);
			}

			$product->prepare();
			$product->save();
			$product->load();
			
		
		} 
		else if ($_REQUEST['action'] == 'new_version') {
			
			$product->add_product_version($_REQUEST['version_name'], $_REQUEST['version_price'], $_REQUEST['version_deposit']);
		} 
		else if ($_REQUEST['action'] == 'remove_version') {
			$product->change_product_version_status($_REQUEST['v'], ProductVersion::INACTIVE);
		} 
		else if ($_REQUEST['action'] == 'activate_version') {
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
	$validation_rules['pro_max_cart_count']['required']['value'] = 'true';
	$validation_rules['pro_max_purchase_count']['required']['value'] = 'true';
	$validation_rules['pro_requirements']['required']['value'] = 'true';
	
	echo $formwriter->set_validate($validation_rules);			

	?>
	<script type="text/javascript">
	
		function set_pricing_choices(){
			var value = $("#pro_price_type").val();
			if(value == 1){  //ONE PRICE	
				$("#pro_price_container").show();
			}	
			else if(value == 2){  //MULTIPLE PRICES
				$("#pro_price_container").hide();				
			}
			else if(value == 3){  //USER CHOOSES PRICE
				$("#pro_price_container").hide();				
			}			
		}
	
		function set_expire_choices(){
			var value = $("#pro_recurring").val(); 
			if(value == 1){  //SUBSCRIPTION	
				$("#pro_expires_container").hide();
				$("#pro_expires").val(0)
			}	
			else if(value == 0){  //ONE TIME
				$("#pro_expires_container").show();				
			}			
		}	
	
		$(document).ready(function() {
			set_pricing_choices();
			$("#pro_price_type").change(function() {	
				set_pricing_choices();
			});	
			
			set_expire_choices();
			$("#pro_recurring").change(function() {	
				set_expire_choices();
			});	
		});
	
		
	</script>
	<?php
	
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

	$optionvals = array("Event ticket"=>'1', 'Other Item' => '2', 'System (do not change)' => 0);
	echo $formwriter->dropinput("Product type", "product_type", "ctrlHolder", $optionvals, $product->get('product_type'), '', FALSE);	

	//echo $formwriter->textinput('Product Description', 'pro_description', 'ctrlHolder', 100, $product->get('pro_description'), '', 255, '');
	echo $formwriter->textbox('Product Description', 'pro_description', 'ctrlHolder', 5, 80, $product->get('pro_description'), '', 'yes');
	
	$optionvals = array("Yes, it is a recurring monthly charge"=>1, 'No, it is a one time payment' => 0);
	if($product->get('pro_recurring')){
		$recurring=1;
	}
	else{
		$recurring=0;
	}
	echo $formwriter->dropinput("Subscription?", "pro_recurring", "ctrlHolder", $optionvals, $recurring, '', FALSE);	
	
	$events = new MultiEvent(
		array('deleted'=>false, 'past'=>false),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$events->load();
	$optionvals = $events->get_dropdown_array();
	echo $formwriter->dropinput("Event registration", "pro_evt_event_id", "ctrlHolder", $optionvals, $product->get('pro_evt_event_id'), '', TRUE);	

	$groups = new MultiGroup(
		array('type' => Group::GROUP_TYPE_EVENT),  //SEARCH CRITERIA
		NULL,  //SORT AND DIRECTION array($usrsort=>$usrsdirection)
		NULL,  //NUM PER PAGE
		NULL,  //OFFSET
	);
	$numbundles = $groups->count_all();
	if($numbundles){
		$groups->load();
		$optionvals = $groups->get_dropdown_array();
		echo $formwriter->dropinput("Event Bundle", "pro_grp_group_id", "ctrlHolder", $optionvals, $product->get('pro_grp_group_id'), '', TRUE);
	}
	
	$optionvals = array("One price"=>1, 'Multiple pricing levels' => 2, 'User chooses price'=>3);
	echo $formwriter->dropinput("Pricing", "pro_price_type", "ctrlHolder", $optionvals, $product->get('pro_price_type'), '', FALSE);

	echo $formwriter->textinput('Price ('.$currency_symbol.'no cents)', 'pro_price', 'ctrlHolder', 100, (int)$product->get('pro_price'), '', 5, '');
	echo $formwriter->textinput('Max Number that can be added to cart (0 for unlimited):', 'pro_max_cart_count', 'ctrlHolder', 100, $product->get('pro_max_cart_count'), '', 3, '');
	echo $formwriter->textinput('Max Number that can be purchased total (0 for unlimited):', 'pro_max_purchase_count', 'ctrlHolder', 100, $product->get('pro_max_purchase_count'), '', 3, '');
	echo $formwriter->textinput('Purchase expires after (days, 0 for never)', 'pro_expires', NULL, 100, $product->get('pro_expires'), '', 4, '');
	

	
	$pgs = new MultiProductGroup(
		array(),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	if($pgs->count_all()){
		$pgs->load();
		$optionvals = $pgs->get_dropdown_array();
		echo $formwriter->dropinput("Product Group", "pro_prg_product_group_id", "ctrlHolder", $optionvals, $product->get('pro_prg_product_group_id'), '', TRUE);	
	}
	
	$optionvals = array(
		'Name' => 1, 
		'Email' => 64,
		//'Phone Number' => 2,
		//'Date of Birth' => 4,
		//'Address' => 8,
		//'GDPR Notice' => 16,
		'Consent to record' => 32,
		'Optional One-time Donation' => 128,
		'Newsletter Signup' => 256,
		'Comment' => 512
	);
	if ($product->key) {
		//FILL THE CHECKED VALUES AND DECLARE EMAIL AND NAME READ ONLY
		$checkedvals = $product->get_requirement_info('ids');
		$checkedvals[] = 1;
		$checkedvals[] = 64;
		$readonlyvals = array(1, 64); //DEFAULT
	}
	else{
		$checkedvals = array(1, 64);
		$readonlyvals = array(1, 64); //DEFAULT
	}
	$disabledvals = array();
	
	echo $formwriter->checkboxList("Info to collect at purchase", 'pro_requirements', "ctrlHolder", $optionvals, $checkedvals, $disabledvals, $readonlyvals);



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
