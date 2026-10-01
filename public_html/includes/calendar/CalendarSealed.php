<?php
/**
 * CalendarSealed — the calendar's Sealed Vault consumer bootstrap (core
 * consumer `calendar_sealed`, declared in vault_consumers.json).
 *
 * A Private calendar's entries hold their title, location, link and notes
 * sealed to the owner's vault through the generic model hook
 * (CalendarEntry::$sealed_fields), so the consumer owes the vault two things
 * and nothing else:
 *
 *   - rotation: re-seal every entry on the generation being retired
 *     (VaultUnlock::modelReseal, one line);
 *   - deferred work: finish a level change the page's batch loop did not
 *     (CalendarLevel::hasWork / drain), in the owner's window.
 *
 * No onWipe: the calendar keeps no in-window plaintext outside the sealed
 * columns. No File hook: entries carry no attachments.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('includes/VaultDeferredWork.php'));
require_once(PathHelper::getIncludePath('data/entries_class.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarLevel.php'));

VaultUnlock::onReseal(VaultUnlock::modelReseal(array(CalendarEntry::class)));

VaultDeferredWork::register(
	CalendarLevel::DEFERRED_WORK_ID,
	function (int $user_id): bool {
		return CalendarLevel::hasWork($user_id);
	},
	function (int $user_id, VaultKey $key, float $deadline): int {
		return CalendarLevel::drain($user_id, $deadline);
	}
);
?>
