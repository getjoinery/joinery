<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ShoppingCart.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');

class PublicPage {

	private $rowcount;

	private static $header_defaults = array(
		'title' => '',
		'showheader' => TRUE,
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
		$output = '	<div class="uk-section">
		<div class="uk-container">';
		
		if($title){
			$output .= '';
		}

		$output .= '';

		return $output;
		exit();
	}	

	public static function EndPage($options=array()) {
		$output = '</div></div>'; 
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

		//$this->cdn = $settings->get_setting($this->secure ? 'CDN_SSL' : 'CDN');
		//$this->protocol = $this->secure ? 'https://' : 'http://';
		//$this->secure_prefix = ($this->debug == 0) ? $settings->get_setting('webDir_SSL') : $settings->get_setting('webDir');

		$session = SessionControl::get_instance();
		//$this->location_data = $session->get_location_data();

		// This is for apache specific logging, so we have to check to make sure we are
		// serving off apache before we can set the userid.
		/*
		if (function_exists('apache_note') && $session->get_user_id(TRUE)) {
			apache_note('user_id', $session->get_user_id(TRUE));
		}
		*/

		if ($session->get_user_id()) {
			$this->user = new User($session->get_user_id(), TRUE);
		}

	}

	public function public_header($options=array()) {
		$session = SessionControl::get_instance();
		if($settings->get_setting('force_https')){
			header('Strict-Transport-Security: max-age=3153600');
			header("Content-Security-Policy: default-src https: youtube.com vimeo.com fonts.googleapis.com fonts.gstatic.com; style-src https: 'unsafe-inline'; script-src https: 'unsafe-inline'");
			//header("Content-Security-Policy-Report-Only: default-src https:");
		}
		header('X-Frame-Options: SAMEORIGIN');
		header('X-Content-Type-Options: nosniff');
		header('Referrer-Policy: unsafe-url');		
		?>
		
	<!DOCTYPE html>
<html lang="en-gb" dir="ltr">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>The Zouk Room - Courses and Community</title>
  <base href="/">
  <link rel="shortcut icon" type="image/png" href="img/favicon.png" >
  <link href="https://fonts.googleapis.com/css?family=Nunito+Sans:400,600,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/theme/zoukroom/css/main.css" />
  <script src="/theme/zoukroom/js/uikit.js"></script>
  <script src="<?php echo $this->cdn; ?>/theme/zoukroom/includes/jquery-3.4.1.min.js"></script>
 
		<?php
		if($settings->get_setting('custom_css')){
			echo '<style>'.$settings->get_setting('custom_css').'</style>';
		}
		?> 
 		<!--
<link rel="icon" href="/favicon.ico" />
<link rel="icon" href="/wp-content/themes/typology/assets/img/favicon-32x32.png" sizes="32x32" />
<link rel="icon" href="/wp-content/themes/typology/assets/img/android-chrome-192x192.png" sizes="192x192" />
<link rel="apple-touch-icon-precomposed" href="/wp-content/themes/typology/assets/img/apple-touch-icon.png" />
<meta name="msapplication-TileImage" content="/wp-content/themes/typology/assets/img/android-chrome-192x192.png" />
		-->
</head>

<body class="uk-background-body">

	<?php	
	if(empty($options['noheader'])){
		if($_SESSION['permission'] == 10){
			include("admin_debug.php");
		}
		?>	

<header id="header">
	<div data-uk-sticky="animation: uk-animation-slide-top; sel-target: .uk-navbar-container; cls-active: uk-navbar-sticky; cls-inactive: uk-navbar-transparent ; top: #header">
	  <nav class="uk-navbar-container uk-letter-spacing-small uk-text-bold">
	    <div class="uk-container">
	      <div class="uk-position-z-index" data-uk-navbar>
	        <div class="uk-navbar-left">
	          <a class="uk-navbar-item uk-logo" href="/">The Zouk Room</a>
	        </div>
	        <div class="uk-navbar-right">
	          <ul class="uk-navbar-nav uk-visible@m" data-uk-scrollspy-nav="closest: li; scroll: true; offset: 80">
	            <li class="uk-active"><a href="/events">Courses</a></li>
				<?php
						if ($session->get_user_id()){
							echo '<li><a href="/profile/profile">Profile</a></li> '; 				
							if($_SESSION['permission'] >= 5){
								echo '<li><a href="/admin/admin_users">Admin</a></li> ';
							}
							$cart = $session->get_shopping_cart();
							if($numitems = $cart->count_items()){
								echo '<li><a href="/cart">Cart'. $numitems . '</a></li> ';
							}
							echo '<li><a href="/logout">Log out</a></li>';
						}
						else{
							echo '<li><a href="/login">Log in</a></li>';
						}
						
						if($_SESSION['permission'] == 10){
							echo '<li><a id="admintoggle" href="#">Debug</a></li>';				
						}
				?>
	            <!--<li ><a href="/artists">Artists</a></li>-->
				<!--
	            <li >
	              <a href="#">Pages</a>
	              <div class="uk-navbar-dropdown">
	                <ul class="uk-nav uk-navbar-dropdown-nav">
	                  <li ><a href="course.html">Course</a></li>
	                  <li ><a href="event.html">Event</a></li>
	                  <li ><a href="search.html">Search</a></li>
	                  <li ><a href="sign-in.html">Sign In</a></li>
	                  <li ><a href="sign-up.html">Sign Up</a></li>
	                </ul>
	              </div>            
	            </li>
				-->
	          </ul>
			  <!--
	          <div>
	            <a class="uk-navbar-toggle" data-uk-search-icon href="#"></a>
	            <div class="uk-drop uk-background-default" data-uk-drop="mode: click; pos: left-center; offset: 0">
	              <form class="uk-search uk-search-navbar uk-width-1-1">
	                <input class="uk-search-input uk-text-demi-bold" type="search" placeholder="Search..." autofocus>
	              </form>
	            </div>
	          </div>
			  -->
			  <!--
	          <div class="uk-navbar-item">
	            <div><a class="uk-button uk-button-primary-light" href="sign-up.html">Sign Up</a></div>
	          </div>    
				-->			  
	          <a class="uk-navbar-toggle uk-hidden@m" href="#offcanvas" data-uk-toggle><span
	            data-uk-navbar-toggle-icon></span></a>
	        </div>
	      </div>
	    </div>
	  </nav>
	</div>

		
		
		<?php 
		}
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
		
<footer class="uk-section uk-section-secondary uk-section-large">
	<div class="uk-container uk-text-muted">
		<div class="uk-child-width-1-2@s uk-child-width-1-5@m" data-uk-grid>
			<div>
				<h5>Dance Links</h5>
				<ul class="uk-list uk-text-small">
					<li><a class="uk-link-muted" href="https://www.danceplace.com/">Danceplace</a></li>
					<li><a class="uk-link-muted" href="http://zoukology.com/">Zoukology</a></li>
				</ul>
			</div>
			<div>
				<h5>To list your course</h5>
				<ul class="uk-list uk-text-small">
					<li><p>Send an email to <a href="mailto:jeremy.tunnell@gmail.com">jeremy.tunnell@gmail.com</a> with the course dates, description, link to register, a large picture for the event, and a large picture of your instructor.</li>
				</ul>
			</div>
			<div>
				<h5>&nbsp;</h5>
				<ul class="uk-list uk-text-small">
					<li><a class="uk-link-muted" href="#">&nbsp;</a></li>
				</ul>
			</div>
			<div>
				<h5>&nbsp;</h5>
				<ul class="uk-list uk-text-small">
					<li><a class="uk-link-muted" href="#">&nbsp;</a></li>
				</ul>
			</div>
			<div>
				<div class="uk-margin">
					<a href="#" class="uk-logo">The Zouk Room</a>
				</div>
				<div class="uk-margin uk-text-small">				
					<p>Created by <a href="https://jeremytunnell.com" target="_blank">Jeremy Tunnell</a> in Asheville, NC.</p>
				</div>				
				<div class="uk-margin uk-text-small">				
					<p>Template by <a href="https://unbound.studio/" target="_blank">Unbound Studio</a> in Guatemala City.</p>
				</div>
				<div class="uk-margin uk-text-small">				
					<p>Pictures by <a href="https://www.grartslk.com/" target="_blank">Graziella</a> in Marseille, France.</p>
				</div>				
				
				<!--
				<div class="uk-margin">
					<div data-uk-grid class="uk-child-width-auto uk-grid-small">
						<div class="uk-first-column">
							<a href="https://www.facebook.com/" data-uk-icon="icon: facebook" class="uk-icon-link uk-icon"
								target="_blank"></a>
						</div>
						<div>
							<a href="https://www.instagram.com/" data-uk-icon="icon: instagram" class="uk-icon-link uk-icon"
								target="_blank"></a>
						</div>
						<div>
							<a href="mailto:info@blacompany.com" data-uk-icon="icon: mail" class="uk-icon-link uk-icon"
								target="_blank"></a>
						</div>
					</div>
				</div>
				-->
			</div>			
		</div>
	</div>
</footer>

<div id="offcanvas" data-uk-offcanvas="flip: true; overlay: true">
  <div class="uk-offcanvas-bar">
    <a class="uk-logo" href="/">The Zouk Room</a>
    <button class="uk-offcanvas-close" type="button" data-uk-close="ratio: 1.2"></button>
    <ul class="uk-nav uk-nav-primary uk-nav-offcanvas uk-margin-medium-top uk-text-center">
      <!--<li class="uk-active"><a href="index.html">Courses</a></li>-->
      <li ><a href="/events">Courses</a></li>
      <!--<li ><a href="event.html">Event</a></li>
      <li ><a href="search.html">Search</a></li>
      <li ><a href="sign-in.html">Sign In</a></li>
      <li ><a href="sign-up.html">Sign Up</a></li>-->
    </ul>
	<!--
    <div class="uk-margin-medium-top">
      <a class="uk-button uk-width-1-1 uk-button-primary-light" href="sign-up.html">Sign Up</a>
    </div>
    <div class="uk-margin-medium-top uk-text-center">
      <div data-uk-grid class="uk-child-width-auto uk-grid-small uk-flex-center">
        <div>
          <a href="https://twitter.com/" data-uk-icon="icon: twitter" class="uk-icon-link" target="_blank"></a>
        </div>
        <div>
          <a href="https://www.facebook.com/" data-uk-icon="icon: facebook" class="uk-icon-link" target="_blank"></a>
        </div>
        <div>
          <a href="https://www.instagram.com/" data-uk-icon="icon: instagram" class="uk-icon-link" target="_blank"></a>
        </div>
        <div>
          <a href="https://vimeo.com/" data-uk-icon="icon: vimeo" class="uk-icon-link" target="_blank"></a>
        </div>
      </div>
    </div>
	-->
  </div>
</div>

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
