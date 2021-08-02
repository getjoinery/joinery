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
	

		echo '<div class="container"><div class="vertical-space-20"></div><p>'.$body.'</p><div class="vertical-space-20"></div></div>';

		
		echo PublicPage::EndPage();
		$page->public_footer();
		exit;
	}
	
	public static function BeginPage($title='', $options=array()) {
		$output = '';
		if($title){
			$output .= '

							<div class="container"><div class="vertical-space-20"></div><h2 class="main-title text-center">'.$title.'</h2>';
							if($options['subtitle']){
								$output .= '<h6 class="sub-title after-title text-center">'.$options['subtitle'].'</h6>';
							}
							$output .= '</div><div class="vertical-space-70"></div>';
		}
		return $output;
	}	

	public static function EndPage($options=array()) {
		$output = '<div class="vertical-space-40"></div>'; 
		return $output;
	}	

	public function public_header($options=array()) {
		$_GLOBALS['page_header_loaded'] = true;
		$settings = Globalvars::get_instance();
		$session = SessionControl::get_instance();
		$options = parent::public_header_common($options);

?>
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:fb="http://ogp.me/ns/fb#">
	<head>
		<meta charset="utf-8">
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<meta name="viewport" content="width=device-width">
		<meta name="description" content="<?php echo $options['meta_description']; ?>">
        <meta name="keywords" content="">

		<title><?php echo $options['title']; ?></title>
		<!-- Favicon -->
		<!--
        <link href="../assets/images/favicon.png" rel="shortcut icon">
		<link rel="icon" href="/theme/integralzen/images/cropped-IZ-Icon-07-32x32.png" sizes="32x32" />
		<link rel="icon" href="/theme/integralzen/images/cropped-IZ-Icon-07-192x192.png" sizes="192x192" />
		<link rel="apple-touch-icon-precomposed" href="/theme/integralzen/images/cropped-IZ-Icon-07-180x180.png" />
		<meta name="msapplication-TileImage" content="/theme/integralzen/images/cropped-IZ-Icon-07-270x270.png" />	
		-->
		<?php $this->global_includes_top($options); ?>
					
	
		<!--THIS TEMPLATE STYLES -->
		<link rel="stylesheet" href="<?php echo $this->theme_url; ?>/css/font-awesome.min.css">
		<link href="<?php echo $this->theme_url; ?>/css/bootstrap.min.css" rel="stylesheet" />
		<link href="<?php echo $this->theme_url; ?>/css/owl.carousel.min.css" rel="stylesheet" />
		<link href="<?php echo $this->theme_url; ?>/css/settings.css" rel="stylesheet" />
		<link href="<?php echo $this->theme_url; ?>/css/jquery.fancybox.min.css" rel="stylesheet" />
		<link href="<?php echo $this->theme_url; ?>/css/animate.css" rel="stylesheet" />
		<!-- Default css -->
		<link href="<?php echo $this->theme_url; ?>/css/style.css" rel="stylesheet" />
		<!-- Theme css -->
		<link href="https://fonts.googleapis.com/css?family=Karla:400,400i,700,700i" rel="stylesheet">
		<link href="https://fonts.googleapis.com/css?family=Lusitana:400,700" rel="stylesheet">

		<?php
		if($settings->get_setting('custom_css')){
			echo '<style>'.$settings->get_setting('custom_css').'</style>';
		}
		?>
		
		<?php	
	if(empty($options['noheader'])){
		if($_SESSION['permission'] == 10){
			require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/admin_debug.php');
		}
		?>	
	</head>
	<body>
		<!-- Start header - Second variation -->
		<header class="v2">
			<div class="top-bar">
				<div class="container">
					<a href="/"><img src="/static_files/liberato-method-logo-300.png" alt="Xandy Liberato Logo"></a>
					<!--<h3 style="display:inline;">Xandy Liberato</h3>-->

					<div class="contact-info">
					<!--
						<div class="media">
							<div class="media-left">
								<i class="fa fa-phone" aria-hidden="true"></i>
							</div>
							<div class="media-body">
								<h5>Call Us</h5>
								<a href="#"> 1800-153-259 </a>
							</div>
						</div>
						-->
     <?php 
						if ($session->get_user_id()){
							echo '<a style="margin-right:5px;" href="/profile/profile">My Profile</a>'; 
							if($_SESSION['permission'] >= 5){
								echo '<a  style="margin-right:5px;" href="/admin/admin_users">Admin</a>';
							}

							$cart = $session->get_shopping_cart();
							if($numitems = $cart->count_items()){
								echo '<a style="margin-right:5px;" href="/cart">Cart ('. $numitems . ') </a> &nbsp;';
							}
							else{
								//echo '<span class="cartcontents">Cart</span> ';
							}

							echo '<a style="margin-right:5px;" href="/logout">Log out </a>';

						}
						else{
							echo '<a style="margin-right:5px;" href="/login">Log in </a><a style="text-decoration:underline;margin-right:5px;" href="/register">Register </a>';
						}
						
						if($_SESSION['permission'] == 10){
							echo '<a style="margin-right:5px;" id="admintoggle" href="#">Debug </a>';				
						}
						if($session->get_timezone()){
							echo '<br /><a style="margin-right:5px;" href="/profile/account_edit">Timezone: '.$session->get_timezone().' (change)</a>';
						}
						?>
					</div>

				</div>
			</div>
			<nav class="navbar navbar-default menu-style-3">
				<div class="container">
					<!-- Brand and toggle get grouped for better mobile display -->
					<div class="navbar-header">
						<button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#bs-example-navbar-collapse-1" aria-expanded="false">
							<span class="sr-only">Toggle navigation</span>
							<span class="icon-bar"></span>
							<span class="icon-bar"></span>
							<span class="icon-bar"></span>
						</button>
					</div>

					<!-- Collect the nav links, forms, and other content for toggling -->
					<div class="collapse navbar-collapse" id="bs-example-navbar-collapse-1">
						<ul class="nav navbar-nav">
							<li><a href="/page/about">About Us</a></li>
							<li><a href="/events">Classes</a></li>
							<li><a href="/page/liberato-method">The Liberato Method</a></li>
							<li><a href="/page/contact">Contact Us</a></li>
						</ul>
					</div><!-- /.navbar-collapse -->
				</div><!-- /.container-fluid -->
			</nav>
		</header>
		<!-- End header section -->


		
	<?php } //end if noheader ?>

		
	
		<?php
	}

	public function public_footer($options=array()) {
		$session = SessionControl::get_instance();
		$session->clear_clearable_messages();
	
		?>
		<!-- start footer -->
		<footer class="footer-copyright v2"> <!-- Footer variation-2 -->
			<div class="background-footer-v2"><!-- First footer -->
				<div class="container">
					<div class="vertical-space-50"></div>
					<div class="row">
						<div class="col-xs-12 col-sm-12 col-md-12 text-center">
							<!--<img src="images/logo.png" alt="Logo">-->
							<div class="vertical-space-20"></div>
						</div>
						<div class="col-xs-12 col-sm-12 col-md-12 text-center">
							<ul class="social-icons">
								<li>
									<a href="https://www.facebook.com/xandy.liberato">
										<i class="fa fa-facebook" aria-hidden="true"></i>
									</a>
								</li>
								<li>
									<a href="https://www.instagram.com/xandyliberato/">
										<i class="fa fa-instagram" aria-hidden="true"></i>
									</a>
								</li>
								<li>
									<a href="https://open.spotify.com/user/gvc9zna585o9hek1s12q487gm?si=b390d9d271c1423f">
										<i class="fa fa-spotify" aria-hidden="true"></i>
									</a>
								</li>
								<li>
									<a href="https://www.youtube.com/user/xliberato">
										<i class="fa fa-youtube-play" aria-hidden="true"></i>
									</a>
								</li>
							</ul> <!-- social-icons -->
						</div>
						<!--
						<div class="col-xs-12 col-sm-12 col-md-12 text-center">
							<div class="vertical-space-10"></div>
							<ul class="qucik-links">
								<li><a href="/page/about">About</a></li>
								<li><a href="/events">Classes</a></li>
							</ul>
							<div class="vertical-space-20"></div>
						</div>
						-->
					</div>
				</div>
			</div>
			<div class="container"> <!-- Second footer -->
				<div class="vertical-space-50"></div>
				<div class="row">
				<!--
					<div class="col-xs-12 col-sm-6 col-md-3">
						<h4>Our Timing</h4>
						<div class="vertical-space-40"></div>
						<div class="vertical-space-10"></div>
						<p><strong>Monday</strong> 9:00 am to 6:00 pm</p>
						<p><strong>Tuesday</strong> 9:00 am to 6:00 pm</p>
						<p><strong>Wensday</strong> 9:00 am to 6:00 pm</p>
						<p><strong>Thursday</strong> 9:00 am to 6:00 pm</p>
						<p><strong>Friday</strong> 9:00 am to 6:00 pm</p>
						<p><strong>Saturday</strong> 9:00 am to 2:00 pm</p>
						<p><strong>Sunday</strong> Off </p>
					</div>-->
					<div class="col-xs-12 col-sm-6 col-md-3">
						<h4>Contact Us</h4>
						<!--<div class="vertical-space-40"></div>-->
						<div class="vertical-space-10"></div>
						<ul class="footer-addres">
							<li>
								<i class="fa fa-map-marker" aria-hidden="true"></i>
								Valencia, Spain
							</li>
							<li>
								<i class="fa fa-envelope-o" aria-hidden="true"></i>
								<a href="mailto:info@xandyliberato.com">info@xandyliberato.com</a>
							</li>
						</ul>
					</div><!-- 
					<div class="col-xs-12 col-sm-6 col-md-3">
						<h4>Instagram</h4>
						<div class="vertical-space-40"></div>
						<div class="vertical-space-10"></div>

						<ul class="instagram-list">
							<li><a href="#"><img src="images/new/home-var3/footer.png" alt="..."></a></li>
							<li><a href="#"><img src="images/new/home-var3/footer.png" alt="..."></a></li>
							<li><a href="#"><img src="images/new/home-var3/footer.png" alt="..."></a></li>
							<li><a href="#"><img src="images/new/home-var3/footer.png" alt="..."></a></li>
							<li><a href="#"><img src="images/new/home-var3/footer.png" alt="..."></a></li>							
							<li><a href="#"><img src="images/new/home-var3/footer.png" alt="..."></a></li>
							<li><a href="#"><img src="images/new/home-var3/footer.png" alt="..."></a></li>
							<li><a href="#"><img src="images/new/home-var3/footer.png" alt="..."></a></li>
							<li><a href="#"><img src="images/new/home-var3/footer.png" alt="..."></a></li>				
						</ul>
					</div>-->
					<!--
					<div class="col-xs-12 col-sm-6 col-md-3">
						<h4>Send Inquiry</h4>
						<div class="vertical-space-40"></div>
						<div class="vertical-space-10"></div>
						<form name="contact_form_2" method="post" action="functions.php">
							<input type="text" id="home2_fname" name="home2_fname" placeholder="Full Name" required>
							<input type="text" id="home2_email" name="home2_email" placeholder="Email" required>
							<input type="text" id="home2_contact" name="home2_contact" placeholder="Contact Number" required>
							<textarea id="home2_message" name="home2_message" required>Message*</textarea>
							<input type="submit" name="Submit" value="Send Inquiry">
						</form>
						<div class="vertical-space-20"></div>
					</div>
					-->
				</div>
			</div>
		</footer>
		<!-- end footer -->
		<!-- Modal box data -->
		<!--
		<div class="modal fade" id="videoModal" tabindex="-1" role="dialog">
			<div class="modal-dialog modal-lg" role="document">
				<div class="modal-content">
					<div class="modal-body">
						<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
						<video width="320" height="240" controls class="full-width">
							<source src="video/357014656.mp4" type="video/mp4">
							<source src="video/357014656.ogg" type="video/ogg">
							Your browser does not support the video tag.
						</video> 
					</div>
				</div>
			</div>
		</div>
		-->
		<!-- End Modal box data -->
		<!--<script src="js/jquery-2.1.4.min.js"></script>		-->
		<script src="<?php echo $this->theme_url; ?>/js/wow.js"></script>
		<script src="<?php echo $this->theme_url; ?>/js/bootstrap.min.js"></script>
		<script src="<?php echo $this->theme_url; ?>/js/jquery.themepunch.tools.min.js"></script>
		<script src="<?php echo $this->theme_url; ?>/js/jquery.themepunch.revolution.min.js"></script>
		<script src="<?php echo $this->theme_url; ?>/js/owl.carousel.min.js"></script>
		<script src="<?php echo $this->theme_url; ?>/js/jquery.fancybox.min.js"></script>
		<script src="<?php echo $this->theme_url; ?>/js/isotope.pkgd.min.js"></script>
		<script src="<?php echo $this->theme_url; ?>/js/jquery.countdown.min.js"></script>
		<script src="<?php echo $this->theme_url; ?>/js/moment.min.js"></script>
		<script src="<?php echo $this->theme_url; ?>/js/jquery.touchSwipe.js"></script>
		<script src="<?php echo $this->theme_url; ?>/js/jquery.cookie.js"></script>
		<script type="text/javascript" src="<?php echo $this->theme_url; ?>/js/jquery.rollingslider.js"></script>
		<script src="<?php echo $this->theme_url; ?>/js/custom.js"></script>
		<!-- Default JS -->
		<script> /* Start to Add data in Sechdule Section and table with Date and time wise */
			jQuery(document).ready(function($) {

				$('#demo').RollingSlider({
					showArea:"#example",
					prev:"#jprev",
					next:"#jnext",
					moveSpeed:300,
					autoPlay:false
				});
			});
		</script>
	</body>
</html>	
		

		<?php
	}


}

?>
