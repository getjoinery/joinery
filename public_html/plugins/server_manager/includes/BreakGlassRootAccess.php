<?php
/**
 * BreakGlassRootAccess — the root password a fresh install keeps while Joinery
 * is in beta (specs/implemented/beta_break_glass_root_access.md section 3).
 *
 * One state, kept, between held and retiring. When the machine's agents are
 * admitted and the box may be kept, the password moves from SecretBox into
 * the holder's vault, with nobody present (keep()). When the holder clicks
 * Retire with their vault open, it moves back under SecretBox and the row
 * returns to held (retire()); from there the existing retire_install_password
 * pipeline does the rest, unchanged. Nothing removes a kept password
 * unattended.
 *
 * A leak of this management node's database and config does not reveal a
 * kept password: it is sealed to the holder's vault, not under SecretBox,
 * whose key sits in the config.
 *
 * @version 1.1 - break_glass_root_login_off: a machine whose root login its own agent turned off (disable_root_login,
 *                5.3), listed with the reveals and retires
 * @version 1.0
 */
class BreakGlassRootAccess {

	const SETTING        = 'server_manager_beta_root_access';
	const HOLDER_SETTING = 'server_manager_beta_root_access_holder';

	/** The general event log's events, one per Reveal and per Retire (VaultAudit's table). */
	const EVENT_REVEAL = 'break_glass_reveal';
	const EVENT_RETIRE = 'break_glass_retire';
	const EVENT_ROOT_LOGIN_OFF = 'break_glass_root_login_off';

	/** The locator the install executor opens the SecretBox copy by; any other is one it cannot read. */
	const SECRETBOX_LOCATOR = 'cvp_customer_cloud_provisions.cvp_root_pass_sealed';

	/** Is this management node keeping fresh installs' root passwords for the beta? */
	public static function beta_on(): bool {
		return (string)Globalvars::get_instance()->get_setting(self::SETTING, true, true) === '1';
	}

	/** Whose vault new kept passwords seal to; 0 when nobody is named. */
	public static function holder_id(): int {
		return max(0, (int)Globalvars::get_instance()->get_setting(self::HOLDER_SETTING, true, true));
	}

	/**
	 * Why this user cannot hold kept passwords, or null when they can: they
	 * have a vault, and a second factor, without which they could use neither
	 * Reveal nor Retire. A kept password nobody can retire is worse than none.
	 */
	public static function holder_unfit_reason(int $user_id): ?string {
		if ($user_id <= 0) {
			return 'no holder is named (' . self::HOLDER_SETTING . ')';
		}
		$user = new User($user_id, TRUE);
		if (!$user->key || $user->get('usr_delete_time')) {
			return "the holder (user #{$user_id}) does not exist";
		}
		$vault = UserEncryptionVault::loadForUser($user_id, UserEncryptionVault::SCOPE_USER);
		if (!$vault || !$vault->key) {
			return 'the holder (' . $user->display_name() . ') has no vault';
		}
		if (!SessionControl::get_instance()->user_has_second_factor($user)) {
			return 'the holder (' . $user->display_name() . ') has no second factor';
		}
		return null;
	}

	/**
	 * Does this install offer the owner the checkbox (4.1)? The beta setting
	 * is on, it is a fresh install (not bare, which has no site, nor a copy,
	 * whose site database is its source's), and no root keys were supplied:
	 * a box whose operator supplied keys already has its own way in.
	 */
	public static function offer_on_install($provision): bool {
		return self::beta_on()
			&& ((string)$provision->get('cvp_install_mode') ?: 'fresh') === 'fresh'
			&& trim((string)$provision->get('cvp_root_ssh_keys')) === '';
	}

	/** Has the site's owner turned emergency root access off, as its agent last reported? */
	public static function owner_asked_off($node): bool {
		return $node && $node->key && (string)$node->get('mgn_agent_root_access') === 'off';
	}

	/**
	 * Why this held box, whose agents are admitted, is retired rather than
	 * kept; null when it is kept (3.2).
	 */
	public static function keep_refusal($provision, $node): ?string {
		if (!self::beta_on()) {
			return 'emergency root access is off on this management node';
		}
		if (!(bool)$provision->get('cvp_root_access_offered')) {
			return 'the install did not offer it';
		}
		if (trim((string)$provision->get('cvp_root_ssh_keys')) !== '') {
			return 'root keys were supplied';
		}
		if (self::owner_asked_off($node)) {
			return 'the site\'s owner turned it off';
		}
		$unfit = self::holder_unfit_reason(self::holder_id());
		if ($unfit !== null) {
			return $unfit;
		}
		return null;
	}

	/**
	 * held -> kept: move the password from SecretBox into the holder's vault,
	 * with nobody present. Sealing needs only the vault's public key.
	 *
	 * The seal is checked from the database before the SecretBox copy goes:
	 * a save with no vault for the owner writes a sealed field as plaintext,
	 * so a row that does not read back sealed, to the holder, is not kept. Any
	 * throw or failed check falls back to held (fallback_to_held()), the
	 * password still under SecretBox and the vault column cleared, so the next
	 * pass retires the box as before.
	 *
	 * @return array{kept: bool, reason: string}
	 */
	public static function keep($provision): array {
		$id = (int)$provision->key;
		$holder = self::holder_id();
		try {
			$opened = (new SecretBox())->open((string)$provision->get('cvp_root_pass_sealed'));
			if (($opened['state'] ?? '') !== 'ok' || (string)$opened['value'] === '') {
				throw new CustomerCloudProvisionException('the install password could not be read from SecretBox');
			}
			$provision->set('cvp_sealed_owner_user_id', $holder);
			$provision->set('cvp_root_pass_vault_sealed', (string)$opened['value']);
			$provision->set('cvp_install_password', 'kept');
			$provision->save();

			$check = new CustomerCloudProvision($id, TRUE);
			$raw = self::raw_vault_column($id);
			if (!$check->rowIsSealed() || trim((string)$check->get('cvp_sealed_key')) === ''
					|| (int)$check->get('cvp_sealed_owner_user_id') !== $holder
					|| strpos((string)$raw, 'v1.aead.') !== 0) {
				throw new CustomerCloudProvisionException('the password did not read back sealed to the holder');
			}
			$check->set('cvp_root_pass_sealed', null);
			$check->set('cvp_error', null);
			$check->save();
		} catch (\Throwable $e) {
			return array('kept' => false, 'reason' => self::fallback_to_held($id, $e->getMessage()));
		}
		error_log('BreakGlassRootAccess: install password kept for provision #' . $id
			. ' (' . $provision->get('cvp_domain') . '), sealed to user #' . $holder);
		return array('kept' => true, 'reason' => '');
	}

	/**
	 * A keep that did not hold: one direct UPDATE, not through save(), because
	 * if the seal landed and a later step failed, save() would try to open the
	 * row's key to rewrite the field, and the sweep has no vault. The vault
	 * column and the four sealing columns are cleared (the copy the check
	 * rejected may be sitting there in the clear), the row returns to held
	 * with its SecretBox copy, and cvp_root_access_offered goes false so the
	 * next pass retires instead of trying again. Then the raw column is read
	 * back to confirm it is empty. Returns the reason recorded.
	 */
	private static function fallback_to_held(int $id, string $why): string {
		$reason = 'Not kept for the beta: ' . rtrim($why, '. ') . '. It is retired instead.';
		$db = DbConnector::get_instance()->get_db_link();
		$db->prepare("UPDATE cvp_customer_cloud_provisions SET cvp_root_pass_vault_sealed = NULL,
			cvp_content_sealed = false, cvp_sealed_key = NULL, cvp_sealed_owner_user_id = NULL, cvp_key_generation = 0,
			cvp_install_password = 'held', cvp_root_access_offered = false, cvp_error = ?, cvp_update_time = now()
			WHERE cvp_customer_cloud_provision_id = ?")->execute(array(mb_substr($reason, 0, 4000), $id));
		if (self::raw_vault_column($id) !== null) {
			error_log('BreakGlassRootAccess: provision #' . $id . ' still holds a value in cvp_root_pass_vault_sealed after the fallback');
		}
		error_log('BreakGlassRootAccess: provision #' . $id . ': ' . $reason);
		return $reason;
	}

	/** cvp_root_pass_vault_sealed as the database holds it, never opened; null when empty. */
	private static function raw_vault_column(int $id): ?string {
		$q = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT cvp_root_pass_vault_sealed FROM cvp_customer_cloud_provisions WHERE cvp_customer_cloud_provision_id = ?');
		$q->execute(array($id));
		$value = $q->fetchColumn();
		return ($value === false || $value === null || $value === '') ? null : (string)$value;
	}

	/**
	 * Why this viewer may not Reveal or Retire this row, or null when they may
	 * (3.5): the row is kept, the viewer is its holder, the holder's vault is
	 * open in this session, and the holder has a second factor and used it
	 * recently. 'stepup' => true when only the recent confirmation is missing,
	 * so the page can send them to confirm and back.
	 *
	 * @return array{refusal: ?string, stepup: bool}
	 */
	public static function holder_action_refusal($provision, $session): array {
		$deny = function (string $why) { return array('refusal' => $why, 'stepup' => false); };
		if ((string)$provision->get('cvp_install_password') !== 'kept') {
			return $deny('this box\'s root password is not kept');
		}
		$viewer = (int)$session->get_user_id();
		if ($viewer <= 0 || $viewer !== (int)$provision->get('cvp_sealed_owner_user_id')) {
			return $deny('only its holder can, and that is not you');
		}
		$user = new User($viewer, TRUE);
		if (!$session->user_has_second_factor($user)) {
			return $deny('your account needs a second factor (a passkey or an authenticator app) first');
		}
		if (VaultUnlock::secretKey($viewer) === null) {
			return $deny('unlock your vault first (the padlock at the top of the page)');
		}
		if (!$session->has_recent_second_factor()) {
			return array('refusal' => 'confirm your second factor first', 'stepup' => true);
		}
		return array('refusal' => null, 'stepup' => false);
	}

	/**
	 * Reveal: the kept password, read from the holder's open vault, and the
	 * read recorded. The caller has checked holder_action_refusal(). Opening
	 * it arms the sealed-content write rule for the rest of the request, so
	 * the caller shows it and does nothing else.
	 */
	public static function reveal($provision, $session): string {
		$password = (string)$provision->get('cvp_root_pass_vault_sealed');
		if ($password === '') {
			throw new CustomerCloudProvisionException('This row holds no kept password.');
		}
		self::log_event(self::EVENT_REVEAL, (int)$session->get_user_id(), $provision);
		return $password;
	}

	/**
	 * Retire: kept -> held, in one request with the vault open. The password
	 * is read (recorded as a reveal), sealed back under SecretBox with the
	 * locator the install executor opens it by, and the vault column cleared;
	 * cvp_root_access_offered goes false, which is what makes Retire one-way:
	 * the keep requires it, so the next pass retires instead of keeping again.
	 * The vault is open, so clearing the sealed field is an ordinary save.
	 */
	public static function retire($provision, $session): void {
		$password = self::reveal($provision, $session);
		$provision->set('cvp_root_pass_sealed', (new SecretBox())->seal(self::SECRETBOX_LOCATOR, $password));
		$provision->set('cvp_root_pass_vault_sealed', null);
		$provision->set('cvp_install_password', 'held');
		$provision->set('cvp_root_access_offered', false);
		$provision->set('cvp_error', null);
		$provision->save();
		self::log_event(self::EVENT_RETIRE, (int)$session->get_user_id(), $provision);
		error_log('BreakGlassRootAccess: provision #' . $provision->key . ' (' . $provision->get('cvp_domain')
			. ') returned to held by its holder, user #' . (int)$session->get_user_id() . '; the next pass retires it');
	}

	/**
	 * One row in the general event log: who, when, which box, never the
	 * password. The note names the provision and node and stays short: the
	 * request has opened sealed content, and the write rule refuses a long
	 * plaintext value.
	 */
	private static function log_event(string $event, int $user_id, $provision): void {
		$log = new EventLog(NULL);
		$log->set('evl_event', $event);
		$log->set('evl_usr_user_id', $user_id);
		$log->set('evl_was_success', true);
		$log->set('evl_note', self::event_note((int)$provision->key, (int)$provision->get('cvp_mgn_managed_node_id')));
		$log->save();
	}

	/**
	 * The machine's own agent turned root login off (disable_root_login), so
	 * its install password was retired without the retire job: who asked, as
	 * the job's creator, since a result is folded with no user session.
	 */
	public static function log_root_login_off(int $user_id, $provision): void {
		self::log_event(self::EVENT_ROOT_LOGIN_OFF, $user_id, $provision);
	}

	/** The note an event carries: 'provision #12, node #34'. */
	public static function event_note(int $provision_id, int $node_id): string {
		return 'provision #' . $provision_id . ', node #' . $node_id;
	}

	/**
	 * Every Reveal, Retire and agent turn-off recorded for one provision,
	 * newest first: [['event' => reveal|retire|root_login_off, 'time' => UTC,
	 * 'user' => name, 'label' => the line to show]].
	 */
	public static function events_for_provision(int $provision_id, int $limit = 20): array {
		$q = DbConnector::get_instance()->get_db_link()->prepare(
			"SELECT evl_event, evl_create_time, evl_usr_user_id FROM evl_event_logs
			 WHERE evl_event IN (?, ?, ?) AND (evl_note = ? OR evl_note LIKE ?)
			 ORDER BY evl_create_time DESC LIMIT " . max(1, (int)$limit));
		$prefix = 'provision #' . $provision_id;
		$q->execute(array(self::EVENT_REVEAL, self::EVENT_RETIRE, self::EVENT_ROOT_LOGIN_OFF, $prefix, $prefix . ',%'));
		$out = array();
		$names = array();
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$uid = (int)$row['evl_usr_user_id'];
			if (!isset($names[$uid])) {
				$u = new User($uid, TRUE);
				$names[$uid] = $u->key ? $u->display_name() : "user #{$uid}";
			}
			$event = array(self::EVENT_RETIRE => 'retire', self::EVENT_ROOT_LOGIN_OFF => 'root_login_off')[$row['evl_event']] ?? 'reveal';
			$out[] = array(
				'event' => $event,
				'time'  => (string)$row['evl_create_time'],
				'user'  => $names[$uid],
				'label' => array('retire' => 'Retired', 'root_login_off' => 'Root login turned off by the agent', 'reveal' => 'Revealed')[$event]
					. ($uid > 0 ? ' by ' . $names[$uid] : ''),
			);
		}
		return $out;
	}

	/** Every kept row, newest first. */
	public static function kept_rows(): array {
		$rows = new MultiCustomerCloudProvision(array('install_password_states' => array('kept'), 'deleted' => false),
			array('cvp_customer_cloud_provision_id' => 'DESC'));
		return iterator_to_array($rows, false);
	}

	/** A kept row's node, or null when the record is gone. */
	public static function live_node($provision) {
		$node_id = (int)$provision->get('cvp_mgn_managed_node_id');
		$node = $node_id ? new ManagedNode($node_id, TRUE) : null;
		return ($node && $node->key && !$node->get('mgn_delete_time')) ? $node : null;
	}

	/**
	 * Why a kept box needs its holder, or an empty list (3.7): its owner asked
	 * for removal, its node is gone, or it is sealed to someone other than the
	 * current holder, so it is retired rather than forgotten.
	 */
	public static function attention_reasons($provision, int $current_holder): array {
		$reasons = array();
		$node = self::live_node($provision);
		if ($node === null) {
			$reasons[] = 'the node record is gone';
		} elseif (self::owner_asked_off($node)) {
			$reasons[] = 'the owner asked to remove it';
		}
		if ((int)$provision->get('cvp_sealed_owner_user_id') !== $current_holder) {
			$reasons[] = 'sealed to user #' . (int)$provision->get('cvp_sealed_owner_user_id') . ', not the current holder';
		}
		return $reasons;
	}

	/** The holder's name for a summary line. */
	public static function holder_name(int $user_id): string {
		if ($user_id <= 0) {
			return 'nobody';
		}
		$u = new User($user_id, TRUE);
		return $u->key ? $u->display_name() : "user #{$user_id}";
	}
}
