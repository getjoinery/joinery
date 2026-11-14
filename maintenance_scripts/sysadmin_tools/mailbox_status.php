<?php
/**
 * mailbox_status.php — what the mailbox_status agent word carries.
 *
 * Usage: php mailbox_status.php [DOMAIN]
 *
 * With no domain: the site's spam-learning counts (SpamLearning::status) and
 * the list of the site's own mail domains, so a caller learns which names it
 * may ask about without probing.
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
 *   - send_outcomes: how the domain's mail left this site (MailSendOutcome),
 *     when that ledger exists.
 *
 * Run by the agent as the site's web user, so a file-readability row reads as
 * the Setup tab sees it. Prints one JSON object; exit 0 on an answer
 * (a refusal is an answer), 1 on an error, 2 on bad usage.
 *
 * @version 1.0.0 - the mailbox_status word (specs/agent_recipes_and_vocabulary.md, 2026-11-14)
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
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

/** Each authoritative nameserver's answer, at most 6. */
function mbs_authoritative(string $name, int $type, string $zone): array {
	$out = array();
	try {
		foreach (AuthoritativeDns::lookupAll($name, $type, $zone) as $ip => $records) {
			$out[] = $records === null
				? array('nameserver' => (string)$ip, 'status' => 'no_clean_answer', 'answer' => array())
				: array('nameserver' => (string)$ip, 'status' => $records ? 'answer' : 'no_answer', 'answer' => mbs_values($records));
			if (count($out) >= 6) {
				break;
			}
		}
	} catch (\Throwable $e) {
		return array();
	}
	return $out;
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
			);
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
	foreach (mbs_dns_names($model) as list($what, $name, $type_label, $type)) {
		$node = mbs_node_answer($name, $type);
		$auth = mbs_authoritative($name, $type, $domain);
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
