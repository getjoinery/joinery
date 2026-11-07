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
 * @version 1.4.0 - looked_at: who has read each of the member's mailboxes without a grant, last 90 days (MailboxReadAudit)
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

	// Who has looked (mail_access_hardening WP1): every read of each mailbox by
	// someone holding no grant on it, in the last 90 days, grouped by who and
	// the day in the member's own time zone, with how many messages through
	// which surface. Nothing here deletes a row.
	$looked_at = array();
	$names = array();
	$tz = $session->get_timezone();
	foreach ($mailboxes as $mb) {
		$groups = array();
		foreach (MailboxReadAudit::forMailbox($mb['alias_id'], MailboxReadAudit::HOLDER_VIEW_DAYS) as $read) {
			$viewer_id = $read['viewer_id'];
			if (!isset($names[$viewer_id])) {
				$viewer = new User($viewer_id, TRUE);
				$names[$viewer_id] = $viewer->key ? $viewer->display_name() : 'A removed account';
			}
			$day = LibraryFunctions::convert_time($read['time'], 'UTC', $tz, 'Y-m-d');
			$key = $viewer_id . '|' . $day;
			if (!isset($groups[$key])) {
				$groups[$key] = array(
					'viewer'   => $names[$viewer_id],
					'day'      => LibraryFunctions::convert_time($read['time'], 'UTC', $tz, 'M j, Y'),
					'surfaces' => array(),
					'count'    => 0,
				);
			}
			$groups[$key]['surfaces'][$read['surface']] = ($groups[$key]['surfaces'][$read['surface']] ?? 0) + 1;
			$groups[$key]['count'] += $read['count'];
		}
		$looked_at[$mb['alias_id']] = array_values($groups);
	}

	return LogicResult::render(array(
		'session'        => $session,
		'settings'       => $settings,
		'mailboxes'      => $mailboxes,
		// alias id => the reads of that mailbox by non-holders, newest first.
		'looked_at'      => $looked_at,
		'looked_at_days' => MailboxReadAudit::HOLDER_VIEW_DAYS,
		// Importing old mail is a deployment switch, so the way to it only
		// appears where there is something to reach.
		'import_enabled' => (bool)$settings->get_setting('mailbox_import_enabled'),
		// AI on end-to-end encrypted mail runs against the member's own model
		// (specs/fortress_mail_device_ai.md § R2): where it answers is set here,
		// and only a member with such a mailbox has a reason to.
		'has_fortress'     => (bool)array_filter(array_column($mailboxes, 'fortress')),
		'device_ai_origin' => MailboxDeviceAiHost::originForUser($user_id),
		'device_ai_site_model' => MailboxDeviceAi::siteModel(Access::permits(Access::staffLevel(), $session)),
	));
}
?>
