<?php
/**
 * DataRootEscrow — one machine's encrypted data root, as its escrow holder
 * keeps it (specs/hosted_data_promise_disk_encryption.md D3, WP2).
 *
 * The holder keeps one random escrow secret per machine, sealed under its
 * SecretBox, and answers that machine's boot with it: the machine adds it as
 * a key slot at enrolment and opens its data root with it at every boot. The
 * row is keyed by the machine's UNLOCK IDENTITY, an Ed25519 key of its own,
 * not by a node row: the holder may not be the machine's plane (getjoinery and
 * dev hold each other's), and a leave or a re-pair changes nothing here.
 *
 * What the holder checks before it answers (Tier 1): the request is signed by
 * a recorded identity, it comes from an address the machine may ask from (the
 * addresses it stated and those its requests came from, both families,
 * compared as addresses), and the identity has not been answered in the last
 * 15 minutes without reporting itself unlocked or shut down since.
 *
 * THE REPORT KEY. The identity is on the plain root disk, so every copy of the
 * disk carries it, and a copy could report a new address of its own before it
 * asks. So what changes the record (an address, unlocked, shut down) must also
 * carry an HMAC under a key derived from the volume key, handed over at
 * enrolment while the volume was open. A running machine can make one; a copy
 * of its disk, whose volume is ciphertext, cannot.
 *
 * Status: pending (asked, awaiting a person's approval, C-3), approved (the
 * secret is minted and handed over, the slot not yet confirmed), enrolled,
 * known (seen from its node's host report, never enrolled: a holder unlock can
 * still answer it), retired.
 *
 * @version 1.3 - takes_new_enrolments(): only where data_root_encryption_offered is on (D7: none before the release
 *                keys are rotated); approve() refuses otherwise. dre_retired_time: when this holder gave a key up, an escrow change its own backup must
 *                follow (O11, EscrowHolderBackup)
 * @version 1.2 - address_moved(): an IP swap this management node makes moves the rows' stated and seen addresses
 * @version 1.1 - Tier 2 (D3): the provider event that refused a boot, and the owner's excuse for it
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/SecretBox.php'));

class DataRootEscrowException extends SystemBaseException {}

class DataRootEscrow extends SystemBase {
	public static $prefix = 'dre';
	public static $tablename = 'dre_data_root_escrows';
	public static $pkey_column = 'dre_data_root_escrow_id';

	const SECRET_LOCATOR = 'dre_data_root_escrows.dre_secret';
	const REPORT_KEY_LOCATOR = 'dre_data_root_escrows.dre_report_key';
	const SHARED_LOCATOR = 'dre_data_root_escrows.dre_pending_shared';

	/** One answer per boot (D3, O3): no second answer inside this many seconds. */
	const ANSWER_HOLD_SECONDS = 900;
	/** A value a person gave on the holder's unlock page waits this long for its machine. */
	const SHARED_TTL_SECONDS = 900;
	/** At most this many requests waiting for a person's approval at once. */
	const MAX_PENDING = 20;

	const STATUSES = array('pending', 'approved', 'enrolled', 'known', 'retired');

	protected static $foreign_key_actions = array(
		'dre_mgn_managed_node_id' => array('action' => 'null'),
		// Records of who approved or excused, never owners: the row stays.
		'dre_approved_usr_user_id' => array('action' => 'null', 'source_table' => 'usr_users'),
		'dre_tier2_excused_usr_user_id' => array('action' => 'null', 'source_table' => 'usr_users'),
	);

	public static $test_fixture = array(
		'values'       => array('dre_identity_key' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'dre_status' => 'pending'),
		'update_field' => 'dre_hostname',
	);

	public static $field_specifications = array(
		'dre_data_root_escrow_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		// The machine's unlock identity: its Ed25519 public key, base64.
		'dre_identity_key'    => array('type'=>'varchar(64)', 'is_nullable'=>false, 'unique'=>true),
		'dre_status'          => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>'pending'),
		// The node it is, where it is one of this plane's (proved by its node
		// key at enrolment, or seen in its host report). Null for a machine
		// held for another plane (C-3).
		'dre_mgn_managed_node_id' => array('type'=>'int8'),
		'dre_hostname'        => array('type'=>'varchar(255)'),
		// Sealed (SecretBox): the escrow secret, and the report key.
		'dre_secret'          => array('type'=>'text'),
		'dre_report_key'      => array('type'=>'text'),
		'dre_slot'            => array('type'=>'int4'),
		// The break-glass copy's envelope as last reported, and when it last
		// differed from the one before (D2b: shown as a warning).
		'dre_box'             => array('type'=>'text'),
		'dre_box_sha256'      => array('type'=>'varchar(64)'),
		'dre_box_changed_time' => array('type'=>'timestamp(6)'),
		// Where it may ask from: what it stated, and what its accepted
		// requests came from. JSON lists of addresses.
		'dre_addresses'       => array('type'=>'text'),
		'dre_seen_addresses'  => array('type'=>'text'),
		'dre_requested_time'  => array('type'=>'timestamp(6)'),
		'dre_approved_time'   => array('type'=>'timestamp(6)'),
		'dre_approved_usr_user_id' => array('type'=>'int4'),
		'dre_enrolled_time'   => array('type'=>'timestamp(6)'),
		// When this holder gave the key up (retire). With the enrolment time,
		// what this holder's own backup has to follow (O11).
		'dre_retired_time'    => array('type'=>'timestamp(6)'),
		// The last answer, and what the machine said since.
		'dre_answered_time'   => array('type'=>'timestamp(6)'),
		'dre_answered_boot_id' => array('type'=>'varchar(64)'),
		'dre_answered_address' => array('type'=>'varchar(64)'),
		'dre_unlocked_time'   => array('type'=>'timestamp(6)'),
		'dre_shutdown_time'   => array('type'=>'timestamp(6)'),
		// The last refusal, for the holder's page.
		'dre_refusal'         => array('type'=>'text'),
		'dre_refusal_time'    => array('type'=>'timestamp(6)'),
		'dre_refusal_address' => array('type'=>'varchar(64)'),
		// Tier 2 (D3): the provider event that refused the last boot, and the
		// owner's word that they did it themselves, which excuses every event
		// up to when it was given.
		'dre_tier2_event'     => array('type'=>'varchar(255)'),
		'dre_tier2_excused_time' => array('type'=>'timestamp(6)'),
		'dre_tier2_excused_usr_user_id' => array('type'=>'int8'),
		'dre_last_request_time' => array('type'=>'timestamp(6)'),
		'dre_last_request_address' => array('type'=>'varchar(64)'),
		// A value a person worked out on the holder's unlock page (D2b), sealed,
		// handed to the machine at its next ask and then forgotten.
		'dre_pending_shared'  => array('type'=>'text'),
		'dre_pending_shared_time' => array('type'=>'timestamp(6)'),
		'dre_create_time'     => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'dre_update_time'     => array('type'=>'timestamp(6)'),
	);

	function save($debug = false) {
		$key = (string)$this->get('dre_identity_key');
		$raw = base64_decode($key, true);
		if ($raw === false || strlen($raw) !== 32) {
			throw new DataRootEscrowException('An escrow row is keyed by an unlock identity: 32 bytes, base64.');
		}
		if (!in_array((string)$this->get('dre_status'), self::STATUSES, true)) {
			throw new DataRootEscrowException('Unknown escrow status: ' . $this->get('dre_status'));
		}
		$this->set('dre_update_time', gmdate('Y-m-d H:i:s'));
		return parent::save($debug);
	}

	public static function find_by_identity(string $identity_b64): ?DataRootEscrow {
		foreach (new MultiDataRootEscrow(array('identity_key' => $identity_b64)) as $row) {
			return $row;
		}
		return null;
	}

	/** The row its node's machine enrolled or was seen with, newest first. */
	public static function for_node(int $node_id): ?DataRootEscrow {
		foreach (new MultiDataRootEscrow(array('node' => $node_id, 'live' => true), array('dre_data_root_escrow_id' => 'DESC')) as $row) {
			return $row;
		}
		return null;
	}

	/** The short fingerprint both sides show: 16 hex of sha256 over the raw key. */
	public static function fingerprint_of(string $identity_b64): string {
		return substr(hash('sha256', (string)base64_decode($identity_b64, true)), 0, 16);
	}

	public function fingerprint(): string {
		return self::fingerprint_of((string)$this->get('dre_identity_key'));
	}

	private static function seal(string $locator, string $value): string {
		return (new SecretBox())->seal($locator, $value);
	}

	private static function open_sealed($sealed): ?string {
		if (!is_string($sealed) || $sealed === '') {
			return null;
		}
		$r = (new SecretBox())->open($sealed);
		return ($r['state'] ?? '') === 'ok' ? (string)$r['value'] : null;
	}

	/** Mint and keep a new escrow secret (32 random bytes); returns it raw. */
	public function mint_secret(): string {
		$secret = random_bytes(32);
		$this->set('dre_secret', self::seal(self::SECRET_LOCATOR, base64_encode($secret)));
		return $secret;
	}

	public function secret(): ?string {
		$b64 = self::open_sealed($this->get('dre_secret'));
		return $b64 === null ? null : base64_decode($b64, true);
	}

	public function set_report_key(string $raw): void {
		$this->set('dre_report_key', self::seal(self::REPORT_KEY_LOCATOR, base64_encode($raw)));
	}

	public function report_key(): ?string {
		$b64 = self::open_sealed($this->get('dre_report_key'));
		return $b64 === null ? null : base64_decode($b64, true);
	}

	public function set_pending_shared(?string $raw): void {
		if ($raw === null) {
			$this->set('dre_pending_shared', null);
			$this->set('dre_pending_shared_time', null);
			return;
		}
		$this->set('dre_pending_shared', self::seal(self::SHARED_LOCATOR, base64_encode($raw)));
		$this->set('dre_pending_shared_time', gmdate('Y-m-d H:i:s'));
	}

	/** A person's value from the holder's unlock page, while still fresh. */
	public function pending_shared(): ?string {
		$t = $this->get('dre_pending_shared_time');
		if (!$t || strtotime($t . ' UTC') < time() - self::SHARED_TTL_SECONDS) {
			return null;
		}
		$b64 = self::open_sealed($this->get('dre_pending_shared'));
		return $b64 === null ? null : base64_decode($b64, true);
	}

	private static function list_of($json): array {
		$v = json_decode((string)$json, true);
		return is_array($v) ? array_values(array_filter($v, 'is_string')) : array();
	}

	/** Every address it may ask from: stated, and seen on accepted requests. */
	public function allowed_addresses(): array {
		return array_values(array_unique(array_merge(self::list_of($this->get('dre_addresses')), self::list_of($this->get('dre_seen_addresses')))));
	}

	/** Valid addresses only, normalised, at most 32. */
	public static function clean_addresses($list): array {
		$out = array();
		foreach ((array)$list as $a) {
			if (!is_string($a) || IpAddress::binary(trim($a)) === null) {
				continue;
			}
			$out[] = inet_ntop(IpAddress::binary(trim($a)));
			if (count($out) >= 32) {
				break;
			}
		}
		return array_values(array_unique($out));
	}

	public function set_stated_addresses(array $list): void {
		$this->set('dre_addresses', json_encode(self::clean_addresses($list)));
	}

	public function note_seen_address(string $addr): void {
		if (IpAddress::binary($addr) === null) {
			return;
		}
		$seen = self::list_of($this->get('dre_seen_addresses'));
		if (!IpAddress::in($addr, $seen)) {
			$seen[] = inet_ntop(IpAddress::binary($addr));
			$this->set('dre_seen_addresses', json_encode(array_slice($seen, -16)));
		}
	}

	/**
	 * This management node moved a machine's address (IpSwapMove): the rows of
	 * node $node_id that state or have seen $from name $to instead, so the
	 * machine's next boot asks from an address it may, and whatever machine
	 * holds $from now does not inherit its standing. Calling it again changes
	 * nothing. Returns how many rows changed.
	 */
	public static function address_moved(int $node_id, string $from, string $to): int {
		if (!$node_id || IpAddress::binary($from) === null || IpAddress::binary($to) === null) {
			return 0;
		}
		$n = 0;
		foreach (new MultiDataRootEscrow(array('node' => $node_id, 'live' => true)) as $row) {
			$changed = false;
			foreach (array('dre_addresses', 'dre_seen_addresses') as $col) {
				$list = self::list_of($row->get($col));
				$out = array();
				foreach ($list as $a) {
					$moved = IpAddress::same($a, $from);
					$changed = $changed || $moved;
					$a = $moved ? $to : $a;
					if (!IpAddress::in($a, $out)) {
						$out[] = $a;
					}
				}
				$row->set($col, json_encode($out));
			}
			if ($changed) {
				$row->save();
				$n++;
			}
		}
		return $n;
	}

	public function address_allowed(string $addr): bool {
		return IpAddress::in($addr, $this->allowed_addresses());
	}

	/** Answered inside the hold, and not heard from since (D3). */
	public function answered_recently(): bool {
		$a = $this->get('dre_answered_time');
		if (!$a) {
			return false;
		}
		$at = strtotime($a . ' UTC');
		if ($at < time() - self::ANSWER_HOLD_SECONDS) {
			return false;
		}
		foreach (array('dre_unlocked_time', 'dre_shutdown_time') as $col) {
			$t = $this->get($col);
			if ($t && strtotime($t . ' UTC') >= $at) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The break-glass box in one form whichever way it arrived (the whole
	 * envelope from the machine, or its two fields from a host report): its
	 * recipient's fingerprint and the sealed value. '' when it is neither.
	 */
	public static function canonical_box(string $box): string {
		$v = json_decode($box, true);
		if (is_array($v) && isset($v['recipients'][0]) && is_array($v['recipients'][0])) {
			$v = $v['recipients'][0];
		}
		$fpr = (string)($v['fingerprint'] ?? '');
		$sealed = (string)($v['sealed'] ?? '');
		if (!preg_match('/^[0-9a-f]{64}$/', $fpr) || !preg_match('#^[A-Za-z0-9+/=]{60,400}$#', $sealed)) {
			return '';
		}
		return json_encode(array('fingerprint' => $fpr, 'sealed' => $sealed));
	}

	/**
	 * The machine is locked and asking right now: an unlock it asked for was
	 * refused in the last two minutes (a locked machine asks every ten
	 * seconds), and it has not reported itself unlocked since.
	 */
	public function is_asking_now(): bool {
		$asked = $this->get('dre_refusal_time');
		if (!$asked || (string)$this->get('dre_status') === 'retired') {
			return false;
		}
		$at = strtotime($asked . ' UTC');
		if ($at < time() - 120) {
			return false;
		}
		$unlocked = $this->get('dre_unlocked_time');
		return !$unlocked || strtotime($unlocked . ' UTC') < $at - 5;
	}

	public function note_box(string $box): void {
		$box = self::canonical_box($box);
		if ($box === '') {
			return;
		}
		$sum = hash('sha256', $box);
		$was = (string)$this->get('dre_box_sha256');
		if ($was !== '' && $was !== $sum) {
			$this->set('dre_box_changed_time', gmdate('Y-m-d H:i:s'));
		}
		$this->set('dre_box', $box);
		$this->set('dre_box_sha256', $sum);
	}

	public function refuse(string $reason, string $addr): void {
		$this->set('dre_refusal', $reason);
		$this->set('dre_refusal_time', gmdate('Y-m-d H:i:s'));
		$this->set('dre_refusal_address', $addr);
	}

	/**
	 * Whether this holder takes NEW enrolments: only where encrypting is
	 * switched on (data_root_encryption_offered). Until the release signing
	 * keys are rotated, no machine enrols anywhere: whoever holds the old keys
	 * could ship a release that reads every escrow secret out of the holder.
	 * Enrolments made already keep answering.
	 */
	public static function takes_new_enrolments(): bool {
		return DataRootEncryptionOffer::offered();
	}

	const NO_NEW_ENROLMENTS = 'This management node takes no new escrow enrolments yet: none is made anywhere until the release signing keys are rotated. Machines already enrolled here keep opening.';

	/** A person approves a waiting enrolment (C-3), after comparing fingerprints. */
	public function approve(int $user_id): void {
		if ((string)$this->get('dre_status') !== 'pending') {
			throw new DataRootEscrowException('Only an enrolment that is waiting can be approved.');
		}
		if (!self::takes_new_enrolments()) {
			throw new DataRootEscrowException(self::NO_NEW_ENROLMENTS);
		}
		$this->set('dre_status', 'approved');
		$this->set('dre_approved_time', gmdate('Y-m-d H:i:s'));
		$this->set('dre_approved_usr_user_id', $user_id);
		$this->save();
	}

	/**
	 * This holder no longer holds the machine's key: its secret and report key
	 * go, and it opens by its recovery key until it enrols again.
	 */
	public function retire(): void {
		// A key it held, given up: an escrow change its own backup must follow.
		// A request refused before any key was handed over is not one.
		if (trim((string)$this->get('dre_secret')) !== '') {
			$this->set('dre_retired_time', gmdate('Y-m-d H:i:s'));
		}
		$this->set('dre_status', 'retired');
		$this->set('dre_secret', null);
		$this->set('dre_report_key', null);
		$this->set_pending_shared(null);
		$this->save();
	}

	/**
	 * The value a person worked out in their browser on this holder's unlock
	 * page (D2b), kept sealed for the machine's next ask: the 32 bytes that
	 * open its break-glass box, and the public half of the key they came from,
	 * which must be the one the box is sealed to.
	 */
	public function accept_unlock_value(string $shared_b64, string $public_b64): void {
		$shared = base64_decode($shared_b64, true);
		$public = base64_decode($public_b64, true);
		$parts = $this->box_parts();
		if ($parts === null) {
			throw new DataRootEscrowException('This holder has no break-glass box for that machine to unlock it with.');
		}
		if ($shared === false || strlen($shared) !== 32 || $public === false || strlen($public) !== 32) {
			throw new DataRootEscrowException('That is not what this page sends.');
		}
		if (!hash_equals($parts['fingerprint'], hash('sha256', $public))) {
			throw new DataRootEscrowException('That recovery key is not the one this machine\'s data is sealed to.');
		}
		$this->set_pending_shared($shared);
		$this->save();
	}

	/** The break-glass envelope's recipient fingerprint and sealed box, for the holder's unlock page. */
	public function box_parts(): ?array {
		$r = json_decode((string)$this->get('dre_box'), true);
		if (!is_array($r) || empty($r['sealed'])) {
			return null;
		}
		$sealed = base64_decode((string)$r['sealed'], true);
		if ($sealed === false || strlen($sealed) <= 32) {
			return null;
		}
		return array('fingerprint' => (string)($r['fingerprint'] ?? ''), 'ephemeral' => base64_encode(substr($sealed, 0, 32)));
	}
}

class MultiDataRootEscrow extends SystemMultiBase {
	protected static $model_class = 'DataRootEscrow';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['identity_key'])) {
			$filters['dre_identity_key'] = array((string)$this->options['identity_key'], PDO::PARAM_STR);
		}
		if (isset($this->options['node'])) {
			$filters['dre_mgn_managed_node_id'] = array((int)$this->options['node'], PDO::PARAM_INT);
		}
		if (isset($this->options['status'])) {
			$filters['dre_status'] = array((string)$this->options['status'], PDO::PARAM_STR);
		}
		if (!empty($this->options['live'])) {
			$filters['dre_status'] = "<> 'retired'";
		}
		return $this->_get_resultsv2('dre_data_root_escrows', $filters, $this->order_by, $only_count, $debug);
	}
}
