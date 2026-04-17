<?php
/**
 * MemberPage — default page wrapper for the /profile member area.
 *
 * Analogous to AdminPage: by default uses the joinery-system theme regardless
 * of the active public theme.  No admin sidebar — uses a member-specific
 * horizontal nav and lets each view manage its own card layout.
 *
 * Theme-overridable: profile views load this via getThemeFilePath(), so themes
 * can provide their own MemberPage at theme/{theme}/includes/MemberPage.php
 * to re-skin the entire member area.
 *
 * @version 1.2
 */
require_once(__DIR__ . '/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/PublicPageJoinerySystem.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));

// Member area always uses joinery-system theme, regardless of the active public theme
if (!class_exists('PublicPage')) {
    class PublicPage extends PublicPageJoinerySystem {}
}

class MemberPage extends PublicPage {

    protected $header_options = array();

    /**
     * Get FormWriter instance for member pages.
     * Uses FormWriterV2HTML5 to match the joinery-system theme.
     */
    public function getFormWriter($form_id = 'form1', $form_options = []) {
        require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));
        return new FormWriterV2HTML5($form_id, $form_options);
    }

    /**
     * Build the member area navigation items.
     * Settings-aware: only shows links for enabled features.
     */
    protected function get_member_nav() {
        $settings = Globalvars::get_instance();
        $nav = array();

        $nav[] = array('label' => 'Dashboard', 'url' => '/profile', 'match' => '/profile/profile');
        if ($settings->get_setting('messaging_active')) {
            $nav[] = array('label' => 'Messages', 'url' => '/profile/conversations', 'match' => '/profile/conversation');
        }
        $nav[] = array('label' => 'Notifications', 'url' => '/notifications');
        $nav[] = array('label' => 'Account', 'url' => '/profile/account_edit', 'match' => '/profile/account');

        return $nav;
    }

    public function member_header($options = array()) {
        $_GLOBALS['page_header_loaded'] = true;

        $options['hide_horizontal_menu'] = true;
        $options['hide_vertical_menu'] = true;
        $options['member_nav'] = $this->get_member_nav();

        $this->header_options = $options;
        $this->public_header($options);

        // Use no-card layout — views manage their own card structure
        echo PublicPageJoinerySystem::BeginPageNoCard($options);

        return true;
    }

    public function member_footer($options = array()) {
        $session = SessionControl::get_instance();
        $session->clear_clearable_messages();

        echo PublicPageJoinerySystem::EndPageNoCard();
        $this->public_footer($options);
    }
}
?>
