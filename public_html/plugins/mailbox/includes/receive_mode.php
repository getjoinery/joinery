<?php
/**
 * Mailbox - deployment receive mode (the relay-or-direct choice).
 *
 * Every hosted domain's DNS prescription hangs on one deployment-level fact:
 * does mail come straight to this server, or does a relay front it so the
 * server's address stays hidden? This helper resolves that fact.
 *
 * IT IS A SETTING, NOT A GATE (specs/mailbox_relay_surface_simplification.md).
 * An undecided deployment receives directly and works; the choice is made by
 * the relay itself — enabling it sets relay, disabling it sets direct — on the
 * Setup tab's Relay section, and can be changed at any time. A relay is only
 * load-bearing under the Seal at the relay add-on, so demanding the answer
 * before any domain has asked for it asked the operator to decide about infrastructure they
 * may never need, in front of every mailbox page.
 *
 * The choice belongs to the admin: a relay provisioned as part of setup does
 * NOT silently decide it. Resolution order:
 *   1. The stored choice (mailbox_receive_mode setting) => its value.
 *   2. Live domains already registered => report what the deployment is
 *      actually doing (relay row => 'relay', else 'direct').
 *   3. Otherwise '' — undecided, which every consumer treats as direct.
 *
 * A SITE CONTAINER HAS NO MAIL SERVER (specs/multi_tenant_docker_hosts.md WP9):
 * no Postfix and no rspamd. Mail that arrives by SMTP can then reach it only
 * through a relay, so it never receives directly, whatever the setting says. A
 * webhook provider (Mailgun, SES, SendGrid) and an IMAP feed need no mail
 * server, and work in a container as anywhere else.
 *
 * @version 1.9 - a site container whose mail arrives by SMTP receives through a relay only
 *                (mailbox_site_has_mail_server, mailbox_needs_relay)
 * @version 1.8 - no choice card: the receive mode follows the relay's Enable / Disable
 *                (relay_admin.php); the comparison and its handler are gone
 * @version 1.7 - the settled-state sentence moves into the Relay section (relay_section.php)
 * @version 1.6 - the relay row names the Seal at the relay add-on
 * @version 1.5 - IMAP-source domains do not decide the receive topology
 * @version 1.4 - a settled deployment reads its state in a sentence; the
 *                comparison is a decision aid and waits behind a disclosure
 * @version 1.3 - the choice no longer gates the mailbox surfaces
 */

/**
 * The resolution matrix as a pure function (unit-tested directly).
 *
 * @return string 'relay' | 'direct' | '' (undecided)
 */
function mailbox_receive_mode_resolve(bool $has_relay, string $setting, bool $has_domains,
		bool $relay_only = false): string {
	if ($setting === 'direct' || $setting === 'relay') {
		$mode = $setting;
	} elseif ($has_domains) {
		$mode = $has_relay ? 'relay' : 'direct';
	} else {
		$mode = '';
	}
	// A site that cannot receive directly (mailbox_needs_relay) is never
	// direct: what would have been direct is a relay it does not have yet.
	return ($relay_only && $mode === 'direct') ? 'relay' : $mode;
}

/**
 * Whether renting a slot on the operator's shared relay is offered to users.
 * Off for V1 launch — the fleet is not customer-facing yet. Gates every
 * tenant-side hosted-relay surface (the Setup Relay section's Hosted relay
 * block, the Settings connection box, the live fleet-status fetch) in one
 * place; flip to true when the hosted offering launches.
 */
function mailbox_hosted_relay_offered(): bool {
	return false;
}

/** True when a live relay row (hosted slot or self-hosted) exists. */
function mailbox_receive_relay_exists(): bool {
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relays_class.php'));
	$relays = new MultiMailboxRelay(array('deleted' => false));
	$relays->load();
	return count($relays) > 0;
}

/** The deployment's resolved receive mode. */
function mailbox_receive_mode(): string {
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domains_class.php'));

	// Only a domain this deployment actually receives for counts as a decided
	// topology. An IMAP-source anchor (gmail.com behind a connected account)
	// receives at its provider, so an IMAP-only deployment stays undecided
	// (specs/imap_source_domain_boundaries.md § 3).
	$setting = (string)Globalvars::get_instance()->get_setting('mailbox_receive_mode');

	return mailbox_receive_mode_resolve(mailbox_receive_relay_exists(), $setting,
		mailbox_receiving_domain_exists(), mailbox_needs_relay());
}

/** Whether any live domain receives mail here (an IMAP-source anchor does not). */
function mailbox_receiving_domain_exists(): bool {
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domains_class.php'));
	$domains = new MultiInboundEmailDomain(array('deleted' => false));
	foreach ($domains as $d) {
		if (!$d->is_imap_source()) {
			return true;
		}
	}
	return false;
}

/**
 * Whether this site has a mail server of its own (Postfix, rspamd, port 25).
 * A bare-metal server does; a site container does not: its mail arrives
 * through a relay, which checks it, and every sending provider is an API or
 * an outside server. Read from the installer's record of where the site runs
 * (deployment_environment), the one place that says so.
 */
function mailbox_site_has_mail_server(): bool {
	return Globalvars::get_instance()->get_setting('deployment_environment', true, true) !== 'docker';
}

/**
 * Whether this site's mail arrives by SMTP (the Postfix provider) rather than
 * through a provider's webhook.
 */
function mailbox_receives_by_smtp(): bool {
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundProviderRegistry.php'));
	$provider = InboundProviderRegistry::active();
	return !$provider::isWebhook();
}

/**
 * Whether only a relay can take this site's mail in: a site container (no mail
 * server of its own) whose mail arrives by SMTP.
 */
function mailbox_needs_relay(): bool {
	return !mailbox_site_has_mail_server() && mailbox_receives_by_smtp();
}
?>
