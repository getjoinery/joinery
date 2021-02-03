<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/page_contents_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/posts_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');

	$page = new PublicPage();
	$hoptions = array(
		//'title' => $sitename,
		//'description' => 'Integral Zen',
		'body_id' => 'about-integral-zen',
	);
	$page->public_header($hoptions);
	echo PublicPage::BeginPage('Sitemap');
	
	echo '<h2>Pages</h2>';


	$search_criteria = array('published' => TRUE, 'has_link' => TRUE);
	$page_contents = new MultiPageContent(
		$search_criteria);	
	$page_contents->load();

	echo '<ul>';
	foreach ($page_contents as $page_content){
		echo '<li><a href="/page/'.$page_content->get('pac_link').'">'.$page_content->get('pac_location_name').'</a></li>';
	}
	echo '<li><a href="/dana">Dana (donations)</a></li>';
	echo '<li><a href="/newsletter">Newsletter</a></li>';
	echo '<li><a href="/password-reset-1">Reset Password</a></li>';
	echo '</ul>';

	echo '<h2>Events</h2>';
	
	$sort = 'start_time';
	$sdirection = 'ASC';

	$searches = array();
	$searches['deleted'] = FALSE;
	$searches['visibility'] = 1;
	$searches['status'] = 1;
	$events = new MultiEvent(
		$searches,
		array($sort=>$sdirection),
		NULL,
		NULL,
		'AND');
	$events->load();	

	echo '<ul>';
	foreach ($events as $event){
		echo '<li><a href="'.$event->get_url().'">'.$event->get('evt_name').'</a></li>';
	}
	echo '</ul>';

	
	$settings = Globalvars::get_instance();
	if($settings->get_setting('blog_active')){
	
		echo '<h2>Blog Posts</h2>';
	
		$page_sort = LibraryFunctions::fetch_variable('page_sort', 'post_id', 0, '');	
		$page_direction = LibraryFunctions::fetch_variable('page_direction', 'DESC', 0, '');
		$search_criteria = array('published'=>TRUE);
		$posts = new MultiPost(
			$search_criteria);	
		$posts->load();		
		
		echo '<ul>';
		foreach ($posts as $post){
			echo '<li><a href="'.$post->get_url().'">'.$post->get('pst_title').'</a></li>';
		}
		echo '</ul>';	
	}

	echo PublicPage::EndPage();
	$page->public_footer(array('track'=>TRUE, 'is_404'=> 1));
?>