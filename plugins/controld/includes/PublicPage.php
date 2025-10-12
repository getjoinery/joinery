<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/PublicPageBase.php');

class PublicPage extends PublicPageBase {

	// Implement abstract method from PublicPageBase
	protected function getTableClasses() {
		return [
			'wrapper' => 'table-responsive scrollbar',
			'table' => 'table',
			'header' => 'thead-light'
		];
	}

	public static function OutputGenericPublicPage($title, $header, $body, $options=array()) {
		$page = new PublicPage();
		$page->public_header(
			array_merge(
				array(
					'title' => $title,
					'showheader' => TRUE
				),
				$options));
		echo PublicPage::BeginPage($title);
		echo PublicPage::BeginPanel();
		echo '<div class="text-lg max-w-prose mx-auto">';
		echo '<div>'.$body.'</div>';
		echo '</div>';
		
		echo PublicPage::EndPanel();
		echo PublicPage::EndPage();
		$page->public_footer();
		exit;
	}
	
	

	
	static function tab_menu($tab_menus, $current=NULL){
	
		
		$output = '';
		

		$output .= '<div class="container"><div class="filter-menu filter-menu-active">

								';
									foreach($tab_menus as $name => $link){
										if($name == 'Edit Address' || $name == 'Edit Phone Number'){
											continue;
										}
										if($name == $current){
											$output .= '
											<button data-filter="*" class="tab-btn active" type="button">'.$name.'</button>
											
											';
										}
										else{
											$output .= '
											<a href="'.$link.'"><button data-filter=".cat5" class="tab-btn" type="button">'.$name.'</button></a>
											
											';
										}
									}
                             $output .= '       
                                
                        </div></div>';
		

		
		return $output;
		
	}
	
	public function global_includes_top($options=array()){
		$settings = Globalvars::get_instance();

		
		//CHECK TO SEE IF WE PASSED IN A PREVIEW IMAGE
		if(isset($options['preview_image_url']) && $options['preview_image_url']){
			//IF NO INCREMENT IS PROVIDED, USE 1
			if(!isset($options['preview_image_increment'])){
				!$options['preview_image_increment'] = 1;
			}
			echo '<meta property="og:image" content="'.$options['preview_image_url'].'?'.$options['preview_image_increment'].'" />';			
		}
		else{
			//IF NOT, USE THE DEFAULT ONE
			$preview_image_url = $settings->get_setting('preview_image');
			if($preview_image_url){
				echo '<meta property="og:image" content="'.$settings->get_setting('preview_image').'?'.$settings->get_setting('preview_image_increment').'" />';
			}
		}
		
		if($settings->get_setting('custom_css')){
			echo '<style>'.$settings->get_setting('custom_css').'</style>';
		}
	}
	

	
	public static function BeginPage($title='', $options=array()) {
		
		$output = '';
		
		//if($title){
			
		$output .= '
    <div class="breadcumb-wrapper " data-bg-src="/plugins/controld/assets/img/bg/breadcumb-bg.jpg">
        <div class="container">
           <div class="breadcumb-content">
                <h1 class="breadcumb-title">'.$title.'</h1>
               <!--  <ul class="breadcumb-menu">
                    <li><a href="home-hr-management.html">Home</a></li>
                    <li>Contact Us</li>
                </ul>-->
            </div>
        </div>
    </div>
	
	
	<!--==============================
Career Area
==============================-->
   <div class="space overflow-hidden ">
        <div class="container">
            <!--<div class="title-area text-center">
                <span class="sub-title sub-title3">Job Post</span>
                <h2 class="sec-title">Feature Job Offers</h2>
            </div>-->
            <div class="row gy-30">
				
			';
		//}
		
			/*
			
			$output .= '<div class="space overflow-hidden ">
        <div class="container">
            <div class="title-area text-center">
                <!--<span class="sub-title sub-title3">Job Post</span>-->
                <h2 class="sec-title">'.$title.'</h2>
            </div>
            <div class="row gy-30">';	
		}
		else{
			$output .= '<div class="space overflow-hidden ">
        <div class="container">
            <div class="title-area text-center">
                <!--<span class="sub-title sub-title3">Job Post</span>-->
                <h2 class="sec-title"></h2>
            </div>
            <div class="row gy-30">';			
		}
		*/
		
		/*
		if($options['subtitle']){
			$output .= '<p class="mt-4 text-lg text-gray-500">'.$options['subtitle'].'</p>';
		}
		*/

		//$output .= '</div></div>';
		
				
						
		return $output;
	}	

	public static function EndPage($options=array()) {
		$output = '</div>
	</div>
  </div>'; 
		return $output;
	}	

	public function public_header($options=array()) {
		$_GLOBALS['page_header_loaded'] = true;
		$settings = Globalvars::get_instance();
		$session = SessionControl::get_instance();
		$options = parent::public_header_common($options);
	
	
		$profile_menu = array();
		$logged_out_menu = array();
		if ($session->get_user_id()){ 
			$profile_menu['My Profile'] = '/profile/profile';
			if($_SESSION['permission'] >= 5){ 
				$profile_menu['Admin'] = '/admin/admin_users';
			}
			$profile_menu['Settings'] = '/profile/account_edit';
			$profile_menu['Sign out'] = '/logout';
		}
		else{ 		
			$logged_out_menu['Sign in'] = '/login';			
			if($settings->get_setting('register_active')){
				//$logged_out_menu['Sign up'] = '/register';	
			}
		}	

		$cart = $session->get_shopping_cart();
		if($numitems = $cart->count_items()){
			$cart_menu = array('Cart' => '/cart');

		}
		else{
			$cart_menu = NULL;
		}
		
		$notification_menu = NULL;

		$menus = PublicPage::get_public_menu();
		
		
		
?>

<!doctype html>
<html class="no-js" lang="zxx">

<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
	
	
		<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
		<meta name="description" content="<?php echo $options['description']; ?>">
        <meta name="keywords" content="adblocker, social media blocker, adult content filter, malware blocker">

		<title><?php echo $options['title']; ?></title>
				<?php $this->global_includes_top($options); ?>
				
				
    <!--<meta name="author" content="Themeholy">-->
    <meta name="robots" content="INDEX,FOLLOW">

    <!-- Favicons - Place favicon.ico in the root directory -->
	<!--
    <link rel="apple-touch-icon" sizes="57x57" href="/plugins/controld/assets/img/favicons/apple-icon-57x57.png">
    <link rel="apple-touch-icon" sizes="60x60" href="/plugins/controld/assets/img/favicons/apple-icon-60x60.png">
    <link rel="apple-touch-icon" sizes="72x72" href="/plugins/controld/assets/img/favicons/apple-icon-72x72.png">
    <link rel="apple-touch-icon" sizes="76x76" href="/plugins/controld/assets/img/favicons/apple-icon-76x76.png">
    <link rel="apple-touch-icon" sizes="114x114" href="/plugins/controld/assets/img/favicons/apple-icon-114x114.png">
    <link rel="apple-touch-icon" sizes="120x120" href="/plugins/controld/assets/img/favicons/apple-icon-120x120.png">
    <link rel="apple-touch-icon" sizes="144x144" href="/plugins/controld/assets/img/favicons/apple-icon-144x144.png">
    <link rel="apple-touch-icon" sizes="152x152" href="/plugins/controld/assets/img/favicons/apple-icon-152x152.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/plugins/controld/assets/img/favicons/apple-icon-180x180.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/plugins/controld/assets/img/favicons/android-icon-192x192.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/plugins/controld/assets/img/favicons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="96x96" href="/plugins/controld/assets/img/favicons/favicon-96x96.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/plugins/controld/assets/img/favicons/favicon-16x16.png">
    <link rel="manifest" href="/plugins/controld/assets/img/favicons/manifest.json">
    <meta name="msapplication-TileColor" content="#ffffff">
    <meta name="msapplication-TileImage" content="/plugins/controld/assets/img/favicons/ms-icon-144x144.png">
    <meta name="theme-color" content="#ffffff">
	-->

    <!--==============================
	  Google Fonts
	============================== -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap" rel="stylesheet">

    <!-- Jquery -->
	<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script> 

    <!--==============================
	    All CSS File
	============================== -->
    <!-- Bootstrap -->
    <!--<link rel="stylesheet" href="/plugins/controld/assets/css/bootstrap.min.css">-->
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Fontawesome Icon -->
    <link rel="stylesheet" href="/plugins/controld/assets/css/fontawesome.min.css">
	<!--<link href="https://cdn.jsdelivr.net/npm/@r3dz0n3/fontawesome-pro@6.3.0/css/fontawesome.min.css" rel="stylesheet">-->
    <!-- Magnific Popup -->
    <!--<link rel="stylesheet" href="/plugins/controld/assets/css/magnific-popup.min.css">-->
	<link href="https://cdn.jsdelivr.net/npm/magnific-popup@1.2.0/dist/magnific-popup.min.css" rel="stylesheet">
    <!-- Swiper Slider -->
    <link rel="stylesheet" href="/plugins/controld/assets/css/swiper-bundle.min.css">
    <!-- Theme Custom CSS -->
    <link rel="stylesheet" href="/plugins/controld/assets/css/style.css">
    <!-- ControlD Plugin CSS -->
    <link rel="stylesheet" href="/plugins/controld/assets/css/controld-plugin.css">

    <!-- Facebook Open Graph Meta Tags -->
    <meta property="og:title" content="Scrolldaddy - Take Control of Your Browsing">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://scrolldaddy.app">
    <meta property="og:image" content="https://scrolldaddy.app/static_files/scrolldaddylogonopadding.svg">
    <meta property="og:description" content="Block distractions, filter harmful content, and take back control of your browsing with Scrolldaddy.">
    <meta property="og:site_name" content="Scrolldaddy">
    <meta property="og:locale" content="en_US">
    <!--<meta property="fb:app_id" content="YOUR_FACEBOOK_APP_ID">--> <!-- Optional -->

</head>

<body>

    <!--[if lte IE 9]>
    	<p class="browserupgrade">You are using an <strong>outdated</strong> browser. Please <a href="https://browsehappy.com/">upgrade your browser</a> to improve your experience and security.</p>
  <![endif]-->

    <!--********************************
   		Code Start From Here 
	******************************** -->

    <!--==============================
     Preloader
  ==============================-->
  <!--
    <div id="preloader" class="preloader ">
        <button class="th-btn th-radius preloaderCls">Cancel Preloader </button>
        <div class="preloader">
            <div class="loading-container">
                <div class="loading"></div>
                <div class="preloader-logo">
                    <a class="icon-masking" href="home-hr-management.html"><span data-mask-src="/plugins/controld/assets/img/preloader.svg" class="mask-icon"></span><img src="/plugins/controld/assets/img/preloader.svg" alt="Sassa"></a>
                </div>
            </div>
        </div>
    </div>
	-->
	
	
	
	<!--==============================
    Sidemenu
============================== -->
<!--
    <div class="sidemenu-wrapper d-none d-lg-block ">
        <div class="sidemenu-content">
            <button class="closeButton sideMenuCls"><i class="far fa-times"></i></button>
            <div class="widget footer-widget">
                <h3 class="widget_title">About Company</h3>
                <div class="th-widget-about">
                    <p class="about-text">Professionally redefine transparent ROI through low-risk high-yield imperatives. Progressively create empowered. cost effective users via team driven.</p>
                    <div class="th-social">
                        <a href="https://www.facebook.com/"><i class="fab fa-facebook-f"></i></a>
                        <a href="https://www.twitter.com/"><i class="fab fa-twitter"></i></a>
                        <a href="https://www.linkedin.com/"><i class="fab fa-linkedin-in"></i></a>
                        <a href="https://www.whatsapp.com/"><i class="fab fa-whatsapp"></i></a>
                        <a href="https://www.youtube.com/"><i class="fab fa-youtube"></i></a>
                    </div>
                </div>
            </div>
            <div class="widget footer-widget">
                <h3 class="widget_title">Recent Posts</h3>
                <div class="recent-post-wrap">
                    <div class="recent-post">
                        <div class="media-img">
                            <a href="blog-details.html"><img src="/plugins/controld/assets/img/blog/recent-post-1-1.jpg" alt="Blog Image"></a>
                        </div>
                        <div class="media-body">
                            <h4 class="post-title"><a class="text-inherit" href="blog-details.html">The Benefits of Managed IT Services post could</a></h4>
                            <div class="recent-post-meta">
                                <a href="blog.html">21 July, 2024</a>
                            </div>
                        </div>
                    </div>
                    <div class="recent-post">
                        <div class="media-img">
                            <a href="blog-details.html"><img src="/plugins/controld/assets/img/blog/recent-post-1-2.jpg" alt="Blog Image"></a>
                        </div>
                        <div class="media-body">
                            <h4 class="post-title"><a class="text-inherit" href="blog-details.html">The Benefits of Managed IT Services post could</a></h4>
                            <div class="recent-post-meta">
                                <a href="blog.html">22 July, 2024</a>
                            </div>
                        </div>
                    </div>
                    <div class="recent-post">
                        <div class="media-img">
                            <a href="blog-details.html"><img src="/plugins/controld/assets/img/blog/recent-post-1-3.jpg" alt="Blog Image"></a>
                        </div>
                        <div class="media-body">
                            <h4 class="post-title"><a class="text-inherit" href="blog-details.html">The Benefits of Managed IT Services post could</a></h4>
                            <div class="recent-post-meta">
                                <a href="blog.html">23 July, 2024</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="widget footer-widget">
                <h3 class="widget_title">Get In Touch</h3>
                <div class="th-widget-contact">
                    <div class="info-box">
                        <div class="box-icon">
                            <img src="/plugins/controld/assets/img/icon/location.svg" alt="">
                        </div>
                        <p class="box-text">55 Main Street, 2nd block Malborne, Australia</p>
                    </div>
                    <div class="info-box">
                        <div class="box-icon">
                            <img src="/plugins/controld/assets/img/icon/mail.svg" alt="">
                        </div>
                        <p class="box-text">
                            <a href="mailto:info@Sassa.com" class="box-link">info@Sassa.com</a>
                            <a href="mailto:info.example@Sassa.com" class="box-link">info.example@Sassa.com</a>
                        </p>
                    </div>
                    <div class="info-box">
                        <div class="box-icon">
                            <img src="/plugins/controld/assets/img/icon/call.svg" alt="">
                        </div>
                        <p class="box-text">
                            <a href="tel:+16365414789" class="box-link">+163 6541 4789</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div> 
	-->
	
	<!--==============================
    Mobile Menu
  ============================== -->
    <div class="th-menu-wrapper">
        <div class="th-menu-area text-center">
            <button class="th-menu-toggle"><i class="fal fa-times"></i></button>
            <div class="mobile-logo">
			<a class="icon-masking" href="/"><span data-mask-src="/static_files/scrolldaddylogonopadding.svg" class="mask-icon"></span><img src="/static_files/scrolldaddylogonopadding.svg" alt="ScrollDaddy" width="200"></a>
               <!-- <a href="/"><img src="/plugins/controld/assets/img/logo.svg" alt="Sassa"></a>-->
            </div>

            <div class="th-mobile-menu">
			<ul>
 		<?php
		
			if($session->get_user_id()){
				echo '<li><a href="/profile/devices">Devices</a></li>';
				echo '<li><a href="/profile">Settings</a></li>';
				echo '<li><a href="/logout">Log out</a></li>';
			}
			else{
				if($settings->get_setting('register_active')){
					echo '<li><a href="/pricing">Sign up</a></li>';
				}
				echo '<li><a href="/login">Log in</a></li>';
			}	
			$cart = $session->get_shopping_cart();
			if($cart->count_items()){
				echo '<li><a href="/cart">Cart</a></li>';
			}			
		
			foreach ($menus as $menu){
				if($menu['parent'] == true){
					$submenus = $menu['submenu'];
					
					if(empty($submenus)){	
						echo '<li><a href="'.$menu['link'].'" class="text-base font-medium text-gray-500 hover:text-gray-900">'.$menu['name'].'</a></li>';
					}
					else{
						echo '
						<li class="menu-item-has-children">
							<a href="'.$menu["link"].'">'.$menu["name"].'</a>
							<ul class="sub-menu">';
							foreach ($submenus as $submenu){ 
								echo '<li><a href="'.$submenu["link"].'">'.$submenu["name"].'</a></li>';
							 }
							echo '</ul>
						</li>';
						
						
					}
				}
			}		
			?>

                </ul>
            </div>
        </div>
    </div>
	
	
	<!--==============================
	Header Area
==============================-->
    <header class="th-header default-header">
        <div class="sticky-wrapper">
            <!-- Main Menu Area -->
            <div class="menu-area">
                <div class="container">
                    <div class="row align-items-center justify-content-between">
                        <div class="col-auto">
                            <div class="header-logo">
							<!--<h2 class="hero-title">ScrollDaddy</h2>-->
							
                                <a class="icon-masking" href="/"><span data-mask-src="/static_files/scrolldaddylogonopadding.svg" class="mask-icon"></span><img src="/static_files/scrolldaddylogonopadding.svg" alt="ScrollDaddy" width="300"></a>
                            </div>
                        </div>
                        <div class="col-auto">
                            <nav class="main-menu style2 d-none d-lg-inline-block">
							 <ul>
                                    
									<?php
										
										foreach ($menus as $menu){
											if($menu['parent'] == true){
												$submenus = $menu['submenu'];
												
												if(empty($submenus)){	
													echo '          <li><a href="'.$menu['link'].'" class="text-base font-medium text-gray-500 hover:text-gray-900">'.$menu['name'].'</a></li>';
												}
												else{
													echo '
													<li class="menu-item-has-children">
														<a href="'.$menu["link"].'">'.$menu["name"].'</a>
														<ul class="sub-menu">';
														foreach ($submenus as $submenu){ 
															echo '<li><a href="'.$submenu["link"].'">'.$submenu["name"].'</a></li>';
														 }
														echo '</ul>
													</li>';
													
													
												}
											}
										}		
										?>
                                   
                                </ul>
								
                            </nav>
                            <div class="header-button">
                                <button type="button" class="th-menu-toggle d-inline-block d-lg-none"><i class="far fa-bars"></i></button>
                            </div>
                        </div>
                        <div class="col-auto d-none d-lg-block">
                            <div class="header-button">
								<?php
								if($session->get_user_id()){
									echo '<a href="/profile/devices" class="icon-btn"><i class="fa-light fa-user"></i></a>';
									echo '<a href="/profile" class="icon-btn"><i class="fa-light fa-gear"></i></a>';
									echo '<a href="/logout" class="th-btn">Log out</a>';
								}
								else{
									//echo '<a href="/register" class="th-btn">Sign up</a>';
									echo '<a href="/login" class="th-btn">Log in</a>';
								}
								$cart = $session->get_shopping_cart();
								if($cart->count_items()){
									echo '<a href="/cart" class="icon-btn"><i class="fa-light fa-cart-shopping"></i></a>';
								}
								?>
                                
                                <!--<a href="#" class="icon-btn sideMenuToggler"><i class="fa-light fa-grid"></i></a>-->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>
<?php	
		
		
		
		
		/*
		?>

	
	<!--==============================
	Header Area
==============================-->
    <header class="th-header header-layout1 header-absolute">

            
                        
	
*/
		

	}

	public function public_footer($options=array()) {
		$session = SessionControl::get_instance();
		$session->clear_clearable_messages();
		$settings = Globalvars::get_instance();
		
	
	?>
	   <footer class="footer-wrapper footer-layout1">
        <div class="container">
			<?php
			if(!$session->get_user_id() && $settings->get_setting('emails_active')){
			?>
            <div class="footer-top">
                <div class="row gx-0 align-items-center">
                    <div class="col-xl-6">
                        <div class="footer-newsletter-content">
                            <h3 class="mb-15 mt-n1">Subscribe our Newsletter</h3>
                            <p class="footer-text2">Keep updated about deals, new features, and useful info.</p>
                        </div>
                    </div>
                    <div class="col-xl-6">
                        <div class="footer-newsletter">
                            <form class="newsletter-form style2" method="GET" action="/lists">
                                <div class="form-group">
                                    <i class="fa-sharp fa-light fa-envelope"></i>
                                    <input class="form-control" name="email" type="email" placeholder="Email Address" required="">
                                </div>
                                <button type="submit" class="th-btn">Subscribe Now</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
			<?php
			}
			?>
            <div class="widget-area">
                <div class="row justify-content-between">
                    <div class="col-md-6 col-xxl-3 col-xl-4">
                        <div class="widget footer-widget">
                            <div class="th-widget-about">
                                <div class="about-logo">
								<h3 class="hero-title">ScrollDaddy</h3>
                                    <!--<a class="icon-masking" href="index.html"><span data-mask-src="/plugins/controld/assets/img/logo.svg" class="mask-icon"></span><img src="/plugins/controld/assets/img/logo.svg" alt="Sassa"></a>-->
                                </div>
                                <p class="about-text">Manage your screentime and protect against internet threats.</p>
                                <!--<div class="th-social">
                                    <a href="https://www.facebook.com/"><i class="fab fa-facebook-f"></i></a>
                                    <a href="https://www.twitter.com/"><i class="fab fa-twitter"></i></a>
                                    <a href="https://www.linkedin.com/"><i class="fab fa-linkedin-in"></i></a>
                                    <a href="https://www.whatsapp.com/"><i class="fab fa-whatsapp"></i></a>
                                </div>-->
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-auto">
                        <div class="widget widget_nav_menu footer-widget">
                            <h3 class="widget_title">Product</h3>
                            <div class="menu-all-pages-container">
                                <ul class="menu">
                                    <li><a href="https://scrolldaddy.app/page/overview"> Product Overview</a></li>
                                    <li><a href="https://scrolldaddy.app/pricing">Pricing</a></li>     
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-auto">
                        <div class="widget widget_nav_menu footer-widget">
                            <h3 class="widget_title">Resources</h3>
                            <div class="menu-all-pages-container">
                                <ul class="menu">
                                    <!--<li><a href="https://scrolldaddy.app/blog">Blog</a></li>-->
                                    <li><a href="https://scrolldaddy.app/page/privacy">Privacy Policy</a></li>
									<li><a href="https://scrolldaddy.app/page/terms">Terms of Service</a></li>
                                    <li><a href="https://scrolldaddy.app/page/faq">FAQ</a></li>
                                    <li><a href="https://scrolldaddy.app/lists">Newsletter</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-auto">
                        <div class="widget widget_nav_menu footer-widget">
                            <h3 class="widget_title">Use Cases</h3>
                            <div class="menu-all-pages-container">
                                <ul class="menu">
                                    <li><a href="https://scrolldaddy.app/page/social-media-addiction">Block Social Media </a></li>
                                    <li><a href="https://scrolldaddy.app/page/news-doomscrolling">News Doomscrolling </a></li>
                                    <li><a href="https://scrolldaddy.app/page/online-gambling-addiction">Online Gambling Addiction </a></li>
                                    <li><a href="https://scrolldaddy.app/page/porn-addiction">Porn Addiction</a></li>
									<li><a href="https://scrolldaddy.app/page/children-web-filtering">Web Filtering for Children</a></li>
									<li><a href="https://scrolldaddy.app/page/online-safety-seniors">Online Safety for Seniors</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-auto">
                        <div class="widget widget_nav_menu footer-widget">
                            <h3 class="widget_title">Company</h3>
                            <div class="menu-all-pages-container">
                                <ul class="menu">
                                    <li><a href="https://scrolldaddy.app/page/about">About Us</a></li>
                                    <li><a href="https://scrolldaddy.app/page/contact">Contact Us</a></li>
                                
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="copyright-wrap text-center">
            <div class="container">
                <p class="copyright-text">Copyright <i class="fal fa-copyright"></i> 2025 ScrollDaddy. All Rights Reserved.</p>
            </div>
        </div>
    </footer>

    <!--********************************
			Code End  Here 
	******************************** -->

    <!-- Scroll To Top -->
    <div class="scroll-top">
        <svg class="progress-circle svg-content" width="100%" height="100%" viewBox="-1 -1 102 102">
            <path d="M50,1 a49,49 0 0,1 0,98 a49,49 0 0,1 0,-98" style="transition: stroke-dashoffset 10ms linear 0s; stroke-dasharray: 307.919, 307.919; stroke-dashoffset: 307.919;">
            </path>
        </svg>
    </div>

    <!--==============================
    All Js File
============================== -->

    <!--<script src="/plugins/controld/assets/js/vendor/jquery-3.7.1.min.js"></script> //LOADED IN THE HEADER-->
	<!-- Joinery Validation (Pure JS, no jQuery dependency) -->
	<script src="/assets/js/joinery-validate.js"></script>
    <!-- Swiper Slider -->
    <script src="/plugins/controld/assets/js/swiper-bundle.min.js"></script>
    <!-- Bootstrap -->
    <!--<script src="/plugins/controld/assets/js/bootstrap.min.js"></script>-->
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.min.js"></script>
    <!-- Magnific Popup -->
    <!--<script src="/plugins/controld/assets/js/jquery.magnific-popup.min.js"></script>-->
	<script src="https://cdn.jsdelivr.net/npm/magnific-popup@1.2.0/dist/jquery.magnific-popup.min.js"></script>
    <!-- Counter Up -->
    <!--<script src="/plugins/controld/assets/js/jquery.counterup.min.js"></script>-->
    <!-- Circle Progress -->
    <!--<script src="/plugins/controld/assets/js/circle-progress.js"></script>-->
    <!-- Range Slider -->
    <!--<script src="/plugins/controld/assets/js/jquery-ui.min.js"></script>-->
	<script src="https://cdn.jsdelivr.net/npm/jquery-ui@1.14.1/dist/jquery-ui.min.js"></script>
    <!-- Imagesloadedr -->
    <!--<script src="/plugins/controld/assets/js/imagesloaded.pkgd.min.js"></script>-->
    <!-- isotope -->
    <!--<script src="/plugins/controld/assets/js/isotope.pkgd.min.js"></script>-->
    <!-- Tilt.jquery -->
    <!--<script src="/plugins/controld/assets/js/tilt.jquery.min.js"></script>-->
    <!-- Nice-select -->
    <!--<script src="/plugins/controld/assets/js/nice-select.min.js"></script>-->
    <!-- wow -->
    <!--<script src="/plugins/controld/assets/js/wow.min.js"></script>-->

    <!-- Main Js File -->
    <script src="/plugins/controld/assets/js/main.js"></script>
    <!-- ControlD Plugin JS -->
    <script src="/plugins/controld/assets/js/controld-plugin.js"></script>

</body>

</html>
	
	<?php
	
	}

}

?>
