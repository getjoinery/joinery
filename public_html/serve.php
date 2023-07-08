<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
$params = explode("/", $_REQUEST['path']);

$full_path = $_REQUEST['path'];
$static_routes_path = rtrim($_REQUEST['path'], '/');
$static_routes_path = ltrim($static_routes_path, '/');

$settings = Globalvars::get_instance();
$site_template = $settings->get_setting('site_template');
$template_directory = $_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template;

//FOR STATS.  WE WILL ONLY RECORD HITS TO ACTUAL PAGES.
$is_valid_page = false;

//ALLOW CURRENT SITE TO OVERRIDE OR ADD ROUTES
$template_file = $template_directory.'/serve.php';
if(file_exists($template_file)){
	require_once($template_file);
}

//ROBOTS.TXT
if($params[0] == 'robots.txt'){
	//$template_file = $template_directory.'/robots.php';
	$base_file = $_SERVER['DOCUMENT_ROOT'] . '/views/robots.php';
	if(file_exists($template_file)){
		require_once($template_file);
		exit();
	}
	else{
		require_once($base_file); 
		exit();		
	}
}

if($settings->get_setting('urls_active')){

	//CHECK REDIRECTS
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/urls_class.php');
	$urls = new MultiUrl(
		array('deleted'=>false, 'incoming'=> mb_convert_encoding($static_routes_path, 'UTF-8', 'UTF-8')),
		NULL,
		1,
		0,
		'AND');	
	$urls->load();
			
	if($urls->count()){
		$url = $urls->get(0);

		if($url->get('url_redirect_url')){		

			if($url->get('url_type') == 301){
				header("HTTP/1.1 301 Moved Permanently");
				header("Location: ".$url->get('url_redirect_url'));
				exit();
			}
			else{
				header("HTTP/1.1 302 Found");
				header("Location: ".$url->get('url_redirect_url'));
				exit();			
			}
		}
		else{
			require_once(LibraryFunctions::display_404_page());				
			//THIS IS TURNED OFF
			//include($url->get('url_redirect_file'));
			exit();	
		}
	}
}

//CHECK STATIC FILES DIRECTORY (/VAR/WWW/HTML/$SITE/STATIC_FILES)
if($params[0] == 'static_files'){
	$static_files_dir = $settings->get_setting('static_files_dir');
	if(!static_files_dir){
		throw new SystemDisplayableError('static_files_dir is missing.');
		exit();
	}
	if($params[4]){
		$file = $static_files_dir.'/'.$params[1].'/'.$params[2].'/'.$params[3].'/'.$params[4];
	}
	else if($params[3]){
		$file = $static_files_dir.'/'.$params[1].'/'.$params[2].'/'.$params[3];
	}
	else if($params[2]){
		$file = $static_files_dir.'/'.$params[1].'/'.$params[2];
	}
	else{
		$file = $static_files_dir.'/'.$params[1]; 
	}

	//ORIGINAL FILE
	if(file_exists($file)){
		$seconds_to_cache = 43200;
		$ts = gmdate("D, d M Y H:i:s", time() + $seconds_to_cache) . " GMT";
		header("Expires: $ts");
		header("Pragma: cache");
		header("Cache-Control: max-age=$seconds_to_cache");
		$the_content_type = 'Content-type: '.mime_content_type($file);
		header($the_content_type);
		readfile($file);
		exit();
	}
}

//FILES
if($settings->get_setting('files_active')){
	if($params[0] == 'uploads'){
		$upload_dir = $settings->get_setting('upload_dir');
		if($params[2]){
			//RESIZED FILE
			$file = $upload_dir.'/'.$params[1].'/'.$params[2];
		}
		else{
			$file = $upload_dir.'/'.$params[1];
		}
		//ORIGINAL FILE
		if(file_exists($file)){
			require_once($_SERVER['DOCUMENT_ROOT'] . '/data/files_class.php');
			$file_obj = File::get_by_name(basename($file));
			if($file_obj){
				if($file_obj->get('fil_delete_time')){
					LibraryFunctions::display_404_page();
					exit;
				}

				if($file_obj->get('fil_min_permission')){
					require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
					$session = SessionControl::get_instance();
					if (!isset($_SESSION['loggedin']) || !$_SESSION['loggedin']) {
						echo 'Insufficient permissions.  Must be logged in.';
						exit;
					}
					if ($session->$session->get_permission() < $file_obj->get('fil_min_permission')){
						echo 'Insufficient permissions';
						exit;
					}
					
					if ($group_id = $file_obj->get('fil_grp_group_id')){
						require_once($_SERVER['DOCUMENT_ROOT'] . '/data/groups_class.php');
						//CHECK TO SEE IF USER IS IN AUTHORIZED GROUP
						$group = new Group($group_id, TRUE);
						if(!$group->is_member_in_group($session->get_user_id())){
							echo 'Insufficient group permissions';
							exit;
						}
					}
					
					if ($event_id = $file_obj->get('fil_evt_event_id')){
						require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_registrants_class.php');
						//CHECK TO SEE IF USER IS IN AUTHORIZED EVENT
						$searches['user_id'] = $session->get_user_id();
						$searches['event_id'] = $event_id;
						$searches['expired'] = false;
						$event_registrations = new MultiEventRegistrant(
							$searches,
							NULL, //array('event_id'=>'DESC'),
							NULL,
							NULL);
						$numeventsregistrations = $event_registrations->count_all();	

						if(!$numeventsregistrations){
							echo 'Insufficient event permissions';
							exit;
						}
					}
				}
						
				$seconds_to_cache = 43200;
				$ts = gmdate("D, d M Y H:i:s", time() + $seconds_to_cache) . " GMT";
				header("Expires: $ts");
				header("Pragma: cache");
				header("Cache-Control: max-age=$seconds_to_cache");
				$the_content_type = 'Content-type: '.mime_content_type($file);
				header($the_content_type);
				readfile($file);
				exit();
			}
			else{
				LibraryFunctions::display_404_page();		
			}
		}
		else{
			LibraryFunctions::display_404_page();		
		}	
	}
}


//HOMEPAGE
if(!$params[0]){
	$template_file = $template_directory.'/index.php';
	$base_file = $_SERVER['DOCUMENT_ROOT'].'/views/index.php';
	$is_valid_page = true;

	if(file_exists($template_file)){
		require_once($template_file);
		exit();
	}
	else if(file_exists($base_file)){
		require_once($base_file); 
		exit();		
	}
}

//ADMIN SECTION
if($params[0] == 'admin'){
	if(!$params[1]){
		require_once(LibraryFunctions::display_404_page());		
	}
	$theme_file = $template_directory.'/adm/'.$params[1].'.php';
	$base_file = $_SERVER['DOCUMENT_ROOT'] . '/adm/'.$params[1].'.php';

	if(file_exists($theme_file)){
		require_once($theme_file);
		exit();
	}
	else if(file_exists($base_file)){
		require_once($base_file); 
		exit();		
	}
}

//PROFILE SECTION
if($params[0] == 'profile'){
	if($params[1]){
		$template_file = $template_directory.'/profile/'.$params[1].'.php';
		$base_file = $_SERVER['DOCUMENT_ROOT'].'/views/profile/'.$params[1].'.php';
	}
	else{
		$template_file = $template_directory.'/profile/profile.php';
		$base_file = $_SERVER['DOCUMENT_ROOT'].'/views/profile/profile.php';
	}
	
	$is_valid_page = true;

	if(file_exists($template_file)){
		require_once($template_file);
		exit();
	}
	else if(file_exists($base_file)){
		require_once($base_file); 
		exit();		
	}
}

//BLOG.  DEFAULT IS TO USE THE /POST/ SUBDIRECTORY
if($settings->get_setting('blog_active')){
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/posts_class.php');
	$blog_subdirectory = ltrim($settings->get_setting('blog_subdirectory'), '/');

	if($params[0] == $blog_subdirectory && !$params[1]){
		$template_file = $template_directory.'/blog.php';
		$base_file = $_SERVER['DOCUMENT_ROOT'].'/views/blog.php';
		
		$is_valid_page = true; 

		if(file_exists($template_file)){
			require_once($template_file);
			exit();
		}
		else if(file_exists($base_file)){
			require_once($base_file); 
			exit();		
		}
	}
	else if($params[0] == $blog_subdirectory && $params[1] == 'tag'){
		$template_file = $template_directory.'/blog.php';
		$base_file = $_SERVER['DOCUMENT_ROOT'].'/views/blog.php';
		
		$is_valid_page = true; 

		if(file_exists($template_file)){
			require_once($template_file);
			exit();
		}
		else if(file_exists($base_file)){
			require_once($base_file); 
			exit();		
		}		
	}
	if($params[0] == $blog_subdirectory){
		$post = Post::get_by_link($params[1], true);	
	}
	else{
		//CHECK BLOG URLS THAT ARE NOT UNDER /POST/
		$post = Post::get_by_link('/'.$_REQUEST['path'], true);

		//CHECK WITHOUT THE SLASH
		if(!$post){
			$post = Post::get_by_link($_REQUEST['path'], true);	
		}			
	}
	
	if($post){
		$template_file = $template_directory.'/post.php';
		$base_file = $_SERVER['DOCUMENT_ROOT'].'/views/post.php';
		
		$is_valid_page = true;
		
		if(file_exists($template_file)){
			require_once($template_file);
			exit();
		}
		else if(file_exists($base_file)){
			require_once($base_file); 
			exit();		
		}		
	}

}

//PAGE CONTENTS.  DEFAULT IS TO USE THE /PAGE/ SUBDIRECTORY
if($params[0] == 'page'){
	if($settings->get_setting('page_contents_active')){
		require_once($_SERVER['DOCUMENT_ROOT'].'/data/pages_class.php');

		$page = Page::get_by_link($params[1], true);		

		$template_file = $template_directory.'/page.php';
		$base_file = $_SERVER['DOCUMENT_ROOT'].'/views/page.php';
		
		$is_valid_page = true;
		
		if(file_exists($template_file)){
			require_once($template_file);
			exit();
		}
		else if(file_exists($base_file)){
			require_once($base_file); 
			exit();		
		}		
	}	
}

//LOCATIONS.  DEFAULT IS TO USE THE /LOCATION/ SUBDIRECTORY
if($params[0] == 'location'){
	if($settings->get_setting('events_active')){
		require_once($_SERVER['DOCUMENT_ROOT'].'/data/locations_class.php');

		$location = Location::get_by_link($params[1], true);		

		$template_file = $template_directory.'/location.php';
		$base_file = $_SERVER['DOCUMENT_ROOT'].'/views/location.php';
		
		$is_valid_page = true;
		
		if(file_exists($template_file)){
			require_once($template_file);
			exit();
		}
		else if(file_exists($base_file)){
			require_once($base_file); 
			exit();		
		}		
	}	
}

//MAILING LISTS.  DEFAULT IS TO USE THE /LIST/ SUBDIRECTORY
if($params[0] == 'list'){
	//if($settings->get_setting('mailing_lists_active')){
		require_once($_SERVER['DOCUMENT_ROOT'].'/data/mailing_lists_class.php');

		$mailing_list = MailingList::get_by_link($params[1], true);		

		$template_file = $template_directory.'/list.php';
		$base_file = $_SERVER['DOCUMENT_ROOT'].'/views/list.php';
		
		$is_valid_page = true;
		
		if(file_exists($template_file)){
			require_once($template_file);
			exit();
		}
		else if(file_exists($base_file)){
			require_once($base_file); 
			exit();		
		}		
	//}	
}

//PRODUCTS.  DEFAULT IS TO USE THE /PRODUCT/ SUBDIRECTORY
if($params[0] == 'product'){
	if($settings->get_setting('products_active')){
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/products_class.php');

		$product = Product::get_by_link($params[1], true);	
		$product_id = $product->key;
		
		$template_file = $template_directory.'/product.php';
		$base_file = $_SERVER['DOCUMENT_ROOT'].'/views/product.php';
		
		$is_valid_page = true;
		
		if(file_exists($template_file)){
			require_once($template_file);
			exit();
		}
		else if(file_exists($base_file)){
			require_once($base_file); 
			exit();		
		}		
	}	
}

//ROOT PAGES
if($params[0]){
	$template_file = $template_directory.'/'.$params[0].'.php';
	$base_file = $_SERVER['DOCUMENT_ROOT'].'/views/'.$params[0].'.php';
	
	$is_valid_page = true; 

	if(file_exists($template_file)){
		require_once($template_file);
		exit();
	}
	else if(file_exists($base_file)){
		require_once($base_file); 
		exit();		
	}
}




	
LibraryFunctions::display_404_page();		

?>