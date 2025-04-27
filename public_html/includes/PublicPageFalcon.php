<?php
require_once('PublicPageMaster.php');
require_once('Pager.php');

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
			 if($session->get_permission() >= 5){ ?>
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
	
	public function vertical_menu($menu){
		$session = SessionControl::get_instance();
		$settings = Globalvars::get_instance();						

		
		?>
        <nav class="navbar navbar-light navbar-vertical navbar-expand-xl">
          <!--<script>
            var navbarStyle = localStorage.getItem("navbarStyle");
            if (navbarStyle && navbarStyle !== 'transparent') {
              document.querySelector('.navbar-vertical').classList.add(`navbar-${navbarStyle}`);
            }
          </script>-->
          <div class="d-flex align-items-center">
            <div class="toggle-icon-wrapper">

              <button class="btn navbar-toggler-humburger-icon navbar-vertical-toggle" data-bs-toggle="tooltip" data-bs-placement="left" title="Toggle Navigation"><span class="navbar-toggle-icon"><span class="toggle-line"></span></span></button>

            </div><a class="navbar-brand" href="/">
              <div class="d-flex align-items-center py-3">
			  
					<?php 
							echo $this->get_logo(); 
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
				  <!--
                  <div class="row navbar-vertical-label-wrapper mt-3 mb-2">
                    <div class="col-auto navbar-vertical-label">Documentation
                    </div>
                    <div class="col ps-0">
                      <hr class="mb-0 navbar-vertical-divider" />
                    </div>
                  </div>-->
                  			<?php

							
							$iterate_menu = $menu;
							foreach ($menu as $menu_id=>$menu_info){	
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
												echo '<!-- parent pages--><a class="nav-link" href="'.$menu_info['defaultpage'].'" role="button" >
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
		<?php
				
		
		
		
	}
	
	public function get_logo(){
			$settings = Globalvars::get_instance();
					
			if($settings->get_setting('logo_link')){
				echo '<img class="me-2" src="'.$settings->get_setting('logo_link').'" alt="" width="40" />';
			}
			 
			echo '<span class="font-sans-serif text-primary">';
			
					 echo $settings->get_setting('site_name'); 
			
			echo '</span>';	
		
		
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
	<link rel="stylesheet" type="text/css" id="style-default" href="<?php echo LibraryFunctions::get_theme_file_path('theme.css', '/includes/css', 'web'); ?>">
	<link rel="stylesheet" type="text/css" id="user-style-default" href="<?php echo LibraryFunctions::get_theme_file_path('user_exceptions.css', '/includes/css', 'web'); ?>">
	
	

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
	<?php 
	if(isset($options['full_width']) && $options['full_width']){
		echo '<div class="container-fluid">';
	}
	else{
		echo '<div class="container">';
	}
	?>

			<!-- Vertical Menu -->
		  <?php 
		  if($options['vertical_menu']){
				echo $this->vertical_menu($options['vertical_menu']);
		  }
		  ?>
	

		 
        <div class="content">
          <nav class="navbar navbar-light navbar-glass navbar-top navbar-expand-lg"  data-navbar-top="combo">

            <button class="btn navbar-toggler-humburger-icon navbar-toggler me-1 me-sm-3" type="button" data-bs-toggle="collapse" data-bs-target="#navbarVerticalCollapse" aria-controls="navbarVerticalCollapse" aria-expanded="false" aria-label="Toggle Navigation"><span class="navbar-toggle-icon"><span class="toggle-line"></span></span></button>
            <a class="navbar-brand me-1 me-sm-3" href="/">
              <div class="d-flex align-items-center">

					<?php echo $this->get_logo(); ?>
              </div>
            </a>


			<?php 
			if(!isset($options['hide_horizontal_menu']) || !$options['hide_horizontal_menu']){
			  ?>
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
		  <?php } ?>
            <ul class="navbar-nav navbar-nav-icons ms-auto flex-row align-items-center">
              <?php $this->top_right_menu(); ?>
            </ul>
          </nav>

	<?php 
	}

	public function public_footer($options=array()) {
	
      ?>
         

          <footer class="footer">
            <div class="row g-0 justify-content-between fs-10 mt-4 mb-3">
              <div class="col-12 col-sm-auto text-center">
                <!--<p class="mb-0 text-600">Thank you for creating with Falcon <span class="d-none d-sm-inline-block">| </span><br class="d-sm-none" /> 2024 &copy; <a href="https://themewagon.com">Themewagon</a></p>-->
              </div>
              <div class="col-12 col-sm-auto text-center">
                <p class="mb-0 text-600">v0.5.0</p>
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
	
	echo '<div>';
	
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
			echo '<div class="d-flex align-items-center justify-content-center position-relative mt-3">';
						echo '<div class="position-absolute start-0 mb-0 fs-10"> '.$pager->num_records().' records, Page '.$pager->current_page() .' of '.$pager->total_pages().'</div>';

						echo '<div><div class="d-flex justify-content-center mt-3">';
		
						if($pager->num_records() > $pager->num_per_page()){
							if($page_number = $pager->is_valid_page('-10')){
								echo '<a href="'.$pager->get_url($page_number).'"><button class="btn btn-sm btn-falcon-default me-1" type="button" title="Previous 10" data-list-pagination="prev"><svg class="svg-inline--fa fa-chevron-left fa-w-10" aria-hidden="true" focusable="false" data-prefix="fas" data-icon="chevron-left" role="img" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 512" data-fa-i2svg=""><path fill="currentColor" d="M34.52 239.03L228.87 44.69c9.37-9.37 24.57-9.37 33.94 0l22.67 22.67c9.36 9.36 9.37 24.52.04 33.9L131.49 256l154.02 154.75c9.34 9.38 9.32 24.54-.04 33.9l-22.67 22.67c-9.37 9.37-24.57 9.37-33.94 0L34.52 272.97c-9.37-9.37-9.37-24.57 0-33.94z"></path></svg><!-- <span class="fas fa-chevron-left"></span> Font Awesome fontawesome.com --></button></a>';
							}
							else{
								echo '<button class="btn btn-sm btn-falcon-default me-1 disabled" type="button" title="Previous 10" data-list-pagination="prev" disabled=""><svg class="svg-inline--fa fa-chevron-left fa-w-10" aria-hidden="true" focusable="false" data-prefix="fas" data-icon="chevron-left" role="img" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 512" data-fa-i2svg=""><path fill="currentColor" d="M34.52 239.03L228.87 44.69c9.37-9.37 24.57-9.37 33.94 0l22.67 22.67c9.36 9.36 9.37 24.52.04 33.9L131.49 256l154.02 154.75c9.34 9.38 9.32 24.54-.04 33.9l-22.67 22.67c-9.37 9.37-24.57 9.37-33.94 0L34.52 272.97c-9.37-9.37-9.37-24.57 0-33.94z"></path></svg><!-- <span class="fas fa-chevron-left"></span> Font Awesome fontawesome.com --></button>';
							}
											
							echo '<ul class="pagination mb-0">';
							for($x=4; $x>=1;$x--){
								if($page_number = $pager->is_valid_page('-'.$x)){
									echo '<a href="'.$pager->get_url($page_number).'"><button class="page btn btn-sm btn-falcon-default" type="button" data-i="2" data-page="5">'.$page_number.'</button></a> ';
								}
							}
							
							echo '<li class="active"><button class="page btn btn-sm btn-falcon-default disabled" type="button" disabled="">'.$pager->current_page().'</button></li> ';
							
							for($x=1; $x<=4;$x++){
								if($page_number = $pager->is_valid_page('+'.$x)){
									echo '<a href="'.$pager->get_url($page_number).'"><button class="page btn btn-sm btn-falcon-default" type="button" data-i="2" data-page="5">'.$page_number.'</button></a> ';
								}
							}	
							echo '</ul>';
						
							if($page_number = $pager->is_valid_page('+10')){
								echo '<a href="'.$pager->get_url($page_number).'"><button class="btn btn-sm btn-falcon-default ms-1" type="button" title="Next 10" data-list-pagination="next"><svg class="svg-inline--fa fa-chevron-right fa-w-10" aria-hidden="true" focusable="false" data-prefix="fas" data-icon="chevron-right" role="img" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 512" data-fa-i2svg=""><path fill="currentColor" d="M285.476 272.971L91.132 467.314c-9.373 9.373-24.569 9.373-33.941 0l-22.667-22.667c-9.357-9.357-9.375-24.522-.04-33.901L188.505 256 34.484 101.255c-9.335-9.379-9.317-24.544.04-33.901l22.667-22.667c9.373-9.373 24.569-9.373 33.941 0L285.475 239.03c9.373 9.372 9.373 24.568.001 33.941z"></path></svg><!-- <span class="fas fa-chevron-right"></span> Font Awesome fontawesome.com --></button></a>';
							}
							else{
								echo '<button class="btn btn-sm btn-falcon-default ms-1 disabled" type="button" title="Next 10" data-list-pagination="next" disabled=""><svg class="svg-inline--fa fa-chevron-right fa-w-10" aria-hidden="true" focusable="false" data-prefix="fas" data-icon="chevron-right" role="img" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 512" data-fa-i2svg=""><path fill="currentColor" d="M285.476 272.971L91.132 467.314c-9.373 9.373-24.569 9.373-33.941 0l-22.667-22.667c-9.357-9.357-9.375-24.522-.04-33.901L188.505 256 34.484 101.255c-9.335-9.379-9.317-24.544.04-33.901l22.667-22.667c9.373-9.373 24.569-9.373 33.941 0L285.475 239.03c9.373 9.372 9.373 24.568.001 33.941z"></path></svg><!-- <span class="fas fa-chevron-right"></span> Font Awesome fontawesome.com --></button>';
							}
						}
			
			
			echo '</div></div>';
			echo '</div>';
		
		}
		
		
		$this->end_box();

	}	
	
	
	
	
	
	static function alert($title, $content, $type)
	{
		// map types to Bootstrap classes and SVG icons
		switch ($type) {
			case 'error':
				$bsClass = 'alert-danger';
				$svg = '<svg class="bi flex-shrink-0 me-2" width="24" height="24" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
						  <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
						</svg>';
				break;

			case 'warn':
				$bsClass = 'alert-warning';
				$svg = '<svg class="bi flex-shrink-0 me-2" width="24" height="24" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
						  <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
						</svg>';
				break;

			case 'success':
				$bsClass = 'alert-success';
				$svg = '<svg class="bi flex-shrink-0 me-2" width="24" height="24" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
						  <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
						</svg>';
				break;

			default:
				$bsClass = 'alert-info';
				$svg = '';
		}

		// build Bootstrap alert with icon
		$output  = '<div class="alert ' . $bsClass . ' d-flex align-items-start alert-dismissible fade show" role="alert">';
		if ($svg) {
			$output .= $svg;
		}
		$output .= '<div class="flex-grow-1">';
		$output .= '<h4 class="alert-heading mb-1">' . htmlspecialchars($title) . '</h4>';
		$output .= '<p class="mb-0">' . $content . '</p>';
		$output .= '</div>';
		$output .= '<button type="button" class="btn-close ms-3" data-bs-dismiss="alert" aria-label="Close"></button>';
		$output .= '</div>';

		return $output;
	}
	


	static function tab_menu($tab_menus, $current=NULL){
		
		$output = '';
		$output .= '
		<ul class="nav nav-tabs mb-3">';

					foreach($tab_menus as $name => $link){
						if($name == $current){
						  $output .= '<li class="nav-item"><a class="nav-link active" href="#"  aria-selected="true">'.$name.'</a></li>';						
						}
						else{
						   $output .= '<li class="nav-item"><a class="nav-link" href="'.$link.'"  aria-selected="false">'.$name.'</a></li>';							
						}
					}
				$output .= '

		</ul>';
		
		return $output;
		
	}


	
}

?>
