<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	PathHelper::requireOnce('/includes/AdminPage.php');
	
	PathHelper::requireOnce('/includes/LibraryFunctions.php');
	PathHelper::requireOnce('/includes/StripeHelper.php');

	PathHelper::requireOnce('/data/email_templates_class.php');
	PathHelper::requireOnce('/data/products_class.php');
	PathHelper::requireOnce('/data/product_groups_class.php');
	PathHelper::requireOnce('/data/product_requirements_class.php');
	PathHelper::requireOnce('/data/product_requirement_instances_class.php');
	PathHelper::requireOnce('/data/order_items_class.php');
	PathHelper::requireOnce('/data/events_class.php');

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
		
		
		if ($_POST['action'] == 'add' || $_POST['action'] == 'edit') {
			
		
			if($_POST['pro_requirements']){
				$total_value = 0;
				foreach ($_POST['pro_requirements'] as $choice => $value){
					$total_value += $value;		 	
				}
				$product->set('pro_requirements', $total_value);
			}



			$product->save_requirement_instances($_POST['additional_pro_requirements']);
			

			
			if($_POST['pro_evt_event_id'] == '' || $_POST['pro_evt_event_id'] == 0){
				$product->set('pro_evt_event_id', NULL);

			}
			else{
				$product->set('pro_evt_event_id', intval($_POST['pro_evt_event_id']));
			}
			
			//MUST BE INTEGER
			$product->set('pro_expires', (int)$_POST['pro_expires']);
			$product->set('pro_prg_product_group_id', (int)$_POST['pro_prg_product_group_id']);
			

	
			//PRICE MUST BE INTEGER
			if($_POST['pro_grp_group_id']){
				$_POST['pro_grp_group_id'] = (int)$_POST['pro_grp_group_id'];
			}
			else{
				$_POST['pro_grp_group_id'] = NULL;
			}

	
			
			//STORE THE PRODUCT SCRIPTS
			$product->set('pro_product_scripts', NULL);
			if(is_array($_POST['product_scripts'])){
				$product->set('pro_product_scripts', implode(',', $_POST['product_scripts']));
			}
			
			$editable_fields = array('pro_name', 'pro_description', 'pro_max_purchase_count', 'pro_max_cart_count', 'pro_after_purchase_message','pro_is_active', 'pro_receipt_body', 'pro_grp_group_id', 'pro_digital_link', 'pro_short_description');

			foreach($editable_fields as $field) {
				$product->set($field, $_POST[$field]);
			}
			
			if(!$product->get('pro_link') || $_SESSION['permission'] == 10){
				if($_POST['pro_link']){
					$product->set('pro_link', $product->create_url($_POST['pro_link']));
				}
				else{
					$product->set('pro_link', $product->create_url($event->get('pro_name')));
				}
			}
		
			$product->prepare();
			
			
			//IF STRIPE IS ENABLED, CREATE A PRODUCT 
			if($settings->get_setting('checkout_type') != 'none'){
				$stripe_helper = new StripeHelper();
				$product_info=array();
				$product_info['name'] = $product->get('pro_name');
				//$product_info['description'] = '';

				if($stripe_helper->test_mode){
					if(!$product->get('pro_stripe_product_id_test')){
						$stripe_product = $stripe_helper->create_product($product_info);
						$product->set('pro_stripe_product_id_test', $stripe_product['id']);
						if(!$stripe_product['id']){
							throw new SystemDisplayablePermanentError("Unable to create a stripe product."); 
						}
					}
				}
				else{
					if(!$product->get('pro_stripe_product_id')){				
						$stripe_product = $stripe_helper->create_product($product_info);
						if(!$stripe_product['id']){
							throw new SystemDisplayablePermanentError("Unable to create a stripe product."); 
						}
						$product->set('pro_stripe_product_id', $stripe_product['id']);
					}
				}
				
			}
			
			$product->save();
			$product->load();
			
		
		} 
		
		if ($_REQUEST['action'] == 'new_version') {
			$product_version = new ProductVersion(NULL);
			$product_version->set('prv_pro_product_id', $product->key);
			$product_version->set('prv_version_name', $_REQUEST['version_name']);
			$product_version->set('prv_version_price', $_REQUEST['version_price']);
			$product_version->set('prv_price_type', $_REQUEST['prv_price_type']);
			$product_version->set('prv_trial_period_days', $_REQUEST['prv_trial_period_days']);
			$product_version->set('prv_status', 1);
			$product_version->prepare();
			$product_version->save();
		} 
		else if ($_REQUEST['action'] == 'remove_version') {
			$product_version = new ProductVersion($_REQUEST['v'], TRUE);
			$product_version->set('prv_status', 0);
			$product_version->prepare();
			$product_version->save();
		} 
		else if ($_REQUEST['action'] == 'activate_version') {
			$product_version = new ProductVersion($_REQUEST['v'], TRUE);
			$product_version->set('prv_status', 1);
			$product_version->prepare();
			$product_version->save(); 
		}
		

		if($_POST['json_confirm']){
			echo json_encode($product->key);
		}
		LibraryFunctions::redirect('/admin/admin_product?pro_product_id='. $product->key);
		return;

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
		'menu-id'=> 'products-list',
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
	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
	
	$validation_rules = array();
	
	$validation_rules['pro_name']['required']['value'] = 'true';
	$validation_rules['pro_name']['maxlength']['value'] = 255;
	$validation_rules['pro_link']['required']['value'] = 'true';
	$validation_rules['pro_max_cart_count']['required']['value'] = 'true';
	$validation_rules['pro_max_purchase_count']['required']['value'] = 'true';
	$validation_rules['pro_requirements']['required']['value'] = 'true';
	
	echo $formwriter->set_validate($validation_rules);			

	?>
	<script type="text/javascript">
	/*
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
		
	
		$(document).ready(function() {
			set_pricing_choices();
			$("#pro_price_type").change(function() {	
				set_pricing_choices();
			});	

		});
	*/
		
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



	echo $formwriter->textbox('Short Description', 'pro_short_description', 'ctrlHolder', 5, 80, $product->get('pro_short_description'), '', 'yes');
	echo $formwriter->textbox('Description', 'pro_description', 'ctrlHolder', 5, 80, $product->get('pro_description'), '', 'yes');
	

	
	echo $formwriter->textinput('Digital item link', 'pro_digital_link', NULL, 100, $product->get('pro_digital_link'), '', 255, '');

	
	$events = new MultiEvent(
		array('deleted'=>false, 'past'=>false),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$numevents = $events->count_all();
	if($numevents){
		$events->load();
		$optionvals = $events->get_dropdown_array();
		echo $formwriter->dropinput("Event registration", "pro_evt_event_id", "ctrlHolder", $optionvals, $product->get('pro_evt_event_id'), '', TRUE);
	}


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
		echo $formwriter->dropinput("Event Bundle", "pro_grp_group_id", "ctrlHolder", $optionvals, $product->get('pro_grp_group_id'), '', TRUE);
	}
	
	
	if(!$pro_max_purchase_count_fill = $product->get('pro_max_purchase_count')){
		$pro_max_purchase_count_fill = 0;
	}
	echo $formwriter->textinput('Total Number available for purchase (0 for unlimited):', 'pro_max_purchase_count', 'ctrlHolder', 100, $pro_max_purchase_count_fill, '', 3, '');

	if(!$pro_max_cart_count_fill = $product->get('pro_max_cart_count')){
		$pro_max_cart_count_fill = 0;
	}
	echo $formwriter->textinput('Max Number that can be added to cart per user (0 for unlimited):', 'pro_max_cart_count', 'ctrlHolder', 100, $pro_max_cart_count_fill, '', 3, '');
	
	if(!$pro_expires_fill = $product->get('pro_expires')){
		$pro_expires_fill = 0;
	}
	echo $formwriter->textinput('Purchase expires after (days, 0 for never)', 'pro_expires', NULL, 100, $pro_expires_fill, '', 4, '');
	
	if(!$product->get('pro_link') || $_SESSION['permission'] == 10){
		echo $formwriter->textinput('Link (optional): '.$settings->get_setting('webDir').'/product/', 'pro_link', NULL, 100, $product->get('pro_link'), '', 255, '');	
	}
	

	
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
		'Phone Number' => 2,
		'Date of Birth' => 4,
		'Address' => 8,
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


	//PRODUCT SCRIPTS
	$optionvals = array();
	$optionvals = array_merge($optionvals, getFunctionNamesFromFile(PathHelper::getRoot() . '/logic/product_scripts_logic.php'));
	
	$plugins = LibraryFunctions::list_plugins();
	foreach($plugins as $plugin){
		$product_script_file = PathHelper::getRoot().'/plugins/'.$plugin.'/logic/product_scripts_logic.php';
		if(file_exists($product_script_file)){
			$optionvals = array_merge($optionvals, getFunctionNamesFromFile($product_script_file));
		}
	}
	if(!empty($optionvals)){
		$optionvals = array_combine($optionvals, $optionvals);
		$readonlyvals = array(); 
		$checkedvals = explode(',', $product->get('pro_product_scripts'));
		$disabledvals = array();
		echo $formwriter->checkboxList("Run these scripts upon purchase", 'product_scripts', "ctrlHolder", $optionvals, $checkedvals, $disabledvals, $readonlyvals);
	}


	$instances = $product->get_requirement_instances(false);

	$product_requirements = new MultiProductRequirement(
		array('deleted'=>false),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	if($product_requirements->count_all()){
		$product_requirements->load();
		$optionvals = $product_requirements->get_dropdown_array();
		
		$readonlyvals = array(); 
		$checkedvals = array();
		$disabledvals = array();
		foreach ($product_requirements as $product_requirement){
			if($product_requirement->get('prq_is_default_checked')){
				$checkedvals[] = $product_requirement->key;
			}
			
			foreach($instances as $instance){
				if($product_requirement->key == $instance->get('pri_prq_product_requirement_id')){
					$checkedvals[] = $instance->get('pri_prq_product_requirement_id');
				}
			}
		}
		
		
		echo $formwriter->checkboxList("Additional Info to collect at purchase", 'additional_pro_requirements', "ctrlHolder", $optionvals, $checkedvals, $disabledvals, $readonlyvals);
	}



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


	/**
	 * Extracts function names from a given PHP file.
	 *
	 * @param string $filePath The path to the PHP file.
	 * @return array An array of function names found in the file.
	 * @throws Exception If the file cannot be read.
	 */
	if (!function_exists('getFunctionNamesFromFile')) {
function getFunctionNamesFromFile($filePath) {
		if (!file_exists($filePath)) {
			throw new Exception("File does not exist: $filePath");
		}

		$fileContent = file_get_contents($filePath);
		if ($fileContent === false) {
			throw new Exception("Failed to read the file: $filePath");
		}

		$tokens = token_get_all($fileContent);
		$functions = [];
		$isFunction = false;

		foreach ($tokens as $token) {
			if (is_array($token)) {
				if ($token[0] === T_FUNCTION) {
					$isFunction = true; // Next string token will be the function name
				} elseif ($isFunction && $token[0] === T_STRING) {
					$functions[] = $token[1]; // Add function name to the list
					$isFunction = false;
				}
			}
		}

		return $functions;
	}
}
?>
