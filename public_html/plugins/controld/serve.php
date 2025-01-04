<?php

//ITEMS.  DEFAULT IS TO USE THE /ITEMS/ SUBDIRECTORY
//TODO: USER CHOOSES URL NAMESPACE

if($params[0] == 'create_account'){
	$base_file = $_SERVER['DOCUMENT_ROOT'].'/plugins/controld/views/create_account.php';
	require_once($base_file); 
	exit();	
}


//if($settings->get_setting('blog_active')){
	/*
	if($params[0] == 'items'){
		if(!$params[1] || $params[1] == 'tag'){
			$template_file = $template_directory.'/plugins/views/items.php';
			$base_file = $_SERVER['DOCUMENT_ROOT'].'/plugins/items/views/items.php';

			if(file_exists($template_file)){
				$is_valid_page = true;
				require_once($template_file);
				exit();
			}
			else if(file_exists($base_file)){
				$is_valid_page = true;
				require_once($base_file); 
				exit();		
			}				
		}
	}
	else if($params[0] == 'item'){
	
		require_once(LibraryFunctions::get_plugin_file_path('items_class.php', 'items', '/data', 'system'));
		
		$item = Item::get_by_link($params[1], true);	

		$template_file = $template_directory.'/item.php';
		$base_file = $_SERVER['DOCUMENT_ROOT'].'/views/item.php';
		
		if(file_exists($template_file)){
			$is_valid_page = true;
			require_once($template_file);
			exit();
		}
		else if(file_exists($base_file)){
			$is_valid_page = true;
			require_once($base_file); 
			exit();		
		}		
	}
	*/
//}

?>