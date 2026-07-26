<?php
/**
 * Setup hints for the Accounts listing — "this mailbox looks unfinished, go and
 * check it" (specs/mailbox_setup_verdicts.md).
 *
 * A mailbox can sit broken indefinitely because the only surface that would say
 * so is a page nobody opened. These hints put that knowledge where people
 * already are, without paying for the Setup tab's DNS lookups and host probes on
 * every listing render.
 *
 * Three signals, tiered by what they cost:
 *
 *  1. **Free** — half-finished ceremonies readable straight off the domain row
 *     the page already loaded. A Fortress domain whose protect ceremony never
 *     ran is invisible today and costs nothing to catch.
 *  2. **One query** — has any mail ever arrived for this address. The strongest
 *     single signal that a mailbox works, answered for every alias at once.
 *  3. **Persisted** — the DNS verdict the CheckDomainSetup task last reached.
 *
 * **A hint is never a verdict.** It says go and look; the Setup tab is the only
 * thing that claims a domain is correct or broken, because it re-runs everything
 * live. So the copy says "needs attention", never "broken", and a stored verdict
 * older than the staleness window is not shown at all — pointing at a domain
 * that was fixed last week wastes the attention this exists to buy.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));

/** A stored DNS verdict older than this is ignored rather than shown. */
const MAILBOX_SETUP_VERDICT_STALE_DAYS = 7;

/**
 * Build one hint per mailbox that has something worth looking at.
 *
 * @param array $tree The Accounts page tree: [['domain' => InboundEmailDomain,
 *                    'mailboxes' => [['alias' => InboundEmailAlias, ...], ...]], ...]
 * @return array<int,array{text:string,title:string,url:string}> Keyed by alias id.
 *         A mailbox with nothing to report is simply absent.
 */
function mailbox_setup_hints(array $tree): array {
	$hints = array();
	$setup_base = '/plugins/mailbox/admin/admin_mailbox_setup?alias_id=';

	// Collect the addresses this page will actually ask about, so the arrival
	// lookup is bounded by what is on screen rather than by how much mail the
	// deployment has ever received.
	$addresses = array();
	foreach ($tree as $node) {
		if ((bool)$node['domain']->get('ied_is_imap_source')) {
			continue;
		}
		foreach ($node['mailboxes'] as $mailbox) {
			if ($mailbox['alias']->get('iea_is_enabled')) {
				$addresses[] = strtolower((string)$mailbox['alias']->get_full_address());
			}
		}
	}
	$arrivals = _mailbox_setup_arrivals($addresses);

	foreach ($tree as $node) {
		$domain = $node['domain'];
		$domain_reason = _mailbox_setup_domain_reason($domain);
		$is_imap = (bool)$domain->get('ied_is_imap_source');

		foreach ($node['mailboxes'] as $mailbox) {
			$alias = $mailbox['alias'];
			if (!$alias->get('iea_is_enabled')) {
				continue;   // already badged as disabled; nothing to add
			}

			$reason = $domain_reason;
			if ($reason === '' && !$is_imap) {
				// An IMAP-pull mailbox has no inbound delivery path of its own,
				// so "nothing has arrived" says nothing about its setup.
				$address = strtolower((string)$alias->get_full_address());
				if (!isset($arrivals[$address])) {
					$reason = 'No mail has ever arrived at this address.';
				}
			}

			if ($reason !== '') {
				$hints[(int)$alias->key] = array(
					'text'  => 'needs attention',
					'title' => $reason . ' Check this mailbox\'s setup.',
					'url'   => $setup_base . (int)$alias->key,
				);
			}
		}
	}

	return $hints;
}

/**
 * The free signals plus the persisted verdict, for one domain. Returns '' when
 * there is nothing to say.
 *
 * Ordered most-actionable first: a ceremony someone abandoned halfway is a more
 * useful thing to surface than yesterday's DNS verdict.
 */
function _mailbox_setup_domain_reason($domain): string {
	$level = $domain->security_level();

	// Free: half-finished protection ceremonies, straight off the loaded row.
	if ($level === InboundEmailDomain::LEVEL_FORTRESS && !$domain->get('ied_is_protected_identity')) {
		return 'Outbound protection was chosen for this domain but never activated.';
	}
	if ($level !== InboundEmailDomain::LEVEL_STANDARD
			&& trim((string)$domain->get('ied_dkim_selector')) === '') {
		return 'This domain has no sealed signing key yet.';
	}
	if (!$domain->get('ied_is_enabled')) {
		return 'This domain is switched off, so no mail is accepted for it.';
	}

	// Persisted: what the scheduled task last concluded about this domain's DNS.
	if ((string)$domain->get('ied_setup_status') === 'attention'
			&& _mailbox_setup_verdict_is_fresh((string)$domain->get('ied_setup_checked_time'))) {
		return 'A required DNS record for this domain was missing or wrong when it was last checked.';
	}

	return '';
}

/** Is a stored verdict recent enough to act on? */
function _mailbox_setup_verdict_is_fresh(string $checked_time): bool {
	if (trim($checked_time) === '') {
		return false;
	}
	$cutoff = LibraryFunctions::time_shift(gmdate('Y-m-d H:i:s'),
		'-' . MAILBOX_SETUP_VERDICT_STALE_DAYS . ' days', 'Y-m-d H:i:s');
	return $checked_time > $cutoff;
}

/**
 * Which of these addresses have ever received mail.
 *
 * Two queries, not one per mailbox, and both bounded by the addresses actually
 * on screen — so the cost tracks the size of the listing, not the size of the
 * mail store. Each is an index lookup against the LOWER() expression indexes
 * declared on the two tables.
 *
 * Two sources because two ingest paths exist: the colocated path writes a
 * transaction row per message, while the relay path stores the message and
 * writes no transaction row. Asking only the log would report a relay
 * deployment's whole estate as never having received anything.
 *
 * @param string[] $addresses Lowercased addresses to ask about.
 * @return array<string,true>
 */
function _mailbox_setup_arrivals(array $addresses): array {
	$addresses = array_values(array_unique(array_filter($addresses)));
	if (empty($addresses)) {
		return array();
	}
	$placeholders = implode(',', array_fill(0, count($addresses), '?'));
	$seen = array();
	try {
		$db = DbConnector::get_instance()->get_db_link();

		$stmt = $db->prepare('SELECT DISTINCT lower(iel_to_address) AS addr FROM iel_inbound_email_logs '
			. 'WHERE lower(iel_to_address) IN (' . $placeholders . ')');
		$stmt->execute($addresses);
		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $addr) {
			if ($addr !== null && $addr !== '') { $seen[(string)$addr] = true; }
		}

		// Inbound only: on a composed row iem_recipient is sealed content, not a
		// routing address, and matching ciphertext would be meaningless. The
		// predicate also lets the partial index serve the query.
		$stmt = $db->prepare("SELECT DISTINCT lower(iem_recipient) AS addr FROM iem_inbound_email_messages "
			. "WHERE iem_direction = 'inbound' AND lower(iem_recipient) IN (" . $placeholders . ')');
		$stmt->execute($addresses);
		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $addr) {
			if ($addr !== null && $addr !== '') { $seen[(string)$addr] = true; }
		}
	} catch (\Throwable $e) {
		// Tables absent on a brand-new install. Treat every address as having
		// received mail rather than badging the whole listing with false alarms.
		error_log('mailbox_setup_hints: arrival lookup failed: ' . $e->getMessage());
		return array_fill_keys($addresses, true);
	}
	return $seen;
}
