<?php
require_once(__DIR__ . '/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/PublicPageJoinerySystem.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));
require_once(PathHelper::getIncludePath('data/admin_menus_class.php'));

// Admin section always uses joinery-system theme, regardless of the active public theme
if (!class_exists('PublicPage', false)) {
    class PublicPage extends PublicPageJoinerySystem {}
}

/**
 * AdminPage — the admin interface's page object.
 *
 * @version 1.7 - admin_header takes no menu-id (the tier edit page warned)
 * @version 1.6 - the root request panel's poller is assets/js/root-request.js (no inline script)
 * @version 1.5 - email_audience_table() lists direct messages; direct_messages_to()
 * @version 1.4 - the root request panel polls /api/v1/action/root_request_status; the bare /api/v1/ path is the
 *                model API, so every panel answered 400 and sat at Queued whatever the request did
 * @version 1.3 - readable_title is optional (BeginPage's own default)
 * @version 1.2 - an error flash message carries a "Report a problem" link
 * @version 1.1 - root_request_panel() takes the URL to open when an
 *                install_package request is refused as unverified (exit 3),
 *                so the warning page follows the refusal (specs/package_signing.md WP6)
 */
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




	/**
	 * Render the settings tab strip.
	 *
	 * Every settings tab page calls this and passes only its own label, so the
	 * tab list has one definition. Payment Settings and Plugin Settings are
	 * conditional: each appears only when something is behind it.
	 *
	 * @param string|null $current Label of the calling page's tab
	 * @return string HTML
	 */
	/**
	 * The live view of a queued root request.
	 *
	 * The pool cannot write the code tree, so an upgrade, a plugin install or a
	 * docs save is queued for root and carried out on its own clock
	 * (specs/implemented/read_only_tree.md). A button that queued one and then said nothing
	 * would be a button that looks broken, so every page that submits a request
	 * renders this: the state, the exit code when there is one, and the
	 * transcript as it grows.
	 *
	 * Polls the read-only root_request_status action through /api/v1 with the
	 * browser-session credential, every two seconds, and stops the moment the
	 * request reaches done or failed.
	 *
	 * An install_package request that root refused because the package did
	 * not verify fails with exit 3 (RootRequest::EXIT_UNVERIFIED), and that
	 * outcome has a next step: the warning page. $unverified_url, when given,
	 * is where the panel sends the browser at that moment, so the operator
	 * sees the warning rather than a failed request they have to interpret
	 * (specs/package_signing.md WP6).
	 *
	 * @param string $id             The request id returned by RootRequest::submit()
	 * @param string $unverified_url Same-site URL to go to on exit 3, or ''
	 * @return string HTML
	 */
	public static function root_request_panel($id, $unverified_url = '') {
		$id = (string)$id;
		if ($id === '') {
			return '';
		}
		$safe = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
		$unverified_url = (string)$unverified_url;
		// Same-site relative only, never an open redirect: a browser drops
		// tabs and line breaks and reads "\" as "/", so none of those either.
		if ($unverified_url !== '' && ($unverified_url[0] !== '/' || (isset($unverified_url[1]) && $unverified_url[1] === '/')
				|| preg_match('/[\x00-\x20\x7f\\\\]/', $unverified_url))) {
			$unverified_url = '';
		}
		$safe_unverified = htmlspecialchars($unverified_url, ENT_QUOTES, 'UTF-8');

		// assets/js/root-request.js polls each panel on the page, from these
		// attributes: no inline script.
		static $script_sent = false;
		$script = '';
		if (!$script_sent) {
			$script_sent = true;
			$script = '<script defer src="/assets/js/root-request.js?v='
				. (@filemtime(PathHelper::getIncludePath('assets/js/root-request.js')) ?: '1') . '"></script>';
		}
		return <<<HTML
<div class="jy-rootreq" data-request="{$safe}" data-unverified-url="{$safe_unverified}" data-state="queued">
<style>
.jy-rootreq{margin:1rem 0;border:1px solid var(--jy-border,#d4d4d8);border-radius:6px;overflow:hidden}
.jy-rootreq__head{display:flex;align-items:center;gap:.5rem;padding:.6rem .9rem;background:var(--jy-surface-2,#f4f4f5);font-size:.95rem}
.jy-rootreq__dot{width:.6rem;height:.6rem;border-radius:50%;background:#a1a1aa;flex:none}
.jy-rootreq[data-state="running"] .jy-rootreq__dot{background:#f59e0b;animation:jy-rootreq-pulse 1.2s ease-in-out infinite}
.jy-rootreq[data-state="done"] .jy-rootreq__dot{background:#16a34a}
.jy-rootreq[data-state="failed"] .jy-rootreq__dot{background:#dc2626}
@keyframes jy-rootreq-pulse{50%{opacity:.35}}
.jy-rootreq__note{color:#71717a}
.jy-rootreq__log{margin:0;padding:.75rem .9rem;max-height:22rem;overflow:auto;background:#18181b;color:#e4e4e7;font-size:.85rem;line-height:1.45;white-space:pre-wrap;word-break:break-word}
.jy-rootreq__log:empty{display:none}
</style>
<div class="jy-rootreq__head">
<span class="jy-rootreq__dot"></span>
<strong class="jy-rootreq__state">Queued</strong>
<span class="jy-rootreq__note"></span>
</div>
<pre class="jy-rootreq__log"></pre>
</div>
{$script}
HTML;
	}

	/**
	 * The line shown above any button that queues a root request, when this
	 * machine has nothing that will carry one out. Empty when it has.
	 *
	 * The request is still written either way — a converger that comes back
	 * finds it waiting — but a button must not look like it worked on a box
	 * where nothing will act on it.
	 */
	public static function root_actor_notice() {
		$warning = RootRequest::actorWarning();
		if ($warning === '') {
			return '';
		}
		return '<div class="alert alert-warning" role="status">'
			. htmlspecialchars($warning, ENT_QUOTES, 'UTF-8') . '</div>';
	}

	public static function settings_tab_menu($current = NULL) {
		$tab_menus = array('General Settings' => '/admin/admin_settings');
		// Payment settings live in the store plugin — only offer the tab when active.
		if (PluginHelper::isPluginActive('store')) {
			$tab_menus['Payment Settings'] = '/plugins/store/admin/admin_settings_payments';
		}
		$tab_menus['Email Settings'] = '/admin/admin_settings_email';
		// Nothing to administer when no active plugin declares a setting.
		require_once(PathHelper::getIncludePath('includes/SettingsDeclarations.php'));
		if (!empty(SettingsDeclarations::renderableSources())) {
			$tab_menus['Plugin Settings'] = '/admin/admin_settings_plugins';
		}
		return static::tab_menu($tab_menus, $current);
	}

	/**
	 * Render a second-level tab strip (pills) below a page's main tabs.
	 *
	 * @param array $tab_menus Associative array of label => url
	 * @param string|null $current Label of the active subtab
	 * @return string HTML
	 */
	public static function subtab_menu($tab_menus, $current = NULL) {
		$output = '<nav class="subtabs" aria-label="Section tabs">';
		foreach ($tab_menus as $name => $link) {
			if ($name == $current) {
				$output .= '<span class="subtab active" aria-current="page">' . htmlspecialchars($name) . '</span>';
			} else {
				$output .= '<a class="subtab" href="' . htmlspecialchars($link) . '">' . htmlspecialchars($name) . '</a>';
			}
		}
		$output .= '</nav>';
		return $output;
	}

	public function admin_header($options=array()) {
		$session = SessionControl::get_instance();
		$_GLOBALS['page_header_loaded'] = true;
		$options['vertical_menu'] =  MultiAdminMenu::getadminmenu($session->get_permission(), $options['menu-id'] ?? null);

		$options['hide_horizontal_menu'] = true;
		$options['full_width'] = true;

		// Store options for use in footer
		$this->header_options = $options;

		$this->public_header($options);

		// Check for no_page_card option
		if (isset($options['no_page_card']) && $options['no_page_card'] === true) {
			echo AdminPage::BeginPageNoCard($options);
		} else {
			echo AdminPage::BeginPage($options['readable_title'] ?? '', $options);
		}

		// Pending session flash messages (e.g. process_logic()'s error path)
		// render here on every admin page; admin_footer()'s
		// clear_clearable_messages() then removes them — shown once, then gone.
		echo $this->renderFlashMessages();

		// The site-wide notices: the hosting arrangement, a domain about to
		// lapse, mail that has stopped arriving — whatever an operator must know
		// wherever they are. Admin pages only, so this reaches permission 5 and
		// above by virtue of where it renders; every notice is silent unless it
		// has something to say (AdminNotices).
		echo AdminNotices::render();

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
		// NULL location = both GLOBAL and IN_PAGE — admin has one message region,
		// and renders every slot, so it takes them all.
		$messages = $session->get_messages($_SERVER['REQUEST_URI'] ?? '', NULL);
		$session->mark_shown($messages);
		$out = '';
		foreach ($messages as $msg) {
			$alert_class = 'alert-info';
			if ($msg->display_type == DisplayMessage::MESSAGE_ERROR)            $alert_class = 'alert-danger';
			elseif ($msg->display_type == DisplayMessage::MESSAGE_WARNING)      $alert_class = 'alert-warning';
			elseif ($msg->display_type == DisplayMessage::MESSAGE_ANNOUNCEMENT) $alert_class = 'alert-success';
			$out .= '<div class="alert ' . $alert_class . '" role="alert">';
			if ($msg->message_title) $out .= '<strong>' . htmlspecialchars($msg->message_title) . ':</strong> ';
			$out .= htmlspecialchars($msg->message);
			$report = $msg->report_link_html();
			if ($report !== '') $out .= ' ' . $report;
			$out .= '<button type="button" class="alert-close" aria-label="Close">&times;</button>';
			$out .= '</div>';
		}
		return $out;
	}

	/**
	 * A box listing the direct messages sent to one audience — an event's
	 * registrants, a group's members, one member — each linking to the
	 * message's delivery page.
	 *
	 * @param MultiDirectMessage $messages Already filtered (provider, reference) and paged
	 * @param array              $options  tableheader() options: title, altlinks, card
	 * @param Pager|null         $pager
	 */
	public function email_audience_table($messages, $options = array(), $pager = NULL) {
		$session = SessionControl::get_instance();
		$this->tableheader(array('Subject', 'Sent by', 'Status', 'Recipients', 'Time'), $options, $pager);
		foreach ($messages as $message) {
			$author = $message->get('dmg_usr_user_id_sender') ? new User($message->get('dmg_usr_user_id_sender'), TRUE) : NULL;
			$counts = $message->delivery_counts();
			$delivery = $counts['sent'] . ' of ' . (int)$message->get('dmg_recipient_count') . ' sent';
			if ($counts['failed'] > 0) {
				$delivery .= ', ' . $counts['failed'] . ' not sent';
			}
			$when = $message->get('dmg_sent_time') ?: $message->get('dmg_create_time');
			$this->disprow(array(
				'<a href="/admin/admin_direct_message?dmg_direct_message_id=' . $message->key . '">' . htmlspecialchars((string)$message->get('dmg_subject')) . '</a>',
				$author && $author->key ? htmlspecialchars($author->display_name()) : '(System)',
				$message->get_status_text(),
				$delivery,
				$when ? LibraryFunctions::convert_time($when, 'UTC', $session->get_timezone()) : '',
			));
		}
		$this->endtable($pager);
	}

	/** The direct messages to one audience, newest first, a page at a time. */
	public static function direct_messages_to(string $provider, int $reference_id, int $per_page, int $offset): MultiDirectMessage {
		return new MultiDirectMessage(
			array('dmg_provider' => $provider, 'dmg_reference_id' => $reference_id, 'deleted' => false),
			array('dmg_direct_message_id' => 'DESC'),
			$per_page,
			$offset);
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
