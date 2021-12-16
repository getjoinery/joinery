<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Pager.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/admin_menus_class.php');

class AdminPage{

	private $rowcount;

	function __construct($secure=TRUE){
		$this->rowcount=0;
		$this->secure = $secure;

		$settings = Globalvars::get_instance();

		$debug = $settings->get_setting('debug');
		if ($debug == 1) {
			$secure = FALSE;
			$this->secure = FALSE;
		}

		/*
		// If secure is on, they are not HTTPS and on port 80, forward them to SSL
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

		$this->prefix = $this->secure ? 'https://' : 'http://';
		$this->cdn = $settings->get_setting($this->secure ? 'CDN_SSL' : 'CDN');
		$this->secure_prefix = ($debug == 0) ? $settings->get_setting('webDir_SSL') : $settings->get_setting('webDir');
	}	
	

	
	
	
	
	

	function admin_header($pagevars, $display=TRUE) 
	{

		$_GLOBALS['page_header_loaded'] = true;
		
		//header("Content-Security-Policy-Report-Only: default-src 'self' 'unsafe-inline' https://integralzen.org https://*.cloudflare.com https://*.gstatic.com https://*.googleapis.com; report-uri https://integralzen.org/somasdflkj; report-to groupname");

		$session = $pagevars['session'];		
		
		if($session->get_user_id()){
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
		<link rel="icon" href="/theme/integralzen/images/cropped-IZ-Icon-07-32x32.png" sizes="32x32" />
		<link rel="icon" href="/theme/integralzen/images/cropped-IZ-Icon-07-192x192.png" sizes="192x192" />
		<link rel="apple-touch-icon-precomposed" href="/theme/integralzen/images/cropped-IZ-Icon-07-180x180.png" />	-->
				
	</head>
	<body>


		<!--HEADER-->
		<header id="top-head" class="uk-position-fixed">

			
	<?php
	
	if($_SESSION['permission'] == 10){
		echo '		<style type="text/css">
table.example3 {background-color:white;border-collapse:collapse;width:100%;display:block;}
table.example3 th, table.example3 td {text-align:center;border:1px solid black;padding:5px;}
table.example3 th {background-color:AntiqueWhite;}
table.example3 td:first-child {width:20%;}
</style>';
		echo '<div id="admin_panel" style="display:none;">';
		echo '<table class = "example3"><th colspan=2>Session</th>';
		foreach($_SESSION as $sname=>$svar){
			echo '<tr><td>'.$sname . '</td><td>';
			if(is_array($svar)){
				print_r($svar);
			}
			else if(is_object($svar)){
				var_dump($svar);
			}
			else{
				echo $svar;
			}
			echo '</td></tr>';
		}
		echo '</table><br /><table class = "example3"><th colspan=2>Request</th>';
		foreach($_REQUEST as $sname=>$svar){
			echo '<tr><td>'.$sname . '</td><td>';
			if(is_array($svar)){
				print_r($svar);
			}
			else if(is_object($svar)){
				var_dump($svar);
			}
			else{
				echo $svar;
			}
			echo '</td></tr>';
		}		
		
		echo '</table></div>';
		//echo '<a id="admintoggle" href="#">toggle</a>';
		echo '<script>
 
$(document).ready(function() {

$("#admintoggle").click(function () {
$("#admin_panel").toggle();
});
});
</script>';
	}
	
	?>			
			
			
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
					echo '<a class="uk-logo" href="/"><span>'.$settings->get_setting('site_name').'</span></a>';
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
						if($session->get_permission() >= 9) {
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
								<li class="uk-nav-divider"></li>
								<li><a id="admintoggle" href="#"><span data-uk-icon="icon: image"></span> Debug Data</a></li>
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
					//DON'T SHOW IF TURNED OFF
					if($menu_id == 26 && !$settings->get_setting('blog_active')){
						continue;
					}
					if($menu_id == 2 && !$settings->get_setting('events_active')){
						continue;
					}
					if($menu_id == 4 && !$settings->get_setting('products_active')){
						continue;
					}
					if($menu_id == 5 && !$settings->get_setting('products_active')){
						continue;
					}
					if($menu_id == 11 && !$settings->get_setting('emails_active')){
						continue;
					}
					if($menu_id == 3 && !$settings->get_setting('videos_active')){
						continue;
					}
					if($menu_id == 9 && !$settings->get_setting('files_active')){
						continue;
					}
					if($menu_id == 32 && !$settings->get_setting('urls_active')){
						continue;
					}
					if($menu_id == 36 && !$settings->get_setting('coupons_active')){
						continue;
					}
					
					if(!$menu_info['parent']){
						if($menu_info['currentmain']){
							if($menu_info['has_subs']){
								echo '<li class="uk-parent uk-open"><a href="/admin/'.$menu_info['defaultpage'].'"><span data-uk-icon="icon: '.$menu_info['icon'].'" class="uk-margin-small-right"></span>'.$menu_info['display'].'</a>';
								echo '<ul class="uk-nav-sub">';
								foreach ($iterate_menu as $iterate_menu_id=>$iterate_menu_info){
									if($iterate_menu_info['parent'] == $menu_id){
										if($iterate_menu_info['currentsub']){
											echo '<li><a title="'.$iterate_menu_info['display'].'" href="/admin/'.$iterate_menu_info['defaultpage'].'">'.$iterate_menu_info['display'].'</a></li>';
										}
										else{
											echo '<li><a title="'.$iterate_menu_info['display'].'" href="/admin/'.$iterate_menu_info['defaultpage'].'">'.$iterate_menu_info['display'].'</a></li>';									
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
											echo '<li><a title="'.$iterate_menu_info['display'].'" href="/admin/'.$iterate_menu_info['defaultpage'].'">'.$iterate_menu_info['display'].'</a></li>';
										}
										else{
											echo '<li><a title="'.$iterate_menu_info['display'].'" href="/admin/'.$iterate_menu_info['defaultpage'].'">'.$iterate_menu_info['display'].'</a></li>';									
										}
									}
								}
								echo '</ul>';
								echo '</li>';							}
							else{
								echo '<li><a href="/admin/'.$menu_info['defaultpage'].'"><span data-uk-icon="icon: '.$menu_info['icon'].'" class="uk-margin-small-right"></span>'.$menu_info['display'].'</a></li>';									
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
		$CDN = $settings->get_setting('CDN');
		
		$session = SessionControl::get_instance();
		if($session->get_user_id()){
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
					<p class="uk-text-small uk-text-center">Copyright 2020 - Jeremy Tunnell | Built with <a href="http://getuikit.com" title="Visit UIkit 3 site" target="_blank" data-uk-tooltip><span data-uk-icon="uikit"></span></a> </p>
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
								echo '<li class="uk-parent uk-open"><a href="/admin/'.$menu_info['defaultpage'].'">'.$menu_info['display'].'</a>';
								echo '<ul class="uk-nav-sub">';
								foreach ($iterate_menu as $iterate_menu_id=>$iterate_menu_info){
									if($iterate_menu_info['parent'] == $menu_id){
										if($iterate_menu_info['currentsub']){
											echo '<li><a title="'.$iterate_menu_info['display'].'" href="/admin/'.$iterate_menu_info['defaultpage'].'">'.$iterate_menu_info['display'].'</a></li>';
										}
										else{
											echo '<li><a title="'.$iterate_menu_info['display'].'" href="/admin/'.$iterate_menu_info['defaultpage'].'">'.$iterate_menu_info['display'].'</a></li>';									
										}
									}
								}
								echo '</ul>';
								echo '</li>';
							}
							else{
								echo '<li><a href="/admin/'.$menu_info['defaultpage'].'">'.$menu_info['display'].'</a></li>';	
							}
						}
						else{
							if($menu_info['has_subs']){
								echo '<li class="uk-parent"><a href="/admin/'.$menu_info['defaultpage'].'">'.$menu_info['display'].'</a>';
								echo '<ul class="uk-nav-sub">';
								foreach ($iterate_menu as $iterate_menu_id=>$iterate_menu_info){
									if($iterate_menu_info['parent'] == $menu_id){
										if($iterate_menu_info['currentsub']){
											echo '<li><a title="'.$iterate_menu_info['display'].'" href="/admin/'.$iterate_menu_info['defaultpage'].'">'.$iterate_menu_info['display'].'</a></li>';
										}
										else{
											echo '<li><a title="'.$iterate_menu_info['display'].'" href="/admin/'.$iterate_menu_info['defaultpage'].'">'.$iterate_menu_info['display'].'</a></li>';									
										}
									}
								} 
								echo '</ul>';
								echo '</li>';							
							}
							else{
								echo '<li><a href="/admin/'.$menu_info['defaultpage'].'">'.$menu_info['display'].'</a></li>';									
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



	
	function dropdown_or_buttons($options){
		if(!$options['options_class']){
			$options['options_class'] = 'pull-right';
		}

		if(!$options['options_label']){
			$options['options_label'] = 'Options';
		}		
		

		if(count($options['altlinks']) > 3){
			echo '<button class="uk-button uk-button-default" type="button">'.$options['options_label'].'</button><div uk-dropdown><ul class="uk-nav uk-dropdown-nav">';
			foreach($options['altlinks'] as $label=>$link){
				echo '<li><a href="'.$link.'" class="dropdown-item">'.$label.'</a></li>';
			}	
			echo '</ul></div>';	    
								
		}
		else if(count($options['altlinks']) > 0){
			echo'<div class="'.$options['options_class'].'">';
			foreach($options['altlinks'] as $label=>$link){
				echo '<a class="uk-button uk-button-default" href="'.$link.'">'.$label.'</a>';
			}
			echo '</div>';
		}		
	}
	
	
	/***********
	Widths: 
	uk-width-auto Automatic
	uk-width-1-1	Fills 100% of the available width.
	uk-width-1-2	The element takes up halves of its parent container.
	uk-width-1-3 to .uk-width-2-3	The element takes up thirds of its parent container.
	uk-width-1-4 to .uk-width-3-4	The element takes up fourths of its parent container.
	uk-width-1-5 to .uk-width-4-5	The element takes up fifths of its parent container.
	uk-width-1-6 to .uk-width-5-6	The element takes up sixths of its parent container.
	*******************/
	function begin_box($options=NULL){
	
	if(!$options['width']){
		$options['width'] = 'uk-width-1-1';
	}
	?>
					<!-- panel -->
					<div class="<?php echo $options['width']; ?> uk-grid-margin">
						<div class="uk-card uk-card-default uk-card-small uk-card-hover">
							<div class="uk-card-header">
								<div class="uk-grid uk-grid-small">
									<div class="uk-width-auto"><h4><?php echo $options['title']; ?></h4></div>
									<div class="uk-width-expand uk-text-right panel-icons">
													<?php
												$this->dropdown_or_buttons($options);
												?>
												<!--
										<a href="#" class="uk-icon-link" title="Move" data-uk-tooltip data-uk-icon="icon: move"></a>
										<a href="#" class="uk-icon-link" title="Configuration" data-uk-tooltip data-uk-icon="icon: cog"></a>
										<a href="#" class="uk-icon-link" title="Close" data-uk-tooltip data-uk-icon="icon: close"></a>
										-->
									</div>
								</div>
							</div>
							<div class="uk-card-body">
 
	<?php		
	
	}
	
	function end_box(){
		
	?>
							</div>
						</div>
					</div>
					<!-- /panel -->

	
	<?php	
	
	}


	function tableheader($headers, $options=NULL, $pager=NULL){
		$this->begin_box($options);
		
		if(!$pager){
			$pager = new Pager();
		}

		$sortoptions= $options['sortoptions'];
		$search_on = $options['search_on'];

		if($sortoptions){
			echo '<div class="uk-align-left">';
			printf('<form method="get" ACTION="%s">', $_SERVER[REQUEST_URI]);
			echo '<label for="'.$pager->prefix().'sort'.'">Sort: </label><select name="'.$pager->prefix().'sort'.'">';
			foreach ($sortoptions as $key => $value) {
				if($pager->sort() == $value){
					echo "<option value='$value' selected=selected>$key";
				}
				else{
					echo "<option value='$value'>$key";
				}
			}
			echo '</select>';


			echo '<label for="'.$pager->prefix().'sdirection'.'"> </label><select name="'.$pager->prefix().'sdirection'.'">';
			$diroptions = array('Ascending'=>'ASC', 'Descending'=>'DESC');
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


		if($search_on){
			
			echo '<div id="example1_filter" class="uk-align-right">';
			$formwriter = new FormWriterMaster("search_form");
			echo $formwriter->begin_form("search_form", "get", $_SERVER[REQUEST_URI]);
			
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

		
		echo '<table class="uk-table uk-table-hover uk-table-divider uk-table-small" id="sample-table-3">

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
		echo '</table>';


		//PAGE
		if($pager->num_records()){	
			echo '<div class="uk-align-left" id="example1_info" role="status" aria-live="polite"> '.$pager->num_records().' records, Page '.$pager->current_page() .' of '.$pager->total_pages().'</div>';
		}
	
		
		if($pager->num_records() > $pager->num_per_page()){
			echo '<div class="uk-align-right">';
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
			
			echo '<a class="current" href="'.$pager->get_url().'"><strong>'.$pager->current_page().'</strong></a> ';
			
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

}

?>
