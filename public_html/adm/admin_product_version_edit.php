<?php
	
	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/StripeHelper.php'));
	require_once(PathHelper::getIncludePath('data/email_templates_class.php'));
	require_once(PathHelper::getIncludePath('data/products_class.php'));
	require_once(PathHelper::getIncludePath('data/product_groups_class.php'));
	require_once(PathHelper::getIncludePath('data/product_requirements_class.php'));
	require_once(PathHelper::getIncludePath('data/product_requirement_instances_class.php'));
	require_once(PathHelper::getIncludePath('data/order_items_class.php'));
	require_once(PathHelper::getIncludePath('data/events_class.php'));

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
			if(isset($_REQUEST['prv_display_priority'])){
				$product_version->set('prv_display_priority', $_REQUEST['prv_display_priority']);
			}
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
			if(isset($_REQUEST['prv_display_priority'])){
				$product_version->set('prv_display_priority', $_REQUEST['prv_display_priority']);
			}
			$product_version->prepare();
			$product_version->save();

			// Sync Stripe price when editing existing version
			if($settings->get_setting('checkout_type') != 'none'){
				$stripe_helper = new StripeHelper();
				$stripe_price = $stripe_helper->get_or_create_price($product_version, NULL);
			}
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

	$formwriter = $page->getFormWriter('form1');

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

			echo $formwriter->textinput('Label', 'version_name', NULL, 100, $product_version->get('prv_version_name'), '', 255, '');
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
				echo $formwriter->hiddeninput('product_version_id', $product_version->key);

				// Display price as read-only for existing versions
				echo '<div class="ctrlHolder"><p class="label">Current Price</p>';
				echo '<div class="textInput"><strong>'.$currency_symbol . $product_version->get('prv_version_price') . ' / ' . $product_version->get('prv_price_type') . '</strong>';
				echo '<br><em style="color: #666;">Price cannot be edited. To change pricing, create a new version and make this one inactive.</em></div></div>';
			}
			
			//THIS SECTION IS FOR /PRICING PAGE.  USER CHOOSES DISPLAY PRIORITY
			if($settings->get_setting('pricing_page')){
				echo $formwriter->textinput(
					'Display Priority (0=private, >0=public, higher=preferred):',
					'prv_display_priority',
					'ctrlHolder',
					10,
					$product_version->get('prv_display_priority'),
					'',
					5,
					'Set to 0 to hide from public /pricing page. Higher values show first when multiple versions exist.'
				);
			}
	
			echo $formwriter->start_buttons();
			echo $formwriter->new_form_button('Submit');
			echo $formwriter->end_buttons();
			echo $formwriter->end_form();
	
	$page->end_box();

	$page->admin_footer();

?>
