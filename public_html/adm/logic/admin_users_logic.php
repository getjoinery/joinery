<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_users_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/phone_number_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '', $get_vars);
	$sort = LibraryFunctions::fetch_variable('sort', 'user_id', 0, '', $get_vars);
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '', $get_vars);
	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '', $get_vars);

	$search_criteria = array();
	if(strstr($searchterm, '@')){
		$search_criteria['email_like'] = $searchterm;
	}
	else if ($searchterm != ''){
		$fsearch = trim(preg_replace('/\s+/', ' ', $searchterm));
		$fsearch = str_replace(' ', ' | ', $fsearch);

		$user_id_list = array();

		$phonesearch = preg_replace('/[^0-9]/', '', $searchterm);
		if(strlen($phonesearch) >= 7) {
			$phone_numbers = new MultiPhoneNumber(
				array('phone_number_like'=>$phonesearch),
				NULL);
			$numphonerecords = $phone_numbers->count_all();
			if($numphonerecords) {
				$phone_numbers->load();
				foreach($phone_numbers as $phone_number) {
					array_push($user_id_list, $phone_number->get('phn_usr_user_id'));
				}
			}
		}

		$search_criteria['user_id_list'] = $user_id_list;
		if(strstr($searchterm, ' ')) {
			$search_criteria['name_like'] = $fsearch;
		}
		else {
			$search_criteria['first_name_like'] = $fsearch;
			$search_criteria['last_name_like'] = $fsearch;
			$search_criteria['nickname_like'] = $fsearch;
		}

		if(is_numeric($searchterm) && (int)$searchterm > 0 && (int)$searchterm < 2147483647) {
			$search_criteria['user_id'] = (int)$searchterm;
		}

	}
	else{
		$search_criteria['not_system_users'] = true;
	}

	//ONLY SHOW DELETED TO SUPER ADMINS
	if($_SESSION['permission'] < 10){
		$search_criteria['deleted'] = false;
	}

	$users = new MultiUser(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset,
		'OR');
	$numrecords = $users->count_all();
	$users->load();

	if($searchterm){
		$title = 'Users matching "'.$searchterm.'"';
	}
	else{
		$title = 'User list';
	}

	$page_vars = array(
		'session' => $session,
		'users' => $users,
		'numrecords' => $numrecords,
		'numperpage' => $numperpage,
		'searchterm' => $searchterm,
		'title' => $title
	);

	return LogicResult::render($page_vars);
}
?>
