<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/PublicPageFalcon.php');
require_once($siteDir . '/includes/Pager.php');
require_once($siteDir . '/data/admin_menus_class.php');

class AdminPage extends PublicPageFalcon {






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


	public function admin_header($options=array()) {
		$_GLOBALS['page_header_loaded'] = true;
		$settings = Globalvars::get_instance();
		$session = SessionControl::get_instance();
		
		if($session && $session->get_user_id()){
			$user = new User($session->get_user_id(), TRUE);
			$user_name = $user->display_name();
		}
		else{
			$user = new User(NULL);
		}	
	
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
				$logged_out_menu['Sign up'] = '/register';	
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
		$maintitle = $settings->get_setting('site_name');
		$logo = $settings->get_setting('logo_link');
		$thisfile = basename($_SERVER['PHP_SELF']);

			

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
      var isRTL = false;

        var linkRTL = document.getElementById('style-rtl');
        var userLinkRTL = document.getElementById('user-style-rtl');
        linkRTL.setAttribute('disabled', true);
        userLinkRTL.setAttribute('disabled', true);
      
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
	
		
		
		
                <nav class="navbar navbar-light navbar-vertical navbar-expand-xl">
          
          <div class="d-flex align-items-center">
            <div class="toggle-icon-wrapper">

              <button class="btn navbar-toggler-humburger-icon navbar-vertical-toggle" data-bs-toggle="tooltip" data-bs-placement="left" title="Toggle Navigation"><span class="navbar-toggle-icon"><span class="toggle-line"></span></span></button>

            </div>
			
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
          </div>
          <div class="collapse navbar-collapse" id="navbarVerticalCollapse">
            <div class="navbar-vertical-content scrollbar">
              <ul class="navbar-nav flex-column mb-3" id="navbarVerticalNav">
                
                
                <li class="nav-item">
                  <!-- label-->
                  <div class="row navbar-vertical-label-wrapper mt-3 mb-2">
                    <div class="col-auto navbar-vertical-label">Pages
                    </div>
                    <div class="col ps-0">
                      <hr class="mb-0 navbar-vertical-divider" />
                    </div>
                  </div>
 

			<?php 		
			
				$admin_menu = MultiAdminMenu::getadminmenu($user->get('usr_permission'), $pagevars['menu-id']); 
				$iterate_menu = $admin_menu;
				foreach ($admin_menu as $menu_id=>$menu_info){	
					if(!$menu_info['parent']){
						if($menu_info['currentmain']){
							if($menu_info['has_subs']){
								echo '<!-- parent pages--><a class="nav-link dropdown-indicator" href="#'.str_replace(' ', '_', $menu_info['display']).'" role="button" data-bs-toggle="collapse" aria-expanded="false" aria-controls="'.str_replace(' ', '_', $menu_info['display']).'">
									<div class="d-flex align-items-center"><span class="nav-link-icon"><span class="fas fa-'.$menu_info['icon'].'"></span></span><span class="nav-link-text ps-1">'.$menu_info['display'].'</span>
									</div>
								  </a>';
								echo '<ul class="nav collapse show" id="'.str_replace(' ', '_', $menu_info['display']).'">';
								foreach ($iterate_menu as $iterate_menu_id=>$iterate_menu_info){
									if($iterate_menu_info['parent'] == $menu_id){
										if($iterate_menu_info['currentsub']){
											echo '<li class="nav-item"><a class="nav-link active" title="'.$iterate_menu_info['display'].'" href="'.$iterate_menu_info['defaultpage'].'"><div class="d-flex align-items-center"><span class="nav-link-text ps-1">'.$iterate_menu_info['display'].'</span>
											</div></a></li>';
										}
										else{
											echo '<li class="nav-item"><a class="nav-link" title="'.$iterate_menu_info['display'].'" href="'.$iterate_menu_info['defaultpage'].'"><div class="d-flex align-items-center"><span class="nav-link-text ps-1">'.$iterate_menu_info['display'].'</span>
											</div></a></li>';								
										}
									}
								}
								echo '</ul>';
							}
							else{
									echo '<!-- parent pages--><a class="nav-link " href="'.$menu_info['defaultpage'].'" role="button" data-bs-toggle="collapse" aria-expanded="false" aria-controls="'.$menu_info['display'].'">
									<div class="d-flex align-items-center"><span class="nav-link-icon"><span class="fas fa-'.$menu_info['icon'].'"></span></span><span class="nav-link-text ps-1">'.$menu_info['display'].'</span>
									</div>
								  </a>';	
							}
						}
						else{
							if($menu_info['has_subs']){
								echo '<!-- parent pages--><a class="nav-link dropdown-indicator" href="#'.str_replace(' ', '_', $menu_info['display']).'" role="button" data-bs-toggle="collapse" aria-expanded="false" aria-controls="'.str_replace(' ', '_', $menu_info['display']).'">
									<div class="d-flex align-items-center"><span class="nav-link-icon"><span class="fas fa-'.$menu_info['icon'].'"></span></span><span class="nav-link-text ps-1">'.$menu_info['display'].'</span>
									</div>
								  </a>';
								echo '<ul class="nav collapse" id="'.str_replace(' ', '_', $menu_info['display']).'">';
								foreach ($iterate_menu as $iterate_menu_id=>$iterate_menu_info){
									if($iterate_menu_info['parent'] == $menu_id){
										if($iterate_menu_info['currentsub']){
											echo '<li class="nav-item"><a class="nav-link active" title="'.$iterate_menu_info['display'].'" href="'.$iterate_menu_info['defaultpage'].'"><div class="d-flex align-items-center"><span class="nav-link-text ps-1">'.$iterate_menu_info['display'].'</span>
											</div></a></li>';
										}
										else{
											echo '<li class="nav-item"><a class="nav-link" title="'.$iterate_menu_info['display'].'" href="'.$iterate_menu_info['defaultpage'].'"><div class="d-flex align-items-center"><span class="nav-link-text ps-1">'.$iterate_menu_info['display'].'</span>
											</div></a></li>';								
										}
									}
								}
								echo '</ul>';
															}
							else{
									echo '<!-- parent pages--><a class="nav-link" href="'.$menu_info['defaultpage'].'" role="button" data-bs-toggle="collapse" aria-expanded="false" aria-controls="'.$menu_info['display'].'">
									<div class="d-flex align-items-center"><span class="nav-link-icon"><span class="fas fa-'.$menu_info['icon'].'"></span></span><span class="nav-link-text ps-1">'.$menu_info['display'].'</span>
									</div>
								  </a>';
									
							}
						}
					}		
				}
				
				
				?>











 


                </li>

               
              </ul>
              
            </div>
          </div>
        </nav>
		<div class="content">
          
          
		<nav class="navbar navbar-light navbar-glass navbar-top navbar-expand">

            <button class="btn navbar-toggler-humburger-icon navbar-toggler me-1 me-sm-3" type="button" data-bs-toggle="collapse" data-bs-target="#navbarVerticalCollapse" aria-controls="navbarVerticalCollapse" aria-expanded="false" aria-label="Toggle Navigation"><span class="navbar-toggle-icon"><span class="toggle-line"></span></span></button>
            
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
			

            <ul class="navbar-nav align-items-center d-none d-lg-block">
              <li class="nav-item">
			  <!--
                <div class="search-box" data-list='{"valueNames":["title"]}'>
                  <form class="position-relative" data-bs-toggle="search" data-bs-display="static">
                    <input class="form-control search-input fuzzy-search" type="search" placeholder="Search..." aria-label="Search" />
                    <span class="fas fa-search search-box-icon"></span>

                  </form>
                  <div class="btn-close-falcon-container position-absolute end-0 top-50 translate-middle shadow-none" data-bs-dismiss="search">
                    <button class="btn btn-link btn-close-falcon p-0" aria-label="Close"></button>
                  </div>
                  <div class="dropdown-menu border font-base start-0 mt-2 py-0 overflow-hidden w-100">
                    <div class="scrollbar list py-3" style="max-height: 24rem;">
                      <h6 class="dropdown-header fw-medium text-uppercase px-x1 fs-11 pt-0 pb-2">Recently Browsed</h6><a class="dropdown-item fs-10 px-x1 py-1 hover-primary" href="../app/events/event-detail.html">
                        <div class="d-flex align-items-center">
                          <span class="fas fa-circle me-2 text-300 fs-11"></span>

                          <div class="fw-normal title">Pages <span class="fas fa-chevron-right mx-1 text-500 fs-11" data-fa-transform="shrink-2"></span> Events</div>
                        </div>
                      </a>
                      <a class="dropdown-item fs-10 px-x1 py-1 hover-primary" href="../app/e-commerce/customers.html">
                        <div class="d-flex align-items-center">
                          <span class="fas fa-circle me-2 text-300 fs-11"></span>

                          <div class="fw-normal title">E-commerce <span class="fas fa-chevron-right mx-1 text-500 fs-11" data-fa-transform="shrink-2"></span> Customers</div>
                        </div>
                      </a>

                      <hr class="text-200 dark__text-900" />
                      <h6 class="dropdown-header fw-medium text-uppercase px-x1 fs-11 pt-0 pb-2">Suggested Filter</h6><a class="dropdown-item px-x1 py-1 fs-9" href="../app/e-commerce/customers.html">
                        <div class="d-flex align-items-center"><span class="badge fw-medium text-decoration-none me-2 badge-subtle-warning">customers:</span>
                          <div class="flex-1 fs-10 title">All customers list</div>
                        </div>
                      </a>
                      <a class="dropdown-item px-x1 py-1 fs-9" href="../app/events/event-detail.html">
                        <div class="d-flex align-items-center"><span class="badge fw-medium text-decoration-none me-2 badge-subtle-success">events:</span>
                          <div class="flex-1 fs-10 title">Latest events in current month</div>
                        </div>
                      </a>
                      <a class="dropdown-item px-x1 py-1 fs-9" href="../app/e-commerce/product/product-grid.html">
                        <div class="d-flex align-items-center"><span class="badge fw-medium text-decoration-none me-2 badge-subtle-info">products:</span>
                          <div class="flex-1 fs-10 title">Most popular products</div>
                        </div>
                      </a>

                      <hr class="text-200 dark__text-900" />
                      <h6 class="dropdown-header fw-medium text-uppercase px-x1 fs-11 pt-0 pb-2">Files</h6><a class="dropdown-item px-x1 py-2" href="#!">
                        <div class="d-flex align-items-center">
                          <div class="file-thumbnail me-2"><img class="border h-100 w-100 object-fit-cover rounded-3" src="../assets/img/products/3-thumb.png" alt="" /></div>
                          <div class="flex-1">
                            <h6 class="mb-0 title">iPhone</h6>
                            <p class="fs-11 mb-0 d-flex"><span class="fw-semi-bold">Antony</span><span class="fw-medium text-600 ms-2">27 Sep at 10:30 AM</span></p>
                          </div>
                        </div>
                      </a>
                      <a class="dropdown-item px-x1 py-2" href="#!">
                        <div class="d-flex align-items-center">
                          <div class="file-thumbnail me-2"><img class="img-fluid" src="../assets/img/icons/zip.png" alt="" /></div>
                          <div class="flex-1">
                            <h6 class="mb-0 title">Falcon v1.8.2</h6>
                            <p class="fs-11 mb-0 d-flex"><span class="fw-semi-bold">John</span><span class="fw-medium text-600 ms-2">30 Sep at 12:30 PM</span></p>
                          </div>
                        </div>
                      </a>

                      <hr class="text-200 dark__text-900" />
                      <h6 class="dropdown-header fw-medium text-uppercase px-x1 fs-11 pt-0 pb-2">Members</h6><a class="dropdown-item px-x1 py-2" href="../pages/user/profile.html">
                        <div class="d-flex align-items-center">
                          <div class="avatar avatar-l status-online me-2">
                            <img class="rounded-circle" src="../assets/img/team/1.jpg" alt="" />

                          </div>
                          <div class="flex-1">
                            <h6 class="mb-0 title">Anna Karinina</h6>
                            <p class="fs-11 mb-0 d-flex">Technext Limited</p>
                          </div>
                        </div>
                      </a>
                      <a class="dropdown-item px-x1 py-2" href="../pages/user/profile.html">
                        <div class="d-flex align-items-center">
                          <div class="avatar avatar-l me-2">
                            <img class="rounded-circle" src="../assets/img/team/2.jpg" alt="" />

                          </div>
                          <div class="flex-1">
                            <h6 class="mb-0 title">Antony Hopkins</h6>
                            <p class="fs-11 mb-0 d-flex">Brain Trust</p>
                          </div>
                        </div>
                      </a>
                      <a class="dropdown-item px-x1 py-2" href="../pages/user/profile.html">
                        <div class="d-flex align-items-center">
                          <div class="avatar avatar-l me-2">
                            <img class="rounded-circle" src="../assets/img/team/3.jpg" alt="" />

                          </div>
                          <div class="flex-1">
                            <h6 class="mb-0 title">Emma Watson</h6>
                            <p class="fs-11 mb-0 d-flex">Google</p>
                          </div>
                        </div>
                      </a>

                    </div>
                    <div class="text-center mt-n3">
                      <p class="fallback fw-bold fs-8 d-none">No Result Found.</p>
                    </div>
                  </div>
                </div>
				-->
              </li>
            </ul>
            <ul class="navbar-nav navbar-nav-icons ms-auto flex-row align-items-center">
              <?php $this->top_right_menu(); ?>
            </ul>
          </nav>        
   


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
		
		echo AdminPage::BeginPage($options['readable_title']);
	}

	public function admin_footer($options=array()) {
		$session = SessionControl::get_instance();
		$session->clear_clearable_messages();
		$settings = Globalvars::get_instance();
		
		echo AdminPage::EndPage();
	
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




















/*


	function admin_header($pagevars, $display=TRUE) 
	{


		$_GLOBALS['page_header_loaded'] = true;
		
		//header("Content-Security-Policy-Report-Only: default-src 'self' 'unsafe-inline' https://integralzen.org https://*.cloudflare.com https://*.gstatic.com https://*.googleapis.com; report-uri https://integralzen.org/somasdflkj; report-to groupname");

		$session = $pagevars['session'];		
		
		if($session && $session->get_user_id()){
			$user = new User($session->get_user_id(), TRUE);
			$user_name = $user->display_name();
		}
		else{
			$user = new User(NULL);
		}
		
		$settings = Globalvars::get_instance();

		$maintitle = $settings->get_setting('site_name');
		$logo = $settings->get_setting('logo_link');
		$thisfile = basename($_SERVER['PHP_SELF']);
		
		if($_SESSION['permission'] == 10){
			//error_reporting(E_ALL | E_STRICT);
		}
		 
		?>
<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<base href="/">

		
		<?php
		if($pagevars['page_title']){
			echo '<title>'.$pagevars['page_title'].'</title>';
		}

		if($pagevars['uploader']){
			?>
			<!--For file uploader to work, we need to use Bootstrap 3.3.7 styles -->
			<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css"	/>

			<!-- CSS FILES -->
			<link rel="stylesheet" type="text/css" href="/adm/includes/uikit-3.6.14/css/uikit.min.css">
			<link rel="stylesheet" type="text/css" href="/adm/includes/uikit-3.6.14/css/main_admin.css">
		
			<!-- jQuery 3 -->
			<!-- jQuery 3.2.1 <script src="/adm/assets/vendor_components/jquery/dist/jquery.min.js"></script>-->
			<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
			<!--<script src="https://code.jquery.com/jquery-migrate-3.1.0.min.js"></script>-->
			
			<!-- jQuery validate -->
			<script type="text/javascript" src="/adm/includes/scripts/jquery.validate-1.9.1.js"></script>


			<!--jQuery UI, needed for blueimp uploader -->
			<script src="https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.js"></script>

			<!-- blueimp Gallery styles for uploader-->
			<link rel="stylesheet" href="/includes/jquery-file-upload/css/blueimp-gallery.min.css"/>
			<!-- CSS to style the file input field as button and adjust the Bootstrap progress bars -->
			<link rel="stylesheet" href="/includes/jquery-file-upload/css/jquery.fileupload.css" />
			<link rel="stylesheet" href="/includes/jquery-file-upload/css/jquery.fileupload-ui.css" />
			<!-- CSS adjustments for browsers with JavaScript disabled -->
			<noscript
			  ><link rel="stylesheet" href="/includes/jquery-file-upload/css/jquery.fileupload-noscript.css"
			/></noscript>
			<noscript
			  ><link rel="stylesheet" href="/includes/jquery-file-upload/css/jquery.fileupload-ui-noscript.css"
			/></noscript>
			<!--end Gallery styles-->	

			<?php
		}
		else{
			?>
			<!-- CSS FILES -->
			<link rel="stylesheet" type="text/css" href="/adm/includes/uikit-3.6.14/css/uikit.min.css">
			<link rel="stylesheet" type="text/css" href="/adm/includes/uikit-3.6.14/css/main_admin.css">
		
			<!-- jQuery 3 -->
			<!-- jQuery 3.2.1 <script src="/adm/assets/vendor_components/jquery/dist/jquery.min.js"></script>-->
			<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
			<!--<script src="https://code.jquery.com/jquery-migrate-3.1.0.min.js"></script>-->
			
			<!-- jQuery validate -->
			<script type="text/javascript" src="/adm/includes/scripts/jquery.validate-1.9.1.js"></script>			
			
			<?php
		}
		?>	


		
	<!--
		<link rel="icon" href="icon.png" sizes="32x32" />
		<link rel="icon" href="icon.png" sizes="192x192" />
		<link rel="apple-touch-icon-precomposed" href="icon.png" />	-->
				
	</head>
	<body>


		<!--HEADER-->
		<header id="top-head" class="uk-position-fixed">		
			
			
			<div class="uk-container uk-container-expand uk-background-primary">		
				<nav class="uk-navbar uk-light" data-uk-navbar="mode:click; duration: 250">
					<div class="uk-navbar-left">
					<!--
						<div class="uk-navbar-item uk-hidden@m">
							<a class="uk-logo" href="/"><img class="custom-logo" src="<?php 
							if($settings->get_setting('logo_link')){
								echo $settings->get_setting('logo_link'); 
							}
							?>" alt="<?php echo $settings->get_setting('site_name'); ?>"></a>
						</div>-->
						
						<?php

						if($pagevars['breadcrumbs']){	
							$breadcrumb_output = array();
							echo '<span>';
							foreach ($pagevars['breadcrumbs'] as $breadcrumb=>$link){
								if($link){
									array_push($breadcrumb_output, '<a href="'.$link.'">'.$breadcrumb .'</a>');
								}
								else{
									array_push($breadcrumb_output, $breadcrumb);	
								}
							}
							echo implode(' > ', $breadcrumb_output);
							echo '</span>';
						}
						?>
						<!--
						<ul class="uk-navbar-nav uk-visible@m">
							<li><a href="#">Accounts</a></li>
							<li>
								<a href="#">Settings <span data-uk-icon="icon: triangle-down"></span></a>
								<div class="uk-navbar-dropdown">
									<ul class="uk-nav uk-navbar-dropdown-nav">
										<li class="uk-nav-header">YOUR ACCOUNT</li>
										<li><a href="#"><span data-uk-icon="icon: info"></span> Summary</a></li>
										<li><a href="#"><span data-uk-icon="icon: refresh"></span> Edit</a></li>
										<li><a href="#"><span data-uk-icon="icon: settings"></span> Configuration</a></li>
										<li class="uk-nav-divider"></li>
										<li><a href="#"><span data-uk-icon="icon: image"></span> Your Data</a></li>
										<li class="uk-nav-divider"></li>
										<li><a href="#"><span data-uk-icon="icon: sign-out"></span> Logout</a></li>
									</ul>
								</div>
							</li>
						</ul>
						<div class="uk-navbar-item uk-visible@s">
							<form action="dashboard.html" class="uk-search uk-search-default">
								<span data-uk-search-icon></span>
								<input class="uk-search-input search-field" type="search" placeholder="Search">
							</form>
						</div>
						-->
					</div>
					<div class="uk-navbar-right">
						<ul class="uk-navbar-nav">
							<li><a href="/profile" data-uk-icon="icon:user" title="Your profile" data-uk-tooltip></a></li>
							<li><a href="/admin/admin_help" data-uk-icon="icon: question" title="Help" data-uk-tooltip></a></li> 
							<?php if($_SESSION['permission'] == 10){ ?>
							<li><a href="/admin/admin_settings" data-uk-icon="icon: grid" title="Settings" data-uk-tooltip></a></li>
							<li><a href="/admin/admin_utilities" data-uk-icon="icon: grid" title="Utilities" data-uk-tooltip></a></li>
							<?php } ?>
							<li><a href="/logout" data-uk-icon="icon:  sign-out" title="Sign Out" data-uk-tooltip></a></li>
							<li><a class="uk-navbar-toggle" data-uk-toggle data-uk-navbar-toggle-icon href="#offcanvas-nav" title="Offcanvas" data-uk-tooltip></a></li>
						</ul>
					</div>
				</nav>
			</div>
		</header>
		<!--/HEADER-->
		<!-- LEFT BAR -->
		<aside id="left-col" class="uk-light uk-visible@m">
			<div class="left-logo uk-flex uk-flex-middle">
				<?php
				if($settings->get_setting('logo_link')){
					echo '<a class="uk-logo" href="/"><img class="custom-logo" src="'. $settings->get_setting('logo_link') .'" alt="'.$settings->get_setting('site_name').'"></a>';
				}
				else{
					$dots = '';
					if(strlen($settings->get_setting('site_name')) > 20){
						$dots = '...';
					}
					echo '<a class="uk-logo" href="/"><span style="font-size: 14px;">'.substr($settings->get_setting('site_name'), 0, 20).$dots .'</span></a>';
				}
				?>
			</div>
			<div class="left-content-box  content-box-dark">
				<!--<img src="img/avatar.svg" alt="" class="uk-border-circle profile-img">-->
				<h4 class="uk-text-center uk-margin-remove-vertical text-light"><?php echo $user_name; ?> <?php echo '('.$user->key.')'; ?></h4>
				
				<div class="uk-position-relative uk-text-center uk-display-block">
				    <a href="#" class="uk-text-small uk-text-muted uk-display-block uk-text-center" data-uk-icon="icon: triangle-down; ratio: 0.7">Admin</a>
				    <!-- user dropdown -->
				    <div class="uk-dropdown user-drop" data-uk-dropdown="mode: click; pos: bottom-center; animation: uk-animation-slide-bottom-small; duration: 150">
				    	<ul class="uk-nav uk-dropdown-nav uk-text-left">

						<?php
						if($session && $session->get_permission() >= 9) {
							if ($session->get_user_id() !== $session->get_initial_user_id()) {
								echo '<div id="adminMenu" style="background: #FF7777;">';
							} else {
								echo '<div id="adminMenu">';
							}
							?>
							<form id="form0" class="" name="form0" method="post" action="/data/login_data_admin">
							<span>User</span>
							<input id="newuserid" type="text" name="usr_user_id" value="<?php echo $_SESSION['usr_user_id']; ?>" style="width: 3em" /><br />
						  <span>Emails</span>

						   <label for="send_emails1">
								<input name="send_emails" id="send_emails1" value="1" type="checkbox" <?php if((!isset($_SESSION['send_emails']) || $_SESSION['send_emails'] == TRUE)){ echo 'checked="checked"'; } ?>  />
							  </label><br />
							  
							<span>Test mode</span>

						   <label for="send_emails1">
								<input name="test_mode" id="test_mode" value="1" type="checkbox" <?php if((isset($_SESSION['test_mode']) && $_SESSION['test_mode'] == TRUE)){ echo 'checked="checked"'; } ?>  />
							  </label>


							<br /><input type="submit" name="submit" value="Update" />
							</form>
							</div>
							<?php
						}
						?>
						<!--
								<li><a href="#"><span data-uk-icon="icon: info"></span> Summary</a></li>
								<li><a href="#"><span data-uk-icon="icon: refresh"></span> Edit</a></li>
								<li><a href="#"><span data-uk-icon="icon: settings"></span> Configuration</a></li>
								<li class="uk-nav-divider"></li>
								-->
								<!--<li class="uk-nav-divider"></li>
								<li><a id="admintoggle" href="#"><span data-uk-icon="icon: image"></span> Debug Data</a></li>-->
								<!--<li class="uk-nav-divider"></li>
								<li><a href="/logout"><span data-uk-icon="icon: sign-out"></span> Sign Out</a></li>-->
					    </ul>
				    </div>
				    <!-- /user dropdown -->
				</div>
			</div>
			
			<div class="left-nav-wrap">
				
				<ul class="uk-nav uk-nav-default uk-nav-parent-icon" data-uk-nav>
					<li class="uk-nav-header">MAIN MENU</li>

				<?php 		
				$admin_menu = MultiAdminMenu::getadminmenu($user->get('usr_permission'), $pagevars['menu-id']); 
				$iterate_menu = $admin_menu;
				
				foreach ($admin_menu as $menu_id=>$menu_info){	
					if(!$menu_info['parent']){
						if($menu_info['currentmain']){
							if($menu_info['has_subs']){
								echo '<li class="uk-parent uk-open"><a href="/admin/'.$menu_info['defaultpage'].'"><span data-uk-icon="icon: '.$menu_info['icon'].'" class="uk-margin-small-right"></span>'.$menu_info['display'].'</a>';
								echo '<ul class="uk-nav-sub">';
								foreach ($iterate_menu as $iterate_menu_id=>$iterate_menu_info){
									if($iterate_menu_info['parent'] == $menu_id){
										if($iterate_menu_info['currentsub']){
											echo '<li class="uk-active"><a title="'.$iterate_menu_info['display'].'" href="'.$iterate_menu_info['defaultpage'].'">'.$iterate_menu_info['display'].'</a></li>';
										}
										else{
											echo '<li><a title="'.$iterate_menu_info['display'].'" href="'.$iterate_menu_info['defaultpage'].'">'.$iterate_menu_info['display'].'</a></li>';									
										}
									}
								}
								echo '</ul>';
								echo '</li>';
							}
							else{
								echo '<li><a href="/admin/'.$menu_info['defaultpage'].'"><span data-uk-icon="icon: '.$menu_info['icon'].'" class="uk-margin-small-right"></span>'.$menu_info['display'].'</a></li>';	
							}
						}
						else{
							if($menu_info['has_subs']){
								echo '<li class="uk-parent"><a href="/admin/'.$menu_info['defaultpage'].'"><span data-uk-icon="icon: '.$menu_info['icon'].'" class="uk-margin-small-right"></span>'.$menu_info['display'].'</a>';
								echo '<ul class="uk-nav-sub">';
								foreach ($iterate_menu as $iterate_menu_id=>$iterate_menu_info){
									if($iterate_menu_info['parent'] == $menu_id){
										if($iterate_menu_info['currentsub']){
											echo '<li class="uk-active"><a title="'.$iterate_menu_info['display'].'" href="'.$iterate_menu_info['defaultpage'].'">'.$iterate_menu_info['display'].'</a></li>';
										}
										else{
											echo '<li><a title="'.$iterate_menu_info['display'].'" href="'.$iterate_menu_info['defaultpage'].'">'.$iterate_menu_info['display'].'</a></li>';									
										}
									}
								}
								echo '</ul>';
								echo '</li>';							}
							else{
								echo '<li><a href="'.$menu_info['defaultpage'].'"><span data-uk-icon="icon: '.$menu_info['icon'].'" class="uk-margin-small-right"></span>'.$menu_info['display'].'</a></li>';									
							}
						}
					}		
				}
				
				
				?>

					
				</ul>
				<!--
				<div class="left-content-box uk-margin-top">
					
						<h5>Daily Reports</h5>
						<div>
							<span class="uk-text-small">Traffic <small>(+50)</small></span>
							<progress class="uk-progress" value="50" max="100"></progress>
						</div>
						<div>
							<span class="uk-text-small">Income <small>(+78)</small></span>
							<progress class="uk-progress success" value="78" max="100"></progress>
						</div>
						<div>
							<span class="uk-text-small">Feedback <small>(-12)</small></span>
							<progress class="uk-progress warning" value="12" max="100"></progress>
						</div>
					
				</div>
				-->
				
			</div>
			<!--
			<div class="bar-bottom">
				<ul class="uk-subnav uk-flex uk-flex-center uk-child-width-1-5" data-uk-grid>
					<li>
						<a href="#" class="uk-icon-link" data-uk-icon="icon: home" title="Home" data-uk-tooltip></a>
					</li>
					<li>
						<a href="#" class="uk-icon-link" data-uk-icon="icon: settings" title="Settings" data-uk-tooltip></a>
					</li>
					<li>
						<a href="#" class="uk-icon-link" data-uk-icon="icon: social"  title="Social" data-uk-tooltip></a>
					</li>
					
					<li>
						<a href="#" class="uk-icon-link" data-uk-tooltip="Sign out" data-uk-icon="icon: sign-out"></a>
					</li>
				</ul>
			</div>
			-->
		</aside>
		<!-- /LEFT BAR -->

			<!-- CONTENT -->
		<div id="content" data-uk-height-viewport="expand: true">
			<div class="uk-container uk-container-expand">
				<div class="uk-grid" data-uk-grid>
	<?php

	}


	function admin_footer() {
		$settings = Globalvars::get_instance();
		
		$session = SessionControl::get_instance();
		if($session && $session->get_user_id()){
			$user = new User($session->get_user_id(), TRUE);
			$user_name = $user->display_name();
		}
		else{
			$user = new User(NULL);
		}


		?>
							
						</div>
				<footer class="uk-section uk-section-small uk-text-center">
					<hr>
					<p class="uk-text-small uk-text-center">Copyright 2023 - Jeremy Tunnell | Built with <a href="http://getuikit.com" title="Visit UIkit 3 site" target="_blank" data-uk-tooltip><span data-uk-icon="uikit"></span></a> </p>
				</footer>
			</div>
		
		
		</div>
		<!-- /CONTENT -->
		<!-- OFFCANVAS -->
		<div id="offcanvas-nav" data-uk-offcanvas="flip: true; overlay: true">
			<div class="uk-offcanvas-bar uk-offcanvas-bar-animation uk-offcanvas-slide">
				<button class="uk-offcanvas-close uk-close uk-icon" type="button" data-uk-close></button>
				<ul class="uk-nav uk-nav-default">
					<li class="uk-active"><a href="#">Active</a></li>
					<li class="uk-parent">
				<?php 		
				$admin_menu = MultiAdminMenu::getadminmenu($user->get('usr_permission'), $pagevars['menu-id']); 
				$iterate_menu = $admin_menu;				
				foreach ($admin_menu as $menu_id=>$menu_info){			
					if(!$menu_info['parent']){
						if($menu_info['currentmain']){
							if($menu_info['has_subs']){
								echo '<li class="uk-parent uk-open"><a href="'.$menu_info['defaultpage'].'">'.$menu_info['display'].'</a>';
								echo '<ul class="uk-nav-sub">';
								foreach ($iterate_menu as $iterate_menu_id=>$iterate_menu_info){
									if($iterate_menu_info['parent'] == $menu_id){
										if($iterate_menu_info['currentsub']){
											echo '<li><a title="'.$iterate_menu_info['display'].'" href="'.$iterate_menu_info['defaultpage'].'">'.$iterate_menu_info['display'].'</a></li>';
										}
										else{
											echo '<li><a title="'.$iterate_menu_info['display'].'" href="'.$iterate_menu_info['defaultpage'].'">'.$iterate_menu_info['display'].'</a></li>';									
										}
									}
								}
								echo '</ul>';
								echo '</li>';
							}
							else{
								echo '<li><a href="'.$menu_info['defaultpage'].'">'.$menu_info['display'].'</a></li>';	
							}
						}
						else{
							if($menu_info['has_subs']){
								echo '<li class="uk-parent"><a href="'.$menu_info['defaultpage'].'">'.$menu_info['display'].'</a>';
								echo '<ul class="uk-nav-sub">';
								foreach ($iterate_menu as $iterate_menu_id=>$iterate_menu_info){
									if($iterate_menu_info['parent'] == $menu_id){
										if($iterate_menu_info['currentsub']){
											echo '<li><a title="'.$iterate_menu_info['display'].'" href="'.$iterate_menu_info['defaultpage'].'">'.$iterate_menu_info['display'].'</a></li>';
										}
										else{
											echo '<li><a title="'.$iterate_menu_info['display'].'" href="'.$iterate_menu_info['defaultpage'].'">'.$iterate_menu_info['display'].'</a></li>';									
										}
									}
								} 
								echo '</ul>';
								echo '</li>';							
							}
							else{
								echo '<li><a href="'.$menu_info['defaultpage'].'">'.$menu_info['display'].'</a></li>';									
							}
						}
					}		
				}
				
				
				?>


					<!--
						<ul class="uk-nav-sub">
							<li><a href="#">Sub item</a></li>
							<li><a href="#">Sub item</a></li>
						</ul>
					</li>
					<li class="uk-nav-header">Header</li>
					<li><a href="#js-options"><span class="uk-margin-small-right uk-icon" data-uk-icon="icon: table"></span> Item</a></li>
					<li><a href="#"><span class="uk-margin-small-right uk-icon" data-uk-icon="icon: thumbnails"></span> Item</a></li>
					<li class="uk-nav-divider"></li>
					<li><a href="#"><span class="uk-margin-small-right uk-icon" data-uk-icon="icon: trash"></span> Item</a></li>
					-->
					</li>
				</ul>
				<!--
				<h3>Title</h3>
				<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.</p>
				-->
			</div>
		</div>
		<!-- /OFFCANVAS -->
		
		<!-- JS FILES -->
		<script src="/adm/includes/uikit-3.6.14/js/uikit.min.js"></script>
		<script src="/adm/includes/uikit-3.6.14/js/uikit-icons.min.js"></script>

	</body>
</html>
<?php
	}

*/
}

?>
