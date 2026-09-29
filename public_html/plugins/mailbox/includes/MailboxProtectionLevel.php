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
 * A Fortress raise moves mail in its owner's window as the vault's deferred
 * work (MailboxFortressLevel), and a Fortress lowering is the owner's browser
 * moving keys back: neither converges in a request, so a scope at Fortress has
 * nothing pending here.
 *
 * Product rules stay with the editors that call change(): the sending lock
 * comes off first, a group mailbox stays Standard, the add-ons and AI consent
 * have their own gates, and a mailbox lowers before its grants sync and raises
 * after.
 *
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
 * What a mail scope converges once its promise has flipped: sealing at
 * Private, the caller's unseal at Standard, nothing in a request at Fortress.
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

	/** Re-read after a flip: the convergence follows the level it now promises. */
	protected function forgetConvergence(): void {
		$this->convergence = null;
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
