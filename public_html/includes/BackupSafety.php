<?php
/**
 * BackupSafety — the rules that stop a retention pass from deleting the
 * backups a restore would need (specs/storage_targets.md §9, F1 F2 F3).
 *
 * Retention picks what is surplus by age (BackupRunner::surplus) or by count
 * (a customer's chains). This class decides which of those may actually go,
 * and every pruner asks it: a site's own retention, the management node's
 * prune of a Managed node's backups, and its prune of a customer's.
 *
 *   - The newest verified restore point is kept, and so is everything newer
 *     than it, however old (F1). Backups that upload fine but do not open can
 *     then never age the last good one out.
 *   - The newest restore point is never deleted, whatever else is decided (F3).
 *   - Nothing is deleted the first time it is found surplus. A point goes only
 *     when an earlier pass, at least CONFIRM_HOURS before, found it surplus too
 *     and every pass since has agreed (F3). A wrong rule shows as a day of
 *     waiting deletions before anything goes.
 *
 * Pure: the callers keep the "first found surplus" times wherever their
 * points live, and do the deleting.
 *
 * @version 1.0
 */
class BackupSafety {

	/** How long a point waits between first being found surplus and being deleted. */
	const CONFIRM_HOURS = 20;

	/** No run finished off-site for this long is said out loud: two nights. */
	const OFFSITE_GAP_SECONDS = 172800;

	/** backup_verify_every_days and the fleet verify interval, as shipped. */
	const DEFAULT_VERIFY_EVERY_DAYS = 7;

	/**
	 * Days without a passed verification before it is a problem: one day past
	 * the interval, so a verify that runs on time never trips it. 0 when
	 * verification is switched off.
	 */
	public static function verify_alarm_days(int $every_days): int {
		return $every_days > 0 ? $every_days + 1 : 0;
	}

	/**
	 * What a site's own backups are missing, for its Backups page: no run
	 * finished off-site for two nights (F2), no backup passed verification for
	 * a day past the verify interval (F1). Nothing is said before the site's
	 * first run is that old: a new site has not had the chance.
	 *
	 * Times are unix seconds, null for never. Returns a list of
	 * ['kind' => 'offsite'|'verify', 'since' => ?int].
	 */
	public static function site_warnings(?int $first_run, ?int $last_offsite, ?int $last_verified, int $verify_every_days, int $now): array {
		$out = array();
		if ($first_run === null) {
			return $out;
		}
		if ($now - $first_run > self::OFFSITE_GAP_SECONDS
				&& ($last_offsite === null || $now - $last_offsite > self::OFFSITE_GAP_SECONDS)) {
			$out[] = array('kind' => 'offsite', 'since' => $last_offsite);
		}
		$alarm = self::verify_alarm_days($verify_every_days) * 86400;
		if ($alarm > 0 && $now - $first_run > $alarm
				&& ($last_verified === null || $now - $last_verified > $alarm)) {
			$out[] = array('kind' => 'verify', 'since' => $last_verified);
		}
		return $out;
	}

	/**
	 * The start time of the newest verified point, or null when none is.
	 * Each point is ['item' => string, 'time' => unix start, 'verified' => bool].
	 */
	public static function protect_since(array $points): ?int {
		$since = null;
		foreach ($points as $p) {
			if (!empty($p['verified']) && ($since === null || (int)$p['time'] > $since)) {
				$since = (int)$p['time'];
			}
		}
		return $since;
	}

	/**
	 * Which surplus points may be deleted on this pass.
	 *
	 * $points: every restore point of one owner, newest first, as
	 *          ['item' => string, 'time' => unix start, 'verified' => bool].
	 * $surplus: the items the owner's retention rule chose.
	 * $listed_before: item => unix time it was first found surplus, as the
	 *          caller stored it on the last pass.
	 * $floor: a verified point's start time found outside $points (another
	 *          family of the same owner's backups), or null.
	 *
	 * Returns:
	 *   delete  the items to delete now, in $surplus order;
	 *   listed  item => first-found time for every item still surplus on this
	 *           pass, the deleted ones included: the caller stores these and
	 *           forgets the rest, so a point that stops being surplus starts
	 *           over if it becomes surplus again;
	 *   held    item => why it is kept: 'newest', 'verified' or 'confirming'.
	 */
	public static function confirm(array $points, array $surplus, array $listed_before, int $now, ?int $floor = null): array {
		$out = array('delete' => array(), 'listed' => array(), 'held' => array());
		if (!$surplus) {
			return $out;
		}
		$times = array();
		foreach ($points as $p) {
			$times[(string)$p['item']] = (int)$p['time'];
		}
		$newest = $points ? (string)$points[0]['item'] : null;
		$since = self::protect_since($points);
		if ($floor !== null && ($since === null || $floor > $since)) {
			$since = $floor;
		}

		foreach ($surplus as $item) {
			$item = (string)$item;
			if ($item === $newest) {
				$out['held'][$item] = 'newest';
				continue;
			}
			if ($since !== null && ($times[$item] ?? PHP_INT_MIN) >= $since) {
				$out['held'][$item] = 'verified';
				continue;
			}
			$first = isset($listed_before[$item]) ? (int)$listed_before[$item] : $now;
			$out['listed'][$item] = $first;
			if ($now - $first >= self::CONFIRM_HOURS * 3600) {
				$out['delete'][] = $item;
			} else {
				$out['held'][$item] = 'confirming';
			}
		}
		return $out;
	}
}
