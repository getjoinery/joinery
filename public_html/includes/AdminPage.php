<?php
require_once(__DIR__ . '/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/PublicPageJoinerySystem.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));
require_once(PathHelper::getIncludePath('data/admin_menus_class.php'));

// Admin section always uses joinery-system theme, regardless of the active public theme
if (!class_exists('PublicPage')) {
    class PublicPage extends PublicPageJoinerySystem {}
}

class AdminPage extends PublicPage {

    /**
     * Store header options for use in footer
     */
    protected $header_options = array();

    /**
     * Get FormWriter instance for admin pages
     * Uses FormWriterV2HTML5 to match the joinery-system HTML5 theme
     *
     * @param string $form_id Form identifier (default: 'form1')
     * @param array $form_options Additional form options (csrf, action, method, etc.)
     * @return FormWriterV2HTML5 FormWriter instance
     *
     * Usage:
     *   $formwriter = $page->getFormWriter('form1');
     *   $formwriter = $page->getFormWriter('form1', ['csrf' => false]);
     */
    public function getFormWriter($form_id = 'form1', $form_options = []) {
        require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));
        return new FormWriterV2HTML5($form_id, $form_options);
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
			echo AdminPage::BeginPage($options['readable_title'], $options);
		}

		// Pending session flash messages (e.g. process_logic()'s error path)
		// render here on every admin page; admin_footer()'s
		// clear_clearable_messages() then removes them — shown once, then gone.
		echo $this->renderFlashMessages();

		return true;
	}

	/**
	 * Render all pending session flash messages as theme alerts. Admin pages
	 * must not fetch or render messages themselves — a logic file surfaces a
	 * user-visible failure by returning LogicResult::error(...) with its data
	 * payload, and it lands here.
	 */
	private function renderFlashMessages(): string {
		$session = SessionControl::get_instance();
		// NULL location = both GLOBAL and IN_PAGE — admin has one message region.
		$messages = $session->get_messages($_SERVER['REQUEST_URI'] ?? '', NULL);
		$out = '';
		foreach ($messages as $msg) {
			$alert_class = 'alert-info';
			if ($msg->display_type == DisplayMessage::MESSAGE_ERROR)            $alert_class = 'alert-danger';
			elseif ($msg->display_type == DisplayMessage::MESSAGE_WARNING)      $alert_class = 'alert-warning';
			elseif ($msg->display_type == DisplayMessage::MESSAGE_ANNOUNCEMENT) $alert_class = 'alert-success';
			$out .= '<div class="alert ' . $alert_class . '" role="alert">';
			if ($msg->message_title) $out .= '<strong>' . htmlspecialchars($msg->message_title) . ':</strong> ';
			$out .= htmlspecialchars($msg->message);
			$out .= '<button type="button" class="alert-close" aria-label="Close">&times;</button>';
			$out .= '</div>';
		}
		return $out;
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
