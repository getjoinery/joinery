<?php
require_once(__DIR__ . '/PathHelper.php');

PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/PublicPageFalcon.php');
PathHelper::requireOnce('includes/Pager.php');
PathHelper::requireOnce('data/admin_menus_class.php');

class AdminPage extends PublicPageFalcon {



	public function admin_header($options=array()) {
		$session = SessionControl::get_instance();
		$_GLOBALS['page_header_loaded'] = true;
		$options['vertical_menu'] =  MultiAdminMenu::getadminmenu($session->get_permission(), $options['menu-id']);
		
		$options['hide_horizontal_menu'] = true;
		$options['full_width'] = true;
		
		$this->public_header($options);
		echo AdminPage::BeginPage($options['readable_title']);
		return true;
	
		
	}

	public function admin_footer($options=array()) {
		$session = SessionControl::get_instance();
		$session->clear_clearable_messages();
		$settings = Globalvars::get_instance();
		
		echo AdminPage::EndPage();
		
		$this->public_footer($options);
	
	}



}

?>
