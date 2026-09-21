<?php
/**
 * ServiceTenant — one self-hosted site's standing with one service this
 * operator runs (specs/services_phase2_platform.md §6, umbrella contract C2).
 *
 * A site that uses our outbound mail or our backup storage holds one row per
 * service. The row is KEYED BY THE CONNECTED KEY (svt_apk_api_key_id, D2): the
 * Connect flow mints one API key per site, so the key is the site. The host is
 * a label the site reports and may change; a renamed site keeps its row and
 * its backup storage.
 *
 * What decides whether the service works is the date: svt_paid_until, null
 * meaning never entitled. In this phase an operator writes it from the
 * Service Tenants admin page; a payment writes the same column later. The
 * reconcile compares it every pass and walks the row down the core
 * ServiceTenantLadder when it has passed.
 *
 * The states:
 *
 *   unpaid        the row exists (the site asked) and no date was ever set.
 *                 Nothing has been minted.
 *   provisioning  entitled, and the provider-side pieces are being built
 *                 (mail only: a subaccount without its sender domain yet).
 *   active        entitled and working.
 *   suspended     the date passed and the grace window ran out: mail's
 *                 subaccount is closed, the backup storage broker refuses the tenant.
 *                 The 90-day retention clock (svt_prune_after_time) runs from
 *                 svt_revoked_time. A new date reactivates in place.
 *   released      the customer left the service (the switch-over) or the
 *                 account holder disconnected the site. Same retention clock.
 *
 * THE FIGURE IS NOT A METER THE PLANE TRUSTS. For mail it is the provider's
 * month-to-date count (read by the reconcile; the webhook nudges it between
 * reads); the provider enforces the limit. For backup storage it is the sum of
 * the ledger's completed objects, which the broker keeps exact; the broker
 * refuses a run that would cross the allowance.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class ServiceTenantException extends SystemBaseException {}

class ServiceTenant extends SystemBase {
	public static $prefix = 'svt';
	public static $tablename = 'svt_service_tenants';
	public static $pkey_column = 'svt_service_tenant_id';

	const SERVICE_MAIL  = 'mail';
	const SERVICE_SHELF = 'shelf';
	const SERVICES = array(self::SERVICE_MAIL, self::SERVICE_SHELF);

	/** Before any date: the site asked, nothing was minted. */
	const STATE_UNPAID       = 'unpaid';
	const STATE_PROVISIONING = ServiceTenantLadder::STATE_PROVISIONING;
	const STATE_ACTIVE       = ServiceTenantLadder::STATE_ACTIVE;
	const STATE_SUSPENDED    = ServiceTenantLadder::STATE_SUSPENDED;
	const STATE_RELEASED     = ServiceTenantLadder::STATE_RELEASED;
	const STATES = array(
		self::STATE_UNPAID, self::STATE_PROVISIONING, self::STATE_ACTIVE,
		self::STATE_SUSPENDED, self::STATE_RELEASED,
	);

	/** Mail: where the sender domain stands at the provider. */
	const MAIL_DOMAIN_ADDED    = 'domain_added';
	const MAIL_DOMAIN_VERIFIED = 'domain_verified';

	/** Days backup storage is kept after suspension or release: a customer-facing promise. */
	const RETENTION_DAYS = 90;

	protected static $foreign_key_actions = array(
		// A tenant row without its account is a row nobody can act for; the
		// key going is the account holder cutting the site off, which the
		// Disconnect path handles by releasing first.
		'svt_usr_user_id'     => array('action' => 'cascade'),
		'svt_apk_api_key_id'  => array('action' => 'null'),
	);

	public static $test_fixture = array(
		'values'       => array('svt_service' => 'mail', 'svt_state' => 'unpaid'),
		'update_field' => 'svt_host',
	);

	public static $field_specifications = array(
		'svt_service_tenant_id'  => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		// The getjoinery account the site is linked to, and the connected key
		// that IS the site (D2). The key id moves to a new key on re-connect.
		'svt_usr_user_id'        => array('type'=>'int8', 'is_nullable'=>false),
		'svt_apk_api_key_id'     => array('type'=>'int8'),
		// The site's host as it last reported it: a label, refreshed on every
		// status call, never a key.
		'svt_host'               => array('type'=>'varchar(255)'),
		'svt_service'            => array('type'=>'varchar(16)', 'is_nullable'=>false,
			'allowed_values'=>array('mail', 'shelf')),
		// The tenant's name at the provider and in backup storage: t<id>, so a
		// bucket listing or a subaccount label names no customer.
		'svt_slug'               => array('type'=>'varchar(28)'),
		'svt_state'              => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>'unpaid',
			'allowed_values'=>array('unpaid', 'provisioning', 'active', 'suspended', 'released')),
		// Entitlement: the date the tenant is paid through (null = never), and
		// the allowance in the service's unit (sends a month; bytes on the
		// shelf), snapshotted from the plan setting on every reconcile.
		'svt_paid_until'         => array('type'=>'timestamp(6)'),
		'svt_allowance'          => array('type'=>'int8', 'is_nullable'=>false, 'default'=>0),
		// The figure against the allowance and when it was measured, plus when
		// the plane last looked at this row at all.
		'svt_figure'             => array('type'=>'int8', 'is_nullable'=>false, 'default'=>0),
		'svt_figure_time'        => array('type'=>'timestamp(6)'),
		'svt_checked_time'       => array('type'=>'timestamp(6)'),
		// Shelf: when the ledger was last reconciled against a real listing.
		'svt_reconciled_time'    => array('type'=>'timestamp(6)'),
		// The ladder's clock: when the date was first seen passed. Grace ends
		// svt_lapse_time + server_manager_services_grace_days.
		'svt_lapse_time'         => array('type'=>'timestamp(6)'),
		// When the service actually stopped (suspended past grace, or released),
		// and the day backup storage may be pruned: revoked + RETENTION_DAYS. Stored,
		// because it is a promise made to the customer on that day.
		'svt_revoked_time'       => array('type'=>'timestamp(6)'),
		'svt_prune_after_time'   => array('type'=>'timestamp(6)'),
		'svt_pruned_time'        => array('type'=>'timestamp(6)'),
		// Mail: the provider ids, the sender domain and where it stands, and
		// the DNS records the provider asked for (JSON, DnsRecordPlan shape),
		// kept so the site can ask for them again.
		'svt_provider_subaccount_id' => array('type'=>'varchar(64)'),
		'svt_provider_domain'    => array('type'=>'varchar(255)'),
		'svt_provider_user_id'   => array('type'=>'varchar(128)'),
		'svt_mail_state'         => array('type'=>'varchar(24)'),
		'svt_mail_records'       => array('type'=>'text'),
		// The sentence the site shows (a refusal's cause, a pause), or null.
		'svt_notice'             => array('type'=>'text'),
		'svt_note'               => array('type'=>'text'),
		'svt_create_time'        => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'svt_update_time'        => array('type'=>'timestamp(6)'),
		'svt_delete_time'        => array('type'=>'timestamp(6)'),
	);

	function prepare() {
		$this->set('svt_update_time', gmdate('Y-m-d H:i:s'));
	}

	function save($debug = false) {
		if (!(int)$this->get('svt_usr_user_id')) {
			throw new ServiceTenantException('A service tenant row belongs to an account.');
		}
		if (!in_array((string)$this->get('svt_service'), self::SERVICES, true)) {
			throw new ServiceTenantException("Unknown service '" . $this->get('svt_service') . "'.");
		}
		$state = $this->get('svt_state') ?: self::STATE_UNPAID;
		if (!in_array($state, self::STATES, true)) {
			throw new ServiceTenantException("Unknown tenant state '{$state}'.");
		}
		$this->set('svt_update_time', gmdate('Y-m-d H:i:s'));
		return parent::save($debug);
	}

	/** Is the date set and still ahead of now? The one question entitlement asks. */
	public function entitled(?string $now = null): bool {
		$until = trim((string)$this->get('svt_paid_until'));
		if ($until === '') {
			return false;
		}
		return $until > ($now ?? gmdate('Y-m-d H:i:s'));
	}

	/** The service works right now: active and entitled. */
	public function usable(?string $now = null): bool {
		return (string)$this->get('svt_state') === self::STATE_ACTIVE && $this->entitled($now);
	}

	/** The DNS records the mail provider asked for, as stored. */
	public function mailRecords(): array {
		$raw = $this->get('svt_mail_records');
		if (is_string($raw)) { $raw = json_decode($raw, true); }
		return is_array($raw) ? $raw : array();
	}

	/** The row for one connected key and one service, or null. */
	public static function forKey(int $api_key_id, string $service): ?ServiceTenant {
		if ($api_key_id <= 0) {
			return null;
		}
		$rows = new MultiServiceTenant(array(
			'api_key_id' => $api_key_id, 'service' => $service, 'deleted' => false,
		), array('svt_service_tenant_id' => 'DESC'), 1);
		foreach ($rows as $row) {
			return $row;
		}
		return null;
	}

	/** The account's rows for one host label (both services), newest first. */
	public static function forHost(int $user_id, string $host): array {
		$out = array();
		$rows = new MultiServiceTenant(array(
			'user_id' => $user_id, 'host' => strtolower(trim($host)), 'deleted' => false,
		), array('svt_service_tenant_id' => 'DESC'));
		foreach ($rows as $row) {
			$out[] = $row;
		}
		return $out;
	}
}

class MultiServiceTenant extends SystemMultiBase {
	protected static $model_class = 'ServiceTenant';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['user_id'])) {
			$filters['svt_usr_user_id'] = array((int)$this->options['user_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['api_key_id'])) {
			$filters['svt_apk_api_key_id'] = array((int)$this->options['api_key_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['service'])) {
			$filters['svt_service'] = array((string)$this->options['service'], PDO::PARAM_STR);
		}
		if (isset($this->options['host'])) {
			$filters['svt_host'] = array((string)$this->options['host'], PDO::PARAM_STR);
		}
		if (isset($this->options['state'])) {
			$filters['svt_state'] = array((string)$this->options['state'], PDO::PARAM_STR);
		}
		if (isset($this->options['states']) && is_array($this->options['states']) && count($this->options['states'])) {
			$quoted = array_map(function ($s) {
				return "'" . preg_replace('/[^a-z_]/', '', $s) . "'";
			}, $this->options['states']);
			$filters['svt_state'] = 'IN (' . implode(',', $quoted) . ')';
		}
		if (isset($this->options['provider_user_id'])) {
			$filters['svt_provider_user_id'] = array((string)$this->options['provider_user_id'], PDO::PARAM_STR);
		}
		if (isset($this->options['provider_subaccount_id'])) {
			$filters['svt_provider_subaccount_id'] = array((string)$this->options['provider_subaccount_id'], PDO::PARAM_STR);
		}
		// Rows whose backup storage may be pruned now.
		if (!empty($this->options['prune_due'])) {
			$filters['svt_prune_after_time'] = "<= now() AND svt_pruned_time IS NULL";
		}

		return $this->_get_resultsv2('svt_service_tenants', $filters, $this->order_by, $only_count, $debug);
	}
}
