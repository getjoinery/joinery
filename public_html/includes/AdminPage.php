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

	public function get_admin_menu($options){
		$session = SessionControl::get_instance();
		$settings = Globalvars::get_instance();
		$siteDir = $settings->get_setting('siteDir');							
		require_once($siteDir . '/data/admin_menus_class.php');
		
		if($session && $session->get_user_id()){
			$user = new User($session->get_user_id(), TRUE);
			$user_name = $user->display_name();
		}
		else{
			$user = new User(NULL);
		}	
	
		
		$admin_menu = MultiAdminMenu::getadminmenu($user->get('usr_permission'), $options['menu-id']); 
		
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


	public function admin_header($options=array()) {
		$_GLOBALS['page_header_loaded'] = true;
		$options['show_admin_menus'] = true;
		
		$this->public_header($options);
		echo AdminPage::BeginPage($options['readable_title']);
		return true;
	
		
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
