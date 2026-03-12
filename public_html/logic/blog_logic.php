<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

	function blog_logic ($get_vars, $post_vars) {
		$page_vars = array();
		
		require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
		require_once(PathHelper::getIncludePath('includes/Pager.php'));
		require_once(PathHelper::getIncludePath('data/posts_class.php'));   	
		
		$settings = Globalvars::get_instance();
		$page_vars['settings'] = $settings;
		if(!$settings->get_setting('blog_active')){
			//TURNED OFF
			return LogicResult::error('This feature is turned off');			
		}

		$session = SessionControl::get_instance();
		$page_vars['session'] = $session;
		$session->set_return();
		
		
		$numperpage = 10;
		$page_offset = LibraryFunctions::fetch_variable_local($get_vars, 'offset', 0, 'notrequired', '', 'safemode', 'int');
		$page_sort = LibraryFunctions::fetch_variable_local($get_vars, 'sort', 'post_id', 0, 'notrequired', 'safemode', 'int');	
		$page_direction = LibraryFunctions::fetch_variable_local($get_vars, 'sdirection', 'DESC', 'notrequired', '', 'safemode', 'string');
		

		
		$params = explode("/", $_REQUEST['path']);
		if($params[1] && $params[2]){
			$page_vars['posts'] = MultiPost::get_posts_for_tag($params[2], $numperpage, $page_offset);
			$numrecords = MultiPost::get_num_posts_for_tag($params[2], $numperpage, $page_offset);
			if(empty($page_vars['posts'])){
				return LogicResult::error('No blog posts found for this tag.');
			}
			$page_vars['title'] = 'Blog Posts with tag '.$params[2];
		}
		else{
			$search_criteria = array('published'=>TRUE, 'deleted'=>FALSE, 'listed'=>TRUE);
			$posts = new MultiPost(
				$search_criteria,
				array($page_sort=>$page_direction),
				$numperpage,
				$page_offset);	
			$numrecords = $posts->count_all();	
			$posts->load();	
			$page_vars['posts'] = $posts;
			$page_vars['title'] = 'Blog';
		}
		
		$search_criteria = array('published'=>TRUE, 'deleted'=>FALSE, 'listed'=>TRUE, 'pinned'=>TRUE);
		$pinned_posts = new MultiPost(
			$search_criteria,
			array($page_sort=>$page_direction),
			$numperpage,
			$page_offset,
			'AND');	
		$page_vars['num_pinned_posts'] = $pinned_posts->count_all();	
		$pinned_posts->load();	
		$page_vars['pinned_posts'] = $pinned_posts;		
		
		
		$page_vars['tags'] = Group::get_groups_in_category('post_tag', false, 'names');

		$page_vars['pager'] = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
		
		return LogicResult::render($page_vars);
	}
	
?>