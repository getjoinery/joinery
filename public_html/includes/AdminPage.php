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
		$json = json_encode($id);
		$unverified_url = (string)$unverified_url;
		if ($unverified_url !== '' && ($unverified_url[0] !== '/' || (isset($unverified_url[1]) && $unverified_url[1] === '/'))) {
			$unverified_url = '';           // same-site relative only, never an open redirect
		}
		$json_unverified = json_encode($unverified_url);

		return <<<HTML
<div class="jy-rootreq" data-request="{$safe}" data-state="queued">
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
<script>
(function () {
	var id = {$json};
	var unverifiedUrl = {$json_unverified};
	var box = document.querySelector('.jy-rootreq[data-request="' + id + '"]');
	if (!box) { return; }
	var stateEl = box.querySelector('.jy-rootreq__state');
	var noteEl  = box.querySelector('.jy-rootreq__note');
	var logEl   = box.querySelector('.jy-rootreq__log');
	var meta    = document.querySelector('meta[name="joinery-api-csrf"]');
	var words   = { queued: 'Queued', running: 'Running', done: 'Done', failed: 'Failed' };

	function tick() {
		fetch('/api/v1/root_request_status', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-Joinery-Csrf': meta ? meta.content : '' },
			body: JSON.stringify({ id: id })
		}).then(function (r) { return r.json(); }).then(function (d) {
			var data = (d && d.data) ? d.data : d;
			if (!data || !data.state) { setTimeout(tick, 5000); return; }
			box.setAttribute('data-state', data.state);
			stateEl.textContent = words[data.state] || data.state;
			if (data.transcript && data.transcript !== logEl.textContent) {
				logEl.textContent = data.transcript;
				logEl.scrollTop = logEl.scrollHeight;
			}
			if (data.state === 'failed' && data.exit_code === 3 && unverifiedUrl) {
				// Not ours: the warning page decides what happens next.
				noteEl.textContent = 'the package did not verify — opening the warning';
				window.location = unverifiedUrl;
				return;
			}
			if (data.state === 'queued' && data.actor !== 'present') {
				noteEl.textContent = 'waiting for this machine’s root actor';
			} else if (data.state === 'failed' && data.abandoned) {
				noteEl.textContent = 'interrupted — the run carrying it out was killed; submit it again';
			} else if (data.state === 'failed') {
				noteEl.textContent = 'exit ' + (data.exit_code === null ? '?' : data.exit_code);
			} else {
				noteEl.textContent = '';
			}
			if (!data.finished) { setTimeout(tick, 2000); }
		}).catch(function () { setTimeout(tick, 5000); });
	}
	tick();
})();
</script>
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
			$out .= '<button type="button" class="alert-close" aria-label="Close">&times;</button>';
			$out .= '</div>';
		}
		return $out;
	}

	/**
	 * A box listing the emails sent to one audience — an event's registrants,
	 * a group's members — each linking to the email's delivery page.
	 *
	 * @param MultiEmail $emails  Already filtered (recipient_group) and paged
	 * @param array      $options tableheader() options: title, altlinks, card
	 * @param Pager|null $pager
	 */
	public function email_audience_table($emails, $options = array(), $pager = NULL) {
		$session = SessionControl::get_instance();
		$this->tableheader(array('Subject', 'Sent by', 'Status', 'Recipients', 'Time'), $options, $pager);
		foreach ($emails as $email) {
			$author = $email->get('eml_usr_user_id') ? $email->get_user() : NULL;
			$sent = count(new MultiEmailRecipient(array('email_id' => $email->key, 'sent' => true)));
			$total = count(new MultiEmailRecipient(array('email_id' => $email->key)));
			if ($email->get('eml_status') == Email::EMAIL_SENT && $email->get('eml_sent_time')) {
				$time = 'Sent ' . LibraryFunctions::convert_time($email->get('eml_sent_time'), 'UTC', $session->get_timezone());
			} else if ($email->get('eml_status') == Email::EMAIL_QUEUED && $email->get('eml_scheduled_time')) {
				$time = 'Queued ' . LibraryFunctions::convert_time($email->get('eml_scheduled_time'), 'UTC', $session->get_timezone());
			} else {
				$time = '';
			}
			$this->disprow(array(
				'<a href="/admin/admin_email?eml_email_id=' . $email->key . '">' . htmlspecialchars((string)$email->get('eml_subject')) . '</a>',
				$author && $author->key ? htmlspecialchars($author->display_name()) : '(System)',
				$email->get_status_text(),
				$sent . ' of ' . $total . ' sent',
				$time,
			));
		}
		$this->endtable($pager);
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
