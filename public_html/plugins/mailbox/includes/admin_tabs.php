<?php
/**
 * Shared admin tab strip for the Mailbox plugin.
 *
 * One definition of the plugin's admin tabs, rendered through the platform's
 * theme-aware AdminPage::tab_menu() helper instead of a hand-rolled
 * <ul class="nav nav-tabs"> copy-pasted into every page.
 *
 * "Mailboxes" (the Gmail-style reader) is first and the default landing tab.
 * "Accounts" is the consolidated config tree (domains + mailboxes + forwarding
 * + IMAP feeds); the per-object editor pages all highlight the Accounts tab.
 * "Setup" (DNS/host diagnostics) is last.
 *
 * Usage in a page (after admin_header):
 *   require_once(PathHelper::getIncludePath('plugins/mailbox/includes/admin_tabs.php'));
 *   echo AdminPage::tab_menu(mailbox_admin_tabs(), 'Mailboxes');
 *
 * "Filters" (Gmail-parity inbound rules) sits between Accounts and Logs.
 * Relay surfaces are split across the tabs — setup/status on Setup,
 * configuration on Settings; the operator fleet console hangs off the Server
 * Manager dashboard — so there is no Relay tab.
 *
 * Deliverability reports have no tab for the same reason: the sender
 * inventory (admin_mailbox_reports) is a diagnostic reached from the Setup
 * tab's "Deliverability reports" row, the new-sender notification email, and
 * the report_filed lines on Logs — not a place anyone works daily. The page
 * highlights Setup, the way the per-object editors highlight Accounts.
 *
 * (A future declarative-tabs core enhancement — specs/declarative_admin_tabs.md
 * — would replace this helper with adminMenu children; kept self-contained for now.)
 *
 * @version 2.3
 */

if (!function_exists('mailbox_admin_tabs')) {
	/**
	 * @return array<string,string> tab label => target URL
	 */
	function mailbox_admin_tabs(): array {
		$base = '/plugins/mailbox/admin/';
		return array(
			'Mailboxes' => $base . 'admin_mailbox_reader',
			'Accounts'  => $base . 'admin_mailbox_accounts',
			'Filters'   => $base . 'admin_mailbox_filters',
			'Logs'      => $base . 'admin_mailbox_logs',
			'Setup'     => $base . 'admin_mailbox_setup',
			'Settings'  => $base . 'admin_mailbox_settings',
		);
	}
}
?>
