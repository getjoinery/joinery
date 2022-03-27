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
		else{
			$output .= '<div class="text-center relative">
				<div class="max-w-7xl mx-auto sm:px-6 lg:px-8">';			
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
		$settings = Globalvars::get_instance();
	
		?>
<footer class="bg-white">
  <div class="max-w-7xl mx-auto py-12 px-4 overflow-hidden sm:px-6 lg:px-8">
    <div class="flex justify-center space-x-2 text-gray-700">

	<?php
	if($link = $settings->get_setting('social_discord_link')){
	?>
  <!-- Discord -->
  <a href="<?php echo $link; ?>">
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 512" class="w-5 h-5"><!--! Font Awesome Pro 6.0.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2022 Fonticons, Inc. --><path fill="currentColor" d="M524.531,69.836a1.5,1.5,0,0,0-.764-.7A485.065,485.065,0,0,0,404.081,32.03a1.816,1.816,0,0,0-1.923.91,337.461,337.461,0,0,0-14.9,30.6,447.848,447.848,0,0,0-134.426,0,309.541,309.541,0,0,0-15.135-30.6,1.89,1.89,0,0,0-1.924-.91A483.689,483.689,0,0,0,116.085,69.137a1.712,1.712,0,0,0-.788.676C39.068,183.651,18.186,294.69,28.43,404.354a2.016,2.016,0,0,0,.765,1.375A487.666,487.666,0,0,0,176.02,479.918a1.9,1.9,0,0,0,2.063-.676A348.2,348.2,0,0,0,208.12,430.4a1.86,1.86,0,0,0-1.019-2.588,321.173,321.173,0,0,1-45.868-21.853,1.885,1.885,0,0,1-.185-3.126c3.082-2.309,6.166-4.711,9.109-7.137a1.819,1.819,0,0,1,1.9-.256c96.229,43.917,200.41,43.917,295.5,0a1.812,1.812,0,0,1,1.924.233c2.944,2.426,6.027,4.851,9.132,7.16a1.884,1.884,0,0,1-.162,3.126,301.407,301.407,0,0,1-45.89,21.83,1.875,1.875,0,0,0-1,2.611,391.055,391.055,0,0,0,30.014,48.815,1.864,1.864,0,0,0,2.063.7A486.048,486.048,0,0,0,610.7,405.729a1.882,1.882,0,0,0,.765-1.352C623.729,277.594,590.933,167.465,524.531,69.836ZM222.491,337.58c-28.972,0-52.844-26.587-52.844-59.239S193.056,219.1,222.491,219.1c29.665,0,53.306,26.82,52.843,59.239C275.334,310.993,251.924,337.58,222.491,337.58Zm195.38,0c-28.971,0-52.843-26.587-52.843-59.239S388.437,219.1,417.871,219.1c29.667,0,53.307,26.82,52.844,59.239C470.715,310.993,447.538,337.58,417.871,337.58Z"/></svg>
  </a>
	</a>
	<?php } ?>

	<?php
	if($link = $settings->get_setting('social_facebook_link')){
	?>	
  <!-- Facebook -->
  <a href="<?php echo $link; ?>">
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 512" class="w-5 h-5"><!--! Font Awesome Pro 6.0.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2022 Fonticons, Inc. --><path fill="currentColor" d="M279.14 288l14.22-92.66h-88.91v-60.13c0-25.35 12.42-50.06 52.24-50.06h40.42V6.26S260.43 0 225.36 0c-73.22 0-121.08 44.38-121.08 124.72v70.62H22.89V288h81.39v224h100.17V288z"/></svg>
  </a>
<?php } ?>

	<?php
	if($link = $settings->get_setting('social_messenger_link')){
	?>	
  <!-- Messenger -->
  <a href="<?php echo $link; ?>">
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" class="w-5 h-5"><!--! Font Awesome Pro 6.0.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2022 Fonticons, Inc. --><path fill="currentColor" d="M256.55 8C116.52 8 8 110.34 8 248.57c0 72.3 29.71 134.78 78.07 177.94 8.35 7.51 6.63 11.86 8.05 58.23A19.92 19.92 0 0 0 122 502.31c52.91-23.3 53.59-25.14 62.56-22.7C337.85 521.8 504 423.7 504 248.57 504 110.34 396.59 8 256.55 8zm149.24 185.13l-73 115.57a37.37 37.37 0 0 1-53.91 9.93l-58.08-43.47a15 15 0 0 0-18 0l-78.37 59.44c-10.46 7.93-24.16-4.6-17.11-15.67l73-115.57a37.36 37.36 0 0 1 53.91-9.93l58.06 43.46a15 15 0 0 0 18 0l78.41-59.38c10.44-7.98 24.14 4.54 17.09 15.62z"/></svg>
  </a>
<?php } ?>

	<?php
	if($link = $settings->get_setting('social_twitter_link')){
	?>	
  <!-- Twitter -->
  <a href="<?php echo $link; ?>">
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" class="w-5 h-5"><!--! Font Awesome Pro 6.0.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2022 Fonticons, Inc. --><path fill="currentColor" d="M459.37 151.716c.325 4.548.325 9.097.325 13.645 0 138.72-105.583 298.558-298.558 298.558-59.452 0-114.68-17.219-161.137-47.106 8.447.974 16.568 1.299 25.34 1.299 49.055 0 94.213-16.568 130.274-44.832-46.132-.975-84.792-31.188-98.112-72.772 6.498.974 12.995 1.624 19.818 1.624 9.421 0 18.843-1.3 27.614-3.573-48.081-9.747-84.143-51.98-84.143-102.985v-1.299c13.969 7.797 30.214 12.67 47.431 13.319-28.264-18.843-46.781-51.005-46.781-87.391 0-19.492 5.197-37.36 14.294-52.954 51.655 63.675 129.3 105.258 216.365 109.807-1.624-7.797-2.599-15.918-2.599-24.04 0-57.828 46.782-104.934 104.934-104.934 30.213 0 57.502 12.67 76.67 33.137 23.715-4.548 46.456-13.32 66.599-25.34-7.798 24.366-24.366 44.833-46.132 57.827 21.117-2.273 41.584-8.122 60.426-16.243-14.292 20.791-32.161 39.308-52.628 54.253z"/></svg>
  </a>
<?php } ?>

	<?php
	if($link = $settings->get_setting('social_google_link')){
	?>	
  <!-- Google -->
  <a href="<?php echo $link; ?>">
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 488 512" class="w-5 h-5"><!--! Font Awesome Pro 6.0.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2022 Fonticons, Inc. --><path fill="currentColor" d="M488 261.8C488 403.3 391.1 504 248 504 110.8 504 0 393.2 0 256S110.8 8 248 8c66.8 0 123 24.5 166.3 64.9l-67.5 64.9C258.5 52.6 94.3 116.6 94.3 256c0 86.5 69.1 156.6 153.7 156.6 98.2 0 135-70.4 140.8-106.9H248v-85.3h236.1c2.3 12.7 3.9 24.9 3.9 41.4z"/></svg>
  </a>
<?php } ?>

	<?php
	if($link = $settings->get_setting('social_instagram_link')){
	?>	
  <!-- Instagram -->
  <a href="<?php echo $link; ?>">
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512" class="w-5 h-5"><!--! Font Awesome Pro 6.0.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2022 Fonticons, Inc. --><path fill="currentColor" d="M224.1 141c-63.6 0-114.9 51.3-114.9 114.9s51.3 114.9 114.9 114.9S339 319.5 339 255.9 287.7 141 224.1 141zm0 189.6c-41.1 0-74.7-33.5-74.7-74.7s33.5-74.7 74.7-74.7 74.7 33.5 74.7 74.7-33.6 74.7-74.7 74.7zm146.4-194.3c0 14.9-12 26.8-26.8 26.8-14.9 0-26.8-12-26.8-26.8s12-26.8 26.8-26.8 26.8 12 26.8 26.8zm76.1 27.2c-1.7-35.9-9.9-67.7-36.2-93.9-26.2-26.2-58-34.4-93.9-36.2-37-2.1-147.9-2.1-184.9 0-35.8 1.7-67.6 9.9-93.9 36.1s-34.4 58-36.2 93.9c-2.1 37-2.1 147.9 0 184.9 1.7 35.9 9.9 67.7 36.2 93.9s58 34.4 93.9 36.2c37 2.1 147.9 2.1 184.9 0 35.9-1.7 67.7-9.9 93.9-36.2 26.2-26.2 34.4-58 36.2-93.9 2.1-37 2.1-147.8 0-184.8zM398.8 388c-7.8 19.6-22.9 34.7-42.6 42.6-29.5 11.7-99.5 9-132.1 9s-102.7 2.6-132.1-9c-19.6-7.8-34.7-22.9-42.6-42.6-11.7-29.5-9-99.5-9-132.1s-2.6-102.7 9-132.1c7.8-19.6 22.9-34.7 42.6-42.6 29.5-11.7 99.5-9 132.1-9s102.7-2.6 132.1 9c19.6 7.8 34.7 22.9 42.6 42.6 11.7 29.5 9 99.5 9 132.1s2.7 102.7-9 132.1z"/></svg>
  </a>
<?php } ?>

	<?php
	if($link = $settings->get_setting('social_linkedin_link')){
	?>	
  <!-- Linkedin -->
  <a href="<?php echo $link; ?>">
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512" class="w-5 h-5"><!--! Font Awesome Pro 6.0.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2022 Fonticons, Inc. --><path fill="currentColor" d="M100.28 448H7.4V148.9h92.88zM53.79 108.1C24.09 108.1 0 83.5 0 53.8a53.79 53.79 0 0 1 107.58 0c0 29.7-24.1 54.3-53.79 54.3zM447.9 448h-92.68V302.4c0-34.7-.7-79.2-48.29-79.2-48.29 0-55.69 37.7-55.69 76.7V448h-92.78V148.9h89.08v40.8h1.3c12.4-23.5 42.69-48.3 87.88-48.3 94 0 111.28 61.9 111.28 142.3V448z"/></svg>
  </a>
<?php } ?>

	<?php
	if($link = $settings->get_setting('social_pinterest_link')){
	?>	
  <!-- Pinterest -->
  <a href="<?php echo $link; ?>">
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 384 512" class="w-5 h-5"><!--! Font Awesome Pro 6.0.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2022 Fonticons, Inc. --><path fill="currentColor" d="M204 6.5C101.4 6.5 0 74.9 0 185.6 0 256 39.6 296 63.6 296c9.9 0 15.6-27.6 15.6-35.4 0-9.3-23.7-29.1-23.7-67.8 0-80.4 61.2-137.4 140.4-137.4 68.1 0 118.5 38.7 118.5 109.8 0 53.1-21.3 152.7-90.3 152.7-24.9 0-46.2-18-46.2-43.8 0-37.8 26.4-74.4 26.4-113.4 0-66.2-93.9-54.2-93.9 25.8 0 16.8 2.1 35.4 9.6 50.7-13.8 59.4-42 147.9-42 209.1 0 18.9 2.7 37.5 4.5 56.4 3.4 3.8 1.7 3.4 6.9 1.5 50.4-69 48.6-82.5 71.4-172.8 12.3 23.4 44.1 36 69.3 36 106.2 0 153.9-103.5 153.9-196.8C384 71.3 298.2 6.5 204 6.5z"/></svg>
  </a>
<?php } ?>
	<?php
	if($link = $settings->get_setting('social_stack_link')){
	?>	
  <!-- Stack overflow -->
  <a href="<?php echo $link; ?>">
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 384 512" class="w-5 h-5"><!--! Font Awesome Pro 6.0.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2022 Fonticons, Inc. --><path fill="currentColor" d="M290.7 311L95 269.7 86.8 309l195.7 41zm51-87L188.2 95.7l-25.5 30.8 153.5 128.3zm-31.2 39.7L129.2 179l-16.7 36.5L293.7 300zM262 32l-32 24 119.3 160.3 32-24zm20.5 328h-200v39.7h200zm39.7 80H42.7V320h-40v160h359.5V320h-40z"/></svg>
  </a>
<?php } ?>

	<?php
	if($link = $settings->get_setting('social_telegram_link')){
	?>	
  <!-- Telegram -->
  <a href="<?php echo $link; ?>">
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 496 512" class="w-5 h-5"><!--! Font Awesome Pro 6.0.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2022 Fonticons, Inc. --><path fill="currentColor" d="M248,8C111.033,8,0,119.033,0,256S111.033,504,248,504,496,392.967,496,256,384.967,8,248,8ZM362.952,176.66c-3.732,39.215-19.881,134.378-28.1,178.3-3.476,18.584-10.322,24.816-16.948,25.425-14.4,1.326-25.338-9.517-39.287-18.661-21.827-14.308-34.158-23.215-55.346-37.177-24.485-16.135-8.612-25,5.342-39.5,3.652-3.793,67.107-61.51,68.335-66.746.153-.655.3-3.1-1.154-4.384s-3.59-.849-5.135-.5q-3.283.746-104.608,69.142-14.845,10.194-26.894,9.934c-8.855-.191-25.888-5.006-38.551-9.123-15.531-5.048-27.875-7.717-26.8-16.291q.84-6.7,18.45-13.7,108.446-47.248,144.628-62.3c68.872-28.647,83.183-33.623,92.511-33.789,2.052-.034,6.639.474,9.61,2.885a10.452,10.452,0,0,1,3.53,6.716A43.765,43.765,0,0,1,362.952,176.66Z"/></svg>
  </a>
<?php } ?>

	<?php
	if($link = $settings->get_setting('social_youtube_link')){
	?>	
  <!-- Youtube -->
  <a href="<?php echo $link; ?>">
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512" class="w-5 h-5"><!--! Font Awesome Pro 6.0.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2022 Fonticons, Inc. --><path fill="currentColor" d="M549.655 124.083c-6.281-23.65-24.787-42.276-48.284-48.597C458.781 64 288 64 288 64S117.22 64 74.629 75.486c-23.497 6.322-42.003 24.947-48.284 48.597-11.412 42.867-11.412 132.305-11.412 132.305s0 89.438 11.412 132.305c6.281 23.65 24.787 41.5 48.284 47.821C117.22 448 288 448 288 448s170.78 0 213.371-11.486c23.497-6.321 42.003-24.171 48.284-47.821 11.412-42.867 11.412-132.305 11.412-132.305s0-89.438-11.412-132.305zm-317.51 213.508V175.185l142.739 81.205-142.739 81.201z"/></svg>
  </a>
<?php } ?>

	<?php
	if($link = $settings->get_setting('social_tiktok_link')){
	?>	
  <!-- TikTok -->
  <a href="<?php echo $link; ?>">
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512" class="w-5 h-5"><!--! Font Awesome Pro 6.0.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2022 Fonticons, Inc. --><path fill="currentColor" d="M448,209.91a210.06,210.06,0,0,1-122.77-39.25V349.38A162.55,162.55,0,1,1,185,188.31V278.2a74.62,74.62,0,1,0,52.23,71.18V0l88,0a121.18,121.18,0,0,0,1.86,22.17h0A122.18,122.18,0,0,0,381,102.39a121.43,121.43,0,0,0,67,20.14Z"/></svg>
  </a>
<?php } ?>

	<?php
	if($link = $settings->get_setting('social_snapchat_link')){
	?>	
  <!-- Snapchat -->
  <a href="<?php echo $link; ?>">
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" class="w-5 h-5"><!--! Font Awesome Pro 6.0.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2022 Fonticons, Inc. --><path fill="currentColor" d="M496.926,366.6c-3.373-9.176-9.8-14.086-17.112-18.153-1.376-.806-2.641-1.451-3.72-1.947-2.182-1.128-4.414-2.22-6.634-3.373-22.8-12.09-40.609-27.341-52.959-45.42a102.889,102.889,0,0,1-9.089-16.12c-1.054-3.013-1-4.724-.248-6.287a10.221,10.221,0,0,1,2.914-3.038c3.918-2.591,7.96-5.22,10.7-6.993,4.885-3.162,8.754-5.667,11.246-7.44,9.362-6.547,15.909-13.5,20-21.278a42.371,42.371,0,0,0,2.1-35.191c-6.2-16.318-21.613-26.449-40.287-26.449a55.543,55.543,0,0,0-11.718,1.24c-1.029.224-2.059.459-3.063.72.174-11.16-.074-22.94-1.066-34.534-3.522-40.758-17.794-62.123-32.674-79.16A130.167,130.167,0,0,0,332.1,36.443C309.515,23.547,283.91,17,256,17S202.6,23.547,180,36.443a129.735,129.735,0,0,0-33.281,26.783c-14.88,17.038-29.152,38.44-32.673,79.161-.992,11.594-1.24,23.435-1.079,34.533-1-.26-2.021-.5-3.051-.719a55.461,55.461,0,0,0-11.717-1.24c-18.687,0-34.125,10.131-40.3,26.449a42.423,42.423,0,0,0,2.046,35.228c4.105,7.774,10.652,14.731,20.014,21.278,2.48,1.736,6.361,4.24,11.246,7.44,2.641,1.711,6.5,4.216,10.28,6.72a11.054,11.054,0,0,1,3.3,3.311c.794,1.624.818,3.373-.36,6.6a102.02,102.02,0,0,1-8.94,15.785c-12.077,17.669-29.363,32.648-51.434,44.639C32.355,348.608,20.2,352.75,15.069,366.7c-3.868,10.528-1.339,22.506,8.494,32.6a49.137,49.137,0,0,0,12.4,9.387,134.337,134.337,0,0,0,30.342,12.139,20.024,20.024,0,0,1,6.126,2.741c3.583,3.137,3.075,7.861,7.849,14.78a34.468,34.468,0,0,0,8.977,9.127c10.019,6.919,21.278,7.353,33.207,7.811,10.776.41,22.989.881,36.939,5.481,5.778,1.91,11.78,5.605,18.736,9.92C194.842,480.951,217.707,495,255.973,495s61.292-14.123,78.118-24.428c6.907-4.24,12.872-7.9,18.489-9.758,13.949-4.613,26.163-5.072,36.939-5.481,11.928-.459,23.187-.893,33.206-7.812a34.584,34.584,0,0,0,10.218-11.16c3.434-5.84,3.348-9.919,6.572-12.771a18.971,18.971,0,0,1,5.753-2.629A134.893,134.893,0,0,0,476.02,408.71a48.344,48.344,0,0,0,13.019-10.193l.124-.149C498.389,388.5,500.708,376.867,496.926,366.6Zm-34.013,18.277c-20.745,11.458-34.533,10.23-45.259,17.137-9.114,5.865-3.72,18.513-10.342,23.076-8.134,5.617-32.177-.4-63.239,9.858-25.618,8.469-41.961,32.822-88.038,32.822s-62.036-24.3-88.076-32.884c-31-10.255-55.092-4.241-63.239-9.858-6.609-4.563-1.24-17.211-10.341-23.076-10.739-6.907-24.527-5.679-45.26-17.075-13.206-7.291-5.716-11.8-1.314-13.937,75.143-36.381,87.133-92.552,87.666-96.719.645-5.046,1.364-9.014-4.191-14.148-5.369-4.96-29.189-19.7-35.8-24.316-10.937-7.638-15.748-15.264-12.2-24.638,2.48-6.485,8.531-8.928,14.879-8.928a27.643,27.643,0,0,1,5.965.67c12,2.6,23.659,8.617,30.392,10.242a10.749,10.749,0,0,0,2.48.335c3.6,0,4.86-1.811,4.612-5.927-.768-13.132-2.628-38.725-.558-62.644,2.84-32.909,13.442-49.215,26.04-63.636,6.051-6.932,34.484-36.976,88.857-36.976s82.88,29.92,88.931,36.827c12.611,14.421,23.225,30.727,26.04,63.636,2.071,23.919.285,49.525-.558,62.644-.285,4.327,1.017,5.927,4.613,5.927a10.648,10.648,0,0,0,2.48-.335c6.745-1.624,18.4-7.638,30.4-10.242a27.641,27.641,0,0,1,5.964-.67c6.386,0,12.4,2.48,14.88,8.928,3.546,9.374-1.24,17-12.189,24.639-6.609,4.612-30.429,19.343-35.8,24.315-5.568,5.134-4.836,9.1-4.191,14.149.533,4.228,12.511,60.4,87.666,96.718C468.629,373.011,476.119,377.524,462.913,384.877Z"/></svg>
  </a>
<?php } ?>

	<?php
	if($link = $settings->get_setting('social_slack_link')){
	?>	
  <!-- Slack -->
  <a href="<?php echo $link; ?>">
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512" class="w-5 h-5"><!--! Font Awesome Pro 6.0.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2022 Fonticons, Inc. --><path fill="currentColor" d="M94.12 315.1c0 25.9-21.16 47.06-47.06 47.06S0 341 0 315.1c0-25.9 21.16-47.06 47.06-47.06h47.06v47.06zm23.72 0c0-25.9 21.16-47.06 47.06-47.06s47.06 21.16 47.06 47.06v117.84c0 25.9-21.16 47.06-47.06 47.06s-47.06-21.16-47.06-47.06V315.1zm47.06-188.98c-25.9 0-47.06-21.16-47.06-47.06S139 32 164.9 32s47.06 21.16 47.06 47.06v47.06H164.9zm0 23.72c25.9 0 47.06 21.16 47.06 47.06s-21.16 47.06-47.06 47.06H47.06C21.16 243.96 0 222.8 0 196.9s21.16-47.06 47.06-47.06H164.9zm188.98 47.06c0-25.9 21.16-47.06 47.06-47.06 25.9 0 47.06 21.16 47.06 47.06s-21.16 47.06-47.06 47.06h-47.06V196.9zm-23.72 0c0 25.9-21.16 47.06-47.06 47.06-25.9 0-47.06-21.16-47.06-47.06V79.06c0-25.9 21.16-47.06 47.06-47.06 25.9 0 47.06 21.16 47.06 47.06V196.9zM283.1 385.88c25.9 0 47.06 21.16 47.06 47.06 0 25.9-21.16 47.06-47.06 47.06-25.9 0-47.06-21.16-47.06-47.06v-47.06h47.06zm0-23.72c-25.9 0-47.06-21.16-47.06-47.06 0-25.9 21.16-47.06 47.06-47.06h117.84c25.9 0 47.06 21.16 47.06 47.06 0 25.9-21.16 47.06-47.06 47.06H283.1z"/></svg>
  </a>
<?php } ?>

	<?php
	if($link = $settings->get_setting('social_github_link')){
	?>	
  <!-- Github -->
  <a href="<?php echo $link; ?>">
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 496 512" class="w-5 h-5"><!--! Font Awesome Pro 6.0.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2022 Fonticons, Inc. --><path fill="currentColor" d="M165.9 397.4c0 2-2.3 3.6-5.2 3.6-3.3.3-5.6-1.3-5.6-3.6 0-2 2.3-3.6 5.2-3.6 3-.3 5.6 1.3 5.6 3.6zm-31.1-4.5c-.7 2 1.3 4.3 4.3 4.9 2.6 1 5.6 0 6.2-2s-1.3-4.3-4.3-5.2c-2.6-.7-5.5.3-6.2 2.3zm44.2-1.7c-2.9.7-4.9 2.6-4.6 4.9.3 2 2.9 3.3 5.9 2.6 2.9-.7 4.9-2.6 4.6-4.6-.3-1.9-3-3.2-5.9-2.9zM244.8 8C106.1 8 0 113.3 0 252c0 110.9 69.8 205.8 169.5 239.2 12.8 2.3 17.3-5.6 17.3-12.1 0-6.2-.3-40.4-.3-61.4 0 0-70 15-84.7-29.8 0 0-11.4-29.1-27.8-36.6 0 0-22.9-15.7 1.6-15.4 0 0 24.9 2 38.6 25.8 21.9 38.6 58.6 27.5 72.9 20.9 2.3-16 8.8-27.1 16-33.7-55.9-6.2-112.3-14.3-112.3-110.5 0-27.5 7.6-41.3 23.6-58.9-2.6-6.5-11.1-33.3 2.6-67.9 20.9-6.5 69 27 69 27 20-5.6 41.5-8.5 62.8-8.5s42.8 2.9 62.8 8.5c0 0 48.1-33.6 69-27 13.7 34.7 5.2 61.4 2.6 67.9 16 17.7 25.8 31.5 25.8 58.9 0 96.5-58.9 104.2-114.8 110.5 9.2 7.9 17 22.9 17 46.4 0 33.7-.3 75.4-.3 83.6 0 6.5 4.6 14.4 17.3 12.1C428.2 457.8 496 362.9 496 252 496 113.3 383.5 8 244.8 8zM97.2 352.9c-1.3 1-1 3.3.7 5.2 1.6 1.6 3.9 2.3 5.2 1 1.3-1 1-3.3-.7-5.2-1.6-1.6-3.9-2.3-5.2-1zm-10.8-8.1c-.7 1.3.3 2.9 2.3 3.9 1.6 1 3.6.7 4.3-.7.7-1.3-.3-2.9-2.3-3.9-2-.6-3.6-.3-4.3.7zm32.4 35.6c-1.6 1.3-1 4.3 1.3 6.2 2.3 2.3 5.2 2.6 6.5 1 1.3-1.3.7-4.3-1.3-6.2-2.2-2.3-5.2-2.6-6.5-1zm-11.4-14.7c-1.6 1-1.6 3.6 0 5.9 1.6 2.3 4.3 3.3 5.6 2.3 1.6-1.3 1.6-3.9 0-6.2-1.4-2.3-4-3.3-5.6-2z"/></svg>
  </a>
<?php } ?>

	<?php
	if($link = $settings->get_setting('social_reddit_link')){
	?>	
  <!-- Reddit -->
  <a href="<?php echo $link; ?>">
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" class="w-5 h-5"><!--! Font Awesome Pro 6.0.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2022 Fonticons, Inc. --><path fill="currentColor" d="M440.3 203.5c-15 0-28.2 6.2-37.9 15.9-35.7-24.7-83.8-40.6-137.1-42.3L293 52.3l88.2 19.8c0 21.6 17.6 39.2 39.2 39.2 22 0 39.7-18.1 39.7-39.7s-17.6-39.7-39.7-39.7c-15.4 0-28.7 9.3-35.3 22l-97.4-21.6c-4.9-1.3-9.7 2.2-11 7.1L246.3 177c-52.9 2.2-100.5 18.1-136.3 42.8-9.7-10.1-23.4-16.3-38.4-16.3-55.6 0-73.8 74.6-22.9 100.1-1.8 7.9-2.6 16.3-2.6 24.7 0 83.8 94.4 151.7 210.3 151.7 116.4 0 210.8-67.9 210.8-151.7 0-8.4-.9-17.2-3.1-25.1 49.9-25.6 31.5-99.7-23.8-99.7zM129.4 308.9c0-22 17.6-39.7 39.7-39.7 21.6 0 39.2 17.6 39.2 39.7 0 21.6-17.6 39.2-39.2 39.2-22 .1-39.7-17.6-39.7-39.2zm214.3 93.5c-36.4 36.4-139.1 36.4-175.5 0-4-3.5-4-9.7 0-13.7 3.5-3.5 9.7-3.5 13.2 0 27.8 28.5 120 29 149 0 3.5-3.5 9.7-3.5 13.2 0 4.1 4 4.1 10.2.1 13.7zm-.8-54.2c-21.6 0-39.2-17.6-39.2-39.2 0-22 17.6-39.7 39.2-39.7 22 0 39.7 17.6 39.7 39.7-.1 21.5-17.7 39.2-39.7 39.2z"/></svg>
  </a>
<?php } ?>

	<?php
	if($link = $settings->get_setting('social_whatsapp_link')){
	?>	
  <!-- Whatsapp -->
  <a href="<?php echo $link; ?>">
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512" class="w-5 h-5"><!--! Font Awesome Pro 6.0.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2022 Fonticons, Inc. --><path fill="currentColor" d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7.9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/></svg>
  </a>
<?php } ?>

	<?php
	if($link = $settings->get_setting('social_twitch_link')){
	?>	
  <!-- Twitch -->
  <a href="<?php echo $link; ?>">
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" class="w-5 h-5"><!--! Font Awesome Pro 6.0.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2022 Fonticons, Inc. --><path fill="currentColor" d="M391.17,103.47H352.54v109.7h38.63ZM285,103H246.37V212.75H285ZM120.83,0,24.31,91.42V420.58H140.14V512l96.53-91.42h77.25L487.69,256V0ZM449.07,237.75l-77.22,73.12H294.61l-67.6,64v-64H140.14V36.58H449.07Z"/></svg>
  </a>
<?php } ?>
</div>
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
			/*REGULAR MENU*/
			$("#'.$id.'_button").click(function() { 
			 $("#'.$id.'").toggleClass("invisible");
			 event.stopPropagation();
			});	
		
			/*MOBILE MENU*/
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
