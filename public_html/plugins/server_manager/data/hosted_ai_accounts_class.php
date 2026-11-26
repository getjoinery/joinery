<?php
/**
 * HostedAiAccount — one site's AI credit with us: its TrustedRouter key and
 * the figures that set the key's cap (specs/hosted_ai_credit.md §3.1, §3.2).
 *
 * The site is either a Managed site (hai_cvp_customer_cloud_provision_id) or
 * a self-hosted site on the email and backups bundle
 * (hai_svt_service_tenant_id, the site's first service row). One of the two
 * is set; a Managed site that moves to its buyer's own account and keeps the
 * bundle carries its account across by gaining the tenant id.
 *
 * THE KEY ITSELF IS NEVER STORED. hai_key_hash is TrustedRouter's id for it,
 * enough to cap, disable and delete it. The key exists for as long as it takes
 * to push it to the site; a retry mints a new one and deletes the old.
 *
 * A REPLACED KEY IS RETIRED IN TWO STEPS (HostedAiCredit::retire_key): it is
 * switched off and its spend so far settled, then parked in
 * hai_retiring_keys ([{hash, usage, time}], one entry per replaced key); a later pass reads its final usage (requests that were
 * in flight when it was switched off included), charges the difference, and
 * only then deletes it. A failure at either step changes nothing.
 *
 * `removing` is a removal that could not reach TrustedRouter; the leg asks
 * again each pass until the key is deleted.
 *
 * Every money figure is microdollars of TrustedRouter spend (bigint):
 *
 *   hai_period_start_usage  U0  the key's lifetime usage when this month began
 *   hai_allowance           A   this month's free usage, at most $1
 *   hai_purchased           B   bought credit left at the month's start, plus any bought since
 *   hai_owed                D   spend past the cap nothing has paid for yet
 *
 * The key's cap is always U0 + A + B (HostedAiCap::cap()). hai_purchased moves
 * only in the same transaction as the HostedAiLedger row that explains it.
 *
 * @version 1.2 - a removing account may have lost its site row (B15)
 * @version 1.1 - two-step key retirement (hai_retiring_keys), hai_key_time, the removing state (reviewer2 B1, B2, B5)
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class HostedAiAccountException extends SystemBaseException {}

class HostedAiAccount extends SystemBase {
	public static $prefix = 'hai';
	public static $tablename = 'hai_hosted_ai_accounts';
	public static $access_class = 'money';
	public static $pkey_column = 'hai_hosted_ai_account_id';

	const STATE_PENDING         = 'pending';
	const STATE_KEY_CREATED     = 'key_created';
	const STATE_SETTINGS_PUSHED = 'settings_pushed';
	const STATE_DONE            = 'done';
	const STATE_FAILED          = 'failed';
	const STATE_DISABLED        = 'disabled';
	const STATE_REMOVING        = 'removing';
	const STATE_REMOVED         = 'removed';

	const STATES = array(self::STATE_PENDING, self::STATE_KEY_CREATED, self::STATE_SETTINGS_PUSHED,
		self::STATE_DONE, self::STATE_FAILED, self::STATE_DISABLED, self::STATE_REMOVING, self::STATE_REMOVED);

	/** A key exists at TrustedRouter in these states. */
	const KEYED_STATES = array(self::STATE_KEY_CREATED, self::STATE_SETTINGS_PUSHED, self::STATE_DONE,
		self::STATE_DISABLED);

	protected static $foreign_key_actions = array(
		'hai_cvp_customer_cloud_provision_id' => array('action' => 'null'),
		'hai_svt_service_tenant_id'           => array('action' => 'null'),
	);

	public static $test_fixture = array(
		// A removed account names no site, so the fixture needs no provision.
		'values'       => array('hai_state' => 'removed'),
		'update_field' => 'hai_note',
	);

	public static $field_specifications = array(
		'hai_hosted_ai_account_id'            => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'hai_cvp_customer_cloud_provision_id' => array('type'=>'int8', 'unique'=>true),
		'hai_svt_service_tenant_id'           => array('type'=>'int8', 'unique'=>true),
		'hai_state'                           => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>'pending'),
		'hai_key_hash'                        => array('type'=>'varchar(128)'),
		'hai_key_time'                        => array('type'=>'timestamp(6)'),
		'hai_retiring_keys'                   => array('type'=>'jsonb'),
		'hai_period_start'                    => array('type'=>'varchar(7)'),
		'hai_period_start_usage'              => array('type'=>'int8', 'is_nullable'=>false, 'default'=>0),
		'hai_allowance'                       => array('type'=>'int8', 'is_nullable'=>false, 'default'=>0),
		'hai_purchased'                       => array('type'=>'int8', 'is_nullable'=>false, 'default'=>0),
		'hai_owed'                            => array('type'=>'int8', 'is_nullable'=>false, 'default'=>0),
		'hai_usage'                           => array('type'=>'int8', 'is_nullable'=>false, 'default'=>0),
		'hai_usage_time'                      => array('type'=>'timestamp(6)'),
		'hai_limit_set'                       => array('type'=>'int8'),
		'hai_disabled_set'                    => array('type'=>'bool', 'is_nullable'=>false, 'default'=>'false'),
		'hai_note'                            => array('type'=>'text'),
		'hai_attempts'                        => array('type'=>'int4', 'is_nullable'=>false, 'default'=>0),
		'hai_retry_time'                      => array('type'=>'timestamp(6)'),
		'hai_create_time'                     => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'hai_update_time'                     => array('type'=>'timestamp(6)', 'stamp_on_save'=>true),
		'hai_delete_time'                     => array('type'=>'timestamp(6)'),
	);

	function save($debug = false) {
		$provision = (int)$this->get('hai_cvp_customer_cloud_provision_id');
		$tenant = (int)$this->get('hai_svt_service_tenant_id');
		$state = (string)($this->get('hai_state') ?: self::STATE_PENDING);
		// A removed account keeps its history after its site is gone, so only
		// a live one has to name a site.
		// A removing one too: its provision row may have been deleted while
		// TrustedRouter was down, and the retry must still be able to save (B15).
		if (!in_array($state, array(self::STATE_REMOVED, self::STATE_REMOVING), true) && !$provision && !$tenant) {
			throw new HostedAiAccountException('An AI credit account belongs to a hosted site or a bundle site.');
		}
		if (!in_array($state, self::STATES, true)) {
			throw new HostedAiAccountException("Unknown AI credit state '{$state}'.");
		}
		foreach (array('hai_period_start_usage', 'hai_allowance', 'hai_purchased', 'hai_owed') as $field) {
			if ((int)$this->get($field) < 0) {
				throw new HostedAiAccountException($field . ' cannot be below zero.');
			}
		}
		return parent::save($debug);
	}

	/** The Managed site's account, or null. */
	public static function for_provision($provision_id): ?HostedAiAccount {
		return self::first(array('provision_id' => (int)$provision_id));
	}

	/** The bundle site's account (by its anchor service row), or null. */
	public static function for_tenant($tenant_id): ?HostedAiAccount {
		return self::first(array('tenant_id' => (int)$tenant_id));
	}

	public static function for_key_hash(string $hash): ?HostedAiAccount {
		return $hash === '' ? null : self::first(array('key_hash' => $hash));
	}

	private static function first(array $opts): ?HostedAiAccount {
		if ((int)reset($opts) <= 0 && !is_string(reset($opts))) {
			return null;
		}
		foreach (new MultiHostedAiAccount($opts + array('deleted' => false),
				array('hai_hosted_ai_account_id' => 'DESC'), 1) as $row) {
			return $row;
		}
		return null;
	}

	/** Spend this month, microdollars: lifetime usage less usage at the month's start. */
	public function spent_this_month(): int {
		return max(0, (int)$this->get('hai_usage') - (int)$this->get('hai_period_start_usage'));
	}

	/** The cap the figures call for now. */
	public function wanted_cap(): int {
		return HostedAiCap::cap((int)$this->get('hai_period_start_usage'),
			(int)$this->get('hai_allowance'), (int)$this->get('hai_purchased'));
	}

	/** Bought credit not yet spent, microdollars (what a refund is measured against). */
	public function unspent_purchased(): int {
		return HostedAiCap::unspent_purchased($this->spent_this_month(),
			(int)$this->get('hai_allowance'), (int)$this->get('hai_purchased'));
	}

	/** The parked keys: [['hash' => …, 'usage' => settled usage, 'time' => switched off], …]. */
	public function retiring_keys(): array {
		$raw = $this->get('hai_retiring_keys');
		$list = is_string($raw) ? json_decode($raw, true) : $raw;
		return is_array($list) ? array_values($list) : array();
	}

	public function set_retiring_keys(array $list): void {
		$this->set('hai_retiring_keys', $list ? json_encode(array_values($list)) : null);
	}

	public function has_key(): bool {
		return trim((string)$this->get('hai_key_hash')) !== ''
			&& in_array((string)$this->get('hai_state'), self::KEYED_STATES, true);
	}
}

class MultiHostedAiAccount extends SystemMultiBase {
	protected static $model_class = 'HostedAiAccount';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['provision_id'])) {
			$filters['hai_cvp_customer_cloud_provision_id'] = array((int)$this->options['provision_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['tenant_id'])) {
			$filters['hai_svt_service_tenant_id'] = array((int)$this->options['tenant_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['key_hash'])) {
			$filters['hai_key_hash'] = array((string)$this->options['key_hash'], PDO::PARAM_STR);
		}
		if (isset($this->options['states'])) {
			$filters['hai_state'] = SqlCondition::bind('IN (?)', array_values((array)$this->options['states']));
		}
		return $this->_get_resultsv2('hai_hosted_ai_accounts', $filters, $this->order_by, $only_count, $debug);
	}
}
