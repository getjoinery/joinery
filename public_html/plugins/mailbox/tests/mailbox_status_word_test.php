<?php
/** @joinery-test
 * name: mailbox_status_word
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 180
 */

/**
 * What the mailbox_status agent word runs (maintenance_scripts/sysadmin_tools/
 * mailbox_status.php) and the read-only Setup engine it runs on
 * (specs/agent_recipes_and_vocabulary.md, 2026-11-14; reviewer2's B2-B7).
 *
 *   - InboundEmailSetupCheck::readOnly() asks no fleet service and no provider
 *     API and writes no setting; notAsked() says what it skipped.
 *   - The script refuses a name the site does not host before any lookup, and
 *     a name of the wrong shape as bad usage.
 *   - With no domain it lists the site's own mail domains beside the
 *     spam-learning counts.
 *   - With a hosted domain, a check row carries id, status, severity and label
 *     only: never a summary, detail or fix. Flags are booleans and short
 *     names, with no owner id and no key.
 *
 * The fixture domain is under .invalid, so every DNS lookup it makes fails
 * fast and none reaches a real zone.
 *
 * WP1-WP2 of specs/mailbox_diagnosis_words.md: per-domain arrival counts
 * (counts only, never an address; unreadable, never a false zero), and each
 * nameserver row's reason, ms, skip-after-silence and the TCP probe that never
 * reports on an inside-network address.
 *
 * @version 1.2 - every silent nameserver asked once however many the zone has; catch_all
 * @version 1.1 - arrival counts, nameserver reasons, TCP 53 probe
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

if (!class_exists('InboundEmailSetupCheck')) {
	section('mailbox_status');
	harness_skip('the mailbox plugin is not active');
	harness_finish();
}

$script = PathHelper::getBasePath() . '/maintenance_scripts/sysadmin_tools/mailbox_status.php';
if (!is_file($script)) {
	$script = dirname(PathHelper::getIncludePath('includes/PathHelper.php'), 3) . '/maintenance_scripts/sysadmin_tools/mailbox_status.php';
}

function mbw_run(string $script, array $args): array {
	$cmd = array_merge(array(PHP_BINARY, $script), $args);
	$proc = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	$out = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$code = proc_close($proc);
	return array($code, $out, $err, json_decode(trim((string)$out), true));
}

$suffix = bin2hex(random_bytes(3));
$name = 'mbw-' . $suffix . '.invalid';
$d = new InboundEmailDomain(NULL);
$d->set('ied_domain', $name);
$d->set('ied_is_enabled', true);
$d->save();
$d->load();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', $d->key);

// ---------------------------------------------------------------------------
section('readOnly(): no fleet service, no provider API, no setting written');

$engine = InboundEmailSetupCheck::readOnly();
$fleet = Closure::bind(function () { return $this->fleetState(); }, $engine, InboundEmailSetupCheck::class)();
check($fleet['ok'] === false && strpos($fleet['error'], 'read-only') !== false,
	'the fleet state is not asked for', var_export($fleet, true));
$dkim = Closure::bind(function () { return $this->providerDkimStatus('NoSuchProviderClass', 'example.com'); }, $engine, InboundEmailSetupCheck::class)();
check($dkim['status'] === 'unreachable', 'a provider DKIM status is not asked for (a class that does not exist is never called)');
check($engine->notAsked() === array('fleet', 'provider_api'), 'notAsked() names both, once each', var_export($engine->notAsked(), true));

$before = Globalvars::get_instance()->get_setting('mailbox_relay_cutover_complete');
$state = Closure::bind(function () { return $this->recordCutover(array('complete' => true, 'reason' => '')); }, $engine, InboundEmailSetupCheck::class)();
$after = (new MultiSetting(array('setting_name' => 'mailbox_relay_cutover_complete')));
$stored = null;
foreach ($after as $row) { $stored = $row->get('stg_value'); }
check($state['complete'] === true && (string)$stored === (string)$before, 'the cutover verdict is returned but not written');

$normal = new InboundEmailSetupCheck();
check($normal->notAsked() === array(), 'an ordinary engine skips nothing');

// ---------------------------------------------------------------------------
section('The script: refusals before any lookup');

list($code, $out, , $j) = mbw_run($script, array('nothosted-' . $suffix . '.invalid'));
check($code === 0 && ($j['refused'] ?? '') === 'not_a_hosted_domain' && !isset($j['dns']) && !isset($j['checks']),
	'a name the site does not host is refused, with nothing looked up', $out);
list($code, $out, , $j) = mbw_run($script, array('gmail.com'));
check(($j['refused'] ?? '') === 'not_a_hosted_domain', 'a public mail domain is refused', $out);
foreach (array('Not A Domain', '../../etc/passwd', 'x;id') as $bad) {
	list($code) = mbw_run($script, array($bad));
	check($code === 2, 'a name of the wrong shape is bad usage: ' . $bad);
}
list($code) = mbw_run($script, array('a.com', 'b.com'));
check($code === 2, 'two arguments are bad usage');

// ---------------------------------------------------------------------------
section('The script: no domain lists the site\'s own domains');

list($code, $out, , $j) = mbw_run($script, array());
$listed = array_column(is_array($j['domains'] ?? null) ? $j['domains'] : array(), 'domain');
check($code === 0 && ($j['mailbox'] ?? '') === 'active' && in_array($name, $listed, true) && isset($j['corrections']),
	'the spam counts and the fixture domain are both there', mb_substr($out, 0, 400));

// ---------------------------------------------------------------------------
section('The script: one hosted domain, compiled strings only');

list($code, $out, $err, $j) = mbw_run($script, array($name));
check($code === 0 && ($j['domain'] ?? '') === $name && is_array($j['checks'] ?? null) && $j['checks'],
	'a hosted domain answers with its check rows', mb_substr($out . $err, 0, 400));
$keys = array();
foreach ($j['checks'] ?? array() as $row) { $keys = array_unique(array_merge($keys, array_keys($row))); }
sort($keys);
check($keys === array('id', 'label', 'severity', 'status'), 'each row carries id, status, severity and label, nothing else', implode(',', $keys));
$fk = array_keys($j['flags'] ?? array());
sort($fk);
check($fk === array('enabled', 'forwarding_subdomain', 'owner_has_vault', 'owner_set', 'protected', 'sealed_key_present', 'selector', 'send_lock_requested'),
	'the flags are the fixed set, with no owner id or key', implode(',', $fk));
check(strpos($out, 'ied_dkim_sealed_key') === false && strpos($out, '"fix"') === false && strpos($out, '"summary"') === false,
	'no key, no fix, no summary anywhere in the answer');
$records = array_column($j['dns'] ?? array(), 'record');
check(array_slice($records, 0, 4) === array('mx', 'spf', 'dmarc', 'dkim_ordinary'),
	'the DNS names are the fixed list', implode(',', $records));
check(array_key_exists('send_outcomes', $j ?? array()), 'the send ledger is reported, or null where it does not exist');

// ---------------------------------------------------------------------------
section('Arrival counts per domain: counts only, never a false zero');

define('MBS_FUNCTIONS_ONLY', true);
require($script);
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/mailbox_setup_hints.php'));

$db = DbConnector::get_instance()->get_db_link();
$mk_alias = function (string $local, bool $enabled) use ($db, $d) {
	$st = $db->prepare('INSERT INTO iea_inbound_email_aliases (iea_ied_inbound_email_domain_id, iea_alias, iea_is_enabled) VALUES (?, ?, ?) RETURNING iea_inbound_email_alias_id');
	$st->execute(array((int)$d->key, $local, $enabled ? 'true' : 'false'));
	$id = (int)$st->fetchColumn();
	harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', $id);
	return $id;
};
$mk_alias('got', true);
$mk_alias('Never', true);
$mk_alias('off', false);

$st = $db->prepare("INSERT INTO iem_inbound_email_messages (iem_ied_inbound_email_domain_id, iem_recipient, iem_direction, iem_received_time) VALUES (?, ?, 'inbound', now() - interval '1 hour') RETURNING iem_inbound_email_message_id");
$st->execute(array((int)$d->key, 'Got@' . $name));
$iem = (int)$st->fetchColumn();
harness_register_row('iem_inbound_email_messages', 'iem_inbound_email_message_id', $iem);
// The same message logged and stored: counted once.
$st = $db->prepare("INSERT INTO iel_inbound_email_logs (iel_to_address, iel_status, iel_iem_inbound_email_message_id, iel_create_time) VALUES (?, 'stored', ?, now() - interval '1 hour') RETURNING iel_inbound_email_log_id");
$st->execute(array('got@' . $name, $iem));
harness_register_row('iel_inbound_email_logs', 'iel_inbound_email_log_id', (int)$st->fetchColumn());
// A forward logged and not stored, eight days ago: counts toward last received, not the 7 days.
$st = $db->prepare("INSERT INTO iel_inbound_email_logs (iel_to_address, iel_status, iel_create_time) VALUES (?, 'forwarded', now() - interval '8 days') RETURNING iel_inbound_email_log_id");
$st->execute(array('got@' . $name));
harness_register_row('iel_inbound_email_logs', 'iel_inbound_email_log_id', (int)$st->fetchColumn());

list($code, $out, , $j) = mbw_run($script, array());
$row = null;
foreach ($j['domains'] ?? array() as $r) { if (($r['domain'] ?? '') === $name) { $row = $r; } }
check($row !== null && $row['mailboxes'] === 2 && $row['mailboxes_never_received'] === 1 && $row['received_7d'] === 1
	&& $row['arrivals'] === 'read' && $row['capped'] === false && $row['catch_all'] === false,
	'two enabled mailboxes, one never reached, one message in 7 days (logged and stored counts once)', json_encode($row));
check(is_string($row['last_received_time'] ?? null) && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\dZ$/', $row['last_received_time']),
	'the newest arrival, cut to the minute', json_encode($row['last_received_time'] ?? null));
check(strpos($out, '@') === false, 'no @ anywhere in the no-domain answer');

$aliases = new MultiInboundEmailAlias(array('domain_id' => (int)$d->key, 'deleted' => false));
$badge = array();
foreach (mailbox_setup_hints(array(array('domain' => $d, 'mailboxes' => array_map(function ($a) { return array('alias' => $a); }, (function ($m) { $o = array(); foreach ($m as $x) { $o[] = $x; } return $o; })($aliases))))) as $h) {
	$badge[] = $h['text'];
}
check($badge === array('no mail yet'), 'the badge counts the same one mailbox as never reached', json_encode($badge));

$fail = function () { return null; };
$u = mbs_domain_arrivals($d, $fail);
check($u['arrivals'] === 'unreadable' && $u['mailboxes_never_received'] === null && $u['received_7d'] === null
	&& $u['last_received_time'] === null, 'a failed lookup reads unreadable with null counts, never a zero', json_encode($u));
$u = mbs_domain_arrivals($d, null, $fail);
check($u['arrivals'] === 'unreadable' && $u['mailboxes_never_received'] === null, 'and so does a failed recent count');

// ---------------------------------------------------------------------------
section('Nameserver rows: reason, ms, asked once after silence, TCP probe never inside');

foreach (array('127.0.0.1', '10.1.2.3', '172.16.0.1', '172.31.255.255', '192.168.1.1', '169.254.0.1', '100.64.0.1', '0.0.0.0',
		'::1', '::', 'fc00::1', 'fd12:3456::1', 'fe80::1', '::ffff:10.0.0.1', '::ffff:127.0.0.1', '::ffff:192.168.0.1', 'not-an-ip') as $ip) {
	check(mbs_tcp_53($ip) === 'skipped', 'TCP probe skipped for ' . $ip);
}
foreach (array('8.8.8.8', '172.32.0.1', '100.128.0.1', '2001:4860:4860::8888', '::ffff:8.8.8.8') as $ip) {
	check(mbs_inside_address($ip) === false, $ip . ' is not inside');
}

$sent = array();
AuthoritativeDns::reset();
AuthoritativeDns::setNameservers($name, array('10.9.9.1', '10.9.9.2'));
AuthoritativeDns::$sender = function (string $ip, string $packet) use (&$sent) {
	$sent[] = $ip;
	if ($ip === '10.9.9.2') { return null; }
	$qend = 12;
	while (ord($packet[$qend]) !== 0) { $qend += 1 + ord($packet[$qend]); }
	return substr($packet, 0, 2) . pack('nnnnn', 0x8403, 1, 0, 0, 0) . substr($packet, 12, $qend + 5 - 12);   // NXDOMAIN
};
$silent = array();
$first = mbs_authoritative('mx', $name, DNS_MX, $name, $silent);
$second = mbs_authoritative('spf', $name, DNS_TXT, $name, $silent);
$by = function (array $rows, string $ip) { foreach ($rows as $r) { if ($r['nameserver'] === $ip) { return $r; } } return null; };
$f2 = $by($first, '10.9.9.2');
check($f2 !== null && $f2['status'] === 'no_clean_answer' && $f2['reason'] === 'no_reply' && is_int($f2['ms']) && $f2['tcp_53'] === 'skipped',
	'the silent server: no_reply, ms, tcp_53 skipped (inside address)', json_encode($f2));
$f1 = $by($first, '10.9.9.1');
check($f1 !== null && $f1['status'] === 'no_answer' && $f1['reason'] === null && !isset($f1['tcp_53']),
	'the answering server: no reason, no TCP probe', json_encode($f1));
$s2 = $by($second, '10.9.9.2');
check($s2 !== null && $s2['ms'] === null && $s2['skipped_after'] === 'mx' && $s2['reason'] === 'no_reply' && $s2['status'] === 'no_clean_answer',
	'under the next name it repeats with ms null and skipped_after mx', json_encode($s2));
check(count(array_keys($sent, '10.9.9.2', true)) === 1 && count(array_keys($sent, '10.9.9.1', true)) === 2,
	'the silent server was asked once; the other twice', json_encode($sent));
check(mbs_verdict(array('status' => 'no_answer', 'answer' => array()), $second) === 'agree', 'the verdict reads as before');
AuthoritativeDns::reset();

// A zone with more addresses than rows (Cloudflare: 12), all silent: each is
// asked once for the whole domain, and only the first six get a TCP probe.
$sent = array();
$eight = array();
for ($i = 1; $i <= 8; $i++) { $eight[] = '10.8.8.' . $i; }
AuthoritativeDns::reset();
AuthoritativeDns::setNameservers($name, $eight);
AuthoritativeDns::$sender = function (string $ip, string $packet) use (&$sent) { $sent[] = $ip; return null; };
$silent = array();
$r1 = mbs_authoritative('mx', $name, DNS_MX, $name, $silent);
$r2 = mbs_authoritative('spf', $name, DNS_TXT, $name, $silent);
$r3 = mbs_authoritative('dmarc', '_dmarc.' . $name, DNS_TXT, $name, $silent);
check(count($sent) === 8 && count(array_unique($sent)) === 8, 'eight silent servers, three names: eight queries, one each', json_encode($sent));
check(count($silent) === 8, 'every silent server is remembered, not only the first six');
check(count(array_filter($silent, function ($w) { return $w['tcp_53'] !== null; })) === 6
	&& $silent['10.8.8.7']['tcp_53'] === null && $silent['10.8.8.8']['tcp_53'] === null,
	'only the first six get the TCP probe');
check(count($r1) === 6 && count($r2) === 6 && count($r3) === 6 && ($r2[0]['skipped_after'] ?? '') === 'mx',
	'each name still carries six rows, the later ones as skipped');
AuthoritativeDns::reset();

// ---------------------------------------------------------------------------
section('MailTestProbe: refusals before anything is sent');

$a = MailTestProbe::sendOrdinary('nothosted-' . $suffix . '.invalid');
check($a['sent'] === false && $a['refused'] === 'not_a_hosted_domain', 'a domain the site does not host is refused');
$p = new InboundEmailDomain(NULL);
$p->set('ied_domain', 'mbwp-' . $suffix . '.invalid');
$p->set('ied_is_enabled', true);
$p->set('ied_is_protected_identity', true);
$p->save();
$p->load();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', $p->key);
$a = MailTestProbe::sendOrdinary('mbwp-' . $suffix . '.invalid');
check($a['sent'] === false && $a['refused'] === 'protected', 'a protected domain is refused on the ordinary path');
$a = MailTestProbe::sendProtected(SessionControl::get_instance(), $name);
check($a['sent'] === false && $a['refused'] === 'not_protected', 'an ordinary domain is refused on the protected path');
check(strpos(MailTestProbe::describe(array('domain' => 'x.com', 'sent' => false, 'refused' => 'protected')), 'signed-in session') !== false,
	'a refusal reads in plain words');
list($code, $out) = mbw_run(str_replace('mailbox_status.php', 'mail_test_send.php', $script), array('mbwp-' . $suffix . '.invalid'));
$j = json_decode(trim((string)$out), true);
check($code === 0 && ($j['refused'] ?? '') === 'protected', 'the agent\'s script refuses a protected domain too', $out);

$rows = (new InboundEmailSetupCheck())->runDomainChecks($name);
$tm = null;
foreach ($rows as $r) { if ($r['id'] === 'domain.test_message') { $tm = $r; } }
check($tm !== null && ($tm['fix']['action']['action'] ?? '') === 'send_test_message' && $tm['status'] === InboundEmailSetupCheck::INFO,
	'every enabled hosted domain has a Send test message row, neutral until pressed');

// ---------------------------------------------------------------------------
section('A provider that refuses an unregistered sender domain: the DKIM row says the mail does not leave');

if (!class_exists('MbwRefusingProvider', false)) {
	class MbwRefusingProvider implements DkimRecordSource, SendingDomainRegistrar, RefusesUnregisteredSenderDomains {
		public static function getDkimStatus(string $domain): array { return array('status' => 'not_registered', 'records' => array()); }
		public static function createSendingDomain(string $domain): array { return array('status' => 'ok'); }
	}
	class MbwSigningProvider implements DkimRecordSource {
		public static function getDkimStatus(string $domain): array { return array('status' => 'not_registered', 'records' => array()); }
	}
}
$rows_for = function (string $class) {
	$engine = new InboundEmailSetupCheck();
	return Closure::bind(function () use ($class) {
		return $this->providerDkimRows($class, 'Stub', 'example.com', 'domain.dkim_provider');
	}, $engine, InboundEmailSetupCheck::class)();
};
$refusing = $rows_for('MbwRefusingProvider')[0];
check($refusing['status'] === InboundEmailSetupCheck::FAIL && $refusing['severity'] === InboundEmailSetupCheck::REQUIRED
	&& strpos($refusing['summary'], 'does not leave') !== false,
	'a refusing provider: a required failure saying the mail does not leave', $refusing['summary']);
check(($refusing['fix']['action']['action'] ?? '') === 'register_sending_domain' && ($refusing['fix']['action']['domain'] ?? '') === 'example.com',
	'and, the provider being able to register, a Register button for the domain');
$signing = $rows_for('MbwSigningProvider')[0];
check($signing['status'] === InboundEmailSetupCheck::WARN && strpos($signing['summary'], 'fails DMARC alignment') !== false
	&& !isset($signing['fix']['action']),
	'a provider that signs as its own domain: a warning about alignment, and no Register button it cannot honour', $signing['summary']);
if (!class_exists('MbwUnverifiedProvider', false)) {
	class MbwUnverifiedProvider implements DkimRecordSource, RefusesUnregisteredSenderDomains {
		public static $state = 'unverified';
		public static function getDkimStatus(string $domain): array {
			return array('status' => 'ok', 'records' => array(array('type' => 'CNAME', 'name' => 's1._domainkey.example.com', 'value' => 'dkim.example.net')));
		}
		public static function getSendingDomainState(string $domain): string { return self::$state; }
		public static function verifySendingDomain(string $domain): string { return 'active'; }
	}
}
$verified_row = function (array $rows) {
	foreach ($rows as $r) { if (substr($r['id'], -9) === '.verified') { return $r; } }
	return null;
};
$v = $verified_row($rows_for('MbwUnverifiedProvider'));
check($v !== null && $v['status'] === InboundEmailSetupCheck::FAIL && $v['severity'] === InboundEmailSetupCheck::REQUIRED
	&& ($v['fix']['action']['action'] ?? '') === 'verify_sending_domain',
	'records published but the provider has not verified the domain: a required failure with Ask it to check again', var_export($v, true));
MbwUnverifiedProvider::$state = 'active';
$v = $verified_row($rows_for('MbwUnverifiedProvider'));
check($v !== null && $v['status'] === InboundEmailSetupCheck::PASS, 'once the provider reports it active, the row passes');
check($verified_row($rows_for('MbwSigningProvider')) === null, 'a provider that does not refuse gets no verification row');
$ro = InboundEmailSetupCheck::readOnly();
$ro_rows = Closure::bind(function () { return $this->providerDkimRows('MbwUnverifiedProvider', 'Stub', 'example.com', 'domain.dkim_provider'); }, $ro, InboundEmailSetupCheck::class)();
$ro_v = $verified_row($ro_rows);
check(count($ro_rows) === 2 && $ro_rows[0]['status'] === InboundEmailSetupCheck::UNKNOWN
	&& $ro_v !== null && $ro_v['status'] === InboundEmailSetupCheck::UNKNOWN
	&& in_array('provider_api', $ro->notAsked(), true),
	'the read-only engine asks the provider nothing: the DKIM and verification rows are unknown, named as not asked');

check(in_array('RefusesUnregisteredSenderDomains', class_implements('Smtp2GoProvider') ?: array(), true)
	&& !in_array('RefusesUnregisteredSenderDomains', class_implements('MailgunProvider') ?: array(), true),
	'SMTP2GO declares it refuses; Mailgun does not');

harness_finish();
