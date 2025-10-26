<?php
require_once(__DIR__ . '/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/PublicPageFalcon.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));
require_once(PathHelper::getIncludePath('data/admin_menus_class.php'));

class AdminPage extends PublicPageFalcon {

    /**
     * Store header options for use in footer
     */
    protected $header_options = array();

    /**
     * Get FormWriter instance for admin pages
     * Supports both V1 (legacy) and V2 (modern) during migration
     *
     * @param string $form_id Form identifier (default: 'form1')
     * @param string $version 'v1' for legacy FormWriterBootstrap, 'v2' for FormWriterV2Bootstrap
     * @param array $form_options Additional form options (csrf, action, method, etc.)
     * @return FormWriterBootstrap|FormWriterV2Bootstrap FormWriter instance
     *
     * Usage:
     *   $formwriter = $page->getFormWriter('form1');                                    // V1 (default)
     *   $formwriter = $page->getFormWriter('form1', 'v2');                             // V2 (modern)
     *   $formwriter = $page->getFormWriter('form1', 'v2', ['csrf' => false]);          // V2 without CSRF
     */
    public function getFormWriter($form_id = 'form1', $version = 'v1', $form_options = []) {
        if ($version === 'v2') {
            require_once(PathHelper::getIncludePath('includes/FormWriterV2Bootstrap.php'));

            // Auto-detect form action from current request
            $form_action = '/admin/dashboard';  // Safe default
            if (!empty($_SERVER['REQUEST_URI'])) {
                $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
                if (!empty($path)) {
                    // Remove trailing .php if present (for direct access)
                    $form_action = preg_replace('/\.php$/', '', $path);
                }
            }

            // Merge defaults with provided options
            $options = array_merge([
                'action' => $form_action,
                'method' => 'POST',
                'csrf' => false  // Admin pages do NOT use CSRF protection
            ], $form_options);

            return new FormWriterV2Bootstrap($form_id, $options);
        }

        // Default to V1 for backward compatibility
        require_once(PathHelper::getIncludePath('includes/FormWriterBootstrap.php'));
        return new FormWriterBootstrap($form_id);
    }




	public function admin_header($options=array()) {
		$session = SessionControl::get_instance();
		$_GLOBALS['page_header_loaded'] = true;
		$options['vertical_menu'] =  MultiAdminMenu::getadminmenu($session->get_permission(), $options['menu-id']);

		$options['hide_horizontal_menu'] = true;
		$options['full_width'] = true;

		// Store options for use in footer
		$this->header_options = $options;

		$this->public_header($options);

		// Check for no_page_card option
		if (isset($options['no_page_card']) && $options['no_page_card'] === true) {
			echo AdminPage::BeginPageNoCard($options);
		} else {
			echo AdminPage::BeginPage($options['readable_title']);
		}

		return true;
	}

	public function admin_footer($options=array()) {
		$session = SessionControl::get_instance();
		$session->clear_clearable_messages();
		$settings = Globalvars::get_instance();

		// Check for no_page_card option from header
		if (isset($this->header_options['no_page_card']) && $this->header_options['no_page_card'] === true) {
			echo AdminPage::EndPageNoCard();
		} else {
			echo AdminPage::EndPage();
		}

		$this->public_footer($options);
	}



}

?>
