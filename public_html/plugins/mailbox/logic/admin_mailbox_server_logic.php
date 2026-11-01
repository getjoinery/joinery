<?php
/**
 * Logic for the Inbound Email Server tab.
 *
 * Everything that belongs to the whole mail server rather than one mailbox or
 * domain: the relay (whether one takes this server's mail in, its health, its
 * lifecycle), this server's mail hostname and public IP, and the full health
 * run across every hosted domain. Nothing here needs a mailbox chosen first,
 * so the tab opens straight onto it. Setup for one mailbox or domain is the
 * Setup tab (admin_mailbox_setup_logic.php).
 *
 * POST actions: the relay's actions (relay_admin.php), saving the mail
 * hostname / public IP, and the one-click fixes a check row carries
 * (setup_fix_actions.php). Each redirects so the next render reads fresh state.
 *
 * @version 1.0
 */
function admin_mailbox_server_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/relay_admin.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/setup_fix_actions.php'));

	$session  = SessionControl::get_instance();
	$settings = Globalvars::get_instance();
	$base     = '/plugins/mailbox/admin/admin_mailbox_server';

	// Every action is a form submit (POST). A GET must reach none of them: a
	// link could otherwise enable the relay or rewrite the mail identity.
	$posted = LibraryFunctions::isFormSubmission() ? $input : array();

	// Relay actions (lifecycle, provisioning, hosted-slot enrollment, the local
	// listener). First, because the provision form also carries a
	// mail_hostname field that the identity save below would otherwise take.
	$relay_redirect = admin_mailbox_relay_tenant_actions($posted, $session, $base);
	if ($relay_redirect !== null) {
		return $relay_redirect;
	}

	// This server's mail identity.
	if (isset($posted['mail_hostname']) || isset($posted['public_ip'])) {
		mailbox_setup_write_setting('mailbox_mail_hostname', strtolower(trim($posted['mail_hostname'] ?? '')));
		mailbox_setup_write_setting('mailbox_public_ip', trim($posted['public_ip'] ?? ''));
		$session->save_message(new DisplayMessage(
			'Mail identity saved.', 'Saved', '~/plugins/mailbox/admin/~',
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
		return LogicResult::redirect($base . '#mail-identity');
	}

	$fix_redirect = mailbox_setup_fix_action($posted, $session, $base);
	if ($fix_redirect !== null) {
		return $fix_redirect;
	}

	$checker = new InboundEmailSetupCheck();

	return LogicResult::render(array(
		'session'              => $session,
		'base'                 => $base,
		'relay_section'        => admin_mailbox_relay_tenant_vars(),
		'has_mail_server'      => mailbox_site_has_mail_server(),
		'edit_identity'        => !empty($input['edit_identity']),
		'mail_hostname'        => $checker->getMailHostname(),
		'public_ip'            => $checker->getPublicIp(),
		'public_ip_private'    => $checker->publicIpIsPrivate(),
		'configured_public_ip' => trim((string)$settings->get_setting('mailbox_public_ip')),
		// The whole battery, every hosted domain. Real work (DNS lookups, host
		// probes), and the reason anyone opens this tab.
		'results'              => $checker->run(null, null),
	));
}
?>
