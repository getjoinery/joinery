<?php
require_once('PathHelper.php');
require_once('Globalvars.php');
require_once('SessionControl.php');
require_once('DbConnector.php');
require_once('LibraryFunctions.php');

// Lazy load PublicPage to avoid circular dependencies during database updates
// The theme file will be loaded only when PublicPage is actually needed
function load_public_page_if_needed() {
    if (!class_exists('PublicPage', false)) {
        try {
            $theme_file = LibraryFunctions::get_theme_file_path('PublicPage.php', '/includes');
            require_once($theme_file);
        } catch (Exception $e) {
            // If theme loading fails (e.g., during database update), use basic error output
            return false;
        }
    }
    return true;
}


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

		$settings = Globalvars::get_instance();
		$show_errors = $settings->get_setting('show_errors');
		
		// If show_errors is enabled, use minimal display without header/footer
		if ($show_errors) {
			echo '<!DOCTYPE html><html><head><title>' . htmlspecialchars($title) . '</title>';
			echo '<style>body{font-family:monospace;margin:20px;background:#f5f5f5;}';
			echo '.error-container{background:white;padding:20px;border:1px solid #ddd;border-radius:4px;}';
			echo '.error-title{color:#d32f2f;margin-bottom:15px;font-size:18px;font-weight:bold;}';
			echo '.error-content{white-space:pre-wrap;word-wrap:break-word;}</style>';
			echo '</head><body>';
			echo '<div class="error-container">';
			echo '<div class="error-title">' . htmlspecialchars($title) . '</div>';
			if ($errortext) {
				echo '<div class="error-content">' . htmlspecialchars($errortext) . '</div>';
			}
			echo '</div></body></html>';
			exit;
		}

		// Standard error display with header/footer
		if(!isset($_GLOBALS['page_header_loaded'])){
			
			// Try to load PublicPage, fall back to basic output if it fails
			if (load_public_page_if_needed()) {
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
			} else {
				// Fallback when PublicPage can't be loaded (e.g., during database updates)
				echo '<!DOCTYPE html><html><head><title>' . htmlspecialchars($title) . '</title></head><body>';
				echo '<div style="max-width: 600px; margin: 50px auto; padding: 20px; font-family: Arial, sans-serif;">';
				echo '<h1>' . htmlspecialchars($title) . '</h1>';
			}
		}
		if ($errortext) {
			echo '<div class="form-error"><strong>'.$errortext.'</strong></div>';
		}
		echo '<br />';

		try {
			$email = $settings->get_setting('webmaster_email');
			echo '<p>If you need quick help, you can contact the webmaster at '.$email.'.</p>';
		} catch (Exception $e) {
			// Skip email display if settings not available
		}
		echo '<p>Press your Back button or <a href="#" onclick="history.go(-1);return false;">click here</a> to go to the last page</p>';
		
		if (class_exists('PublicPage', false)) {
			echo PublicPage::EndPage();
			$page->public_footer($foptions=array('track'=>FALSE));
		} else {
			echo '</div></body></html>';
		}
		exit;
	}

	function handle_admin_error($errortext, $error_type=self::DEFAULT_ERROR){
		$settings = Globalvars::get_instance();
		$show_errors = $settings->get_setting('show_errors');
		
		// If show_errors is enabled, use minimal display without header/footer
		if ($show_errors) {
			$title = "Admin Error";
			echo '<!DOCTYPE html><html><head><title>' . htmlspecialchars($title) . '</title>';
			echo '<style>body{font-family:monospace;margin:20px;background:#f5f5f5;}';
			echo '.error-container{background:white;padding:20px;border:1px solid #ddd;border-radius:4px;}';
			echo '.error-title{color:#d32f2f;margin-bottom:15px;font-size:18px;font-weight:bold;}';
			echo '.error-content{white-space:pre-wrap;word-wrap:break-word;}</style>';
			echo '</head><body>';
			echo '<div class="error-container">';
			echo '<div class="error-title">' . htmlspecialchars($title) . '</div>';
			if ($errortext) {
				echo '<div class="error-content">' . htmlspecialchars($errortext) . '</div>';
			}
			echo '</div></body></html>';
			exit;
		}

		// Standard admin error display with header/footer
		PathHelper::requireOnce('includes/AdminPage.php');
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

		try {
			$email = $settings->get_setting('webmaster_email');
			echo '<p>If you need quick help, you can contact the webmaster at '.$email.'.</p>';	
		} catch (Exception $e) {
			// Skip email display if settings not available
		}
		echo '<p>Press your Back button or <a href="#" onclick="history.go(-1);return false;">click here</a> to go to the last page</p>';
		echo '</div>';
		$page->end_box();
		$page->admin_footer();
		exit(-1);
	}
}

?>
