<?php
/**
 * The Setup tab's per-mailbox checks, as one callable thing.
 *
 * The Setup tab is mailbox-first: pick a mailbox and it shows a "Receiving"
 * group plus a "Forwarding" (or, for a store-only mailbox, "Sending") group,
 * scoped to that one address. This file owns which rows land in which group and
 * what verdict they add up to, so that every surface asking "is this mailbox
 * set up?" gets the same answer as the tab — the reader's banner and the tab
 * cannot drift, because they run the same code.
 *
 * The cost is the check suite itself: DNS lookups plus local host probes, a
 * fraction of a second in practice. Callers that render on every page load are
 * expected to cache (see the reader's setup_status action); this file always
 * answers live.
 *
 * @version 1.5 - a grant that signed in without mail access is reported as
 *                that, not as an expired authorization
 * @version 1.4 - the remembered verdict moved to mailbox_setup_memory.php, so
 *                a feed save can drop it without loading the check suite
 * @version 1.3 - the machine-sender family (domain.machine_sender*) lands in
 *                Sending and is excluded from Receiving, which otherwise admits
 *                every domain-layer row
 * @version 1.2 - relay rows and cards are scoped to mailboxes whose domain needs
 *                a relay (specs/mailbox_relay_surface_simplification.md)
 * @version 1.1 - a broken relay scanner surfaces as a Receiving card instead of
 *                waiting in Advanced (specs/mailbox_relay_scanner_health.md)
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/mailbox_setup_memory.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundProviderRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));

/**
 * Run the checks for one mailbox and return them grouped the way the Setup tab
 * shows them.
 *
 * @param int    $alias_id           The mailbox (alias) to check.
 * @param string $relay_advanced_url Where the relay cards' "Go to relay setup"
 *                                   should point; omit for callers with no such
 *                                   destination.
 * @return array|null Null when the alias does not resolve. Otherwise:
 *   address, domain, mode, forwards (bool), arrival ('imap'|'webhook'|'postfix'),
 *   imap (InboundImapAccount|null), receiving (rows), forwarding (rows).
 */
function mailbox_setup_scoped_rows(int $alias_id, string $relay_advanced_url = ''): ?array {
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/relay_admin.php'));

	if ($alias_id <= 0) {
		return null;
	}
	$alias = new InboundEmailAlias($alias_id, TRUE);
	if (!$alias->key || $alias->get('iea_delete_time')) {
		return null;
	}
	$domain = new InboundEmailDomain($alias->get('iea_ied_inbound_email_domain_id'), TRUE);
	if (!$domain->key) {
		return null;
	}

	$is_imap  = (bool)$domain->get('ied_is_imap_source');
	$mode     = $alias->get('iea_delivery_mode') ?: InboundEmailAlias::MODE_FORWARD;
	$forwards = in_array($mode, array(InboundEmailAlias::MODE_FORWARD,
		InboundEmailAlias::MODE_FORWARD_AND_STORE), true);
	$address      = strtolower($alias->get('iea_alias') . '@' . $domain->get('ied_domain'));
	$focus_domain = strtolower((string)$domain->get('ied_domain'));

	// A RELAY IS ONLY THIS MAILBOX'S BUSINESS AT FORTRESS
	// (specs/mailbox_relay_surface_simplification.md). The relay is what seals
	// arriving mail, and only Fortress requires that. A deployment may run one
	// for its own reasons at any level — that stays possible, and it stays
	// visible in the Setup tab's Relay section — but it must not surface as a
	// card, a warning or a verdict on a mailbox whose domain does not need it.
	$needs_relay = ($domain->security_level() === InboundEmailDomain::LEVEL_FORTRESS);

	$provider = InboundProviderRegistry::active();
	$arrival  = $is_imap ? 'imap' : ($provider::isWebhook() ? 'webhook' : 'postfix');

	$receiving_rows  = array();
	$forwarding_rows = array();
	$imap_feed       = null;

	if ($arrival === 'imap') {
		// IMAP-pull mailboxes have no MX/host stack — receiving is "is the
		// feed connected and fetching". Build those rows from the feed model.
		$feeds = new MultiInboundImapAccount(array('alias_id' => $alias_id, 'deleted' => false));
		$feeds->load();
		$imap_feed = count($feeds) ? $feeds->get(0) : null;
		$receiving_rows = _setup_imap_receiving_rows($imap_feed);

		// Forwarding still applies if the mailbox forwards; those checks are
		// server-global (relay/SRS), so a domain-less run supplies them.
		if ($forwards) {
			$all = (new InboundEmailSetupCheck())->run(null, null);
			foreach ($all as $r) {
				if (_setup_is_forwarding_row($r)) { $forwarding_rows[] = $r; }
			}
		}
	} else {
		$all = (new InboundEmailSetupCheck())->run($focus_domain, $address);
		foreach ($all as $r) {
			if ($forwards && _setup_is_forwarding_row($r)) {
				$forwarding_rows[] = $r;
			} elseif (!$forwards && _setup_is_sending_row($r)) {
				$forwarding_rows[] = $r;
			} elseif (_setup_is_receiving_row($r, $focus_domain, $needs_relay)) {
				$receiving_rows[] = $r;
			}
		}
	}

	// The relay reads as two cards among the checks — one per side of the
	// mail path — rather than a section of its own above everything. Grey
	// and optional until a relay exists; its health once one does. The
	// receiving card only applies to mail this deployment actually receives,
	// so an IMAP-pull mailbox gets the sending card alone.
	// ...and only where a relay is this mailbox's business at all.
	if ($needs_relay) {
		$relay_cards = admin_mailbox_relay_check_rows($relay_advanced_url);
		if ($arrival !== 'imap' && $relay_cards['receiving'] !== null) {
			$receiving_rows[] = $relay_cards['receiving'];
		}
		if ($relay_cards['sending'] !== null) {
			$forwarding_rows[] = $relay_cards['sending'];
		}
	}

	return array(
		'address'    => $address,
		'domain'     => $focus_domain,
		'mode'       => $mode,
		'forwards'   => $forwards,
		'arrival'    => $arrival,
		'imap'       => $imap_feed,
		'receiving'  => $receiving_rows,
		'forwarding' => $forwarding_rows,
	);
}

/**
 * What those rows add up to: is this mailbox all green?
 *
 * Anything the tab paints amber or red counts — a warning is a thing the
 * operator has not finished, and grading it away is how a mailbox sits half-set-
 * up with nothing saying so. What does NOT count is the absence of information:
 * a check that could not run (UNKNOWN), one that is legitimately undecidable
 * yet (INFO), and a capability nobody turned on (OPTIONAL) are all silent,
 * because a verdict that flaps with a DNS hiccup gets ignored.
 *
 * The reason quotes the first non-green row in the order the tab renders, so
 * the banner names the same thing the operator will see at the top of the page
 * it sends them to.
 *
 * @param array|null $scoped From mailbox_setup_scoped_rows().
 * @return array{status:string,reason:string,label:string}
 *         status: ok | attention | unknown
 */
function mailbox_setup_verdict(?array $scoped): array {
	if ($scoped === null) {
		return array('status' => 'unknown', 'reason' => '', 'label' => '');
	}
	$rows = array_merge($scoped['receiving'], $scoped['forwarding']);
	$evaluated = 0;
	foreach ($rows as $row) {
		$status = (string)($row['status'] ?? '');
		if ($status === InboundEmailSetupCheck::UNKNOWN
				|| $status === InboundEmailSetupCheck::INFO
				|| $status === InboundEmailSetupCheck::OPTIONAL) {
			continue;
		}
		$evaluated++;
		if ($status === InboundEmailSetupCheck::FAIL || $status === InboundEmailSetupCheck::WARN) {
			return array(
				'status' => 'attention',
				'reason' => (string)($row['summary'] ?? ''),
				'label'  => (string)($row['label'] ?? ''),
			);
		}
	}
	return array('status' => $evaluated > 0 ? 'ok' : 'unknown', 'reason' => '', 'label' => '');
}

/**
 * Forwarding (outbound) rows: the relay, SRS, and DKIM signing — the only
 * checks that matter when a mailbox forwards mail back out.
 */
function _setup_is_forwarding_row(array $r): bool {
	return in_array($r['id'], array('plugin.srs_secret', 'plugin.relay', 'domain.dkim', 'host.opendkim'), true);
}

/**
 * Sending rows for a store-only mailbox: replies and new mail composed from
 * the reader still leave through the outbound stack, so the relay and DKIM
 * signing are its concerns too — everything a forwarding mailbox needs except
 * SRS, which only rewrites forwarded envelopes.
 */
function _setup_is_sending_row(array $r): bool {
	// The machine-sender family (domain.machine_sender*) is outbound: it is
	// about where the site's automated mail sends from.
	if (strpos((string)$r['id'], 'domain.machine_sender') === 0) {
		return true;
	}
	return in_array($r['id'], array('plugin.relay', 'domain.dkim', 'host.opendkim'), true);
}

/**
 * Receiving rows for a mailbox: the domain's inbound DNS (including the
 * fleet ownership proof), the inbound-auth verifier, that the plugin is on,
 * the relay cutover-completion row, that the alias resolves, and the
 * end-to-end proof. Server-internal host/mailhost rows are otherwise excluded —
 * they live in the Advanced server view.
 *
 * The exception is the mail host's own A record. When the mail hostname sits
 * inside the focused domain (mail.example.com under example.com), that record is
 * one this domain's owner has to publish in the same zone as its MX and SPF, so
 * it belongs beside them rather than behind a disclosure. Both rows come along:
 * one says the record exists, the other says it points at the right server —
 * an A record resolving to somebody else's address is a distinct failure and
 * hiding it would be the silent-wrong-answer this page exists to prevent.
 *
 * A mail host outside the domain (a shared devmail.example.net, a relay in the
 * operator's zone) stays in Advanced: it is not this domain owner's record.
 *
 * The relay's spam scanning is the one row whose PRESENCE depends on its status:
 * it comes up front when it is a problem and stays in Advanced when it is not.
 * That is the opposite of the mailhost rule two paragraphs up, deliberately,
 * because the two rows are different kinds of thing. A mailhost record is a TASK
 * — it must remain visible after it goes green, or the page appears to forget
 * what the operator just did. Relay scanning is a FAULT REPORT about a component
 * nobody configured and that is supposed to work silently; a green "your relay
 * is scanning" card is a line of checklist noise for a job the operator never
 * had. Broken is news. Working is not.
 */
function _setup_is_receiving_row(array $r, string $focus_domain = '', bool $needs_relay = true): bool {
	if ($r['id'] === 'domain.dkim') { return false; }      // DKIM signing is outbound
	// The machine-sender family is outbound too — and this filter admits every
	// domain-layer row by default, so the exclusion must be explicit.
	if (strpos((string)$r['id'], 'domain.machine_sender') === 0) { return false; }
	// Deployment-wide, and legitimately so: the relay scans every message for
	// every hosted domain, so "the relay is not scanning" is equally true of
	// every mailbox behind it. That is what separates it from
	// 'plugin.relay_enable' below, which looks deployment-wide but actually
	// reports on one other domain's MX.
	//
	// But only for a mailbox whose domain needs a relay. Promoting this row
	// unconditionally meant one deployment-wide fault turned EVERY mailbox on
	// the deployment amber, including mailboxes on domains the relay does
	// nothing for — one problem, reported as though everything were broken.
	if ($r['id'] === 'host.relay_scanner') {
		return $needs_relay && in_array($r['status'], array(InboundEmailSetupCheck::WARN,
			InboundEmailSetupCheck::FAIL), true);
	}
	if ($r['layer'] === 'domain')   { return true; }
	if ($r['layer'] === 'address')  { return true; }
	if ($r['layer'] === 'e2e')      { return true; }
	if (in_array($r['id'], array('mailhost.a_record', 'mailhost.a_matches_ip'), true)) {
		return _setup_row_is_in_zone($r, $focus_domain);
	}
	// 'plugin.relay_enable' (Relay cutover) is deliberately absent: it is a
	// DEPLOYMENT-WIDE roll-up that reports the first domain whose MX has not
	// moved yet, so inside "Receiving — info@example.com" it reads as a verdict
	// on that mailbox while actually describing a different domain entirely.
	// The per-domain question it looks like it answers is already answered
	// properly one row above by 'domain.mx'. The roll-up lives in Advanced with
	// the other server-wide facts.
	return in_array($r['id'], array('plugin.enabled', 'host.inbound_verification'), true);
}

/**
 * Is the hostname a check row is about inside the focused domain's own zone?
 * Mailhost rows carry that hostname in their scope, so this works whether the
 * row passed or failed — a card that vanished once it went green would be worse
 * than one that was never there.
 */
function _setup_row_is_in_zone(array $r, string $focus_domain): bool {
	$host = strtolower(rtrim(trim((string)($r['scope'] ?? '')), '.'));
	$domain = strtolower(rtrim(trim($focus_domain), '.'));
	if ($host === '' || $domain === '') {
		return false;
	}
	return $host === $domain || substr($host, -(strlen($domain) + 1)) === '.' . $domain;
}

/**
 * Synthetic receiving rows for an IMAP-pull mailbox, derived from its feed.
 * Mirrors the row shape InboundEmailSetupCheck::r() produces so the same
 * renderer handles them.
 */
function _setup_imap_receiving_rows(?InboundImapAccount $imap): array {
	$row = function ($status, $label, $summary, $detail = '', $fix = null) {
		return array(
			'id' => 'imap.' . strtolower(str_replace(' ', '_', $label)), 'scope' => '', 'layer' => 'imap',
			'label' => $label, 'severity' => InboundEmailSetupCheck::REQUIRED, 'status' => $status,
			'summary' => $summary, 'detail' => $detail, 'fix' => $fix, 'recheckable' => true,
		);
	};
	$accounts_link = array('text' => 'Manage this mailbox on the Accounts tab.');

	if (!$imap) {
		return array($row(InboundEmailSetupCheck::FAIL, 'IMAP feed',
			'This mailbox has no IMAP feed configured.',
			'An IMAP-source mailbox needs a feed to pull mail. Add one from the Accounts tab.', $accounts_link));
	}

	$out = array();
	if ($imap->isOAuth() && !$imap->hasOAuthToken()) {
		$out[] = $row(InboundEmailSetupCheck::FAIL, 'IMAP connection',
			'The mailbox is not connected yet.',
			'Press "Connect" on the Accounts tab to authorize access.', $accounts_link);
	} elseif ($imap->mailAccessRefused()) {
		// Named ahead of the expiry row because both present as a failed login,
		// and this one is not an expiry: the sign-in worked and was never allowed
		// near the mail. "Renew the authorization" sends the operator round the
		// same consent screen to make the same omission again.
		$out[] = $row(InboundEmailSetupCheck::FAIL, 'IMAP connection',
			'The sign-in did not include permission to read mail.',
			'The mailbox was connected without granting access to the mail itself, so every fetch '
				. 'is refused. Press "Reconnect" on the Accounts tab and allow access to your email '
				. 'on the provider\'s permission screen.', $accounts_link);
	} elseif ($imap->needsReauth()) {
		$out[] = $row(InboundEmailSetupCheck::FAIL, 'IMAP connection',
			'The stored authorization has expired and needs to be renewed.',
			'Press "Reconnect" on the Accounts tab.', $accounts_link);
	} else {
		$out[] = $row(InboundEmailSetupCheck::PASS, 'IMAP connection', 'The mailbox is connected.');
	}

	if (!$imap->get('iia_is_enabled')) {
		$out[] = $row(InboundEmailSetupCheck::WARN, 'Feed enabled',
			'Fetching is turned off for this feed.',
			'Enable it from the Accounts tab to resume pulling mail.', $accounts_link);
	}

	// Sync mode (specs/two_way_imap_sync.md §8): report Off / Read-only / Two-way
	// and the CONDSTORE requirement so the operator sees why sync may be unavailable.
	$modeLabels = array(
		InboundImapAccount::SYNC_OFF  => 'Off (one-time import)',
		InboundImapAccount::SYNC_PULL => 'Read-only (follow the source)',
		InboundImapAccount::SYNC_BOTH => 'Two-way (full sync)',
	);
	$mode = $imap->syncMode();
	if ($mode === InboundImapAccount::SYNC_OFF) {
		$summary = $imap->supportsCondstore()
			? 'Sync is off; this feed does a one-time import only.'
			: 'Sync is off. This server does not advertise CONDSTORE, so only one-time import is available.';
		$out[] = $row(InboundEmailSetupCheck::INFO, 'Sync', $summary);
	} else {
		$out[] = $row(InboundEmailSetupCheck::PASS, 'Sync', $modeLabels[$mode]);
	}

	$last = trim((string)$imap->get('iia_last_status'));
	if ($last !== '') {
		$out[] = $row(InboundEmailSetupCheck::INFO, 'Last fetch', $last);
	}

	return $out;
}
