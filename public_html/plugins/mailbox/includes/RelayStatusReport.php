<?php
/**
 * RelayStatusReport - this site's view of its mail relay, for the relay_status
 * observe word (agent 1.101.0): what the relay last said, whether this site's
 * map reached it, whether pickup is running, which transport key mail is sealed
 * to, and whether mail is arriving. Read from the relay record and the inbound
 * table; the relay itself is never asked, and nothing is written.
 *
 * Versions, times, counts, booleans and short state words only: never an
 * address, a domain, a subject, a key, a secret, a host name or the relay's
 * free-text detail (rule 8 of specs/agent_recipes_and_vocabulary.md).
 *
 * The map is deliberately NOT rebuilt to compare it with what was pushed:
 * building it can mint a new transport key (MailboxRelay::ensureTransportKeypair),
 * and an observe word writes nothing.
 *
 * @version 1.2 - transport_key: kind is xwing or none; no retired_kept or replaced_time
 * @version 1.1 - the map's last push attempt: when, its outcome and its reason code (RelayMapSync 2.6)
 * @version 1.0
 */

class RelayStatusReport {

	/** The report, as one array. ['relay' => 'none'] on a site with no enabled relay. */
	public static function status(): array {
		$relays = new MultiMailboxRelay(array('enabled' => true, 'deleted' => false));
		$enabled = count($relays);
		$relay = MailboxRelay::active();
		if ($relay === null) {
			return array('relay' => 'none', 'relays_enabled' => $enabled, 'inbound' => self::inbound());
		}

		$running = $relay->provisionedVersion();
		$health = $relay->lastHealth();
		$probe = InboundEmailHealth::originProbeVerdict();
		$public = (string)$relay->get('mrl_transport_public_key');

		return array(
			'relay'          => 'active',
			'relays_enabled' => $enabled,
			'relay_id'       => intval($relay->key),
			'route'          => $relay->get('mrl_is_hosted') ? 'hosted' : ((string)$relay->get('mrl_cloud_instance_id') !== '' ? 'cloud' : 'hand'),
			'version'        => array(
				'running'            => self::version($running),
				'shipped'            => self::version(RelayVersion::shipped()),
				'standing'           => RelayVersion::compare($running),
				'seals_for_browsers' => RelayVersion::sealsForBrowsers($relay),
				'seals_post_quantum' => RelayVersion::sealsPostQuantum($running),
			),
			'health'         => array(
				'state'        => self::word($health['state'] ?? 'never'),
				'reason'       => self::word($health['reason'] ?? ''),
				'failure'      => self::word((string)$relay->get('mrl_last_health_failure')),
				'queue'        => isset($health['queue']) && is_numeric($health['queue']) ? intval($health['queue']) : null,
				'sole_tenant'  => isset($health['sole']) && is_bool($health['sole']) ? $health['sole'] : null,
				'checked_time' => self::time($relay->get('mrl_last_health_time')),
			),
			'last_test'      => array(
				'tested_time' => self::time($relay->get('mrl_last_test_time')),
				'probe_state' => self::word($probe['state'] ?? ''),
				'probe_time'  => self::time($probe['checked_time'] ?? ''),
			),
			'map'            => array(
				'version'        => intval($relay->get('mrl_map_version')),
				'last_push_time' => self::time($relay->get('mrl_last_push_time')),
				// The last ATTEMPT, whatever came of it: why a map is not moving.
				'last_attempt_time'   => self::time($relay->get('mrl_last_push_attempt_time')),
				'last_attempt_result' => self::word($relay->get('mrl_last_push_result')),
				'last_attempt_reason' => self::word($relay->get('mrl_last_push_reason')),
			),
			'pickup'         => array(
				'last_pull_time'    => self::time($relay->get('mrl_last_pull_time')),
				'last_drained_time' => self::time($relay->get('mrl_last_pull_drained_time')),
				'held'              => intval($relay->get('mrl_last_pull_held')),
				'alarm_time'        => self::time($relay->get('mrl_pickup_alarm_time')),
			),
			'transport_key'  => array(
				'kind'          => ($public === '') ? 'none' : 'xwing',
				'generation'    => intval($relay->get('mrl_transport_key_generation')),
			),
			'inbound'        => self::inbound(),
		);
	}

	/** Mail stored in the last hour and day, and the newest arrival. */
	private static function inbound(): array {
		$db = DbConnector::get_instance()->get_db_link();
		$row = $db->query("SELECT
				COUNT(*) FILTER (WHERE iem_received_time >= now() - interval '1 hour') AS last_hour,
				COUNT(*) AS last_day,
				MAX(iem_received_time) AS newest
			FROM iem_inbound_email_messages
			WHERE iem_received_time >= now() - interval '1 day'")->fetch(PDO::FETCH_ASSOC);
		$newest = $db->query('SELECT MAX(iem_received_time) FROM iem_inbound_email_messages')->fetchColumn();
		return array(
			'last_hour'   => intval($row['last_hour'] ?? 0),
			'last_day'    => intval($row['last_day'] ?? 0),
			'newest_time' => self::time($newest),
		);
	}

	/** A version string, or '' when it is not one. */
	private static function version($v): string {
		$v = trim((string)$v);
		return preg_match('/^\d+(\.\d+){0,3}$/', $v) ? $v : '';
	}

	/** A short state word ([a-z0-9_-], 40 characters), or ''. */
	private static function word($v): string {
		$v = strtolower(trim((string)$v));
		return preg_match('/^[a-z0-9_-]{1,40}$/', $v) ? $v : '';
	}

	/** A stored UTC time as 'Y-m-d H:i:s', or ''. */
	private static function time($v): string {
		$v = trim((string)$v);
		if ($v === '') {
			return '';
		}
		$ts = strtotime($v . ' UTC');
		return ($ts === false) ? '' : gmdate('Y-m-d H:i:s', $ts);
	}
}
