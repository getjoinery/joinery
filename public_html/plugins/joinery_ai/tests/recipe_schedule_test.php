<?php
/** @joinery-test
 * name: recipe_schedule
 * tier: safe
 * env: dev-only
 * needs: []
 */
/**
 * RecipeSchedule — the one answer both schedulers ask for "should this run by
 * itself now?" (specs/recipe_run_scheduling.md).
 *
 * The substance is the FIRE POINT: the most recent moment a recipe was supposed
 * to run, always in the past. Dueness is "nothing has started since then", which
 * gives catch-up for free — a Monday 07:00 nobody was around to meet stays
 * claimable on Tuesday instead of expiring with the calendar day. For a recipe
 * whose mail only its owner can decrypt, that is the difference between "Weekly"
 * meaning the next time you are here after Monday and meaning only if you happen
 * to be here on Monday.
 *
 * Everything here is pure: recipes are built in memory and never saved, so no
 * row exists and startedSince() answers false for all of them. That is what
 * makes this a safe-tier test — the DB half of the question (a run suppressing
 * its own fire point, a stranded manual row being adopted) is proved against
 * real rows in the db-tier in_window_email suite.
 *
 * Run: php tests/run.php safe --filter=recipe_schedule
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeSchedule.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineJobRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineJobInterface.php'));

/** An unsaved recipe carrying just the scheduling columns. */
function rs_recipe(string $frequency, bool $enabled = true,
		?string $time_utc = null, ?int $dow = null): Recipe {
	$recipe = new Recipe(NULL);
	$recipe->set('rcp_name', 'schedule fixture');
	$recipe->set('rcp_enabled', $enabled);
	$recipe->set('rcp_schedule_frequency', $frequency);
	$recipe->set('rcp_schedule_time', $time_utc);
	$recipe->set('rcp_schedule_day_of_week', $dow);
	return $recipe;
}

try {

	// -----------------------------------------------------------------------
	section('Manually only is the absence of any automatic trigger');

	check(RecipeSchedule::frequencyOf(rs_recipe('weekly', false)) === RecipeSchedule::FREQ_MANUAL,
		'a disabled recipe reads as Manually only whatever frequency its row remembers');
	check(RecipeSchedule::isManual(rs_recipe('weekly', false)),
		'and isManual() agrees');
	check(RecipeSchedule::frequencyOf(rs_recipe('none', true)) === RecipeSchedule::FREQ_MANUAL,
		'a legacy row still carrying the retired "none" reads as Manually only too');
	check(RecipeSchedule::frequencyOf(rs_recipe('', true)) === RecipeSchedule::FREQ_MANUAL,
		'so does an empty frequency');
	check(RecipeSchedule::frequencyOf(rs_recipe('daily', true)) === RecipeSchedule::FREQ_DAILY,
		'an enabled recipe reads as the frequency it stores');

	check(RecipeSchedule::lastFirePoint(rs_recipe('weekly', false), '2026-08-21 12:00:00') === null,
		'Manually only has no fire point at all');
	check(RecipeSchedule::isClockDue(rs_recipe('weekly', false), '2026-08-21 12:00:00') === false,
		'so it is never due');
	check(RecipeSchedule::isDue(rs_recipe('weekly', false),
			PipelineJobInterface::POSTURE_STANDARD, '2026-08-21 12:00:00') === false,
		'and neither scheduler will ever queue it');

	// -----------------------------------------------------------------------
	section('Hourly: the top of the current UTC hour');

	check(RecipeSchedule::lastFirePoint(rs_recipe('hourly'), '2026-08-21 14:37:09')
			=== '2026-08-21 14:00:00',
		'the fire point is this hour, not the last one');
	check(RecipeSchedule::lastFirePoint(rs_recipe('hourly'), '2026-08-21 00:00:00')
			=== '2026-08-21 00:00:00',
		'exactly on the hour, the fire point is now');
	check(RecipeSchedule::isClockDue(rs_recipe('hourly'), '2026-08-21 14:37:09') === true,
		'with no run since, it is due');

	// -----------------------------------------------------------------------
	section('Daily: today at the scheduled time, or yesterday when today has not come');

	$daily = rs_recipe('daily', true, '07:00:00');
	check(RecipeSchedule::lastFirePoint($daily, '2026-08-21 09:15:00') === '2026-08-21 07:00:00',
		'past 07:00 today, today is the fire point');
	check(RecipeSchedule::lastFirePoint($daily, '2026-08-21 07:00:00') === '2026-08-21 07:00:00',
		'exactly at 07:00, today counts');
	check(RecipeSchedule::lastFirePoint($daily, '2026-08-21 03:00:00') === '2026-08-20 07:00:00',
		'before 07:00 today, the fire point is YESTERDAY — not "nothing yet"');
	check(RecipeSchedule::lastFirePoint($daily, '2026-03-01 03:00:00') === '2026-02-28 07:00:00',
		'and the walk back crosses a month boundary');
	check(RecipeSchedule::lastFirePoint($daily, '2026-01-01 03:00:00') === '2025-12-31 07:00:00',
		'and a year boundary');

	$midnight = rs_recipe('daily', true, null);
	check(RecipeSchedule::lastFirePoint($midnight, '2026-08-21 09:15:00') === '2026-08-21 00:00:00',
		'a daily recipe with no time saved fires at midnight rather than never');

	// -----------------------------------------------------------------------
	section('Weekly: the most recent target-day-at-time at or before now');

	// 2026-08-21 is a Friday; 2026-08-17 the Monday before it.
	$monday = rs_recipe('weekly', true, '07:00:00', 1);
	check(gmdate('w', strtotime('2026-08-21 12:00:00 UTC')) === '5',
		'precondition: 2026-08-21 is a Friday');
	check(RecipeSchedule::lastFirePoint($monday, '2026-08-21 12:00:00') === '2026-08-17 07:00:00',
		'on Friday, the fire point is Monday just gone');
	check(RecipeSchedule::lastFirePoint($monday, '2026-08-17 09:00:00') === '2026-08-17 07:00:00',
		'on Monday after 07:00, the fire point is today');
	check(RecipeSchedule::lastFirePoint($monday, '2026-08-17 06:59:59') === '2026-08-10 07:00:00',
		'on Monday BEFORE 07:00, it is the Monday a week earlier — never a future moment');

	$sunday = rs_recipe('weekly', true, '23:30:00', 0);
	check(RecipeSchedule::lastFirePoint($sunday, '2026-08-17 00:05:00') === '2026-08-16 23:30:00',
		'Sunday (day 0) resolves like any other day, across the midnight boundary');

	$junk_dow = rs_recipe('weekly', true, '07:00:00', 9);
	check(RecipeSchedule::lastFirePoint($junk_dow, '2026-08-21 12:00:00') !== null,
		'an out-of-range day_of_week still produces a fire point rather than stranding the schedule');

	// -----------------------------------------------------------------------
	section('Catch-up: a fire point nobody met stays claimable');

	// The property, stated as a rule about the fire point rather than about a
	// run row: for every clock frequency the point is in the PAST, so the
	// "started since" comparison keeps answering "no" until something runs.
	// Under the old calendar-day rule a missed 07:00 was dropped at midnight.
	$now = '2026-08-21 03:00:00';
	foreach (array(
		'hourly' => rs_recipe('hourly'),
		'daily'  => rs_recipe('daily', true, '07:00:00'),
		'weekly' => rs_recipe('weekly', true, '07:00:00', 1),
	) as $freq => $recipe) {
		$point = RecipeSchedule::lastFirePoint($recipe, $now);
		check($point !== null && $point <= $now,
			"$freq: the fire point is at or before now, so it can be caught up", (string)$point);
		check(RecipeSchedule::isClockDue($recipe, $now) === true,
			"$freq: unmet, it is due — a missed occurrence is not skipped");
	}

	// The suppression half of the same rule: dueness is exactly
	// "nothing started at or after the fire point". startedSince() answers that
	// against real rows; here we pin its contract at the boundary.
	check(RecipeSchedule::startedSince(0, '2026-08-21 00:00:00') === false,
		'startedSince() on a recipe with no key answers false without touching the database');

	// -----------------------------------------------------------------------
	section('Arrival belongs to the job, in the job\'s own words');

	$offering = array();
	$silent = array();
	foreach (PipelineJobRegistry::all() as $job_id => $job_class) {
		$label = (new $job_class())->arrivalLabel();
		if ($label === null) { $silent[] = $job_id; continue; }
		$offering[$job_id] = $label;
		check(trim($label) !== '' && $label === trim($label),
			"$job_id offers a usable arrival label", $label);
	}
	check(count($offering) > 0,
		'at least one registered job offers an arrival option', json_encode($offering));

	$mail_jobs = array_intersect_key($offering,
		array_flip(array('email_triage', 'email_security_scan', 'email_schedule')));
	check(count($mail_jobs) === 3
			&& count(array_unique($mail_jobs)) === 1
			&& reset($mail_jobs) === 'As mail arrives',
		'the three mail jobs all offer it in the same words', json_encode($mail_jobs));

	// An agent-mode recipe has no job at all, so the option can never appear.
	$agent = rs_recipe(RecipeSchedule::FREQ_ARRIVAL);
	$agent->set('rcp_mode', Recipe::MODE_AGENT);
	$agent->set('rcp_pipeline_job', '');
	check(RecipeSchedule::arrivalLabelFor($agent) === null,
		'a recipe with no job is offered no arrival option');
	check(RecipeSchedule::isDue($agent, PipelineJobInterface::POSTURE_STANDARD,
			gmdate('Y-m-d H:i:s')) === false,
		'and a stored "arrival" on it is inert rather than due-forever');

	// A clock frequency is never confused for an arrival one, in either direction.
	check(RecipeSchedule::isClockFrequency(RecipeSchedule::FREQ_ARRIVAL) === false,
		'arrival is not a clock frequency');
	check(RecipeSchedule::isClockDue(rs_recipe(RecipeSchedule::FREQ_ARRIVAL), $now) === false,
		'so an arrival recipe is never "overdue" — it waits for something to arrive');
	foreach (RecipeSchedule::CLOCK_FREQUENCIES as $clock) {
		check(RecipeSchedule::isClockFrequency($clock) === true, "$clock is a clock frequency");
	}

} catch (Throwable $e) {
	check(false, 'unexpected exception', get_class($e) . ': ' . $e->getMessage()
		. ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
