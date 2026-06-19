<?php
/**
 * Inbound Email - Mailbox Reader (Gmail-style).
 *
 * Two-pane Gmail layout: a left sidebar (mailbox switcher — the addresses this
 * viewer has been granted, each independently badged; "All mail" for
 * superadmins — plus filters + search) and a single main pane that shows EITHER
 * the conversation list OR an opened conversation full-width (with a back
 * arrow), never both side-by-side. HTML bodies render in a sandboxed iframe.
 *
 * All data and mutations go through the scoped AJAX endpoints
 * (/ajax/mailbox_*). Vanilla JS only. The single-message detail page is kept
 * for raw MIME / .eml download / deep links.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/admin_tabs.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/logic/admin_inbound_email_reader_logic.php'));

$page_vars = process_logic(admin_inbound_email_reader_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(
	array(
		'menu-id' => 'incoming',
		'breadcrumbs' => array(
			'Inbound Email' => '/plugins/inbound_email/admin/admin_inbound_email',
			'Mailbox' => '',
		),
		'session' => $session,
	)
);

echo AdminPage::tab_menu(inbound_email_admin_tabs(), 'Mailboxes');

// Reader assets — cache-busted by file mtime so CDN/browser caches never serve
// a stale stylesheet or script after an edit.
$asset_ver = function ($rel) {
	$path = PathHelper::getIncludePath('plugins/inbound_email/assets/' . $rel);
	return '/plugins/inbound_email/assets/' . $rel . '?v=' . (is_file($path) ? filemtime($path) : '1');
};
echo '<link rel="stylesheet" href="' . htmlspecialchars($asset_ver('mailbox_reader.css')) . '">';

// Config + seed data for the vanilla-JS reader.
$config = array(
	'csrf'              => $csrf_token,
	'mailboxesUrl'      => '/ajax/mailbox_mailboxes',
	'listUrl'           => '/ajax/mailbox_list',
	'threadUrl'         => '/ajax/mailbox_thread',
	'actionUrl'         => '/ajax/mailbox_action',
	'sendUrl'           => '/ajax/mailbox_send',
	'messageDetailBase' => '/plugins/inbound_email/admin/admin_inbound_email_message',
	'initialMailboxes'  => $initial_mailboxes,
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
	// clicked conversation, then submits it via fetch (no reload). Admin-only, so
	// FormWriter's single-use/expiring token would only get in the way of a
	// long-lived reader — disabled (csrf => false); the endpoint validates the
	// reader's persistent token instead. @see specs/outbound_reply_forward.md §4
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
			$compose->textinput('to', 'To', array('id' => 'mbx_to',
				'helptext' => 'Separate multiple addresses with commas.'));
			$compose->textinput('cc', 'Cc', array('id' => 'mbx_cc', 'placeholder' => 'Optional'));
			$compose->textinput('subject', 'Subject', array('id' => 'mbx_subject'));
			$compose->textarea('body', 'Message', array('id' => 'mbx_body', 'rows' => 10));
			$compose->fileinput('attachments[]', 'Attachments', array('id' => 'mbx_attachments', 'multiple' => true));
			$compose->submitbutton('mbx_send', 'Send');
			$compose->end_form();
			?>
		</div>
		<div class="mbx-list-view" id="mbx-list-view">
			<div class="mbx-list-header">
				<span id="mbx-list-title" class="mbx-list-title">All mail</span>
				<button type="button" id="mbx-refresh" class="mbx-iconbtn" title="Refresh">&#8635;</button>
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
$page->admin_footer();
?>
