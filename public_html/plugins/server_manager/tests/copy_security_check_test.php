<?php
/** @joinery-test
 * name: copy_security_check
 * tier: db
 * env: any
 * needs: []
 */

/**
 * copy_security_check (specs/site_copy_final_test.md, the rig): the security
 * a site's machine promises, read on the management node from its reports.
 *
 *  - a container on an engine host that holds every promise passes every row;
 *  - each promise broken fails its own row, with its evidence;
 *  - an own server: the engine, filter and container rows do not apply;
 *  - a copy weaker than its source fails the run (weaker()), and a row that
 *    does not apply to the copy's kind of machine is not compared.
 *
 * @version 1.1 - quiet on a server of its own is read from PHP-FPM's answer (B44)
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$tag = bin2hex(random_bytes(3));
$filter = CopySecurityCheck::release_filter_sha();
$caps = CopySecurityCheck::release_caps();
check($filter !== '' && count($caps) === 6, 'this release names its site filter and the six capabilities a site adds', $filter . ' ' . implode(',', $caps));

$mk_node = function (string $suffix, array $set) use ($tag) {
	$n = new ManagedNode(NULL);
	$n->set('mgn_name', 'HarnessTest CSC ' . $suffix);
	$n->set('mgn_slug', 'harnesscsc-' . $suffix . '-' . $tag);
	$n->set('mgn_host', '192.0.2.' . (180 + crc32($suffix) % 40));
	$n->set('mgn_uptime_enabled', false);
	foreach ($set as $k => $v) {
		$n->set($k, $v);
	}
	$n->save();
	$n->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $n->key);
	return $n;
};
$host_report = function (array $over = array()) use ($filter, $caps) {
	return json_encode(array_replace_recursive(array(
		'hardening' => array('state' => 'ok', 'drift' => array()),
		'kernel_events_24h' => array('oom' => 0, 'enospc' => 0, 'io_error' => 0, 'kernel_warning' => 0),
		'site_engine' => 'podman',
		'site_security' => array('filter' => $filter, 'caps' => $caps),
		'site_refresh' => array('scheduled' => true, 'check' => 'ok', 'holds' => array()),
		'outbound_limits' => array('state' => 'on'),
		'host_restrictions' => array('unsigned_packages' => 'refuse'),
		'data_root_encryption' => array('backing' => 'luks-file', 'open' => true),
		'containers' => array(array('name' => 'cscsite', 'answers' => 'yes',
			'code_check' => array('state' => 'ok', 'at' => gmdate('Y-m-d\TH:i:s\Z'), 'failures' => array()))),
	), $over));
};
$inventory = array('cscsite' => array('name' => 'cscsite', 'security' => array('filter' => $filter, 'caps' => $caps, 'drop_all' => true, 'setuid' => 1)));
CopySecurityCheck::$inventory_reader = function ($host) use (&$inventory) { return array('containers' => array_values($inventory)); };
harness_defer(function () { CopySecurityCheck::$inventory_reader = null; });

$hnode = $mk_node('host', array('mgn_last_host_report' => $host_report()));
$p = new CustomerCloudProvision(NULL);
foreach (array('cvp_origin' => 'admin', 'cvp_usr_user_id' => 1, 'cvp_domain' => 'csc-' . $tag . '.example.org', 'cvp_slug' => 'csc-' . $tag,
		'cvp_status' => 'done', 'cvp_docker_mode' => 'bare-metal', 'cvp_provider' => 'linode', 'cvp_hosting_mode' => 'operator',
		'cvp_instance_id' => 'csc' . $tag, 'cvp_provider_disk_encryption' => 'disabled', 'cvp_mgn_managed_node_id' => (int)$hnode->key) as $k => $v) {
	$p->set($k, $v);
}
$p->save();
harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $p->key);
$esc = new DataRootEscrow(NULL);
foreach (array('dre_mgn_managed_node_id' => (int)$hnode->key, 'dre_status' => 'enrolled') as $k => $v) {
	$esc->set($k, $v);
}
try {
	$esc->save();
	harness_register_row('dre_data_root_escrows', 'dre_data_root_escrow_id', $esc->key);
} catch (Exception $e) {
	$esc = null;
}
$mh = new ManagedHost(NULL);
foreach (array('mgh_slug' => 'harnesscsc-h-' . $tag, 'mgh_name' => 'HarnessTest CSC host', 'mgh_host' => (string)$hnode->get('mgn_host'),
		'mgh_mgn_managed_node_id' => (int)$hnode->key) as $k => $v) {
	$mh->set($k, $v);
}
$mh->save();
harness_register_row('mgh_managed_hosts', 'mgh_managed_host_id', $mh->key);
$site = $mk_node('site', array('mgn_container_name' => 'cscsite', 'mgn_mgh_managed_host_id' => (int)$mh->key,
	'mgn_last_backup_outcome' => 'success', 'mgn_last_backup_time' => gmdate('Y-m-d H:i:s')));

// ---------------------------------------------------------------------------
section('A container that holds every promise');

$rows = CopySecurityCheck::rows($site);
$failing = array();
foreach ($rows as $k => $r) {
	if ($r['applies'] && $r['pass'] !== true && !($k === 'layers' && !$esc)) {
		$failing[] = $k . ': ' . $r['evidence'];
	}
}
check(!$failing, 'every row passes', implode(' | ', $failing));

// ---------------------------------------------------------------------------
section('Each promise broken fails its own row');

$broken = array(
	'kernel_settings' => array('hardening' => array('state' => 'drift', 'drift' => array(array('key' => 'kernel.yama.ptrace_scope', 'live' => '0', 'want' => '1')))),
	'kernel_warnings' => array('kernel_events_24h' => array('kernel_warning' => 2)),
	'site_engine'     => array('site_engine' => 'docker'),
	'site_filter'     => array('site_security' => array('filter' => str_repeat('0', 64))),
	'site_refresh'    => array('site_refresh' => array('holds' => array(array('site' => 'cscsite')))),
	'outbound_limits' => array('outbound_limits' => array('state' => 'off')),
	'code_check'      => array('containers' => array(array('name' => 'cscsite', 'code_check' => array('state' => 'failed')))),
	'quiet'           => array('containers' => array(array('name' => 'cscsite', 'answers' => 'quiet'))),
);
foreach ($broken as $key => $over) {
	$hnode->set('mgn_last_host_report', $host_report($over));
	$hnode->save();
	$r = CopySecurityCheck::rows($site)[$key];
	check($r['pass'] === false, 'broken: ' . $key, $r['evidence']);
}
$hnode->set('mgn_last_host_report', $host_report());
$hnode->save();
foreach (array(
	'site_filter_label' => array('filter' => str_repeat('1', 64)),
	'capabilities'      => array('caps' => array_merge($caps, array('NET_ADMIN'))),
	'setuid'            => array('setuid' => 3),
) as $key => $over) {
	$inventory['cscsite']['security'] = array_merge(array('filter' => $filter, 'caps' => $caps, 'drop_all' => true, 'setuid' => 1), $over);
	$r = CopySecurityCheck::rows($site)[$key];
	check($r['pass'] === false, 'broken: ' . $key, $r['evidence']);
}
$inventory['cscsite']['security'] = array('filter' => $filter, 'caps' => $caps, 'drop_all' => false, 'setuid' => 1);
check(CopySecurityCheck::rows($site)['capabilities']['pass'] === false, 'broken: a site that does not drop every other capability');
$inventory['cscsite']['security'] = array('filter' => $filter, 'caps' => $caps, 'drop_all' => true, 'setuid' => 1);
check(CopySecurityCheck::rows($site, true)['quiet']['pass'] === false, 'a site expected quiet that answers: fails');

// ---------------------------------------------------------------------------
section('An own server, and the comparison');

$own = $mk_node('own', array('mgn_last_host_report' => $host_report(array('site_engine' => 'none')), 'mgn_last_backup_outcome' => 'success'));
$orows = CopySecurityCheck::rows($own);
check(!$orows['site_engine']['applies'] && !$orows['capabilities']['applies'] && !$orows['code_check']['applies']
	&& $orows['kernel_settings']['applies'] && $orows['backup']['applies'], 'on a server of its own the container rows do not apply');
$baseline = CopySecurityCheck::rows($site);
$hnode->set('mgn_last_host_report', $host_report(array('outbound_limits' => array('state' => 'off'))));
$hnode->save();
$weaker = CopySecurityCheck::weaker($baseline, CopySecurityCheck::rows($site));
check(array_keys($weaker) === array('outbound_limits'), 'a copy weaker than its source is named, row by row', json_encode(array_keys($weaker)));
check(!isset(CopySecurityCheck::weaker($baseline, $orows)['site_engine']), 'a row that does not apply to the copy\'s machine is not compared');
// Quiet on a server of its own: Apache answers the quiet state's 503 as well,
// so only PHP-FPM tells a quiet site from a live one (B44).
$own->set('mgn_last_host_report', $host_report(array('site_engine' => 'none', 'answers' => array('apache2' => 'yes', 'php-fpm' => 'quiet', 'postgresql' => 'yes'))));
$own->save();
check(CopySecurityCheck::rows($own, true)['quiet']['pass'] === true && CopySecurityCheck::rows($own)['quiet']['pass'] === false,
	'a dormant copy on its own server reads quiet, though Apache answers', CopySecurityCheck::rows($own, true)['quiet']['evidence']);
$own->set('mgn_last_host_report', $host_report(array('site_engine' => 'none', 'answers' => array('apache2' => 'yes', 'php-fpm' => 'yes', 'postgresql' => 'yes'))));
$own->save();
check(CopySecurityCheck::rows($own)['quiet']['pass'] === true && CopySecurityCheck::rows($own, true)['quiet']['pass'] === false,
	'and a live one reads live');
$lines = CopySecurityCheck::lines($orows);
check(strpos($lines[0], 'PASS') === 0 && (bool)array_filter($lines, function ($l) { return strpos($l, 'N/A') === 0; }),
	'each row is one line: PASS, FAIL, UNKNOWN or N/A, with its evidence');

harness_finish();
