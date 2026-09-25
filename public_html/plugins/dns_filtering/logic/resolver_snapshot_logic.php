<?php
/**
 * resolver_snapshot - everything a DNS server needs to filter this site's
 * devices, in one answer.
 *
 * Exposed as POST /api/v1/action/dns_filtering/resolver_snapshot. Only a
 * machine key scoped to this action can call it: the DNS server access panel
 * on the plugin's settings page mints one per server (DnsResolverAccess).
 * Each DNS server polls it every minute, sending the version it holds.
 *
 * The answer:
 *   version           sha256 of the canonical JSON of devices, blocks and
 *                     blocklist_sources — never of generated_at, or no answer
 *                     would ever match if_version
 *   generated_at      UTC 'Y-m-d H:i:s'
 *   devices           [{id, uid, timezone, log_queries}] — active, undeleted,
 *                     with a resolver UID
 *   blocks            [{id, device_id, name, always_on, start, end, days,
 *                     timezone, filters, services, domains}] — active and
 *                     undeleted; each rule list is [{key, action}], domain
 *                     rules active only
 *   blocklist_sources {categories: {key: [url, ...]}, skip_domains: [...]}
 * When if_version equals the version, the answer is {version, unchanged: true}.
 *
 * Device ids are this site's own; a DNS server reading several sites keys
 * devices by UID, which is unique across sites.
 *
 * @version 1.0
 */

function resolver_snapshot_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	try {
		$sources = BlocklistSources::load();
	} catch (BlocklistSourcesException $e) {
		return LogicResult::error($e->getMessage());
	}

	$content = array(
		'devices'           => resolver_snapshot_devices(),
		'blocks'            => resolver_snapshot_blocks(),
		'blocklist_sources' => $sources,
	);
	$version = resolver_snapshot_version($content);

	$if_version = isset($input['if_version']) ? trim((string)$input['if_version']) : '';
	if ($if_version !== '' && hash_equals($version, $if_version)) {
		return LogicResult::render(array('version' => $version, 'unchanged' => true));
	}

	return LogicResult::render(array(
		'version'      => $version,
		'generated_at' => gmdate('Y-m-d H:i:s'),
	) + $content);
}

/**
 * The version of a snapshot's content: sha256 over its canonical JSON. The
 * content is built in a fixed key order with sorted lists, so the same rows
 * always hash the same.
 */
function resolver_snapshot_version(array $content): string {
	return hash('sha256', json_encode(array(
		'devices'           => $content['devices'],
		'blocks'            => $content['blocks'],
		'blocklist_sources' => $content['blocklist_sources'],
	), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/** Active, undeleted devices with a resolver UID, ordered by id. */
function resolver_snapshot_devices(): array {
	$devices = array();
	foreach (new MultiSdDevice(array('active' => true, 'deleted' => false)) as $device) {
		$uid = (string)$device->get('sdd_resolver_uid');
		if ($uid === '') {
			continue;
		}
		$timezone = (string)$device->get('sdd_timezone');
		$devices[] = array(
			'id'          => (int)$device->key,
			'uid'         => $uid,
			'timezone'    => $timezone !== '' ? $timezone : 'UTC',
			'log_queries' => (bool)$device->get('sdd_log_queries'),
		);
	}
	usort($devices, fn($a, $b) => $a['id'] <=> $b['id']);
	return $devices;
}

/**
 * Active, undeleted blocks, ordered by id, each carrying its filter, service
 * and (active) domain rules sorted by key then action. The rule tables are
 * read whole, one query each, and kept only for the blocks selected here —
 * the same rows a join on an active, undeleted block returns. Reading them
 * whole is fine at today's handful of devices, polled twice a minute; with
 * many, narrow each query to the selected blocks' ids in SQL.
 */
function resolver_snapshot_blocks(): array {
	$blocks = array();
	foreach (new MultiSdScheduledBlock(array('is_active' => true, 'deleted' => false)) as $block) {
		$days = json_decode((string)$block->get('sdb_schedule_days'), true);
		$blocks[(int)$block->key] = array(
			'id'        => (int)$block->key,
			'device_id' => (int)$block->get('sdb_sdd_device_id'),
			'name'      => (string)$block->get('sdb_name'),
			'always_on' => (bool)$block->get('sdb_is_always_on'),
			'start'     => (string)$block->get('sdb_schedule_start'),
			'end'       => (string)$block->get('sdb_schedule_end'),
			'days'      => is_array($days) ? array_values(array_map('strval', $days)) : array(),
			'timezone'  => (string)$block->get('sdb_schedule_timezone'),
			'filters'   => array(),
			'services'  => array(),
			'domains'   => array(),
		);
	}

	$rule_sets = array(
		'filters'  => array(new MultiSdScheduledBlockFilter(array()), 'sbf_sdb_scheduled_block_id', 'sbf_filter_key', 'sbf_action'),
		'services' => array(new MultiSdScheduledBlockService(array()), 'sbs_sdb_scheduled_block_id', 'sbs_service_key', 'sbs_action'),
		'domains'  => array(new MultiSdScheduledBlockRule(array('active' => true)), 'sbr_sdb_scheduled_block_id', 'sbr_hostname', 'sbr_action'),
	);
	foreach ($rule_sets as $list => list($rules, $block_col, $key_col, $action_col)) {
		foreach ($rules as $rule) {
			$block_id = (int)$rule->get($block_col);
			if (!isset($blocks[$block_id])) {
				continue;
			}
			$blocks[$block_id][$list][] = array(
				'key'    => (string)$rule->get($key_col),
				'action' => (int)$rule->get($action_col),
			);
		}
	}

	foreach ($blocks as &$block) {
		foreach (array('filters', 'services', 'domains') as $list) {
			usort($block[$list], fn($a, $b) => array($a['key'], $a['action']) <=> array($b['key'], $b['action']));
		}
	}
	unset($block);

	ksort($blocks);
	return array_values($blocks);
}

function resolver_snapshot_logic_descriptor(): array {
	return array(
		'description'      => 'Everything a DNS server needs to filter this site\'s devices: devices, blocks and their rules, and the blocklist sources. For the DNS servers\' scoped keys only.',
		'requires_session' => true,
		'mutates'          => false,
		'auth'             => array(
			'capability'           => 'read',
			'requires_machine_key' => true,
			'requires_scoped_key'  => true,
		),
		'input'            => array(
			'if_version' => array('type' => 'string', 'required' => false, 'label' => 'The version the caller holds'),
		),
	);
}

?>
