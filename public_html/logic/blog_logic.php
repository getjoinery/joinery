<?php

	function blog_logic ($get_vars, $post_vars) {
		$page_vars = array();
		
		require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
		require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Pager.php');
		require_once($_SERVER['DOCUMENT_ROOT'] . '/data/posts_class.php');   	
		
		$settings = Globalvars::get_instance();
		if(!$settings->get_setting('blog_active')){
			//TURNED OFF
			header("HTTP/1.0 404 Not Found");
			echo 'This feature is turned off';
			exit();			
		}

		$session = SessionControl::get_instance();
		$session->set_return();
		
		
		$numperpage = 10;
		$page_offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
		$page_sort = LibraryFunctions::fetch_variable('sort', 'post_id', 0, '');	
		$page_direction = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
		

		
		$params = explode("/", $_REQUEST['path']);
		if($params[1] && $params[2]){
			$page_vars[posts] = MultiPost::get_posts_for_tag($params[2], $numperpage, $page_offset);
			if(empty($page_vars[posts])){
				header("HTTP/1.0 404 Not Found");
				LibraryFunctions::display_404_page();
				exit();					
			}
			$page_vars[title] = 'Blog Posts with tag '.$params[2];
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
		$page_vars[num_pinned_posts] = $pinned_posts->count_all();	
		$pinned_posts->load();	
		$page_vars[pinned_posts] = $pinned_posts;		
		
		
		$page_vars[tags] = MultiPost::get_all_tags();

		$page_vars[pager] = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
		
		return $page_vars;
	}
	
?>