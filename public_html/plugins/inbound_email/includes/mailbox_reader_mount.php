<?php
/**
 * Mailbox Reader mount — the one reader UI, shared by its two entry points:
 * the admin page (admin_inbound_email_reader) and the member page
 * (/profile/inbound_email/mailbox). Emits the reader config, the two-pane
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
 * @version 1.2.0
 */

require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/MailboxSender.php'));

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
 */
function inbound_email_render_mailbox_reader($page, array $opts): void {
	$csrf_token = (string)$opts['csrf_token'];

	// Reader script — cache-busted by file mtime so CDN/browser caches never
	// serve a stale script after an edit. The stylesheet (mailbox_reader.css)
	// loads via the plugin "styles" declaration in plugin.json, not here.
	$asset_ver = function ($rel) {
		$path = PathHelper::getIncludePath('plugins/inbound_email/assets/' . $rel);
		return '/plugins/inbound_email/assets/' . $rel . '?v=' . (is_file($path) ? filemtime($path) : '1');
	};

	// Config + seed data for the vanilla-JS reader.
	$config = array(
		'csrf'              => $csrf_token,
		'mailboxesUrl'      => '/ajax/mailbox_mailboxes',
		'listUrl'           => '/ajax/mailbox_list',
		'threadUrl'         => '/ajax/mailbox_thread',
		'actionUrl'         => '/ajax/mailbox_action',
		'sendUrl'           => '/ajax/mailbox_send',
		'messageDetailBase' => $opts['message_detail_base'] ?? null,
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
		'action'  => '/ajax/mailbox_send',
		'method'  => 'POST',
		'enctype' => 'multipart/form-data',
		'csrf'    => false,
	));
	?>
		<div class="mbx-compose" id="mbx-compose" hidden>
			<div class="mbx-compose-head">
				<span class="mbx-compose-title" id="mbx-compose-title">Reply</span>
				<button type="button" class="mbx-iconbtn" id="mbx-compose-close" title="Discard">&times;</button>
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
			$compose->textinput('subject', 'Subject', array('id' => 'mbx_subject'));
			$compose->textarea('body', 'Message', array('id' => 'mbx_body', 'rows' => 10));
			?>
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
</div>
<script src="<?php echo htmlspecialchars($asset_ver('mailbox_reader.js')); ?>"></script>
	<?php
}
