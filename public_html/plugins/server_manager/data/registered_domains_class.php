<?php
/**
 * RegisteredDomain - one domain bought on a buyer's behalf, cradle to custody.
 *
 * The row exists from the moment a paid order carries a managed-domain answer
 * and lives until the domain is fully the buyer's. Two independent axes run
 * along it, and conflating them is the mistake this class is shaped to
 * prevent:
 *
 *  - **rdm_status** is the fulfillment axis: is the name bought and wired up?
 *    pending -> registered -> active, with failed parked for a person.
 *  - **rdm_graduation_state** is the custody axis: whose registrar account
 *    holds it? operator_managed -> push_requested -> push_sent -> self_custody.
 *
 * Legal ownership belongs to neither axis, because it never changes: the buyer
 * is the WHOIS registrant from registration. Custody is about who manages and
 * pays, not who owns.
 *
 * **The step timestamps are the idempotency ledger, not decoration.** Each of
 * rdm_dns_bootstrap_time, rdm_dns_mail_time and rdm_ptr_time is null while its
 * step is outstanding and stamped once it is done; the provisioning phase
 * retries exactly the null ones every tick. Registration itself is guarded by
 * status instead — only a pending row may attempt a purchase — because a
 * timestamp written after a charge is one crash away from a second charge.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/SecretBox.php'));

class RegisteredDomainException extends SystemBaseException {}

class RegisteredDomain extends SystemBase {

	public static $prefix = 'rdm';
	public static $tablename = 'rdm_registered_domains';
	public static $pkey_column = 'rdm_id';

	/** Fulfillment axis. */
	const STATUS_PENDING    = 'pending';
	const STATUS_REGISTERED = 'registered';
	const STATUS_ACTIVE     = 'active';
	const STATUS_FAILED     = 'failed';

	/** Custody axis. */
	const GRAD_OPERATOR  = 'operator_managed';
	const GRAD_REQUESTED = 'push_requested';
	const GRAD_SENT      = 'push_sent';
	const GRAD_SELF      = 'self_custody';

	/** How long before expiry the buyer first hears about graduation. */
	const PROMPT_LEAD_DAYS = 182;

	protected static $foreign_key_actions = array(
		// A buyer with a live domain cannot be deleted out from under it: the
		// row is what the pipeline uses to reach them about the renewal, and a
		// domain with no owner of record is worse than a delete that refuses.
		'rdm_usr_user_id' => array('action' => 'prevent',
			'message' => 'This user owns a registered domain.'),
		// The box can go away — a domain outlives the server it pointed at,
		// and the row's job (custody, expiry, hand-over) does not need one.
		'rdm_mgn_node_id' => array('action' => 'null'),
	);

	public static $test_fixture = array(
		'update_field' => 'rdm_buyer_email',
	);

	public static $field_specifications = array(
		'rdm_id'                     => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'rdm_registrar'              => array('type'=>'varchar(32)', 'is_nullable'=>false, 'default'=>'namecheap'),
		'rdm_domain'                 => array('type'=>'varchar(255)', 'required'=>true, 'unique'=>true),
		'rdm_usr_user_id'            => array('type'=>'int8', 'is_nullable'=>false,
			'foreign_key'=>array('table'=>'usr_users','column'=>'usr_user_id','on_delete'=>'RESTRICT')),
		'rdm_external_order_item_id' => array('type'=>'int8', 'unique'=>true),
		'rdm_mgn_node_id'            => array('type'=>'int8'),
		'rdm_buyer_email'            => array('type'=>'varchar(255)'),
		'rdm_registrant_sealed'      => array('type'=>'text'),
		'rdm_price_paid'             => array('type'=>'numeric(10,2)'),
		'rdm_status'                 => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>'pending',
			'allowed_values'=>array('pending','registered','active','failed')),
		'rdm_graduation_state'       => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>'operator_managed',
			'allowed_values'=>array('operator_managed','push_requested','push_sent','self_custody')),
		'rdm_ncp_username'           => array('type'=>'varchar(128)'),
		'rdm_registered_time'        => array('type'=>'timestamp(6)'),
		'rdm_expiry_time'            => array('type'=>'timestamp(6)'),
		'rdm_expiry_checked_time'    => array('type'=>'timestamp(6)'),
		'rdm_dns_bootstrap_time'     => array('type'=>'timestamp(6)'),
		'rdm_dns_mail_time'          => array('type'=>'timestamp(6)'),
		'rdm_ptr_time'               => array('type'=>'timestamp(6)'),
		'rdm_prompt_pushed_time'     => array('type'=>'timestamp(6)'),
		'rdm_error'                  => array('type'=>'text'),
		'rdm_create_time'            => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'rdm_update_time'            => array('type'=>'timestamp(6)'),
		'rdm_delete_time'            => array('type'=>'timestamp(6)'),
	);

	function prepare() {
		$this->set('rdm_update_time', gmdate('Y-m-d H:i:s'));
	}

	function save($debug = false) {
		$domain = strtolower(trim((string)$this->get('rdm_domain')));
		if ($domain === '') {
			throw new RegisteredDomainException('A registered domain row needs a domain name.');
		}
		$this->set('rdm_domain', $domain);
		if (!$this->get('rdm_usr_user_id')) {
			throw new RegisteredDomainException('A registered domain row needs an owner.');
		}
		$this->set('rdm_update_time', gmdate('Y-m-d H:i:s'));
		return parent::save($debug);
	}

	// ------------------------------------------------------------------
	// The registrant snapshot
	// ------------------------------------------------------------------

	/**
	 * Store the contact block the domain was registered with.
	 *
	 * It is a home address and phone number, held only because a re-registration
	 * attempt after a transient failure has to send the same WHOIS owner. It is
	 * sealed wherever a secret_box_key exists and written as readable JSON where
	 * one does not, so a zero-config install still works — the same tolerance
	 * every other sealed column here has.
	 */
	public function seal_registrant(array $contact): void {
		$json = json_encode($contact);
		try {
			$box = new SecretBox();
		} catch (\Throwable $e) {
			// Zero-config install (no secret_box_key): store readable JSON.
			$this->set('rdm_registrant_sealed', $json);
			return;
		}
		// seal() outside the catch: a refused (undeclared) locator must fail loudly.
		$this->set('rdm_registrant_sealed',
			json_encode(array('enc' => $box->seal('rdm_registered_domains.rdm_registrant_sealed', $json))));
	}

	/**
	 * Every stored registrant blob, for the sealed-secret reconciler. The column
	 * is a JSON {"enc":"<blob>"} envelope, so the reconciler cannot reach the blob
	 * from the code-free locator alone — this enumerator unwraps it.
	 *
	 * @return array<array{ref:string, blob:?string}>
	 */
	public static function eachRegistrantBlob(): array {
		$out = array();
		$rows = new MultiRegisteredDomain(array('deleted' => false));
		$rows->load();
		foreach ($rows as $row) {
			$decoded = json_decode((string)$row->get('rdm_registrant_sealed'), true);
			$blob = (is_array($decoded) && isset($decoded['enc']) && is_string($decoded['enc'])
				&& SecretBox::looksEncrypted($decoded['enc'])) ? $decoded['enc'] : null;
			$out[] = array('ref' => (string)$row->get('rdm_domain'), 'blob' => $blob);
		}
		return $out;
	}

	/** The stored contact block, or null when there is none / it cannot be read. */
	public function open_registrant(): ?array {
		$stored = trim((string)$this->get('rdm_registrant_sealed'));
		if ($stored === '') {
			return null;
		}
		$decoded = json_decode($stored, true);
		if (!is_array($decoded)) {
			return null;
		}
		if (isset($decoded['enc']) && is_string($decoded['enc']) && SecretBox::looksEncrypted($decoded['enc'])) {
			$opened = (new SecretBox())->open($decoded['enc']);
			if ($opened['value'] === null) {   // dead: moved database or rotated key
				return null;
			}
			$inner = json_decode($opened['value'], true);
			return is_array($inner) ? $inner : null;
		}
		return $decoded;
	}

	// ------------------------------------------------------------------
	// State helpers
	// ------------------------------------------------------------------

	/** Every fulfillment step is done. */
	public function steps_complete(): bool {
		return $this->get('rdm_dns_bootstrap_time')
			&& $this->get('rdm_dns_mail_time')
			&& $this->get('rdm_ptr_time');
	}

	/** Whole days until expiry, or null when the expiry is unknown. */
	public function days_to_expiry(): ?int {
		$expiry = trim((string)$this->get('rdm_expiry_time'));
		if ($expiry === '') {
			return null;
		}
		$stamp = strtotime($expiry . ' UTC');
		if ($stamp === false) {
			return null;
		}
		return (int)floor(($stamp - time()) / 86400);
	}

	/**
	 * Is the buyer inside the window where graduation is mentioned at all?
	 *
	 * Nothing about ownership transfer appears anywhere — not in the wizard,
	 * not in the welcome email — until six months before expiry. Before then
	 * the buyer has a working site and no chore.
	 */
	public function in_prompt_window(): bool {
		$days = $this->days_to_expiry();
		return $days !== null && $days <= self::PROMPT_LEAD_DAYS;
	}

	/** Record a terminal failure. Saves. */
	public function fail(string $message): void {
		$this->set('rdm_status', self::STATUS_FAILED);
		$this->set('rdm_error', mb_substr($message, 0, 4000));
		$this->save();
	}
}

class MultiRegisteredDomain extends SystemMultiBase {

	protected static $model_class = 'RegisteredDomain';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['user_id'])) {
			$filters['rdm_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
		}

		if (isset($this->options['domain'])) {
			$filters['rdm_domain'] = array(strtolower(trim((string)$this->options['domain'])), PDO::PARAM_STR);
		}

		if (isset($this->options['status'])) {
			$filters['rdm_status'] = array($this->options['status'], PDO::PARAM_STR);
		}

		if (isset($this->options['statuses']) && is_array($this->options['statuses'])
				&& count($this->options['statuses'])) {
			$quoted = array_map(function ($s) {
				return "'" . preg_replace('/[^a-z_]/', '', $s) . "'";
			}, $this->options['statuses']);
			$filters['rdm_status'] = 'IN (' . implode(',', $quoted) . ')';
		}

		if (isset($this->options['graduation_state'])) {
			$filters['rdm_graduation_state'] = array($this->options['graduation_state'], PDO::PARAM_STR);
		}

		if (isset($this->options['graduation_states']) && is_array($this->options['graduation_states'])
				&& count($this->options['graduation_states'])) {
			$quoted = array_map(function ($s) {
				return "'" . preg_replace('/[^a-z_]/', '', $s) . "'";
			}, $this->options['graduation_states']);
			$filters['rdm_graduation_state'] = 'IN (' . implode(',', $quoted) . ')';
		}

		if (isset($this->options['external_order_item_id'])) {
			$filters['rdm_external_order_item_id'] = array($this->options['external_order_item_id'], PDO::PARAM_INT);
		}

		if (isset($this->options['node_id'])) {
			$filters['rdm_mgn_node_id'] = array($this->options['node_id'], PDO::PARAM_INT);
		}

		return $this->_get_resultsv2('rdm_registered_domains', $filters, $this->order_by, $only_count, $debug);
	}
}
