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

		?>
		<p><?php echo $body; ?></p>
		<?php
		echo PublicPage::EndPage();
		$page->public_footer();
		exit;
	}
	
	public static function BeginPage($title='', $options=array()) {
		$output = '<div class="wrap"><article id="post-7249" class="post-7249 page type-page status-publish has-post-thumbnail hentry category-dana pmpro-has-access">';
		if($title){
			$output .= '<h1 class="entry-title">'.$title.'</h1>'; 
		}
		return $output;
	}	

	public static function EndPage($options=array()) {
		$output = '</article></div>'; 
		return $output;
	}	

	public function __construct($secure=FALSE) {
		$this->rowcount = 0;
		$this->secure = $secure;
		$this->server = $_SERVER['PHP_SELF'];
		$this->remote_addr = $_SERVER['REMOTE_ADDR'];

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

		$settings = Globalvars::get_instance();
		if($settings->get_setting('force_https')){
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
		}

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
		}
		
		$session = SessionControl::get_instance();

		$site_title = $settings->get_setting('site_name');
		if(isset($options['title']) && $options['title']){
			$site_title = $options['title'] . ' - ' . $settings->get_setting('site_name');
		}
		
		$site_description = $settings->get_setting('site_description');
		if(isset($options['description']) & $options['description']){
			$site_description = $options['description'];
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
		<html lang="en-US" class="no-js no-svg">
		<head>
		<meta charset="utf-8">
		<base href="/">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<meta name="description" content="<?php echo $site_description; ?>">

		<title><?php echo $site_title; ?></title>

		<link rel='stylesheet' id='integral_zen_main'  href='<?php echo $this->cdn; ?>/theme/integralzen/styles/integral_style1.css' type='text/css' media='all' />
		<!--<link rel="stylesheet" href="<?php echo $this->cdn; ?>/theme/styles/4f877.css" media="all" />-->
		<link rel='stylesheet'  href='<?php echo $this->cdn; ?>/theme/integralzen/styles/widget-styles.css' type='text/css' media='all' />		
		<link type="text/css" rel="stylesheet" media="screen" href="<?php echo $this->cdn; ?>/theme/integralzen/styles/uni-form-profile_4.css" />
		
		<link type="text/css" href="<?php echo $this->cdn; ?>/theme/integralzen/styles/ui/jquery-ui-1.7.custom_5.css" rel="stylesheet" />
	

					<style class="et_heading_font">
				h1, h2, h3, h4, h5, h6 {
					font-family: 'Lora', Georgia, "Times New Roman", serif;				}
				</style>
							<style class="et_body_font">
				body, input, textarea, select {
					font-family: 'Lato', Helvetica, Arial, Lucida, sans-serif;				}
				</style>
							<style class="et_all_buttons_font">
				.et_pb_button {
					font-family: 'Lora', Georgia, "Times New Roman", serif;				}
				</style>
							<style class="et_primary_nav_font">
				#main-header,
				#et-top-navigation {
					font-family: 'Lato', Helvetica, Arial, Lucida, sans-serif;				}
					
				.et_pb_button {
					font-family: 'Lora', Georgia, "Times New Roman", serif;
					font-size: 16px;
					font-weight: bold;
					font-style: italic;
					text-transform: none;
					text-decoration: none !important;	
					padding: 0.3em 1em !important;					
					border: 2px solid;
					border-radius: 3px;
					background: transparent;
					line-height: 1.7em !important;
					transition: all 0.2s;
					display: inline-block;
					color: #b62027 !important;
				}
				</style>
			
	
		<!--<link rel="stylesheet" type="text/css" href="/theme/integralzen/uikit-3.4.2/css/uikit.min.css">-->

		<link href="<?php echo $this->cdn; ?>/theme/default/includes/assets/plugins/bootstrap/bootstrap.min.css" rel="stylesheet">
		
		<link href="<?php echo $this->cdn; ?>/theme/default/includes/assets/plugins/owl-carousel/owl.carousel.min.css" rel="stylesheet">
		<link href="<?php echo $this->cdn; ?>/theme/default/includes/assets/plugins/owl-carousel/owl.theme.default.min.css" rel="stylesheet">
		<link href="<?php echo $this->cdn; ?>/theme/default/includes/assets/plugins/magnific-popup/magnific-popup.min.css" rel="stylesheet">


		<link href="<?php echo $this->cdn; ?>/theme/default/includes/assets/css/theme.css" rel="stylesheet">	
		<link href="<?php echo $this->cdn; ?>/theme/test/includes/assets/css/site_styles.css" rel="stylesheet">
		<!-- Fonts/Icons -->
		<link href="<?php echo $this->cdn; ?>/theme/default/includes/assets/plugins/font-awesome/css/all.min.css" rel="stylesheet">
		<link href="<?php echo $this->cdn; ?>/theme/default/includes/assets/plugins/themify/themify-icons.min.css" rel="stylesheet">
		<link href="<?php echo $this->cdn; ?>/theme/default/includes/assets/plugins/simple-line-icons/css/simple-line-icons.css" rel="stylesheet">

		<script src="<?php echo $this->cdn; ?>/theme/integralzen/scripts/df983.js"></script> 
		
		<!-- jQuery 3.2.1 <script src="/admin/assets/vendor_components/jquery/dist/jquery.min.js"></script>-->
		<script src="<?php echo $this->cdn; ?>/theme/integralzen/includes/jquery-3.4.1.min.js"></script>
		<!--<script src="https://code.jquery.com/jquery-migrate-3.1.0.min.js"></script>-->
		
		<!-- jQuery validate -->
		<script type="text/javascript" src="/theme/integralzen/scripts/js/jquery.validate-1.9.1.js"></script>				
		

		
		<!--GDPR NOTICE  https://www.jqueryscript.net/other/GDPR-Cookie-Consent-Popup-Plugin.html-->
		<script src="<?php echo $this->cdn; ?>/theme/integralzen/scripts/GDPR/jquery.ihavecookies.js"></script>
		
		
		
		<script type="text/javascript">
			//<![CDATA[

			$(document).ready(function() {
				
					
				$('#menu-hamburger-click').click(function(){
					$('#menu-main-container').toggle('slow');
				});

				});
			//]]>
				</script>

			<noscript><style>.woocommerce-product-gallery{ opacity: 1 !important; }</style></noscript>
					<style id="twentyseventeen-custom-header-styles" type="text/css">
						.site-title,
				.site-description {
					position: absolute;
					clip: rect(1px, 1px, 1px, 1px);
				}
						</style>
				<link rel="icon" href="/theme/integralzen/images/cropped-IZ-Icon-07-32x32.png" sizes="32x32" />
		<link rel="icon" href="/theme/integralzen/images/cropped-IZ-Icon-07-192x192.png" sizes="192x192" />
		<link rel="apple-touch-icon-precomposed" href="/theme/integralzen/images/cropped-IZ-Icon-07-180x180.png" />
		<meta name="msapplication-TileImage" content="/theme/integralzen/images/cropped-IZ-Icon-07-270x270.png" />


		</head>	
	<?php	
	if(empty($options['noheader'])){
		if($_SESSION['permission'] == 10){
			require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/admin_debug.php');
		}
		?>		
			
			
			<body class="home page-template page-template-front-page page-template-front-page-php page page-id-50 logged-in wp-custom-logo wp-embed-responsive twentyseventeen cookies-not-set pmpro-body-has-access woocommerce-no-js group-blog twentyseventeen-front-page page-one-column title-tagline-hidden colors-light">
			<div id="page" class="site">
				<a class="skip-link screen-reader-text" href="#content">Skip to content</a>

				<header id="masthead" class="site-header" role="banner">
					<div class="wrap">
						<div class="menu-login-container"><ul id="login-menu" class="menu"><li id="menu-item-1007809" class="menu-item menu-item-type-post_type menu-item-object-page menu-item-1007809">
						
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
						</li>
			</ul></div>		</div>
					<div class="header-wrap">

					<div class="custom-header">

					<div class="custom-header-media">
								</div>

				<div class="site-branding">
				<div class="wrap">

					<a href="/" class="custom-logo-link" rel="home"><img width="863" height="250" src="/theme/integralzen/images/cropped-IZ-Logo-4small.png" class="custom-logo" alt="Integral Zen" srcset="/theme/integralzen/images/cropped-IZ-Logo-4small.png 863w, /theme/integralzen/images/cropped-IZ-Logo-4small-300x87.png 300w, /theme/integralzen/images/cropped-IZ-Logo-4small-768x222.png 768w, /theme/integralzen/images/cropped-IZ-Logo-4small-610x177.png 610w, /theme/integralzen/images/cropped-IZ-Logo-4small-350x101.png 350w" sizes="100vw" /></a>
					<div class="site-branding-text">
										<h1 class="site-title"><a href="/" rel="home">Integral Zen</a></h1>
						
								</div><!-- .site-branding-text -->

					
				</div><!-- .wrap -->
			</div><!-- .site-branding -->

			</div><!-- .custom-header -->
			


								<div class="navigation-top">
							<div class="wrap">
								<nav id="site-navigation" class="main-navigation" role="navigation" aria-label="Top Menu">
										<div id="menu-hamburger" class="menu-hamburger" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-has-children menu-item-7351"><a id="menu-hamburger-click" href="#"><img src="/theme/integralzen/images/menu-64.png"></a></div>

				<div class="menu-main-container" id="menu-main-container"><ul id="top-menu" class="menu"><li id="menu-item-7351" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-has-children menu-item-7351"><a href="/page/about-integral-zen">What is Integral Zen?<svg class="icon icon-angle-down" aria-hidden="true" role="img"> <use href="#icon-angle-down" xlink:href="#icon-angle-down"></use> </svg></a>
			<ul class="sub-menu">
				<!--<li id="menu-item-507620" class="menu-item menu-item-type-post_type menu-item-object-page menu-item-507620"><a href="/what-is-integral-zen/about-integral-zen/">About Integral Zen</a></li>-->
				<li id="menu-item-98" class="menu-item menu-item-type-post_type menu-item-object-page menu-item-98"><a href="/page/about-integral-zen">About Integral Zen</a></li>
				<!--<li id="menu-item-7375" class="menu-item menu-item-type-post_type menu-item-object-page menu-item-7375"><a href="/what-is-integral-zen/mission/">Mission</a></li>-->
				<li id="menu-item-124" class="menu-item menu-item-type-post_type menu-item-object-page menu-item-124"><a href="/page/integral-zen-lineage">Lineage and Mission</a></li>
				<li id="menu-item-123" class="menu-item menu-item-type-post_type menu-item-object-page menu-item-123"><a href="/page/teachers-priests">Roshi, Teachers, and Priests</a></li>
				<li id="menu-item-1008108" class="menu-item menu-item-type-post_type_archive menu-item-object-sanghas menu-item-1008108"><a href="/page/dharma-community">Dharma Community</a></li>
			</ul>
			</li>
			<li id="menu-item-7366" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-has-children menu-item-7366"><a href="/page/recommended-reading">Resources<svg class="icon icon-angle-down" aria-hidden="true" role="img"> <use href="#icon-angle-down" xlink:href="#icon-angle-down"></use> </svg></a>
			<ul class="sub-menu">
			<li id="menu-item-5486" class="menu-item menu-item-type-post_type menu-item-object-page menu-item-5486"><a href="/page/recommended-reading">Recommended Reading</a></li>
				<li id="menu-item-7048" class="menu-item menu-item-type-post_type menu-item-object-page menu-item-7048"><a href="/page/downloads">Publications</a></li>
				<li id="menu-item-4639" class="menu-item menu-item-type-post_type menu-item-object-page menu-item-4639"><a href="/page/media-library">Videos</a></li>
				<li id="menu-item-7162" class="menu-item menu-item-type-post_type menu-item-object-page menu-item-7162"><a href="/newsletter">Newsletter</a></li>
				
			</ul>
			</li>
			<li id="menu-item-7353" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-7353"><a href="/events">Retreats and Courses<svg class="icon icon-angle-down" aria-hidden="true" role="img"> <use href="#icon-angle-down" xlink:href="#icon-angle-down"></use> </svg></a>

			</li>
			<li id="menu-item-4346" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-4346"><a href="/page/schedule-meeting">Schedule a Meeting<svg class="icon icon-angle-down" aria-hidden="true" role="img"> <use href="#icon-angle-down" xlink:href="#icon-angle-down"></use> </svg></a>

			</li>
			<li id="menu-item-7357" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-7357"><a href="/dana">Donations</a>
			<!--
			<ul class="sub-menu">
				<li id="menu-item-7254" class="menu-item menu-item-type-post_type menu-item-object-page menu-item-7254"><a href="/contribute/dana/">Donations (Dana)</a></li>
				<li id="menu-item-370" class="menu-item menu-item-type-post_type menu-item-object-page menu-item-370"><a href="/volunteer_form">Volunteer</a></li>
			</ul>
			-->
			</li>
			</ul></div>
				</nav><!-- #site-navigation -->
							</div><!-- .wrap -->
						</div><!-- .navigation-top -->
						
							</div><!-- .header-wrap -->
				</header><!-- #masthead -->
	<?php } //end if noheader ?>

					
			
			<div class="site-content-contain">
				<div id="content" class="site-content">
						
		 
		<div class="wrap">
			<div id="primary" class="content-area">
				<main id="main" class="site-main" role="main">

	
		<?php
	}

	public function public_footer($options=array()) {
		$session = SessionControl::get_instance();
		$session->clear_clearable_messages();
	
		$settings = Globalvars::get_instance();
		if($settings->get_setting('force_https')){
		?>
			<!--Make sure https-->
			<script type="text/javascript">
			if (location.protocol !== 'https:') {
				location.replace(`https:${location.href.substring(location.protocol.length)}`);
			}
			</script>
		<?php
		} 
		?>
		<script type="text/javascript">
			$('body').ihavecookies({
			  title: "Cookies & Privacy",
			  message: "This website uses cookies.",
			  link: "/privacy-policy",
			  delay: 2000,
			  expires: 180, // days
			  fixedCookieTypeLabel: 'These are cookies that are essential for the website to work correctly.',
			  cookieTypesTitle: 'Cookie types',
			  advancedBtnLabel: 'More info',
			  cookieTypes: [

			],
			});

			</script>			
		
		
		
		
				</main><!-- #main -->
			</div><!-- #primary -->
		</div><!-- .wrap -->


				</div><!-- #content -->

		<!-- ***** JAVASCRIPTS ***** -->
		<script src="<?php echo $this->cdn; ?>/theme/default/includes/assets/js/polyfill.min.js?features=IntersectionObserver"></script>
		<script src="<?php echo $this->cdn; ?>/theme/default/includes/assets/plugins/plugins.js"></script>
		<script src="<?php echo $this->cdn; ?>/theme/default/includes/assets/js/functions.js"></script>
		
		<footer id="colophon" class="site-footer izfooter" role="contentinfo">
		
		<script src="/theme/integralzen/uikit-3.4.2/js/uikit.min.js"></script>
		<script src="/theme/integralzen/uikit-3.4.2/js/uikit-icons.min.js"></script> 
		
			<div class="wrap">
				<aside class="widget-area" role="complementary" aria-label="Footer">

		
						<div class="textwidget">
						<p>
						<a class="izfooter" href="mailto:info@integralzen.org">Contact: info@integralzen.org</a>&nbsp;&nbsp;
						<a href="/privacy-policy/">Privacy Policy</a>&nbsp;&nbsp;
						<a href="/sitemap">Sitemap</a>&nbsp;&nbsp;
						<a href="https://www.facebook.com/IntegralZen">Facebook</a>&nbsp;&nbsp;
						<a href="https://www.instagram.com/integralzen/">Instagram</a>&nbsp;&nbsp;
						<a href="https://twitter.com/integralzen">Twitter</a></p>
						</div>
		

				</aside>
			</div><!-- .wrap -->
		</footer><!-- #colophon -->
		</div><!-- .site-content-contain -->
		</div><!-- #page -->
		</body>
		</html>
		<?php
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
