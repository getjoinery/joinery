<?php
/** @joinery-test
 * name: services_tenants
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * The operator side of the services a self-hosted site rents from this plane
 * (specs/services_phase2_platform.md §2, §4, §6, §7 — build item 2):
 *
 *   - First contact creates the tenant row at `unpaid` and mints NOTHING; the
 *     answer is not entitled. A date granted on the plane is what lets the
 *     next enrol build the service.
 *   - Mail enrol builds the subaccount (capped at the allowance), the sender
 *     domain (mail.<host>, records kept), and one SMTP user, and answers the
 *     send values the site writes. A second enrol mints a fresh credential in
 *     the same subaccount and creates nothing else. A host change re-enrols
 *     the sender domain. Zero records from the provider fails loudly.
 *   - Shelf enrol mints nothing: a slug, a prefix inside the plane's backup storage
 *     target, and no credential anywhere in the answer.
 *   - Status is contract C2 per service; the webhook nudges the mail figure and
 *     a figure from an earlier month reads as 0.
 *   - Release closes the subaccount, starts the retention clock (shelf only),
 *     and is idempotent; a new date reactivates a stopped row in place; the
 *     ladder's two acts close and reopen the subaccount.
 *   - The three actions run as the key's user and refuse without a key.
 *
 * The mail provider is a Guzzle MockHandler with history: every call is
 * asserted at the wire and nothing leaves the process. Rows are written to the
 * test database and deleted.
 *
 * Run: php tests/run.php --only=plugins/server_manager/tests/services_tenants_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
// A live session before any \$_SESSION write: FormWriter and OAuth2State start
// one when none is active, which would replace what the test put there.
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
require_once(PathHelper::getIncludePath('tests/lib/logic.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/Smtp2GoClient.php'));

$db = DbConnector::get_instance()->get_db_link();
$has_table = $db->query("SELECT to_regclass('public.svt_service_tenants') IS NOT NULL")->fetchColumn();
if (!$has_table) {
	section('schema');
	harness_skip('svt_service_tenants is not in the test database yet — sync the server_manager plugin, then copy live to test');
	harness_finish();
	exit;
}

$cleanup_svt = array();
$cleanup_bkt = array();
harness_defer(function () use (&$cleanup_svt, &$cleanup_bkt, $db) {
	foreach ($cleanup_svt as $id) { $db->exec("DELETE FROM svt_service_tenants WHERE svt_service_tenant_id = " . (int)$id); }
	foreach ($cleanup_bkt as $id) { $db->exec("DELETE FROM bkt_backup_targets WHERE bkt_backup_target_id = " . (int)$id); }
});
$track = function (ServiceTenant $row) use (&$cleanup_svt) {
	if (!in_array((int)$row->key, $cleanup_svt, true)) { $cleanup_svt[] = (int)$row->key; }
	return $row;
};

// ── The provider, mocked ────────────────────────────────────────────────────
$history = array();
$mock = new \GuzzleHttp\Handler\MockHandler(array());
$stack = \GuzzleHttp\HandlerStack::create($mock);
$stack->push(\GuzzleHttp\Middleware::history($history));
$client = new Smtp2GoClient('harness-master-key', new \GuzzleHttp\Client(array('handler' => $stack)));

/** Queue provider answers, in order. */
$reply = function (array $bodies) use ($mock) {
	foreach ($bodies as $body) {
		$mock->append(new \GuzzleHttp\Psr7\Response(200, array(), json_encode(array('data' => $body))));
	}
};
/** The calls made since the last drain: [path => body]. */
$drain = function () use (&$history) {
	$calls = array();
	foreach ($history as $entry) {
		$calls[] = array(
			'path' => ltrim($entry['request']->getUri()->getPath(), '/'),
			'body' => json_decode((string)$entry['request']->getBody(), true) ?: array(),
		);
	}
	$history = array();
	return $calls;
};
$paths = function (array $calls) { return array_map(function ($c) { return $c['path']; }, $calls); };
$domain_entry = function (string $full, bool $verified) {
	return array('domains' => array(array('domain' => array(
		'fulldomain' => $full, 'dkim_selector' => 's2g', 'dkim_value' => 'dkim.smtp2go.net',
		'rpath_selector' => 'em', 'rpath_value' => 'return.smtp2go.net',
		'dkim_verified' => $verified, 'rpath_verified' => $verified,
	))));
};

harness_set_setting_mem('server_manager_hosted_send_allowance', '1000');
harness_set_setting_mem('server_manager_hosted_shelf_allowance_gb', '10');
harness_set_setting_mem('server_manager_services_grace_days', '14');
harness_set_setting_mem('server_manager_smtp2go_sandbox_users', '');
harness_set_setting_mem('server_manager_smtp2go_referral_url', 'https://smtp2go.example/ref');
harness_set_setting_mem('server_manager_storage_referral_url', '');

// ── Fixtures ────────────────────────────────────────────────────────────────
$owner = make_user('SvcOwner');
$other = make_user('SvcOther');
$key = make_machine_key($owner->key, 'Joinery services', 3);
$key_id = (int)$key['api_key']->key;
$host = 'svc-' . substr(md5(uniqid('', true)), 0, 6) . '.example.com';

// ---------------------------------------------------------------------------
section('first contact: a row at unpaid, nothing minted');
$answer = JoineryServices::enrol($owner->key, $key_id, 'mail', $host, $client);
$row = $track(ServiceTenant::forKey($key_id, 'mail'));
check($row !== null && (string)$row->get('svt_state') === ServiceTenant::STATE_UNPAID, 'the tenant row is created at unpaid');
check($answer['entitled'] === false && $answer['state'] === 'unpaid' && $answer['paid_until'] === null,
	'the answer is not entitled with no date', json_encode($answer));
check(count($drain()) === 0, 'nothing went to the provider');
check((string)$row->get('svt_slug') === 't' . (int)$row->key, 'the slug is t<id>');
check((string)$row->get('svt_host') === $host, 'the host is recorded as a label');
check((int)$row->get('svt_apk_api_key_id') === $key_id && (int)$row->get('svt_usr_user_id') === (int)$owner->key,
	'the row is keyed by the connected key and owned by its account');
check($answer['manage_url'] !== '' && substr($answer['manage_url'], -32) === '/profile/server_manager/services',
	'the answer names the account\'s services page', $answer['manage_url']);
check(JoineryServices::enrol($owner->key, $key_id, 'mail', $host, $client)['state'] === 'unpaid'
	&& ServiceTenant::forKey($key_id, 'mail')->key == $row->key, 'a second contact finds the same row');

try {
	JoineryServices::enrol($owner->key, $key_id, 'mail', 'not a host', $client);
	check(false, 'a bad host is refused');
} catch (JoineryServicesException $e) {
	check(strpos($e->getMessage(), 'hostname') !== false, 'a bad host is refused with a sentence');
}
try {
	JoineryServices::enrol($other->key, $key_id, 'mail', $host, $client);
	check(false, 'another account cannot use this key\'s row');
} catch (JoineryServicesException $e) {
	check(true, 'a key from another account is refused');
}

// ---------------------------------------------------------------------------
section('grant, then mail enrol builds the service at once');
JoineryServices::grant($row, '2030-01-01', $client);
$row = ServiceTenant::forKey($key_id, 'mail');
check((string)$row->get('svt_paid_until') === '2030-01-01 23:59:59', 'a bare date is paid through the whole day');
check($row->entitled() && (string)$row->get('svt_state') === ServiceTenant::STATE_UNPAID,
	'granting an unpaid row does not build anything by itself');
check((int)$row->get('svt_allowance') === 1000, 'the allowance is snapshotted on grant');

$reply(array(
	array('subaccount_id' => 'sub-' . $host),                       // subaccount/add
	array(),                                                          // subaccount/edit (limit)
	$domain_entry('mail.' . $host, false),                            // domain/add
	array('username' => 'ignored-by-caller'),                         // users/smtp/add
	$domain_entry('mail.' . $host, false),                            // domain/verify probe
));
$answer = JoineryServices::enrol($owner->key, $key_id, 'mail', $host, $client);
$calls = $drain();
check($paths($calls) === array('subaccount/add', 'subaccount/edit', 'domain/add', 'users/smtp/add', 'domain/verify'),
	'subaccount, limit, sender domain, SMTP user, one verify probe — in that order', json_encode($paths($calls)));
check(($calls[0]['body']['subaccount_name'] ?? '') === 'Joinery services — ' . $host
	&& ($calls[0]['body']['email'] ?? '') === (string)$owner->get('usr_email'),
	'the subaccount is labelled for the host and owned by the account\'s address');
check(($calls[1]['body']['limit'] ?? 0) === 1000 && ($calls[1]['body']['subaccount_id'] ?? '') === 'sub-' . $host,
	'the limit is the allowance, on the tenant\'s own subaccount');
check(($calls[2]['body']['domain'] ?? '') === 'mail.' . $host, 'the sender domain is mail.<host>');
check(($calls[3]['body']['subaccount_id'] ?? '') === 'sub-' . $host && !isset($calls[3]['body']['status']),
	'the SMTP user is minted inside the subaccount, delivering (no sandbox)');
$username = (string)($calls[3]['body']['username'] ?? '');
check(strpos($username, 't' . (int)$row->key . '-') === 0, 'the username carries the tenant slug', $username);

$mail = $answer['mail'] ?? array();
check($answer['entitled'] === true && $answer['state'] === 'active', 'the answer is entitled and active');
check(($mail['service'] ?? '') === 'smtp' && ($mail['host'] ?? '') === 'mail.smtp2go.com' && ($mail['port'] ?? 0) === 587,
	'the send values name the provider\'s relay');
check(($mail['username'] ?? '') === $username && strlen((string)($mail['password'] ?? '')) === 28,
	'the credential minted is the credential returned');
check(($mail['sender'] ?? '') === 'bounces@mail.' . $host && ($mail['helo'] ?? '') === 'mail.' . $host
	&& ($mail['hostname'] ?? '') === 'mail.' . $host, 'sender, HELO and hostname are the sending identity');
check(count($mail['records'] ?? array()) === 2 && ($mail['records'][0]['type'] ?? '') === 'CNAME',
	'the DNS records come back for the publish box', json_encode($mail['records'] ?? null));
check(($mail['domain_state'] ?? '') === 'domain_added', 'the domain is added, not yet verified');

$row = ServiceTenant::forKey($key_id, 'mail');
check((string)$row->get('svt_provider_subaccount_id') === 'sub-' . $host
	&& (string)$row->get('svt_provider_domain') === 'mail.' . $host
	&& (string)$row->get('svt_provider_user_id') === $username,
	'the provider ids are on the row');
check(count($row->mailRecords()) === 2, 'the records are kept on the row');
$stored = $db->query("SELECT svt_mail_records, svt_notice FROM svt_service_tenants WHERE svt_service_tenant_id = " . (int)$row->key)->fetch(PDO::FETCH_ASSOC);
check(strpos((string)$stored['svt_mail_records'], $mail['password']) === false, 'the password is nowhere on the row');

// ---------------------------------------------------------------------------
section('a second enrol: same subaccount and domain, a fresh credential');
$reply(array(
	array(),                                        // subaccount/edit
	array(),                                        // users/smtp/remove
	array('username' => 'x'),                       // users/smtp/add
	$domain_entry('mail.' . $host, true),           // domain/verify → verified now
));
$again = JoineryServices::enrol($owner->key, $key_id, 'mail', $host, $client);
$calls = $drain();
check($paths($calls) === array('subaccount/edit', 'users/smtp/remove', 'users/smtp/add', 'domain/verify'),
	'no new subaccount, no new domain: remove the old user, mint a new one', json_encode($paths($calls)));
check(($calls[1]['body']['username'] ?? '') === $username, 'the previous SMTP user is the one removed');
check(($again['mail']['username'] ?? '') !== $username && ($again['mail']['password'] ?? '') !== $mail['password'],
	'the credential is fresh');
check(($again['mail']['domain_state'] ?? '') === 'domain_verified' && ($again['domain_state'] ?? '') === 'domain_verified',
	'the probe found the domain verified');
check(ServiceTenant::forKey($key_id, 'mail')->key == $row->key, 'still the one row');

// ---------------------------------------------------------------------------
section('a host change re-enrols the sender domain');
$host2 = 'moved-' . substr(md5(uniqid('', true)), 0, 6) . '.example.com';
$reply(array(
	array(),                                        // subaccount/edit
	array(),                                        // domain/remove (old)
	$domain_entry('mail.' . $host2, false),         // domain/add (new)
	array(),                                        // users/smtp/remove
	array('username' => 'x'),                       // users/smtp/add
	$domain_entry('mail.' . $host2, false),         // domain/verify
));
$moved = JoineryServices::enrol($owner->key, $key_id, 'mail', $host2, $client);
$calls = $drain();
check($paths($calls) === array('subaccount/edit', 'domain/remove', 'domain/add', 'users/smtp/remove', 'users/smtp/add', 'domain/verify'),
	'the old sender domain is released and the new one added', json_encode($paths($calls)));
check(($calls[1]['body']['domain'] ?? '') === 'mail.' . $host && ($calls[2]['body']['domain'] ?? '') === 'mail.' . $host2,
	'old out, new in');
$row = ServiceTenant::forKey($key_id, 'mail');
check((string)$row->get('svt_host') === $host2 && (string)$row->get('svt_provider_domain') === 'mail.' . $host2
	&& $moved['service'] === 'mail', 'the row carries the new host and domain');
check(($moved['mail']['sender'] ?? '') === 'bounces@mail.' . $host2, 'the send values follow the new host');

// ---------------------------------------------------------------------------
section('status: contract C2, the host label, the webhook figure');
$reply(array($domain_entry('mail.' . $host2, false)));       // domain/verify probe
$status = JoineryServices::status($owner->key, $key_id, $host2, $client);
check($paths($drain()) === array('domain/verify'), 'an unverified domain is probed once on status');
$m = $status['services']['mail'] ?? array();
foreach (array('figure', 'allowance', 'paid_until', 'state', 'notice', 'action_label', 'action_url') as $field) {
	check(array_key_exists($field, $m), 'C2 carries ' . $field);
}
check($m['figure'] === 0 && $m['allowance'] === 1000 && $m['percent'] === 0, 'figure 0 of 1000');
check($m['action_url'] === 'https://smtp2go.example/ref' && $m['action_label'] === 'Use your own email account',
	'the first door is the plane\'s referral link');
check($status['connected'] === true && !isset($status['services']['shelf']), 'only services with a row appear');

check(JoineryServices::countWebhookSend((string)$row->get('svt_provider_user_id'), ''), 'a delivery for the tenant\'s SMTP user is taken');
check(JoineryServices::countWebhookSend('nobody-' . $host2, 'sub-' . $host), 'a delivery naming only the subaccount is taken');
check(!JoineryServices::countWebhookSend('nobody', 'nosub'), 'an unknown credential is dropped');
$row = ServiceTenant::forKey($key_id, 'mail');
check((int)$row->get('svt_figure') === 2 && JoineryServices::currentFigure($row) === 2, 'the figure moved twice');
$row->set('svt_figure_time', gmdate('Y-m-d H:i:s', strtotime('first day of last month')));
$row->save();
check(JoineryServices::currentFigure($row) === 0, 'a figure from an earlier month reads as 0');
check(JoineryServices::countWebhookSend((string)$row->get('svt_provider_user_id'), '')
	&& JoineryServices::currentFigure(ServiceTenant::forKey($key_id, 'mail')) === 1,
	'the first delivery of a new month starts the count again');

$status = JoineryServices::status($owner->key, $key_id, 'renamed-' . $host2, null);
check(count($drain()) === 0, 'a verified domain is not probed');
check((string)ServiceTenant::forKey($key_id, 'mail')->get('svt_host') === 'renamed-' . $host2, 'status refreshes the host label');

// ---------------------------------------------------------------------------
section('shelf enrol: a slug and a prefix, no credential');
$target = new BackupTarget(NULL);
$target->set('bkt_name', 'harnesstest services backup storage');
$target->set('bkt_provider', 's3');
$target->set('bkt_bucket', 'harness-shelf');
$target->set('bkt_path_prefix', 'harness-backups');
$target->set('bkt_credentials', json_encode(array('access_key' => 'AKIA-harness', 'secret_key' => 'harness-secret',
	'region' => 'us-east-1', 'endpoint' => 'https://s3.example')));
$target->save();
$cleanup_bkt[] = (int)$target->key;
harness_set_setting_mem('server_manager_services_shelf_target_id', (string)$target->key);

$shelf = JoineryServices::enrol($owner->key, $key_id, 'shelf', $host2, $client);
$shelf_row = $track(ServiceTenant::forKey($key_id, 'shelf'));
check($shelf['entitled'] === false && (string)$shelf_row->get('svt_state') === 'unpaid', 'backup storage row starts unpaid too');
JoineryServices::grant($shelf_row, '2030-06-30 12:00:00', $client);
$shelf = JoineryServices::enrol($owner->key, $key_id, 'shelf', $host2, $client);
$c = $shelf['shelf'] ?? array();
$slug = 't' . (int)$shelf_row->key;
check($shelf['entitled'] === true && $shelf['state'] === 'active', 'entitled: backup storage row is active');
check(($c['slug'] ?? '') === $slug && ($c['path_prefix'] ?? '') === 'harness-backups'
	&& ($c['prefix'] ?? '') === 'harness-backups/' . $slug . '/', 'the tenant lives at {prefix}/{slug}/');
check(($c['retention_days'] ?? 0) === 90, 'the 90-day promise is in the answer');
check(($c['bucket'] ?? '') === 'harness-shelf' && ($c['region'] ?? '') === 'us-east-1' && ($c['endpoint'] ?? '') === 'https://s3.example',
	'bucket, region and endpoint come back for the managed target row');
$flat = json_encode($shelf);
check(strpos($flat, 'AKIA-harness') === false && strpos($flat, 'harness-secret') === false
	&& !isset($c['access_key']) && !isset($c['secret_key']), 'no credential anywhere in the answer');
check($shelf['allowance'] === 10 * 1073741824 && $shelf['allowance_label'] === '10 GB', 'backup storage allowance is in bytes');
check(count($drain()) === 0, 'backup storage touches no mail provider');

harness_set_setting_mem('server_manager_services_shelf_target_id', '999999999');
try {
	JoineryServices::enrol($owner->key, $key_id, 'shelf', $host2, $client);
	check(false, 'a missing backup storage target refuses');
} catch (JoineryServicesException $e) {
	check(strpos($e->getMessage(), 'no backup storage target') !== false, 'a missing backup storage target refuses with a sentence');
}
harness_set_setting_mem('server_manager_services_shelf_target_id', (string)$target->key);

// ---------------------------------------------------------------------------
section('release: the subaccount closes, the retention clock starts');
$reply(array(array()));                                   // subaccount/close
$rel = JoineryServices::release($owner->key, $key_id, 'mail', $client);
$calls = $drain();
check($paths($calls) === array('subaccount/close') && ($calls[0]['body']['subaccount_id'] ?? '') === 'sub-' . $host,
	'mail release closes the tenant\'s subaccount');
$row = ServiceTenant::forKey($key_id, 'mail');
check((string)$row->get('svt_state') === 'released' && $row->get('svt_revoked_time') !== null
	&& $row->get('svt_prune_after_time') === null, 'released; revoked stamped; mail has nothing to prune');
check(count($rel['records']) === 2 && $rel['released'] === true, 'the records the plane published are listed for replacement');
JoineryServices::release($owner->key, $key_id, 'mail', $client);
check(count($drain()) === 0, 'a second release does nothing at the provider');

$srel = JoineryServices::release($owner->key, $key_id, 'shelf', $client);
$shelf_row = ServiceTenant::forKey($key_id, 'shelf');
$expected_prune = LibraryFunctions::time_shift((string)$shelf_row->get('svt_revoked_time'), '90 days', 'Y-m-d H:i:s');
check((string)$shelf_row->get('svt_state') === 'released'
	&& substr((string)$shelf_row->get('svt_prune_after_time'), 0, 19) === $expected_prune,
	'shelf release sets prune-after = revoked + 90 days');
check(strpos((string)$srel['notice'], '90 days') !== false, 'the notice says backup storage is kept 90 days');
try {
	JoineryServices::release($owner->key, $key_id, 'nothing', $client);
	check(false, 'an unknown service refuses');
} catch (JoineryServicesException $e) {
	check(true, 'releasing a service the site never held is refused');
}

// ---------------------------------------------------------------------------
section('a new date reactivates a stopped row in place');
$reply(array(array()));                                   // subaccount/reopen
JoineryServices::grant($row, '2031-01-01', $client);
$calls = $drain();
$row = ServiceTenant::forKey($key_id, 'mail');
check($paths($calls) === array('subaccount/reopen'), 'mail reopens the subaccount');
check((string)$row->get('svt_state') === 'active' && $row->get('svt_revoked_time') === null
	&& $row->get('svt_lapse_time') === null && $row->get('svt_notice') === null, 'active again, clocks cleared');
JoineryServices::grant($shelf_row, '2031-01-01', $client);
$shelf_row = ServiceTenant::forKey($key_id, 'shelf');
check((string)$shelf_row->get('svt_state') === 'active' && $shelf_row->get('svt_prune_after_time') === null,
	'backup storage comes back in place with its prune date cleared');
check(count($drain()) === 0, 'backup storage touches no provider to come back');

section('the ladder\'s two acts');
$reply(array(array()));                                   // subaccount/close
JoineryServices::suspend($row, $client);
$row = ServiceTenant::forKey($key_id, 'mail');
check($paths($drain()) === array('subaccount/close') && (string)$row->get('svt_state') === 'suspended'
	&& strpos((string)$row->get('svt_notice'), 'paid-through date has passed') !== false,
	'suspend closes the subaccount and says why');
$reply(array(array()));                                   // subaccount/reopen
JoineryServices::reactivate($row, $client);
check($paths($drain()) === array('subaccount/reopen') && (string)ServiceTenant::forKey($key_id, 'mail')->get('svt_state') === 'active',
	'reactivate reopens it');
JoineryServices::suspend($shelf_row, $client);
$shelf_row = ServiceTenant::forKey($key_id, 'shelf');
check((string)$shelf_row->get('svt_state') === 'suspended' && $shelf_row->get('svt_prune_after_time') !== null,
	'a suspended shelf starts its retention clock');

// ---------------------------------------------------------------------------
section('zero records from the provider fails loudly, subaccount kept');
$key2 = make_machine_key($owner->key, 'Joinery services', 3);
$key2_id = (int)$key2['api_key']->key;
$host3 = 'zero-' . substr(md5(uniqid('', true)), 0, 6) . '.example.com';
JoineryServices::enrol($owner->key, $key2_id, 'mail', $host3, $client);
$zrow = $track(ServiceTenant::forKey($key2_id, 'mail'));
JoineryServices::grant($zrow, '2030-01-01', $client);
$reply(array(
	array('subaccount_id' => 'sub-zero'),
	array(),
	array('domains' => array(array('domain' => array('fulldomain' => 'mail.' . $host3)))),   // no selectors → no records
));
try {
	JoineryServices::enrol($owner->key, $key2_id, 'mail', $host3, $client);
	check(false, 'zero records refuses');
} catch (JoineryServicesException $e) {
	check(strpos($e->getMessage(), 'could not read any DNS records') !== false, 'the refusal names the cause');
}
$calls = $drain();
check($paths($calls) === array('subaccount/add', 'subaccount/edit', 'domain/add'), 'the line stopped before any SMTP user was minted');
$zrow = ServiceTenant::forKey($key2_id, 'mail');
check((string)$zrow->get('svt_provider_subaccount_id') === 'sub-zero' && (string)$zrow->get('svt_state') === 'provisioning'
	&& strpos((string)$zrow->get('svt_notice'), 'DNS records') !== false,
	'the subaccount is kept on the row, the state is provisioning, the notice is the sentence');
$reply(array(array(), $domain_entry('mail.' . $host3, false), array('username' => 'x'), $domain_entry('mail.' . $host3, false)));
$fixed = JoineryServices::enrol($owner->key, $key2_id, 'mail', $host3, $client);
check($paths($drain()) === array('subaccount/edit', 'domain/add', 'users/smtp/add', 'domain/verify'),
	'the retry does not create a second subaccount');
check($fixed['state'] === 'active' && $fixed['notice'] === '', 'and the row is active with the notice cleared');

// ---------------------------------------------------------------------------
section('a rehearsal plane names its things test_');
harness_set_setting_mem('server_manager_smtp2go_sandbox_users', '1');
$key3 = make_machine_key($owner->key, 'Joinery services', 3);
$key3_id = (int)$key3['api_key']->key;
$host4 = 'sand-' . substr(md5(uniqid('', true)), 0, 6) . '.example.com';
JoineryServices::enrol($owner->key, $key3_id, 'mail', $host4, $client);
$srow = $track(ServiceTenant::forKey($key3_id, 'mail'));
JoineryServices::grant($srow, '2030-01-01', $client);
$reply(array(array('subaccount_id' => 'sub-sand'), array(), $domain_entry('mail.' . $host4, false),
	array('username' => 'x'), $domain_entry('mail.' . $host4, false)));
JoineryServices::enrol($owner->key, $key3_id, 'mail', $host4, $client);
$calls = $drain();
check(strpos((string)($calls[0]['body']['subaccount_name'] ?? ''), 'test_') === 0, 'the subaccount label starts test_');
check(strpos((string)($calls[3]['body']['username'] ?? ''), 'test_') === 0 && ($calls[3]['body']['status'] ?? '') === 'sandbox',
	'the SMTP user starts test_ and is minted in the sandbox');
harness_set_setting_mem('server_manager_smtp2go_sandbox_users', '');

// ---------------------------------------------------------------------------
section('the actions run as the key\'s user');
$saved_session = $_SESSION ?? array();
$_SESSION = array('loggedin' => 1, 'usr_user_id' => (int)$owner->key, 'permission' => 0, 'api_key_id' => $key_id);
try {
	$r = harness_call_logic('plugins/server_manager/logic/services_status_logic.php', 'services_status_logic', array('host' => $host2));
	check(!$r->error && isset($r->data['services']['mail']) && isset($r->data['services']['shelf']),
		'services_status answers both rows for the key');
	$r = harness_call_logic('plugins/server_manager/logic/services_enroll_logic.php', 'services_enroll_logic',
		array('service' => 'bogus', 'host' => $host2));
	check($r->error !== '' && $r->error !== null, 'services_enroll refuses an unknown service', (string)$r->error);
	$r = harness_call_logic('plugins/server_manager/logic/services_release_logic.php', 'services_release_logic',
		array('service' => 'shelf'));
	check(!$r->error && $r->data['released'] === true && (string)ServiceTenant::forKey($key_id, 'shelf')->get('svt_state') === 'released',
		'services_release releases backup storage over the action');
	$_SESSION['api_key_id'] = null;
	$r = harness_call_logic('plugins/server_manager/logic/services_status_logic.php', 'services_status_logic', array());
	check($r->error !== '' && $r->error !== null, 'without a connected key the status action refuses');
	$r = harness_call_logic('plugins/server_manager/logic/services_enroll_logic.php', 'services_enroll_logic',
		array('service' => 'mail', 'host' => $host2));
	check($r->error !== '' && $r->error !== null, 'without a connected key the enrol action refuses');
	$descr = services_enroll_logic_descriptor();
	check($descr['requires_session'] === true && $descr['mutates'] === true
		&& services_status_logic_descriptor()['mutates'] === false, 'the descriptors expose the actions');
} finally {
	$_SESSION = $saved_session;
}

harness_finish();
