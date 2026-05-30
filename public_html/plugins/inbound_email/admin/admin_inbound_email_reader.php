<?php
/**
 * Inbound Email - Mailbox Reader (Gmail-style).
 *
 * Replaces the old flat Mailbox table. Left rail = mailbox switcher (the
 * addresses this viewer has been granted, each independently badged; "All mail"
 * for superadmins) + filters + search. Center = conversation list. Right =
 * reading pane, with HTML bodies rendered in a sandboxed iframe.
 *
 * All data and mutations go through the scoped AJAX endpoints
 * (/ajax/mailbox_*). Vanilla JS only. The single-message detail page is kept
 * for raw MIME / .eml download / deep links.
 *
 * @version 1.0
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

echo AdminPage::tab_menu(inbound_email_admin_tabs(), 'Mailbox');

// Reader styles.
echo '<link rel="stylesheet" href="/plugins/inbound_email/assets/mailbox_reader.css">';

// Config + seed data for the vanilla-JS reader.
$config = array(
	'csrf'              => $csrf_token,
	'mailboxesUrl'      => '/ajax/mailbox_mailboxes',
	'listUrl'           => '/ajax/mailbox_list',
	'threadUrl'         => '/ajax/mailbox_thread',
	'actionUrl'         => '/ajax/mailbox_action',
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
			<h2 class="mbx-rail-title">Filters</h2>
			<div class="mbx-filters">
				<button type="button" class="mbx-filter active" data-filter="all">All</button>
				<button type="button" class="mbx-filter" data-filter="unread">Unread</button>
				<button type="button" class="mbx-filter" data-filter="starred">Starred</button>
			</div>
			<input type="search" id="mbx-search" class="mbx-search" placeholder="Search mail…" autocomplete="off">
		</div>
	</aside>

	<section class="mbx-list-pane">
		<div class="mbx-list-header">
			<span id="mbx-list-title" class="mbx-list-title">All mail</span>
			<button type="button" id="mbx-refresh" class="mbx-iconbtn" title="Refresh">&#8635;</button>
		</div>
		<ul id="mbx-threads" class="mbx-threads"></ul>
		<div class="mbx-list-footer">
			<button type="button" id="mbx-more" class="mbx-more" hidden>Load more</button>
		</div>
	</section>

	<section class="mbx-read-pane" id="mbx-read-pane">
		<div class="mbx-empty" id="mbx-read-empty">Select a conversation to read.</div>
		<div class="mbx-thread" id="mbx-thread" hidden></div>
	</section>
</div>
<script src="/plugins/inbound_email/assets/mailbox_reader.js"></script>
<?php
$page->admin_footer();
?>
