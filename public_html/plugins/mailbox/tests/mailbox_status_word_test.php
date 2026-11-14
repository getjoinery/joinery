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

harness_finish();
