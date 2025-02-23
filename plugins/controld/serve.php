<?php

//ITEMS.  DEFAULT IS TO USE THE /ITEMS/ SUBDIRECTORY
//TODO: USER CHOOSES URL NAMESPACE




//ADMIN
if($params[0] == 'plugins' && $params[1] == 'controld' && $params[2] == 'admin'){	
	$base_file = ensure_extension($_SERVER['DOCUMENT_ROOT'].'/plugins/controld/admin/'.$params[3],'php');
	if(file_exists($base_file)){
		$is_valid_page = true;
		require_once($base_file); 
		exit();		
	}
}


if($params[0] == 'create_account'){
	$base_file = $_SERVER['DOCUMENT_ROOT'].'/plugins/controld/views/create_account.php';
	require_once($base_file); 
	exit();	
}



?>