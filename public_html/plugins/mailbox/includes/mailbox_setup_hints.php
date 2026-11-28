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
 *     the page already loaded. A domain that asked for the sending lock and
 *     never finished the protect ceremony costs nothing to catch.
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
 * @version 1.3 - mailbox_setup_hint_addresses(), mailbox_arrivals_ever() and mailbox_arrivals_recent() shared with the mailbox_status agent word
 * @version 1.2 - a mailbox nothing has reached yet is badged 'no mail yet', not 'needs attention'
 * @version 1.1 - the half-finished signal is a requested, unfinished sending lock
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domains_class.php'));

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
		$aliases = array();
		foreach ($node['mailboxes'] as $mailbox) {
			$aliases[] = $mailbox['alias'];
		}
		$addresses = array_merge($addresses, mailbox_setup_hint_addresses($node['domain'], $aliases));
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

			if ($domain_reason !== '') {
				$hints[(int)$alias->key] = array(
					'text'  => 'needs attention',
					'title' => $domain_reason . ' Check this mailbox\'s setup.',
					'url'   => $setup_base . (int)$alias->key,
				);
				continue;
			}

			// An IMAP-pull mailbox has no inbound delivery path of its own, so
			// "nothing has arrived" says nothing about its setup. For any other,
			// it is the one sign a finished Setup tab cannot give, so the badge
			// names it rather than calling a green mailbox one that needs attention.
			$address = strtolower((string)$alias->get_full_address());
			if (!$is_imap && !isset($arrivals[$address])) {
				$hints[(int)$alias->key] = array(
					'text'  => 'no mail yet',
					'title' => 'No mail has arrived at this address yet. Send it a message from another address to check it can receive.',
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
	if ($domain->send_lock_outstanding()) {
		return 'Only send while I\'m signed in was chosen for this domain but never finished.';
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
 * The addresses the Accounts badge asks "has mail ever arrived" about, for one
 * domain: its enabled aliases, lowercased, none for an IMAP-source domain (an
 * IMAP-pull mailbox has no inbound delivery path of its own). The mailbox_status
 * agent word counts the same list, so the two can never disagree about which
 * mailboxes a domain has.
 *
 * @param iterable $aliases InboundEmailAlias rows of $domain (any that are not enabled are left out).
 * @return string[]
 */
function mailbox_setup_hint_addresses(InboundEmailDomain $domain, iterable $aliases): array {
	if ((bool)$domain->get('ied_is_imap_source')) {
		return array();
	}
	$out = array();
	foreach ($aliases as $alias) {
		if ($alias->get('iea_is_enabled')) {
			$out[] = strtolower((string)$alias->get_full_address());
		}
	}
	return $out;
}

/**
 * Which of these addresses have ever received mail, for the badge: an address
 * the lookup could not answer for counts as received, so a brand-new install
 * whose tables are absent is not badged all over with false alarms.
 *
 * @param string[] $addresses Lowercased addresses to ask about.
 * @param callable|null $lookup Stands in for mailbox_arrivals_ever(); tests only.
 * @return array<string,true>
 */
function _mailbox_setup_arrivals(array $addresses, ?callable $lookup = null): array {
	$seen = ($lookup ?? 'mailbox_arrivals_ever')($addresses);
	if ($seen === null) {
		return array_fill_keys(array_values(array_unique(array_filter($addresses))), true);
	}
	return $seen;
}

/**
 * Which of these addresses have ever received mail, or null when the lookup
 * failed.
 *
 * Two queries, not one per mailbox, and both bounded by the addresses asked
 * about — so the cost tracks the size of the listing, not the size of the mail
 * store. Each is an index lookup against the LOWER() expression indexes
 * declared on the two tables.
 *
 * Two sources because two ingest paths exist: the colocated path writes a
 * transaction row per message, while the relay path stores the message and
 * writes no transaction row. Asking only the log would report a relay
 * deployment's whole estate as never having received anything.
 *
 * @param string[] $addresses Lowercased addresses to ask about.
 * @return array<string,true>|null
 */
function mailbox_arrivals_ever(array $addresses): ?array {
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
		error_log('mailbox_setup_hints: arrival lookup failed: ' . $e->getMessage());
		return null;
	}
	return $seen;
}

/**
 * How much mail reached these addresses in the last $days days, and when the
 * newest arrived: {received: int, last_time: ?string (UTC, as stored)}, or null
 * when the lookup failed. Each message counts once: every stored inbound
 * message, plus every log row that stored none (a colocated delivery logged but
 * not kept, such as a forward-only alias). A log row counts whatever its
 * status, as mailbox_arrivals_ever() does: a refused message still reached the
 * site. For an agent word's single ask, never a page view.
 *
 * @param string[] $addresses Lowercased addresses to ask about.
 */
function mailbox_arrivals_recent(array $addresses, int $days = 7): ?array {
	$addresses = array_values(array_unique(array_filter($addresses)));
	if (empty($addresses)) {
		return array('received' => 0, 'last_time' => null);
	}
	$days = max(1, $days);
	$placeholders = implode(',', array_fill(0, count($addresses), '?'));
	$since = gmdate('Y-m-d H:i:s', time() - $days * 86400);
	try {
		$db = DbConnector::get_instance()->get_db_link();

		$stmt = $db->prepare("SELECT count(*) FILTER (WHERE iem_received_time >= ?) AS recent, max(iem_received_time) AS newest "
			. "FROM iem_inbound_email_messages WHERE iem_direction = 'inbound' AND lower(iem_recipient) IN (" . $placeholders . ')');
		$stmt->execute(array_merge(array($since), $addresses));
		$stored = $stmt->fetch(PDO::FETCH_ASSOC) ?: array();

		$stmt = $db->prepare('SELECT count(*) FILTER (WHERE iel_create_time >= ?) AS recent, max(iel_create_time) AS newest '
			. 'FROM iel_inbound_email_logs WHERE iel_iem_inbound_email_message_id IS NULL '
			. 'AND lower(iel_to_address) IN (' . $placeholders . ')');
		$stmt->execute(array_merge(array($since), $addresses));
		$logged = $stmt->fetch(PDO::FETCH_ASSOC) ?: array();
	} catch (\Throwable $e) {
		error_log('mailbox_setup_hints: recent arrival lookup failed: ' . $e->getMessage());
		return null;
	}
	$times = array_filter(array((string)($stored['newest'] ?? ''), (string)($logged['newest'] ?? '')));
	return array(
		'received'  => (int)($stored['recent'] ?? 0) + (int)($logged['recent'] ?? 0),
		'last_time' => $times ? max($times) : null,
	);
}
