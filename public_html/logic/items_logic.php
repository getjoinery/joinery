<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

	function items_logic ($get_vars, $post_vars) {
		$page_vars = array();
		
		PathHelper::requireOnce('includes/SessionControl.php');
PathHelper::requireOnce('includes/LogicResult.php');
		PathHelper::requireOnce('includes/Pager.php');
		require_once(PathHelper::getIncludePath('plugins/items/data/items_class.php'));  	
		
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
		
		return LogicResult::render($page_vars);
	}
	
?>