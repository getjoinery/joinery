<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ShoppingCart.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');

class PublicPage {

	private $rowcount;

	private static $header_defaults = array(
		'title' => 'Integral Zen',
		'showheader' => TRUE,
		'keyword_display' => 'All Services',
		'address_display' => 'Enter City, State or Zip',
		'currentmain' => NULL,
		'currentsub' => NULL,
		'noindex' => FALSE,
		'nofollow' => FALSE,
		'ui_wrapper' => TRUE,
	);

	private static $footer_defaults = array(
		'track' => TRUE,
	);

	public static function OutputGenericPublicPage($title, $header, $body, $options=array()) {
		$page = new PublicPage();
		$page->public_header(
			array_merge(
				array(
					'title' => $title,
					'showheader' => TRUE
				),
				$options));
		echo PublicPage::BeginPage($header);

		?>
		<p><?php echo $body; ?></p>
		<?php
		echo PublicPage::EndPage();
		$page->public_footer();
		exit;
	}
	
	public static function BeginPage($title='', $options=array()) {
		$output = '	<div class="section-content">
		<article id="post-746" class="typology-post typology-single-post post-746 post type-post status-publish format-standard hentry category-uncategorized">';
		
		if($title){
			$output .= '<header class="entry-header">
                <h1 class="entry-title entry-title-cover-empty">'.$title.'</h1>   
				<div class="post-letter">'.$title[0].'</div>    
            </header>';
		}

		$output .= '<div class="entry-content clearfix">';

		return $output;
	}	

	public static function EndPage($options=array()) {
		$output = '			</div>
		</article>	
	</div>'; 
		return $output;
	}	

	public function __construct($secure=FALSE) {
		$this->rowcount = 0;
		$this->secure = $secure;
		$this->server = $_SERVER['PHP_SELF'];
		$this->remote_addr = $_SERVER['REMOTE_ADDR'];

		$settings = Globalvars::get_instance();

		$this->debug = $settings->get_setting('debug');
		if ($this->debug == 1) {
			$secure = FALSE;
			$this->secure = FALSE;
		}

		// If secure is on, they are not HTTPS and on port 80, forward them to SSL
		/*
		if ($secure && $_SERVER["SERVER_PORT"] == 80) {
			header("HTTP/1.1 301 Moved Permanently");
			header("Location: https://" . $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"]);
			exit;
		} else if (!$secure && $_SERVER["SERVER_PORT"] == 443) {
			// Likewise if they aren't secure and reading an SSLed page, redirect them to non-SSL
			header("HTTP/1.1 301 Moved Permanently");
			header("Location: http://" . $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"]);
			exit;
		}
		*/

		$this->cdn = $settings->get_setting($this->secure ? 'CDN_SSL' : 'CDN');
		$this->protocol = $this->secure ? 'https://' : 'http://';
		$this->secure_prefix = ($this->debug == 0) ? $settings->get_setting('webDir_SSL') : $settings->get_setting('webDir');

		$session = SessionControl::get_instance();
		$this->location_data = $session->get_location_data();

		// This is for apache specific logging, so we have to check to make sure we are
		// serving off apache before we can set the userid.
		if (function_exists('apache_note') && $session->get_user_id(TRUE)) {
			apache_note('user_id', $session->get_user_id(TRUE));
		}

		if ($session->get_user_id()) {
			$this->user = new User($session->get_user_id(), TRUE);
		}

		//$this->google_map_api_key = $settings->get_setting('GoogleMapAPIKey');
	}

	public function public_header($options=array()) {
		$session = SessionControl::get_instance();

		$settings = Globalvars::get_instance();
		if($settings->get_setting('force_https')){
			header('Strict-Transport-Security: max-age=3153600');
		}
		header('X-Frame-Options "SAMEORIGIN"');
		header('X-Content-Type-Options: nosniff');
		header('Referrer-Policy: ""');
		//header('Content-Security-Policy: default-src https://jeremytunnell.com fonts.googleapis.com fonts.gstatic.com');
		
		?>
<!DOCTYPE html>
<html lang="en-US" class="no-js no-svg">
	<head>
		<meta charset="UTF-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		
		<title>Jeremy Tunnell</title>
		<base href="/">
		<link rel="dns-prefetch" href="//fonts.googleapis.com">
 

<link rel="stylesheet" id="wp-block-library-css" href="/theme/jeremytunnell/includes/wp-includes/css/dist/block-library/style.min.css" type="text/css" media="all">
<link rel="stylesheet" id="mks_shortcodes_simple_line_icons-css" href="/theme/jeremytunnell/includes/wp-content/plugins/meks-flexible-shortcodes/css/simple-line/simple-line-icons.css" type="text/css" media="screen">
<link rel="stylesheet" id="mks_shortcodes_css-css" href="/theme/jeremytunnell/includes/wp-content/plugins/meks-flexible-shortcodes/css/style.css" type="text/css" media="screen">
<link rel="stylesheet" id="typology-fonts-css" href="https://fonts.googleapis.com/css?family=Domine%3A400%7CJosefin+Sans%3A400%2C600&subset=latin%2Clatin-ext&ver=1.6.3" type="text/css" media="all">
<link rel="stylesheet" id="typology-main-css" href="/theme/jeremytunnell/includes/wp-content/themes/typology/assets/css/min.css" type="text/css" media="all">
<style id="typology-main-inline-css" type="text/css">
body,blockquote:before, q:before{font-family: 'Domine';font-weight: 400;}body,.typology-action-button .sub-menu{color:#444444;}body{background:#f8f8f8;font-size: 1.6rem;}.typology-fake-bg{background:#f8f8f8;}.typology-sidebar,.typology-section{background:#ffffff;}h1, h2, h3, h4, h5, h6,.h1, .h2, .h3, .h4, .h5, .h6,.submit,.mks_read_more a,input[type="submit"],input[type="button"],a.mks_button,.cover-letter,.post-letter,.woocommerce nav.woocommerce-pagination ul li span,.woocommerce nav.woocommerce-pagination ul li a,.woocommerce div.product .woocommerce-tabs ul.tabs li,.typology-pagination a,.typology-pagination span,.comment-author .fn,.post-date-month,.typology-button-social,.meks-instagram-follow-link a,.mks_autor_link_wrap a,.entry-pre-title,.typology-button,button,.wp-block-cover .wp-block-cover-image-text, .wp-block-cover .wp-block-cover-text, .wp-block-cover h2, .wp-block-cover-image .wp-block-cover-image-text, .wp-block-cover-image .wp-block-cover-text, .wp-block-cover-image h2,.wp-block-button__link,body div.wpforms-container-full .wpforms-form input[type=submit], body div.wpforms-container-full .wpforms-form button[type=submit], body div.wpforms-container-full .wpforms-form .wpforms-page-button {font-family: 'Josefin Sans';font-weight: 600;}.typology-header .typology-nav{font-family: 'Josefin Sans';font-weight: 600;}.typology-cover .entry-title,.typology-cover h1 { font-size: 6.4rem;}h1, .h1 {font-size: 4.8rem;}h2, .h2 {font-size: 3.5rem;}h3, .h3 {font-size: 2.8rem;}h4, .h4 {font-size: 2.3rem;}h5, .h5,.typology-layout-c.post-image-on .entry-title,blockquote, q {font-size: 1.8rem;}h6, .h6 {font-size: 1.5rem;}.widget{font-size: 1.4rem;}.typology-header .typology-nav a{font-size: 1.1rem;}.typology-layout-b .post-date-hidden,.meta-item{font-size: 1.3rem;}.post-letter {font-size: 26.0rem;}.typology-layout-c .post-letter{height: 26.0rem;}.cover-letter {font-size: 60.0rem;}h1, h2, h3, h4, h5, h6,.h1, .h2, .h3, .h4, .h5, .h6,h1 a,h2 a,h3 a,h4 a,h5 a,h6 a,.post-date-month{color:#333333;}.typology-single-sticky a{color:#444444;}.entry-title a:hover,.typology-single-sticky a:hover{color:#c62641;}.bypostauthor .comment-author:before,#cancel-comment-reply-link:after{background:#c62641;}a,.widget .textwidget a,.typology-layout-b .post-date-hidden{color: #c62641;}.single .typology-section:first-child .section-content, .section-content-page, .section-content.section-content-a{max-width: 720px;}.typology-header{height:110px;}.typology-header-sticky-on .typology-header{background:#c62641;}.cover-letter{padding-top: 110px;}.site-title a,.typology-site-description{color: #ffffff;}.typology-header .typology-nav,.typology-header .typology-nav > li > a{color: #ffffff;}.typology-header .typology-nav .sub-menu a{ color:#444444;}.typology-header .typology-nav .sub-menu a:hover{color: #c62641;}.typology-action-button .sub-menu ul a:before{background: #c62641;}.sub-menu .current-menu-item a{color:#c62641;}.dot,.typology-header .typology-nav .sub-menu{background:#ffffff;}.typology-header .typology-main-navigation .sub-menu .current-menu-ancestor > a,.typology-header .typology-main-navigation .sub-menu .current-menu-item > a{color: #c62641;}.typology-header-wide .slot-l{left: 35px;}.typology-header-wide .slot-r{right: 20px;}.meta-item,.meta-item span,.meta-item a,.comment-metadata a{color: #888888;}.comment-meta .url,.meta-item a:hover{color:#333333;}.typology-post:after,.section-title:after,.typology-pagination:before{background:rgba(51,51,51,0.2);}.typology-layout-b .post-date-day,.typology-outline-nav li a:hover,.style-timeline .post-date-day{color:#c62641;}.typology-layout-b .post-date:after,blockquote:before,q:before{background:#c62641;}.typology-sticky-c,.typology-sticky-to-top span,.sticky-author-date{color: #888888;}.typology-outline-nav li a{color: #444444;}.typology-post.typology-layout-b:before, .section-content-b .typology-ad-between-posts:before{background:rgba(68,68,68,0.1);}.submit,.mks_read_more a,input[type="submit"],input[type="button"],a.mks_button,.typology-button,.submit,.typology-button-social,.page-template-template-authors .typology-author .typology-button-social,.widget .mks_autor_link_wrap a,.widget .meks-instagram-follow-link a,.widget .mks_read_more a,button,body div.wpforms-container-full .wpforms-form input[type=submit], body div.wpforms-container-full .wpforms-form button[type=submit], body div.wpforms-container-full .wpforms-form .wpforms-page-button {color:#ffffff;background: #c62641;border:1px solid #c62641;}body div.wpforms-container-full .wpforms-form input[type=submit]:hover, body div.wpforms-container-full .wpforms-form input[type=submit]:focus, body div.wpforms-container-full .wpforms-form input[type=submit]:active, body div.wpforms-container-full .wpforms-form button[type=submit]:hover, body div.wpforms-container-full .wpforms-form button[type=submit]:focus, body div.wpforms-container-full .wpforms-form button[type=submit]:active, body div.wpforms-container-full .wpforms-form .wpforms-page-button:hover, body div.wpforms-container-full .wpforms-form .wpforms-page-button:active, body div.wpforms-container-full .wpforms-form .wpforms-page-button:focus {color:#ffffff;background: #c62641;border:1px solid #c62641;}.page-template-template-authors .typology-author .typology-icon-social:hover {border:1px solid #c62641;}.button-invert{color:#c62641;background:transparent;}.widget .mks_autor_link_wrap a:hover,.widget .meks-instagram-follow-link a:hover,.widget .mks_read_more a:hover{color:#ffffff;}.typology-cover{min-height: 240px;}.typology-cover-empty{height:209px;min-height:209px;}.typology-fake-bg .typology-section:first-child {top: -99px;}.typology-flat .typology-cover-empty{height:110px;}.typology-flat .typology-cover{min-height:110px;}.typology-cover-empty,.typology-cover,.typology-header-sticky{background: #c62641;;}.typology-cover-overlay:after{background: rgba(198,38,65,0.6);}.typology-sidebar-header{background:#c62641;}.typology-cover,.typology-cover .entry-title,.typology-cover .entry-title a,.typology-cover .meta-item,.typology-cover .meta-item span,.typology-cover .meta-item a,.typology-cover h1,.typology-cover h2,.typology-cover h3{color: #ffffff;}.typology-cover .typology-button{color: #c62641;background:#ffffff;border:1px solid #ffffff;}.typology-cover .button-invert{color: #ffffff;background: transparent;}.typology-cover-slider .owl-dots .owl-dot span{background:#ffffff;}.typology-outline-nav li:before,.widget ul li:before{background:#c62641;}.widget a{color:#444444;}.widget a:hover,.widget_calendar table tbody td a,.entry-tags a:hover,.wp-block-tag-cloud a:hover{color:#c62641;}.widget_calendar table tbody td a:hover,.widget table td,.entry-tags a,.wp-block-tag-cloud a{color:#444444;}.widget table,.widget table td,.widget_calendar table thead th,table,td, th{border-color: rgba(68,68,68,0.3);}.widget ul li,.widget .recentcomments{color:#444444;}.widget .post-date{color:#888888;}#today{background:rgba(68,68,68,0.1);}.typology-pagination .current, .typology-pagination .infinite-scroll a, .typology-pagination .load-more a, .typology-pagination .nav-links .next, .typology-pagination .nav-links .prev, .typology-pagination .next a, .typology-pagination .prev a{color: #ffffff;background:#333333;}.typology-pagination a, .typology-pagination span{color: #333333;border:1px solid #333333;}.typology-footer{background:#f8f8f8;color:#aaaaaa;}.typology-footer h1,.typology-footer h2,.typology-footer h3,.typology-footer h4,.typology-footer h5,.typology-footer h6,.typology-footer .post-date-month{color:#aaaaaa;}.typology-count{background: #c62641;}.typology-footer a, .typology-footer .widget .textwidget a{color: #888888;}input[type="text"], input[type="email"],input[type=search], input[type="url"], input[type="tel"], input[type="number"], input[type="date"], input[type="password"], textarea, select{border-color:rgba(68,68,68,0.2);}blockquote:after, blockquote:before, q:after, q:before{-webkit-box-shadow: 0 0 0 10px #ffffff;box-shadow: 0 0 0 10px #ffffff;}pre,.entry-content #mc_embed_signup{background: rgba(68,68,68,0.1);}.wp-block-button__link{background: #c62641;color: #ffffff; }.wp-block-image figcaption,.wp-block-audio figcaption{color: #444444;}.wp-block-pullquote:not(.is-style-solid-color) blockquote{border-top:2px solid #444444;border-bottom:2px solid #444444;}.wp-block-pullquote.is-style-solid-color{background: #c62641;color: #ffffff; }.wp-block-separator{border-color: rgba(68,68,68,0.3);}body.wp-editor{background:#ffffff;}.has-small-font-size{ font-size: 1.3rem;}.has-large-font-size{ font-size: 1.9rem;}.has-huge-font-size{ font-size: 2.2rem;}@media(min-width: 801px){.has-small-font-size{ font-size: 1.3rem;}.has-normal-font-size{ font-size: 1.6rem;}.has-large-font-size{ font-size: 2.2rem;}.has-huge-font-size{ font-size: 2.9rem;}}.has-typology-acc-background-color{ background-color: #c62641;}.has-typology-acc-color{ color: #c62641;}.has-typology-txt-background-color{ background-color: #444444;}.has-typology-txt-color{ color: #444444;}.has-typology-meta-background-color{ background-color: #888888;}.has-typology-meta-color{ color: #888888;}.has-typology-bg-background-color{ background-color: #ffffff;}.has-typology-bg-color{ color: #ffffff;}.site-title{text-transform: uppercase;}.typology-site-description{text-transform: none;}.typology-nav{text-transform: uppercase;}h1, h2, h3, h4, h5, h6, .wp-block-cover-text, .wp-block-cover-image-text{text-transform: uppercase;}.section-title{text-transform: uppercase;}.widget-title{text-transform: uppercase;}.meta-item{text-transform: none;}.typology-button{text-transform: uppercase;}.submit,.mks_read_more a,input[type="submit"],input[type="button"],a.mks_button,.typology-button,.widget .mks_autor_link_wrap a,.widget .meks-instagram-follow-link a,.widget .mks_read_more a,button,.typology-button-social,.wp-block-button__link,body div.wpforms-container-full .wpforms-form input[type=submit], body div.wpforms-container-full .wpforms-form button[type=submit], body div.wpforms-container-full .wpforms-form .wpforms-page-button {text-transform: uppercase;}
</style>

		<script type="text/javascript" src="<?php echo $this->cdn; ?>/theme/jeremytunnell/includes/jquery-3.4.1.min.js"></script>
		
		<!-- jQuery validate -->
		<script type="text/javascript" src="/theme/jeremytunnell/includes/jquery.validate-1.9.1.js"></script>				
		
<script type="text/javascript" src="/theme/jeremytunnell/includes/wp-includes/js/jquery/jquery-migrate.min.js"></script>

<link rel="icon" href="/favicon.ico" />
<link rel="icon" href="/theme/jeremytunnell/includes/wp-content/themes/typology/assets/img/favicon-32x32.png" sizes="32x32" />
<link rel="icon" href="/theme/jeremytunnell/includes/wp-content/themes/typology/assets/img/android-chrome-192x192.png" sizes="192x192" />
<link rel="apple-touch-icon-precomposed" href="/theme/jeremytunnell/includes/wp-content/themes/typology/assets/img/apple-touch-icon.png" />
<meta name="msapplication-TileImage" content="/theme/jeremytunnell/includes/wp-content/themes/typology/assets/img/android-chrome-192x192.png" />




	</head>

	<body class="home page-template-default page page-id-35 wp-embed-responsive typology-v_1_6_3">

		
			<header id="typology-header" class="typology-header">
				<div class="container">
					<div class="slot-l">
	<div class="typology-site-branding">
	
	<h1 class="site-title h4"><a href="/" rel="home"><img class="typology-logo" src="/theme/jeremytunnell/includes/wp-content/themes/typology/assets/img/typology_logo.png" alt="JeremyTunnell.com"></a></h1>	
</div>
	
</div>

<div class="slot-r">
				<ul id="menu-main-menu" class="typology-nav typology-main-navigation">
<li id="menu-item-474" class="menu-item menu-item-type-post_type menu-item-object-page menu-item-474"><a href="/page/about">About</a></li>
<!--<li id="menu-item-475" class="menu-item menu-item-type-post_type menu-item-object-post menu-item-475"><a href="/contact">Contact</a></li>-->
<!--<li id="menu-item-59" class="menu-item menu-item-type-taxonomy menu-item-object-category menu-item-59"><a href="category/humans/index.html">Category</a></li>
<li id="menu-item-63" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-has-children menu-item-63"><a href="#">Features</a>
<ul class="sub-menu">
	<li id="menu-item-67" class="menu-item menu-item-type-post_type menu-item-object-page menu-item-67"><a href="styleguide/index.html">Styleguide</a></li>
	<li id="menu-item-433" class="menu-item menu-item-type-post_type menu-item-object-page menu-item-433"><a href="blocks-wp-5-0-gutenberg/index.html">WordPress 5.0 blocks</a></li>
	<li id="menu-item-196" class="menu-item menu-item-type-post_type menu-item-object-page menu-item-196"><a href="shortcodes/index.html">Shortcodes</a></li>
	<li id="menu-item-193" class="menu-item menu-item-type-post_type menu-item-object-page menu-item-193"><a href="contact/index.html">Contact</a></li>
</ul>
</li>-->
</ul>			
	<ul class="typology-nav typology-actions-list">
    <li class="typology-action-button typology-action-sidebar ">
		<span>
			<i class="fa fa-bars"></i>
		</span>
</li>
</ul></div>				</div>
			</header>

 <div id="typology-cover" class="typology-cover typology-cover-empty">
                    </div>		

<!--
<div id="typology-cover" class="typology-cover ">
			
<div class="typology-slider-wrapper-fixed">
	<div class="typology-cover-wrap">
				<div class="typology-slider-wrapper  typology-cover-slider owl-carousel">
						 		
				<div class="typology-cover-item  ">
					<div class="cover-item-container">
						<article class="post-47 post type-post status-publish format-standard hentry category-humans tag-noimages tag-typography tag-writing">
						    <header class="entry-header">
						        <h2 class="entry-title h1"><a href="does-anybody-else-feel-jealous-and-worried/index.html">A beautiful blog with no images required</a></h2>						         
	                				<div class="entry-meta"><div class="meta-item meta-author">By <span class="vcard author"><span class="fn"><a href="author/jtunnell99/index.html">jtunnell99</a></span></span></div><div class="meta-item meta-category">In <a href="category/humans/index.html" rel="category tag">Humans</a></div><div class="meta-item meta-comments"><a href="does-anybody-else-feel-jealous-and-worried/#comments">5 Comments</a></div></div>
	            										    </header>
						          
						        <div class="entry-footer">
						            <a href="does-anybody-else-feel-jealous-and-worried/index.html" class="typology-button">Read on</a><a href="javascript:void(0);" class="typology-button button-invert typology-rl pocket" data-url="https://getpocket.com/edit?url=https%3A%2F%2Fjeremytestsite-1b8274.ingress-bonde.easywp.com%2Fdoes-anybody-else-feel-jealous-and-worried%2F"><i class="fa fa-bookmark-o"></i>Read later</a>						        </div>
    						
						    	    						<div class="cover-letter">A</div>
	    											</article>
					</div>

					
				</div>

						 		
				<div class="typology-cover-item  ">
					<div class="cover-item-container">
						<article class="post-21 post type-post status-publish format-standard hentry category-humans tag-medium tag-noimages tag-writing">
						    <header class="entry-header">
						        <h2 class="entry-title h1"><a href="what-could-possibly-go-wrong/index.html">What could possibly go wrong?</a></h2>						         
	                				<div class="entry-meta"><div class="meta-item meta-author">By <span class="vcard author"><span class="fn"><a href="author/jtunnell99/index.html">jtunnell99</a></span></span></div><div class="meta-item meta-category">In <a href="category/humans/index.html" rel="category tag">Humans</a></div><div class="meta-item meta-comments"><a href="what-could-possibly-go-wrong/#comments">3 Comments</a></div></div>
	            										    </header>
						          
						        <div class="entry-footer">
						            <a href="what-could-possibly-go-wrong/index.html" class="typology-button">Read on</a><a href="javascript:void(0);" class="typology-button button-invert typology-rl pocket" data-url="https://getpocket.com/edit?url=https%3A%2F%2Fjeremytestsite-1b8274.ingress-bonde.easywp.com%2Fwhat-could-possibly-go-wrong%2F"><i class="fa fa-bookmark-o"></i>Read later</a>						        </div>
    						
						    	    						<div class="cover-letter">W</div>
	    											</article>
					</div>

					
				</div>

						 		
				<div class="typology-cover-item  ">
					<div class="cover-item-container">
						<article class="post-129 post type-post status-publish format-standard hentry category-business tag-noimages tag-text-based tag-writing">
						    <header class="entry-header">
						        <h2 class="entry-title h1"><a href="the-simplest-ways-to-choose-the-best-coffee/index.html">The simplest ways to choose the best coffee</a></h2>						         
	                				<div class="entry-meta"><div class="meta-item meta-author">By <span class="vcard author"><span class="fn"><a href="author/jtunnell99/index.html">jtunnell99</a></span></span></div><div class="meta-item meta-category">In <a href="category/business/index.html" rel="category tag">Business</a></div><div class="meta-item meta-comments"><a href="the-simplest-ways-to-choose-the-best-coffee/#comments">2 Comments</a></div></div>
	            										    </header>
						          
						        <div class="entry-footer">
						            <a href="the-simplest-ways-to-choose-the-best-coffee/index.html" class="typology-button">Read on</a><a href="javascript:void(0);" class="typology-button button-invert typology-rl pocket" data-url="https://getpocket.com/edit?url=https%3A%2F%2Fjeremytestsite-1b8274.ingress-bonde.easywp.com%2Fthe-simplest-ways-to-choose-the-best-coffee%2F"><i class="fa fa-bookmark-o"></i>Read later</a>						        </div>
    						
						    	    						<div class="cover-letter">T</div>
	    											</article>
					</div>

					
				</div>

						 		
				<div class="typology-cover-item  ">
					<div class="cover-item-container">
						<article class="post-134 post type-post status-publish format-standard hentry category-culture tag-noimages tag-stories tag-writing">
						    <header class="entry-header">
						        <h2 class="entry-title h1"><a href="what-does-your-pet-really-think-about-you/index.html">What does your pet really think about you?</a></h2>						         
	                				<div class="entry-meta"><div class="meta-item meta-author">By <span class="vcard author"><span class="fn"><a href="author/jtunnell99/index.html">jtunnell99</a></span></span></div><div class="meta-item meta-category">In <a href="category/culture/index.html" rel="category tag">Culture</a></div><div class="meta-item meta-comments"><a href="what-does-your-pet-really-think-about-you/#comments">2 Comments</a></div></div>
	            										    </header>
						          
						        <div class="entry-footer">
						            <a href="what-does-your-pet-really-think-about-you/index.html" class="typology-button">Read on</a><a href="javascript:void(0);" class="typology-button button-invert typology-rl pocket" data-url="https://getpocket.com/edit?url=https%3A%2F%2Fjeremytestsite-1b8274.ingress-bonde.easywp.com%2Fwhat-does-your-pet-really-think-about-you%2F"><i class="fa fa-bookmark-o"></i>Read later</a>						        </div>
    						
						    	    						<div class="cover-letter">W</div>
	    											</article>
					</div>

					
				</div>

						 		
				<div class="typology-cover-item  ">
					<div class="cover-item-container">
						<article class="post-117 post type-post status-publish format-standard hentry category-politics tag-noimages tag-stories tag-writing">
						    <header class="entry-header">
						        <h2 class="entry-title h1"><a href="once-brewed-coffee-may-be-served-in-a-variety-of-ways/index.html">Coffee may be served in a variety of ways</a></h2>						         
	                				<div class="entry-meta"><div class="meta-item meta-author">By <span class="vcard author"><span class="fn"><a href="author/jtunnell99/index.html">jtunnell99</a></span></span></div><div class="meta-item meta-category">In <a href="category/politics/index.html" rel="category tag">Politics</a></div><div class="meta-item meta-comments"><a href="once-brewed-coffee-may-be-served-in-a-variety-of-ways/#respond">Add Comment</a></div></div>
	            										    </header>
						          
						        <div class="entry-footer">
						            <a href="once-brewed-coffee-may-be-served-in-a-variety-of-ways/index.html" class="typology-button">Read on</a><a href="javascript:void(0);" class="typology-button button-invert typology-rl pocket" data-url="https://getpocket.com/edit?url=https%3A%2F%2Fjeremytestsite-1b8274.ingress-bonde.easywp.com%2Fonce-brewed-coffee-may-be-served-in-a-variety-of-ways%2F"><i class="fa fa-bookmark-o"></i>Read later</a>						        </div>
    						
						    	    						<div class="cover-letter">C</div>
	    											</article>
					</div>

					
				</div>

					</div>


		
	</div>

</div>


        	</div>
-->

<div class="typology-fake-bg">
	<div class="typology-section">

	<?php	
	}

	public function public_footer($options=array()) {
		$session = SessionControl::get_instance();
		$session->clear_clearable_messages();
				

		//TRACKING
		if(!$_SESSION['permission'] || $_SESSION['permission'] == 0){
			if(!$session->crawlerDetect($_SERVER["HTTP_USER_AGENT"])){
				if(!isset($options['is_404'])){
					$options['is_404'] = 0;
				}

				$session->save_visitor_event(1, $options['is_404'], $session->get_user_id());
			}
		}		
?>
		
			
				
			</div>


                            <footer id="typology-footer" class="typology-footer">
                    
                                        
                                            
                        <div class="container">
                                    
                                                                    
                                                                    <div class="col-lg-4 typology-footer-sidebar"><div id="text-1" class="widget clearfix widget_text">			
																	<div class="textwidget"><div style="text-align: center">
<!--<img style="width: 111px; margin-bottom: 10px" src="https://demo.mekshq.com/typology/dc/typology_logo_invert.png" alt="Typology">-->
<p>Copyright Jeremy Tunnell <br> All rights reserved</p>
</div></div>
		</div></div>
                                                                    
                                                                    
                        </div>

                                    </footer>
            
            
		</div>

		<div class="typology-sidebar">
	<div class="typology-sidebar-header">
		<div class="typology-sidebar-header-wrapper">
			<div class="typology-site-branding">
	
	<span class="site-title h4"><a href="/" rel="home"><img class="typology-logo" src="/theme/jeremytunnell/includes/wp-content/themes/typology/assets/img/typology_logo.png" alt="JeremyTunnell.com"></a></span>	
</div>
			<span class="typology-sidebar-close"><i class="fa fa-times" aria-hidden="true"></i></span>
		</div>
	</div>

	<div class="widget typology-responsive-menu">
					<ul id="menu-main-menu-1" class="typology-nav typology-main-navigation">
					<li class="menu-item menu-item-type-post_type menu-item-object-page menu-item-home current-menu-item page_item page-item-35 current_page_item menu-item-476"><a href="/page/about">About</a></li>
					<!--<li class="menu-item menu-item-type-post_type menu-item-object-page menu-item-home current-menu-item page_item page-item-35 current_page_item menu-item-476"><a href="" aria-current="page">Home</a></li>
<li class="menu-item menu-item-type-post_type menu-item-object-page menu-item-474"><a href="example-page/index.html">Page</a></li>
<li class="menu-item menu-item-type-post_type menu-item-object-post menu-item-475"><a href="what-does-your-pet-really-think-about-you/index.html">Post</a></li>
<li class="menu-item menu-item-type-taxonomy menu-item-object-category menu-item-59"><a href="category/humans/index.html">Category</a></li>
<li class="menu-item menu-item-type-custom menu-item-object-custom menu-item-has-children menu-item-63"><a href="#">Features</a>
<ul class="sub-menu">
	<li class="menu-item menu-item-type-post_type menu-item-object-page menu-item-67"><a href="styleguide/index.html">Styleguide</a></li>
	<li class="menu-item menu-item-type-post_type menu-item-object-page menu-item-433"><a href="blocks-wp-5-0-gutenberg/index.html">WordPress 5.0 blocks</a></li>
	<li class="menu-item menu-item-type-post_type menu-item-object-page menu-item-196"><a href="shortcodes/index.html">Shortcodes</a></li>
	<li class="menu-item menu-item-type-post_type menu-item-object-page menu-item-193"><a href="contact/index.html">Contact</a></li>-->
</ul>
</li>
</ul>		</div>

					
								<div id="mks_author_widget-1" class="widget clearfix mks_author_widget">
	<img alt="" src="/theme/jeremytunnell/images/jeremy.jpg" class="avatar avatar-90 photo avatar-default" height="90" width="90">
	

			

</div><div id="mks_social_widget-1" class="widget clearfix mks_social_widget"><h4 class="widget-title h5">Get in touch</h4>
					<p>You can reach Jeremy at jeremy.tunnell@gmail.com</p>
						<!--
						<ul class="mks_social_widget_ul">
			  		  		<li><a href="" title="Facebook" class="socicon-facebook soc_circle" target="_blank" style="width: 46px; height: 46px; font-size: 16px;line-height:51px;"><span>facebook</span></a></li>
		  			  		<li><a href="" title="Instagram" class="socicon-instagram soc_circle" target="_blank" style="width: 46px; height: 46px; font-size: 16px;line-height:51px;"><span>instagram</span></a></li>
		  			  		<li><a href="" title="Twitter" class="socicon-twitter soc_circle" target="_blank" style="width: 46px; height: 46px; font-size: 16px;line-height:51px;"><span>twitter</span></a></li>
		  			  		<li><a href="" title="dribbble" class="socicon-dribbble soc_circle" target="_blank" style="width: 46px; height: 46px; font-size: 16px;line-height:51px;"><span>dribbble</span></a></li>
		  			  </ul>-->
		

		</div>				
</div>

<div class="typology-sidebar-overlay"></div>		
<script type="text/javascript" src="/theme/jeremytunnell/includes/wp-includes/js/imagesloaded.min.js"></script>
<script type="text/javascript">
/* <![CDATA[ */
var typology_js_settings = {"rtl_mode":"","header_sticky":"1","logo":"https:\/\/jeremytunnell.com\/theme\/jeremytunnell\/includes\/wp-content\/themes\/typology\/assets\/img\/typology_logo.png","logo_retina":"https:\/\/jeremytunnell.com\/theme\/jeremytunnell\/includes\/wp-content\/themes\/typology\/assets\/img\/typology_logo.png","use_gallery":"1","slider_autoplay":"0","cover_video_image_fallback":""};
/* ]]> */
</script>
<script type="text/javascript" src="/theme/jeremytunnell/includes/wp-content/themes/typology/assets/js/min.js"></script>

	</body>
</html>

<?php	
	}


	private function configure_page_options($options) {
		return $this->configure_header_options($this->configure_footer_options($options));
	}

	private function configure_header_options($options) {
		$options = array_merge(self::$header_defaults, $options);

		if ($this->location_data) {
			$options['address_display'] = trim($this->location_data['disp_addr']);
		}

		if (!isset($options['profilenav']) && $options['currentmain']) {
			$options['profilenav'] = TRUE;
		}

		// In debug mode (on test instances), make all pages noindex and nofollow
		if ($this->debug) {
			$options['noindex'] = TRUE;
			$options['nofollow'] = TRUE;
		}

		return $options;
	}

	private function configure_footer_options($options) {
		$options = array_merge(self::$footer_defaults, $options);

		if (!isset($_SESSION['ie_popup']) && isset($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE 6.0') !== FALSE) {
			// If the user might be using MSIE 6, lets make the light box to show it
			// However, this is triggered by the specific javascript above, so it will
			// not hit any false positives
			$_SESSION['ie_popup'] = TRUE;
			$options['ie6'] = TRUE;
		}

		if ($this->debug) {
			$options['track'] = FALSE;
		}

		return $options;
	}

	static function pagination_list($tmpnumtotal, $numperpage, $currentpage, $qstring=NULL){

		parse_str($qstring, $current_query);
		unset($current_query['location']);
		unset($current_query['addr_id']);

		$links = array();
		$numpagestotal = ceil($tmpnumtotal/$numperpage);
		$tmpoffset	= $currentpage * $numperpage;

		if($tmpnumtotal > $numperpage){
			$x = $currentpage - 2;

			if ($currentpage > 1) {
				$current_query['pagenum'] = $currentpage - 1;
				$links['Previous']['link'] = '?' . http_build_query($current_query);
				$links['Previous']['current'] = FALSE;
			}

			if($currentpage > 10){
				$current_query['pagenum'] = $currentpage - 10;
				$links[$current_query['pagenum']]['link'] = '?' . http_build_query($current_query);
				$links[$current_query['pagenum']]['current'] = FALSE;
				$links['elipse1']['link'] = NULL;
				$links['elipse1']['current'] = FALSE;
			}
			else if($currentpage <= 10 && $x > 1) {
				$current_query['pagenum'] = 1;
				$links[$current_query['pagenum']]['link'] = '?' . http_build_query($current_query);
				$links[$current_query['pagenum']]['current'] = FALSE;
				$links['elipse1']['link'] = NULL;
				$links['elipse1']['current'] = FALSE;
			}

			$numprinted=0;
			while($numprinted < 5 && $x <= $numpagestotal){
				if($x > 0 && $x <= $numpagestotal){
					$current_query['pagenum'] = $x;
					$links[$x]['link'] = '?' . http_build_query($current_query);

					if($x == $currentpage) {
						$links[$x]['current'] = TRUE;
					}
					else {
						$links[$x]['current'] = FALSE;
					}
					$numprinted++;
				}
				$x++;
			}

			if($currentpage+10 < $numpagestotal){
				$links['elipse2']['link']  = NULL;
				$links['elipse2']['current'] = FALSE;
				$current_query['pagenum'] = $currentpage + 10;
				$links[$current_query['pagenum']]['link'] = '?' . http_build_query($current_query);
				$links[$current_query['pagenum']]['current'] = FALSE;
			}

			if ($currentpage < $numpagestotal) {
				$current_query['pagenum'] = $currentpage + 1;
				$links['Next']['link'] = '?' . http_build_query($current_query);
				$links['Next']['current'] = FALSE;
			}
		}

		return $links;
	}

	static function write_pagination($page_links) {
		$out = '';
                foreach ($page_links as $pagelabel=>$pageinfo) {
                  	if($pagelabel && $pageinfo['link']) {
                  		if($page_links[$pagelabel]['current']) {
                  			$out .= '<span class="currentPage">'.$pagelabel.'</span>';
                  		}
                  		else {
                  			$out .= '<a href="'.$pageinfo['link'].'">'.$pagelabel.'</a>';
                  		}
                  	}
                  	else if($pagelabel == 'elipse1' || $pagelabel == 'elipse2') {
                  		$out .= '<span class="ellipsis">...</span>';
                  	}
                 }
         return $out;
	}

	function set_pagination($tmpnumtotal, $numperpage, $tmpoffset=0, $sort=NULL, $sdirection=NULL, $getvars=NULL){
		// HANDLES THE PAGINATION OF TABLES DISPLAYED
		// $numtotal - Total number of records returned
		// $offset - Current offset of the current page.

		if ($sort != NULL && $sdirection != NULL) {
			$sortphrase = "&sort=$sort&sdirection=$sdirection";
		} else {
			$sortphrase = '';
		}

		if ($getvars != NULL) {
			$sortphrase .= '&' . $getvars;
		}

		$numpagestotal = ceil($tmpnumtotal/$numperpage);
		$currentpage = $tmpoffset / $numperpage;

		$self = $_SERVER['PHP_SELF'];;

		echo '<center>';
		echo "Pages ($numpagestotal Pages, $tmpnumtotal Records)<br />";

		if ($tmpnumtotal > $numperpage) {
			$x = $currentpage - 5;
			if ($currentpage >= 10) {
				$newtmpoffset = $tmpoffset - (10 * $numperpage);
				echo "<a href='$self?offset=$newtmpoffset$sortphrase'><< Back 10</a>&nbsp;&nbsp;&nbsp;";
			} else {
				echo "<< Back 10&nbsp;&nbsp;&nbsp;";
			}

			$numprinted=0;
			while ($numprinted < 10 && $x < $numpagestotal) {
				if ($x >= 0 && $x < $numpagestotal) {
					$newtmpoffset = $x * $numperpage;
					$disppnum = $x + 1;
					if($x == $currentpage){
						echo "<a href='$self?offset=$newtmpoffset$sortphrase'><b>$disppnum</b></a> ";
					}
					else{
						echo "<a href='$self?offset=$newtmpoffset$sortphrase'>$disppnum</a> ";
					}
					$numprinted++;
				}
				$x++;
			}

			if ($currentpage + 10 < $numpagestotal) {
				$newtmpoffset = $tmpoffset + (10 * $numperpage);
				echo "&nbsp;&nbsp;&nbsp;<a href='$self?offset=$newtmpoffset$sortphrase'>Ahead 10 >></a>";
			} else {
				echo "&nbsp;&nbsp;&nbsp;Ahead 10 >>";
			}
		}
		echo '</center>';

	}

	function tableheader($headers, $version="default"){
		//version VARIABLE TOGGLES BETWEEN STYLESHEETS
		echo "<table class='sortable admin_table' id='$version' cellspacing='0' summary=''>
			<caption></caption>
			<tr>";

		foreach ($headers as $value) {
			printf('<th scope="col" abbr="%s" class="bg">%s</th>', $value, $value);
		}
		echo '</tr>';
	}

	function disprow($dataarray){

		echo '<tr>';

		foreach ($dataarray as $value) {
			if ($value == "") {
				$value = "&nbsp";
			}

			if ($this->rowcount % 2 == 0) {
				printf('<td class="light">%s</td>', $value);
			} else {
				printf('<td class="dark">%s</td>', $value);
			}
		}
		echo "</tr>\n";
		$this->rowcount++;
	}

	function endtable(){
		$this->rowcount = 0;
		echo '</table>';
	}
}

?>
