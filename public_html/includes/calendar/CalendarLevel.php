<?php
/**
 * CalendarLevel — one member's personal calendar as a ProtectionLevelChange
 * scope: Standard <-> Private (docs/calendar.md § Protection level).
 *
 * The level lives on the member's CalendarPreference row
 * (cpr_protection_level); a member with no row is Standard. Private seals
 * every entry's title, location, link and notes to the member's vault
 * (CalendarEntry::$sealed_fields); the times and everything else that
 * describes the schedule stay plaintext.
 *
 * The level flips first. Every entry carries its own seal flag and every
 * reader keys on the row, so a calendar whose promise has moved reads
 * correctly while its older rows catch up, and an entry written after the
 * flip already lands at the new level (CalendarEntry::shouldSeal() reads the
 * level fresh). The rows converge afterwards in bounded passes of BATCH_ROWS:
 * raising seals with the owner's public key (no window), lowering decrypts and
 * so needs the owner's window (ProtectionLevelChange rule 4). The page's batch
 * loop (calendar_level_batch) is the fast path; the vault's deferred work
 * (consumer `calendar_level`, includes/calendar/CalendarSealed.php) finishes a
 * change the page did not, in the owner's next window.
 *
 * The static half answers the per-row policy the model asks at write time:
 * levelFor() / sealsFor(), memoized per request and forgotten on flip().
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/ProtectionLevel.php'));
require_once(PathHelper::getIncludePath('includes/ProtectionLevelChange.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('data/calendar_preferences_class.php'));
require_once(PathHelper::getIncludePath('data/entries_class.php'));

class CalendarLevel implements ProtectionLevelScope {

	/** The rungs a calendar offers: no add-ons, no Fortress (docs/calendar.md). */
	const LEVELS = array(ProtectionLevel::STANDARD, ProtectionLevel::PRIVATE_);

	/** Rows one converge pass takes at most. */
	const BATCH_ROWS = 50;

	/** How long a pass passes by a row whose conversion failed before trying it again. */
	const RETRY_SECONDS = 3600;

	/** The deferred-work consumer id (VaultDeferredWork::register). */
	const DEFERRED_WORK_ID = 'calendar_level';

	/** @var array<int,string> request-scoped: user id => level */
	private static $levels = array();

	private $user_id;

	public function __construct(int $user_id) {
		$this->user_id = $user_id;
	}

	// ------------------------------------------------------------- the policy

	/** The member's calendar level, memoized per request. */
	public static function levelFor(int $user_id): string {
		if ($user_id <= 0) {
			return ProtectionLevel::STANDARD;
		}
		if (!array_key_exists($user_id, self::$levels)) {
			self::$levels[$user_id] = CalendarPreference::get_for($user_id)->protection_level();
		}
		return self::$levels[$user_id];
	}

	/** Forget a memoized level (after a flip, or in a test that writes the row directly). */
	public static function forget(?int $user_id = null): void {
		if ($user_id === null) {
			self::$levels = array();
		} else {
			unset(self::$levels[$user_id]);
		}
	}

	/** Does this member hold the server-custody vault a Private calendar seals to? */
	public static function ownerHasVault(int $user_id): bool {
		return $user_id > 0 && UserEncryptionVault::loadForUser($user_id, UserEncryptionVault::SCOPE_USER) !== null;
	}

	/**
	 * Does content written to this member's calendar seal? Private AND a vault
	 * to seal to — the same rule chat uses. A Private calendar whose owner has
	 * lost their vault stores plaintext (the vault contract: an item with no
	 * resolvable owner is stored in the clear) and blockers() refuses the
	 * level until a vault exists.
	 */
	public static function sealsFor(int $user_id): bool {
		return self::levelFor($user_id) === ProtectionLevel::PRIVATE_ && self::ownerHasVault($user_id);
	}

	// -------------------------------------------------------------- the scope

	public function label(): string {
		return 'your calendar';
	}

	public function levels(): array {
		return self::LEVELS;
	}

	public function currentLevel(): string {
		return self::levelFor($this->user_id);
	}

	/** Private needs a vault to seal to. */
	public function blockers(string $target, int $actor_id): ?string {
		if ($target === ProtectionLevel::PRIVATE_ && !self::ownerHasVault($this->user_id)) {
			return 'Set up your encryption vault first (in your security settings) to make your calendar private.';
		}
		return null;
	}

	/** Record the level on the preference row (created when the member has none). */
	public function flip(string $target): void {
		$pref = CalendarPreference::get_for($this->user_id);
		$pref->set('cpr_protection_level', $target);
		$pref->set('cpr_update_time', gmdate('Y-m-d H:i:s'));
		$pref->save();
		self::forget($this->user_id);
	}

	// ----------------------------------------------------------- the converge

	/** SQL over `cal_entries` columns: a live row of this member whose seal flag disagrees with $sealing. */
	private static function backlogSql(bool $sealing, bool $ready): string {
		return "cal_subject_type = '" . CalendarSubject::TYPE_USER . "' AND cal_subject_id = ?
			AND cal_delete_time IS NULL AND cal_content_sealed = " . ($sealing ? 'false' : 'true')
			. ($ready ? ' AND ' . self::readySql('cal_level_attempt_time') : '');
	}

	/** SQL: a row a pass may take now — it has not failed within RETRY_SECONDS. */
	private static function readySql(string $column): string {
		return '(' . $column . ' IS NULL OR ' . $column . " < NOW() AT TIME ZONE 'UTC' - INTERVAL '"
			. self::RETRY_SECONDS . " seconds')";
	}

	private function sealing(): bool {
		return $this->currentLevel() === ProtectionLevel::PRIVATE_;
	}

	public function pending(int $limit): array {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT cal_entry_id FROM cal_entries WHERE ' . self::backlogSql($this->sealing(), true)
			. ' ORDER BY cal_entry_id ASC LIMIT ' . max(0, intval($limit)));
		$stmt->execute(array($this->user_id));
		$items = array();
		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
			$items[] = (int)$id;
		}
		return $items;
	}

	/** The calendar is server custody: nothing here is sealed for a browser. */
	public function browserSealed($item): bool {
		return false;
	}

	/**
	 * Seal one entry's content under a fresh DEK (public key only), or open it
	 * back to plaintext in the owner's window. A failure other than a closed
	 * window is stamped so the next passes take the rows behind it.
	 */
	public function convertOne($item): ?int {
		$entry = new CalendarEntry((int)$item, TRUE);
		if (!$entry->key) {
			return null;
		}
		try {
			$done = self::convertEntry($entry, $this->sealing());
		} catch (VaultLockedException $e) {
			throw $e;
		} catch (Throwable $e) {
			CalendarEntry::updateColumns((int)$entry->key, array('cal_level_attempt_time' => gmdate('Y-m-d H:i:s')));
			throw $e;
		}
		if ($entry->get('cal_level_attempt_time')) {
			CalendarEntry::updateColumns((int)$entry->key, array('cal_level_attempt_time' => null));
		}
		return $done;
	}

	/**
	 * Move one loaded entry to $sealing. Null when it is already there.
	 * Sealing reads the row's plaintext (an unsealed row's get() is a plain
	 * read) and writes ciphertext under a fresh DEK; opening reads through
	 * get(), which decrypts in-window or throws VaultLockedException, and
	 * writes plaintext back with the seal columns cleared in one UPDATE.
	 */
	public static function convertEntry(CalendarEntry $entry, bool $sealing): ?int {
		if ($entry->rowIsSealed() === $sealing) {
			return null;
		}
		$content = array(
			'cal_title'    => $entry->get('cal_title'),
			'cal_location' => $entry->get('cal_location'),
			'cal_link'     => $entry->get('cal_link'),
			'cal_notes'    => $entry->get('cal_notes'),
		);
		if ($sealing) {
			$owner_id = (int)$entry->get('cal_subject_id');
			$vault = UserEncryptionVault::loadForUser($owner_id, UserEncryptionVault::SCOPE_USER);
			if ($vault === null) {
				throw new RuntimeException('CalendarLevel: owner ' . $owner_id . ' has no vault; the entry cannot be sealed.');
			}
			CalendarEntry::sealColumns((int)$entry->key, $vault, $content);
			return 0;
		}
		CalendarEntry::updateColumns((int)$entry->key, $content + array(
			'cal_content_sealed'       => false,
			'cal_sealed_key'           => null,
			'cal_sealed_owner_user_id' => null,
			'cal_key_generation'       => 0,
		));
		return 0;
	}

	/**
	 * Before a content edit of an existing entry: a row still sealed on a
	 * calendar that has been lowered is opened first (its owner's window is
	 * needed, as for any read of it), so the edit lands as plaintext instead of
	 * being left aside by save()'s sealed-row rule. A plaintext row on a
	 * calendar raised to Private needs nothing here: save() seals the whole row
	 * on its first sealed write.
	 *
	 * @throws VaultLockedException when the row is sealed and the owner's window is closed
	 */
	public static function settleBeforeEdit(CalendarEntry $entry): void {
		if (!$entry->key || !$entry->rowIsSealed()) {
			return;
		}
		if ($entry->get('cal_subject_type') !== CalendarSubject::TYPE_USER) {
			return;
		}
		if (self::levelFor((int)$entry->get('cal_subject_id')) === ProtectionLevel::PRIVATE_) {
			return;
		}
		self::convertEntry($entry, false);
		$entry->load();
	}

	public function remaining(): int {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT COUNT(*) FROM cal_entries WHERE ' . self::backlogSql($this->sealing(), false));
		$stmt->execute(array($this->user_id));
		return (int)$stmt->fetchColumn();
	}

	public function budget(): array {
		return array('rows' => self::BATCH_ROWS);
	}

	// ------------------------------------------------------ the deferred driver

	/** The deferred-work predicate: a row of $owner's not at the promise that a pass may take now. */
	public static function hasWork(int $owner_id): bool {
		if ($owner_id <= 0) {
			return false;
		}
		$sealing = (self::levelFor($owner_id) === ProtectionLevel::PRIVATE_);
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT 1 FROM cal_entries WHERE ' . self::backlogSql($sealing, true) . ' LIMIT 1');
		$stmt->execute(array($owner_id));
		return (bool)$stmt->fetchColumn();
	}

	/** Converge $owner's calendar until done, stuck, locked or out of time; returns the rows converted. */
	public static function drain(int $owner_id, float $deadline): int {
		$scope = new self($owner_id);
		$done = 0;
		do {
			$pass = ProtectionLevelChange::convergeBatch($scope, null, $deadline);
			$done += $pass['converted'];
		} while (!$pass['locked'] && $pass['converted'] > 0 && $pass['remaining'] > 0 && microtime(true) < $deadline);
		return $done;
	}
}
?>
