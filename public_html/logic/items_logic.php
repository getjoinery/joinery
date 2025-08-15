<?php

	function items_logic ($get_vars, $post_vars) {
		$page_vars = array();
		
		require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
		require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Pager.php');
		require_once(LibraryFunctions::get_plugin_file_path('items_class.php', 'items', '/data', 'system'));  	
		
		$settings = Globalvars::get_instance();
		$page_vars['settings'] = $settings;
		/*
		if(!$settings->get_setting('_active')){
			//TURNED OFF
			header("HTTP/1.0 404 Not Found");
			echo 'This feature is turned off';
			exit();			
		}
		*/

		$session = SessionControl::get_instance();
		$page_vars['session'] = $session;
		$session->set_return();
		
		
		$numperpage = 10;
		//$page_offset = LibraryFunctions::fetch_variable_local($get_vars, 'offset', 0, 'notrequired', '', 'safemode', 'int');
		//$page_sort = LibraryFunctions::fetch_variable_local($get_vars, 'sort', 'post_id', 0, 'notrequired', 'safemode', 'int');	
		//$page_direction = LibraryFunctions::fetch_variable_local($get_vars, 'sdirection', 'DESC', 'notrequired', '', 'safemode', 'string');
		

		

		$search_criteria = array('published'=>TRUE, 'deleted'=>FALSE, 'listed'=>TRUE);
		$items = new MultiItem(
			$search_criteria,
			//array($page_sort=>$page_direction),
			//$numperpage,
			//$page_offset
		);	
		$numrecords = $items->count_all();	
		$items->load();	
		$page_vars['items'] = $items;
		$page_vars['title'] = 'Items';
		
		
		
		//$page_vars['tags'] = Group::get_groups_in_category('post_tag', false, 'names');

		$page_vars['pager'] = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
		
		return $page_vars;
	}
	
?>