<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/PublicPageMaster.php');

class PublicPageTW extends PublicPageMaster {

	public static function OutputGenericPublicPage($title, $header, $body, $options=array()) {
		$page = new PublicPageTW();
		$page->public_header(
			array_merge(
				array(
					'title' => $title,
					'showheader' => TRUE
				),
				$options));
		echo PublicPageTW::BeginPage($title);
		echo PublicPageTW::BeginPanel();
		echo '<div class="text-lg max-w-prose mx-auto">';
		echo '<div>'.$body.'</div>';
		echo '</div>';
		
		echo PublicPageTW::EndPanel();
		echo PublicPageTW::EndPage();
		$page->public_footer();
		exit;
	}
	
	public static function BeginPage($title='', $options=array()) {
		
		$output = '';
		$output .= '  <div class="py-10">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">';
      $output .= '<main class="lg:col-span-9 xl:col-span-6">
        <div class="px-4 sm:px-0">';
		
		
		if($options['breadcrumbs']){
			echo '		
			<div class="max-w-7xl mx-auto px-4 sm:px-6">
			  <div class="border-t border-gray-200 py-3">
				<nav class="flex" aria-label="Breadcrumb">';
				
			
			echo '
			  <div class="flex sm:hidden">
				<a href="/" class="group inline-flex space-x-3 text-sm font-medium text-gray-500 hover:text-gray-700">
				  <!-- Heroicon name: solid/arrow-narrow-left -->
				  <svg class="flex-shrink-0 h-5 w-5 text-gray-400 group-hover:text-gray-600" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
					<path fill-rule="evenodd" d="M7.707 14.707a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l2.293 2.293a1 1 0 010 1.414z" clip-rule="evenodd" />
				  </svg>
				  <span>Back to Home</span>
				</a>
			  </div>
			  <div class="hidden sm:block">
				<ol role="list" class="flex items-center space-x-4">
				  <li>
					<div>
					  <a href="/" class="text-gray-400 hover:text-gray-500">
						<!-- Heroicon name: solid/home -->
						<svg class="flex-shrink-0 h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
						  <path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z" />
						</svg>
						<span class="sr-only">Home</span>
					  </a>
					</div>
				  </li>';
				  
				  foreach ($options['breadcrumbs'] as $breadcrumb=>$link){
					echo '				  
					<li>
						<div class="flex items-center">
						  <svg class="flex-shrink-0 h-5 w-5 text-gray-300" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
							<path d="M5.555 17.776l8-16 .894.448-8 16-.894-.448z" />
						  </svg>
						  <a href="'.$link.'" class="ml-4 text-sm font-medium text-gray-500 hover:text-gray-700">'.$breadcrumb.'</a>
						</div>
					  </li>';
				  }



				
				echo '</ol>';
				

		echo '
			  </div>
			</nav>
		  </div>
		</div>
		';
			
		}


		
		if($title){
			$output .= '<div class="py-10 text-center relative">
				<div class="max-w-7xl mx-auto sm:px-6 lg:px-8"><h1 class="flex-1 text-3xl font-bold text-gray-900 mb-6">'.$title.'</h1>';
		}
				
		if($options['subtitle']){
			$output .= '<p class="mt-4 text-lg text-gray-500">'.$options['subtitle'].'</p>';
		}

		$output .= '</div></div>';
		
				
						
		return $output;
	}	

	public static function EndPage($options=array()) {
		$output = '</div>

      </main>
	</div>
  </div>'; 
		return $output;
	}	

	public static function BeginPanel($options=array()) {
		$output = '<div class="relative py-16 bg-white rounded-lg shadow-lg overflow-hidden">
  <div class="relative px-4 sm:px-6 lg:px-8">'; 
		return $output;
	}



	public static function EndPanel($options=array()) {
		$output = '
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
			$logged_out_menu['Sign up'] = '/register';	
		}	

		$cart = $session->get_shopping_cart();
		if($numitems = $cart->count_items()){
			$cart_menu = array('Cart' => '/cart');

		}
		else{
			$cart_menu = NULL;
		}
		
		$notification_menu = NULL;

		$menus = PublicPageTW::get_public_menu();
			

		?>
		
<!DOCTYPE html>
<html class="h-full" lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:fb="http://ogp.me/ns/fb#">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
		<meta name="description" content="<?php echo $options['description']; ?>">
        <meta name="keywords" content="">

		<title><?php echo $options['title']; ?></title>
				<?php $this->global_includes_top($options); ?>
				
		<!-- Favicon -->
		<!--
        <link href="../assets/images/favicon.png" rel="shortcut icon">
		<link rel="icon" href="/theme/integralzen/images/cropped-IZ-Icon-07-32x32.png" sizes="32x32" />
		<link rel="icon" href="/theme/integralzen/images/cropped-IZ-Icon-07-192x192.png" sizes="192x192" />
		<link rel="apple-touch-icon-precomposed" href="/theme/integralzen/images/cropped-IZ-Icon-07-180x180.png" />
		<meta name="msapplication-TileImage" content="/theme/integralzen/images/cropped-IZ-Icon-07-270x270.png" />	
		-->
		
		<!-- CSS -->

		
		<link href="<?php echo $this->theme_url; ?>/includes/assets/plugins/owl-carousel/owl.carousel.min.css" rel="stylesheet">
		<link href="<?php echo $this->theme_url; ?>/includes/assets/plugins/owl-carousel/owl.theme.default.min.css" rel="stylesheet">

		
		<link rel="stylesheet" type="text/css" href="<?php echo $this->theme_url; ?>/includes/output.css" >
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
	
	<script language="javascript">
	 $(document).ready(function() {	
		$('.js-clickable-menu').click(function() { 
		 var clicked_menu = $(this).nextAll('.js-clicked-menu');
		 clicked_menu.toggleClass('invisible');
		 $('.js-clicked-menu').not(clicked_menu).addClass('invisible');
		 event.stopPropagation();
		});
		
		$('#user-menu-button').click(function() { 
		 $('#user-menu').toggleClass('invisible');
		 event.stopPropagation();
		});	
	
		$('#mobile-toggle-button').click(function() { 
			$('#mobile-menu').removeClass('invisible');
		});	

		$('#mobile-close-button').click(function() { 
		 $('#mobile-menu').addClass('invisible');
		});	
	
		$('html').click(function() {
			$('.js-clicked-menu').addClass('invisible');
		});
	});
	</script>
	
	<?php	
	
	if(empty($options['noheader'])){
		?>	
	<body class="h-full">
<div class="min-h-full">

<!-- This example requires Tailwind CSS v2.0+ -->
<div class="bg-gray-50">
  <div class="relative bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6">
      <div class="flex justify-between items-center py-6 md:justify-start md:space-x-10">
        <div class="flex justify-start lg:w-0 lg:flex-1">
          <a href="#">
            <!--<span class="sr-only">Workflow</span>-->
			<?php 
			echo '<span class="sr-only">'.$settings->get_setting('site_name').'</span>';
			if($settings->get_setting('logo_link')){
				echo '<a href="/"><img class="h-14 w-auto sm:h-10" src="'.$settings->get_setting('logo_link').'" alt=""></a>';
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
				if($menu[parent] == true){
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
          <a href="/register" class="whitespace-nowrap bg-blue-100 border border-transparent rounded-md py-2 px-4 inline-flex items-center justify-center text-base font-medium text-blue-700 hover:bg-blue-200">
            Sign up
          </a>
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
				if($menu[parent] == true){
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
            <a href="/register" class="w-full flex items-center justify-center px-4 py-2 border border-transparent rounded-md shadow-sm text-base font-medium text-white bg-blue-600 hover:bg-blue-700">
              Sign up
            </a>
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
	}

	public function public_footer($options=array()) {
		$session = SessionControl::get_instance();
		$session->clear_clearable_messages();
	
		?>
<footer class="bg-white">
  <div class="max-w-7xl mx-auto py-12 px-4 overflow-hidden sm:px-6 lg:px-8">
    <!--<nav class="-mx-5 -my-2 flex flex-wrap justify-center" aria-label="Footer">
      <div class="px-5 py-2">
        <a href="#" class="text-base text-gray-500 hover:text-gray-900">
          About
        </a>
      </div>

      <div class="px-5 py-2">
        <a href="#" class="text-base text-gray-500 hover:text-gray-900">
          Blog
        </a>
      </div>

      <div class="px-5 py-2">
        <a href="#" class="text-base text-gray-500 hover:text-gray-900">
          Jobs
        </a>
      </div>

      <div class="px-5 py-2">
        <a href="#" class="text-base text-gray-500 hover:text-gray-900">
          Press
        </a>
      </div>

      <div class="px-5 py-2">
        <a href="#" class="text-base text-gray-500 hover:text-gray-900">
          Accessibility
        </a>
      </div>

      <div class="px-5 py-2">
        <a href="#" class="text-base text-gray-500 hover:text-gray-900">
          Partners
        </a>
      </div>
    </nav>-->
	<!--
    <div class="mt-8 flex justify-center space-x-6">
      <a href="#" class="text-gray-400 hover:text-gray-500">
        <span class="sr-only">Facebook</span>
        <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path fill-rule="evenodd" d="M22 12c0-5.523-4.477-10-10-10S2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.878v-6.987h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.988C18.343 21.128 22 16.991 22 12z" clip-rule="evenodd" />
        </svg>
      </a>

      <a href="#" class="text-gray-400 hover:text-gray-500">
        <span class="sr-only">Instagram</span>
        <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path fill-rule="evenodd" d="M12.315 2c2.43 0 2.784.013 3.808.06 1.064.049 1.791.218 2.427.465a4.902 4.902 0 011.772 1.153 4.902 4.902 0 011.153 1.772c.247.636.416 1.363.465 2.427.048 1.067.06 1.407.06 4.123v.08c0 2.643-.012 2.987-.06 4.043-.049 1.064-.218 1.791-.465 2.427a4.902 4.902 0 01-1.153 1.772 4.902 4.902 0 01-1.772 1.153c-.636.247-1.363.416-2.427.465-1.067.048-1.407.06-4.123.06h-.08c-2.643 0-2.987-.012-4.043-.06-1.064-.049-1.791-.218-2.427-.465a4.902 4.902 0 01-1.772-1.153 4.902 4.902 0 01-1.153-1.772c-.247-.636-.416-1.363-.465-2.427-.047-1.024-.06-1.379-.06-3.808v-.63c0-2.43.013-2.784.06-3.808.049-1.064.218-1.791.465-2.427a4.902 4.902 0 011.153-1.772A4.902 4.902 0 015.45 2.525c.636-.247 1.363-.416 2.427-.465C8.901 2.013 9.256 2 11.685 2h.63zm-.081 1.802h-.468c-2.456 0-2.784.011-3.807.058-.975.045-1.504.207-1.857.344-.467.182-.8.398-1.15.748-.35.35-.566.683-.748 1.15-.137.353-.3.882-.344 1.857-.047 1.023-.058 1.351-.058 3.807v.468c0 2.456.011 2.784.058 3.807.045.975.207 1.504.344 1.857.182.466.399.8.748 1.15.35.35.683.566 1.15.748.353.137.882.3 1.857.344 1.054.048 1.37.058 4.041.058h.08c2.597 0 2.917-.01 3.96-.058.976-.045 1.505-.207 1.858-.344.466-.182.8-.398 1.15-.748.35-.35.566-.683.748-1.15.137-.353.3-.882.344-1.857.048-1.055.058-1.37.058-4.041v-.08c0-2.597-.01-2.917-.058-3.96-.045-.976-.207-1.505-.344-1.858a3.097 3.097 0 00-.748-1.15 3.098 3.098 0 00-1.15-.748c-.353-.137-.882-.3-1.857-.344-1.023-.047-1.351-.058-3.807-.058zM12 6.865a5.135 5.135 0 110 10.27 5.135 5.135 0 010-10.27zm0 1.802a3.333 3.333 0 100 6.666 3.333 3.333 0 000-6.666zm5.338-3.205a1.2 1.2 0 110 2.4 1.2 1.2 0 010-2.4z" clip-rule="evenodd" />
        </svg>
      </a>

      <a href="#" class="text-gray-400 hover:text-gray-500">
        <span class="sr-only">Twitter</span>
        <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M8.29 20.251c7.547 0 11.675-6.253 11.675-11.675 0-.178 0-.355-.012-.53A8.348 8.348 0 0022 5.92a8.19 8.19 0 01-2.357.646 4.118 4.118 0 001.804-2.27 8.224 8.224 0 01-2.605.996 4.107 4.107 0 00-6.993 3.743 11.65 11.65 0 01-8.457-4.287 4.106 4.106 0 001.27 5.477A4.072 4.072 0 012.8 9.713v.052a4.105 4.105 0 003.292 4.022 4.095 4.095 0 01-1.853.07 4.108 4.108 0 003.834 2.85A8.233 8.233 0 012 18.407a11.616 11.616 0 006.29 1.84" />
        </svg>
      </a>

      <a href="#" class="text-gray-400 hover:text-gray-500">
        <span class="sr-only">GitHub</span>
        <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path fill-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z" clip-rule="evenodd" />
        </svg>
      </a>

      <a href="#" class="text-gray-400 hover:text-gray-500">
        <span class="sr-only">Dribbble</span>
        <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path fill-rule="evenodd" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10c5.51 0 10-4.48 10-10S17.51 2 12 2zm6.605 4.61a8.502 8.502 0 011.93 5.314c-.281-.054-3.101-.629-5.943-.271-.065-.141-.12-.293-.184-.445a25.416 25.416 0 00-.564-1.236c3.145-1.28 4.577-3.124 4.761-3.362zM12 3.475c2.17 0 4.154.813 5.662 2.148-.152.216-1.443 1.941-4.48 3.08-1.399-2.57-2.95-4.675-3.189-5A8.687 8.687 0 0112 3.475zm-3.633.803a53.896 53.896 0 013.167 4.935c-3.992 1.063-7.517 1.04-7.896 1.04a8.581 8.581 0 014.729-5.975zM3.453 12.01v-.26c.37.01 4.512.065 8.775-1.215.25.477.477.965.694 1.453-.109.033-.228.065-.336.098-4.404 1.42-6.747 5.303-6.942 5.629a8.522 8.522 0 01-2.19-5.705zM12 20.547a8.482 8.482 0 01-5.239-1.8c.152-.315 1.888-3.656 6.703-5.337.022-.01.033-.01.054-.022a35.318 35.318 0 011.823 6.475 8.4 8.4 0 01-3.341.684zm4.761-1.465c-.086-.52-.542-3.015-1.659-6.084 2.679-.423 5.022.271 5.314.369a8.468 8.468 0 01-3.655 5.715z" clip-rule="evenodd" />
        </svg>
      </a>
    </div>-->
    <p class="mt-8 text-center text-base text-gray-400">
      &copy; 2022 Joinery, Inc. All rights reserved.
    </p>
  </div>
</footer>		
		
		
		
		</div>

	</body>
</html>
		<?php
	}

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


	function tableheader($headers, $class='', $id='table1'){
		echo '<div class="my-6"><div class="flex flex-col">
		  <div class="-my-2 overflow-x-auto sm:-mx-6 lg:-mx-8">
			<div class="py-2 align-middle inline-block min-w-full sm:px-6 lg:px-8">
			  <div class="shadow overflow-hidden border-b border-gray-200 sm:rounded-lg">
				<table class="min-w-full divide-y divide-gray-200">
				  <thead class="bg-gray-50">
					<tr>';
		

		foreach ($headers as $value) {
			printf('<th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider" abbr="%s">%s</th>', $value, $value);
		}
		echo '</tr>
          </thead><tbody class="bg-white divide-y divide-gray-200">';
	}

	function disprow($dataarray){

		echo '<tr>';

		foreach ($dataarray as $value) {
			if ($value == "") {
				$value = "&nbsp";
			}


			printf('<td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">%s</td>', $value);

		}
		echo "</tr>\n";
		$this->rowcount++;
	}

	function endtable(){
		echo '</tbody></table>      </div>
    </div>
  </div>
</div></div>';
	}
}

?>
