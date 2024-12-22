<?php
	header('Content-Type: application/json');

	require_once( __DIR__ . '/../includes/Globalvars.php');
	require_once( __DIR__ . '/../includes/SessionControl.php');
	require_once( __DIR__ . '/../data/users_class.php');


	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$numperpage = 50;
	$aoffset = LibraryFunctions::fetch_variable('aoffset', 0, 0, '');
	$asort = LibraryFunctions::fetch_variable('asort', 'last_name', 0, '');
	$asdirection = LibraryFunctions::fetch_variable('asdirection', 'ASC', 0, '');

	$searchterm = LibraryFunctions::fetch_variable('q', '', 0, '');
	$includenone = LibraryFunctions::fetch_variable('includenone', 0, 0, '');
	$searchdeleted = LibraryFunctions::fetch_variable('searchdeleted', 0, 0, '');

	$search_criteria = array();
	
	if(strstr($searchterm, '@')){
		$search_criteria['email_like'] = $searchterm;
	}
	else if ($searchterm != ''){
		$fsearch = trim(preg_replace('/\s+/', ' ', $searchterm));
		$fsearch = str_replace(' ', ' | ', $fsearch);

		$user_id_list = array();

		$search_criteria['user_id_list'] = $user_id_list;
		if(strstr($searchterm, ' ')) {
			$search_criteria['name_like'] = $fsearch;
		} else {
			$search_criteria['first_name_like'] = $fsearch;
			$search_criteria['last_name_like'] = $fsearch;
			$search_criteria['nickname_like'] = $fsearch;
		}

		if(is_numeric($searchterm) && (int)$searchterm > 0 && (int)$searchterm < 2147483647) {
			$search_criteria['user_id'] = (int)$searchterm;
		}

	}
	
	if($searchdeleted){
		$search_criteria['deleted'] = false;
	}

	$users = new MultiUser(
		$search_criteria,
		array($asort=>$asdirection),
		$numperpage,
		$aoffset,
		'OR');
	$numrecords = $users->count_all();
	$users->load();

$returnlist = array();
foreach ($users as $user) {
 $returnlist['items'][$i]['text'] = $user->display_name();
 $returnlist['items'][$i]['id'] = $user->key;
 $i++;
}

$json = [];

if($includenone){
	$json[] = [
		'id'=>0, 
		'text'=> 'None'
		];
}

foreach ($users as $user) {
    $json[] = [
		'id'=>$user->key, 
		'text'=> $user->display_name().' - '.$user->get('usr_email')
		];
}



echo json_encode($json);
exit();

?>