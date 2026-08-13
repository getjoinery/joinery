<?php
/**
 * ManagedNode - A remote Joinery server or container managed by the control plane.
 *
 * @version 1.5 - mgn_backup_shelf_checked_time / mgn_backup_shelf_newest_time: the bucket's own
 *                testimony about the fleet-backup shelf, so a node claiming success while nothing
 *                lands is catchable
 * @version 1.4 - mgn_allow_console: per-node opt-in for the node detail Console tab
 * @version 1.3.4
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class ManagedNodeException extends SystemBaseException {}

class ManagedNode extends SystemBase {
	public static $prefix = 'mgn';
	public static $tablename = 'mgn_managed_nodes';
	public static $pkey_column = 'mgn_id';

	public static $json_vars = array('mgn_last_status_data', 'mgn_backup_policy');

	protected static $foreign_key_actions = [
		'mgn_mgh_host_id' => ['action' => 'null'],
		'mgn_bkt_backup_target_id' => ['action' => 'null'],
	];

	public static $field_specifications = array(
		'mgn_id'                  => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'mgn_name'                => array('type'=>'varchar(100)', 'required'=>true, 'is_nullable'=>false),
		'mgn_slug'                => array('type'=>'varchar(50)', 'required'=>true, 'is_nullable'=>false, 'unique'=>true),
		'mgn_host'                => array('type'=>'varchar(255)', 'required'=>true, 'is_nullable'=>false),
		'mgn_ssh_user'            => array('type'=>'varchar(50)', 'is_nullable'=>false, 'default'=>'root'),
		'mgn_ssh_key_path'        => array('type'=>'varchar(500)'),
		'mgn_ssh_port'            => array('type'=>'int4', 'default'=>'22'),
		'mgn_container_name'      => array('type'=>'varchar(100)'),
		'mgn_container_user'      => array('type'=>'varchar(50)'),
		'mgn_web_root'            => array('type'=>'varchar(500)'),
		'mgn_site_url'            => array('type'=>'varchar(500)'),
		'mgn_health_check_url'    => array('type'=>'varchar(500)'),
		'mgn_joinery_version'     => array('type'=>'varchar(20)'),
		'mgn_last_status_check'   => array('type'=>'timestamp(6)'),
		'mgn_last_status_data'    => array('type'=>'jsonb'),
		'mgn_api_public_key'      => array('type'=>'varchar(255)'),
		'mgn_api_secret_key'      => array('type'=>'varchar(255)'),
		'mgn_tls_insecure'        => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		'mgn_bkt_backup_target_id' => array('type'=>'int8'),
		'mgn_delete_local_after_upload' => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		// Fingerprint of the backup recovery public key this node is holding, as
		// the status check last found it. Stored so the fleet view can show which
		// nodes have the control plane's key without reaching out to every node
		// on page load — and so a node holding a key the control plane did not
		// put there is visible rather than silently left behind.
		'mgn_backup_recovery_fpr' => array('type'=>'varchar(64)'),

		// This control plane's backup policy for this node — the manager profile.
		// A blob rather than a column each because it is read whole, written
		// whole, and every field of it is a preference: enabled, frequency, time
		// window, mode, retention count, full interval, target override.
		//
		// A node with no policy inherits the fleet default, which is ENABLED.
		// That default is what stops a node falling through unnoticed; there is
		// deliberately no detector for "nobody decided about this node", because
		// a node nobody decided about does not exist.
		'mgn_backup_policy'       => array('type'=>'jsonb'),

		// The last manager-profile run, denormalised for sorting and alerting.
		// The authoritative history lives on the node; this is the fleet's copy
		// of the one question a dashboard has to answer without visiting anyone.
		'mgn_last_backup_time'    => array('type'=>'timestamp(6)'),
		'mgn_last_backup_outcome' => array('type'=>'varchar(20)'),

		// The bucket's own testimony about this node's shelf: when this control
		// plane last listed it, and the newest object write it saw. Stamped by
		// the scheduler from the retention pass's listing — taken with this
		// control plane's credential, never the node's word. Comparing these
		// against the claimed last run is the only check that catches a node
		// reporting success while nothing actually lands.
		'mgn_backup_shelf_checked_time' => array('type'=>'timestamp(6)'),
		'mgn_backup_shelf_newest_time'  => array('type'=>'timestamp(6)'),
		// Compared against the newest escrow row to detect a manually regenerated
		// (un-escrowed) node key.
		'mgn_enabled'             => array('type'=>'bool', 'default'=>true, 'is_nullable'=>false),
		'mgn_skip_joinery_checks' => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		// Whether the node detail Console tab may run an ad-hoc command here.
		// Default off: the control plane holds SSH keys to every node, so being
		// reachable from a browser form is a decision made per node rather than
		// a property every node acquires the moment it is registered.
		'mgn_allow_console'       => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		'mgn_mgh_host_id'         => array('type'=>'int8'),
		'mgn_ssl_state'           => array('type'=>'varchar(20)'),
		'mgn_port'                => array('type'=>'int4'),
		'mgn_install_state'       => array('type'=>'varchar(20)'),
		'mgn_notes'               => array('type'=>'text'),
		'mgn_uptime_enabled'              => array('type'=>'bool', 'default'=>true, 'is_nullable'=>false),
		'mgn_uptime_check_type'           => array('type'=>'varchar(20)', 'default'=>'http_status', 'is_nullable'=>false),
		// Real cadence for uptime probing. RunNodeUptimeChecks fires every cron
		// pass as a floor, but only probes a node whose interval has elapsed —
		// the same task-floor/per-item pattern PollImapAccounts uses. Keeps
		// probe volume independent of how often cron ticks.
		'mgn_uptime_interval_seconds'     => array('type'=>'int4', 'default'=>'300', 'is_nullable'=>false),
		'mgn_uptime_last_check'           => array('type'=>'timestamp(6)'),
		// Last check that actually concluded up or down. A check that cannot
		// conclude (misconfigured, no target) advances mgn_uptime_last_check but
		// not this, so a node whose monitoring has silently stopped working is
		// detectable instead of looking like one that was simply never checked.
		'mgn_uptime_last_conclusive'      => array('type'=>'timestamp(6)'),
		'mgn_uptime_last_error'           => array('type'=>'varchar(255)'),
		// Port for the tcp_port check type — services with no web endpoint
		// (an inbound mail relay, for example) are proven alive by accepting a
		// TCP connection on the port they exist to serve.
		'mgn_uptime_tcp_port'             => array('type'=>'int4', 'default'=>'0', 'is_nullable'=>false),
		'mgn_uptime_last_status'          => array('type'=>'varchar(20)'),
		'mgn_uptime_consecutive_failures' => array('type'=>'int4', 'default'=>'0', 'is_nullable'=>false),
		'mgn_uptime_down_since'           => array('type'=>'timestamp(6)'),
		'mgn_cert_expiry_ts'              => array('type'=>'timestamp(6)'),
		'mgn_cert_alerted_ts'             => array('type'=>'timestamp(6)'),
		// Hardened ingest relay (specs/inbound_email_hardened_ingest_relay_executor.md
		// § Phase 6). A relay is a ManagedNode row so it gets the dashboard health
		// dot / heartbeat machinery; these columns carry the WireGuard peering the
		// main box dials out to. mgn_is_relay marks the node a relay (no Joinery app
		// runs on it, so the Joinery-app health checks are skipped for it).
		'mgn_is_relay'            => array('type'=>'bool', 'default'=>false, 'is_nullable'=>false),
		'mgn_wg_public_key'       => array('type'=>'varchar(255)'),
		'mgn_wg_endpoint'         => array('type'=>'varchar(255)'),
		'mgn_wg_ip'               => array('type'=>'varchar(64)'),
		'mgn_create_time'         => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'mgn_update_time'         => array('type'=>'timestamp(6)'),
		'mgn_delete_time'         => array('type'=>'timestamp(6)'),
	);

	function prepare() {
		// Normalize slug to lowercase alphanumeric + hyphens
		$slug = strtolower(trim($this->get('mgn_slug')));
		$slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
		$this->set('mgn_slug', $slug);

		if (empty($slug)) {
			throw new ManagedNodeException('Node slug is required.');
		}

		if (empty($this->get('mgn_name'))) {
			throw new ManagedNodeException('Node name is required.');
		}

		if (empty($this->get('mgn_host'))) {
			throw new ManagedNodeException('SSH host is required.');
		}

		// Check for duplicate slug
		$existing = new MultiManagedNode(array('slug' => $slug, 'deleted' => false));
		$existing->load();
		foreach ($existing as $ex) {
			if ($ex->key != $this->key) {
				throw new ManagedNodeException('A node with this slug already exists.');
			}
		}

		$this->set('mgn_update_time', gmdate('Y-m-d H:i:s'));
	}
}

class MultiManagedNode extends SystemMultiBase {
	protected static $model_class = 'ManagedNode';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['slug'])) {
			$filters['mgn_slug'] = [$this->options['slug'], PDO::PARAM_STR];
		}

		if (isset($this->options['host'])) {
			$filters['mgn_host'] = [$this->options['host'], PDO::PARAM_STR];
		}

		if (isset($this->options['host_id'])) {
			if ($this->options['host_id'] === null) {
				$filters['mgn_mgh_host_id'] = "IS NULL";
			} else {
				$filters['mgn_mgh_host_id'] = [$this->options['host_id'], PDO::PARAM_INT];
			}
		}

		if (isset($this->options['enabled'])) {
			$filters['mgn_enabled'] = $this->options['enabled'] ? "= true" : "= false";
		}


		// Use array_key_exists so null values (→ IS NULL) are handled correctly
		if (array_key_exists('ssl_state', $this->options)) {
			$filters['mgn_ssl_state'] = $this->options['ssl_state'] === null
				? "IS NULL"
				: [$this->options['ssl_state'], PDO::PARAM_STR];
		}

		if (array_key_exists('install_state', $this->options)) {
			$filters['mgn_install_state'] = $this->options['install_state'] === null
				? "IS NULL"
				: [$this->options['install_state'], PDO::PARAM_STR];
		}

		return $this->_get_resultsv2('mgn_managed_nodes', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
