<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ShoppingCart.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');

class PublicPage {

	private $rowcount;

	private static $header_defaults = array(
		//'title' => '',
		'showheader' => TRUE,
		'currentmain' => NULL,
		'currentsub' => NULL,
		'noindex' => FALSE,
		'nofollow' => FALSE,
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
	
		echo '			<div class="section">
		<div class="container">';
		echo '<p>'.$body.'</p>';
		echo '		</div><!-- end container -->
	</div>';
		
		echo PublicPage::EndPage();
		$page->public_footer();
		exit;
	}
	
	public static function BeginPage($title='', $options=array()) {
		$output = '';
		if($title){
			$output .= '
		<div class="section padding-bottom-0">
			<div class="container">
				<div class="margin-bottom-70">
					<div class="row text-center">
						<div class="col-12 col-md-10 offset-md-1 col-lg-8 offset-lg-2">
							<h2>'.$title.'</h2>';
							if($options['subtitle']){
								$output .= '<p>'.$options['subtitle'].'</p>';
							}
							$output .= '
						</div>
					</div>
				</div> 
			</div>
		</div>
		';
		}
		return $output;
	}	

	public static function EndPage($options=array()) {
		$output = ''; 
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
		
	}

	public function public_header($options=array()) {
		$_GLOBALS['page_header_loaded'] = true;

		$settings = Globalvars::get_instance();
		if($settings->get_setting('force_https')){
			header('Strict-Transport-Security: max-age=3153600');
			header("Content-Security-Policy: default-src https: youtube.com vimeo.com fonts.googleapis.com fonts.gstatic.com; style-src https: 'unsafe-inline'; script-src https: 'unsafe-inline'");
			//header("Content-Security-Policy-Report-Only: default-src https:");
		}
		header('X-Frame-Options: SAMEORIGIN');
		header('X-Content-Type-Options: nosniff');
		header('Referrer-Policy: unsafe-url');

		$this->debug = $settings->get_setting('debug');
		if ($this->debug == 1) {
			$secure = FALSE;
			$this->secure = FALSE;
		}
		
		$session = SessionControl::get_instance();
		$settings = Globalvars::get_instance();

		if(!isset($options['title']) || !$options['title']){
			$options['title'] = $settings->get_setting('site_name');
		}
		
		if(!isset($options['description']) || !$options['description']){
			$options['description'] = $settings->get_setting('site_description');
		}
		if(empty($options['noheader'])){
			//TRACKING
			if(!$_SESSION['permission'] || $_SESSION['permission'] == 0){
				if(!isset($options['is_404'])){
					$options['is_404'] = 0;
				}

				$session->save_visitor_event(1, $options['is_404']);
			}
		}
	
		?>
		
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:fb="http://ogp.me/ns/fb#">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
		<meta name="description" content="<?php echo $settings->get_setting('site_description') ?>">
        <meta name="keywords" content="">

		<title><?php echo $settings->get_setting('site_name') ?></title>
		<!-- Favicon -->
		<!--
        <link href="../assets/images/favicon.png" rel="shortcut icon">
		<link rel="icon" href="/theme/integralzen/images/cropped-IZ-Icon-07-32x32.png" sizes="32x32" />
		<link rel="icon" href="/theme/integralzen/images/cropped-IZ-Icon-07-192x192.png" sizes="192x192" />
		<link rel="apple-touch-icon-precomposed" href="/theme/integralzen/images/cropped-IZ-Icon-07-180x180.png" />
		<meta name="msapplication-TileImage" content="/theme/integralzen/images/cropped-IZ-Icon-07-270x270.png" />	
		-->
		<!-- CSS -->
		<link type="text/css" href="<?php echo $this->theme_url; ?>/includes/jquery-ui-1.7.custom_5.css" rel="stylesheet" />
		<!--<link rel="stylesheet" type="text/css" href="/theme/default/includes/uikit-3.4.2/css/uikit.min.css">-->
		<link href="<?php echo $this->theme_url; ?>/includes/assets/plugins/bootstrap/bootstrap.min.css" rel="stylesheet">
		
		<link href="<?php echo $this->theme_url; ?>/includes/assets/plugins/owl-carousel/owl.carousel.min.css" rel="stylesheet">
		<link href="<?php echo $this->theme_url; ?>/includes/assets/plugins/owl-carousel/owl.theme.default.min.css" rel="stylesheet">
		<link href="<?php echo $this->theme_url; ?>/includes/assets/plugins/magnific-popup/magnific-popup.min.css" rel="stylesheet">
		<!--<link href="<?php echo $this->theme_url; ?>/includes/assets/plugins/sal/sal.min.css" rel="stylesheet">-->
	
		<link href="<?php echo $this->theme_url; ?>/includes/assets/css/theme.css" rel="stylesheet">
		<link href="<?php echo $this->theme_url; ?>/includes/assets/css/site_styles.css" rel="stylesheet">
		<!-- Fonts/Icons -->
		<link href="<?php echo $this->theme_url; ?>/includes/assets/plugins/font-awesome/css/all.min.css" rel="stylesheet">
		<link href="<?php echo $this->theme_url; ?>/includes/assets/plugins/themify/themify-icons.min.css" rel="stylesheet">
		<link href="<?php echo $this->theme_url; ?>/includes/assets/plugins/simple-line-icons/css/simple-line-icons.css" rel="stylesheet">
		
		<?php
		if($settings->get_setting('custom_css')){
			echo '<style>'.$settings->get_setting('custom_css').'</style>';
		}
		?>
		
		<script src="<?php echo $this->theme_url; ?>/includes/jquery-3.4.1.min.js"></script>
		<!--<script src="https://code.jquery.com/jquery-migrate-3.1.0.min.js"></script>-->
		
		<!-- jQuery validate -->
		<script type="text/javascript" src="<?php echo $this->theme_url; ?>/includes/jquery.validate-1.9.1.js"></script>				
		

		
		<!--GDPR NOTICE  https://www.jqueryscript.net/other/GDPR-Cookie-Consent-Popup-Plugin.html-->
		<!--<script src="<?php echo $this->theme_url; ?>/scripts/GDPR/jquery.ihavecookies.js"></script>-->
	</head>
	<?php	
	if(empty($options['noheader'])){
		if($_SESSION['permission'] == 10){
			require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/admin_debug.php');
		}
		?>	
	<body data-preloader="1">
		
		<!-- Header -->
		<div class="header center sticky-autohide">
			<div class="container">
				<!-- Logo -->
				<div class="header-logo">
					<h3><a href="#">Test Site</a></h3>
					<!-- 
					<img class="logo-dark" src="../assets/images/your-logo-dark.png" alt="">
					<img class="logo-light" src="../assets/images/your-logo-light.png" alt=""> 
					-->
				</div>
				<!-- Menu -->
				<div class="header-menu">
					<ul class="nav">
						<li class="nav-item">
							<a class="nav-link" href="#">Link Only</a>
						</li>
						<li class="nav-item">
							<a class="nav-link" href="#">Dropdown</a>
							<ul class="nav-dropdown">
								<li class="nav-dropdown-item"><a class="nav-dropdown-link" href="#">Dropdown Item</a></li>
								<li class="nav-dropdown-item"><a class="nav-dropdown-link" href="#">Dropdown Item</a></li>
								<li class="nav-dropdown-item"><a class="nav-dropdown-link" href="#">Dropdown Item</a></li>
								<li class="nav-dropdown-item"><a class="nav-dropdown-link" href="#">Dropdown Item</a></li>
							</ul>
						</li>
						<li class="nav-item">
							<a class="nav-link" href="#">Subdropdown</a>
							<ul class="nav-dropdown">
								<li class="nav-dropdown-item">
									<a class="nav-dropdown-link" href="#">Dropdown Item</a>
									<ul class="nav-subdropdown">
										<li class="nav-subdropdown-item"><a class="nav-subdropdown-link" href="#">Subdropdown Item</a></li>
										<li class="nav-subdropdown-item"><a class="nav-subdropdown-link" href="#">Subdropdown Item</a></li>
										<li class="nav-subdropdown-item"><a class="nav-subdropdown-link" href="#">Subdropdown Item</a></li>
										<li class="nav-subdropdown-item"><a class="nav-subdropdown-link" href="#">Subdropdown Item</a></li>
									</ul>
								</li>
								<li class="nav-dropdown-item">
									<a class="nav-dropdown-link" href="#">Dropdown Item</a>
									<ul class="nav-subdropdown">
										<li class="nav-subdropdown-item"><a class="nav-subdropdown-link" href="#">Subdropdown Item</a></li>
										<li class="nav-subdropdown-item"><a class="nav-subdropdown-link" href="#">Subdropdown Item</a></li>
										<li class="nav-subdropdown-item"><a class="nav-subdropdown-link" href="#">Subdropdown Item</a></li>
										<li class="nav-subdropdown-item"><a class="nav-subdropdown-link" href="#">Subdropdown Item</a></li>
									</ul>
								</li>
							</ul>
						</li>
					</ul>
				</div>
				<!-- Menu Extra -->
				<div class="header-menu-extra">
					<ul class="list-inline">
						<?php 
						if ($session->get_user_id()){
							echo '<a href="/profile/profile">My Profile</a> '; 
							if($_SESSION['permission'] >= 5){
								echo '| <a href="/admin/admin_users">Admin</a> ';
							}

							$cart = $session->get_shopping_cart();
							if($numitems = $cart->count_items()){
								echo '| <a href="/cart">Cart ('. $numitems . ')</a> ';
							}
							else{
								//echo '<span class="cartcontents">Cart</span> ';
							}

							echo '| <a href="/logout">Log out</a>';

						}
						else{
							echo '<a href="/login">Log in</a> | <a href="/register">Register</a>';
						}
						
						if($_SESSION['permission'] == 10){
							echo ' | <a id="admintoggle" href="#">Debug</a>';				
						}
						echo '<br />Timezone: '.$session->get_timezone().' (<a href="/profile/account_edit">change</a>)';
						?>
						<!--
						<li><a href="#"><i class="fab fa-facebook-f"></i></a></li>
						<li><a href="#"><i class="fab fa-twitter"></i></a></li>
						<li><a href="#"><i class="fab fa-linkedin-in"></i></a></li>
						-->
					</ul>
				</div>
				<!-- Menu Toggle -->
				<button class="header-toggle">
					<span></span>
				</button>
			</div><!-- end container -->
		</div>
		<!-- end Header -->		
		
	<?php } //end if noheader ?>

		
	
		<?php
	}

	public function public_footer($options=array()) {
		$session = SessionControl::get_instance();
		$session->clear_clearable_messages();
	
		?>
		
		
	
		
		<footer>
			<div class="section-sm bg-dark">
				<div class="container">
					<div class="row col-spacing-20">
						<div class="col-6 col-sm-6 col-lg-3">
							<h3>mono</h3>
						</div>
						<div class="col-6 col-sm-6 col-lg-3">
							<h6 class="font-small font-weight-normal uppercase">Useful Links</h6>
							<ul class="list-dash">
								<li><a href="#">About us</a></li>
								<li><a href="#">Team</a></li>
								<li><a href="#">Prices</a></li>
								<li><a href="#">Contact</a></li>
							</ul>
						</div>
						<div class="col-6 col-sm-6 col-lg-3">
							<h6 class="font-small font-weight-normal uppercase">Additional Links</h6>
							<ul class="list-dash">
								<li><a href="#">Services</a></li>
								<li><a href="#">Process</a></li>
								<li><a href="#">FAQ</a></li>
								<li><a href="#">Careers</a></li>
							</ul>
						</div>
						<div class="col-6 col-sm-6 col-lg-3">
							<h6 class="font-small font-weight-normal uppercase">Contact Info</h6>
							<ul class="list-unstyled">
								<li>121 King St, Melbourne VIC 3000</li>
								<li>contact@example.com</li>
								<li>+(123) 456 789 01</li>
							</ul>
						</div>
					</div><!-- end row(1) -->

					<hr class="margin-top-30 margin-bottom-30">

					<div class="row col-spacing-10">
						<div class="col-12 col-md-6 text-center text-md-left">
							<p>&copy; 2021 FlaTheme, All Rights Reserved.</p>
						</div>
						<div class="col-12 col-md-6 text-center text-md-right">
							<ul class="list-inline">
								<li><a href="#"><i class="fab fa-facebook-f"></i></a></li>
								<li><a href="#"><i class="fab fa-twitter"></i></a></li>
								<li><a href="#"><i class="fab fa-pinterest"></i></a></li>
								<li><a href="#"><i class="fab fa-instagram"></i></a></li>
							</ul>
						</div>
					</div><!-- end row(2) -->
				</div><!-- end container -->
			</div>
		</footer>

		<!-- Scroll to top button -->
		<div class="scrolltotop">
			<a class="button-circle button-circle-sm button-circle-dark" href="#"><i class="ti-arrow-up"></i></a>
		</div>
		<!-- end Scroll to top button -->

		<!-- ***** JAVASCRIPTS ***** -->
		<script src="<?php echo $this->theme_url; ?>/includes/assets/js/polyfill.min.js?features=IntersectionObserver"></script>
		<script src="<?php echo $this->theme_url; ?>/includes/assets/plugins/plugins.js"></script>
		<script src="<?php echo $this->theme_url; ?>/includes/assets/js/functions.js"></script>
	</body>
</html>
		<?php
	}




	function tableheader($headers, $class='table cart-table', $id='table1'){
		echo '<table class="'.$class.'" id="'.$id.'" cellspacing="0">
			<thead><tr>';

		foreach ($headers as $value) {
			printf('<th scope="col" abbr="%s">%s</th>', $value, $value);
		}
		echo '</tr></thead><tbody>';
	}

	function disprow($dataarray){

		echo '<tr>';

		foreach ($dataarray as $value) {
			if ($value == "") {
				$value = "&nbsp";
			}


			printf('<td>%s</td>', $value);

		}
		echo "</tr>\n";
		$this->rowcount++;
	}

	function endtable(){
		echo '</tbody></table>';
	}
}

?>
