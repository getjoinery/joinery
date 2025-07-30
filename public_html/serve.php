<?php
require_once(__DIR__ . '/includes/PathHelper.php');

PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
$params = explode("/", $_REQUEST['path']);

$full_path = $_REQUEST['path'];
$static_routes_path = rtrim($_REQUEST['path'], '/');
$static_routes_path = ltrim($static_routes_path, '/');

$settings = Globalvars::get_instance();
$session = SessionControl::get_instance();
$theme_template = $settings->get_setting('theme_template');
$template_directory = PathHelper::getIncludePath('theme/'.$theme_template);




//FOR STATS.  WE WILL ONLY RECORD HITS TO ACTUAL PAGES.
$is_valid_page = false;

//ALLOW CURRENT SITE TO OVERRIDE OR ADD ROUTES
$template_file = $template_directory.'/serve.php';
if(file_exists($template_file)){
	require_once($template_file);
}

/*
if($_GET['act_code']){
	PathHelper::requireOnce('includes/Activation.php');
	$activated = Activation::ActivateUser($act_code);
}
*/

//ROBOTS.TXT
if($params[0] == 'robots.txt'){
	$template_file = $template_directory.'/views/robots.php';
	$base_file = PathHelper::getIncludePath('views/robots.php');
	if(file_exists($template_file)){
		require_once($template_file);
		exit();
	}
	else{
		require_once($base_file); 
		exit();		
	}
}

//FAVICON.  TEMPORARY UNTIL WE FIGURE OUT HOW TO HANDLE
if($params[0] == 'favicon.ico'){
	$base_file = $_REQUEST['path'];
	if(file_exists($base_file)){
		$seconds_to_cache = 43200;
		$ts = gmdate("D, d M Y H:i:s", time() + $seconds_to_cache) . " GMT";
		header("Expires: $ts");
		header("Pragma: cache");
		header("Cache-Control: max-age=$seconds_to_cache");
		$the_content_type = 'Content-type: '.mime_type($base_file);
		header($the_content_type);
		readfile($base_file);
		exit();
	}
}

//MAIN INCLUDE FILES.  LOAD ANYTHING UNDER /includes
if($params[0] == 'includes'){
	$base_file = PathHelper::getRootDir().$_SERVER['REQUEST_URI'];
	if(file_exists($base_file)){
		$seconds_to_cache = 43200;
		$ts = gmdate("D, d M Y H:i:s", time() + $seconds_to_cache) . " GMT";
		header("Expires: $ts");
		header("Pragma: cache");
		header("Cache-Control: max-age=$seconds_to_cache");
		$the_content_type = 'Content-type: '.mime_type($base_file);
		header($the_content_type);
		readfile($base_file);
		exit();
	}
	else{
		LibraryFunctions::display_404_page();
	}
}

//PLUGIN INCLUDE FILES.  LOAD ANYTHING UNDER /plugins/PLUGIN/includes
if($params[0] == 'plugins' && $params[2] == 'includes'){
	$base_file = PathHelper::getRootDir().$_SERVER['REQUEST_URI'];

	if(file_exists($base_file)){
		// Check if plugin is active before serving include files
		$plugin_name = $params[1]; // Extract plugin name from URL
		PathHelper::requireOnce('data/plugins_class.php');
		
		if(Plugin::is_plugin_active($plugin_name)){
			$seconds_to_cache = 43200;
			$ts = gmdate("D, d M Y H:i:s", time() + $seconds_to_cache) . " GMT";
			header("Expires: $ts");
			header("Pragma: cache");
			header("Cache-Control: max-age=$seconds_to_cache");
			$the_content_type = 'Content-type: '.mime_type($base_file);
			header($the_content_type);
			readfile($base_file);
			exit();
		}
		else{
			// Plugin not active - return 404
			LibraryFunctions::display_404_page();
		}
	}
	else{
		LibraryFunctions::display_404_page();
	}
}

//THEME INCLUDE FILES.  LOAD ANYTHING UNDER /theme/
if($params[0] == 'theme'){

	$base_file = PathHelper::getRootDir().$_SERVER['REQUEST_URI'];
	if(file_exists($base_file)){
		$seconds_to_cache = 43200;
		$ts = gmdate("D, d M Y H:i:s", time() + $seconds_to_cache) . " GMT";
		header("Expires: $ts");
		header("Pragma: cache");
		header("Cache-Control: max-age=$seconds_to_cache");
		$the_content_type = 'Content-type: '.mime_type($base_file);
		header($the_content_type);
		readfile($base_file);
		exit();
	}
	else{
		LibraryFunctions::display_404_page();
	}
}


//REDIRECT URLS
if($settings->get_setting('urls_active')){

	//CHECK REDIRECTS
	PathHelper::requireOnce('data/urls_class.php');
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

//CHECK API
if($params[0] == 'api' && $params[1] == 'v1'){
	$theme_file = $template_directory.'/api/apiv1.php';
	$base_file = PathHelper::getIncludePath('api/apiv1.php');

	if(file_exists($theme_file)){
		require_once($theme_file);
		exit();
	}
	else if(file_exists($base_file)){
		require_once($base_file); 
		exit();		
	}
}

//AJAX DIRECTORY
if($params[0] == 'ajax'){
	if($params[1]){
		
		//LOAD THE AJAX FILES FROM THE PLUGINS
		$plugins = LibraryFunctions::list_plugins();
		foreach($plugins as $plugin){
			$plugin_file = ensure_extension(PathHelper::getIncludePath('plugins/'.$plugin.'/ajax/'.$params[1]), 'php');
			if(file_exists($plugin_file)){
				// Check if plugin is active before loading AJAX file
				PathHelper::requireOnce('data/plugins_class.php');
				
				if(Plugin::is_plugin_active($plugin)){
					$is_valid_page = true;
					require_once($plugin_file);
					exit();
				}
				// If plugin is not active, skip this AJAX file
			}
		}	
		
		$base_file = ensure_extension(PathHelper::getIncludePath('ajax/'.$params[1]),'php');
		if(file_exists($base_file)){
			$is_valid_page = true;
			require_once($base_file); 
			exit();		
		}
	}
	else{
		LibraryFunctions::display_404_page();	
	}  
}

//CHECK STATIC FILES DIRECTORY (/VAR/WWW/HTML/$SITE/STATIC_FILES)
if($params[0] == 'static_files'){
	$static_files_dir = $settings->get_setting('static_files_dir');
	if(!$static_files_dir){
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
		
		//DO NOT CACHE UPGRADES
		if(str_contains($file, '.upg.zip')){
			$seconds_to_cache = 10;
		}
		else{
			$seconds_to_cache = 43200;
		}
		$ts = gmdate("D, d M Y H:i:s", time() + $seconds_to_cache) . " GMT";
		header("Expires: $ts");
		header("Pragma: cache");
		header("Cache-Control: max-age=$seconds_to_cache");
		$the_content_type = 'Content-type: '.mime_type($file);
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
			PathHelper::requireOnce('data/files_class.php');
			$file_obj = File::get_by_name(basename($file));

			PathHelper::requireOnce('includes/SessionControl.php');
					
			if($file_obj && $file_obj->authenticate_read(array('session'=>$session))){	
				
				$seconds_to_cache = 43200;
				$ts = gmdate("D, d M Y H:i:s", time() + $seconds_to_cache) . " GMT";
				header("Expires: $ts");
				header("Pragma: cache");
				header("Cache-Control: max-age=$seconds_to_cache");
				$the_content_type = 'Content-type: '.mime_type($file);
				header($the_content_type);
				readfile($file);
				exit();

			}
			else{
				LibraryFunctions::display_404_page();		
			}	
		}
	}
}

//VIDEOS
if($settings->get_setting('videos_active')){
	if($params[0] == 'video'){
		PathHelper::requireOnce('data/videos_class.php');
		$video = Video::get_by_link($params[1], true);
		
		PathHelper::requireOnce('includes/SessionControl.php');
		$session = SessionControl::get_instance();
		if($video && $video->authenticate_read(array('session'=>$session))){		
				$template_file = $template_directory.'/views/video.php';
				$base_file = PathHelper::getIncludePath('views/video.php');
				
				$is_valid_page = true;
				
				if(file_exists($template_file)){
					require_once($template_file);
					exit();
				}
				else if(file_exists($base_file)){
					require_once($base_file); 
					exit();		
				}
				exit();
		}
		else{
			LibraryFunctions::display_404_page();		
		}	
	}
}

//HOMEPAGE
if(!$params[0]){
	$alternate_page = $settings->get_setting('alternate_loggedin_homepage');
	if($alternate_page && $session->is_logged_in()){
		
		$page_pieces = explode('/', $alternate_page);

		//IF IT IS THE BLOG
		if($page_pieces[1] == 'blog'){
			$template_file = $template_directory.'/views/blog.php';
			$base_file = PathHelper::getIncludePath('views/blog.php');

		}
		else if($page_pieces[1] == 'page'){
			//IF IT IS A PAGE
			if($settings->get_setting('page_contents_active')){
				PathHelper::requireOnce('data/pages_class.php');

				$page = Page::get_by_link($page_pieces[2], true);		

				$template_file = $template_directory.'/views/page.php';
				$base_file = PathHelper::getIncludePath('views/page.php');
						
			}	
		}
		else{
			$template_file = $template_directory.$alternate_page;
			$base_file = PathHelper::getRootDir().$alternate_page;			
			
		}
		
	}
	else if($alternate_page = $settings->get_setting('alternate_homepage')){
		$page_pieces = explode('/', $alternate_page);

		//IF IT IS THE BLOG
		if($page_pieces[1] == 'blog'){
			$template_file = $template_directory.'/views/blog.php';
			$base_file = PathHelper::getIncludePath('views/blog.php');

		}
		else if($page_pieces[1] == 'page'){
			//IF IT IS A PAGE
			if($settings->get_setting('page_contents_active')){
				PathHelper::requireOnce('data/pages_class.php');

				$page = Page::get_by_link($page_pieces[2], true);		

				$template_file = $template_directory.'/views/page.php';
				$base_file = PathHelper::getIncludePath('views/page.php');
						
			}	
		}
		else{
			$template_file = $template_directory.$alternate_page;
			$base_file = PathHelper::getRootDir().$alternate_page;			
		}		
		
	}
	else{
		$template_file = $template_directory.'/views/index.php';
		$base_file = PathHelper::getIncludePath('views/index.php');		
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

//PROFILE SECTION
if($params[0] == 'profile'){
	if($params[1]){
		$template_file = ensure_extension($template_directory.'/views/profile/'.$params[1],'php');
		$base_file = ensure_extension(PathHelper::getIncludePath('views/profile/'.$params[1]),'php');
	}
	else{
		$template_file = $template_directory.'/views/profile/profile.php';
		$base_file = PathHelper::getIncludePath('views/profile/profile.php');
	}
	
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

//BLOG.  DEFAULT IS TO USE THE /POST/ SUBDIRECTORY
if($settings->get_setting('blog_active')){
	if($params[0] == 'posts'){
		if(!$params[1] || $params[1] == 'tag'){
			$template_file = $template_directory.'/views/blog.php';
			$base_file = PathHelper::getIncludePath('views/blog.php');
			
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
	else if($params[0] == 'post'){
	
		PathHelper::requireOnce('data/posts_class.php');
		
		$post = Post::get_by_link($params[1], true);	

		$template_file = $template_directory.'/views/post.php';
		$base_file = PathHelper::getIncludePath('views/post.php');
		
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

//PAGE CONTENTS.  DEFAULT IS TO USE THE /PAGE/ SUBDIRECTORY
if($params[0] == 'page'){
	if($settings->get_setting('page_contents_active')){
		PathHelper::requireOnce('data/pages_class.php');

		$page = Page::get_by_link($params[1], true);		

		$template_file = $template_directory.'/views/page.php';
		$base_file = PathHelper::getIncludePath('views/page.php');
		
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

//LOCATIONS.  DEFAULT IS TO USE THE /LOCATION/ SUBDIRECTORY
if($params[0] == 'location'){
	if($settings->get_setting('events_active')){
		PathHelper::requireOnce('data/locations_class.php');

		$location = Location::get_by_link($params[1], true);		

		$template_file = $template_directory.'/views/location.php';
		$base_file = PathHelper::getIncludePath('views/location.php');
		
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

//EVENTS.  DEFAULT IS TO USE THE /EVENT/ SUBDIRECTORY
if($params[0] == 'event'){
	if($settings->get_setting('events_active')){
		PathHelper::requireOnce('data/events_class.php');

		$event = Event::get_by_link($params[1], true);		

		$template_file = $template_directory.'/views/event.php';
		$base_file = PathHelper::getIncludePath('views/event.php');

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

//MAILING LISTS.  DEFAULT IS TO USE THE /LIST/ SUBDIRECTORY
if($params[0] == 'list'){
	//if($settings->get_setting('mailing_lists_active')){
		PathHelper::requireOnce('data/mailing_lists_class.php');

		$mailing_list = MailingList::get_by_link($params[1], true);		

		$template_file = $template_directory.'/views/list.php';
		$base_file = PathHelper::getIncludePath('views/list.php');
		
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
	//}	
}

//PRODUCTS.  DEFAULT IS TO USE THE /PRODUCT/ SUBDIRECTORY
if($params[0] == 'product'){
	if($settings->get_setting('products_active')){
	PathHelper::requireOnce('data/products_class.php');

		$product = Product::get_by_link($params[1], true);	
		$product_id = $product->key;
		
		$template_file = $template_directory.'/views/product.php';
		$base_file = PathHelper::getIncludePath('views/product.php');
		
		
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

//ADMIN AREA

//ADMIN STYLING FILES.  TEMPORARY UNTIL WE FIGURE OUT HOW TO HANDLE.  LOAD ANY URL UNDER /adm/includes/
if($params[0] == 'adm' && $params[1] == 'includes'){
	$base_file = PathHelper::getRootDir().$_SERVER['REQUEST_URI'];
	if(file_exists($base_file)){
		$seconds_to_cache = 43200;
		$ts = gmdate("D, d M Y H:i:s", time() + $seconds_to_cache) . " GMT";
		header("Expires: $ts");
		header("Pragma: cache");
		header("Cache-Control: max-age=$seconds_to_cache");
		$the_content_type = 'Content-type: '.mime_type($base_file);
		header($the_content_type);
		readfile($base_file);
		exit();
	}
	else{
		LibraryFunctions::display_404_page();
	}
}
		
if($params[0] == 'admin'){

	if($params[1]){	
		
		$base_file = ensure_extension(PathHelper::getIncludePath('adm/'.$params[1]),'php');
		if(file_exists($base_file)){
			$is_valid_page = true;
			require_once($base_file); 
			exit();		
		}
	}
	else{
		$base_file = ensure_extension(PathHelper::getIncludePath('adm/'.$params[1]),'php');
		if(file_exists($base_file)){
			$is_valid_page = true;
			require_once($base_file); 
			exit();		
		}
	}
}

//PLUGIN URLS
$plugins = LibraryFunctions::list_plugins();
foreach($plugins as $plugin){
	$plugin_dir = PathHelper::getIncludePath('plugins');
	$site_file = PathHelper::getIncludePath('plugins/'.$plugin.'/serve.php');
	
	if(file_exists($site_file)){
		// Check if plugin is active before including serve.php
		PathHelper::requireOnce('data/plugins_class.php');
		
		if(Plugin::is_plugin_active($plugin)){
			include_once($site_file);
		}
		// If plugin is not active, skip including its serve.php
	}
}	

//UTILS DIRECTORY
if($params[0] == 'utils'){
	if($params[1]){
		
		//LOAD THE UTILS FILES FROM THE PLUGINS
		$plugins = LibraryFunctions::list_plugins();
		foreach($plugins as $plugin){
			$plugin_file = ensure_extension(PathHelper::getIncludePath('plugins/'.$plugin.'/utils/'.$params[1]), 'php');
			if(file_exists($plugin_file)){
				// Check if plugin is active before loading utils file
				PathHelper::requireOnce('data/plugins_class.php');
				
				if(Plugin::is_plugin_active($plugin)){
					$is_valid_page = true;
					require_once($plugin_file);
					exit();
				}
				// If plugin is not active, skip this utils file
			}
		}	
		
		$base_file = ensure_extension(PathHelper::getIncludePath('utils/'.$params[1]), 'php');
		if(file_exists($base_file)){
			$is_valid_page = true;
			require_once($base_file); 
			exit();		
		}
	}
	else{
		LibraryFunctions::display_404_page();	
	}  
}

//TESTS DIRECTORY
if($params[0] == 'tests'){
	if($params[1]){
		// Build the path to the test file
		$test_path = 'tests/';
		for($i = 1; $i < count($params); $i++){
			if($params[$i] != ''){
				$test_path .= $params[$i] . '/';
			}
		}
		$test_path = rtrim($test_path, '/');
		
		$base_file = ensure_extension(PathHelper::getIncludePath($test_path), 'php');
		if(file_exists($base_file)){
			$is_valid_page = true;
			require_once($base_file); 
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

//ROOT PAGES
if($params[0]){
	$template_file = ensure_extension($template_directory.'/views/'.$params[0],'php');
	$base_file = ensure_extension(PathHelper::getIncludePath('views/'.$params[0]),'php');

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

	
LibraryFunctions::display_404_page();		

function ensure_extension($path, $extension){
	if(str_ends_with($path, '.'.$extension)){
		return $path;
	}
	else{
		return $path.'.php';
	}
}


function mime_type($filename) {

	$mime_types = array(

		'txt' => 'text/plain',
		'htm' => 'text/html',
		'html' => 'text/html',
		'php' => 'text/html',
		'css' => 'text/css',
		'js' => 'application/javascript',
		'json' => 'application/json',
		'xml' => 'application/xml',
		'swf' => 'application/x-shockwave-flash',
		'flv' => 'video/x-flv',

		// images
		'png' => 'image/png',
		'jpe' => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'jpg' => 'image/jpeg',
		'gif' => 'image/gif',
		'bmp' => 'image/bmp',
		'ico' => 'image/vnd.microsoft.icon',
		'tiff' => 'image/tiff',
		'tif' => 'image/tiff',
		'svg' => 'image/svg+xml',
		'svgz' => 'image/svg+xml',

		// archives
		'zip' => 'application/zip',
		'rar' => 'application/x-rar-compressed',
		'exe' => 'application/x-msdownload',
		'msi' => 'application/x-msdownload',
		'cab' => 'application/vnd.ms-cab-compressed',

		// audio/video
		'mp3' => 'audio/mpeg',
		'qt' => 'video/quicktime',
		'mov' => 'video/quicktime',

		// adobe
		'pdf' => 'application/pdf',
		'psd' => 'image/vnd.adobe.photoshop',
		'ai' => 'application/postscript',
		'eps' => 'application/postscript',
		'ps' => 'application/postscript',

		// ms office
		'doc' => 'application/msword',
		'rtf' => 'application/rtf',
		'xls' => 'application/vnd.ms-excel',
		'ppt' => 'application/vnd.ms-powerpoint',

		// open office
		'odt' => 'application/vnd.oasis.opendocument.text',
		'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
	);
	$parts = explode('.',$filename);
	$ext = strtolower(array_pop($parts));
	if (array_key_exists($ext, $mime_types)) {
		return $mime_types[$ext];
	}
	elseif (function_exists('finfo_open')) {
		$finfo = finfo_open(FILEINFO_MIME);
		$mimetype = finfo_file($finfo, $filename);
		finfo_close($finfo);
		return $mimetype;
	}
	else {
		throw new SystemDisplayableError('Unknown file type.');
		exit;
		//return 'application/octet-stream';
	}
}
?>