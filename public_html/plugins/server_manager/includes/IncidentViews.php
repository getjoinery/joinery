<?php
/**
 * IncidentViews — how an incident looks wherever it is shown: the Incidents
 * list, one incident's page, and the node page's Incidents card
 * (incident_triage.md, Pages).
 *
 * Pure over the rows it is handed, so a test can give it hostile ones and
 * read the HTML back. Writes are never made here: the triage controls are
 * POST forms to the incident pages, which call IncidentTriage.
 *
 * WHO IS HOSTILE: the node, for an agent case's reason, close reason, notes
 * and body; a person, for a note. Everything is escaped as it is printed, and
 * nothing a node or a note said becomes a link. The only links here are the
 * plane's own pages by id.
 *
 * @version 1.2 - the triage badge names a fix awaiting its proof; Looking is gone; the timeline shows the
 *                reconciler putting an unproven fix back to new
 * @version 1.1 - Resolve asks what fixed it in a modal (resolve_note_field, resolve_note_script)
 * @version 1.0
 */
class IncidentViews {

	const LIST_URL = '/admin/server_manager/incidents';
	const PAGE_URL = '/admin/server_manager/incident';

	/** One incident's page. */
	public static function url(int $id): string {
		return self::PAGE_URL . '?id=' . $id;
	}

	public static function e($v): string {
		return htmlspecialchars(is_scalar($v) ? (string)$v : '', ENT_QUOTES, 'UTF-8');
	}

	/** A stored UTC time as an age, in the viewer's timezone. */
	public static function when($stored): string {
		$stored = trim((string)$stored);
		if ($stored === '') {
			return 'unknown';
		}
		return LibraryFunctions::time_ago($stored, SessionControl::get_instance()->get_timezone());
	}

	public static function severity_badge(IncidentRecord $inc): string {
		return $inc->is_critical()
			? '<span class="badge bg-danger">Critical</span>'
			: '<span class="badge bg-warning">Warning</span>';
	}

	/** Whether the condition is still there, as its source last said. */
	public static function condition_badge(IncidentRecord $inc): string {
		return $inc->is_open()
			? '<span class="badge bg-danger">Happening now</span>'
			: '<span class="badge bg-secondary">Cleared</span>';
	}

	/** What a person is doing about it. */
	public static function triage_badge(IncidentRecord $inc): string {
		$t = $inc->triage();
		$cls = array(
			IncidentRecord::TRIAGE_NEW      => 'bg-primary',
			IncidentRecord::TRIAGE_SNOOZED  => 'bg-secondary',
			IncidentRecord::TRIAGE_RESOLVED => $inc->awaiting_proof() ? 'bg-info' : 'bg-success',
			IncidentRecord::TRIAGE_IGNORED  => 'bg-light text-dark',
		);
		$label = IncidentTriage::label($inc);
		if ($t === IncidentRecord::TRIAGE_SNOOZED) {
			$label .= ' until ' . LibraryFunctions::convert_time((string)$inc->get('inc_snooze_until'), 'UTC',
				SessionControl::get_instance()->get_timezone(), 'M j, g:i A');
		}
		return '<span class="badge ' . ($cls[$t] ?? 'bg-secondary') . '">' . self::e($label) . '</span>';
	}

	/** A person's name, by id, cached for the request. */
	public static function person(?int $user_id): string {
		static $names = array();
		if (!$user_id) {
			return '';
		}
		if (!isset($names[$user_id])) {
			try {
				$u = new User($user_id, TRUE);
				$names[$user_id] = trim((string)$u->display_name()) ?: ('user #' . $user_id);
			} catch (Throwable $e) {
				$names[$user_id] = 'user #' . $user_id;
			}
		}
		return $names[$user_id];
	}

	/** A node's number and name, by id, cached for the request. */
	public static function node_label(int $node_id): string {
		static $labels = array();
		if (!isset($labels[$node_id])) {
			try {
				$n = new ManagedNode($node_id, TRUE);
				$labels[$node_id] = '#' . $node_id . ' ' . (string)$n->get('mgn_name');
			} catch (Throwable $e) {
				$labels[$node_id] = '#' . $node_id;
			}
		}
		return $labels[$node_id];
	}

	/** How many of a node's incidents that need nobody the node card shows. */
	const NODE_OTHERS_SHOWN = 10;

	/** A node's incidents: every one that needs a person, then the newest others. */
	public static function for_node(int $node_id): array {
		$out = array();
		$seen = array();
		foreach (new MultiIncidentRecord(array('node_id' => $node_id, 'view' => 'needs_you', 'deleted' => false),
			array('inc_incident_record_id' => 'DESC')) as $inc) {
			$out[] = $inc;
			$seen[(int)$inc->key] = true;
		}
		$others = 0;
		foreach (new MultiIncidentRecord(array('node_id' => $node_id, 'deleted' => false),
			array('inc_incident_record_id' => 'DESC'), count($seen) + self::NODE_OTHERS_SHOWN) as $inc) {
			if (isset($seen[(int)$inc->key]) || $others >= self::NODE_OTHERS_SHOWN) { continue; }
			$out[] = $inc;
			$others++;
		}
		return $out;
	}

	/**
	 * What the source said, short, under the title. An agent case's reason
	 * carries its finding in parentheses ("... it still fails (fail2ban is
	 * inactive); ..."): that part is shown, labelled as the node's account.
	 */
	public static function summary(IncidentRecord $inc): string {
		$reason = trim((string)$inc->get('inc_reason'));
		if ($reason === '') {
			return '';
		}
		// The first parenthesised part, allowing one level of nesting.
		if (preg_match('/\(((?:[^()]|\([^()]*\))+)\)/', $reason, $m)) {
			$reason = $m[1];
		}
		if (mb_strlen($reason) > 120) {
			$reason = mb_substr($reason, 0, 117) . '...';
		}
		return 'The node says: ' . $reason;
	}

	/**
	 * The incident table. Options:
	 *   show_node  - the node under each title (the list; not the node's own page)
	 *   select     - a checkbox per row, named ids[], in the form with this id
	 */
	public static function table(array $incidents, array $opts = array()): string {
		$show_node = !empty($opts['show_node']);
		$form = (string)($opts['select'] ?? '');
		$html = '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr>';
		if ($form !== '') {
			$html .= '<th class="svm-incident-pick"><span class="jy-visually-hidden">Select</span></th>';
		}
		$html .= '<th>Incident</th><th>Started</th><th>Status</th></tr></thead><tbody>';
		foreach ($incidents as $inc) {
			$id = (int)$inc->key;
			$html .= '<tr>';
			if ($form !== '') {
				$html .= '<td><input type="checkbox" name="ids[]" value="' . $id . '" form="' . self::e($form) . '" aria-label="Select incident ' . $id . '"></td>';
			}
			$html .= '<td>' . self::severity_badge($inc) . ' <a href="' . self::e(self::url($id)) . '"><strong>' . self::e($inc->title()) . '</strong></a>';
			$under = array();
			if ($show_node) {
				$node_id = (int)$inc->get('inc_mgn_managed_node_id');
				$under[] = '<a href="/admin/server_manager/node_detail?mgn_managed_node_id=' . $node_id . '">' . self::e(self::node_label($node_id)) . '</a>';
			}
			$summary = self::summary($inc);
			if ($summary !== '') {
				$under[] = self::e($summary);
			}
			if ($under) {
				$html .= '<div class="small text-muted">' . implode(' &middot; ', $under) . '</div>';
			}
			$html .= '</td>';
			$html .= '<td class="text-nowrap">' . self::e(self::when($inc->get('inc_opened_time') ?: $inc->get('inc_create_time'))) . '</td>';
			$by = self::person((int)$inc->get('inc_triage_usr_user_id'));
			$html .= '<td><div class="d-flex flex-wrap gap-1">' . self::condition_badge($inc) . self::triage_badge($inc) . '</div>'
				. ($by !== '' && $inc->triage() !== IncidentRecord::TRIAGE_NEW ? '<div class="small text-muted">by ' . self::e($by) . '</div>' : '') . '</td>';
			$html .= '</tr>';
		}
		$html .= '</tbody></table></div>';
		return $html;
	}

	/** A two-column table of label => HTML (the HTML is the caller's, already escaped). */
	public static function facts(array $rows): string {
		$html = '<table class="table table-sm mb-0"><tbody>';
		foreach ($rows as $label => $value_html) {
			$html .= '<tr><th scope="row" class="text-muted text-nowrap">' . self::e($label) . '</th><td>' . $value_html . '</td></tr>';
		}
		return $html . '</tbody></table>';
	}

	/** The timeline, oldest first. */
	public static function timeline(array $events): string {
		if (count($events) === 0) {
			return '<p class="text-muted mb-0">Nothing recorded yet.</p>';
		}
		$html = '<ol class="list-unstyled mb-0">';
		foreach ($events as $ev) {
			$kind = (string)$ev->get('ine_kind');
			$who = self::person((int)$ev->get('ine_usr_user_id'));
			$text = trim((string)$ev->get('ine_text'));
			$data = $ev->data();
			switch ($kind) {
				case IncidentEvent::KIND_OPENED:
					$line = '<strong>Opened.</strong>' . ($text !== '' ? ' The node said: ' . self::e($text) : '');
					break;
				case IncidentEvent::KIND_CLEARED:
					$line = '<strong>Cleared.</strong>' . ($text !== '' ? ' ' . self::e($text) : '');
					break;
				case IncidentEvent::KIND_REOPENED:
					$line = '<strong>Came back.</strong>' . ($text !== '' ? ' ' . self::e($text) : '');
					break;
				case IncidentEvent::KIND_TRIAGE:
					$to = (string)($data['to'] ?? '');
					// A triage with no person is the reconciler's: a fix whose proof never came.
					if (!empty($data['unproven'])) {
						$line = '<strong>Back to New.</strong>' . ($text !== '' ? ' ' . self::e($text) : '');
						break;
					}
					// A state no longer offered (Looking) still reads as its word.
					$line = self::e($who !== '' ? $who : 'Someone') . ' set it to <strong>' . self::e(IncidentTriage::LABELS[$to] ?? ucfirst($to)) . '</strong>';
					if ($to === IncidentRecord::TRIAGE_SNOOZED && !empty($data['until'])) {
						$line .= ' until ' . self::e(LibraryFunctions::convert_time((string)$data['until'], 'UTC',
							SessionControl::get_instance()->get_timezone(), 'M j, g:i A'));
					}
					if (!empty($data['carried'])) {
						$line .= ' <span class="text-muted">(' . self::e($data['carried']) . ')</span>';
					}
					$line .= '.';
					break;
				case IncidentEvent::KIND_NOTE:
					$line = self::e($who !== '' ? $who : 'Someone') . ' wrote:<div class="border-start ps-2 mt-1 svm-incident-text">' . self::e($text) . '</div>';
					break;
				case IncidentEvent::KIND_ANALYSIS:
					$status = (string)($data['status'] ?? '');
					if ($status === IncidentAnalyst::STATUS_DONE && isset($data['answer']['cause'])) {
						$cause = (string)$data['answer']['cause'];
						$line = '<strong>Analysis</strong> (' . self::e($data['answer']['confidence'] ?? '') . ' confidence): '
							. self::e(mb_strlen($cause) > 200 ? mb_substr($cause, 0, 197) . '...' : $cause);
					} elseif ($status === IncidentAnalyst::STATUS_FAILED) {
						$line = '<strong>Analysis did not finish.</strong> ' . self::e($data['reason'] ?? '');
					} elseif (IncidentAnalyst::is_running($ev)) {
						$line = self::e($who !== '' ? $who : 'Someone') . ' asked for an analysis; it is running.';
					} else {
						$line = '<strong>Analysis did not finish:</strong> its worker stopped.';
					}
					break;
				default:
					$line = self::e($kind) . ($text !== '' ? ': ' . self::e($text) : '');
			}
			$html .= '<li class="mb-2"><span class="small text-muted text-nowrap">' . self::e(self::when($ev->get('ine_time'))) . '</span> &middot; ' . $line . '</li>';
		}
		return $html . '</ol>';
	}

	/**
	 * What the source attached: for an agent case, the failing checks since
	 * it opened and the body (what the node tried, its host at the time).
	 */
	public static function evidence(IncidentRecord $inc): string {
		$html = '';
		$reason = trim((string)$inc->get('inc_reason'));
		if ($reason !== '') {
			$html .= '<div><span class="text-muted">The node says:</span> ' . self::e($reason) . '</div>';
		}
		$notes = (int)$inc->get('inc_note_count');
		if ($notes > 0) {
			$html .= '<div class="small text-muted mt-1 svm-incident-text">' . $notes . ' failing check' . ($notes === 1 ? '' : 's') . ' since; newest '
				. self::e(self::when($inc->get('inc_last_note_time'))) . ': ' . self::e($inc->get('inc_last_note')) . '</div>';
		}
		$body = $inc->body();
		if (is_array($body)) {
			$attempts = isset($body['attempts']) && is_array($body['attempts']) ? $body['attempts'] : array();
			$html .= '<div class="mt-3"><strong>What the node tried</strong> <span class="small text-muted">(' . self::e($body['mode'] ?? 'unknown') . ')</span></div>';
			if (count($attempts) === 0) {
				$html .= '<div class="small text-muted">No attempts recorded.</div>';
			} else {
				$html .= '<ul class="small mb-1">';
				foreach ($attempts as $a) {
					if (!is_array($a)) { continue; }
					$html .= '<li class="svm-incident-text">' . self::e(self::when($a['started'] ?? '')) . ' ' . self::e($a['word'] ?? '') . ': <strong>' . self::e($a['outcome'] ?? '') . '</strong>'
						. (trim((string)($a['detail'] ?? '')) !== '' ? ' — ' . self::e($a['detail']) : '') . '</li>';
				}
				$html .= '</ul>';
			}
			$hr = $body['host_report'] ?? 'unknown';
			if (is_array($hr)) {
				$units = array();
				foreach ((array)($hr['expected_units'] ?? array()) as $unit => $state) {
					$units[] = self::e($unit) . ' ' . self::e($state);
				}
				$failed = $hr['failed_units'] ?? 'unknown';
				$html .= '<div class="small">The host at the time: ' . implode(', ', $units)
					. '; failed units: ' . (is_array($failed) ? (count($failed) ? self::e(implode(', ', array_map('strval', $failed))) : 'none') : self::e($failed))
					. '.</div>';
			} else {
				$html .= '<div class="small text-muted">Host report: ' . self::e($hr) . '</div>';
			}
			// The node sends both as comma lists with no spaces; spaced, they wrap.
			$spaced = function ($v) { return str_replace(',', ', ', is_scalar($v) ? (string)$v : ''); };
			$html .= '<div class="small text-muted svm-incident-text">Agent words: ' . self::e($spaced($body['vocabulary'] ?? '')) . '; recipes: ' . self::e($spaced($body['recipes'] ?? '')) . '</div>';
		}
		$detail = $inc->get('inc_detail');
		if (is_string($detail)) { $detail = json_decode($detail, true); }
		if (is_array($detail) && $detail) {
			$rows = array();
			foreach ($detail as $k => $v) {
				$rows[(string)$k] = '<span class="svm-incident-text">' . self::e(is_scalar($v) ? $v : json_encode($v)) . '</span>';
			}
			$html .= '<div class="mt-3 small">' . self::facts($rows) . '</div>';
		}
		return $html !== '' ? $html : '<p class="text-muted mb-0">The source attached nothing more.</p>';
	}

	/**
	 * The latest analysis of an incident: running, its answer, or why there is
	 * none. Everything in an answer came from a model that read node text, so
	 * it is escaped like node text; a suggested step is a value from a closed
	 * list, shown as words with the node page to take it from.
	 */
	public static function analysis(?IncidentEvent $e, int $node_id): string {
		if ($e === null) {
			return '<p class="text-muted mb-0">Not analyzed yet. Analyze asks a model to read this incident and the node\'s facts, '
				. 'ask the node for up to ' . IncidentAnalyst::MAX_WORDS . ' read-only reports, and say what it thinks is wrong and what to do. '
				. 'It recommends; it never acts.</p>';
		}
		$data = $e->data();
		$status = (string)($data['status'] ?? '');
		$who = self::person((int)$e->get('ine_usr_user_id'));
		$asked = 'Asked ' . self::when($e->get('ine_time')) . ($who !== '' ? ' by ' . $who : '');
		if (IncidentAnalyst::is_running($e)) {
			return '<p class="mb-0"><span class="badge bg-info">Running</span> ' . self::e($asked)
				. '. It can take a few minutes when it asks the node for reports; this page refreshes until it is done.</p>';
		}
		$meta = array();
		if (!empty($data['model'])) { $meta[] = self::e('Model: ' . $data['model']); }
		if (!empty($data['tokens'])) { $meta[] = (int)$data['tokens'] . ' tokens'; }
		$html = '';
		if ($status === IncidentAnalyst::STATUS_DONE && is_array($data['answer'] ?? null)) {
			$a = $data['answer'];
			$html .= '<div class="svm-incident-text mb-2">' . self::e($a['cause'] ?? '') . '</div>';
			$facts = array('How sure' => self::e(ucfirst((string)($a['confidence'] ?? ''))));
			if (!empty($a['evidence']) && is_array($a['evidence'])) {
				$facts['Evidence'] = '<ul class="mb-0">' . implode('', array_map(function ($l) { return '<li>' . self::e($l) . '</li>'; }, $a['evidence'])) . '</ul>';
			}
			$step = (string)($a['next_step'] ?? '');
			$facts['Suggested next step'] = self::e(IncidentAnalyst::NEXT_STEPS[$step] ?? $step)
				. (($a['next_step_detail'] ?? '') !== '' ? ': ' . self::e($a['next_step_detail']) : '')
				. ($step !== 'person_looks' ? ' <span class="small text-muted">(from the <a href="/admin/server_manager/node_detail?mgn_managed_node_id=' . $node_id . '">node page</a>)</span>' : '');
			$html .= self::facts($facts);
		} elseif ($status === IncidentAnalyst::STATUS_FAILED) {
			$html .= '<p class="mb-2">It did not finish: ' . self::e($data['reason'] ?? 'no reason recorded') . '</p>';
		} else {
			$html .= '<p class="mb-2">It did not finish: its worker stopped.</p>';
		}
		$words = array();
		foreach ((array)($data['words'] ?? array()) as $w) {
			if (!is_array($w)) { continue; }
			$args = array();
			foreach ((array)($w['args'] ?? array()) as $k => $v) { $args[] = $k . ' ' . $v; }
			$words[] = self::e($w['word'] ?? '') . ($args ? ' (' . self::e(implode(', ', $args)) . ')' : '');
		}
		if ($words) { $meta[] = 'Asked the node for: ' . implode('; ', $words); }
		if (!empty($data['refused'])) { $meta[] = 'Refused: ' . self::e(implode(', ', array_map('strval', (array)$data['refused']))); }
		// Every piece of $meta is HTML, escaped where it was built.
		$html .= '<div class="small text-muted mt-2">' . self::e($asked) . ($meta ? '. ' . implode(' &middot; ', $meta) : '') . '</div>';
		return $html;
	}

	/**
	 * The triage buttons for one incident: a single-button POST form per state
	 * other than the one it reads as now, each carrying the CSRF token.
	 */
	public static function triage_buttons(string $action, IncidentRecord $inc, string $csrf): string {
		$current = $inc->triage();
		$html = '<div class="d-flex flex-wrap align-items-center gap-2">';
		foreach (array(IncidentRecord::TRIAGE_RESOLVED, IncidentRecord::TRIAGE_IGNORED, IncidentRecord::TRIAGE_NEW) as $state) {
			if ($state === $current) { continue; }
			$cls = $state === IncidentRecord::TRIAGE_RESOLVED ? 'btn-success' : 'btn-outline-secondary';
			$html .= '<form method="post" action="' . self::e($action) . '" class="d-inline">'
				. '<input type="hidden" name="' . self::e(SmAdminCsrf::FIELD) . '" value="' . self::e($csrf) . '">'
				. '<input type="hidden" name="action" value="triage">'
				. '<input type="hidden" name="id" value="' . (int)$inc->key . '">'
				. '<input type="hidden" name="do" value="' . self::e($state) . '">'
				. ($state === IncidentRecord::TRIAGE_RESOLVED ? self::resolve_note_field(1) : '')
				. '<button type="submit" class="btn btn-sm ' . $cls . '">' . self::e(IncidentTriage::VERBS[$state]) . '</button>'
				. '</form>';
		}
		return $html . '</div>';
	}

	/**
	 * A FormWriter form with one "do" dropdown and a button: the snooze on an
	 * incident's page ($snooze_only), or the action applied to the rows
	 * selected on the list (their checkboxes join it by its id).
	 */
	public static function do_form(string $form_id, string $action, string $csrf, array $hidden, bool $snooze_only, string $button): string {
		$options = array();
		foreach (IncidentTriage::do_options() as $value => $words) {
			if ($snooze_only && strpos($value, 'snooze_') !== 0) { continue; }
			$options[$value] = $words;
		}
		$fw = new FormWriterV2HTML5($form_id, array('action' => $action, 'id' => $form_id));
		ob_start();
		$fw->begin_form();
		$fw->hiddeninput('action', '', array('value' => 'triage'));
		$fw->hiddeninput(SmAdminCsrf::FIELD, '', array('value' => $csrf));
		foreach ($hidden as $k => $v) {
			$fw->hiddeninput($k, '', array('value' => (string)$v));
		}
		if (!$snooze_only) {
			echo self::resolve_note_field(0);
		}
		$fw->dropinput('do', $snooze_only ? 'Snooze for' : 'With the selected', array(
			'options' => $options,
			'value' => $snooze_only ? 'snooze_24' : IncidentRecord::TRIAGE_RESOLVED,
		));
		$fw->submitbutton('btn_' . $form_id, $button, array('class' => 'btn btn-sm btn-outline-primary'));
		$fw->end_form();
		return ob_get_clean();
	}

	/**
	 * The empty note a resolve form carries. resolve_note_script() fills it
	 * from a modal when the form is about to resolve. $count is how many
	 * incidents it resolves; 0 means "the rows ticked for this form".
	 */
	public static function resolve_note_field(int $count): string {
		return '<input type="hidden" name="note" value="" data-resolve-note="' . $count . '">';
	}

	/**
	 * Once per page: before any form with a resolve_note_field() resolves,
	 * a modal asks what fixed it. Optional; what is written goes into the
	 * form's note and lands as a note on each incident's timeline. A form
	 * whose "do" is set to something else submits untouched.
	 */
	public static function resolve_note_script(): string {
		return <<<'JS'
<script>
(function () {
	document.addEventListener('submit', function (ev) {
		var form = ev.target;
		var note = form.querySelector('input[data-resolve-note]');
		if (!note || form.dataset.resolveNoteAsked === '1' || ev.defaultPrevented) { return; }
		var pick = form.querySelector('[name="do"]');
		if (pick && pick.value !== 'resolved') { return; }
		var count = parseInt(note.getAttribute('data-resolve-note'), 10) || 0;
		if (count === 0 && form.id) {
			count = document.querySelectorAll('input[form="' + form.id + '"][name="ids[]"]:checked').length;
			// Nothing ticked: let the page say so.
			if (count === 0) { return; }
		}
		ev.preventDefault();
		var submitter = ev.submitter || null;
		var box = document.createElement('div');
		box.innerHTML = '<label class="form-label" for="incident_resolve_note">What fixed it? <span class="text-muted">(optional)</span></label>'
			+ '<textarea id="incident_resolve_note" class="form-control" rows="4" maxlength="4000"'
			+ ' placeholder="What was wrong and what fixed it. A commit or release number helps."></textarea>'
			+ '<p class="small text-muted mt-2 mb-0">It goes on the timeline of '
			+ (count === 1 ? 'this incident' : 'each of the ' + count + ' incidents') + ' as a note.</p>';
		var text = box.querySelector('textarea');
		JoineryModal.open(box, {buttons: [
			{label: 'Cancel', style: 'secondary'},
			{label: count === 1 ? 'Resolve' : 'Resolve ' + count, style: 'success', onClick: function () {
				note.value = text.value;
				form.dataset.resolveNoteAsked = '1';
				form.requestSubmit(submitter);
			}}
		]});
		text.focus();
	});
})();
</script>
JS;
	}
}
?>