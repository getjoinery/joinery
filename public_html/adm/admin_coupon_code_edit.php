<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/coupon_codes_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/coupon_code_products_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(10);
	
	$settings = Globalvars::get_instance();
	$currency_symbol = Product::$currency_symbols[$settings->get_setting('site_currency')];

	if (isset($_REQUEST['ccd_coupon_code_id'])) {
		$coupon_code = new CouponCode($_REQUEST['ccd_coupon_code_id'], TRUE);
	} 
	else{
		$coupon_code = new CouponCode(NULL);
	}

	if($_POST){
		if($_POST['ccd_start_time_date'] && $_POST['ccd_start_time_time']){
			$time_combined = $_POST['ccd_start_time_date'] . ' ' . LibraryFunctions::toDBTime($_POST['ccd_start_time_time']);
			$utc_time = LibraryFunctions::convert_time($time_combined, $session->get_timezone(),  'UTC', 'c');
			$coupon_code->set('ccd_start_time', $utc_time);
			$coupon_code->set('ccd_start_time_local', $time_combined);
		}
		
		if($_POST['ccd_end_time_date'] && $_POST['ccd_end_time_time']){
			$time_combined = $_POST['ccd_end_time_date'] . ' ' . LibraryFunctions::toDBTime($_POST['ccd_end_time_time']);
			$utc_time = LibraryFunctions::convert_time($time_combined, $session->get_timezone(),  'UTC', 'c');
			$coupon_code->set('ccd_end_time', $utc_time);	
			$coupon_code->set('ccd_end_time_local', $time_combined);			
		}

		if(empty($_POST['ccd_amount_discount'])){
			$coupon_code->set('ccd_amount_discount', NULL);
		}
		else{
			$coupon_code->set('ccd_amount_discount', $_POST['ccd_amount_discount']);
		}

		if(empty($_POST['ccd_percent_discount'])){
			$_POST['ccd_percent_discount'] = NULL;
		}
		else{
			$coupon_code->set('ccd_percent_discount', $_POST['ccd_percent_discount']);
		}
		
		$_POST['ccd_is_active'] = (bool)$_POST['ccd_is_active'];
		$_POST['ccd_is_stackable'] = (bool)$_POST['ccd_is_stackable'];
		$_POST['ccd_code'] = strtolower($_POST['ccd_code']);

		if(!$_POST['ccd_max_num_uses']){
			$_POST['ccd_max_num_uses'] = 0;
		}
		
		if(!$_POST['ccd_applies_to']){
			$_POST['ccd_applies_to'] = 0;
		}
		
		if(!$_POST['ccd_usr_user_id_affiliate']){
			$_POST['ccd_usr_user_id_affiliate'] = null;
		}


		$editable_fields = array('ccd_code', 'ccd_is_active', 'ccd_usr_user_id_affiliate', 'ccd_is_stackable', 'ccd_max_num_uses', 'ccd_applies_to');

		foreach($editable_fields as $field) {
			$coupon_code->set($field, $_POST[$field]);
		}

		$coupon_code->prepare();
		$coupon_code->save();
		$coupon_code->load();

		//CLEAR ALL ENTRIES
		$searches = array('coupon_id' => $coupon_code->key);	
		$coupon_code_products = new MultiCouponCodeProduct($searches);
		$coupon_code_products->load();
		foreach($coupon_code_products as $coupon_code_product){
			$coupon_code_product->permanent_delete();
		}
			
		//LOAD THE NEW ENTRIES
		if($_POST['ccd_applies_to'] == 3){
			foreach ($_REQUEST['products_list'] as $product_id){
				$coupon_code_product = new CouponCodeProduct(NULL);
				$coupon_code_product->set('ccp_ccd_coupon_code_id', $coupon_code->key);
				$coupon_code_product->set('ccp_pro_product_id', $product_id);
				$coupon_code_product->save();
			}
		}

		LibraryFunctions::redirect('/admin/admin_coupon_code?ccd_coupon_code_id='.$coupon_code->key);
		exit;
	}


	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'coupon-codes',
		'breadcrumbs' => array(
			'Products'=>'/admin/admin_products', 
			'Coupon Codes'=>'/admin/admin_coupon_codes',
			'Edit Coupon Code' => '',
		),
		'session' => $session,
	)
	);	

	
	$pageoptions['title'] = "Edit Coupon Code";
	$page->begin_box($pageoptions);

	// Editing an existing email
	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
	
	$validation_rules = array();
	$validation_rules['grp_name']['required']['value'] = 'true';	 
	//$validation_rules['"event_list[]"']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);	

	?>
	<script type="text/javascript">
	
		function set_applies_choices(){
			var value = $("#ccd_applies_to").val();
			if(value == 3){  //ONE PRICE	
				$("#products_list_container").show();
			}	
			else { 
				$("#products_list_container").hide();				
			}			
		}
		
	
		$(document).ready(function() {
			
			set_applies_choices();
			$("#ccd_applies_to").change(function() {	
				set_applies_choices();
			});	
			
		});
	
		
	</script>
	<?php

	echo $formwriter->begin_form('form', 'POST', '/admin/admin_coupon_code_edit');

	if($coupon_code->key){
		echo $formwriter->hiddeninput('ccd_coupon_code_id', $coupon_code->key);
		echo $formwriter->hiddeninput('action', 'edit');
		$is_active = $coupon_code->get('ccd_is_active');
	}
	else{
		$is_active = 1;
	}
	
	echo $formwriter->textinput('Coupon code', 'ccd_code', NULL, 100, $coupon_code->get('ccd_code'), '', 255, '');	
	
	$optionvals = array("Inactive"=>0, "Active"=>1);
	echo $formwriter->dropinput("Active?", "ccd_is_active", "ctrlHolder", $optionvals, $is_active, '', FALSE);

	echo $formwriter->textinput('Amount of discount ('.$currency_symbol.')', 'ccd_amount_discount', NULL, 100, $coupon_code->get('ccd_amount_discount'), '', 255, '');
	
	echo $formwriter->textinput('or percent of discount', 'ccd_percent_discount', NULL, 100, $coupon_code->get('ccd_percent_discount'), '', 255, '');
	
	$optionvals = array("No"=>0, "Yes"=>1);
	echo $formwriter->dropinput("Is this coupon stackable?", "ccd_is_stackable", "ctrlHolder", $optionvals, $coupon_code->get('ccd_is_stackable'), '', FALSE);
	
	$optionvals = array("All products"=>0, "Subscriptions only"=>1, "One time purchases only"=>2, "Custom (below)"=>3);
	echo $formwriter->dropinput("Applies to", "ccd_applies_to", "ctrlHolder", $optionvals, $coupon_code->get('ccd_applies_to'), '', FALSE);
	
	//GET ALL PRODUCTS
	$searches = array();
	$sort = LibraryFunctions::fetch_variable('sort', 'product_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$products = new MultiProduct(
		$searches,
		array($sort=>$sdirection));
	$products->load();
	$optionvals = $products->get_dropdown_array();	
	
	if ($coupon_code->key) {
		//FILL THE CHECKED VALUES
		$checkedvals = array();
		$coupon_code_products = new MultiCouponCodeProduct(array(
			'coupon_code_id' => $coupon_code->key,
		));
		$coupon_code_products->load();
		foreach ($coupon_code_products as $coupon_code_product){
			$checkedvals[] = $coupon_code_product->get('ccp_pro_product_id');
		}
	}
	else{
		$checkedvals = array();
	}
	$disabledvals = array();
	$readonlyvals = array(); 
	echo $formwriter->checkboxList("Valid products for this code", 'products_list', "ctrlHolder", $optionvals, $checkedvals, $disabledvals, $readonlyvals);	
	
	echo $formwriter->datetimeinput('Start time', 'ccd_start_time', 'ctrlHolder', LibraryFunctions::convert_time($coupon_code->get('ccd_start_time_local'), $session->get_timezone(), $session->get_timezone(), 'Y-m-d h:ia'), '', '', '');

	 
	echo $formwriter->datetimeinput('End time', 'ccd_end_time', 'ctrlHolder', LibraryFunctions::convert_time($coupon_code->get('ccd_end_time_local'), $session->get_timezone(), $session->get_timezone(), 'Y-m-d h:ia'), '', '', '');
	
	$max_display = '';
	if($coupon_code->get('ccd_max_num_uses')){
		$max_display = $coupon_code->get('ccd_max_num_uses');
	}
	echo $formwriter->textinput('Maximum number of uses', 'ccd_max_num_uses', NULL, 10, $max_display, '', 255, '');

	$users = new MultiUser(array('deleted' => FALSE), array('last_name' => 'ASC'));
	$users->load();
	$optionvals = $users->get_dropdown_array();
	echo $formwriter->dropinput("Affiliate User for this coupon", "ccd_usr_user_id_affiliate", "ctrlHolder", $optionvals, $coupon_code->get('ccd_usr_user_id_affiliate'), '', TRUE, FALSE, '/ajax/user_search_ajax?includenone=1');	 

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();

$page->end_box();
	$page->admin_footer();

?>
