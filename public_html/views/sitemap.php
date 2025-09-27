<?php
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/pages_class.php'));
	require_once(PathHelper::getIncludePath('data/posts_class.php'));
	require_once(PathHelper::getIncludePath('data/events_class.php'));
	require_once(PathHelper::getIncludePath('data/locations_class.php'));
	require_once(PathHelper::getIncludePath('data/videos_class.php'));

	header("Content-Type: application/xml; charset=UTF-8");

	echo "<?xml version='1.0' encoding='UTF-8'?>\n";
	echo "<urlset xmlns='http://www.sitemaps.org/schemas/sitemap/0.9'>\n";

	$settings = Globalvars::get_instance();
	
	if($settings->get_setting('page_contents_active')){

		$search_criteria = array('published' => TRUE, 'deleted' => false);
		$pages = new MultiPage(
			$search_criteria);	
		$pages->load();
		
		foreach ($pages as $page){
			// Only include pages that have links
			if ($page->get('pag_link') && strlen(trim($page->get('pag_link'))) > 0) {
				echo "    <url>\n";
				echo "        <loc>" . LibraryFunctions::get_absolute_url(htmlspecialchars($page->get_url(), ENT_XML1, 'UTF-8')) . "</loc>\n";
				//echo "        <lastmod>" . date('Y-m-d') . "</lastmod>\n"; // Modify if the lastmod is dynamically available
				echo "        <changefreq>monthly</changefreq>\n";
				echo "        <priority>0.8</priority>\n";
				echo "    </url>\n";
			}
		}
	}

	if($settings->get_setting('events_active')){   
		
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

		foreach ($events as $event){
			echo "    <url>\n";
			echo "        <loc>" . LibraryFunctions::get_absolute_url(htmlspecialchars($event->get_url(), ENT_XML1, 'UTF-8')) . "</loc>\n";
			//echo "        <lastmod>" . date('Y-m-d') . "</lastmod>\n"; // Modify if the lastmod is dynamically available
			echo "        <changefreq>monthly</changefreq>\n";
			echo "        <priority>0.8</priority>\n";
			echo "    </url>\n";
		}

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

		foreach ($locations as $location){
			echo "    <url>\n";
			echo "        <loc>" . LibraryFunctions::get_absolute_url(htmlspecialchars($location->get_url(), ENT_XML1, 'UTF-8')) . "</loc>\n";
			//echo "        <lastmod>" . date('Y-m-d') . "</lastmod>\n"; // Modify if the lastmod is dynamically available
			echo "        <changefreq>monthly</changefreq>\n";
			echo "        <priority>0.8</priority>\n";
			echo "    </url>\n";
		}
	}

	if($settings->get_setting('blog_active')){
	
		$page_sort = LibraryFunctions::fetch_variable('page_sort', 'post_id', 0, '');	
		$page_direction = LibraryFunctions::fetch_variable('page_direction', 'DESC', 0, '');
		$search_criteria = array('published'=>TRUE);
		$search_criteria['deleted'] = false;
		$posts = new MultiPost(
			$search_criteria);	
		$posts->load();		
		
		foreach ($posts as $post){
			echo "    <url>\n";
			echo "        <loc>" . LibraryFunctions::get_absolute_url(htmlspecialchars($post->get_url(), ENT_XML1, 'UTF-8')) . "</loc>\n";
			//echo "        <lastmod>" . date('Y-m-d') . "</lastmod>\n"; // Modify if the lastmod is dynamically available
			echo "        <changefreq>monthly</changefreq>\n";
			echo "        <priority>0.8</priority>\n";
			echo "    </url>\n";
		}	
	}

	echo "</urlset>\n";
?>