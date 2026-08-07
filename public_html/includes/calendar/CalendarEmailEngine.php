<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarItemSourceRegistry.php'));
require_once(PathHelper::getIncludePath('data/calendar_entry_class.php'));
require_once(PathHelper::getIncludePath('data/calendar_preference_class.php'));
require_once(PathHelper::getIncludePath('data/calendar_email_class.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));

/**
 * CalendarEmailEngine — computes and sends calendar reminder and summary
 * emails. All behavior of the CalendarEmails scheduled task lives here; the
 * task is a thin wrapper. The clock is injected so tests can drive it.
 *
 * Reminders: native entries only, at the entry's effective lead — the
 * per-entry cal_reminder_minutes override, else the owner's
 * cpr_reminder_default_minutes. Late-but-before-start sends immediately;
 * at-or-after-start never sends (a missed window is dropped, not queued).
 *
 * Summaries: the owner's aggregated calendar (every CalendarItemSource) over
 * the local day / next 7 days, sent once per period at the owner's chosen
 * local hour. An empty period claims its ledger row but sends nothing.
 *
 * Every send is claimed in cme_calendar_emails first — the unique dedup key
 * is the at-most-once guarantee.
 *
 * Sending is always ambient (EmailSender::sendTemplate, the platform sender):
 * this runs from cron, which can never hold a vault unlock window, so the
 * session-gated compose transport is structurally unavailable — never route
 * these through resolveOutboundTransport().
 *
 * @version 1.0
 */
class CalendarEmailEngine {

	/** Largest offered lead (minutes) — bounds the reminder scan window. */
	const MAX_LEAD_MINUTES = 60;
	/** Scan slack beyond the largest lead, covering cron tick jitter. */
	const WINDOW_SLACK_MINUTES = 5;

	/** @var string UTC 'Y-m-d H:i:s' the whole pass evaluates against. */
	private $now;

	/**
	 * Test/dry-run seam: when set, called as ($template_name, $to_email, $vars,
	 * $subject) instead of EmailSender::sendTemplate. Return bool.
	 * @var callable|null
	 */
	public $sender = null;

	/** @var array per-run caches */
	private $users = [];

	public function __construct(?string $now_utc = null) {
		$this->now = $now_utc ?: gmdate('Y-m-d H:i:s');
	}

	/**
	 * One full pass: reminders then summaries.
	 *
	 * @param bool $dry_run  Compute candidates only — no sends, no ledger writes.
	 * @return array ['reminders' => int, 'summaries' => int, 'preview' => string[]]
	 */
	public function run(bool $dry_run = false): array {
		$result = ['reminders' => 0, 'summaries' => 0, 'preview' => []];

		foreach ($this->dueReminders() as $cand) {
			if ($dry_run) {
				if (!$this->alreadyClaimed($cand['dedup_key'])) {
					$result['reminders']++;
					$result['preview'][] = 'Reminder to ' . $cand['user']->get('usr_email')
						. ': "' . ($cand['entry']->get('cal_title') ?: 'Busy') . '" at '
						. $cand['occurrence_start_utc'] . ' UTC (lead ' . $cand['lead'] . 'm)';
				}
				continue;
			}
			$claim = CalendarEmail::claim(
				$cand['user']->key, CalendarEmail::KIND_REMINDER, $cand['dedup_key'],
				$cand['entry']->key, $cand['occurrence_start_utc']
			);
			if ($claim === NULL) {
				continue; // already handled
			}
			$vars    = $this->reminderVars($cand['entry'], $cand['occurrence_start_utc'], $cand['occurrence_end_utc'], $cand['user']);
			$subject = $this->reminderSubject($vars);
			if ($this->deliver('calendar_reminder', $cand['user']->get('usr_email'), $vars, $subject)) {
				$result['reminders']++;
			}
		}

		foreach ($this->dueSummaries() as $cand) {
			if ($dry_run) {
				if (!$this->alreadyClaimed($cand['dedup_key'])) {
					$result['summaries']++;
					$result['preview'][] = ucfirst(str_replace('summary_', '', $cand['kind']))
						. ' summary to ' . $cand['user']->get('usr_email')
						. ' for ' . $cand['period_key'];
				}
				continue;
			}
			$claim = CalendarEmail::claim(
				$cand['user']->key, $cand['kind'], $cand['dedup_key'],
				null, null, $cand['period_key']
			);
			if ($claim === NULL) {
				continue;
			}
			$vars = $this->summaryVars($cand['user'], $cand['kind'], $cand['period_key'], $cand['timezone']);
			if ($vars === NULL) {
				continue; // empty period — ledger row claimed, nothing sent
			}
			if ($this->deliver('calendar_summary', $cand['user']->get('usr_email'), $vars, $vars['period_label'])) {
				$result['summaries']++;
			}
		}

		return $result;
	}

	// ─── Reminders ──────────────────────────────────────────────────────────

	/**
	 * Every reminder due at $now: entry/occurrence pairs whose effective lead
	 * window contains the current instant. Excludes all-day entries, cancelled
	 * and soft-deleted entries, non-user subjects, and owners without email.
	 *
	 * @return array[] each ['entry','user','occurrence_start_utc','occurrence_end_utc','lead','dedup_key']
	 */
	public function dueReminders(): array {
		$window_minutes = self::MAX_LEAD_MINUTES + self::WINDOW_SLACK_MINUTES;
		$window_end = LibraryFunctions::time_shift($this->now, $window_minutes . ' minutes', 'Y-m-d H:i:s');

		// Occurrence candidates: [entry, start_utc, end_utc]
		$candidates = [];

		$single = new MultiCalendarEntry([
			'subject_type'       => CalendarSubject::TYPE_USER,
			'deleted'            => false,
			'non_recurring_only' => true,
			'start_utc_gte'      => $this->now,
			'start_utc_before'   => $window_end,
		]);
		$single->load();
		foreach ($single as $entry) {
			$candidates[] = [$entry, $entry->get('cal_start_utc'), $entry->get('cal_end_utc')];
		}

		// Recurring parents overlapping the window: series started, not ended.
		// The end-date bound backs off a day so timezone offsets can't clip a
		// series whose local end date is still today somewhere.
		$series_floor = LibraryFunctions::time_shift($this->now, '-1 day', 'Y-m-d');
		$parents = new MultiCalendarEntry([
			'subject_type'         => CalendarSubject::TYPE_USER,
			'deleted'              => false,
			'recurring_only'       => true,
			'start_utc_before'     => $window_end,
			'end_date_null_or_gte' => $series_floor,
		]);
		$parents->load();
		foreach ($parents as $parent) {
			foreach ($parent->get_instances_for_range($this->now, $window_end, CalendarItem::VIS_DETAILS) as $item) {
				$candidates[] = [$parent, $item->start_utc, $item->end_utc];
			}
		}

		// Resolve owner defaults in one query for every entry inheriting them.
		$need_default = [];
		foreach ($candidates as list($entry, $start, $end)) {
			$explicit = $entry->get('cal_reminder_minutes');
			if ($explicit === null || $explicit === '') {
				$need_default[(int)$entry->get('cal_subject_id')] = true;
			}
		}
		$defaults = $this->reminderDefaultsFor(array_keys($need_default));

		$now_ts = strtotime($this->now . ' UTC');
		$due = [];
		foreach ($candidates as list($entry, $start, $end)) {
			if ($entry->get('cal_all_day')) {
				continue; // all-day entries carry no timed reminder (summaries cover them)
			}
			if (($entry->get('cal_status') ?: 'confirmed') === 'cancelled') {
				continue;
			}

			$explicit = $entry->get('cal_reminder_minutes');
			$lead = ($explicit !== null && $explicit !== '')
				? (int)$explicit
				: ($defaults[(int)$entry->get('cal_subject_id')] ?? 0);
			if ($lead <= 0) {
				continue;
			}

			$start_ts = strtotime($start . ' UTC');
			if (!($now_ts >= $start_ts - $lead * 60 && $now_ts < $start_ts)) {
				continue; // outside the lead window, or already started (dropped, never late)
			}

			$user = $this->userFor((int)$entry->get('cal_subject_id'));
			if ($user === NULL) {
				continue;
			}

			$due[] = [
				'entry'                => $entry,
				'user'                 => $user,
				'occurrence_start_utc' => $start,
				'occurrence_end_utc'   => $end,
				'lead'                 => $lead,
				'dedup_key'            => CalendarEmail::reminderKey($entry->key, $start),
			];
		}
		return $due;
	}

	/**
	 * What a reminder email may say about an entry — the single chokepoint for
	 * content decisions. When the calendar protection-level dial lands, the
	 * Private-entry check goes here: a sealed entry keeps only the time vars
	 * and the template's conditionals render the generic form.
	 */
	public function reminderVars(CalendarEntry $entry, string $start_utc, string $end_utc, User $user): array {
		$tz = $entry->get('cal_timezone') ?: ($user->get('usr_timezone') ?: 'UTC');
		return [
			'recipient'     => $user->export_as_array(),
			'title'         => (string)$entry->get('cal_title'),
			'tentative'     => (($entry->get('cal_status') ?: 'confirmed') === 'tentative') ? '1' : '',
			'start_display' => LibraryFunctions::convert_time($start_utc, 'UTC', $tz, 'l, M j, Y g:i A T'),
			'end_display'   => LibraryFunctions::convert_time($end_utc, 'UTC', $tz, 'g:i A T'),
			'start_short'   => LibraryFunctions::convert_time($start_utc, 'UTC', $tz, 'g:i A'),
			'calendar_url'  => $this->baseUrl() . '/profile/calendar',
			'settings_url'  => $this->baseUrl() . '/profile/calendar_settings',
		];
	}

	private function reminderSubject(array $vars): string {
		$what = ($vars['title'] !== '') ? $vars['title'] : 'your calendar entry';
		return 'Reminder: ' . $what . ' at ' . $vars['start_short'];
	}

	// ─── Summaries ──────────────────────────────────────────────────────────

	/**
	 * Every summary due at $now: users whose preference row asks for one, whose
	 * local clock has reached their chosen hour (Monday only for weekly), keyed
	 * by the local period date.
	 *
	 * @return array[] each ['user','kind','period_key','timezone','dedup_key']
	 */
	public function dueSummaries(): array {
		$prefs = new MultiCalendarPreference(['summary_active' => true]);
		$prefs->load();

		$due = [];
		foreach ($prefs as $pref) {
			$user = $this->userFor((int)$pref->get('cpr_usr_user_id'));
			if ($user === NULL) {
				continue;
			}
			$tz = $user->get('usr_timezone') ?: 'America/New_York';
			$local_date = LibraryFunctions::convert_time($this->now, 'UTC', $tz, 'Y-m-d');
			$local_hour = (int)LibraryFunctions::convert_time($this->now, 'UTC', $tz, 'G');

			if ($local_hour < (int)$pref->get('cpr_summary_hour')) {
				continue;
			}
			$freq = $pref->get('cpr_summary_frequency');
			if ($freq === 'weekly') {
				$local_dow = (int)LibraryFunctions::convert_time($this->now, 'UTC', $tz, 'w');
				if ($local_dow !== 1) {
					continue; // weekly summaries go out on Mondays
				}
				$kind = CalendarEmail::KIND_SUMMARY_WEEKLY;
			} elseif ($freq === 'daily') {
				$kind = CalendarEmail::KIND_SUMMARY_DAILY;
			} else {
				continue;
			}

			$due[] = [
				'user'       => $user,
				'kind'       => $kind,
				'period_key' => $local_date,
				'timezone'   => $tz,
				'dedup_key'  => CalendarEmail::summaryKey($kind, $user->key, $local_date),
			];
		}
		return $due;
	}

	/**
	 * Template vars for a summary covering the local period starting at
	 * $period_key, or NULL when the period holds nothing (no email for an
	 * empty calendar). Items come from the full source aggregation — native
	 * entries, events, bookings — exactly what the calendar page shows.
	 */
	public function summaryVars(User $user, string $kind, string $period_key, string $tz): ?array {
		$days_span = ($kind === CalendarEmail::KIND_SUMMARY_WEEKLY) ? 7 : 1;
		$start_utc = LibraryFunctions::convert_time($period_key . ' 00:00:00', $tz, 'UTC', 'Y-m-d H:i:s');
		$end_local = date('Y-m-d', strtotime($period_key . ' +' . $days_span . ' days'));
		$end_utc   = LibraryFunctions::convert_time($end_local . ' 00:00:00', $tz, 'UTC', 'Y-m-d H:i:s');

		$subject = CalendarSubject::user($user->key);
		$items = CalendarItemSourceRegistry::getItems($subject, $start_utc, $end_utc, CalendarItem::VIS_DETAILS);

		$items = array_filter($items, function ($item) {
			return $item->status !== 'cancelled';
		});
		if (empty($items)) {
			return NULL;
		}
		usort($items, function ($a, $b) {
			return strcmp($a->start_utc, $b->start_utc);
		});

		// Group into per-day line lists, everything formatted in the user's zone.
		$by_day = [];
		foreach ($items as $item) {
			$day = LibraryFunctions::convert_time($item->start_utc, 'UTC', $tz, 'Y-m-d');
			$when = $item->all_day
				? 'All day'
				: LibraryFunctions::convert_time($item->start_utc, 'UTC', $tz, 'g:i A')
					. ' – ' . LibraryFunctions::convert_time($item->end_utc, 'UTC', $tz, 'g:i A');
			$line = $when . ' — ' . ($item->title !== null && $item->title !== '' ? $item->title : 'Busy');
			if ($item->status === 'tentative') {
				$line .= ' (tentative)';
			}
			if ($item->type === CalendarItem::TYPE_EVENT) {
				$line .= ' · event';
			} elseif ($item->type === CalendarItem::TYPE_BOOKING) {
				$line .= ' · booking';
			}
			$by_day[$day][] = ['text' => $line];
		}

		$days = [];
		foreach ($by_day as $day => $lines) {
			$days[] = [
				'label' => date('l, F j', strtotime($day)),
				'lines' => $lines,
			];
		}

		if ($kind === CalendarEmail::KIND_SUMMARY_WEEKLY) {
			$period_label = 'Your week ahead — ' . date('M j', strtotime($period_key))
				. ' to ' . date('M j', strtotime($period_key . ' +6 days'));
		} else {
			$period_label = 'Your calendar today — ' . date('l, F j', strtotime($period_key));
		}

		return [
			'recipient'    => $user->export_as_array(),
			'period_label' => $period_label,
			'days'         => $days,
			'calendar_url' => $this->baseUrl() . '/profile/calendar',
			'settings_url' => $this->baseUrl() . '/profile/calendar_settings',
		];
	}

	// ─── Shared plumbing ────────────────────────────────────────────────────

	/** cpr_reminder_default_minutes per user id, one query. Missing row = 0. */
	private function reminderDefaultsFor(array $user_ids): array {
		if (empty($user_ids)) {
			return [];
		}
		$defaults = [];
		$prefs = new MultiCalendarPreference(['user_ids' => $user_ids]);
		$prefs->load();
		foreach ($prefs as $pref) {
			$defaults[(int)$pref->get('cpr_usr_user_id')] = (int)$pref->get('cpr_reminder_default_minutes');
		}
		return $defaults;
	}

	/** Emailable user or NULL (missing, soft-deleted, or no address). Cached per run. */
	private function userFor(int $user_id): ?User {
		if (!array_key_exists($user_id, $this->users)) {
			$user = new User($user_id, TRUE);
			$ok = $user->key && !$user->get('usr_delete_time') && $user->get('usr_email');
			$this->users[$user_id] = $ok ? $user : NULL;
		}
		return $this->users[$user_id];
	}

	private function alreadyClaimed(string $dedup_key): bool {
		$multi = new MultiCalendarEmail(['dedup_key' => $dedup_key]);
		return $multi->count_all() > 0;
	}

	/** Send through the injected seam or the ambient platform path. */
	private function deliver(string $template, string $to, array $vars, string $subject): bool {
		if ($this->sender !== null) {
			return (bool)call_user_func($this->sender, $template, $to, $vars, $subject);
		}
		require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
		try {
			EmailSender::sendTemplate($template, $to, $vars, $subject);
			return true;
		} catch (Exception $e) {
			// send() queues transport failures itself; this catches template/config
			// errors. The ledger row stands — a broken template must not re-fire
			// the same reminder every minute once fixed mid-window.
			error_log('calendar email failed (' . $template . ' to ' . $to . '): ' . $e->getMessage());
			return false;
		}
	}

	private function baseUrl(): string {
		return rtrim(LibraryFunctions::get_absolute_url(''), '/');
	}
}
