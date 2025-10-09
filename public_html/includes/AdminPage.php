<?php
require_once(__DIR__ . '/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/PublicPageFalcon.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));
require_once(PathHelper::getIncludePath('data/admin_menus_class.php'));

class AdminPage extends PublicPageFalcon {

    /**
     * Get FormWriter instance for admin pages
     * Admin pages always use Bootstrap FormWriter
     */
    public function getFormWriter($form_id = 'form1') {
        require_once(PathHelper::getIncludePath('includes/FormWriterBootstrap.php'));
        return new FormWriterBootstrap($form_id);
    }




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
