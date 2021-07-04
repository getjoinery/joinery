<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/PublicPageMaster.php');


class PublicPage extends PublicPageMaster {



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


	public function public_header($options=array()) {
		$_GLOBALS['page_header_loaded'] = true;
		$settings = Globalvars::get_instance();
		$session = SessionControl::get_instance();
		parent::public_header();

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
		<?php $this->global_includes_top(); ?>
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
					<!-- <h3><a href="#">Test Site</a></h3>-->
					<a href="/">
					<img class="logo-dark" src="/static_files/logos/logo-trans-resized.png" alt="DevonAndJerry.com">
					<img class="logo-light" src="/static_files/logos/logo-trans-resized.png" alt="DevonAndJerry.com"> 
					</a>
				</div>
				<!-- Menu -->
				<div class="header-menu">
					<ul class="nav">
					<?php
						$menus = PublicPage::get_public_menu();
						$menus2 = $menus; 
						foreach ($menus as $menu){
							if($menu[parent]){
								$submenus = $menu['submenu'];
								echo '<li class="nav-item">';
								echo '<a class="nav-link" href="'.$menu['link'].'">'.$menu['name'].'</a>';
								
								if(!empty($submenus)){	
									echo '<ul class="nav-dropdown">';
									foreach ($submenus as $submenu){
										echo '<li class="nav-dropdown-item"><a class="nav-dropdown-link" href="'.$submenu['link'].'">'.$submenu['name'].'</a></li>';				
									}
									echo '</ul>';
								}
								echo '</li>';
							}
						}
						?>
						<!--
						<li class="nav-item">
							<a class="nav-link" href="/about">About</a>
						</li>
						<li class="nav-item">
							<a class="nav-link" href="/blog">Blog</a>
						</li>
						<li class="nav-item">
							<a class="nav-link" href="/events">Courses</a>
						</li>
						<li class="nav-item">
							<a class="nav-link" href="/contact">Contact</a>
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
						-->
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
						echo '<br /><span class="timezonesection"> Timezone: '.$session->get_timezone().' (<a href="/profile/account_edit">change</a>)</span>';
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
						<!--<div class="col-6 col-sm-6 col-lg-3">
							<h3>Devon and Jerry</h3>
						</div>
						<div class="col-6 col-sm-6 col-lg-3">
							<h6 class="font-small font-weight-normal uppercase">Useful Links</h6>
							<ul class="list-dash">
								<li><a href="/about">About us</a></li>
								<li><a href="/blog">Blog</a></li>
								<li><a href="/events">Courses</a></li>
								<li><a href="/contact">Contact</a></li>
							</ul>
						</div>--><!--
						<div class="col-6 col-sm-6 col-lg-3">
							<h6 class="font-small font-weight-normal uppercase">Additional Links</h6>
							<ul class="list-dash">
								<li><a href="#">Services</a></li>
								<li><a href="#">Process</a></li>
								<li><a href="#">FAQ</a></li>
								<li><a href="#">Careers</a></li>
							</ul>
						</div>-->
						<div class="col-6 col-sm-6 col-lg-3">
							<h6 class="font-small font-weight-normal uppercase">Contact Info</h6>
							<ul class="list-unstyled">
								<li>devonandjerry@gmail.com</li>
								<li></li>
							</ul>
						</div>
					</div><!-- end row(1) -->

					<hr class="margin-top-30 margin-bottom-30">

					<div class="row col-spacing-10">
						<div class="col-12 col-md-6 text-center text-md-left">
							<p>&copy; 2021 Devon and Jerry</p>
						</div>
						<div class="col-12 col-md-6 text-center text-md-right">
							<ul class="list-inline">
								<li><a href="https://www.facebook.com/devonandjerry"><i class="fab fa-facebook-f"></i></a></li>
								<!--<li><a href="#"><i class="fab fa-twitter"></i></a></li>
								<li><a href="#"><i class="fab fa-pinterest"></i></a></li>-->
								<li><a href="https://www.instagram.com/devonandjerry"><i class="fab fa-instagram"></i></a></li>
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



}

?>
