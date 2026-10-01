<?php
/**
 * Mail's side of ProtectionLevelChange: the two scopes whose level an admin
 * changes (a domain, and a mailbox pulled in over IMAP, which carries a level
 * of its own) and the two server-side convergences behind them.
 *
 *   MailboxSealConvergence   Private rows stored before the raise, sealed to
 *                            each mailbox's holder (public key only, so any
 *                            admin session drives it).
 *   MailboxUnsealConvergence rows on a scope that no longer seals, opened back
 *                            to plaintext — only the caller's own, in the
 *                            caller's window; other holders' rows converge in
 *                            theirs.
 *
 *   MailboxContactConvergence the mailbox's contact rows (imc_mailbox_contacts)
 *                            whose seal state disagrees with its posture, in
 *                            each adding user's window — both directions, because
 *                            the blind index is keyed only on a sealing mailbox
 *                            and is rewritten with the content. Driven by the
 *                            vault's deferred work (`mailbox_contact_level`).
 *
 * A Fortress raise moves mail in its owner's window as the vault's deferred
 * work (MailboxFortressLevel), and a Fortress lowering is the owner's browser
 * moving keys back: neither converges in a request, so a scope at Fortress has
 * nothing pending here. Contacts take the mailbox's level under Fortress too,
 * and stay server custody there (the Fortress card says so).
 *
 * Product rules stay with the editors that call change(): the sending lock
 * comes off first, a group mailbox stays Standard, the add-ons and AI consent
 * have their own gates, and a mailbox lowers before its grants sync and raises
 * after.
 *
 * @version 1.1 - MailboxContactConvergence: a level change takes the contacts with it
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protection_ceremony.php'));

/** Private rows not yet sealed, in one domain or one mailbox. */
class MailboxSealConvergence implements ProtectionLevelConvergence {

	private $domain;
	private $alias_scope_id;
	private $rows;
	private $vaults = array();
	private $router = null;

	public function __construct(InboundEmailDomain $domain, int $alias_scope_id = 0, int $rows = 200) {
		$this->domain = $domain;
		$this->alias_scope_id = $alias_scope_id;
		$this->rows = $rows;
	}

	public function pending(int $limit): array {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT m.iem_inbound_email_message_id, m.iem_iea_inbound_email_alias_id, m.iem_seal_attempt_time
			 FROM iem_inbound_email_messages m
			 " . mailbox_protection_posture_join() . "
			 WHERE m.iem_ied_inbound_email_domain_id = ?
			   AND m.iem_content_sealed = false AND m.iem_pending_parse = false
			   AND m.iem_delete_time IS NULL
			   -- Private only: Fortress mail moves in its owner's window (MailboxFortressLevel).
			   AND " . InboundEmailAlias::effectiveLevelSql('a', 'd') . " = '" . InboundEmailDomain::LEVEL_PRIVATE . "'"
			. mailbox_protection_alias_scope_sql($this->alias_scope_id, 'm') . "
			   AND (m.iem_seal_attempt_time IS NULL
			        OR m.iem_seal_attempt_time < NOW() AT TIME ZONE 'UTC' - INTERVAL '" . MailboxUnsealConvergence::RETRY_SECONDS . " seconds')
			 ORDER BY m.iem_inbound_email_message_id ASC LIMIT " . intval($limit));
		$stmt->execute(array(intval($this->domain->key)));
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/** Only unsealed rows are pending, and an unsealed row is nobody's browser's. */
	public function browserSealed($item): bool {
		return false;
	}

	/**
	 * Seal every content column the row holds to its holder — the same per-row
	 * work as delivery. A row belonging to no mailbox seals to the domain owner
	 * (specs/mailbox_unmatched_sealing.md); a row with nobody holding a vault
	 * to seal to is left (null) and stays counted in the backlog, which the
	 * Setup tab keeps loud.
	 */
	public function convertOne($item): ?int {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
		$alias_id = intval($item['iem_iea_inbound_email_alias_id']);
		if (!array_key_exists($alias_id, $this->vaults)) {
			// sealOwnerUserId() decides, so delivery and this backlog pass can
			// never disagree about whose key a message belongs under.
			$owner_id = InboundEmailMessage::sealOwnerUserId($alias_id ?: null, intval($this->domain->key));
			$this->vaults[$alias_id] = ($owner_id !== null) ? UserEncryptionVault::loadForUser($owner_id) : null;
		}
		$vault = $this->vaults[$alias_id];
		if ($vault === null) {
			return null;
		}
		$id = intval($item['iem_inbound_email_message_id']);
		$msg = new InboundEmailMessage($id, TRUE);
		if (!$msg->key) {
			return null;
		}
		try {
			$dek = InboundEmailMessage::sealExistingRow($msg, $vault);
		} catch (\Throwable $e) {
			// Stamped: passes take the rows behind it for a while (it stays counted).
			InboundEmailMessage::updateColumns($id, array('iem_seal_attempt_time' => gmdate('Y-m-d H:i:s')));
			throw $e;
		}
		if (!empty($item['iem_seal_attempt_time'])) {
			InboundEmailMessage::updateColumns($id, array('iem_seal_attempt_time' => null));
		}
		$raw = $msg->getRawMessage();
		if ($raw !== null && $raw !== '') {
			if ($this->router === null) {
				$this->router = new InboundEmailRouter();
			}
			$this->router->resealBackfillAttachments(intval($msg->key), $raw, $dek);
			$this->router->destroyRawAfterBackfill(intval($msg->key));
		}
		return strlen((string)$raw);
	}

	public function remaining(): int {
		return mailbox_protection_backlog_count(intval($this->domain->key), $this->alias_scope_id);
	}

	public function budget(): array {
		return array('rows' => $this->rows);
	}
}

/**
 * The caller's own sealed rows on a scope that no longer seals — a domain, one
 * mailbox, or ($domain null) everywhere. Unsealing needs the per-message DEK,
 * which unwraps only inside the sealed owner's window, so this converges only
 * rows sealed to the caller. The scope is asked of the MAILBOX
 * (specs/mailbox_connect_flow.md § D), so a still-Private pulled-in mailbox on
 * a lowered domain keeps its mail sealed while its neighbours converge.
 */
class MailboxUnsealConvergence implements ProtectionLevelConvergence {

	/** How long a pass passes by a row whose unseal failed before trying it again. */
	const RETRY_SECONDS = 3600;

	private $scope_sql;
	private $caller_user_id;
	private $rows;

	public function __construct(?InboundEmailDomain $domain, int $caller_user_id, int $alias_scope_id = 0, int $rows = 25) {
		// A scope that still seals yields no rows, so refusal is by construction
		// rather than by a guard somebody has to remember.
		$scope_sql = 'NOT (' . mailbox_protection_seals_sql() . ')';
		if ($domain !== null) {
			$scope_sql .= ' AND m.iem_ied_inbound_email_domain_id = ' . intval($domain->key);
		}
		$this->scope_sql = $scope_sql . mailbox_protection_alias_scope_sql($alias_scope_id, 'm');
		$this->caller_user_id = $caller_user_id;
		$this->rows = $rows;
	}

	public function pending(int $limit): array {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			"SELECT m.iem_inbound_email_message_id, m.iem_sealed_key FROM iem_inbound_email_messages m
			 " . mailbox_protection_posture_join() . "
			 WHERE " . $this->scope_sql . "
			   AND m.iem_content_sealed = true AND m.iem_pending_parse = false
			   AND m.iem_sealed_owner_user_id = ?
			   AND m.iem_delete_time IS NULL
			   -- A Fortress row reaches the server key through its owner's browser
			   -- first (JoinerySealed.changeCustody); until then it stays counted.
			   AND NOT " . InboundEmailMessage::mailKeySql('m.iem_sealed_key') . "
			   AND (m.iem_unseal_attempt_time IS NULL
			        OR m.iem_unseal_attempt_time < NOW() AT TIME ZONE 'UTC' - INTERVAL '" . self::RETRY_SECONDS . " seconds')
			 ORDER BY m.iem_inbound_email_message_id ASC LIMIT " . intval($limit));
		$stmt->execute(array($this->caller_user_id));
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	public function browserSealed($item): bool {
		return VaultCrypto::clientCustodyScope((string)$item['iem_sealed_key']) !== null;
	}

	/** A failure is stamped, so passes take the rows behind it for RETRY_SECONDS. */
	public function convertOne($item): ?int {
		$id = intval($item['iem_inbound_email_message_id']);
		$msg = new InboundEmailMessage($id, TRUE);
		if (!$msg->key) {
			return null;
		}
		try {
			if (InboundEmailMessage::unsealAndPersistContent($msg, $outcome)) {
				return 0;
			}
		} catch (\Throwable $e) {
			InboundEmailMessage::updateColumns($id, array('iem_unseal_attempt_time' => gmdate('Y-m-d H:i:s')));
			throw $e;
		}
		if ($outcome === 'locked') {
			throw new VaultLockedException();
		}
		if ($outcome === 'failed') {
			InboundEmailMessage::updateColumns($id, array('iem_unseal_attempt_time' => gmdate('Y-m-d H:i:s')));
			throw new RuntimeException('message ' . $id . ' could not be unsealed (logged above); tried again in '
				. intval(self::RETRY_SECONDS / 60) . ' minutes');
		}
		return null;
	}

	/** Caller-owned rows still sealed or waiting to be parsed. */
	public function remaining(): int {
		return $this->counts()['own'];
	}

	/** ['own' => caller's rows, 'others' => rows sealed to other holders]. */
	public function counts(): array {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			"SELECT
				COUNT(*) FILTER (WHERE m.iem_sealed_owner_user_id = ?) AS own,
				COUNT(*) FILTER (WHERE m.iem_sealed_owner_user_id IS DISTINCT FROM ?) AS others
			 FROM iem_inbound_email_messages m
			 " . mailbox_protection_posture_join() . "
			 WHERE " . $this->scope_sql . "
			   AND (m.iem_content_sealed = true OR m.iem_pending_parse = true)
			   AND m.iem_delete_time IS NULL");
		$stmt->execute(array($this->caller_user_id, $this->caller_user_id));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return array('own' => intval($row['own'] ?? 0), 'others' => intval($row['others'] ?? 0));
	}

	/** Batches are small: unsealing rewrites attachment bytes. */
	public function budget(): array {
		return array('rows' => $this->rows);
	}
}

/**
 * One member's contact rows whose seal state disagrees with their mailbox's
 * posture (specs/implemented/calendar_contacts_private.md § Contacts): plaintext rows on a
 * mailbox that seals, sealed rows on one that does not. A contact has no level
 * of its own — the row is Private exactly when its mailbox seals content — so
 * a mailbox's level change leaves these rows to converge, and this is what
 * converges them.
 *
 * Every conversion runs in the ADDING user's window, in both directions. The
 * content alone could be sealed with the public key, but the dedup digest
 * (imc_address_hash) is a keyed blind index only on a sealing mailbox and a
 * plain SHA-256 otherwise (MailboxContacts::addressHash()), so it changes with
 * the posture and is rewritten with the content: the keyed form needs the
 * user's index key, the plain form needs the decrypted address. A sealed row
 * beside a plain hash would hand an attacker with the database a dictionary
 * attack on the address the seal exists to hide, so the row moves whole.
 *
 * A row whose new digest collides with one the user added after the flip is
 * merged into it (use counts summed) and dropped: the later row already has
 * the right shape.
 */
class MailboxContactConvergence implements ProtectionLevelConvergence {

	/** How long a pass passes by a row whose conversion failed before trying it again. */
	const RETRY_SECONDS = 3600;

	/** The deferred-work consumer id (VaultDeferredWork::register). */
	const DEFERRED_WORK_ID = 'mailbox_contact_level';

	private $user_id;
	private $scope_sql;
	private $rows;

	/** @param ?InboundEmailDomain $domain one domain, or null for every mailbox the user holds contacts on */
	public function __construct(int $user_id, ?InboundEmailDomain $domain = null, int $alias_scope_id = 0, int $rows = 100) {
		$this->user_id = $user_id;
		$this->rows = $rows;
		$this->scope_sql = self::scopeSql($domain !== null ? intval($domain->key) : 0, $alias_scope_id);
	}

	/** The joins that put a contact row's mailbox posture in reach: its mailbox and that mailbox's domain. */
	public static function postureJoin(): string {
		return 'JOIN iea_inbound_email_aliases a ON a.iea_inbound_email_alias_id = c.imc_iea_inbound_email_alias_id
			 JOIN ied_inbound_email_domains d ON d.ied_inbound_email_domain_id = a.iea_ied_inbound_email_domain_id';
	}

	/** SQL over c/a/d: a row whose seal flag disagrees with its mailbox's posture, narrowed to a domain or a mailbox. */
	private static function scopeSql(int $domain_id, int $alias_scope_id): string {
		$sql = 'c.imc_content_sealed <> (' . mailbox_protection_seals_sql() . ')';
		if ($domain_id > 0) {
			$sql .= ' AND d.ied_inbound_email_domain_id = ' . $domain_id;
		}
		if ($alias_scope_id > 0) {
			$sql .= ' AND c.imc_iea_inbound_email_alias_id = ' . $alias_scope_id;
		}
		return $sql;
	}

	private static function readySql(): string {
		return "(c.imc_level_attempt_time IS NULL OR c.imc_level_attempt_time < NOW() AT TIME ZONE 'UTC' - INTERVAL '"
			. self::RETRY_SECONDS . " seconds')";
	}

	public function pending(int $limit): array {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT c.imc_mailbox_contact_id, (' . mailbox_protection_seals_sql() . ') AS seals
			 FROM imc_mailbox_contacts c ' . self::postureJoin() . '
			 WHERE c.imc_usr_user_id = ? AND ' . $this->scope_sql . ' AND ' . self::readySql() . '
			 ORDER BY c.imc_mailbox_contact_id ASC LIMIT ' . intval($limit));
		$stmt->execute(array($this->user_id));
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/** Contacts are server custody under every level: nothing here is a browser's. */
	public function browserSealed($item): bool {
		return false;
	}

	/**
	 * Move one row to its mailbox's posture, in the user's window. Throws
	 * VaultLockedException when the window is closed; any other failure is
	 * stamped so the next passes take the rows behind it.
	 */
	public function convertOne($item): ?int {
		$id = intval($item['imc_mailbox_contact_id']);
		$key = VaultUnlock::secretKey($this->user_id);
		if ($key === null) {
			throw new VaultLockedException();
		}
		try {
			return $this->convertRow($id, in_array($item['seals'], array(true, 't', 1, '1'), true), $key);
		} catch (VaultLockedException $e) {
			throw $e;
		} catch (\Throwable $e) {
			MailboxContact::updateColumns($id, array('imc_level_attempt_time' => gmdate('Y-m-d H:i:s')));
			throw $e;
		}
	}

	private function convertRow(int $id, bool $seal, VaultKey $key): ?int {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('SELECT * FROM imc_mailbox_contacts WHERE imc_mailbox_contact_id = ? AND imc_usr_user_id = ?');
		$stmt->execute(array($id, $this->user_id));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if (!$row) {
			return null;
		}
		$sealed_now = in_array($row['imc_content_sealed'], array(true, 't', 1, '1'), true);
		if ($sealed_now === $seal) {
			return null;   // already there (another pass, or a hand-add's re-stamp)
		}
		$alias_id = intval($row['imc_iea_inbound_email_alias_id']);
		$contacts = new MailboxContacts();

		if ($seal) {
			$vault = UserEncryptionVault::loadForUser($this->user_id);
			if ($vault === null) {
				// Nobody to seal to: a vault holder's mailbox was raised and the vault is
				// gone since. Stamped and left counted, as a mail row with no holder is.
				throw new RuntimeException('contact ' . $id . ': user ' . $this->user_id . ' holds no vault to seal to');
			}
			$addr = strtolower(trim((string)$row['imc_address']));
			$name = (string)$row['imc_display_name'];
			$index_key = MailboxContactIndexKey::openForUser($this->user_id, $vault, $key);
			$hash = $contacts->addressHash($addr, $index_key, $alias_id);
			if ($this->mergeInto($row, $hash)) {
				return 0;
			}
			// The digest first, the seal second: a crash between the two leaves a
			// plaintext row with the keyed digest, still pending, and the next pass
			// computes the same digest again. The other order would leave a sealed
			// row beside a plain digest, which no pass would revisit.
			MailboxContact::updateColumns($id, array('imc_address_hash' => $hash, 'imc_level_attempt_time' => null));
			MailboxContact::sealColumns($id, $vault, array(
				'imc_address'      => $addr,
				'imc_display_name' => $name,
			));
			return 0;
		}

		// Opening: the decrypt needs the sealing owner's window — this user's, since
		// a row seals to the user who added it. A row sealed to someone else is not
		// this user's to open and stays counted.
		$sealed_owner = intval($row['imc_sealed_owner_user_id'] ?? 0);
		if ($sealed_owner > 0 && $sealed_owner !== $this->user_id) {
			throw new RuntimeException('contact ' . $id . ' is sealed to user ' . $sealed_owner . ', not to its adding user');
		}
		$addr = strtolower(trim((string)MailboxContact::decryptSealedFieldStatic('imc_address', $row['imc_address'], $row)));
		$name = (string)MailboxContact::decryptSealedFieldStatic('imc_display_name', $row['imc_display_name'], $row);
		$hash = $contacts->addressHash($addr, null, $alias_id);
		if ($this->mergeInto($row, $hash)) {
			return 0;
		}
		// One UPDATE: content, digest and the seal columns move together.
		MailboxContact::updateColumns($id, array(
			'imc_address'              => $addr,
			'imc_display_name'         => $name,
			'imc_address_hash'         => $hash,
			'imc_content_sealed'       => false,
			'imc_sealed_key'           => null,
			'imc_sealed_owner_user_id' => null,
			'imc_key_generation'       => 0,
			'imc_level_attempt_time'   => null,
		));
		return 0;
	}

	/**
	 * When another row of this user already carries $hash (the same address
	 * added again after the flip, in the new shape), fold this row's use count
	 * into it and drop this row. True when merged.
	 */
	private function mergeInto(array $row, string $hash): bool {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('SELECT imc_mailbox_contact_id FROM imc_mailbox_contacts
			WHERE imc_usr_user_id = ? AND imc_address_hash = ? AND imc_mailbox_contact_id <> ? LIMIT 1');
		$stmt->execute(array($this->user_id, $hash, intval($row['imc_mailbox_contact_id'])));
		$other = intval($stmt->fetchColumn());
		if ($other <= 0) {
			return false;
		}
		$db->prepare('UPDATE imc_mailbox_contacts SET imc_use_count = imc_use_count + ?,
			imc_last_used_time = GREATEST(imc_last_used_time, ?) WHERE imc_mailbox_contact_id = ?')
			->execute(array(max(0, intval($row['imc_use_count'])), (string)$row['imc_last_used_time'], $other));
		$db->prepare('DELETE FROM imc_mailbox_contacts WHERE imc_mailbox_contact_id = ?')
			->execute(array(intval($row['imc_mailbox_contact_id'])));
		return true;
	}

	public function remaining(): int {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT COUNT(*) FROM imc_mailbox_contacts c ' . self::postureJoin() . '
			 WHERE c.imc_usr_user_id = ? AND ' . $this->scope_sql);
		$stmt->execute(array($this->user_id));
		return intval($stmt->fetchColumn());
	}

	public function budget(): array {
		return array('rows' => $this->rows);
	}

	/**
	 * Rows of EVERY user on a domain (or one mailbox) not at the posture — the
	 * receipt's fact: each converges in its adding user's own window.
	 */
	public static function backlogCount(int $domain_id, int $alias_scope_id = 0): int {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT COUNT(*) FROM imc_mailbox_contacts c ' . self::postureJoin() . '
			 WHERE ' . self::scopeSql($domain_id, $alias_scope_id));
		$stmt->execute();
		return intval($stmt->fetchColumn());
	}

	// ------------------------------------------------------ the deferred driver

	/** The deferred-work predicate: a row of $user_id's not at its mailbox's posture that a pass may take now. */
	public static function hasWork(int $user_id): bool {
		if ($user_id <= 0) {
			return false;
		}
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT 1 FROM imc_mailbox_contacts c ' . self::postureJoin() . '
			 WHERE c.imc_usr_user_id = ? AND ' . self::scopeSql(0, 0) . ' AND ' . self::readySql() . ' LIMIT 1');
		$stmt->execute(array($user_id));
		return (bool)$stmt->fetchColumn();
	}

	/** Converge $user_id's contacts until done, stuck, locked or out of time; returns the rows converted. */
	public static function drain(int $user_id, float $deadline): int {
		$convergence = new self($user_id);
		$done = 0;
		do {
			$pass = ProtectionLevelChange::convergeBatch($convergence, null, $deadline);
			$done += $pass['converted'];
		} while (!$pass['locked'] && $pass['converted'] > 0 && $pass['remaining'] > 0 && microtime(true) < $deadline);
		return $done;
	}
}

/**
 * What a mail scope converges once its promise has flipped: sealing at
 * Private, the caller's unseal at Standard, nothing in a request at Fortress.
 * The scope's contact rows converge beside it in each adding user's window
 * (MailboxContactConvergence, as the vault's deferred work).
 */
abstract class MailboxLevelScope implements ProtectionLevelScope {

	protected $domain;
	protected $actor_id;
	private $convergence = null;

	/** The mailbox this scope narrows to, or 0 for the whole domain. */
	abstract protected function aliasScopeId(): int;

	public function pending(int $limit): array {
		$c = $this->convergence();
		return $c ? $c->pending($limit) : array();
	}

	public function browserSealed($item): bool {
		$c = $this->convergence();
		return $c ? $c->browserSealed($item) : true;
	}

	public function convertOne($item): ?int {
		$c = $this->convergence();
		return $c ? $c->convertOne($item) : null;
	}

	public function remaining(): int {
		$c = $this->convergence();
		if ($c) {
			return $c->remaining();
		}
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxFortressLevel.php'));
		return MailboxFortressLevel::backlogCount($this->actor_id, intval($this->domain->key));
	}

	public function budget(): array {
		$c = $this->convergence();
		return $c ? $c->budget() : array('rows' => 1);
	}

	/**
	 * The raise's prerequisites (specs/mailbox_protection_ceremony.md): the
	 * checklist rows, evaluated per HOLDER — the sealing target — never the
	 * admin running the save. $addons are the add-ons newly switched on.
	 */
	protected function raiseBlocker(string $target, int $actor_id, array $addons = array()): ?string {
		if (ProtectionLevel::rank($target) <= ProtectionLevel::rank($this->currentLevel())
				|| $target === ProtectionLevel::STANDARD) {
			return null;
		}
		$rows = mailbox_protection_rows(mailbox_protection_facts($this->domain, $this->aliasScopeId()),
			$target, $actor_id, $addons);
		return mailbox_protection_required_ok($rows) ? null : mailbox_protection_first_failure($rows);
	}

	/**
	 * Re-read after a flip: the convergence follows the level it now promises,
	 * and the contacts' memoized posture is dropped so a read in this same
	 * request sees the new level.
	 */
	protected function forgetConvergence(): void {
		$this->convergence = null;
		MailboxContacts::forgetPosture();
	}

	private function convergence(): ?ProtectionLevelConvergence {
		if ($this->convergence === null) {
			$level = $this->currentLevel();
			if ($level === ProtectionLevel::PRIVATE_) {
				$this->convergence = new MailboxSealConvergence($this->domain, $this->aliasScopeId());
			} elseif ($level === ProtectionLevel::STANDARD) {
				$this->convergence = new MailboxUnsealConvergence($this->domain, $this->actor_id, $this->aliasScopeId());
			} else {
				$this->convergence = false;
			}
		}
		return $this->convergence ?: null;
	}
}

/** A mail domain: Standard, Private or Fortress. */
class MailboxDomainLevel extends MailboxLevelScope {

	private $addons;

	/** $addons: the add-ons this save newly switches on ('relay_seal', 'send_lock' => bool). */
	public function __construct(InboundEmailDomain $domain, int $actor_id, array $addons = array()) {
		$this->domain = $domain;
		$this->actor_id = $actor_id;
		$this->addons = $addons;
	}

	public function label(): string {
		return 'this domain';
	}

	public function levels(): array {
		return InboundEmailDomain::SETTABLE_LEVELS;
	}

	public function currentLevel(): string {
		return $this->domain->security_level();
	}

	public function blockers(string $target, int $actor_id): ?string {
		return $this->raiseBlocker($target, $actor_id, $this->addons);
	}

	/**
	 * Record the level and save the domain — with every other field the editor
	 * has already set on it, so a change lands in one write.
	 */
	public function flip(string $target): void {
		$this->domain->set_security_level($target);
		$this->domain->prepare();
		$this->domain->save();
		$this->forgetConvergence();
	}

	protected function aliasScopeId(): int {
		return 0;
	}
}

/**
 * A mailbox pulled in over IMAP, which carries its own level: Standard or
 * Private. (A hosted mailbox inherits its domain's.) Standard is stored
 * explicitly rather than as "inherit": a later change to the domain must not
 * silently reopen mail the operator chose to leave in the clear, or seal mail
 * they chose to leave readable.
 */
class MailboxAliasLevel extends MailboxLevelScope {

	private $alias;

	public function __construct(InboundEmailDomain $domain, InboundEmailAlias $alias, int $actor_id) {
		$this->domain = $domain;
		$this->alias = $alias;
		$this->actor_id = $actor_id;
	}

	public function label(): string {
		return 'this mailbox';
	}

	public function levels(): array {
		return array(ProtectionLevel::STANDARD, ProtectionLevel::PRIVATE_);
	}

	public function currentLevel(): string {
		return $this->alias->security_level();
	}

	public function blockers(string $target, int $actor_id): ?string {
		return $this->raiseBlocker($target, $actor_id);
	}

	public function flip(string $target): void {
		$this->alias->set('iea_security_level', $target);
		$this->alias->prepare();
		$this->alias->save();
		$this->alias->load();
		$this->forgetConvergence();
	}

	protected function aliasScopeId(): int {
		return intval($this->alias->key);
	}
}
?>
