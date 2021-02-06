<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
$params = explode("/", $_REQUEST['path']);

$full_path = $_REQUEST['path'];
$static_routes_path = rtrim($_REQUEST['path'], '/');
$static_routes_path = ltrim($static_routes_path, '/');

$settings = Globalvars::get_instance();
$site_template = $settings->get_setting('site_template');
$template_directory = $_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template;

//ALLOW CURRENT SITE TO OVERRIDE OR ADD ROUTES
$template_file = $template_directory.'/serve.php';
if(file_exists($template_file)){
	require_once($template_file);
}

//ROBOTS.TXT
if($params[0] == 'robots.txt'){
	$template_file = $template_directory.'/robots.php';
	$base_file = $_SERVER['DOCUMENT_ROOT'] . '/robots.php';
	if(file_exists($template_file)){
		require_once($template_file);
		exit();
	}
	else{
		require_once($base_file); 
		exit();		
	}
}

//FILES
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
		$the_content_type = 'Content-type: '.mime_content_type($file);
		header($the_content_type);
		require_once($file);
		exit();
	}
	else{
		header("HTTP/1.0 404 Not Found");
		include_once($template_directory.'/404.php');
		exit();			
	}	
}

//HOMEPAGE
if(!$params[0]){
	$template_file = $template_directory.'/index.php';
	if(file_exists($template_file)){
		require_once($template_file);
		exit();
	}
}

//ADMIN SECTION
if($params[0] == 'admin'){
	if(!$params[1]){
		header("HTTP/1.0 404 Not Found");
		include_once($template_directory.'/404.php');
		exit();
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
	}
	else{
		$template_file = $template_directory.'/profile/profile.php';
	}
	if(file_exists($template_file)){
		require_once($template_file);
		exit();
	}	
}


//ROOT PAGES
if($params[0]){
	$template_file = $template_directory.'/'.$params[0].'.php';
	if(file_exists($template_file)){
		require_once($template_file);
		exit();
	}
}

//BLOG.  DEFAULT IS TO USE THE /POST/ SUBDIRECTORY
$blog_active = $settings->get_setting('blog_active');
if($blog_active){
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/posts_class.php');
	$blog_subdirectory = $settings->get_setting('blog_subdirectory');
	if($params[0] == $blog_subdirectory){
		$post = Post::get_by_link($params[1]);
		if($post){
			require_once($template_directory.'/'.'post.php');
			exit();
		}			
	}
	else{
		//CHECK BLOG URLS THAT ARE NOT UNDER /POST/
		$post = Post::get_by_link('/'.$_REQUEST['path']);
		if($post){
			require_once($template_directory.'/post.php');
			exit();
		}

		//CHECK WITHOUT THE SLASH
		$post = Post::get_by_link($_REQUEST['path']);
		if($post){
			require_once($template_directory.'/post.php');
			exit();
		}			
	}
}

//CHECK REDIRECTS
require_once($_SERVER['DOCUMENT_ROOT'].'/data/urls_class.php');
$urls = new MultiUrl(
	array('deleted'=>false, 'incoming'=>$static_routes_path),
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
		header("HTTP/1.0 404 Not Found");
		require_once($template_directory.'/404.php');			
		//THIS IS TURNED OFF
		//include($url->get('url_redirect_file'));
		exit();	
	}
}
	
header("HTTP/1.0 404 Not Found");
require_once($template_directory.'/404.php');
exit();

 

  
  

?>