<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	PathHelper::requireOnce('/includes/SessionControl.php');
	PathHelper::requireOnce('/includes/LibraryFunctions.php');
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	PathHelper::requireOnce('/data/users_class.php');
	PathHelper::requireOnce('/data/pages_class.php');
	PathHelper::requireOnce('/data/posts_class.php');
	PathHelper::requireOnce('/data/events_class.php');
	PathHelper::requireOnce('/data/locations_class.php');
	PathHelper::requireOnce('/data/videos_class.php');

	$paged = new PublicPage();
	$hoptions = array(
		'is_valid_page' => $is_valid_page,
		'title' => 'Sitemap',
	
	);
	$paged->public_header($hoptions);
	echo PublicPage::BeginPage('Sitemap');
	echo PublicPage::BeginPanel();
			
	$settings = Globalvars::get_instance();
	if($settings->get_setting('page_contents_active')){
		echo '<h2>Pages</h2>';


		$search_criteria = array('published' => TRUE, 'deleted' => false, 'has_link' => TRUE);
		$pages = new MultiPage(
			$search_criteria);	
		$pages->load();

		echo '<ul>';
		foreach ($pages as $page){
			echo '<li><a href="/page/'.$page->get_url().'">'.$page->get('pag_title').'</a></li>';
		}
		echo '</ul>';
	}


	if($settings->get_setting('events_active')){   
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


		echo '<h2>Locations</h2>';
		
		$sort = 'location_id';
		$sdirection = 'ASC';

		$searches = array();
		$searches['deleted'] = FALSE;
		$searches['published'] = true;
		$locations = new MultiLocation(
			$searches,
			array($sort=>$sdirection),
			NULL,
			NULL,
			'AND');
		$locations->load();	

		echo '<ul>';
		foreach ($locations as $location){
			echo '<li><a href="'.$location->get_url().'">'.$location->get('loc_name').'</a></li>';
		}
		echo '</ul>';
	}

	

	if($settings->get_setting('blog_active')){
	
		echo '<h2>Blog Posts</h2>';
	
		$page_sort = LibraryFunctions::fetch_variable('page_sort', 'post_id', 0, '');	
		$page_direction = LibraryFunctions::fetch_variable('page_direction', 'DESC', 0, '');
		$search_criteria = array('published'=>TRUE);
		$search_criteria['deleted'] = false;
		$posts = new MultiPost(
			$search_criteria);	
		$posts->load();		
		
		echo '<ul>';
		foreach ($posts as $post){
			echo '<li><a href="'.$post->get_url().'">'.$post->get('pst_title').'</a></li>';
		}
		echo '</ul>';	
	}

	echo PublicPage::EndPanel();
	echo PublicPage::EndPage();
	$paged->public_footer(array('track'=>TRUE));
?>