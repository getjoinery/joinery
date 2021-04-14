<?php
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
	$page_offset = LibraryFunctions::fetch_variable('page_offset', 0, 0, '');
	$page_sort = LibraryFunctions::fetch_variable('page_sort', 'post_id', 0, '');	
	$page_direction = LibraryFunctions::fetch_variable('page_direction', 'DESC', 0, '');
	
	$search_criteria = array('published'=>TRUE, 'deleted'=>FALSE);
	
	$params = explode("/", $_REQUEST['path']);
	if($params[1] && $params[2]){
		$posts = MultiPost::get_posts_for_tag($params[2], $numperpage, $page_offset);
		$title = 'Blog Posts with tag '.$params[2];
	}
	else{
		$posts = new MultiPost(
			$search_criteria,
			array($page_sort=>$page_direction),
			$numperpage,
			$page_offset);	
		$numrecords = $posts->count_all();	
		$posts->load();	
	}

	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));

?>