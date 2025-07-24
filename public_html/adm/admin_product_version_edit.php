<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	
	PathHelper::requireOnce('includes/AdminPage.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	PathHelper::requireOnce('includes/StripeHelper.php');
	PathHelper::requireOnce('data/email_templates_class.php');
	PathHelper::requireOnce('data/products_class.php');
	PathHelper::requireOnce('data/product_groups_class.php');
	PathHelper::requireOnce('data/product_requirements_class.php');
	PathHelper::requireOnce('data/product_requirement_instances_class.php');
	PathHelper::requireOnce('data/order_items_class.php');
	PathHelper::requireOnce('data/events_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return(); 
	
	$settings = Globalvars::get_instance();
	$currency_code = $settings->get_setting('site_currency');
	$currency_symbol = Product::$currency_symbols[$currency_code];

	if (isset($_REQUEST['product_version_id'])) {
		$product_version = new ProductVersion($_REQUEST['product_version_id'], TRUE);
		
	} else {
		$product_version = new ProductVersion(NULL);
	}

	$product = new Product($_REQUEST['product_id'], TRUE);

	if ($_POST || $_REQUEST['action']) {
	
		

		
		if ($_REQUEST['action'] == 'new_version') {

			
			$product_version = new ProductVersion(NULL);
			$product_version->set('prv_pro_product_id', $product->key);
			$product_version->set('prv_version_name', $_REQUEST['version_name']);
			$product_version->set('prv_version_price', $_REQUEST['version_price']);
			$product_version->set('prv_price_type', $_REQUEST['prv_price_type']);
			$product_version->set('prv_trial_period_days', $_REQUEST['prv_trial_period_days']);
			$product_version->set('prv_status', 1);
			$product_version->set('prv_plan_order_year', $_REQUEST['prv_plan_order_year']);
			$product_version->set('prv_plan_order_month', $_REQUEST['prv_plan_order_month']);
			$product_version->prepare();
			$product_version->save();
			$product_version->load();
			
			if($settings->get_setting('checkout_type') != 'none'){
				$stripe_helper = new StripeHelper();
				$stripe_price = $stripe_helper->get_or_create_price($product_version, NULL);	
			}
		} 
		else if ($_REQUEST['action'] == 'remove_version') {
			$product_version = new ProductVersion($_REQUEST['product_version_id'], TRUE);
			$product_version->set('prv_status', 0);
			$product_version->prepare();
			$product_version->save();
		} 
		else if ($_REQUEST['action'] == 'activate_version') {
			$product_version = new ProductVersion($_REQUEST['product_version_id'], TRUE);
			$product_version->set('prv_status', 1);
			$product_version->prepare();
			$product_version->save(); 
		}
		else{
			
			$product_version->set('prv_version_name', $_REQUEST['version_name']);
			$product_version->set('prv_plan_order_year', $_REQUEST['prv_plan_order_year']);
			$product_version->set('prv_plan_order_month', $_REQUEST['prv_plan_order_month']);
			$product_version->prepare();
			$product_version->save();			
		}
		
		LibraryFunctions::redirect('/admin/admin_product?pro_product_id='. $product->key);
		return;		
	} 

	if ($product_version->key) {
		$options['title'] = 'Product Version Edit - '. $product_version->get('prv_version_name');
		$breadcrumb = 'Product '.$product->get('pro_name');
	}
	else{
		$options['title'] = 'New Product Version';
		$breadcrumb = 'New Product Version';
	}

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'products-list',
		'page_title' => 'Products Version Edit',
		'readable_title' => 'Product Version Edit',
		'breadcrumbs' => array(
			'Products'=>'/admin/admin_products', 
			$breadcrumb => '',
			'Product Version Edit'=>'',
		),
		'session' => $session,
	)
	);

	$page->begin_box($options);


	
	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');

	?>
	<script type="text/javascript">
	

		
		function set_subscription_choices(){
			var value = $("#prv_price_type").val();
			if(value == 'single' || value == 'user'){  	
				$("#prv_trial_period_days_container").hide();
			}	
			else { 
				$("#prv_trial_period_days_container").show();				
			}
			
		}
		
	
		$(document).ready(function() {

			set_subscription_choices();
			$("#prv_price_type").change(function() {	
				set_subscription_choices();
			});
		});
	
		
	</script>
	<?php

			
			$validation_rules = array();
			$validation_rules['version_name']['required']['value'] = 'true';
			if(!$product_version->key){
				$validation_rules['version_price']['required']['value'] = 'true';
			}
			echo $formwriter->set_validate($validation_rules);				
			
			echo $formwriter->begin_form('form1', 'POST', '/admin/admin_product_version_edit');

			
			echo $formwriter->textinput('Label', 'version_name', NULL, 100, $product_version->get('prv_plan_order_year'), '', 255, '');
			echo $formwriter->hiddeninput('product_id', $_REQUEST['product_id']);
			if(!$product_version->key){
				echo $formwriter->hiddeninput('action', 'new_version');
				
				echo $formwriter->textinput('Price ('.$currency_symbol.')', 'version_price', 'ctrlHolder', 100, '', '', 255, '');

				$optionvals = array("One price"=>'single', 'User Chooses' => 'user', 'Daily Subscription'=>'day', 'Weekly Subscription'=>'week', 'Monthly Subscription'=>'month', 'Yearly Subscription'=>'year',);
				echo $formwriter->dropinput("Pricing", "prv_price_type", "ctrlHolder", $optionvals, NULL, '', FALSE);

				$prv_trial_period_days_fill = 0;
				echo $formwriter->textinput('Subscription trial period (days):', 'prv_trial_period_days', 'ctrlHolder', 100, $prv_trial_period_days_fill, '', 3, '');
			}
			else{
				echo $formwriter->hiddeninput('action', 'edit');
			}
			
			//THIS SECTION IS FOR /PRICING PAGE.  USER CHOOSES WHICH PLAN AND THEN SETS AN ORDER
			if($settings->get_setting('pricing_page')){
				$optionvals = array(
					'No' => 0, 
					"Monthly Plan 1"=>1,
					"Monthly Plan 2"=>2,
					"Monthly Plan 3"=>3,
					);
				echo $formwriter->dropinput("Include on monthly /pricing page?", "prv_plan_order_month", "ctrlHolder", $optionvals, $product_version->get('prv_plan_order_year'), '', FALSE);	
				
				$optionvals = array(
					'No' => 0, 
					"Yearly Plan 1"=>1,
					"Yearly Plan 2"=>2,
					"Yearly Plan 3"=>3,
					);
				echo $formwriter->dropinput("Include on yearly /pricing page?", "prv_plan_order_year", "ctrlHolder", $optionvals, $product_version->get('prv_plan_order_year'), '', FALSE);	
			}
	
			echo $formwriter->start_buttons();
			echo $formwriter->new_form_button('Submit');
			echo $formwriter->end_buttons();
			echo $formwriter->end_form();
	
	$page->end_box();

	$page->admin_footer();

?>
