<?php
/**
 * mailbox_status.php — what the mailbox_status agent word carries.
 *
 * Usage: php mailbox_status.php [DOMAIN]
 *
 * With no domain: the site's spam-learning counts (SpamLearning::status) and
 * the list of the site's own mail domains, so a caller learns which names it
 * may ask about without probing. Each domain carries how many enabled
 * mailboxes it has, how many no mail has ever reached (the Accounts badge's
 * question, over the same address list), how many messages reached it in the
 * last 7 days and when the newest did: counts and one time, never an address.
 *
 * With a domain: one of the site's own mail domains, refused otherwise before
 * any lookup, so the node is never a DNS lookup service for names it does not
 * host. For that domain:
 *   - checks: the Setup tab's domain rows from InboundEmailSetupCheck::readOnly()
 *     (no fleet service, no provider API, nothing written): id, status,
 *     severity and label only. The summary and detail can name member
 *     addresses and the fix carries values to publish, so neither is sent.
 *   - not_asked: what the read-only run did not ask ('fleet', 'provider_api'),
 *     which is why those rows are UNKNOWN.
 *   - flags: yes/no facts and short names, never a key or a published value.
 *   - dns: a fixed list of names, each read twice: through this machine's own
 *     resolver (with the cached copy's time left) and from each of the zone's
 *     authoritative nameservers, plus which of the two the Setup tab reads.
 *     Each nameserver row says why it gave no clean answer (a compiled word)
 *     and how long it took; one that gave no reply is not asked again for the
 *     domain's later names, and gets one empty TCP connection to port 53
 *     (never to an inside-network address) so a reader can tell UDP dropped
 *     from all DNS dropped.
 *   - send_outcomes: how the domain's mail left this site (MailSendOutcome),
 *     when that ledger exists.
 *
 * Run by the agent as the site's web user, so a file-readability row reads as
 * the Setup tab sees it. Prints one JSON object; exit 0 on an answer
 * (a refusal is an answer), 1 on an error, 2 on bad usage.
 *
 * @version 1.2.0 - every silent nameserver skipped however many the zone has; tcp_53 unreachable; catch_all
 * @version 1.1.0 - nameserver reason, ms, tcp_53 and skip-after-silence; per-domain arrival counts (specs/mailbox_diagnosis_words.md WP1-WP2)
 * @version 1.0.0 - the mailbox_status word (specs/agent_recipes_and_vocabulary.md, 2026-11-14)
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

/** The most TCP 53 probes one run makes, each up to 2 seconds. */
const MBS_MAX_TCP_PROBES = 6;

// A test loads the functions without running the word.
if (defined('MBS_FUNCTIONS_ONLY')) {
	return;
}

if (count($argv) > 2) {
	fwrite(STDERR, "Usage: php mailbox_status.php [DOMAIN]\n");
	exit(2);
}
$domain = isset($argv[1]) ? strtolower(trim($argv[1])) : '';
if ($domain !== '' && (strlen($domain) > 253 || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain))) {
	fwrite(STDERR, "mailbox_status: not a domain name\n");
	exit(2);
}

require_once(__DIR__ . '/../../public_html/includes/PathHelper.php');

/** The names read for a domain: [label, name, DNS type, DNS_* constant]. */
function mbs_dns_names(InboundEmailDomain $model): array {
	$d = (string)$model->get('ied_domain');
	$names = array(
		array('mx', $d, 'MX', DNS_MX),
		array('spf', $d, 'TXT', DNS_TXT),
		array('dmarc', '_dmarc.' . $d, 'TXT', DNS_TXT),
		array('dkim_ordinary', 'mail._domainkey.' . $d, 'TXT', DNS_TXT),
	);
	$selector = strtolower(trim((string)$model->get('ied_dkim_selector')));
	if ($selector !== '' && $selector !== 'mail' && preg_match('/^[a-z0-9-]{1,63}$/', $selector)) {
		$names[] = array('dkim_sealed', $selector . '._domainkey.' . $d, 'TXT', DNS_TXT);
	}
	$fwd = strtolower(trim((string)$model->forwarding_subdomain()));
	if ($fwd !== '' && $fwd !== $d && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $fwd)) {
		$names[] = array('forwarding_mx', $fwd, 'MX', DNS_MX);
		$names[] = array('forwarding_spf', $fwd, 'TXT', DNS_TXT);
	}
	return $names;
}

/** One record as a short comparable string: MX "10 host", TXT the joined text. */
function mbs_value(array $r): string {
	if (isset($r['target'])) {
		return (isset($r['pri']) ? (int)$r['pri'] . ' ' : '') . rtrim(strtolower((string)$r['target']), '.');
	}
	if (isset($r['entries']) && is_array($r['entries'])) {
		return implode('', $r['entries']);
	}
	return (string)($r['txt'] ?? '');
}

/** Records as sorted values, each cut to 512 bytes, at most 8. */
function mbs_values(array $records): array {
	$out = array();
	foreach ($records as $r) {
		if (is_array($r)) {
			$out[] = substr(mbs_value($r), 0, 512);
		}
	}
	sort($out);
	return array_slice($out, 0, 8);
}

/** The node's own answer for one name: this machine's resolver, never the zone's servers. */
function mbs_node_answer(string $name, int $type): array {
	$records = @dns_get_record($name, $type);
	if ($records === false) {
		return array('status' => 'error', 'answer' => array(), 'ttl_left' => null);
	}
	if (!$records) {
		// A cached "no such name" carries no time left this call can read.
		return array('status' => 'no_answer', 'answer' => array(), 'ttl_left' => null);
	}
	$ttl = null;
	foreach ($records as $r) {
		if (isset($r['ttl'])) {
			$ttl = $ttl === null ? (int)$r['ttl'] : min($ttl, (int)$r['ttl']);
		}
	}
	return array('status' => 'answer', 'answer' => mbs_values($records), 'ttl_left' => $ttl);
}

/**
 * Is $ip loopback, private, link-local, shared (CGNAT), "this network" or
 * unique-local, IPv4 or IPv6 (an IPv4-mapped IPv6 address read as its IPv4)?
 * Anything that does not read as an address counts as inside.
 */
function mbs_inside_address(string $ip): bool {
	$bin = @inet_pton($ip);
	if ($bin === false) {
		return true;
	}
	if (strlen($bin) === 16 && substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
		$bin = substr($bin, 12);
	}
	if (strlen($bin) === 4) {
		$n = unpack('N', $bin)[1];
		foreach (array(
			array(0x7f000000, 8), array(0x0a000000, 8), array(0xac100000, 12), array(0xc0a80000, 16),
			array(0xa9fe0000, 16), array(0x64400000, 10), array(0x00000000, 8),
		) as list($net, $bits)) {
			$mask = (0xffffffff << (32 - $bits)) & 0xffffffff;
			if (($n & $mask) === $net) {
				return true;
			}
		}
		return false;
	}
	if ($bin === str_repeat("\0", 15) . "\x01" || $bin === str_repeat("\0", 16)) {
		return true;   // ::1, ::
	}
	$first = ord($bin[0]);
	$second = ord($bin[1]);
	return ($first & 0xfe) === 0xfc                          // fc00::/7
		|| ($first === 0xfe && ($second & 0xc0) === 0x80);     // fe80::/10
}

/**
 * One empty TCP connection to $ip port 53, closed at once: open, closed (the
 * server refused it at once), unreachable (this machine has no route to it:
 * ENETUNREACH or EHOSTUNREACH, as a machine with no IPv6 gives for every IPv6
 * address), timeout, or skipped for an inside-network address. Nothing is sent.
 */
function mbs_tcp_53(string $ip): string {
	if (mbs_inside_address($ip)) {
		return 'skipped';
	}
	$host = strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
	$errno = 0;
	$errstr = '';
	$started = microtime(true);
	$sock = @stream_socket_client('tcp://' . $host . ':53', $errno, $errstr, 2);
	if ($sock !== false) {
		fclose($sock);
		return 'open';
	}
	if ($errno === 101 || $errno === 113) {
		return 'unreachable';
	}
	return ($errno === 110 || microtime(true) - $started >= 1.9) ? 'timeout' : 'closed';
}

/**
 * Each authoritative nameserver's answer, at most 6 rows. $silent carries
 * every one of the domain's servers that gave no reply, address => {record,
 * tcp_53}: none is asked again, however many the zone has, and its row
 * repeats with ms null and skipped_after naming the record it fell silent on.
 * Only the first MBS_MAX_TCP_PROBES silent servers get the TCP probe; past
 * that tcp_53 is absent.
 */
function mbs_authoritative(string $record, string $name, int $type, string $zone, array &$silent): array {
	$out = array();
	try {
		$asked = AuthoritativeDns::probe($name, $type, $zone, array_keys($silent));
	} catch (\Throwable $e) {
		return array();
	}
	foreach ($asked as $ip => $probe) {
		$ip = (string)$ip;
		$records = $probe['records'];
		$row = $records === null
			? array('nameserver' => $ip, 'status' => 'no_clean_answer', 'answer' => array())
			: array('nameserver' => $ip, 'status' => $records ? 'answer' : 'no_answer', 'answer' => mbs_values($records));
		$row['reason'] = $probe['reason'];
		$row['ms'] = (int)$probe['ms'];
		if ($probe['reason'] === 'no_reply') {
			$probed = count(array_filter($silent, function ($w) { return $w['tcp_53'] !== null; }));
			$silent[$ip] = array('record' => $record, 'tcp_53' => $probed < MBS_MAX_TCP_PROBES ? mbs_tcp_53($ip) : null);
		}
		if (isset($silent[$ip]) && $silent[$ip]['tcp_53'] !== null) {
			$row['tcp_53'] = $silent[$ip]['tcp_53'];
		}
		$out[] = $row;
	}
	foreach ($silent as $ip => $was) {
		if ($was['record'] === $record) {
			continue;   // fell silent on this name: its row is above
		}
		$skipped = array('nameserver' => (string)$ip, 'status' => 'no_clean_answer', 'answer' => array(),
			'reason' => 'no_reply', 'ms' => null, 'skipped_after' => $was['record']);
		if ($was['tcp_53'] !== null) {
			$skipped['tcp_53'] = $was['tcp_53'];
		}
		$out[] = $skipped;
	}
	return array_slice($out, 0, 6);
}

/**
 * Whether the domain takes mail for addresses with no mailbox (a catch-all that
 * stores, or forwards to an address). Such mail is not in the counts below,
 * which are over the mailboxes only.
 */
function mbs_has_catch_all(InboundEmailDomain $d): bool {
	return (string)$d->get('ied_catch_all_mode') === InboundEmailDomain::CATCHALL_STORE
		|| trim((string)$d->get('ied_catch_all_address')) !== '';
}

/**
 * The domain's arrival counts over the Accounts badge's own address list
 * (its mailboxes: catch-all mail is not counted; catch_all says it exists).
 * arrivals: read, or unreadable (and the counts null) when a lookup failed.
 * The two lookups are parameters only so a test can make them fail.
 */
function mbs_domain_arrivals(InboundEmailDomain $d, ?callable $ever_fn = null, ?callable $recent_fn = null): array {
	$ever_fn = $ever_fn ?? 'mailbox_arrivals_ever';
	$recent_fn = $recent_fn ?? 'mailbox_arrivals_recent';
	$aliases = new MultiInboundEmailAlias(array('domain_id' => (int)$d->key, 'deleted' => false), array('iea_alias' => 'ASC'));
	$addresses = array_values(array_unique(mailbox_setup_hint_addresses($d, $aliases)));
	$capped = count($addresses) > 1000;
	$addresses = array_slice($addresses, 0, 1000);
	$ever = $ever_fn($addresses);
	$recent = $ever === null ? null : $recent_fn($addresses, 7);
	if ($ever === null || $recent === null) {
		return array('catch_all' => mbs_has_catch_all($d), 'mailboxes' => count($addresses), 'mailboxes_never_received' => null, 'received_7d' => null,
			'last_received_time' => null, 'arrivals' => 'unreadable', 'capped' => $capped);
	}
	$last = null;
	if ($recent['last_time'] !== null && ($ts = strtotime($recent['last_time'] . ' UTC')) !== false) {
		$last = gmdate('Y-m-d\TH:i\Z', $ts);
	}
	return array(
		'catch_all'                => mbs_has_catch_all($d),
		'mailboxes'                => count($addresses),
		'mailboxes_never_received' => count(array_diff($addresses, array_keys($ever))),
		'received_7d'              => (int)$recent['received'],
		'last_received_time'       => $last,
		'arrivals'                 => 'read',
		'capped'                   => $capped,
	);
}

/** How the side by side reads: the node agrees, is stale, or the zone itself disagrees. */
function mbs_verdict(array $node, array $auth): string {
	$answers = array();
	foreach ($auth as $a) {
		if ($a['status'] !== 'no_clean_answer') {
			$answers[] = json_encode($a['answer']);
		}
	}
	if (!$answers) {
		return 'zone_unreadable';
	}
	if (count(array_unique($answers)) > 1) {
		return 'nameservers_disagree';
	}
	if ($node['status'] === 'error') {
		return 'node_error';
	}
	return json_encode($node['answer']) === $answers[0] ? 'agree' : 'node_differs';
}

try {
	// An inactive plugin's classes do not resolve, which is the answer here.
	if (!class_exists('SpamLearning') || !class_exists('InboundEmailDomain')) {
		echo json_encode(array('mailbox' => 'inactive')) . "\n";
		exit(0);
	}
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/mailbox_setup_hints.php'));

	if ($domain === '') {
		$domains = array();
		foreach (new MultiInboundEmailDomain(array('deleted' => false), array('ied_domain' => 'ASC')) as $d) {
			if ($d->get('ied_is_imap_source')) {
				continue;
			}
			$domains[] = array(
				'domain'    => (string)$d->get('ied_domain'),
				'enabled'   => (bool)$d->get('ied_is_enabled'),
				'protected' => (bool)$d->get('ied_is_protected_identity'),
			) + mbs_domain_arrivals($d);
			if (count($domains) >= 100) {
				break;
			}
		}
		echo json_encode(array('mailbox' => 'active') + SpamLearning::status() + array('domains' => $domains)) . "\n";
		exit(0);
	}

	$model = InboundEmailDomain::GetByDomain($domain);
	if (!$model || $model->get('ied_is_imap_source')) {
		echo json_encode(array('mailbox' => 'active', 'domain' => $domain, 'refused' => 'not_a_hosted_domain')) . "\n";
		exit(0);
	}

	$engine = InboundEmailSetupCheck::readOnly();
	$checked_at = gmdate('Y-m-d\TH:i:s\Z');
	$checks = array();
	foreach ($engine->runDomainChecks($domain) as $row) {
		$checks[] = array(
			'id'       => substr((string)($row['id'] ?? ''), 0, 80),
			'status'   => substr((string)($row['status'] ?? ''), 0, 20),
			'severity' => substr((string)($row['severity'] ?? ''), 0, 20),
			'label'    => substr((string)($row['label'] ?? ''), 0, 120),
		);
		if (count($checks) >= 80) {
			break;
		}
	}

	$owner = (int)$model->get('ied_owner_usr_user_id');
	$flags = array(
		'enabled'             => (bool)$model->get('ied_is_enabled'),
		'protected'           => (bool)$model->get('ied_is_protected_identity'),
		'send_lock_requested' => (bool)$model->get('ied_send_lock_requested'),
		'sealed_key_present'  => trim((string)$model->get('ied_dkim_sealed_key')) !== '',
		'selector'            => preg_match('/^[a-z0-9-]{1,63}$/', (string)$model->get('ied_dkim_selector')) ? (string)$model->get('ied_dkim_selector') : '',
		'forwarding_subdomain'=> (string)$model->forwarding_subdomain(),
		'owner_set'           => $owner > 0,
		'owner_has_vault'     => $owner > 0 && class_exists('UserEncryptionVault')
			&& UserEncryptionVault::loadForUser($owner, UserEncryptionVault::SCOPE_USER) !== null,
	);

	// The Setup tab reads every hosted domain from its own nameservers
	// (InboundEmailSetupCheck registers them), falling back to this machine's
	// resolver when they do not answer cleanly.
	$dns = array();
	$silent = array();
	foreach (mbs_dns_names($model) as list($what, $name, $type_label, $type)) {
		$node = mbs_node_answer($name, $type);
		$auth = mbs_authoritative($what, $name, $type, $domain, $silent);
		$dns[] = array(
			'record'        => $what,
			'name'          => $name,
			'type'          => $type_label,
			'node'          => $node,
			'authoritative' => $auth,
			'verdict'       => mbs_verdict($node, $auth),
			'setup_reads'   => array_filter($auth, function ($a) { return $a['status'] !== 'no_clean_answer'; }) ? 'authoritative' : 'node',
		);
	}

	// The send ledger is read on its own: a ledger that cannot be read leaves
	// the rest of the answer standing and says so.
	$outcomes = null;
	if (class_exists('MailSendOutcome') && method_exists('MailSendOutcome', 'summaryForDomain')) {
		try {
			$outcomes = MailSendOutcome::summaryForDomain($domain);
		} catch (\Throwable $e) {
			$outcomes = array('error' => 'unreadable: ' . get_class($e));
		}
	}

	echo json_encode(array(
		'mailbox'       => 'active',
		'domain'        => $domain,
		'checked_at'    => $checked_at,
		'checks'        => $checks,
		'not_asked'     => $engine->notAsked(),
		'flags'         => $flags,
		'dns'           => $dns,
		'send_outcomes' => $outcomes,
	)) . "\n";
} catch (\Throwable $e) {
	echo 'ERROR: the mailbox status could not be read: ' . get_class($e) . "\n";
	exit(1);
}
exit(0);
