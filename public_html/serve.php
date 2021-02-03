<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
$params = explode("/", $_REQUEST['path']);

$full_path = $_REQUEST['path'];
$static_routes_path = rtrim($_REQUEST['path'], '/');
$static_routes_path = ltrim($static_routes_path, '/');

$settings = Globalvars::get_instance();
$site_template = $settings->get_setting('site_template');

//ROBOTS.TXT
if($params[0] == 'robots.txt'){
	$template_file = $_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'robots.php';
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

//CHECK IF ACTUAL FILE EXISTS

if($params[0]){
	if($params[0] == 'profile'){
		if($params[1]){
			$template_file = $_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/profile/'.$params[1].'.php';
		}
		else{
			$template_file = $_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/profile/profile.php';
		}
	}
	else{
		$template_file = $_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/'.$params[0].'.php';
	}
}
else{
	$template_file = $_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/'.'index.php';
}

if(file_exists($template_file)){
	require_once($template_file);
	exit();
}



//CHECK BLOG URLS THAT ARE NOT UNDER /POST/
require_once($_SERVER['DOCUMENT_ROOT'].'/data/posts_class.php');
$post = Post::get_by_link('/'.$_REQUEST['path']);
if($post){
	include($_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/'.'post.php');
	exit();
}

//CHECK WITHOUT THE SLASH
$post = Post::get_by_link($_REQUEST['path']);
if($post){
	include($_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/'.'post.php');
	exit();
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
		include_once("404.php");			
		//THIS IS TURNED OFF
		//include($url->get('url_redirect_file'));
		exit();	
	}
}
else{	
	header("HTTP/1.0 404 Not Found");
	include_once("404.php");
	exit();
}
 

  
  

?>