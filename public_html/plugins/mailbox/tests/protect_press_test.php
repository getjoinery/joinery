<?php
/** @joinery-test
 * name: protect_press
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Send protection in one press (specs/send_protection_one_press.md WP1).
 *
 * mailbox_protect_press() against an in-memory DNS host, with a resolver double
 * that serves what the host holds. The vault, the wait and the readiness rows
 * are stood in for (the press takes them as $deps); everything else is real:
 * the plans, the consent check, the reconciler, the signature proof and the flag.
 *
 *  - One press publishes the signing records, starts signing, then writes the
 *    strict SPF and DMARC, and every strict write happens with the flag on.
 *  - The apex SPF replaces the old SPF and leaves a site verification alone.
 *  - Nameservers that never serve the records stop it before signing.
 *  - A sealed key that does not match its record stops it before signing.
 *  - A strict record that is not written makes the result a failure.
 *  - Two SPF records, or a record nobody confirmed, stop it before any write.
 *  - Nothing is removed, and nothing outside the stage's names is written.
 *
 * @version 1.1 - consent binds live values; no cutover; key opens first; every nameserver; one record at a time
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/dns/DnsReconciler.php'));
require_once(PathHelper::getIncludePath('tests/dns/fixtures/FakeDnsDriver.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protect_identity.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxDkimSigner.php'));

/** Records each write with whether send protection was on when it landed. */
class PressDriver extends FakeDnsDriver {
	public $domain_id = 0;
	public $log = array();
	private function note(string $verb, DnsRecord $r): void {
		$d = new InboundEmailDomain($this->domain_id, TRUE);
		$this->log[] = array('verb' => $verb, 'name' => $r->name, 'type' => $r->type, 'value' => $r->value,
			'protected' => $d->is_protected_identity());
	}
	public function createRecord(string $zone, DnsRecord $record): void {
		parent::createRecord($zone, $record);
		$this->note('create', $record);
	}
	public function updateRecord(string $zone, DnsRecord $live, DnsRecord $desired): void {
		parent::updateRecord($zone, $live, $desired);
		$this->note('update', $desired);
	}
	public function deleteRecord(string $zone, DnsRecord $live): void {
		parent::deleteRecord($zone, $live);
		$this->log[] = array('verb' => 'delete', 'name' => $live->name, 'type' => $live->type, 'value' => $live->value,
			'protected' => null);
	}
}

/** The public resolver, answering from the fake host's zone. */
class PressResolver {
	public $driver;
	public $zone;
	public function getRecords($name, $type) {
		$out = array();
		foreach ($this->driver->listRecords($this->zone) as $r) {
			if ($r->name !== strtolower(rtrim($name, '.'))) { continue; }
			if ($type === DNS_TXT && $r->type === 'TXT') { $out[] = array('txt' => $r->value); }
			if ($type === DNS_MX && $r->type === 'MX') { $out[] = array('target' => $r->value, 'pri' => (int)$r->priority); }
			if ($type === DNS_A && $r->type === 'A') { $out[] = array('ip' => $r->value); }
		}
		return $out;
	}
}

$key  = MailboxDkimSigner::generateKeypair();
$evil = MailboxDkimSigner::generateKeypair();

/** A Private domain with a sealed key asked for, and its zone at the fake host. */
function press_fixture(array $extra_live = array()): array {
	global $key;
	$domain = new InboundEmailDomain(NULL);
	$name = 'harnesstest-press-' . bin2hex(random_bytes(4)) . '.example';
	$domain->set('ied_domain', $name);
	$domain->set('ied_is_enabled', true);
	$domain->set('ied_security_level', InboundEmailDomain::LEVEL_PRIVATE);
	$domain->set('ied_send_lock_requested', true);
	$domain->set('ied_dkim_selector', 'mailk1');
	$domain->set('ied_dkim_sealed_key', 'sealed-in-a-vault-this-test-stands-in-for');
	$domain->set('ied_dkim_public_dns', $key['dns_value']);
	$domain->save();
	$domain->load();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($domain->key));

	$driver = new PressDriver(array('api_token' => 'unused'));
	$driver->domain_id = (int)$domain->key;
	$driver->zones[$name] = array();
	$driver->seed($name, array_merge(array(
		new DnsRecord('TXT', $name, 'google-site-verification=abc123'),
		new DnsRecord('TXT', $name, 'v=spf1 include:mailgun.org ~all'),
		new DnsRecord('TXT', '_dmarc.' . $name, 'v=DMARC1; p=none; rua=mailto:r@dmarc.example'),
		new DnsRecord('MX', $name, 'mx.provider.example', null, 10),
	), array_map(function ($r) use ($name) { return new DnsRecord($r[0], str_replace('@', $name, $r[1]), $r[2]); }, $extra_live)));
	$resolver = new PressResolver();
	$resolver->driver = $driver;
	$resolver->zone = $name;
	DnsResolver::setBackend($resolver);
	return array($domain, $driver, $name);
}

/** Every token the confirmation would have carried: built from the public diff the page shows. */
function press_consent(string $name): array {
	$plan = (new InboundEmailSetupCheck())->protectPressPlan($name);
	$tokens = array();
	foreach ((new DnsReconciler(new MemoryDnsOwnershipStore()))->diffAgainstPublicDns($plan) as $row) {
		$tokens[] = mailbox_protect_consent_token($row);
	}
	return $tokens;
}

function press_run(InboundEmailDomain $domain, PressDriver $driver, string $name, array $over = array(), ?array $consent = null): array {
	global $key;
	$deps = $over + array(
		'reconciler' => new DnsReconciler(new MemoryDnsOwnershipStore()),
		'vault_open' => function ($uid) { return true; },
		'sealed_key' => function ($d) use ($key) { return $key['private_pem']; },
		'blockers'   => function ($d, $plan) use ($driver, $name) {
			// Served once the sealed key's record is in the zone.
			foreach ($driver->listRecords($name) as $r) {
				if ($r->name === 'mailk1._domainkey.' . $name) { return array(); }
			}
			return array('DKIM record — not served');
		},
		'sleep'      => function ($s) {},
		'wait_seconds' => 0,
	);
	return mailbox_protect_press($domain, 1, $driver, $name, $consent ?? press_consent($name), $deps);
}

function press_step(array $result, string $id): string {
	foreach ($result['steps'] as $s) { if ($s['id'] === $id) { return $s['status']; } }
	return '?';
}

function press_live(PressDriver $driver, string $name, string $at): array {
	$out = array();
	foreach ($driver->listRecords($name) as $r) { if ($r->name === $at && $r->type === 'TXT') { $out[] = $r->value; } }
	return $out;
}

try {
	section('One press: signing records, signing on, then the records that reject forgeries');
	list($domain, $driver, $name) = press_fixture();
	$result = press_run($domain, $driver, $name);
	check($result['ok'], 'the press finishes', $result['message'] . ' ' . json_encode($result['steps']));
	$domain = new InboundEmailDomain((int)$domain->key, TRUE);
	check($domain->is_protected_identity(), 'send protection is on');
	$strict = array_values(array_filter($driver->log, function ($w) use ($name) {
		return $w['name'] === $name || $w['name'] === '_dmarc.' . $name;
	}));
	$signing = array_values(array_filter($driver->log, function ($w) use ($name) {
		return $w['name'] !== $name && $w['name'] !== '_dmarc.' . $name;
	}));
	check(count($strict) === 2 && count($signing) >= 1, 'both stages were written', json_encode($driver->log));
	check(!in_array(false, array_column($strict, 'protected'), true), 'every strict write landed with signing already on');
	check(!in_array(true, array_column($signing, 'protected'), true), 'every signing-stage write landed before it');
	$first_strict = array_search($strict[0], $driver->log, true);
	$last_signing = array_search(end($signing), $driver->log, true);
	check($last_signing < $first_strict, 'the signing records come first');
	$apex = press_live($driver, $name, $name);
	check(in_array('v=spf1 -all', $apex, true) && in_array('google-site-verification=abc123', $apex, true)
		&& count($apex) === 2, 'the old SPF is replaced and the site verification is left alone', json_encode($apex));
	check(press_live($driver, $name, '_dmarc.' . $name) === array(
			'v=DMARC1; p=reject; aspf=s; adkim=s; rua=mailto:postmaster@' . $name . ',mailto:r@dmarc.example'),
		'DMARC is strict and keeps the owner\'s report address');
	check(!in_array('delete', array_column($driver->log, 'verb'), true), 'nothing is removed');
	$names_ok = true;
	foreach ($driver->log as $w) {
		if ($w['type'] === 'MX' && $w['name'] === $name) { $names_ok = false; }
		if (in_array($w['type'], array('A', 'AAAA', 'SRV'), true)) { $names_ok = false; }
	}
	check($names_ok, 'the domain\'s own MX, and any A, AAAA or SRV, are never written');

	section('Nameservers that never serve the records: stops before signing');
	list($domain, $driver, $name) = press_fixture();
	$result = press_run($domain, $driver, $name, array('blockers' => function ($d, $p) { return array('DKIM record — served by 1 of 2 nameservers'); }));
	$domain = new InboundEmailDomain((int)$domain->key, TRUE);
	check(!$result['ok'] && press_step($result, 'served') === 'failed', 'stops at the wait');
	check(!$domain->is_protected_identity(), 'signing is not on');
	check(press_live($driver, $name, '_dmarc.' . $name) === array('v=DMARC1; p=none; rua=mailto:r@dmarc.example'),
		'no strict record is written');

	section('A sealed key that does not match its record: stops before signing');
	list($domain, $driver, $name) = press_fixture();
	$result = press_run($domain, $driver, $name, array('sealed_key' => function ($d) use ($evil) { return $evil['private_pem']; }));
	$domain = new InboundEmailDomain((int)$domain->key, TRUE);
	check(!$result['ok'] && press_step($result, 'proved') === 'failed', 'stops at the proof');
	check(!$domain->is_protected_identity(), 'signing is not on');
	check(!in_array('v=spf1 -all', press_live($driver, $name, $name), true), 'no strict record is written');

	section('A strict record that is not written makes the result a failure');
	list($domain, $driver, $name) = press_fixture();
	$driver->fail_value = 'v=spf1 -all';
	$result = press_run($domain, $driver, $name);
	$domain = new InboundEmailDomain((int)$domain->key, TRUE);
	check(!$result['ok'] && press_step($result, 'strict') === 'failed', 'the result says the strict step failed');
	check($domain->is_protected_identity(), 'signing stays on: it is the safe middle state');
	$skipped = mailbox_protect_press_failures(array(array('ok' => true, 'action' => 'skipped',
		'record' => new DnsRecord('TXT', 'x.example', 'v=spf1 -all'), 'reason' => 'needs a choice')));
	check(count($skipped) === 1, 'a skipped record counts as not written, though apply() calls it ok');

	section('Two SPF records, or a record nobody confirmed: stops before any write');
	list($domain, $driver, $name) = press_fixture(array(array('TXT', '@', 'v=spf1 a -all')));
	$result = press_run($domain, $driver, $name);
	check(!$result['ok'] && press_step($result, 'signing') === 'failed' && empty($driver->log),
		'two SPF records at the apex: nothing written', $result['steps'][0]['detail']);
	list($domain, $driver, $name) = press_fixture();
	$consent = press_consent($name);
	array_pop($consent);
	$result = press_run($domain, $driver, $name, array(), $consent);
	check(!$result['ok'] && empty($driver->log), 'a record missing from the confirmation: nothing written');
	list($domain, $driver, $name) = press_fixture();
	$result = press_run($domain, $driver, $name, array('vault_open' => function ($u) { return false; }));
	check(!$result['ok'] && empty($driver->log), 'a locked vault: nothing written');
	list($domain, $driver, $name) = press_fixture();
	$result = press_run($domain, $driver, $name, array('sealed_key' => function ($d) { return null; }));
	check(!$result['ok'] && empty($driver->log) && press_step($result, 'signing') === 'failed',
		'someone whose vault does not open the key (not its owner): nothing written, stopped before any wait');

	section('The consent covers what is replaced, not only what is written');
	list($domain, $driver, $name) = press_fixture();
	$consent = press_consent($name);
	// Someone changes the SPF at the host after the page was shown.
	foreach ($driver->zones[$name] as $r) {
		if ($r->value === 'v=spf1 include:mailgun.org ~all') { $r->value = 'v=spf1 include:_spf.google.com ~all'; }
	}
	$result = press_run($domain, $driver, $name, array(), $consent);
	check(!$result['ok'] && empty($driver->log), 'an SPF changed since the confirmation: nothing written',
		$result['steps'][0]['detail']);

	$mx_row = function ($outcome, $live, $cutover) {
		return array('key' => 'k', 'record' => new DnsRecord('MX', 'fwd.example.com', 'relay.example.net', null, 10),
			'outcome' => $outcome, 'live' => $live, 'cutover' => $cutover);
	};
	$moved = mailbox_protect_press_decisions(array($mx_row(DnsReconciler::CONFLICTS,
		array(new DnsRecord('MX', 'fwd.example.com', 'other.example', null, 10)), true)), array('anything'));
	check($moved['error'] !== '' && empty($moved['decisions']['cutover']),
		'an MX that already carries mail is never moved by the press, and no cutover is ever granted');
	$new_mx = $mx_row(DnsReconciler::MISSING, array(), false);
	$fresh = mailbox_protect_press_decisions(array($new_mx), array(mailbox_protect_consent_token($new_mx)));
	check($fresh['error'] === '', 'a new MX where none exists is fine');
	check(mailbox_protect_press_unsuitable(array(array('key' => 'k', 'outcome' => DnsReconciler::UNKNOWN,
		'record' => new DnsRecord('TXT', 'example.com', 'v=spf1 -all'), 'live' => array(), 'cutover' => false))) !== '',
		'a row that could not be checked: no press is offered');

	section('Served means every nameserver');
	require_once(PathHelper::getIncludePath('includes/dns/AuthoritativeDns.php'));
	AuthoritativeDns::reset();
	AuthoritativeDns::setNameservers('served.example', array('192.0.2.1', '192.0.2.2'));
	$dkim = 'v=DKIM1; k=rsa; p=AAAA';
	AuthoritativeDns::$sender = function ($ip, $packet) use ($dkim) {
		$h = unpack('nid', substr($packet, 0, 2));
		$q = substr($packet, 12, strpos($packet, "\0", 12) - 12 + 5);
		$msg = pack('nnnnnn', $h['id'], 0x8400, 1, $ip === '192.0.2.1' ? 1 : 0, 0, 0) . $q;
		if ($ip === '192.0.2.1') {
			$msg .= "\xc0\x0c" . pack('nnNn', 16, 1, 300, strlen($dkim) + 1) . chr(strlen($dkim)) . $dkim;
		}
		return $msg;
	};
	$plan = new DnsRecordPlan('served.example', 'mailbox');
	$plan->addRecord('TXT', 'mailk1._domainkey.served.example', $dkim, null, null, 'DKIM');
	$behind = mailbox_protect_not_served_everywhere($plan);
	check(count($behind) === 1 && strpos($behind[0], '1 of 2') !== false, 'one nameserver behind: not served', implode(' | ', $behind));
	AuthoritativeDns::$sender = function ($ip, $packet) use ($dkim) {
		$h = unpack('nid', substr($packet, 0, 2));
		$q = substr($packet, 12, strpos($packet, "\0", 12) - 12 + 5);
		return pack('nnnnnn', $h['id'], 0x8400, 1, 1, 0, 0) . $q
			. "\xc0\x0c" . pack('nnNn', 16, 1, 300, strlen($dkim) + 1) . chr(strlen($dkim)) . $dkim;
	};
	check(mailbox_protect_not_served_everywhere($plan) === array(), 'both serving: served');
	$both_serve = AuthoritativeDns::$sender;
	AuthoritativeDns::$sender = function ($ip, $packet) use ($both_serve) {
		return $ip === '192.0.2.2' ? null : $both_serve($ip, $packet);
	};
	$silent = array();
	check(mailbox_protect_not_served_everywhere($plan, $silent) === array() && isset($silent['192.0.2.2']),
		'an address that never answers (no IPv6 route, say) is not a miss, and is remembered as silent');
	$asked = array();
	AuthoritativeDns::$sender = function ($ip, $packet) use ($both_serve, &$asked) { $asked[] = $ip; return $both_serve($ip, $packet); };
	mailbox_protect_not_served_everywhere($plan, $silent);
	check($asked === array('192.0.2.1'), 'and is not asked again in the same press');
	AuthoritativeDns::$sender = function ($ip, $packet) { return null; };
	$none = array();
	check(mailbox_protect_not_served_everywhere($plan, $none) === array(),
		'no nameserver answering at all leaves the decision to the readiness rows');
	AuthoritativeDns::reset();

	section('The proof reads each published record on its own');
	list($domain, $driver, $name) = press_fixture(array(array('TXT', 'mailk1._domainkey.@', $key['dns_value']),
		array('TXT', 'mailk1._domainkey.@', $evil['dns_value'])));
	$two = mailbox_protect_prove_published_key($domain, $key['private_pem']);
	check($two !== null && strpos($two, '2 records') !== false, 'two records at the selector are named as such', (string)$two);

	section('The key proof');
	check(MailboxDkimSigner::proveKeyMatchesRecord($key['private_pem'], $key['dns_value']) === null, 'the right key verifies');
	check(MailboxDkimSigner::proveKeyMatchesRecord($evil['private_pem'], $key['dns_value']) !== null, 'another key does not');
	check(MailboxDkimSigner::proveKeyMatchesRecord($key['private_pem'], 'v=DKIM1; k=rsa;') !== null, 'a record with no key does not');
} finally {
	DnsResolver::clearBackend();
}

harness_finish();
