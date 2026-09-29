<?php
/**
 * ProtectionLevelChange — the one sequence for changing what a scope of member
 * content promises: a Drive folder tree, a mail domain or mailbox, a chat.
 *
 * It owns the order and the security rules, nothing else:
 *
 *   1. The target is a rung of ProtectionLevel::ORDER that this scope offers,
 *      and the scope's current rung is one it can move from here. A Fortress
 *      rung a scope does not list is its browser's to move, never the server's.
 *   2. A change to existing content needs a recent second factor. That is
 *      enforced for an account that HAS a second factor
 *      (SessionControl::step_up_outstanding()): an account without one has
 *      nothing to step up with, so the gate passes, as it does everywhere on
 *      the platform.
 *   3. The scope's prerequisites, asked at call time and never earlier: a
 *      consumer whose prerequisites depend on writes it makes first (a mail
 *      mailbox's holders, after its grants sync) calls change() after them.
 *   4. Ending sealing (a change to Standard) needs the acting user's unlock
 *      window when the acting user holds a vault. Rows sealed to other holders
 *      converge in those holders' own windows; the actor's window opens only
 *      what is sealed to the actor.
 *   5. The promise flips FIRST, so everything written from that moment lands at
 *      the new level, and the backlog still to converge is reported.
 *   6. Stored content converges afterwards in bounded batches — convergeBatch(),
 *      driven by a request (a receipt's batch loop) or by the vault's deferred
 *      work. One item that fails is logged and left in the backlog; a closed
 *      window stops the pass cleanly; an item sealed for the browser is never
 *      converted server-side.
 *
 * Product rules — Drive's sharing revoke, mail's add-ons and sending lock, the
 * group-mailbox rule, AI consent, chat's local-model pin and running-turn check —
 * stay in the consumer's own gates, before it calls change(). The consumer
 * decides WHEN to call it.
 *
 * The first choice on a new scope is not a change to existing content: nothing
 * is stored under it yet, so it asks no step-up ($existing false). A consumer
 * whose new scope still has prerequisites (a new Private mailbox needs holders
 * with vaults) calls change() with $existing false; one with none writes the
 * first level directly.
 *
 * @version 1.0
 */

/**
 * The converge half: something with items not yet at the level it promises.
 * Every level-change scope is one; the vault's deferred-work drains (a mail
 * mailbox raised to Fortress) are one without being a scope.
 */
interface ProtectionLevelConvergence {

	/** Items not yet at the promise, at most $limit, oldest first. */
	public function pending(int $limit): array;

	/** Is this item sealed for the browser? Such an item is never converted server-side. */
	public function browserSealed($item): bool;

	/**
	 * Convert one item to the promise. Returns the bytes it moved, or null when
	 * there was nothing to do (the item is gone, or already there). Throws
	 * VaultLockedException when the window it needs is closed, anything else
	 * on a failure that leaves the item as it was.
	 */
	public function convertOne($item): ?int;

	/** How many items are still not at the promise. */
	public function remaining(): int;

	/** Per-pass bounds, ['rows' => int, 'bytes' => int]; bytes defaults to BYTE_BUDGET. */
	public function budget(): array;
}

/** A scope whose protection level a member can change. */
interface ProtectionLevelScope extends ProtectionLevelConvergence {

	/** What the member calls it, for refusals: "this folder", "this domain". */
	public function label(): string;

	/** The rungs this scope moves between here, weakest first (a subset of ProtectionLevel::ORDER). */
	public function levels(): array;

	/** The level it promises now. */
	public function currentLevel(): string;

	/** Why a change to $target cannot happen now, or null. Asked at call time. */
	public function blockers(string $target, int $actor_id): ?string;

	/** Make the scope promise $target, durably. */
	public function flip(string $target): void;
}

class ProtectionLevelChange {

	/** Bytes one converge pass reads at most (it stops after the item that crosses it). */
	const BYTE_BUDGET = 67108864; // 64 MB

	/** Rows one converge pass takes when the consumer names no bound. */
	const DEFAULT_ROWS = 200;

	const OK        = 'ok';
	const UNCHANGED = 'unchanged';
	const REFUSED   = 'refused';
	const STEPUP    = 'stepup';

	/**
	 * Rules 1 and 2 alone: is $target a level this scope can move to from where
	 * it is, and has the member confirmed it is them? A consumer that writes
	 * other things before the change (a mail mailbox syncs its grants) asks this
	 * first, so a confirmation is never requested after those writes landed.
	 *
	 * $existing false: the scope holds no content yet, so no step-up is asked.
	 *
	 * @return array{status:string, error:?string, from:string, level:string}
	 */
	public static function gate(ProtectionLevelScope $scope, string $target, bool $existing = true): array {
		require_once(PathHelper::getIncludePath('includes/ProtectionLevel.php'));
		$current = $scope->currentLevel();
		$verdict = array('status' => self::OK, 'error' => null, 'from' => $current, 'level' => $target);
		$levels = $scope->levels();

		if (!ProtectionLevel::isValid($target) || !in_array($target, $levels, true)) {
			return self::refuse($verdict, $target === ProtectionLevel::FORTRESS
				? ucfirst($scope->label()) . ' cannot be made Fortress here: its browser encrypts Fortress content, not the server.'
				: 'That is not a protection level ' . $scope->label() . ' offers.');
		}
		if (!in_array($current, $levels, true)) {
			return self::refuse($verdict, $current === ProtectionLevel::FORTRESS
				? ucfirst($scope->label()) . ' is Fortress, encrypted by your browser, so its level cannot be changed here.'
				: 'The protection level of ' . $scope->label() . ' cannot be changed here.');
		}
		if ($current === $target) {
			$verdict['status'] = self::UNCHANGED;
			return $verdict;
		}
		if ($existing && self::stepUpOutstanding()) {
			$verdict['status'] = self::STEPUP;
			$verdict['error'] = 'Confirm it is you before changing the protection of ' . $scope->label() . '.';
			return $verdict;
		}
		return $verdict;
	}

	/**
	 * The whole change: gate(), the scope's prerequisites, the window rule, then
	 * the flip. On success reports how many items are left to converge.
	 *
	 * @return array{status:string, error:?string, from:string, level:string, remaining?:int}
	 */
	public static function change(ProtectionLevelScope $scope, string $target, int $actor_id,
			bool $existing = true): array {
		$verdict = self::gate($scope, $target, $existing);
		if ($verdict['status'] !== self::OK) {
			return $verdict;
		}
		$blocker = $scope->blockers($target, $actor_id);
		if ($blocker !== null && $blocker !== '') {
			return self::refuse($verdict, $blocker);
		}
		if ($target === ProtectionLevel::STANDARD && self::needsWindow($actor_id)) {
			return self::refuse($verdict, 'Unlock your vault before lowering protection on ' . $scope->label() . '.');
		}
		$scope->flip($target);
		$verdict['remaining'] = $scope->remaining();
		return $verdict;
	}

	/**
	 * One bounded pass converging stored content to the promise. $budget
	 * narrows the consumer's own bounds; $deadline (microtime) ends the pass
	 * early, for a driver that has only so long (the vault's deferred work).
	 *
	 * @return array{converted:int, failed:int, bytes:int, remaining:int, locked:bool}
	 */
	public static function convergeBatch(ProtectionLevelConvergence $consumer, ?array $budget = null,
			?float $deadline = null): array {
		$bounds = ($budget ?? array()) + $consumer->budget() + array('rows' => self::DEFAULT_ROWS, 'bytes' => self::BYTE_BUDGET);
		$rows  = max(1, (int)$bounds['rows']);
		$limit = max(1, (int)$bounds['bytes']);

		$converted = 0;
		$failed = 0;
		$bytes = 0;
		$locked = false;
		foreach ($consumer->pending($rows) as $item) {
			if (($deadline !== null && microtime(true) >= $deadline) || $bytes >= $limit) {
				break;
			}
			if ($consumer->browserSealed($item)) {
				continue;   // Fortress content: only its browser converts it
			}
			try {
				$moved = $consumer->convertOne($item);
				if ($moved !== null) {
					$converted++;
					$bytes += max(0, (int)$moved);
				}
			} catch (VaultLockedException $e) {
				$locked = true;   // the window closed: the next pass in an open one carries on
				break;
			} catch (Throwable $e) {
				$failed++;
				error_log('Protection level converge (' . get_class($consumer) . '): item '
					. (is_scalar($item) ? $item : json_encode($item)) . ' could not be converted: ' . $e->getMessage());
			}
		}
		return array(
			'converted' => $converted,
			'failed'    => $failed,
			'bytes'     => $bytes,
			'remaining' => $consumer->remaining(),
			'locked'    => $locked,
		);
	}

	/**
	 * Rule 2's test: this session's member has a second factor and has not
	 * confirmed it recently.
	 */
	public static function stepUpOutstanding(): bool {
		$session = SessionControl::get_instance();
		if (!(int)$session->get_user_id()) {
			return false;
		}
		return $session->step_up_outstanding();
	}

	/** Rule 4's test: $actor_id holds a vault and its window is closed. */
	public static function needsWindow(int $actor_id): bool {
		if ($actor_id <= 0) {
			return false;
		}
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
		require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
		if (UserEncryptionVault::loadForUser($actor_id) === null) {
			return false;
		}
		return !VaultUnlock::isOpen($actor_id);
	}

	private static function refuse(array $verdict, string $error): array {
		$verdict['status'] = self::REFUSED;
		$verdict['error'] = $error;
		return $verdict;
	}
}
?>
