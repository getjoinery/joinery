<?php
require_once('PublicPageMaster.php');

class PublicPageFalcon extends PublicPageMaster {

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
	
	public static function BeginPage($title='', $options=array()) {


		$output = '
		
			<div class="card">';
			if($title){
				$output .= '
				<div class="card-header bg-body-tertiary">
					<h5 class="mb-0">'.$title.'</h5>
				</div>';
			}
			$output .= '
				<div class="card-body">
            ';
		
				
						
		return $output;
	}	

	public static function EndPage($options=array()) {
		$output = '</div></div>
          '; 
		return $output;
	}	

	public static function BeginPanel($options=array()) {
		$output = '<div>'; 
		return $output;
	}



	public static function EndPanel($options=array()) {
		$output = '
		</div>'; 
		return $output;
	}
	
	public function top_right_menu(){
		$session = SessionControl::get_instance();
		$settings = Globalvars::get_instance();

		$cart = $session->get_shopping_cart();
		if($numitems = $cart->count_items()){
			$cart_menu = array('Cart' => '/cart');

		}
		else{
			$cart_menu = NULL;
		}
			
			
			//SHOPPING CART MENU ITEM
			if($cart_menu){
				echo '<li class="nav-item d-none d-sm-block">
				  <a class="nav-link px-0 notification-indicator notification-indicator-warning notification-indicator-fill fa-icon-wait" href="#"><span class="fas fa-shopping-cart" data-fa-transform="shrink-7" style="font-size: 33px;"></span><span class="notification-indicator-number">$numitems</span></a>

				</li>';
			}
			
			//NOTIFICATION MENU ITEM
			?>
			<!--
            <li class="nav-item dropdown">
              <a class="nav-link notification-indicator notification-indicator-primary px-0 fa-icon-wait" id="navbarDropdownNotification" role="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false" data-hide-on-body-scroll="data-hide-on-body-scroll"><span class="fas fa-bell" data-fa-transform="shrink-6" style="font-size: 33px;"></span></a>
              <div class="dropdown-menu dropdown-caret dropdown-caret dropdown-menu-end dropdown-menu-card dropdown-menu-notification dropdown-caret-bg" aria-labelledby="navbarDropdownNotification">
                <div class="card card-notification shadow-none">
                  <div class="card-header">
                    <div class="row justify-content-between align-items-center">
                      <div class="col-auto">
                        <h6 class="card-header-title mb-0">Notifications</h6>
                      </div>
                      <div class="col-auto ps-0 ps-sm-3"><a class="card-link fw-normal" href="#">Mark all as read</a></div>
                    </div>
                  </div>
                  <div class="scrollbar-overlay" style="max-height:19rem">
                    <div class="list-group list-group-flush fw-normal fs-10">
                      <div class="list-group-title border-bottom">NEW</div>
                      <div class="list-group-item">
                        <a class="notification notification-flush notification-unread" href="#!">
                          <div class="notification-avatar">
                            <div class="avatar avatar-2xl me-3">
                              <img class="rounded-circle" src="../assets/img/team/1-thumb.png" alt="" />

                            </div>
                          </div>
                          <div class="notification-body">
                            <p class="mb-1"><strong>Emma Watson</strong> replied to your comment : "Hello world 😍"</p>
                            <span class="notification-time"><span class="me-2" role="img" aria-label="Emoji">💬</span>Just now</span>

                          </div>
                        </a>

                      </div>


                    </div>
                  </div>
                  <div class="card-footer text-center border-top"><a class="card-link d-block" href="../app/social/notifications.html">View all</a></div>
                </div>
              </div>

            </li>
			-->
			
			
			<?php 
			//ADMIN MENU NAVIGATION ITEM
			 if($_SESSION['permission'] >= 5){ ?>
            <li class="nav-item dropdown px-1">
              <a class="nav-link fa-icon-wait nine-dots p-1" id="navbarDropdownMenu" role="button" data-hide-on-body-scroll="data-hide-on-body-scroll" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="43" viewBox="0 0 16 16" fill="none">
                  <circle cx="2" cy="2" r="2" fill="#6C6E71"></circle>
                  <circle cx="2" cy="8" r="2" fill="#6C6E71"></circle>
                  <circle cx="2" cy="14" r="2" fill="#6C6E71"></circle>
                  <circle cx="8" cy="8" r="2" fill="#6C6E71"></circle>
                  <circle cx="8" cy="14" r="2" fill="#6C6E71"></circle>
                  <circle cx="14" cy="8" r="2" fill="#6C6E71"></circle>
                  <circle cx="14" cy="14" r="2" fill="#6C6E71"></circle>
                  <circle cx="8" cy="2" r="2" fill="#6C6E71"></circle>
                  <circle cx="14" cy="2" r="2" fill="#6C6E71"></circle>
                </svg></a>
              <div class="dropdown-menu dropdown-caret dropdown-caret dropdown-menu-end dropdown-menu-card dropdown-caret-bg" aria-labelledby="navbarDropdownMenu">
                <div class="card shadow-none">
                  <div class="scrollbar-overlay nine-dots-dropdown">
                    <div class="card-body px-3">
                      <div class="row text-center gx-0 gy-0">
						<div class="col-4"><a class="d-block hover-bg-200 px-2 py-3 rounded-3 text-center text-decoration-none" href="/">
                            <div class="avatar avatar-2xl"> <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
  <path d="M3 9.5L12 3l9 6.5V21a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V9.5z"/>
</svg>

							</div>
                            <p class="mb-0 fw-medium text-800 text-truncate fs-11">Home</p> 
                          </a></div>
                         <div class="col-4"><a class="d-block hover-bg-200 px-2 py-3 rounded-3 text-center text-decoration-none" href="/profile">
                            <div class="avatar avatar-2xl"> <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
  <circle cx="12" cy="8" r="4"/>
  <path d="M4 20c0-4 4-6 8-6s8 2 8 6"/>
</svg>
</div>
                            <p class="mb-0 fw-medium text-800 text-truncate fs-11">Profile</p>
                          </a></div>
						<?php if($session->get_permission() > 5) { ?>
						 <div class="col-4"><a class="d-block hover-bg-200 px-2 py-3 rounded-3 text-center text-decoration-none" href="/admin/admin_users">
                            <div class="avatar avatar-2xl"> <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
  <path d="M3 4h18v4H3zM3 10h18v10H3z"/>
</svg>
</div>
                            <p class="mb-0 fw-medium text-800 text-truncate fs-11">Admin</p>
                          </a></div>
						 <div class="col-4"><a class="d-block hover-bg-200 px-2 py-3 rounded-3 text-center text-decoration-none" href="/admin/admin_settings">
                            <div class="avatar avatar-2xl"> <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round">
  <path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/>
  <path d="M19.4 15a1.7 1.7 0 0 0 .33 1.82l.05.05a2 2 0 1 1-2.82 2.83l-.06-.06a1.7 1.7 0 0 0-1.83-.33 1.7 1.7 0 0 0-1 1.51v.09a2 2 0 1 1-4 0v-.09a1.7 1.7 0 0 0-1-1.51 1.7 1.7 0 0 0-1.83.33l-.06.06a2 2 0 1 1-2.82-2.83l.05-.05a1.7 1.7 0 0 0 .33-1.82 1.7 1.7 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.7 1.7 0 0 0 1.51-1 1.7 1.7 0 0 0-.33-1.82l-.05-.05a2 2 0 1 1 2.82-2.83l.06.06a1.7 1.7 0 0 0 1.83.33h.09A1.7 1.7 0 0 0 9 3.09V3a2 2 0 1 1 4 0v.09a1.7 1.7 0 0 0 1 1.51h.09a1.7 1.7 0 0 0 1.83-.33l.06-.06a2 2 0 1 1 2.82 2.83l-.05.05a1.7 1.7 0 0 0-.33 1.82 1.7 1.7 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.51 1z"/>
</svg>


</div>
                            <p class="mb-0 fw-medium text-800 text-truncate fs-11">Settings</p>
                          </a></div>
						 <div class="col-4"><a class="d-block hover-bg-200 px-2 py-3 rounded-3 text-center text-decoration-none" href="/admin/admin_utilities" >
                            <div class="avatar avatar-2xl"> <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
  <path d="M3 3h6v6H3zM15 3h6v6h-6zM15 15h6v6h-6zM3 15h6v6H3z"/>
</svg>
</div>
                            <p class="mb-0 fw-medium text-800 text-truncate fs-11">Utilities</p>
                          </a></div>
						 <div class="col-4"><a class="d-block hover-bg-200 px-2 py-3 rounded-3 text-center text-decoration-none" href="/admin/admin_help" >
                            <div class="avatar avatar-2xl"> <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
  <circle cx="12" cy="12" r="10"/>
  <path d="M9.09 9a3 3 0 1 1 5.83 1c-.26 1.2-1.5 2-2.92 2v1"/>
  <circle cx="12" cy="17" r="1"/>
</svg>
</div>
                            <p class="mb-0 fw-medium text-800 text-truncate fs-11">Help</p>
                          </a></div>
                        <?php } ?>
                        <!--<div class="col-12"><a class="btn btn-outline-primary btn-sm mt-4" href="#!">Show more</a></div>-->
                      </div>
                    </div>
                  </div>
                </div>
              </div>

            </li>
			<?php } ?>
			
			
			<?php if(!$session->is_logged_in()){ ?>
			<ul class="navbar-nav" data-top-nav-dropdowns="data-top-nav-dropdowns"><li class="nav-item"><a class="nav-link" href="/login">Login</a></li></ul>
			
			<?php if($settings->get_setting('register_active')){ ?>
			<ul class="navbar-nav" data-top-nav-dropdowns="data-top-nav-dropdowns"><li class="nav-item"><a class="nav-link" href="/register">Register</a></li></ul>
			<?php } ?>
			<?php } ?>

			<?php if($session->is_logged_in()){ ?>
			<li class="nav-item dropdown"><a class="nav-link pe-0 ps-2" id="navbarDropdownUser" role="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <div class="avatar avatar-xl">
                  <img class="rounded-circle" src="<?php echo LibraryFunctions::get_theme_file_path('avatar.png', '/includes/img', 'web'); ?>" alt="" />

                </div>
              </a>
              <div class="dropdown-menu dropdown-caret dropdown-caret dropdown-menu-end py-0" aria-labelledby="navbarDropdownUser">
                <div class="bg-white dark__bg-1000 rounded-2 py-2">
                  

                  <!--<div class="dropdown-divider"></div>-->
                  <a class="dropdown-item" href="/profile">Profile</a>
                  <a class="dropdown-item" href="/logout">Logout</a>
                </div>
              </div>
            </li>
			<?php } 		
		
	}


	public function public_header($options=array()) {
		$_GLOBALS['page_header_loaded'] = true;
		$settings = Globalvars::get_instance();
		$session = SessionControl::get_instance();
		$options = parent::public_header_common($options);
	
		$profile_menu = array();

		
		$notification_menu = NULL;

		$menus = PublicPage::get_public_menu();
			

		?>

<!DOCTYPE html>
<html data-bs-theme="light" lang="en-US" dir="ltr">

  <head>
  
  		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<meta name="description" content="<?php echo $options['description']; ?>">
        <meta name="keywords" content="">

		<title><?php echo $options['title']; ?></title>
			<?php $this->global_includes_top($options); ?>

  


    <!-- ===============================================-->
    <!--    Favicons-->
    <!-- ===============================================-->
    <!--<link rel="apple-touch-icon" sizes="180x180" href="../assets/img/favicons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/img/favicons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="../assets/img/favicons/favicon-16x16.png">
    <link rel="shortcut icon" type="image/x-icon" href="../assets/img/favicons/favicon.ico">
    <link rel="manifest" href="../assets/img/favicons/manifest.json">
    <meta name="msapplication-TileImage" content="../assets/img/favicons/mstile-150x150.png">-->
    <meta name="theme-color" content="#ffffff">
    <!--<script src="../assets/js/config.js"></script>-->
    <!--<script src="../vendors/simplebar/simplebar.min.js"></script>-->
	<script src="<?php echo LibraryFunctions::get_theme_file_path('simplebar.min.js', '/includes/vendors/simplebar', 'web'); ?>"></script>


    <!-- ===============================================-->
    <!--    Stylesheets-->
    <!-- ===============================================-->
    <link rel="preconnect" href="https://fonts.gstatic.com">
    <link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,500,600,700%7cPoppins:300,400,500,600,700,800,900&amp;display=swap" rel="stylesheet">



    <!-- Jquery -->
	<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>	
	
	<link rel="stylesheet" type="text/css" id="stylesheet" href="<?php echo LibraryFunctions::get_theme_file_path('simplebar.min.css', '/includes/vendors/simplebar', 'web'); ?>">
	<link rel="stylesheet" type="text/css" id="style-rtl" href="<?php echo LibraryFunctions::get_theme_file_path('theme-rtl.css', '/includes/css', 'web'); ?>">
	<link rel="stylesheet" type="text/css" id="style-default" href="<?php echo LibraryFunctions::get_theme_file_path('theme.css', '/includes/css', 'web'); ?>">
	<link rel="stylesheet" type="text/css" id="user-style-rtl" href="<?php echo LibraryFunctions::get_theme_file_path('user-rtl.css', '/includes/css', 'web'); ?>">
	<link rel="stylesheet" type="text/css" id="user-style-default" href="<?php echo LibraryFunctions::get_theme_file_path('user.css', '/includes/css', 'web'); ?>">
	
	
	    <script>
      var isRTL = JSON.parse(localStorage.getItem('isRTL'));
      if (isRTL) {
        var linkDefault = document.getElementById('style-default');
        var userLinkDefault = document.getElementById('user-style-default');
        linkDefault.setAttribute('disabled', true);
        userLinkDefault.setAttribute('disabled', true);
        document.querySelector('html').setAttribute('dir', 'rtl');
      } else {
        var linkRTL = document.getElementById('style-rtl');
        var userLinkRTL = document.getElementById('user-style-rtl');
        linkRTL.setAttribute('disabled', true);
        userLinkRTL.setAttribute('disabled', true);
      }
    </script>
	<?php
	if($settings->get_setting('custom_css')){
		echo '<style>'.$settings->get_setting('custom_css').'</style>';
	}
	?>		


  </head>


  <body>

    <!-- ===============================================-->
    <!--    Main Content-->
    <!-- ===============================================-->
    <main class="main" id="top">
      <div class="container" data-layout="container-fluid">

        <nav class="navbar navbar-light navbar-glass navbar-top navbar-expand-lg">

          <button class="btn navbar-toggler-humburger-icon navbar-toggler me-1 me-sm-3" type="button" data-bs-toggle="collapse" data-bs-target="#navbarStandard" aria-controls="navbarStandard" aria-expanded="false" aria-label="Toggle Navigation"><span class="navbar-toggle-icon"><span class="toggle-line"></span></span></button>
            <a class="navbar-brand me-1 me-sm-3" href="/">
              <div class="d-flex align-items-center">
					  <?php 
							if($settings->get_setting('logo_link')){
								echo '<img class="me-2" src="'.$settings->get_setting('logo_link').'" alt="" width="40" />';
							}
					  ?>
					  <span class="font-sans-serif text-primary">
					  <?php 
							 echo $settings->get_setting('site_name'); 
					?>
					</span>
              </div>
            </a>
          <div class="collapse navbar-collapse scrollbar" id="navbarStandard">
            <ul class="navbar-nav" data-top-nav-dropdowns="data-top-nav-dropdowns">
 

			<?php
			foreach ($menus as $menu) {
				if ($menu['parent'] === true) {
					$submenus = $menu['submenu'];
					// If there are no submenu items, output a simple nav item.
					if (empty($submenus)) {
						echo '<li class="nav-item"><a class="nav-link" href="' . $menu['link'] . '">' . $menu['name'] . '</a></li>';
					} else {
						// Generate a safe ID based on the menu name (replace spaces with dashes and lowercase)
						$menuId = strtolower(preg_replace('/\s+/', '-', $menu['name']));
						echo '<li class="nav-item dropdown">';
						echo '  <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false" id="' . $menuId . '">' . $menu['name'] . '</a>';
						echo '  <div class="dropdown-menu dropdown-caret dropdown-menu-card border-0 mt-0" aria-labelledby="' . $menuId . '">';
						foreach ($submenus as $submenu) {
							echo '    <a class="dropdown-item link-600 fw-medium" href="' . $submenu['link'] . '">' . $submenu['name'] . '</a>';
						}
						echo '  </div>';
						echo '</li>';
					}
				}
			}
			?> 
              
              

            </ul>
          </div>
          <ul class="navbar-nav navbar-nav-icons ms-auto flex-row">
		  
		  
			<?php $this->top_right_menu(); ?>
			

          </ul>
        </nav>		
		
		
		<div class="content">
          
          
          
   


<?php /* ?>


		
		
	
	<?php	
	
	if(empty($options['noheader'])){
		?>	
	<body class="h-full">
<div class="min-h-full">

<!-- This example requires Tailwind CSS v2.0+ -->
<div class="bg-gray-50">
  <div class="relative bg-white z-10">
    <div class="max-w-7xl mx-auto px-4 sm:px-6">
      <div class="flex justify-between items-center py-6 md:justify-start md:space-x-10">
        <div class="flex justify-start lg:w-0 lg:flex-1">
          <a href="#">
            <!--<span class="sr-only">Workflow</span>-->
			<?php 
			echo '<span class="sr-only">'.$settings->get_setting('site_name').'</span>';
			if($settings->get_setting('logo_link')){
				echo '<a href="/"><img style="max-height: 100px;" src="'.$settings->get_setting('logo_link').'" alt=""></a>';
			}
			else{
				echo '<h3><a href="/">'.$settings->get_setting('site_name').'</a></h3>';
			}
			?>
            <!--<img class="h-8 w-auto sm:h-10" src="https://tailwindui.com/img/logos/workflow-mark-blue-600.svg" alt="">-->
          </a>
        </div>
        <div class="-mr-2 -my-2 md:hidden">
          <button id="mobile-toggle-button" type="button" class="bg-white rounded-md p-2 inline-flex items-center justify-center text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500" aria-expanded="false">
            <span class="sr-only">Open menu</span>
            <!-- Heroicon name: outline/menu -->
            <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
          </button>
        </div>
        <nav class="hidden md:flex space-x-10">
		<?php
			foreach ($menus as $menu){
				if($menu['parent'] == true){
					$submenus = $menu['submenu'];
					
					if(empty($submenus)){	
						echo '          <a href="'.$menu['link'].'" class="text-base font-medium text-gray-500 hover:text-gray-900">'.$menu['name'].'</a>';
					}
					else{	
						?>
					  <div class="relative">
						<!-- Item active: "text-gray-900", Item inactive: "text-gray-500" -->
						<button type="button" class="js-clickable-menu text-gray-500 group bg-white rounded-md inline-flex items-center text-base font-medium hover:text-gray-900 " aria-expanded="false">
						  <span><?php echo $menu['name']; ?></span>
						  <!--
							Heroicon name: solid/chevron-down

							Item active: "text-gray-600", Item inactive: "text-gray-400"
						  -->
						  <svg class="text-gray-400 ml-2 h-5 w-5 group-hover:text-gray-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
							<path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
						  </svg>
						</button>
						<!--
						  'Solutions' flyout menu, show/hide based on flyout menu state.

						  Entering: "transition ease-out duration-200"
							From: "opacity-0 translate-y-1"
							To: "opacity-100 translate-y-0"
						  Leaving: "transition ease-in duration-150"
							From: "opacity-100 translate-y-0"
							To: "opacity-0 translate-y-1"
						-->
						<div class="js-clicked-menu invisible absolute -ml-4 mt-3 transform z-10 px-2 w-screen max-w-md sm:px-0 lg:ml-0 lg:left-1/2 lg:-translate-x-1/2">
						  <div class="rounded-lg shadow-lg ring-1 ring-black ring-opacity-5 overflow-hidden">
							<div class="relative grid gap-6 bg-white px-5 py-6 sm:gap-8 sm:p-8">
							<?php foreach ($submenus as $submenu){ ?>
							
								<a href="<?php echo $submenu['link']; ?>" class="-m-3 p-3 flex items-start rounded-lg hover:bg-gray-50">
									<!-- Heroicon name: outline/chart-bar -->
									<!--<svg class="flex-shrink-0 h-6 w-6 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
									  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
									</svg>-->
									<div class="ml-4">
									  <p class="text-base font-medium text-gray-900">
										<?php echo $submenu['name']; ?>
									  </p>
									  <!--<p class="mt-1 text-sm text-gray-500">
										Get a better understanding of where your traffic is coming from.
									  </p>-->
									</div>
								  </a>			
							<?php }
						echo '</div></div></div></div>';
						
					}
				}
			}		
		?>
        </nav>





        <div class="hidden md:flex items-center justify-end space-x-8 md:flex-1 lg:w-0">
		
			<!--
			<a href="#" class="text-sm font-medium text-gray-900 hover:underline">
				Go Premium
			</a>
			-->
			
			<?php if(!empty($notification_menu)){ ?>
			<a href="#" class="ml-5 flex-shrink-0 bg-white rounded-full p-1 text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-rose-500">
            <span class="sr-only">View notifications</span>
            <!-- Heroicon name: outline/bell -->
            <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
            </svg>
			</a>
			<?php } ?>

			<?php if(!empty($cart_menu)){ ?>
			<a href="<?php echo $cart_menu['Cart']; ?>" class="ml-5 flex-shrink-0 bg-white rounded-full p-1 text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-rose-500">
            <span class="sr-only">Cart</span>
            <!-- Heroicon name: outline/bell -->
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
			  <path d="M3 1a1 1 0 000 2h1.22l.305 1.222a.997.997 0 00.01.042l1.358 5.43-.893.892C3.74 11.846 4.632 14 6.414 14H15a1 1 0 000-2H6.414l1-1H14a1 1 0 00.894-.553l3-6A1 1 0 0017 3H6.28l-.31-1.243A1 1 0 005 1H3zM16 16.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM6.5 18a1.5 1.5 0 100-3 1.5 1.5 0 000 3z" />
			</svg>
			</a>
			<?php } ?>

			<?php if(!empty($profile_menu)){ ?>
			  <!-- Profile dropdown -->
			<div class="flex-shrink-0 relative ml-5">
				<div>
				  <button type="button" class="bg-white rounded-full flex focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-rose-500" id="user-menu-button" aria-expanded="false" aria-haspopup="true">
					<span class="sr-only">Open user menu</span>
					<svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 rounded-full" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
					</svg>
					<!--<img class="h-8 w-8 rounded-full" src="https://images.unsplash.com/photo-1550525811-e5869dd03032?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80" alt="">-->
				  </button>
				</div>

				<!--
				  Dropdown menu, show/hide based on menu state.

				  Entering: "transition ease-out duration-100"
					From: "transform opacity-0 scale-95"
					To: "transform opacity-100 scale-100"
				  Leaving: "transition ease-in duration-75"
					From: "transform opacity-100 scale-100"
					To: "transform opacity-0 scale-95"
				-->
				<div id="user-menu" class="js-clicked-menu invisible origin-top-right absolute z-10 right-0 mt-2 w-48 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 py-1 focus:outline-none" role="menu" aria-orientation="vertical" aria-labelledby="user-menu-button" tabindex="-1">
				  <!-- Active: "bg-gray-100", Not Active: "" -->
				  <?php
				  foreach($profile_menu as $name=>$link){
					  echo '<a href="'.$link.'" class="block py-2 px-4 text-sm text-gray-700" role="menuitem" tabindex="-1" id="user-menu-item-0">'.$name.'</a>';
				  }
				  ?>
				  <!--
				  <a href="#" class="block py-2 px-4 text-sm text-gray-700" role="menuitem" tabindex="-1" id="user-menu-item-0">Your Profile</a>

				  <a href="#" class="block py-2 px-4 text-sm text-gray-700" role="menuitem" tabindex="-1" id="user-menu-item-1">Settings</a>

				  <a href="#" class="block py-2 px-4 text-sm text-gray-700" role="menuitem" tabindex="-1" id="user-menu-item-2">Sign out</a>
				  -->
				</div>
			</div>
			<?php } ?>
      
			<!--
			<a href="#" class="whitespace-nowrap bg-blue-100 border border-transparent rounded-md py-2 px-4 inline-flex items-center justify-center text-base font-medium text-blue-700 hover:bg-blue-200">
				New Post
			</a>-->
		
		<?php if(!empty($logged_out_menu)){ ?>
          <a href="/login" class="whitespace-nowrap text-base font-medium text-gray-500 hover:text-gray-900">
            Sign in
          </a>
		  <?php
		  if($settings->get_setting('register_active')){
			?>
          <a href="/register" class="whitespace-nowrap bg-blue-100 border border-transparent rounded-md py-2 px-4 inline-flex items-center justify-center text-base font-medium text-blue-700 hover:bg-blue-200">
            Sign up
          </a>
		  <?php } ?>
		<?php } ?>
        </div>
      </div>
    </div>

    <!--
      Mobile menu, show/hide based on mobile menu state.

      Entering: "duration-200 ease-out"
        From: "opacity-0 scale-95"
        To: "opacity-100 scale-100"
      Leaving: "duration-100 ease-in"
        From: "opacity-100 scale-100"
        To: "opacity-0 scale-95"
    -->
    <div  id="mobile-menu" class="invisible absolute top-0 inset-x-0 p-2 transition transform origin-top-right md:hidden">
      <div class="rounded-lg shadow-lg ring-1 ring-black ring-opacity-5 bg-white divide-y-2 divide-gray-50">
        <div class="pt-5 pb-6 px-5">
          <div class="flex items-center justify-between">
            <div>
				<?php 
				echo '<span class="sr-only">'.$settings->get_setting('site_name').'</span>';
				if($settings->get_setting('logo_link')){
					echo '<a href="/"><img class="h-8 w-auto" src="'.$settings->get_setting('logo_link').'" alt=""></a>';
				}
				else{
					echo '<h3><a href="/">'.$settings->get_setting('site_name').'</a></h3>';
				}
				?>
              <!--<img class="h-8 w-auto" src="https://tailwindui.com/img/logos/workflow-mark-blue-600.svg" alt="Workflow">-->
            </div>
            <div class="-mr-2">
              <button id="mobile-close-button" type="button" class="bg-white rounded-md p-2 inline-flex items-center justify-center text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500">
                <span class="sr-only">Close menu</span>
                <!-- Heroicon name: outline/x -->
                <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
          </div>
          <div class="mt-6">
            <nav class="grid gap-y-8">
			
		<?php
			foreach ($menus as $menu){
				if($menu['parent'] == true){
					$submenus = $menu['submenu'];
					
					if(empty($submenus)){
						?>
					  <a href="<?php echo $menu['link']; ?>" class="-m-3 p-3 flex items-center rounded-md hover:bg-gray-50">
						<!-- Heroicon name: outline/chart-bar -->
						<svg class="flex-shrink-0 h-6 w-6 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
						  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
						</svg>
						<span class="ml-3 text-base font-medium text-gray-900">
						  <?php echo $menu['name']; ?>
						</span>
					  </a>
					  <?php
					}
					else{	
						?>
					  <a href="<?php echo $menu['link']; ?>" class="-m-3 p-3 flex items-center rounded-md hover:bg-gray-50">
						<!-- Heroicon name: outline/chart-bar -->
						<svg class="flex-shrink-0 h-6 w-6 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
						  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
						</svg>
						<span class="ml-3 text-base font-medium text-gray-900">
						  <?php echo $menu['name']; ?>
						</span>
					  </a>
					  
						<div class="py-6 px-5 space-y-6">
							<div class="grid grid-cols-2 gap-y-4 gap-x-8">
								<?php 
								foreach ($submenus as $submenu){ 
									echo '<a href="'.$submenu['link'].'" class="text-base font-medium text-gray-900 hover:text-gray-700">'.$submenu['name'].'</a>'; 
								}
							echo '</div>
						</div>';
					}
				}
			}		
			?>			
			
			
            </nav>
          </div>
        </div>
        <div class="py-6 px-5 space-y-6">
          <div class="grid grid-cols-2 gap-y-4 gap-x-8">
			<?php 
			if(!empty($profile_menu)){
				foreach($profile_menu as $name=>$link){
					echo ' <a href="'.$link.'" class="text-base font-medium text-gray-900 hover:text-gray-700">'.$name.'</a>';
				}
			}
			?>

			<?php 
			if(!empty($cart_menu)){
				echo ' <a href="/cart" class="text-base font-medium text-gray-900 hover:text-gray-700">Cart</a>';
			}
			?>

			<?php 
			if(!empty($notification_menu)){
				echo ' <a href="#" class="text-base font-medium text-gray-900 hover:text-gray-700">Notifications</a>';
			}
			?>





			
          </div>
		  <?php if(!empty($logged_out_menu)){ ?>

          <div>
			<?php if($settings->get_setting('register_active')){ ?>
            <a href="/register" class="w-full flex items-center justify-center px-4 py-2 border border-transparent rounded-md shadow-sm text-base font-medium text-white bg-blue-600 hover:bg-blue-700">
              Sign up
            </a>
			<?php } ?>
            <p class="mt-6 text-center text-base font-medium text-gray-500">
              Existing user?
              <a href="/login" class="text-blue-600 hover:text-blue-500">
                Sign in
              </a>
            </p>
          </div>
		  <?php } ?>
        </div>
      </div>
    </div>
  </div>
















		
	<?php } //end if noheader ?>

		
	
		<?php
		*/
	}

	public function public_footer($options=array()) {
		$session = SessionControl::get_instance();
		$session->clear_clearable_messages();
		$settings = Globalvars::get_instance();
	
      ?>
         

          <footer class="footer">
            <div class="row g-0 justify-content-between fs-10 mt-4 mb-3">
              <div class="col-12 col-sm-auto text-center">
                <p class="mb-0 text-600">Thank you for creating with Falcon <span class="d-none d-sm-inline-block">| </span><br class="d-sm-none" /> 2024 &copy; <a href="https://themewagon.com">Themewagon</a></p>
              </div>
              <div class="col-12 col-sm-auto text-center">
                <p class="mb-0 text-600">v3.23.0</p>
              </div>
            </div>
          </footer>
        </div>
        
      </div>
    </main>
    <!-- ===============================================-->
    <!--    End of Main Content-->
    <!-- ===============================================-->


    


    <!-- ===============================================-->
    <!--    JavaScripts-->
    <!-- ===============================================-->
	<!--<script type="text/javascript" src="<?php echo LibraryFunctions::get_theme_file_path('jquery.validate-1.9.1.js', '/includes', 'web'); ?>"></script>	-->
	<script src="https://cdn.jsdelivr.net/npm/jquery-validation@1.21.0/dist/jquery.validate.min.js"></script>
	<script src="<?php echo LibraryFunctions::get_theme_file_path('popper.min.js', '/includes/vendors/popper', 'web'); ?>"></script>
	<script src="<?php echo LibraryFunctions::get_theme_file_path('bootstrap.min.js', '/includes/vendors/bootstrap', 'web'); ?>"></script>
	<script src="<?php echo LibraryFunctions::get_theme_file_path('anchor.min.js', '/includes/vendors/anchorjs', 'web'); ?>"></script>
	<script src="<?php echo LibraryFunctions::get_theme_file_path('is.min.js', '/includes/vendors/is', 'web'); ?>"></script>
    <script src="<?php echo LibraryFunctions::get_theme_file_path('all.min.js', '/includes/vendors/fontawesome', 'web'); ?>"></script>
	<script src="<?php echo LibraryFunctions::get_theme_file_path('lodash.min.js', '/includes/vendors/lodash', 'web'); ?>"></script>
	<script src="<?php echo LibraryFunctions::get_theme_file_path('list.min.js', '/includes/vendors/list.js', 'web'); ?>"></script>
	<script src="<?php echo LibraryFunctions::get_theme_file_path('theme.js', '/includes/js', 'web'); ?>"></script>
	


  </body>
</html>



		<?php
	}
	
	
	function dropdown_or_buttons($options){

		if(!$options['options_label']){
			$options['options_label'] = 'Options';
		}		
	

	
		if(is_array($options['altlinks'])){
			echo '<div class="row justify-content-end justify-content-end gx-3 gy-0 px-3"><div class="col-sm-auto">';
			if(count($options['altlinks']) > 2){
				echo '<div class="dropdown font-sans-serif d-inline-block mb-2"><button class="btn btn-falcon-default dropdown-toggle" id="dropdownMenuButton" type="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">'.$options['options_label'].'</button><div class="dropdown-menu dropdown-menu-end py-0" aria-labelledby="dropdownMenuButton">';
				foreach($options['altlinks'] as $label=>$link){
					echo '<a href="'.$link.'" class="dropdown-item">'.$label.'</a></li>';
				}	
				echo '</div></div>';	    
									
			}
			else if(count($options['altlinks']) > 0){
				
				foreach($options['altlinks'] as $label=>$link){
					echo '<a href="'.$link.'"><button class="btn btn-outline-secondary me-1 mb-1" type="button">'.$label.'</button></a>';
				}
				
			}
			echo '</div></div>';
		}			
	}
	
	
	
	function begin_box($options=NULL){
	
	echo '<div id="tableExample4">';
	
				$this->dropdown_or_buttons($options);
	
	
	}
	
	function end_box(){

	echo '</div>';
	}
	

	function tableheader($headers, $options=NULL, $pager=NULL){
		
		$this->begin_box($options);
		
		if(!$pager){
			$pager = new Pager();
		}

		$sortoptions= $options['sortoptions'];
		$filteroptions= $options['filteroptions'];
		$search_on = $options['search_on'];

		echo '<div class="row justify-content-end justify-content-end gx-3 gy-0 px-3">';

		if($sortoptions){
			echo '<div class="col-sm-auto">';
			printf('<form method="get" ACTION="%s">', $pager->base_url());
			echo $pager->url_vars_as_hidden_input(array('sort', 'sdirection'));
			echo '<label for="'.$pager->prefix().'sort'.'">Sort: </label><select name="'.$pager->prefix().'sort'.'">';
			foreach ($sortoptions as $key => $value) {
				if($pager->get_sort() == $value){
					echo "<option value='$value' selected=selected>$key";
				}
				else{
					echo "<option value='$value'>$key";
				}
			}
			echo '</select>';
			

			echo '<label for="'.$pager->prefix().'sdirection'.'"> </label><select name="'.$pager->prefix().'sdirection'.'">';
			$diroptions = array('Descending'=>'DESC', 'Ascending'=>'ASC');
			foreach ($diroptions as $key => $value) {
				if($pager->sort_direction() == $value){
					echo "<option value='$value' selected=selected>$key";
				}
				else{
					echo "<option value='$value'>$key";
				}
			}
			echo '</select>';

						
			foreach($pager->url_vars() as $key=>$value){
				echo '<input type="hidden" name="'.$key.'" value="'.$value.'">';
			}


			echo '<input type="submit" value="sort" /></form>'; 
			echo '</div>';

		}


		if($filteroptions){
			echo '<div class="col-sm-auto">';
			printf('<form method="get" ACTION="%s">', $pager->base_url());
			echo $pager->url_vars_as_hidden_input(array('filter'));
			echo '<label for="'.$pager->prefix().'filter'.'">Show: </label><select name="'.$pager->prefix().'filter'.'">';
			foreach ($filteroptions as $key => $value) {
				if($pager->get_filter() == $value){
					echo "<option value='$value' selected=selected>$key";
				}
				else{
					echo "<option value='$value'>$key";
				}
			}
			echo '</select>';

						
			foreach($pager->url_vars() as $key=>$value){
				echo '<input type="hidden" name="'.$key.'" value="'.$value.'">';
			}


			echo '<input type="submit" value="submit" /></form>'; 
			echo '</div>';
		}

		if($search_on){
			

			echo '<div class="col-sm-auto">';
			$formwriter = LibraryFunctions::get_formwriter_object('search_form', 'admin');

			echo $formwriter->begin_form("search_form", "get", $pager->base_url());
			echo $pager->url_vars_as_hidden_input(array('searchterm'));
			echo '<label for="searchterm">Search: </label>
						  <input name="'.$pager->prefix().'searchterm" id="'.$pager->prefix().'searchterm" value="'.$pager->search_term().'" size="20" type="text" class="textInput" maxlength="">';	
			
			foreach($pager->url_vars() as $key=>$value){
				echo '<input type="hidden" name="'.$key.'" value="'.$value.'">';
			}	

	
			//echo $formwriter->textinput("Search (personal name, user ID, phone number, dharma name, or email)", "searchterm", '', 20, $searchterm, NULL, NULL);
			echo '<input type="submit" value="Search" />';
			echo $formwriter->end_form();
			echo '</div>';
		}

		echo '</div>';


		echo '<div class="table-responsive scrollbar">
  <table class="table">

			<tr>';

			foreach ($headers as $value) {
				echo '<th>'.$value.'</th>';
			}

		echo '</tr>';

    }


	function disprow($dataarray){
		echo '<tr>';

		foreach ($dataarray as $value) {
			if($value == ""){
				$value = "&nbsp";
			}

			printf('<td>%s</td>', $value);
		}
		echo "</tr>\n";
	}


	function endtable($pager=NULL){
		
		if(!$pager){
			$pager = new Pager();
		}
		echo '</table></div>';

		
		//PAGE
		if($pager->num_records()){	
			echo '<div class="row  gx-3 gy-0 px-3" id="example1_info" role="status" aria-live="polite"> '.$pager->num_records().' records, Page '.$pager->current_page() .' of '.$pager->total_pages().'</div>';
		}
	
		
		if($pager->num_records() > $pager->num_per_page()){
			echo '<div class="row justify-content-end justify-content-end gx-3 gy-0 px-3">';
			if($page_number = $pager->is_valid_page('-10')){
				echo '<a class="previous" href="'.$pager->get_url($page_number).'"><< Back 10&nbsp; </a>';
			}
			else{
				echo '<span><< Back 10&nbsp; </span>';
			}
							

			for($x=4; $x>=1;$x--){
				if($page_number = $pager->is_valid_page('-'.$x)){
					echo '<a href="'.$pager->get_url($page_number).'">'.$page_number.'</a> ';
				}
			}
			
			echo '<strong>'.$pager->current_page().'</strong> ';
			
			for($x=1; $x<=4;$x++){
				if($page_number = $pager->is_valid_page('+'.$x)){
					echo '<a href="'.$pager->get_url($page_number).'">'.$page_number.'</a> ';
				}
			}	

		
			if($page_number = $pager->is_valid_page('+10')){
				echo '<a class="previous" href="'.$pager->get_url($page_number).'"> &nbsp;Ahead 10 >></a>';
			}
			else{
				echo '<span> &nbsp;Ahead 10 >></span>';
			}
			echo '</div>';
		}
		

		
		$this->end_box();

	}	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	/*
	static function alert($title, $content, $type){
		if($type == 'error'){
			$output = '<div class="rounded-md bg-red-50 p-4">
			  <div class="flex">
				<div class="flex-shrink-0">
				  <!-- Heroicon name: solid/x-circle -->
				  <svg class="h-5 w-5 text-red-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
					<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
				  </svg>
				</div>
				<div class="ml-3">
				  <h3 class="text-sm font-medium text-red-800">'.$title.'</h3>
				  <div class="mt-2 text-sm text-red-700">
					'.$content.'
				  </div>
				</div>
			  </div>
			</div>';
		}
		else if($type == 'warn'){
			$output = '<div class="rounded-md bg-yellow-50 p-4">
			  <div class="flex">
				<div class="flex-shrink-0">
				  <!-- Heroicon name: solid/exclamation -->
				  <svg class="h-5 w-5 text-yellow-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
					<path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
				  </svg>
				</div>
				<div class="ml-3">
				  <h3 class="text-sm font-medium text-yellow-800">'.$title.'</h3>
				  <div class="mt-2 text-sm text-yellow-700">
					<p>'.$content.'</p>
				  </div>
				</div>
			  </div>
			</div>';	
		}
		else if($type == 'success'){
			$output = '<div class="rounded-md bg-green-50 p-4">
			  <div class="flex">
				<div class="flex-shrink-0">
				  <!-- Heroicon name: solid/check-circle -->
				  <svg class="h-5 w-5 text-green-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
					<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
				  </svg>
				</div>
				<div class="ml-3">
				  <h3 class="text-sm font-medium text-green-800">'.$title.'</h3>
				  <div class="mt-2 text-sm text-green-700">
					<p>'.$content.'</p>
				  </div>
				  <!--
				  <div class="mt-4">
					<div class="-mx-2 -my-1.5 flex">
					  <button type="button" class="bg-green-50 px-2 py-1.5 rounded-md text-sm font-medium text-green-800 hover:bg-green-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-green-50 focus:ring-green-600">View status</button>
					  <button type="button" class="ml-3 bg-green-50 px-2 py-1.5 rounded-md text-sm font-medium text-green-800 hover:bg-green-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-green-50 focus:ring-green-600">Dismiss</button>
					</div>
				  </div>-->
				</div>
			  </div>
			</div>';
		}
		
		return $output;
	}


	static function dropdown_button($id, $options){ 

		if (!count($options)){
			return(false);
		}
		
		$output = '
		<!--
		<div class="flex items-center">
		  <div class="flex items-center rounded-md shadow-sm md:items-stretch">
			<button type="button" class="flex items-center justify-center rounded-l-md border border-r-0 border-gray-300 bg-white py-2 pl-3 pr-4 text-gray-400 hover:text-gray-500 focus:relative md:w-9 md:px-2 md:hover:bg-gray-50">
			  <span class="sr-only">Previous month</span>
			 
			  <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
				<path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
			  </svg>
			</button>
			<button type="button" class="hidden border-t border-b border-gray-300 bg-white px-3.5 text-sm font-medium text-gray-700 hover:bg-gray-50 hover:text-gray-900 focus:relative md:block">Today</button>
			<span class="relative -mx-px h-5 w-px bg-gray-300 md:hidden"></span>
			<button type="button" class="flex items-center justify-center rounded-r-md border border-l-0 border-gray-300 bg-white py-2 pl-4 pr-3 text-gray-400 hover:text-gray-500 focus:relative md:w-9 md:px-2 md:hover:bg-gray-50">
			  <span class="sr-only">Next month</span>
			  
			  <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
				<path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
			  </svg>
			</button>
		  </div>
		  -->
		  <div class="hidden md:ml-4 md:flex md:items-center">
			<div class="relative">
			  <button type="button" id="'.$id.'_button" class="flex items-center rounded-md border border-gray-300 bg-white py-2 pl-3 pr-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50" aria-expanded="false" aria-haspopup="true">
				Actions
				<!-- Heroicon name: solid/chevron-down -->
				<svg class="ml-2 h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
				  <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
				</svg>
			  </button>

			  <!--
				Dropdown menu, show/hide based on menu state.

				Entering: "transition ease-out duration-100"
				  From: "transform opacity-0 scale-95"
				  To: "transform opacity-100 scale-100"
				Leaving: "transition ease-in duration-75"
				  From: "transform opacity-100 scale-100"
				  To: "transform opacity-0 scale-95"
			  -->
			  <div id="'.$id.'" class="focus:outline-none absolute right-0 mt-3 w-36 origin-top-right overflow-hidden rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 invisible" role="menu" aria-orientation="vertical" aria-labelledby="menu-button" tabindex="-1">
				<div class="py-1" role="none">
				  <!-- Active: "bg-gray-100 text-gray-900", Not Active: "text-gray-700" -->';
					foreach ($options as $label=>$link){
						$output .= '<a href="'.$link.'" class="text-gray-700 block px-4 py-2 text-sm" role="menuitem" tabindex="-1" id="menu-item-0">'.$label.'</a>';
					}			  
				  $output .='
				</div>
			  </div>
			</div>
			<!--
			<div class="ml-6 h-6 w-px bg-gray-300"></div>
			<button type="button" class="focus:outline-none ml-6 rounded-md border border-transparent bg-indigo-600 py-2 px-4 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">Add event</button>-->
		  </div>
		  <div class="relative ml-6 md:hidden">
			<button type="button" class="-mx-2 flex items-center rounded-full border border-transparent p-2 text-gray-400 hover:text-gray-500" id="'.$id.'_button_mobile" aria-expanded="false" aria-haspopup="true">
			  <span class="sr-only">Open menu</span>
			  <!-- Heroicon name: solid/dots-horizontal -->
			  <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
				<path d="M6 10a2 2 0 11-4 0 2 2 0 014 0zM12 10a2 2 0 11-4 0 2 2 0 014 0zM16 12a2 2 0 100-4 2 2 0 000 4z" />
			  </svg>
			</button>

			<!--
			  Dropdown menu, show/hide based on menu state.

			  Entering: "transition ease-out duration-100"
				From: "transform opacity-0 scale-95"
				To: "transform opacity-100 scale-100"
			  Leaving: "transition ease-in duration-75"
				From: "transform opacity-100 scale-100"
				To: "transform opacity-0 scale-95"
			-->
			<div id="'.$id.'_mobile" class="focus:outline-none absolute right-0 mt-3 w-36 origin-top-right divide-y divide-gray-100 overflow-hidden rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 invisible" role="menu" aria-orientation="vertical" aria-labelledby="menu-0-button" tabindex="-1">
			<!-- Active: "bg-gray-100 text-gray-900", Not Active: "text-gray-700" -->
			  <!--
			  <div class="py-1" role="none">
				<a href="#" class="text-gray-700 block px-4 py-2 text-sm" role="menuitem" tabindex="-1" id="menu-0-item-0">Create event</a>
			  </div>
			  <div class="py-1" role="none">
				<a href="#" class="text-gray-700 block px-4 py-2 text-sm" role="menuitem" tabindex="-1" id="menu-0-item-1">Go to today</a>
			  </div>
			  -->
			  <div class="py-1" role="none">';
					foreach ($options as $label=>$link){
						$output .= '<a href="'.$link.'" class="text-gray-700 block px-4 py-2 text-sm" role="menuitem" tabindex="-1" id="menu-item-0">'.$label.'</a>';
					}			  
				  $output .='
			  </div>
			</div>
		  </div>
		</div>

	 
	 <script language="javascript">
		 $(document).ready(function() {	
			
			$("#'.$id.'_button").click(function() { 
			 $("#'.$id.'").toggleClass("invisible");
			 event.stopPropagation();
			});	
		
			
			$("#'.$id.'_button_mobile").click(function() { 
			 $("#'.$id.'_mobile").toggleClass("invisible");
			 event.stopPropagation();
			});	
		
			$("html").click(function() {
				$("#'.$id.'").addClass("invisible");
				$("#'.$id.'_mobile").addClass("invisible");
			});	

		});
		</script>';		

		return $output;
	}


	static function tab_menu($tab_menus, $type=NULL){
		$output = '';
		$output .= '
		<script language="javascript">
			 $(document).ready(function() {	
				$(\'#tab_select\').change(function() {';

					foreach($tab_menus as $name => $link){
						$output .= '
						if($(\'#tab_select\').val() == "'.$name.'"){
							$(location).attr("href","'.$link.'");
						}
						';
					}
			$output .= '
				});	
			});
			</script>	
		<div class="mb-6">
		  <div class="sm:hidden">
			<label for="tabs" class="sr-only">Categories</label>
			<!-- Use an "onChange" listener to redirect the user to the selected tab URL. -->
			<select id="tab_select" name="tab_select" class="block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">';

					foreach($tab_menus as $name => $link){
						if($name == $_REQUEST['menu_item']){
						  $output .= '<option selected>'.$name.'</option>';					
						}
						else{
						  $output .= '<option>'.$name.'</option>';						
						}
					}

			$output .= '
			</select>
		  </div>
		  <div class="hidden sm:block">
			<div class="border-b border-gray-200">
			  <nav class="-mb-px flex space-x-8" aria-label="Tabs">
				<!-- Current: "border-indigo-500 text-indigo-600", Default: "border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300" -->';

					foreach($tab_menus as $name => $link){
						if($name == $_REQUEST['menu_item']){
						  $output .= '<a class="border-indigo-500 text-indigo-600 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm" aria-current="page" href="'.$link.'">'.$name.'</a>';					
						}
						else{
						  $output .= '<a class="border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm" href="'.$link.'">'.$name.'</a>';						
						}
					}
				$output .= '
			  </nav>
			</div>
		  </div>
		</div>';
		
		return $output;
		
	}


	*/
}

?>
