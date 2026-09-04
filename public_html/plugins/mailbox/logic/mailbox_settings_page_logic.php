<?php
/**
 * Logic for the member Email settings section (/profile/mailbox/settings).
 *
 * The one place a member sets up their mail: the signature they sign with, and
 * the way on to their filters and to bringing old mail in.
 *
 * A signature belongs to a person and a mailbox, not to a mailbox alone: it
 * lives on the grant, so two people sharing a mailbox sign their own way. This
 * lists the mailboxes the signed-in member holds a grant on, each with the
 * signature they have set for it. Saving goes through the mailbox/signature_save
 * API action, which sanitizes and writes the caller's own grant.
 *
 * @version 1.1.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function mailbox_settings_page_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));

	$session = SessionControl::get_instance();
	if (!intval($session->get_user_id())) {
		return LogicResult::redirect('/login?return=' . urlencode('/profile/mailbox/settings'));
	}
	$settings = Globalvars::get_instance();
	$user_id = intval($session->get_user_id());

	// Grants, not accessible mailboxes: an all-access superadmin can read every
	// mailbox on the deployment but signs only as the ones they are a member of.
	$mailboxes = array();
	foreach (InboundEmailMailboxGrant::alias_ids_for_user($user_id) as $alias_id) {
		$alias = new InboundEmailAlias(intval($alias_id), TRUE);
		if (!$alias->key || $alias->get('iea_delete_time')) {
			continue;
		}
		$mailboxes[] = array(
			'alias_id'  => intval($alias->key),
			'address'   => (string)$alias->get_full_address(),
			'signature' => InboundEmailMailboxGrant::signatureFor($user_id, intval($alias->key)),
		);
	}
	usort($mailboxes, function ($a, $b) { return strcasecmp($a['address'], $b['address']); });

	return LogicResult::render(array(
		'session'        => $session,
		'settings'       => $settings,
		'mailboxes'      => $mailboxes,
		// Importing old mail is a deployment switch, so the way to it only
		// appears where there is something to reach.
		'import_enabled' => (bool)$settings->get_setting('mailbox_import_enabled'),
	));
}
?>
