<?php
require_once('Globalvars.php');
require_once('SessionControl.php');
require_once('DbConnector.php');
require_once('LibraryFunctions.php');
require_once(LibraryFunctions::get_theme_file_path('PublicPage.php', '/includes'));


class ErrorHandler{

	private $debug;
	private $logfile;
	private $standard_error;

	const DEFAULT_ERROR = 1;
	const INPUT_ERROR = 2;
	const PERMANENT_ERROR = 3;

	public static $ERROR_TYPE_TITLES = array(
		self::DEFAULT_ERROR => 'Error',
		self::INPUT_ERROR => 'Almost Done',
		self::PERMANENT_ERROR => 'Whoops, Better Turn Around',
	);

	function __construct($secure=FALSE){
		$settings = Globalvars::get_instance();
		$standard_error =  $settings->get_setting('standard_error');
	}

	function handle_general_error($errortext, $error_type=self::DEFAULT_ERROR) {
	
		if ($error_type === self::INPUT_ERROR) {
			$title = 'There was an error with something you entered';
		} 
		else if ($error_type === self::PERMANENT_ERROR) {
			$title = "Something didn't work";
		} 
		else { 
			$title = "There was an error";
		}

	
		if(!isset($_GLOBALS['page_header_loaded'])){
			
			$page = new PublicPage();
			$hoptions= array(
				'title' => self::$ERROR_TYPE_TITLES[$error_type],
				'showmap' => FALSE,
				'showheader' => TRUE, 
				'sectionstyle' => 'neutral', 
				'headertext' => '', 
				'contentattached' => FALSE, 
				'toggleTabs' => FALSE
			);
			$page->public_header($hoptions,NULL);
			
			echo PublicPage::BeginPage($title);
		}
		if ($errortext) {
			echo '<div class="form-error"><strong>'.$errortext.'</strong></div>';
		}
		echo '<br />';

		$settings = Globalvars::get_instance();
		$email = $settings->get_setting('webmaster_email');
		echo '<p>If you need quick help, you can contact the webmaster at '.$email.'.</p>';	
		echo '<p>Press your Back button or <a href="#" onclick="history.go(-1);return false;">click here</a> to go to the last page</p>';
		
		echo PublicPage::EndPage();
		$page->public_footer($foptions=array('track'=>FALSE));
		exit;
	}

	function handle_admin_error($errortext){
		require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
		$session = SessionControl::get_instance();

		$page = new AdminPage();
		$page->admin_header(	
		array(
			'menu-id'=> NULL,
			'page_title' => self::$ERROR_TYPE_TITLES[$error_type],
			'readable_title' => self::$ERROR_TYPE_TITLES[$error_type],
			'breadcrumbs' => NULL,
			'session' => $session,
		)
		);
		
		$pageoptions['title'] = self::$ERROR_TYPE_TITLES[$error_type];
		$page->begin_box($pageoptions);
		
		echo "<div><h2>Something didn't work</h2>";

		if ($errortext) {
			echo '<div class="form-error"><strong>'.$errortext.'</strong></div>';
		}
		echo '<br />';

		$settings = Globalvars::get_instance();
		$email = $settings->get_setting('webmaster_email');
		echo '<p>If you need quick help, you can contact the webmaster at '.$email.'.</p>';	
		echo '<p>Press your Back button or <a href="#" onclick="history.go(-1);return false;">click here</a> to go to the last page</p>';
		echo '</div>';
		$page->end_box();
		$page->admin_footer();
		exit(-1);
	}
}

?>
