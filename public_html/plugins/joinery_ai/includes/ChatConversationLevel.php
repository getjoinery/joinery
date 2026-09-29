<?php
/**
 * ChatConversationLevel — one AI chat as a ProtectionLevelChange scope:
 * Standard <-> Private.
 *
 * The level flips first. Every row carries its own seal flag and every reader
 * keys on the row, so a chat whose promise has moved reads correctly while its
 * older rows catch up — and a message written after the flip already lands at
 * the new level (AiConversationMessage::shouldSeal() reads the level fresh).
 * The rows converge afterwards in bounded batches: the conversation's own
 * title/instructions, then each message with its attachments
 * (ChatSeal::sealExistingMessage() / unsealExistingMessage()).
 *
 * Product rules stay with ChatLevel::changeLevel(): only the owner, and not
 * while a reply is being written.
 *
 * @version 1.1 - the backlog counts a sealed turn whose attachments did not follow and
 *   leaves a running turn to its finalize; hasWork()/drain() finish a change the page
 *   did not, as the vault's deferred work
 * @version 1.0
 */
class ChatConversationLevel implements ProtectionLevelScope {

	/** Items a converge pass takes at most: each message may carry attachments. */
	const BATCH_ROWS = 25;

	/** The pending item that stands for the conversation row itself. */
	const CONVERSATION_ITEM = 'conversation';

	private $conversation;

	public function __construct(AiConversation $conversation) {
		$this->conversation = $conversation;
	}

	public function label(): string {
		return 'this chat';
	}

	public function levels(): array {
		return ChatSeal::levels();
	}

	public function currentLevel(): string {
		return $this->conversation->level();
	}

	/**
	 * Private needs a vault to seal to. Raising restores a Local models only
	 * add-on the chat already carries (the flag is one-way and stays stored
	 * while the chat is Standard), so that raise also needs a local model.
	 */
	public function blockers(string $target, int $actor_id): ?string {
		if (!ChatSeal::isProtectedLevel($target)) {
			return null;
		}
		if (!ChatSeal::ownerHasVault((int)$this->conversation->get('aic_owner_user_id'))) {
			return 'Set up your encryption vault first (in your security settings) to make a chat private.';
		}
		if ($this->conversation->localModelsOnlyFlag() && !ChatLevel::localModelConfigured()) {
			return 'This chat is set to Local models only — configure a local model in Joinery AI settings to make it Private again.';
		}
		return null;
	}

	/**
	 * Record the level. An unconverted legacy row is rewritten in the current
	 * shape, so the add-on it implied is never lost; a raise that restores the
	 * Local models only add-on re-pins the chat's model to a local one.
	 */
	public function flip(string $target): void {
		$cols = array('aic_security_level' => $target);
		if ((string)$this->conversation->get('aic_security_level') === AiConversation::LEGACY_LEVEL_LOCAL_ONLY) {
			$cols['aic_local_models_only'] = true;
		}
		if (ChatSeal::isProtectedLevel($target) && $this->conversation->localModelsOnlyFlag()
				&& !ChatLevel::isLocalModel((string)$this->conversation->get('aic_model'))) {
			$cols['aic_model'] = ChatLevel::localDefaultModel();
		}
		AiConversation::updateColumns((int)$this->conversation->key, $cols);
		foreach ($cols as $col => $value) {
			$this->conversation->set($col, $value);
		}
	}

	/**
	 * The messages not at the promise, as SQL over `m`: when sealing, a row not
	 * yet sealed or a sealed row with an attachment that did not follow; when
	 * opening, a row still sealed (its attachments open with it, first). A turn
	 * still being written is left to its finalize, which seals it itself.
	 */
	public static function messageBacklogSql(bool $sealing, bool $ready = false): string {
		$attachment = 'EXISTS (SELECT 1 FROM aia_message_attachments a
			WHERE a.aia_aim_conversation_message_id = m.aim_conversation_message_id
			  AND a.aia_delete_time IS NULL AND a.aia_sealed = false)';
		return "m.aim_delete_time IS NULL AND m.aim_status <> '" . AiConversationMessage::STATUS_RUNNING . "' AND "
			. ($sealing ? '(m.aim_content_sealed = false OR ' . $attachment . ')' : 'm.aim_content_sealed = true')
			. ($ready ? ' AND ' . self::readySql('m.aim_level_attempt_time') : '');
	}

	/** How long a pass passes by a row whose conversion failed before trying it again. */
	const RETRY_SECONDS = 3600;

	/**
	 * SQL: a row a pass may take now — it has not failed within RETRY_SECONDS.
	 * A row that keeps failing stays counted but stops holding up the rows
	 * behind it, and stops waking the vault's deferred work every few seconds.
	 */
	private static function readySql(string $column): string {
		return '(' . $column . ' IS NULL OR ' . $column . " < NOW() AT TIME ZONE 'UTC' - INTERVAL '"
			. self::RETRY_SECONDS . " seconds')";
	}

	public function pending(int $limit): array {
		$sealing = $this->sealing();
		$items = array();
		if ($this->conversationRowSealed() !== $sealing && $this->conversationRowReady()) {
			$items[] = self::CONVERSATION_ITEM;
		}
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT m.aim_conversation_message_id FROM aim_conversation_messages m
			 WHERE m.aim_aic_conversation_id = ? AND ' . self::messageBacklogSql($sealing, true) . '
			 ORDER BY m.aim_conversation_message_id ASC LIMIT ' . max(0, intval($limit) - count($items)));
		$stmt->execute(array((int)$this->conversation->key));
		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
			$items[] = (int)$id;
		}
		return $items;
	}

	/** Chat is server custody: nothing here is sealed for a browser. */
	public function browserSealed($item): bool {
		return false;
	}

	/** Sealing needs only the public key; unsealing decrypts, so it needs the owner's window. */
	public function convertOne($item): ?int {
		$db = DbConnector::get_instance()->get_db_link();
		$conversation_row = ($item === self::CONVERSATION_ITEM);
		$stamp = $conversation_row
			? array('aic_conversations', 'aic_level_attempt_time', 'aic_conversation_id', (int)$this->conversation->key)
			: array('aim_conversation_messages', 'aim_level_attempt_time', 'aim_conversation_message_id', (int)$item);
		try {
			if ($conversation_row) {
				$done = $this->convertConversationRow();
			} else {
				$msg = new AiConversationMessage((int)$item, true);
				if (!$msg->key) {
					return null;
				}
				if ($this->sealing()) {
					ChatSeal::sealExistingMessage($msg, $this->conversation);
				} else {
					ChatSeal::unsealExistingMessage($msg);
				}
				$done = 0;
			}
		} catch (VaultLockedException $e) {
			throw $e;
		} catch (Throwable $e) {
			$db->prepare('UPDATE ' . $stamp[0] . ' SET ' . $stamp[1] . " = NOW() AT TIME ZONE 'UTC' WHERE " . $stamp[2] . ' = ?')
				->execute(array($stamp[3]));
			throw $e;
		}
		$db->prepare('UPDATE ' . $stamp[0] . ' SET ' . $stamp[1] . ' = NULL WHERE ' . $stamp[2] . ' = ? AND ' . $stamp[1] . ' IS NOT NULL')
			->execute(array($stamp[3]));
		return $done;
	}

	public function remaining(): int {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT COUNT(*) FROM aim_conversation_messages m
			 WHERE m.aim_aic_conversation_id = ? AND ' . self::messageBacklogSql($this->sealing()));
		$stmt->execute(array((int)$this->conversation->key));
		return (int)$stmt->fetchColumn() + ($this->conversationRowSealed() !== $this->sealing() ? 1 : 0);
	}

	// ------------------------------------------------------ the deferred driver

	/**
	 * SQL: $owner's chats with a row not at its promise that a pass may take now
	 * (a row that failed recently waits out RETRY_SECONDS). A chat whose
	 * level change stopped part-way (the tab closed after the first pass) is
	 * finished by the vault's deferred work in its owner's window, as a mail
	 * raise is — the page's own batch loop is only the fast path.
	 */
	private static function unconvergedSql(): string {
		$protected = "c.aic_security_level IN ('" . AiConversation::LEVEL_PRIVATE . "','"
			. AiConversation::LEGACY_LEVEL_LOCAL_ONLY . "')";
		$row_ready = self::readySql('c.aic_level_attempt_time');
		return "FROM aic_conversations c
			WHERE c.aic_owner_user_id = ? AND c.aic_delete_time IS NULL AND (
			  ($protected AND ((c.aic_content_sealed = false AND $row_ready) OR EXISTS (SELECT 1 FROM aim_conversation_messages m
			     WHERE m.aim_aic_conversation_id = c.aic_conversation_id AND " . self::messageBacklogSql(true, true) . ")))
			  OR (NOT $protected AND ((c.aic_content_sealed = true AND $row_ready) OR EXISTS (SELECT 1 FROM aim_conversation_messages m
			     WHERE m.aim_aic_conversation_id = c.aic_conversation_id AND " . self::messageBacklogSql(false, true) . "))))";
	}

	/** The deferred-work predicate. */
	public static function hasWork(int $owner_id): bool {
		if ($owner_id <= 0) return false;
		$stmt = DbConnector::get_instance()->get_db_link()->prepare('SELECT 1 ' . self::unconvergedSql() . ' LIMIT 1');
		$stmt->execute(array($owner_id));
		return (bool)$stmt->fetchColumn();
	}

	/** Converge $owner's unfinished chats until $deadline; returns the rows converted. */
	public static function drain(int $owner_id, float $deadline): int {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT c.aic_conversation_id ' . self::unconvergedSql() . ' ORDER BY c.aic_conversation_id LIMIT 20');
		$stmt->execute(array($owner_id));
		$done = 0;
		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cid) {
			if (microtime(true) >= $deadline) break;
			$c = new AiConversation((int)$cid, true);
			if (!$c->key) continue;
			$scope = new self($c);
			// Pass after pass on this scope until it is done, stuck, or out of time.
			do {
				$pass = ProtectionLevelChange::convergeBatch($scope, null, $deadline);
				$done += $pass['converted'];
			} while (!$pass['locked'] && $pass['converted'] > 0 && $pass['remaining'] > 0 && microtime(true) < $deadline);
			if ($pass['locked']) break;
		}
		return $done;
	}

	public function budget(): array {
		return array('rows' => self::BATCH_ROWS);
	}

	private function sealing(): bool {
		return ChatSeal::isProtectedLevel($this->currentLevel());
	}

	/** The conversation row has not failed to convert within RETRY_SECONDS. */
	private function conversationRowReady(): bool {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT 1 FROM aic_conversations WHERE aic_conversation_id = ? AND ' . self::readySql('aic_level_attempt_time'));
		$stmt->execute(array((int)$this->conversation->key));
		return (bool)$stmt->fetchColumn();
	}

	/** The conversation row's seal flag as the table holds it now. */
	private function conversationRowSealed(): bool {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT aic_content_sealed FROM aic_conversations WHERE aic_conversation_id = ?');
		$stmt->execute(array((int)$this->conversation->key));
		return in_array($stmt->fetchColumn(), array(true, 't', 1, '1'), true);
	}

	/** Seal the title/instructions under a fresh DEK, or open them back to plaintext in window. */
	private function convertConversationRow(): ?int {
		$c = new AiConversation((int)$this->conversation->key, true);
		if (!$c->key) {
			return null;
		}
		if ($this->sealing()) {
			if ($c->get('aic_content_sealed')) {
				return null;
			}
			AiConversation::sealColumns((int)$c->key, ChatSeal::requireVault($c), array(
				'aic_title'        => (string)$c->get('aic_title'),
				'aic_instructions' => (string)$c->get('aic_instructions'),
			));
			return 0;
		}
		if (!$c->get('aic_content_sealed')) {
			return null;
		}
		$title = (string)$c->get('aic_title');           // get() decrypts in-window
		$instr = (string)$c->get('aic_instructions');
		AiConversation::updateColumns((int)$c->key, array(
			'aic_title'          => $title,
			'aic_instructions'   => $instr,
			'aic_content_sealed' => false,
			'aic_sealed_key'     => null,
			'aic_sealed_owner_user_id' => null,
			'aic_key_generation' => 0,
		));
		return 0;
	}
}
?>
