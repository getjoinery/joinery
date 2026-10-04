<?php
/**
 * IncidentTriage — the one place a person's answer to "what are we doing
 * about it" is written (incident_triage.md WP1).
 *
 * Triage and notes are a person's; whether the condition is still there is
 * its source's, and nothing here touches inc_status. Every change that takes
 * effect is one IncidentEvent, with who made it. Setting the triage an
 * incident already has is a no-op and records nothing.
 *
 * Callers are the incident page, the Incidents list (several at once), and
 * the incident_triage API action. Each checks the superadmin floor itself
 * before calling; this class trusts the user id it is handed.
 *
 * @version 1.1 - a triage can carry a note (what fixed it), written on each incident it reaches
 * @version 1.0
 */

class IncidentTriageException extends Exception {}

class IncidentTriage {

	/** The snoozes a person picks from, in hours, with their words. */
	const SNOOZES = array(
		4   => '4 hours',
		24  => '1 day',
		72  => '3 days',
		168 => '1 week',
	);

	/** The words each triage state shows, and the button that sets it. */
	const LABELS = array(
		IncidentRecord::TRIAGE_NEW      => 'New',
		IncidentRecord::TRIAGE_LOOKING  => 'Looking',
		IncidentRecord::TRIAGE_SNOOZED  => 'Snoozed',
		IncidentRecord::TRIAGE_RESOLVED => 'Resolved',
		IncidentRecord::TRIAGE_IGNORED  => 'Ignored',
	);
	const VERBS = array(
		IncidentRecord::TRIAGE_NEW      => 'Mark new',
		IncidentRecord::TRIAGE_LOOKING  => 'I\'m looking',
		IncidentRecord::TRIAGE_SNOOZED  => 'Snooze',
		IncidentRecord::TRIAGE_RESOLVED => 'Resolve',
		IncidentRecord::TRIAGE_IGNORED  => 'Ignore',
	);

	/**
	 * What a person can choose to do, as one value each: a state, or a snooze
	 * with its length. The pages and the API all speak these.
	 */
	public static function do_options(): array {
		$out = array(
			IncidentRecord::TRIAGE_LOOKING  => self::VERBS[IncidentRecord::TRIAGE_LOOKING],
			IncidentRecord::TRIAGE_RESOLVED => self::VERBS[IncidentRecord::TRIAGE_RESOLVED],
			IncidentRecord::TRIAGE_IGNORED  => self::VERBS[IncidentRecord::TRIAGE_IGNORED],
			IncidentRecord::TRIAGE_NEW      => self::VERBS[IncidentRecord::TRIAGE_NEW],
		);
		foreach (self::SNOOZES as $h => $words) {
			$out['snooze_' . $h] = 'Snooze ' . $words;
		}
		return $out;
	}

	/**
	 * One "do" value as [state, snooze hours].
	 *
	 * @throws IncidentTriageException for a value that is not offered
	 */
	public static function parse_do(string $do): array {
		if (preg_match('/^snooze_(\d+)$/', $do, $m) && isset(self::SNOOZES[(int)$m[1]])) {
			return array(IncidentRecord::TRIAGE_SNOOZED, (int)$m[1]);
		}
		if (in_array($do, IncidentRecord::TRIAGE_STATES, true) && $do !== IncidentRecord::TRIAGE_SNOOZED) {
			return array($do, 0);
		}
		throw new IncidentTriageException('That is not something an incident can be set to.');
	}

	/** A note is capped like every other text on the row. */
	const NOTE_MAX = 4000;

	/**
	 * Set one incident's triage. A snooze needs one of SNOOZES. Returns true
	 * when it changed, false when the incident already read that way.
	 *
	 * @throws IncidentTriageException for a state or snooze that is not offered
	 */
	public static function set(IncidentRecord $inc, string $state, int $user_id, int $snooze_hours = 0): bool {
		if (!in_array($state, IncidentRecord::TRIAGE_STATES, true)) {
			throw new IncidentTriageException('That is not a triage state.');
		}
		$until = null;
		if ($state === IncidentRecord::TRIAGE_SNOOZED) {
			if (!isset(self::SNOOZES[$snooze_hours])) {
				throw new IncidentTriageException('Pick how long to snooze it.');
			}
			$until = gmdate('Y-m-d H:i:s', time() + $snooze_hours * 3600);
		}
		// Compared with what a person sees: a snooze that ended reads as new,
		// so marking it new again changes nothing.
		$from = $inc->triage();
		if ($from === $state && $state !== IncidentRecord::TRIAGE_SNOOZED) {
			return false;
		}
		$now = gmdate('Y-m-d H:i:s');
		$inc->set('inc_triage', $state);
		$inc->set('inc_triage_time', $now);
		$inc->set('inc_triage_usr_user_id', $user_id);
		$inc->set('inc_snooze_until', $until);
		$inc->save();
		$data = array('from' => $from, 'to' => $state);
		if ($until !== null) {
			$data['until'] = $until;
		}
		IncidentEvent::record((int)$inc->key, IncidentEvent::KIND_TRIAGE, $now, $user_id, '', $data);
		return true;
	}

	/**
	 * Apply one "do" value to several incidents by id. Returns
	 * ['changed' => n, 'missing' => n]: an id that is no live incident is
	 * counted, never created or guessed at. A non-empty note (say, what fixed
	 * it) is added to every live incident in the selection, after its triage.
	 *
	 * @throws IncidentTriageException for a "do" value that is not offered
	 */
	public static function apply(array $ids, string $do, int $user_id, string $note = ''): array {
		list($state, $hours) = self::parse_do($do);
		$changed = 0;
		$missing = 0;
		foreach (array_unique(array_map('intval', $ids)) as $id) {
			$inc = self::load($id);
			if ($inc === null) {
				$missing++;
				continue;
			}
			if (self::set($inc, $state, $user_id, $hours)) {
				$changed++;
			}
			if (trim($note) !== '') {
				self::note($inc, $note, $user_id);
			}
		}
		return array('changed' => $changed, 'missing' => $missing);
	}

	/**
	 * Add a person's note. Empty text is refused; text is capped and stored
	 * as written, escaped where it is shown.
	 *
	 * @throws IncidentTriageException for an empty note
	 */
	public static function note(IncidentRecord $inc, string $text, int $user_id): void {
		$text = trim($text);
		if ($text === '') {
			throw new IncidentTriageException('Write something to add a note.');
		}
		if (mb_strlen($text) > self::NOTE_MAX) {
			$text = mb_substr($text, 0, self::NOTE_MAX);
		}
		IncidentEvent::record((int)$inc->key, IncidentEvent::KIND_NOTE, gmdate('Y-m-d H:i:s'), $user_id, $text);
	}

	/**
	 * Resolve every incident whose condition has cleared and that still needs
	 * a person, within the list's filters (node_id, source). Returns how many
	 * changed. A non-empty note is added to each one it resolved.
	 */
	public static function resolve_all_cleared(int $user_id, array $filters = array(), string $note = ''): int {
		$options = array('status' => IncidentRecord::STATUS_CLOSED, 'view' => 'needs_you', 'deleted' => false);
		foreach (array('node_id', 'source') as $k) {
			if (!empty($filters[$k])) {
				$options[$k] = $filters[$k];
			}
		}
		$n = 0;
		foreach (new MultiIncidentRecord($options) as $inc) {
			if (self::set($inc, IncidentRecord::TRIAGE_RESOLVED, $user_id)) {
				$n++;
				if (trim($note) !== '') {
					self::note($inc, $note, $user_id);
				}
			}
		}
		return $n;
	}

	/** One incident by id, or null when there is no such live incident. */
	public static function load(int $id): ?IncidentRecord {
		if ($id <= 0) {
			return null;
		}
		try {
			$inc = new IncidentRecord($id, TRUE);
		} catch (Throwable $e) {
			return null;
		}
		// A row that is not there loads as an object with no key.
		if (!$inc->key) {
			return null;
		}
		return trim((string)$inc->get('inc_delete_time')) === '' ? $inc : null;
	}
}
?>
