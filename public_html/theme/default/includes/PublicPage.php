<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ShoppingCart.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');

class PublicPage {

	private $rowcount;

	private static $header_defaults = array(
		'title' => '',
		'showheader' => TRUE,
		'noindex' => FALSE,
		'nofollow' => FALSE,
	);

	private static $footer_defaults = array(
		'track' => TRUE,
	);

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

		?>
		<p><?php echo $body; ?></p>
		<?php
		echo PublicPage::EndPage();
		$page->public_footer();
		exit;
	}
	
	public static function BeginPage($title='', $options=array()) {
		$output = '<div>';
		if($title){
			$output .= '<h1>'.$title.'</h1>'; 
		}
		return $output;
	}	

	public static function EndPage($options=array()) {
		$output = '</div>'; 
		return $output;
	}	

	public function __construct($secure=FALSE) {
		$this->rowcount = 0;
		$this->secure = $secure;
		$this->server = $_SERVER['PHP_SELF'];
		$this->remote_addr = $_SERVER['REMOTE_ADDR'];

		$settings = Globalvars::get_instance();

		$this->debug = $settings->get_setting('debug');
		if ($this->debug == 1) {
			$secure = FALSE;
			$this->secure = FALSE;
		}

		$settings = Globalvars::get_instance();
		if($settings->get_setting('force_https')){
			// If secure is on, they are not HTTPS and on port 80, forward them to SSL
			/*
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
		}

		$this->cdn = $settings->get_setting($this->secure ? 'CDN_SSL' : 'CDN');
		$this->protocol = $this->secure ? 'https://' : 'http://';
		$this->secure_prefix = ($this->debug == 0) ? $settings->get_setting('webDir_SSL') : $settings->get_setting('webDir');

		$session = SessionControl::get_instance();
		$this->location_data = $session->get_location_data();

		// This is for apache specific logging, so we have to check to make sure we are
		// serving off apache before we can set the userid.
		if (function_exists('apache_note') && $session->get_user_id(TRUE)) {
			apache_note('user_id', $session->get_user_id(TRUE));
		}

		if ($session->get_user_id()) {
			$this->user = new User($session->get_user_id(), TRUE);
		}

	}

	public function public_header($options=array()) {
		$_GLOBALS['page_header_loaded'] = true;
		
		$session = SessionControl::get_instance();
		$settings = Globalvars::get_instance();

		$site_title = $settings->get_setting('site_name');
		if(isset($options['title']) && $options['title']){
			$site_title = $options['title'] . ' - ' . $settings->get_setting('site_name');
		}
		
		$site_description = $settings->get_setting('site_description');
		if(isset($options['description']) & $options['description']){
			$site_description = $options['description'];
		}
		
		if(empty($options['noheader'])){
			//TRACKING
			if(!$_SESSION['permission'] || $_SESSION['permission'] == 0){
				if(!isset($options['is_404'])){
					$options['is_404'] = 0;
				}

				$session->save_visitor_event(1, $options['is_404']);
			}
		}
	
		?>
		<!DOCTYPE html>
		<html lang="en-US">
		<head>
		<meta charset="utf-8">
		<base href="/">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<meta name="description" content="<?php echo $site_description; ?>">
		<title><?php echo $site_title; ?></title>

		<link rel='stylesheet' id='integral_zen_main'  href='<?php echo $this->cdn; ?>/theme/default/includes/mvp-master/mvp.css' type='text/css' media='all' />
		
		<link type="text/css" href="<?php echo $this->cdn; ?>/theme/default/includes/jquery-ui-1.7.custom_5.css" rel="stylesheet" />
	
		<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
		
		<!-- jQuery validate -->
		<script type="text/javascript" src="/theme/integralzen/scripts/js/jquery.validate-1.9.1.js"></script>				
		
		<!--
		<link rel="icon" href="" sizes="32x32" />
		<link rel="icon" href="" sizes="192x192" />
		<link rel="apple-touch-icon-precomposed" href="" />
		<meta name="msapplication-TileImage" content="" />
		-->
		</head>	
	<?php	
	if(empty($options['noheader'])){
		if($_SESSION['permission'] == 10){
			require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/admin_debug.php');
		}
		?>		
			
<body>
    <header>
        <nav>
            <a href="/"><img alt="Logo" src="https://via.placeholder.com/200x70?text=Logo" height="70"></a>
            <ul>
                <li>Menu Item 1</li>
                <li><a href="#">Menu Item 2</a></li>
                <li><a href="#">Dropdown Menu Item</a>
                    <ul>
                        <li><a href="#">Sublink with a long name</a></li>
                        <li><a href="#">Short sublink</a></li>
                    </ul>
                </li>
            </ul>
        </nav>
        <h1>Page Heading with <i>Italics</i> and <u>Underline</u></h1>
        <p>Page Subheading with <mark>highlighting</mark></p>
        <br>
        <p><a href="#"><i>Italic Link Button</i></a><a href="#"><b>Bold Link Button &rarr;</b></a></p>
    </header>
    <main>
        <hr>
        <section>
            <header>
                <h2>Section Heading</h2>
                <p>Section Subheading</p>

				<?php 
				if ($session->get_user_id()){
					echo '<a href="/profile/profile">My Profile</a> '; 
					if($_SESSION['permission'] >= 5){
						echo '| <a href="/admin/admin_users">Admin</a> ';
					}

					$cart = $session->get_shopping_cart();
					if($numitems = $cart->count_items()){
						echo '| <a href="/cart">Cart ('. $numitems . ')</a> ';
					}
					else{
						//echo '<span class="cartcontents">Cart</span> ';
					}

					echo '| <a href="/logout">Log out</a>';

				}
				else{
					echo '<a href="/login">Log in</a> | <a href="/register">Register</a>';
				}
				
				if($_SESSION['permission'] == 10){
					echo ' | <a id="admintoggle" href="#">Debug</a>';				
				}
				echo '<br />Timezone: '.$session->get_timezone().' (<a href="/profile/account_edit">change</a>)';
				?>
			</header>	
			<main>
			<h1><a href="/" rel="home">Welcome</a></h1>
						
								

	<?php } //end if noheader 
	}

	public function public_footer($options=array()) {
		$session = SessionControl::get_instance();
		$session->clear_clearable_messages();
	
		$settings = Globalvars::get_instance();
		if($settings->get_setting('force_https')){
			?>
			<!--Make sure https-->
			<script type="text/javascript">
			if (location.protocol !== 'https:') {
				location.replace(`https:${location.href.substring(location.protocol.length)}`);
			}
			</script>
			<?php
		} 
		?>
		</main>
		<footer>
			<hr>
			<p>
				<small>Contact info</small>
			</p>
		</footer>
		</body>

		</html>
		<?php
	}




	function tableheader($headers, $version="default"){
		//version VARIABLE TOGGLES BETWEEN STYLESHEETS
		echo "<table id='$version' cellspacing='0' summary=''>
			<caption></caption>
			<tr>";

		foreach ($headers as $value) {
			printf('<th scope="col" abbr="%s" class="bg">%s</th>', $value, $value);
		}
		echo '</tr>';
	}

	function disprow($dataarray){

		echo '<tr>';

		foreach ($dataarray as $value) {
			if ($value == "") {
				$value = "&nbsp";
			}

			if ($this->rowcount % 2 == 0) {
				printf('<td>%s</td>', $value);
			} else {
				printf('<td>%s</td>', $value);
			}
		}
		echo "</tr>\n";
		$this->rowcount++;
	}

	function endtable(){
		$this->rowcount = 0;
		echo '</table>';
	}
}

?>
