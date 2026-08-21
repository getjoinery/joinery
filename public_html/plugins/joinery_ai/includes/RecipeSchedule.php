<?php
/**
 * RecipeSchedule — when a recipe is due to run by itself.
 *
 * One answer, shared by both schedulers. The cron dispatcher takes the part of
 * a recipe's binding that needs no vault window; the in-window drain takes the
 * sealed remainder inside its owner's own request. They ask the same question
 * here so they cannot drift into two different ideas of "due"
 * (specs/recipe_run_scheduling.md).
 *
 * A clock frequency means "at most this often", answered from its most recent
 * FIRE POINT rather than from the calendar day:
 *
 *   hourly — the top of the current UTC hour
 *   daily  — today's scheduled time, or yesterday's when today's hasn't arrived
 *   weekly — the most recent target-day-at-time at or before now
 *
 * Due ⇔ no run of this recipe has STARTED at or after that point, whatever
 * triggered it — a manual run satisfies the schedule just as a scheduled one
 * does. Because the fire point stays in the past until something runs, a fire
 * point nobody was around to meet is caught up rather than skipped: the server
 * being down over Monday 07:00, or a sealed recipe's owner not being signed in
 * at the moment it came due, no longer costs a whole period.
 *
 * On a MIXED binding the two schedulers answer for different halves, so which
 * runs can satisfy a fire point depends on who is asking. A run inside the
 * owner's window reads the whole binding and satisfies both. A worker's run
 * (schedule or manual trigger) cannot touch the sealed subset, so from the
 * in-window drain's posture it satisfies nothing — otherwise cron, which fires
 * within a tick of every fire point, would claim each one first and the sealed
 * half of a mixed clock recipe would never run at all.
 *
 * Fire points are computed in UTC from the stored UTC time, so a local
 * schedule drifts by an hour across a DST boundary — the same trade-off the
 * schedule column has always carried.
 *
 * @version 1.1.0
 */

require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_runs_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineJobInterface.php'));

class RecipeSchedule {

	/** Due whenever the job reports unhandled items. Offered only by a job
	 *  whose items have an arrival concept — see arrivalLabel(). */
	const FREQ_ARRIVAL = 'arrival';

	const FREQ_HOURLY = 'hourly';
	const FREQ_DAILY  = 'daily';
	const FREQ_WEEKLY = 'weekly';

	/** The frequencies answered from a fire point. */
	const CLOCK_FREQUENCIES = array(self::FREQ_HOURLY, self::FREQ_DAILY, self::FREQ_WEEKLY);

	/**
	 * Not a stored value — what the UI and both schedulers call a recipe that
	 * has no automatic trigger at all. `rcp_enabled` false IS this state, and
	 * a legacy row still carrying the retired frequency 'none' reads as it too.
	 */
	const FREQ_MANUAL = 'manual';

	/**
	 * What this recipe's Runs control says, normalised: FREQ_MANUAL, or one of
	 * the automatic frequencies.
	 *
	 * The manual/auto bit lives in `rcp_enabled` (the dispatcher, the drain,
	 * the pending reaper, the save-time kill switch and the AI panel all read
	 * it), so "Manually only" is enabled-false. A row whose stored frequency is
	 * the retired 'none', or empty, reads the same way — there is no automatic
	 * trigger it could name.
	 */
	public static function frequencyOf(Recipe $recipe): string {
		if (!$recipe->get('rcp_enabled')) {
			return self::FREQ_MANUAL;
		}
		$freq = trim((string)$recipe->get('rcp_schedule_frequency'));
		if ($freq === '' || $freq === 'none') {
			return self::FREQ_MANUAL;
		}
		return $freq;
	}

	/** Does this recipe run only when someone presses Run Now? */
	public static function isManual(Recipe $recipe): bool {
		return self::frequencyOf($recipe) === self::FREQ_MANUAL;
	}

	/** Is this one of the frequencies answered from a fire point? */
	public static function isClockFrequency(string $frequency): bool {
		return in_array($frequency, self::CLOCK_FREQUENCIES, true);
	}

	/**
	 * The one shared answer: should this recipe start an automatic run now,
	 * from the asking scheduler's posture?
	 *
	 * $posture is a PipelineJobInterface::POSTURE_* constant — cron asks about
	 * the standard subset of the binding, the in-window drain about the sealed
	 * one. For the arrival frequency dueness IS the job's own answer about
	 * unhandled items; for a clock frequency the posture decides which runs
	 * can have satisfied the fire point (satisfyingTriggers()).
	 */
	public static function isDue(Recipe $recipe, string $posture, string $now_utc): bool {
		$freq = self::frequencyOf($recipe);
		if ($freq === self::FREQ_ARRIVAL) {
			return self::hasArrivals($recipe, $posture);
		}
		if (!self::isClockFrequency($freq)) {
			return false;
		}
		return self::isClockDue($recipe, $now_utc, self::satisfyingTriggers($recipe, $posture));
	}

	/**
	 * Which runs can have satisfied a clock fire point, from the asking
	 * scheduler's posture — null means any run.
	 *
	 * Any run satisfies cron: a worker covers the standard subset itself, and
	 * a window run covers everything (nextItem() reads whatever the executing
	 * process can, and in a window that is the whole binding).
	 *
	 * The sealed posture on a MIXED binding is the one narrow case: a worker's
	 * run — schedule trigger, or a manual row a worker claimed — never touched
	 * the sealed subset, so only a window run counts. Without this, cron claims
	 * every fire point within a tick and the sealed half never runs. A FULLY
	 * sealed recipe needs no narrowing: every run it has ever had executed in
	 * a window, including adopted manual rows.
	 */
	private static function satisfyingTriggers(Recipe $recipe, string $posture): ?array {
		if ($posture !== PipelineJobInterface::POSTURE_SEALED) {
			return null;
		}
		require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeVaultScope.php'));
		if (RecipeVaultScope::requiresWindow($recipe) && RecipeVaultScope::cronRunnable($recipe)) {
			return array(RecipeRun::TRIGGER_WINDOW);
		}
		return null;
	}

	/**
	 * Has a clock-scheduled recipe gone unrun since its most recent fire point?
	 *
	 * False for any recipe that is not on a clock frequency — an arrival recipe
	 * is not "overdue", it is waiting for something to arrive. $triggers
	 * narrows which runs count as satisfaction (see satisfyingTriggers());
	 * null means any run.
	 */
	public static function isClockDue(Recipe $recipe, string $now_utc, ?array $triggers = null): bool {
		$fire_point = self::lastFirePoint($recipe, $now_utc);
		if ($fire_point === null) {
			return false;
		}
		return !self::startedSince((int)$recipe->key, $fire_point, $triggers);
	}

	/**
	 * The most recent moment this recipe was supposed to run, at or before
	 * $now_utc — or null when it has no clock frequency.
	 *
	 * Always in the past, which is what makes catch-up work: the point stays
	 * claimable until a run happens rather than expiring with the calendar day.
	 */
	public static function lastFirePoint(Recipe $recipe, string $now_utc): ?string {
		$freq = self::frequencyOf($recipe);
		$now = strtotime($now_utc . ' UTC');
		if ($now === false) {
			return null;
		}

		if ($freq === self::FREQ_HOURLY) {
			return gmdate('Y-m-d H:00:00', $now);
		}

		$time = self::scheduleTime($recipe);

		if ($freq === self::FREQ_DAILY) {
			$today = gmdate('Y-m-d', $now) . ' ' . $time;
			return $today <= $now_utc
				? $today
				: gmdate('Y-m-d', $now - 86400) . ' ' . $time;
		}

		if ($freq === self::FREQ_WEEKLY) {
			// Walk back to the target weekday, then back another week if that
			// lands on today but the time hasn't come round yet.
			$target = (int)$recipe->get('rcp_schedule_day_of_week');   // 0 = Sunday
			if ($target < 0 || $target > 6) $target = 0;
			$days_back = ((int)gmdate('w', $now) - $target + 7) % 7;
			$candidate = gmdate('Y-m-d', $now - ($days_back * 86400)) . ' ' . $time;
			if ($candidate > $now_utc) {
				$candidate = gmdate('Y-m-d', $now - (($days_back + 7) * 86400)) . ' ' . $time;
			}
			return $candidate;
		}

		return null;
	}

	/**
	 * The recipe's scheduled time of day as 'HH:MM:SS' UTC. A NULL column means
	 * midnight — the column is optional on hourly recipes, and a daily recipe
	 * saved without one should still fire rather than never.
	 */
	private static function scheduleTime(Recipe $recipe): string {
		$time = $recipe->get('rcp_schedule_time');
		if (is_object($time) && method_exists($time, 'format')) {
			$time = $time->format('H:i:s');
		}
		$time = trim((string)$time);
		if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $time)) {
			return '00:00:00';
		}
		if (substr_count($time, ':') === 1) $time .= ':00';
		return str_pad($time, 8, '0', STR_PAD_LEFT);
	}

	/**
	 * Has a run of this recipe started at or after $cutoff_utc?
	 *
	 * With $triggers null, any trigger counts: someone who pressed Run Now five
	 * minutes ago has met this hour's fire point, and queueing a second run
	 * behind theirs would say the schedule disagreed with them. A trigger list
	 * narrows the question to runs a particular executor could have made —
	 * see satisfyingTriggers().
	 */
	public static function startedSince(int $recipe_id, string $cutoff_utc, ?array $triggers = null): bool {
		if ($recipe_id <= 0) {
			return false;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$sql = "SELECT 1 FROM rcr_recipe_runs
			  WHERE rcr_rcp_recipe_id = ?
			    AND rcr_started_time >= ?
			    AND rcr_delete_time IS NULL";
		$params = array($recipe_id, $cutoff_utc);
		if ($triggers !== null && count($triggers) > 0) {
			$sql .= " AND rcr_trigger IN (" . implode(',', array_fill(0, count($triggers), '?')) . ")";
			$params = array_merge($params, array_values($triggers));
		}
		$q = $db->prepare($sql . " LIMIT 1");
		$q->execute($params);
		return (bool)$q->fetchColumn();
	}

	/**
	 * Does this recipe's job report unhandled items for $posture?
	 *
	 * hasWork() is contract-bound to stay a single indexed EXISTS, which is why
	 * the dispatcher can afford to ask it once per tick per arrival recipe. A
	 * job that cannot be resolved, or throws, reports nothing to do — a recipe
	 * with a broken job already says so when the runner tries to execute it,
	 * and guessing "due" here would queue runs that can only fail.
	 */
	private static function hasArrivals(Recipe $recipe, string $posture): bool {
		require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeVaultScope.php'));
		$job = RecipeVaultScope::jobFor($recipe);
		if ($job === null) {
			return false;
		}
		try {
			return $job->hasWork(RecipeVaultScope::configFor($recipe), $recipe, $posture);
		} catch (\Throwable $e) {
			error_log('RecipeSchedule: arrival check failed for recipe '
				. (int)$recipe->key . ': ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * The arrival option this recipe's job offers, or null when it offers none.
	 * The label is the JOB's wording ('As mail arrives'), because only the job
	 * knows what one of its items is.
	 */
	public static function arrivalLabelFor(Recipe $recipe): ?string {
		require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeVaultScope.php'));
		$job = RecipeVaultScope::jobFor($recipe);
		if ($job === null) {
			return null;
		}
		try {
			$label = $job->arrivalLabel();
		} catch (\Throwable $e) {
			return null;
		}
		return ($label === null || trim($label) === '') ? null : trim($label);
	}
}
?>
