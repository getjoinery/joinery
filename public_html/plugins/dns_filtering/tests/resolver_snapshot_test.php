<?php
/**
 * resolver_snapshot and the DNS servers' keys — functional test suite
 *
 * The DNS servers read this site through one action,
 * dns_filtering/resolver_snapshot, with keys the DNS server access panel
 * mints (DnsResolverAccess). This suite proves:
 *
 *   - the answer's shape, and that it selects exactly the rows the resolver's
 *     database queries select: deleted, inactive and UID-less devices, deleted
 *     and inactive blocks, inactive domain rules, and the rules of a block
 *     that is not selected are all left out;
 *   - the version: the same rows give the same version whatever the time,
 *     a changed rule changes it, and a caller holding the current version is
 *     told `unchanged`;
 *   - over HTTP, only a machine key scoped to the action gets an answer: an
 *     unscoped key and a key scoped elsewhere are refused 403, and a key whose
 *     owner is deleted no longer authenticates (browser sessions are refused
 *     by requires_scoped_key, pinned in tests/unit/api_scoped_key_test.php);
 *   - issuing: the key is read-only, scoped, restricted to the server's IPv4
 *     address, owned by the admin who issued it, and issuing again replaces it;
 *   - that admin cannot be deleted, soft or permanently, while the key is live,
 *     and can be once another admin has re-issued it.
 *
 * USAGE (CLI only):
 *   php plugins/dns_filtering/tests/resolver_snapshot_test.php [base_url] [origin_ip]
 *
 * Creates its own users, devices, blocks and keys and removes them afterwards.
 * The issuing section runs only where no DNS server key exists yet, so it
 * never replaces a real one.
 *
 * @version 1.1 - keys belong to the issuing admin; the service account is gone
 * @version 1.0
 */

/** @joinery-test
 * name: dns_resolver_snapshot
 * tier: db
 * env: dev-only
 * needs: []
 */
require_once(__DIR__ . '/../../../tests/functional/api/api_test_harness.php');
api_test_boot($argv);
require_once(PathHelper::getIncludePath('plugins/dns_filtering/logic/resolver_snapshot_logic.php'));

/** Save a row of a plugin model with the given fields and return it. */
function snapshot_fixture($class, array $fields) {
	$row = new $class(NULL);
	foreach ($fields as $name => $value) {
		$row->set($name, $value);
	}
	$row->save();
	$row->load();
	return $row;
}

/** This suite's devices and blocks out of a snapshot answer, by id. */
function snapshot_mine(array $data, array $device_ids, array $block_ids) {
	$devices = array();
	foreach ($data['devices'] as $d) {
		if (in_array($d['id'], $device_ids, true)) $devices[$d['id']] = $d;
	}
	$blocks = array();
	foreach ($data['blocks'] as $b) {
		if (in_array($b['id'], $block_ids, true)) $blocks[$b['id']] = $b;
	}
	return array($devices, $blocks);
}

$settings = Globalvars::get_instance();

try {
	$suffix = strtoupper(LibraryFunctions::random_string(6));
	$uid = function ($tag) use ($suffix) { return strtolower('t' . $suffix . $tag . bin2hex(random_bytes(6))); };

	section('Setup');
	$owner = make_user($suffix . 'DNS');
	$owner_id = (int)$owner->key;

	$dev_on = snapshot_fixture('SdDevice', array('sdd_usr_user_id' => $owner->key, 'sdd_device_name' => 'on',
		'sdd_is_active' => true, 'sdd_resolver_uid' => $uid('a'), 'sdd_timezone' => 'America/Chicago', 'sdd_log_queries' => true));
	$dev_no_tz = snapshot_fixture('SdDevice', array('sdd_usr_user_id' => $owner->key, 'sdd_device_name' => 'notz',
		'sdd_is_active' => true, 'sdd_resolver_uid' => $uid('b')));
	$dev_off = snapshot_fixture('SdDevice', array('sdd_usr_user_id' => $owner->key, 'sdd_device_name' => 'off',
		'sdd_is_active' => false, 'sdd_resolver_uid' => $uid('c')));
	$dev_gone = snapshot_fixture('SdDevice', array('sdd_usr_user_id' => $owner->key, 'sdd_device_name' => 'gone',
		'sdd_is_active' => true, 'sdd_resolver_uid' => $uid('d')));
	$dev_gone->soft_delete();
	$dev_no_uid = snapshot_fixture('SdDevice', array('sdd_usr_user_id' => $owner->key, 'sdd_device_name' => 'nouid',
		'sdd_is_active' => true, 'sdd_resolver_uid' => ''));
	$device_ids = array_map(fn($d) => (int)$d->key, array($dev_on, $dev_no_tz, $dev_off, $dev_gone, $dev_no_uid));

	$blk_always = snapshot_fixture('SdScheduledBlock', array('sdb_sdd_device_id' => $dev_on->key, 'sdb_name' => 'Always',
		'sdb_is_always_on' => true, 'sdb_is_active' => true));
	$blk_sched = snapshot_fixture('SdScheduledBlock', array('sdb_sdd_device_id' => $dev_on->key, 'sdb_name' => 'Evenings',
		'sdb_is_always_on' => false, 'sdb_is_active' => true, 'sdb_schedule_start' => '18:00', 'sdb_schedule_end' => '22:00',
		'sdb_schedule_days' => '["mon","tue"]', 'sdb_schedule_timezone' => 'Europe/Paris'));
	$blk_off = snapshot_fixture('SdScheduledBlock', array('sdb_sdd_device_id' => $dev_on->key, 'sdb_name' => 'Off',
		'sdb_is_active' => false));
	$blk_gone = snapshot_fixture('SdScheduledBlock', array('sdb_sdd_device_id' => $dev_on->key, 'sdb_name' => 'Gone',
		'sdb_is_active' => true));
	$blk_gone->soft_delete();
	$block_ids = array_map(fn($b) => (int)$b->key, array($blk_always, $blk_sched, $blk_off, $blk_gone));

	snapshot_fixture('SdScheduledBlockFilter', array('sbf_sdb_scheduled_block_id' => $blk_always->key, 'sbf_filter_key' => 'porn', 'sbf_action' => 0));
	snapshot_fixture('SdScheduledBlockFilter', array('sbf_sdb_scheduled_block_id' => $blk_always->key, 'sbf_filter_key' => 'ads', 'sbf_action' => 1));
	snapshot_fixture('SdScheduledBlockService', array('sbs_sdb_scheduled_block_id' => $blk_always->key, 'sbs_service_key' => 'tiktok', 'sbs_action' => 0));
	snapshot_fixture('SdScheduledBlockRule', array('sbr_sdb_scheduled_block_id' => $blk_always->key, 'sbr_hostname' => 'Example.COM', 'sbr_is_active' => 1, 'sbr_action' => 0));
	snapshot_fixture('SdScheduledBlockRule', array('sbr_sdb_scheduled_block_id' => $blk_always->key, 'sbr_hostname' => 'paused.example', 'sbr_is_active' => 0, 'sbr_action' => 0));
	snapshot_fixture('SdScheduledBlockFilter', array('sbf_sdb_scheduled_block_id' => $blk_off->key, 'sbf_filter_key' => 'gambling', 'sbf_action' => 0));

	// ------------------------------------------------------------------
	section('The answer selects exactly the resolver\'s rows');
	$r = resolver_snapshot_logic(array());
	check(!$r->error, 'The action answers', (string)$r->error);
	$data = $r->data;
	foreach (array('version', 'generated_at', 'devices', 'blocks', 'blocklist_sources') as $field) {
		check(array_key_exists($field, $data), "The answer carries $field");
	}
	check(preg_match('/^[0-9a-f]{64}$/', $data['version']) === 1, 'The version is a sha256');
	list($devices, $blocks) = snapshot_mine($data, $device_ids, $block_ids);

	check(array_keys($devices) === array((int)$dev_on->key, (int)$dev_no_tz->key),
		'Only the active, undeleted devices with a UID are listed', json_encode(array_keys($devices)));
	check($devices[(int)$dev_on->key] === array('id' => (int)$dev_on->key, 'uid' => $dev_on->get('sdd_resolver_uid'),
		'timezone' => 'America/Chicago', 'log_queries' => true), 'A device carries id, uid, timezone and log_queries',
		json_encode($devices[(int)$dev_on->key]));
	check($devices[(int)$dev_no_tz->key]['timezone'] === 'UTC' && $devices[(int)$dev_no_tz->key]['log_queries'] === false,
		'A device with no timezone reads UTC, and log_queries defaults to false');

	check(array_keys($blocks) === array((int)$blk_always->key, (int)$blk_sched->key),
		'Only the active, undeleted blocks are listed', json_encode(array_keys($blocks)));
	$always = $blocks[(int)$blk_always->key];
	check($always['device_id'] === (int)$dev_on->key && $always['always_on'] === true && $always['name'] === 'Always',
		'A block carries its device, name and always_on');
	check($always['filters'] === array(array('key' => 'ads', 'action' => 1), array('key' => 'porn', 'action' => 0)),
		'Filter rules come sorted, with their actions', json_encode($always['filters']));
	check($always['services'] === array(array('key' => 'tiktok', 'action' => 0)), 'Service rules are carried');
	check($always['domains'] === array(array('key' => 'Example.COM', 'action' => 0)),
		'Only active domain rules are carried, as stored', json_encode($always['domains']));
	$sched = $blocks[(int)$blk_sched->key];
	check($sched['start'] === '18:00' && $sched['end'] === '22:00' && $sched['days'] === array('mon', 'tue')
		&& $sched['timezone'] === 'Europe/Paris', 'A scheduled block carries its schedule, days as a list', json_encode($sched));
	check($always['start'] === '' && $always['days'] === array() && $always['timezone'] === '',
		'An always-on block has an empty schedule');
	$off_listed = false;
	foreach ($data['blocks'] as $b) {
		foreach ($b['filters'] as $f) {
			if ($f['key'] === 'gambling' && $b['id'] === (int)$blk_off->key) $off_listed = true;
		}
	}
	check(!$off_listed, 'An inactive block\'s rules are not carried');

	check(isset($data['blocklist_sources']['categories']['cryptominers'])
		&& is_array($data['blocklist_sources']['skip_domains']), 'The blocklist sources are carried');

	// ------------------------------------------------------------------
	section('The version');
	sleep(1);
	$again = resolver_snapshot_logic(array())->data;
	check($again['version'] === $data['version'], 'The same rows give the same version a second later');
	$r = resolver_snapshot_logic(array('if_version' => $data['version']));
	check($r->data === array('version' => $data['version'], 'unchanged' => true),
		'A caller holding the current version is told unchanged', json_encode($r->data));
	$r = resolver_snapshot_logic(array('if_version' => str_repeat('0', 64)));
	check(isset($r->data['devices']), 'A caller holding another version gets the whole answer');
	snapshot_fixture('SdScheduledBlockRule', array('sbr_sdb_scheduled_block_id' => $blk_always->key, 'sbr_hostname' => 'new.example', 'sbr_is_active' => 1, 'sbr_action' => 1));
	check(resolver_snapshot_logic(array())->data['version'] !== $data['version'], 'A new rule changes the version');

	// ------------------------------------------------------------------
	section('Over HTTP, only a key scoped to the action gets an answer');
	$path = '/api/v1/action/dns_filtering/resolver_snapshot';
	$scoped = make_machine_key($owner->key, 'snap-' . $suffix, 1);
	$scoped['api_key']->set('apk_scope', DnsResolverAccess::ACTION);
	$scoped['api_key']->save();
	$h = key_headers($scoped['api_key']->get('apk_public_key'), $scoped['secret_key']);

	$r = api_request('POST', $path, $h, array());
	check($r['status'] === 200 && isset($r['json']['data']['version'], $r['json']['data']['devices']),
		'The scoped key gets the snapshot', $r['status'] . ' ' . substr($r['raw'], 0, 200));
	$version = $r['json']['data']['version'] ?? '';
	$r = api_request('POST', $path, $h, array('if_version' => $version));
	check($r['status'] === 200 && ($r['json']['data'] ?? null) === array('version' => $version, 'unchanged' => true),
		'Sending the version it holds gets unchanged', substr($r['raw'], 0, 200));

	$plain = make_machine_key($owner->key, 'plainsnap-' . $suffix, 4);
	$r = api_request('POST', $path, key_headers($plain['api_key']->get('apk_public_key'), $plain['secret_key']), array());
	check($r['status'] === 403, 'An unscoped key is refused 403, even with full permission', $r['raw']);

	$elsewhere = make_machine_key($owner->key, 'elsesnap-' . $suffix, 1);
	$elsewhere['api_key']->set('apk_scope', 'notification_unread_count');
	$elsewhere['api_key']->save();
	$r = api_request('POST', $path, key_headers($elsewhere['api_key']->get('apk_public_key'), $elsewhere['secret_key']), array());
	check($r['status'] === 403, 'A key scoped to another action is refused 403', $r['raw']);

	// The owner is soft-deleted beneath the model (User refuses while the key
	// is live, which is the point of the guard tested below).
	$db = DbConnector::get_instance()->get_db_link();
	$db->prepare("UPDATE usr_users SET usr_delete_time = now() WHERE usr_user_id = ?")->execute(array($owner->key));
	$r = api_request('POST', $path, $h, array());
	check($r['status'] >= 400 && $r['status'] < 500 && stripos($r['raw'], 'deleted') !== false,
		'A key whose owner is deleted no longer authenticates', $r['status'] . ' ' . $r['raw']);
	$db->prepare("UPDATE usr_users SET usr_delete_time = NULL WHERE usr_user_id = ?")->execute(array($owner->key));

	// ------------------------------------------------------------------
	section('Issuing a key');
	$existing = trim((string)get_setting_raw('dns_filtering_resolver_key_primary'))
		. trim((string)get_setting_raw('dns_filtering_resolver_key_secondary'));
	if ($existing !== '') {
		echo "  SKIP: this site already has DNS server keys; issuing here would replace them\n";
	} else {
		harness_defer(function () {
			foreach (array('dns_filtering_resolver_key_primary', 'dns_filtering_resolver_key_secondary') as $name) {
				Setting::put($name, '');
			}
		});
		// Registered before their keys, so teardown (LIFO) deletes the keys first.
		$issuer = make_user($suffix . 'ISS', 10);
		$other_admin = make_user($suffix . 'IS2', 10);

		harness_set_setting_mem('dns_filtering_dns_server_ip', '');
		$refused = false;
		try {
			DnsResolverAccess::issueKey('primary', $issuer);
		} catch (SystemDisplayableError $e) {
			$refused = true;
		}
		check($refused, 'A server with no IPv4 address set gets no key');

		harness_set_setting_mem('dns_filtering_dns_server_ip', '192.0.2.10');
		$issued = DnsResolverAccess::issueKey('primary', $issuer);
		$key = $issued['api_key'];
		harness_register_key_id($key->key);
		check((int)$key->get('apk_usr_user_id') === (int)$issuer->key, 'The key belongs to the admin who issued it');
		check($key->get('apk_type') === ApiKey::TYPE_MACHINE && (int)$key->get('apk_permission') === 1
			&& $key->scope() === array(DnsResolverAccess::ACTION) && $key->get('apk_ip_restriction') === '192.0.2.10',
			'The key is a read-only machine key, scoped to the action, restricted to the server\'s address');
		check($key->check_secret_key($issued['secret_key']), 'The returned secret is the key\'s');
		check($key->get('apk_secret_key') !== $issued['secret_key'], 'The secret is stored only as a hash');
		check(DnsResolverAccess::slotKey('primary') && (int)DnsResolverAccess::slotKey('primary')->key === (int)$key->key,
			'The slot records the key');

		$state = DnsResolverAccess::panelState();
		check($state[0]['slot'] === 'primary' && $state[0]['drift'] === false, 'The panel shows the key, with no drift');
		harness_set_setting_mem('dns_filtering_dns_server_ip', '192.0.2.11');
		check(DnsResolverAccess::panelState()[0]['drift'] === true, 'The panel flags a key whose address no longer matches the setting');
		harness_set_setting_mem('dns_filtering_dns_server_ip', '192.0.2.10');

		section('The issuing admin cannot be deleted while their key is live');
		foreach (array('soft_delete', 'permanent_delete') as $method) {
			$threw = false;
			try {
				$fresh = new User($issuer->key, TRUE);
				$fresh->$method();
			} catch (SystemDisplayableError $e) {
				$threw = strpos($e->getMessage(), DnsResolverAccess::ACTION) !== false;
			}
			check($threw, "$method is refused, naming the key's scope");
			check((new User($issuer->key, TRUE))->get('usr_delete_time') === null, "After the refused $method the account is intact");
		}

		$second = DnsResolverAccess::issueKey('primary', $other_admin);
		harness_register_key_id($second['api_key']->key);
		check((new ApiKey($key->key, TRUE))->get('apk_delete_time') !== null, 'Issuing again revokes the old key');
		check((int)$second['api_key']->get('apk_usr_user_id') === (int)$other_admin->key, 'A key re-issued by another admin belongs to them');
		$fresh = new User($issuer->key, TRUE);
		$fresh->soft_delete();
		check((new User($issuer->key, TRUE))->get('usr_delete_time') !== null, 'Once their key is replaced, the first admin can be deleted');

		DnsResolverAccess::revokeKey('primary');
		check(DnsResolverAccess::slotKey('primary') === null, 'Revoke ends the slot\'s key');
	}

} catch (\Throwable $e) {
	check(false, 'unhandled exception mid-suite', $e->getMessage());
	echo "\nEXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
	section('Cleanup');
	harness_teardown_data();
	// Deleting a device records its PIN in the user's device backups; deleting
	// the user must take those with it (SystemBase::DELETION_RULE_ORDER).
	if (isset($owner) && $owner->key === NULL) {
		$q = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT COUNT(*) FROM sbk_device_backups WHERE sbk_usr_user_id = ?');
		$q->execute(array($owner_id));
		check((int)$q->fetchColumn() === 0, 'no device backup outlives the fixture user');
	}
}

harness_finish();
