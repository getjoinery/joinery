<?php
/**
 * Mailbox Reader mount — the one reader UI, shared by its two entry points:
 * the admin page (admin_mailbox_reader) and the member page
 * (/profile/mailbox/mailbox). Emits the reader config, the two-pane
 * skeleton, the compose form, and the reader script. The mounts differ only
 * in chrome (admin vs theme page) and in the URLs they hand the reader —
 * the endpoints themselves scope every read and write via MailboxViewer.
 *
 * Compose attachment UX matches the AI chat composer (paperclip button, pending
 * chips with remove, drag-and-drop onto the open panel) — see
 * specs/implemented/inbound_email_compose_attachments.md. The caps advertised
 * in window.MAILBOX_READER (max_files/max_file_bytes/max_total_bytes) are read
 * from MailboxSender's constants so the client-side preflight can never drift
 * from the server's real policy.
 *
 * A "New message" button in the list header opens the same compose panel in
 * `new` mode (no source thread): the reader JS shows a From selector (the
 * `alias_id` dropinput below, hidden and populated from the already-loaded
 * mailbox list for reply/forward modes) and clears the recipient/subject/body
 * fields. See specs/implemented/inbound_email_new_message_compose.md.
 *
 * Compose maturity (specs/mailbox_compose_maturity.md § Phase 1): the plain body
 * textarea is a rich-text contenteditable + toolbar; a Bcc field hides behind a
 * toggle; inline images paste/drag into the editor. The reader JS owns all of it.
 *
 * The mount also emits the quiet lowering convergence
 * (specs/mailbox_lowering_unseal.md § 6): a signed-in holder with sealed rows
 * on non-sealing domains converges them in the background via
 * mailbox/unseal_batch, silently stopping while their vault is locked.
 *
 * @version 1.8.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxSender.php'));

/**
 * Render the reader.
 *
 * @param object $page AdminPage or PublicPage — anything with getFormWriter().
 * @param array  $opts
 *   - csrf_token          (string, required) reader session token
 *   - initial_mailboxes   (array, required)  switcher seed data
 *   - attachment_url_base (string, required) per-attachment download endpoint
 *   - message_detail_base (string|null)      single-message deep-link page, or
 *                                            null to omit deep links (member mount)
 *   - setup_url_base      (string|null)      admin Setup page prefix, ready for an
 *                                            alias id. Its presence is also what
 *                                            turns on the setup check: the reader
 *                                            asks mailbox/setup_status for the
 *                                            open mailbox and banners a verdict
 *                                            of `attention` at the top of the
 *                                            list. Null on the member mount: mail
 *                                            setup is operator work, and a member
 *                                            reading their own mail has no
 *                                            business being sent to it.
 */
function mailbox_render_mailbox_reader($page, array $opts): void {
	$csrf_token = (string)$opts['csrf_token'];

	// Reader script — cache-busted by file mtime so CDN/browser caches never
	// serve a stale script after an edit. The stylesheet (mailbox_reader.css)
	// loads via the plugin "styles" declaration in plugin.json, not here.
	$asset_ver = function ($rel) {
		$path = PathHelper::getIncludePath('plugins/mailbox/assets/' . $rel);
		return '/plugins/mailbox/assets/' . $rel . '?v=' . (is_file($path) ? filemtime($path) : '1');
	};

	// Config + seed data for the vanilla-JS reader.
	$config = array(
		'csrf'              => $csrf_token,
		'mailboxesUrl'      => '/api/v1/action/mailbox/mailboxes',
		'listUrl'           => '/api/v1/action/mailbox/thread_list',
		'threadUrl'         => '/api/v1/action/mailbox/thread',
		'actionUrl'         => '/api/v1/action/mailbox/thread_action',
		'sendUrl'           => '/api/v1/action/mailbox/send',
		'draftSaveUrl'      => '/api/v1/action/mailbox/draft_save',
		'draftGetUrl'       => '/api/v1/action/mailbox/draft_get',
		'draftDeleteUrl'    => '/api/v1/action/mailbox/draft_delete',
		'draftAttachmentDeleteUrl' => '/api/v1/action/mailbox/draft_attachment_delete',
		'signatureSaveUrl'  => '/api/v1/action/mailbox/signature_save',
		'contactsUrl'       => '/api/v1/action/mailbox/contacts',
		'contactDeleteUrl'  => '/api/v1/action/mailbox/contact_delete',
		'contactsImportUrl' => '/api/v1/action/mailbox/contacts_import',
		'senderContextUrl'  => '/api/v1/action/mailbox/sender_context',
		'setupStatusUrl'    => '/api/v1/action/mailbox/setup_status',
		// The member-context panel is admin-only (member records are operator data);
		// the endpoint enforces it, this flag just suppresses the fetch for non-admins.
		'canSeeContext'     => (SessionControl::get_instance()->get_permission() >= 5),
		'messageDetailBase' => $opts['message_detail_base'] ?? null,
		'setupUrlBase'      => $opts['setup_url_base'] ?? null,
		'attachmentUrlBase' => (string)$opts['attachment_url_base'],
		'initialMailboxes'  => $opts['initial_mailboxes'],
		'maxFiles'          => MailboxSender::MAX_UPLOAD_FILES,
		'maxFileBytes'      => MailboxSender::MAX_UPLOAD_BYTES,
		'maxTotalBytes'     => MailboxSender::MAX_TOTAL_BYTES,
	);
	echo '<script>window.MAILBOX_READER = ' . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
	?>
<div id="mbx-reader" class="mbx-reader">
	<aside class="mbx-rail">
		<div class="mbx-rail-section">
			<h2 class="mbx-rail-title">Mailboxes</h2>
			<ul id="mbx-mailboxes" class="mbx-mailbox-list"></ul>
		</div>
		<div class="mbx-rail-section">
			<h2 class="mbx-rail-title">Search</h2>
			<input type="search" id="mbx-search" class="mbx-search" placeholder="Search mail…" autocomplete="off">
		</div>
	</aside>

	<section class="mbx-main"><?php
	// Compose panel — a real FormWriter form rendered once, hidden; the reader's
	// JS shows it and populates To/Cc/Subject + the hidden context fields per the
	// clicked conversation, then submits it via fetch (no reload). FormWriter's
	// single-use/expiring token would only get in the way of a long-lived
	// reader — disabled (csrf => false); the endpoint validates the reader's
	// persistent token instead. @see specs/outbound_reply_forward.md §4
	$compose = $page->getFormWriter('mbx_compose_form', array(
		'action'  => '/api/v1/action/mailbox/send',
		'method'  => 'POST',
		'enctype' => 'multipart/form-data',
		'csrf'    => false,
	));
	?>
		<div class="mbx-compose" id="mbx-compose" hidden>
			<div class="mbx-compose-head">
				<span class="mbx-compose-title" id="mbx-compose-title">Reply</span>
				<button type="button" class="mbx-iconbtn" id="mbx-compose-discard" title="Discard draft">&#128465;</button>
					<button type="button" class="mbx-iconbtn" id="mbx-compose-close" title="Save &amp; close">&times;</button>
			</div>
			<div class="mbx-compose-error" id="mbx-compose-error" hidden></div>
			<?php
			$compose->begin_form();
			$compose->hiddeninput('mode', '', array('value' => '', 'id' => 'mbx_mode'));
			$compose->hiddeninput('source_id', '', array('value' => '', 'id' => 'mbx_source_id'));
			$compose->hiddeninput('_csrf_token', '', array('value' => $csrf_token, 'id' => 'mbx_csrf'));
			// No FormWriter validation rules: the reader submits this form by fetch,
			// and FormWriter's client validator does a native (full-page) submit when
			// it passes — which would break the SPA. Validation is the reader JS (To
			// non-empty) plus full server-side validation in MailboxSender.
			?>
			<div class="mbx-from-row" id="mbx-from-row" hidden>
			<?php
			// Options filled client-side from state.mailboxes (the switcher data
			// already loaded at init) — reply/reply_all/forward keep their implicit
			// identity and never show this; new-message mode always shows it, even
			// with a single grant, as a plain statement of the sending address.
			$compose->dropinput('alias_id', 'From', array('id' => 'mbx_alias_id', 'options' => array()));
			?>
			</div>
			<?php
			$compose->textinput('to', 'To', array('id' => 'mbx_to',
				'helptext' => 'Separate multiple addresses with commas.'));
			$compose->textinput('cc', 'Cc', array('id' => 'mbx_cc', 'placeholder' => 'Optional'));
			// Bcc is hidden behind a toggle (Gmail-style). It rides its own sealed
			// column server-side (iem_bcc), never merged into the recipient list.
			echo '<div class="mbx-bcc-toggle-row"><button type="button" class="mbx-bcc-toggle" id="mbx-bcc-toggle">Add Bcc</button></div>';
			echo '<div class="mbx-bcc-row" id="mbx-bcc-row" hidden>';
			$compose->textinput('bcc', 'Bcc', array('id' => 'mbx_bcc',
				'placeholder' => 'Blind copy — hidden from other recipients'));
			echo '</div>';
			$compose->textinput('subject', 'Subject', array('id' => 'mbx_subject'));
			// Rich-text editor: a minimal, dependency-free contenteditable + toolbar
			// (specs/mailbox_compose_maturity.md § Phase 1). The reader JS serializes
			// its HTML into body_html (server-sanitized, authoritative) and its text
			// into the plaintext `body` fallback; the plain textarea is gone.
			?>
			<div class="mbx-field mbx-richwrap">
				<span class="mbx-rich-label">Message</span>
				<div class="mbx-toolbar" id="mbx-toolbar" role="toolbar" aria-label="Formatting">
					<button type="button" class="mbx-tb" data-cmd="bold" title="Bold (Ctrl+B)"><b>B</b></button>
					<button type="button" class="mbx-tb" data-cmd="italic" title="Italic (Ctrl+I)"><em>I</em></button>
					<button type="button" class="mbx-tb" data-cmd="underline" title="Underline (Ctrl+U)"><span style="text-decoration:underline">U</span></button>
					<span class="mbx-tb-sep" aria-hidden="true"></span>
					<button type="button" class="mbx-tb" data-cmd="insertUnorderedList" title="Bulleted list">&#8226; List</button>
					<button type="button" class="mbx-tb" data-cmd="insertOrderedList" title="Numbered list">1. List</button>
					<span class="mbx-tb-sep" aria-hidden="true"></span>
					<button type="button" class="mbx-tb" data-cmd="createLink" title="Insert link">&#128279;</button>
					<button type="button" class="mbx-tb" data-cmd="removeFormat" title="Clear formatting">&#10005;</button>
				</div>
				<div class="mbx-rich" id="mbx_body_rich" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="Write your message…"></div>
			</div>
			<div class="mbx-attach-strip" id="mbx-attach-strip" hidden></div>
			<div class="mbx-compose-actions">
				<input type="file" id="mbx-file-input" class="mbx-file-input" multiple>
				<button type="button" id="mbx-attach-btn" class="mbx-iconbtn mbx-attach-btn" title="Attach files" aria-label="Attach files">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
				</button>
				<?php $compose->submitbutton('mbx_send', 'Send'); ?>
			</div>
			<?php
			$compose->end_form();
			?>
		</div>
		<div class="mbx-list-view" id="mbx-list-view">
			<div class="mbx-list-header">
				<span id="mbx-list-title" class="mbx-list-title">All mail</span>
				<div class="mbx-list-header-actions">
					<button type="button" id="mbx-new-message" class="mbx-new-btn" hidden>+ New message</button>
					<button type="button" id="mbx-refresh" class="mbx-iconbtn" title="Refresh">&#8635;</button>
				</div>
			</div>
			<ul id="mbx-threads" class="mbx-threads"></ul>
			<div class="mbx-list-footer">
				<button type="button" id="mbx-more" class="mbx-more" hidden>Load more</button>
			</div>
		</div>

		<div class="mbx-read-view" id="mbx-read-pane">
			<div class="mbx-thread" id="mbx-thread"></div>
		</div>
	</section>
	<!-- Member-context panel (§ Phase 5): who the correspondent is on this platform.
	     Admin-only, lazy-filled on thread open, collapsible, hidden below a width
	     breakpoint. The reader JS shows/populates it. -->
	<aside class="mbx-context" id="mbx-context" hidden></aside>
</div>
<!-- WebAuthn helper for the in-reader vault unlock ceremony (locked-state contract).
     Not deferred: it must define window.JoineryPasskeys before the reader script
     below runs. Same include convention as views/login.php and profile/security.php. -->
<script src="/assets/js/passkeys.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/passkeys.js')) ?: '1'; ?>"></script>
<script src="<?php echo htmlspecialchars($asset_ver('mailbox_reader.js')); ?>"></script>
	<?php
	mailbox_reader_emit_unseal_convergence();
}

/**
 * Quiet lowering convergence (specs/mailbox_lowering_unseal.md § 6): when the
 * signed-in user still owns sealed (or pending-parse) rows on domains that no
 * longer seal, loop mailbox/unseal_batch in the background until done. The
 * domain owner already made the decision when they lowered the level — no
 * banner, no prompt. Window closed → the action answers locked and the loop
 * stops silently; the next unlock-and-visit converges the rest.
 */
function mailbox_reader_emit_unseal_convergence(): void {
	$user_id = (int)SessionControl::get_instance()->get_user_id();
	if (!$user_id) {
		return;
	}
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
	try {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT 1 FROM iem_inbound_email_messages
			 WHERE iem_sealed_owner_user_id = ?
			   AND (iem_content_sealed = true OR iem_pending_parse = true)
			   AND iem_delete_time IS NULL
			   AND iem_ied_inbound_email_domain_id IN (
					SELECT ied_inbound_email_domain_id FROM ied_inbound_email_domains
					WHERE ied_security_level NOT IN ('" . InboundEmailDomain::LEVEL_PRIVATE . "','"
						. InboundEmailDomain::LEVEL_FORTRESS . "') AND ied_delete_time IS NULL)
			 LIMIT 1");
		$stmt->execute(array($user_id));
		if (!$stmt->fetchColumn()) {
			return;
		}
	} catch (\Throwable $e) {
		return; // a probe failure must never break the reader
	}
	?>
<script>
(function () {
	var csrf = (document.querySelector('meta[name="joinery-api-csrf"]') || {}).content || '';
	function batch() {
		fetch('/api/v1/action/mailbox/unseal_batch', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-Joinery-Csrf': csrf },
			body: JSON.stringify({})
		}).then(function (r) { return r.json(); }).then(function (j) {
			var d = (j && j.data) || {};
			if (d.locked || !d.unsealed || !(d.own_remaining > 0)) return;
			batch();
		}).catch(function () { /* silent — the next visit resumes */ });
	}
	batch();
})();
</script>
	<?php
}
