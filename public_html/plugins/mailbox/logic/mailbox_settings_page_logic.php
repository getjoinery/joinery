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
 * Members with an end-to-end encrypted (Fortress) mailbox also set where their
 * own AI model answers, through the mailbox/device_ai_host API action.
 *
 * @version 1.3.0 - device_ai_site_model: the site's own model, offered with one click
 * @version 1.2.0 - has_fortress, device_ai_origin: where the member's own AI model answers
 * @version 1.1.0
 */

function mailbox_settings_page_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grants_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_aliases_class.php'));

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
			'fortress'  => $alias->security_level() === InboundEmailDomain::LEVEL_FORTRESS,
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
		// AI on end-to-end encrypted mail runs against the member's own model
		// (specs/fortress_mail_device_ai.md § R2): where it answers is set here,
		// and only a member with such a mailbox has a reason to.
		'has_fortress'     => (bool)array_filter(array_column($mailboxes, 'fortress')),
		'device_ai_origin' => MailboxDeviceAiHost::originForUser($user_id),
		'device_ai_site_model' => MailboxDeviceAi::siteModel((int)$session->get_permission() >= 5),
	));
}
?>
