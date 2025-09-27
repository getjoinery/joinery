<?php
	require_once(PathHelper::getIncludePath('includes/ErrorHandler.php'));
	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/controld/data/ctldaccounts_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'ctldaccount_id', 0, '');	
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	

	
	$search_criteria = array();
	
	//ONLY SHOW DELETED TO SUPER ADMINS
	if($_SESSION['permission'] < 10){
		$search_criteria['deleted'] = false;
	}

	$accounts = new MultiCtldAccount(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset);	
	$numrecords = $accounts->count_all();	
	$accounts->load();
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'accounts',
		'page_title' => 'Accounts',
		'readable_title' => 'Accounts',
		'breadcrumbs' => array(
			'Accounts'=>'',
		),
		'session' => $session,
	)
	);
		

	$headers = array("User",  "Plan", "Subscription", "Created", "Status", "Renewal");
	$altlinks = array();
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));	
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Scrolldaddy accounts',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);

	foreach ($accounts as $account){
		$user = new User($account->get('cda_usr_user_id'), TRUE);
		
		//SUBSCRIPTIONS
		$subscriptions = new MultiOrderItem(
		array('user_id' => $account->get('cda_usr_user_id')), //SEARCH CRITERIA
		array('order_item_id' => 'DESC'),  // SORT, SORT DIRECTION
		5, //NUMBER PER PAGE
		NULL //OFFSET
		);
		$subscriptions->load();	
		$numsubscriptions = $subscriptions->count_all();
		$subscription_list = array();
		foreach($subscriptions as $subscription){
			$subscription_list[] = '<a href="/admin/admin_order?ord_order_id='.$subscription->get('odi_ord_order_id').'">'.$subscription->readable_subscription_status().'</a>';
		}
			
		
		
		
		$title = $account->get('cda_title');
		if(!$title){
			$title = 'Untitled';
		}
		
		$rowvalues = array();
		array_push($rowvalues, '<a href="/admin/admin_user?usr_user_id='.$user->key.'">'.$user->display_name().'</a> (<a href="/plugins/controld/admin/admin_ctld_account?account_id='.$account->key.'">Info page</a>)');	
		array_push($rowvalues, $account->readable_plan_name());	
		
		array_push($rowvalues, '('.$numsubscriptions.') <br>'.implode('<br>', $subscription_list));	
		

		array_push($rowvalues, LibraryFunctions::convert_time($account->get('cda_create_time'), 'UTC', $session->get_timezone()));
		//array_push($rowvalues, '('.$user->key.') <a href="/admin/admin_user?usr_user_id='.$user->key.'">'.$user->display_name() .'</a> ');

		if($account->get('cda_delete_time')) {
			$status = 'Deleted';
		} 
		else {
			if($account->get('cda_is_active')) {
				$status = 'Active';
			}
			else{
				$status = 'Inactive';
			}
		}		
		array_push($rowvalues, $status);

		if($account->get('cda_renewal_time')){
			array_push($rowvalues, LibraryFunctions::convert_time($account->get(cda_renewal_time), 'UTC', $session->get_timezone()));
		}
		else{
			array_push($rowvalues, 'n/a');
		}

		$page->disprow($rowvalues);
	}

	$page->endtable($pager);
	$page->admin_footer();
?>

