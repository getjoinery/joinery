<?php
/**
 * RecipeCaseNotice — the admin-header notice on a box whose own agent has an
 * open case, and the one daily superadmin mail about it
 * (specs/agent_tier1_recipes.md, "The case", settled Q3 "Unpaired").
 *
 * A recipe compiled into the agent checks one aspect of this host every ten
 * minutes and repairs it when it can. When it gives up — three attempts in an
 * hour and the check still fails — it opens a case. On a paired node the case
 * rides the poll to the management node, whose node page shows it. On an
 * unpaired node there is nobody to ride to, so the agent writes a rendered
 * copy of the case outward under this site's cache directory
 * (cache/recipes/<recipe>.case.json), and this is what shows it: the notice
 * on every admin page, and one plain-text mail per recipe per day to the
 * superadmins (tasks/RecipeCaseMail.php). The notice shows on a paired node
 * too, saying the management node has the case; the mail is the unpaired
 * path only, because the plane's card and notice already carry it.
 *
 * Reads STORED facts only: the rendered files. Nothing here probes, and
 * nothing here can act. The file is written by the agent (root) and readable
 * by the web user; the web user can also forge one, which is a lie to the
 * admin and one mail, nothing root acts on — so the notice and the mail say
 * "as reported by the agent's ledger" and carry no link that acts. The agent
 * never reads the file back.
 *
 * @version 1.0
 */
class RecipeCaseNotice {

	/** The one wording, so a forged record is still labelled as a report. */
	const AS_REPORTED = "as reported by the agent's ledger";

	/** The delivery a rendered case names when a management node is polling for it. */
	const DELIVERY_PLANE = 'management node';

	public static function render(): string {
		if ((int)($_SESSION['permission'] ?? 0) < 10) {
			return '';
		}
		$out = '';
		foreach (self::records() as $rec) {
			$out .= self::forRecord($rec);
		}
		return $out;
	}

	/**
	 * Every rendered case under the site's cache directory, decoded and
	 * bounded, keyed by recipe. A file that does not decode to an object is
	 * skipped. Public so the task and the tests read the same thing.
	 */
	public static function records(?string $site_root = null): array {
		$site_root = $site_root ?? PathHelper::getSiteRoot();
		$out = array();
		foreach (glob($site_root . '/cache/recipes/*.case.json') ?: array() as $path) {
			if (!preg_match('#/([a-z][a-z0-9_]{2,39})\.case\.json$#', $path, $m)) {
				continue;
			}
			$raw = @file_get_contents($path, false, null, 0, 262144);
			$rec = is_string($raw) ? json_decode($raw, true) : null;
			if (!is_array($rec) || array_is_list($rec)) {
				continue;
			}
			$rec['recipe'] = $m[1];
			$out[$m[1]] = $rec;
		}
		ksort($out);
		return $out;
	}

	/** A bounded plain string out of a decoded record. */
	public static function text($value, int $max = 512): string {
		if (!is_string($value)) {
			return is_scalar($value) ? (string)$value : '';
		}
		if (!mb_check_encoding($value, 'UTF-8')) {
			$value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
		}
		$value = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value));
		return mb_strlen($value, 'UTF-8') > $max ? mb_substr($value, 0, $max, 'UTF-8') . '…' : $value;
	}

	/** The notice for one decoded record. Public and pure so the wording can be tested. */
	public static function forRecord(array $rec): string {
		if (($rec['status'] ?? '') !== 'open') {
			return '';
		}
		$e = function ($v) { return htmlspecialchars(self::text($v), ENT_QUOTES, 'UTF-8'); };
		$recipe = self::text($rec['recipe'] ?? '');
		$id = (int)($rec['id'] ?? 0);
		$paired = ($rec['delivery'] ?? '') === self::DELIVERY_PLANE;
		$lead = 'The ' . $recipe . ' recipe on this host has given up: case #' . $id . ' is open, ' . self::AS_REPORTED . '.';
		$body = 'Its check has failed since ' . self::text($rec['opened'] ?? 'an unknown time') . ' and three repair attempts in an hour '
			. 'did not fix it. Reason: ' . self::text($rec['reason'] ?? '') . '.';
		$notes = (int)($rec['notes'] ?? 0);
		if ($notes > 0) {
			$body .= ' ' . $notes . ' failing check' . ($notes === 1 ? '' : 's') . ' since; the newest says: ' . self::text($rec['last_note'] ?? '') . '.';
		}
		$mode = self::text($rec['body']['mode'] ?? '');
		if ($mode === 'report-only') {
			$body .= ' The recipe is report-only this release: it recorded what it would have run and changed nothing.';
		}
		$body .= $paired
			? ' The management node this host is paired to has the case too.'
			: ' This host is not paired to a management node, so a person is the next actor. The case closes itself when the check passes.';
		return self::css()
			. '<div class="jy-case-notice" role="alert"><strong>' . $e($lead) . '</strong> ' . $e($body) . '</div>';
	}

	/**
	 * The plain-text mail for one record: no markup, no link. Public and pure
	 * so a test can hold it to both.
	 */
	public static function mail_body(array $rec, string $site_name): string {
		$plain = function ($v, $max = 512) {
			// Plain text out: strip anything a mail client could read as markup
			// or follow as a link. The sender switches to its HTML template on
			// the first tag it sees, so none may survive.
			$s = self::text($v, $max);
			$s = str_replace(array('<', '>'), array('(', ')'), $s);
			return preg_replace('#[a-z][a-z0-9+.-]*://#i', '', $s);
		};
		$recipe = $plain($rec['recipe'] ?? '');
		$lines = array();
		$lines[] = 'The ' . $recipe . ' recipe on ' . $plain($site_name) . ' has given up, ' . self::AS_REPORTED . '.';
		$lines[] = '';
		$lines[] = 'Case #' . (int)($rec['id'] ?? 0) . ' (' . $plain($rec['source'] ?? '') . '), open since ' . $plain($rec['opened'] ?? 'an unknown time') . '.';
		$lines[] = 'Reason: ' . $plain($rec['reason'] ?? '');
		$notes = (int)($rec['notes'] ?? 0);
		if ($notes > 0) {
			$lines[] = 'Failing checks since: ' . $notes . '. Newest: ' . $plain($rec['last_note'] ?? '');
		}
		$body = isset($rec['body']) && is_array($rec['body']) ? $rec['body'] : array();
		$mode = $plain($body['mode'] ?? '');
		if ($mode !== '') {
			$lines[] = 'Mode: ' . $mode . ($mode === 'report-only' ? ' (recorded what it would have run; changed nothing)' : '');
		}
		$attempts = isset($body['attempts']) && is_array($body['attempts']) ? array_slice($body['attempts'], 0, 10) : array();
		if (count($attempts) > 0) {
			$lines[] = '';
			$lines[] = 'What the agent tried:';
			foreach ($attempts as $a) {
				if (!is_array($a)) { continue; }
				$lines[] = '  ' . $plain($a['started'] ?? '') . '  ' . $plain($a['word'] ?? '') . ': ' . $plain($a['outcome'] ?? '')
					. (trim((string)($a['detail'] ?? '')) !== '' ? ' - ' . $plain($a['detail'] ?? '', 200) : '');
			}
		}
		$lines[] = '';
		$lines[] = 'This host is not paired to a management node, so a person is the next actor.';
		$lines[] = 'The case closes itself when the recipe\'s check passes. The full record is ' . 'cache/recipes/' . $recipe . '.case.json under the site root,';
		$lines[] = 'and the recipe\'s ledger is beside it. This is sent at most once a day per recipe while the case is open.';
		return implode("\n", $lines) . "\n";
	}

	private static function css(): string {
		static $sent = false;
		if ($sent) return '';
		$sent = true;
		return '<style>'
			. '.jy-case-notice{margin:0 0 1rem;padding:.75rem 1rem;border:1px solid #fca5a5;border-radius:6px;background:#fef2f2;color:#7f1d1d;font-size:.95rem;overflow-wrap:anywhere}'
			. '</style>';
	}
}
?>
