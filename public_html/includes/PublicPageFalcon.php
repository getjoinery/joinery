<?php
require_once(__DIR__ . '/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/PublicPageBase.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));

class PublicPageFalcon extends PublicPageBase {
	
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

	/**
	 * Begin page content without outer card wrapper
	 * Displays page title and breadcrumbs in a clean header section
	 */
	public static function BeginPageNoCard($options=array()) {
		$output = '
		<!-- Page Header -->
		<div class="mb-3">';

		// Only show header if there's a title or breadcrumbs
		if (!empty($options['readable_title']) || !empty($options['breadcrumbs'])) {
			$output .= '
			<div class="d-flex flex-wrap flex-between-center mb-2">
				<div>';

			// Page Title
			if (!empty($options['readable_title'])) {
				$output .= '
					<h2 class="mb-2">' . htmlspecialchars($options['readable_title']) . '</h2>';
			}

			// Breadcrumbs
			if (!empty($options['breadcrumbs']) && is_array($options['breadcrumbs'])) {
				$output .= '
					<nav aria-label="breadcrumb">
						<ol class="breadcrumb mb-0">';

				$breadcrumb_count = count($options['breadcrumbs']);
				$current_index = 0;

				foreach ($options['breadcrumbs'] as $name => $url) {
					$current_index++;
					$is_last = ($current_index === $breadcrumb_count);

					if ($is_last || empty($url)) {
						// Last item or no URL - display as active
						$output .= '
							<li class="breadcrumb-item active" aria-current="page">' . htmlspecialchars($name) . '</li>';
					} else {
						// Regular breadcrumb with link
						$output .= '
							<li class="breadcrumb-item"><a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($name) . '</a></li>';
					}
				}

				$output .= '
						</ol>
					</nav>';
			}

			$output .= '
				</div>
			</div>';
		}

		$output .= '
		</div>
		';

		return $output;
	}

	/**
	 * End page content without outer card wrapper
	 */
	public static function EndPageNoCard($options=array()) {
		// No closing markup needed since we don't open any wrapping containers
		return '';
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
		// Get all menu data from centralized function
		$menu_data = $this->get_menu_data();
		$cart = $menu_data['cart'];
		$user_menu = $menu_data['user_menu'];
		$notifications = $menu_data['notifications'];

		// SHOPPING CART MENU ITEM - Falcon theme specific styling
		if($cart['has_items']){
			echo '<li class="nav-item d-none d-sm-block">
			  <a class="nav-link px-0 notification-indicator notification-indicator-warning notification-indicator-fill fa-icon-wait" href="' . $cart['link'] . '">
			    <span class="fas fa-shopping-cart" data-fa-transform="shrink-7" style="font-size: 33px;"></span>
			    <span class="notification-indicator-number">' . $cart['item_count'] . '</span>
			  </a>
			</li>';
		}

		// NOTIFICATION MENU ITEM - Falcon theme specific styling (future implementation)
		if($notifications['enabled'] && $notifications['count'] > 0){
			?>
			<li class="nav-item dropdown">
			  <a class="nav-link notification-indicator notification-indicator-primary px-0 fa-icon-wait" id="navbarDropdownNotification" role="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false" data-hide-on-body-scroll="data-hide-on-body-scroll">
			    <span class="fas fa-bell" data-fa-transform="shrink-6" style="font-size: 33px;"></span>
			    <span class="notification-indicator-number"><?php echo $notifications['unread_count']; ?></span>
			  </a>
			  <div class="dropdown-menu dropdown-caret dropdown-caret dropdown-menu-end dropdown-menu-card dropdown-menu-notification dropdown-caret-bg" aria-labelledby="navbarDropdownNotification">
			    <!-- Notification dropdown content will be rendered here when implemented -->
			  </div>
			</li>
			<?php
		}

		// ADMIN MENU NAVIGATION ITEM - Falcon theme nine-dots design
		if($user_menu['permission_level'] >= 5){ ?>
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
		    </svg>
		  </a>
		  <div class="dropdown-menu dropdown-caret dropdown-caret dropdown-menu-end dropdown-menu-card dropdown-caret-bg" aria-labelledby="navbarDropdownMenu">
		    <div class="card shadow-none">
		      <div class="scrollbar-overlay nine-dots-dropdown">
		        <div class="card-body px-3">
		          <div class="row text-center gx-0 gy-0">
		            <?php
		            // Render admin menu items from menu data
		            foreach($user_menu['items'] as $item) {
		              // Only show admin items
		              if(in_array($item['label'], ['Home', 'My Profile', 'Admin Dashboard', 'Admin Settings', 'Admin Utilities', 'Admin Help'])) {
		                $icon_svg = $this->get_admin_icon_svg($item['icon']);
		                echo '<div class="col-4">
		                  <a class="d-block hover-bg-200 px-2 py-3 rounded-3 text-center text-decoration-none" href="' . $item['link'] . '">
		                    <div class="avatar avatar-2xl">' . $icon_svg . '</div>
		                    <p class="mb-0 fw-medium text-800 text-truncate fs-11">' . $item['label'] . '</p>
		                  </a>
		                </div>';
		              }
		            }
		            ?>
		          </div>
		        </div>
		      </div>
		    </div>
		  </div>
		</li>
		<?php }

		// USER LOGIN/LOGOUT MENU - Falcon theme specific styling
		if(!$user_menu['is_logged_in']){ ?>
		<ul class="navbar-nav" data-top-nav-dropdowns="data-top-nav-dropdowns">
		  <li class="nav-item"><a class="nav-link" href="<?php echo $user_menu['login_link']; ?>">Login</a></li>
		</ul>
		<?php if($user_menu['register_link']){ ?>
		<ul class="navbar-nav" data-top-nav-dropdowns="data-top-nav-dropdowns">
		  <li class="nav-item"><a class="nav-link" href="<?php echo $user_menu['register_link']; ?>">Register</a></li>
		</ul>
		<?php } ?>
		<?php } ?>

		<?php if($user_menu['is_logged_in']){ ?>
		<li class="nav-item dropdown">
		  <a class="nav-link pe-0 ps-2" id="navbarDropdownUser" role="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
		    <div class="avatar avatar-xl">
		      <img class="rounded-circle" src="<?php echo $user_menu['avatar_url'] ?: PathHelper::getThemeFilePath('avatar.png', 'assets/images', 'web', 'falcon'); ?>" alt="" />
		    </div>
		  </a>
		  <div class="dropdown-menu dropdown-caret dropdown-caret dropdown-menu-end py-0" aria-labelledby="navbarDropdownUser">
		    <div class="bg-white dark__bg-1000 rounded-2 py-2">
		      <?php
		      // Render user menu items from menu data
		      foreach($user_menu['items'] as $item) {
		        // Only show user profile items (not admin items)
		        if(!in_array($item['label'], ['Admin Dashboard', 'Admin Settings', 'Admin Utilities', 'Admin Help'])) {
		          echo '<a class="dropdown-item" href="' . $item['link'] . '">' . $item['label'] . '</a>';
		        }
		      }
		      ?>
		    </div>
		  </div>
		</li>
		<?php }

	}

	/**
	 * Get SVG icon for admin menu items - Falcon theme specific
	 */
	private function get_admin_icon_svg($icon_name) {
		$icons = [
			'home' => '<svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9.5L12 3l9 6.5V21a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V9.5z"/></svg>',
			'user' => '<svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>',
			'dashboard' => '<svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 4h18v4H3zM3 10h18v10H3z"/></svg>',
			'wrench' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/><path d="M19.4 15a1.7 1.7 0 0 0 .33 1.82l.05.05a2 2 0 1 1-2.82 2.83l-.06-.06a1.7 1.7 0 0 0-1.83-.33 1.7 1.7 0 0 0-1 1.51v.09a2 2 0 1 1-4 0v-.09a1.7 1.7 0 0 0-1-1.51 1.7 1.7 0 0 0-1.83.33l-.06.06a2 2 0 1 1-2.82-2.83l.05-.05a1.7 1.7 0 0 0 .33-1.82 1.7 1.7 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.7 1.7 0 0 0 1.51-1 1.7 1.7 0 0 0-.33-1.82l-.05-.05a2 2 0 1 1 2.82-2.83l.06.06a1.7 1.7 0 0 0 1.83.33h.09A1.7 1.7 0 0 0 9 3.09V3a2 2 0 1 1 4 0v.09a1.7 1.7 0 0 0 1 1.51h.09a1.7 1.7 0 0 0 1.83-.33l.06-.06a2 2 0 1 1 2.82 2.83l-.05.05a1.7 1.7 0 0 0-.33 1.82 1.7 1.7 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.51 1z"/></svg>',
			'tools' => '<svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 3h6v6H3zM15 3h6v6h-6zM15 15h6v6h-6zM3 15h6v6H3z"/></svg>',
			'question-circle' => '<svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 1 1 5.83 1c-.26 1.2-1.5 2-2.92 2v1"/><circle cx="12" cy="17" r="1"/></svg>'
		];

		return $icons[$icon_name] ?? $icons['dashboard']; // Default to dashboard icon
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
	
	public function get_logo($choice='all'){
			$settings = Globalvars::get_instance();
			
			// Check if we're in admin context by looking for vertical_menu in the call stack
			$is_admin = false;
			$backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
			foreach($backtrace as $trace) {
				if(isset($trace['function']) && $trace['function'] === 'admin_header') {
					$is_admin = true;
					break;
				}
			}
			
			// Only show logo image for non-admin contexts
			if(!$is_admin && $settings->get_setting('logo_link')){
				echo '<img class="me-2" src="'.$settings->get_setting('logo_link').'" alt="" width="40" />';
			}
			 
			// Use smaller font size for admin contexts
			if($is_admin) {
				echo '<span class="font-sans-serif text-primary" style="font-size: 0.95rem;">';
			} else {
				echo '<span class="font-sans-serif text-primary">';
			}
			
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
			

		?>

<!DOCTYPE html>
<html data-bs-theme="light" lang="en-US" dir="ltr">

  <head>
  
  		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<meta name="description" content="<?php echo $options['description'] ?? ''; ?>">
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
	<script src="<?php echo PathHelper::getThemeFilePath('simplebar.min.js', 'assets/vendors/simplebar', 'web', 'falcon'); ?>"></script>


    <!-- ===============================================-->
    <!--    Stylesheets-->
    <!-- ===============================================-->
    <link rel="preconnect" href="https://fonts.gstatic.com">
    <link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,500,600,700%7cPoppins:300,400,500,600,700,800,900&amp;display=swap" rel="stylesheet">



    <!-- Jquery -->
	<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>	
	
	<link rel="stylesheet" type="text/css" id="stylesheet" href="<?php echo PathHelper::getThemeFilePath('simplebar.min.css', 'assets/vendors/simplebar', 'web', 'falcon'); ?>">
	<link rel="stylesheet" type="text/css" id="style-default" href="<?php echo PathHelper::getThemeFilePath('theme.css', 'assets/css', 'web', 'falcon'); ?>">
	<link rel="stylesheet" type="text/css" id="user-style-default" href="<?php echo PathHelper::getThemeFilePath('user_exceptions.css', 'assets/css', 'web', 'falcon'); ?>">
	
	

	<?php
	if($settings->get_setting('custom_css')){
		echo '<style>'.$settings->get_setting('custom_css').'</style>';
	}
	?>		
  </head>


  <body>
	<?php
	if(isset($options['header_only']) && $options['header_only']){
		return;
	}
	?>
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
	

		 <?php 
		  if($options['vertical_menu']){
			?>
			<div class="content">
			 
				<!-- Combo Top and Vertical Nav OR Just Vertical Nav -->
			  <nav class="navbar navbar-light navbar-glass navbar-top navbar-expand-lg" data-move-target="#navbarVerticalNav" data-navbar-top="combo">
				<button
				  class="btn navbar-toggler-humburger-icon navbar-toggler me-1 me-sm-3"
				  type="button"
				  data-bs-toggle="collapse"
				  data-bs-target=".navbar-collapse"
				  aria-controls="navbarStandard"
				  aria-expanded="false"
				  aria-label="Toggle navigation"
				>
				  <span class="navbar-toggle-icon"><span class="toggle-line"></span></span>
				</button>
				<?php 
			}
			else{
				?>
				<div class="content">
				
				<!-- Top Nav Only-->
				
				<nav class="navbar navbar-light navbar-glass navbar-top navbar-expand-lg">
				<button class="btn navbar-toggler-humburger-icon navbar-toggler me-1 me-sm-3" 
				type="button" 
				data-bs-toggle="collapse" 
				data-bs-target="#navbarStandard" 
				aria-controls="navbarStandard" 
				aria-expanded="false" 
				aria-label="Toggle Navigation"
				>
				<span class="navbar-toggle-icon"><span class="toggle-line"></span></span>
				</button>
				
				<?php 
			}
			?>
			
		

			
			
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
				$menu_data = $this->get_menu_data();
				$menus = $menu_data['main_menu'];
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
		  <?php } 
		  ?>
            <ul class="navbar-nav navbar-nav-icons ms-auto flex-row align-items-center">
              <?php $this->top_right_menu(); ?>
            </ul>
          </nav>

	<?php 
	}

	public function public_footer($options=array()) {
	
	
	
	if(!isset($options['header_only']) || !$options['header_only']){
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
	<?php } ?>

    


    <!-- ===============================================-->
    <!--    JavaScripts-->
    <!-- ===============================================-->
	
	<script src="/assets/js/joinery-validate.js"></script>
	<script src="<?php echo PathHelper::getThemeFilePath('popper.min.js', 'assets/vendors/popper', 'web', 'falcon'); ?>"></script>
	<script src="<?php echo PathHelper::getThemeFilePath('bootstrap.min.js', 'assets/vendors/bootstrap', 'web', 'falcon'); ?>"></script>
	<script src="<?php echo PathHelper::getThemeFilePath('anchor.min.js', 'assets/vendors/anchorjs', 'web', 'falcon'); ?>"></script>
	<script src="<?php echo PathHelper::getThemeFilePath('is.min.js', 'assets/vendors/is', 'web', 'falcon'); ?>"></script>
    <script src="<?php echo PathHelper::getThemeFilePath('all.min.js', 'assets/vendors/fontawesome', 'web', 'falcon'); ?>"></script>
	<script src="<?php echo PathHelper::getThemeFilePath('lodash.min.js', 'assets/vendors/lodash', 'web', 'falcon'); ?>"></script>
	<script src="<?php echo PathHelper::getThemeFilePath('list.min.js', 'assets/vendors/list.js', 'web', 'falcon'); ?>"></script>
	<script src="<?php echo PathHelper::getThemeFilePath('theme.js', 'assets/js', 'web', 'falcon'); ?>"></script>
	


  </body>
</html>



		<?php
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
