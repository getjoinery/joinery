<?php
/**
 * IncidentCaseCard — the Cases card on the node page: the cases a node's
 * agent opened, open first, then closed, each with the note a human wrote
 * and a mark-read control (specs/agent_tier1_recipes.md, "The case").
 *
 * The card shows; it never closes. A case closes when the node's own check
 * passes and the node reports it, and a human here can only say what they
 * saw. Writes (the note, mark-read) are POST actions handled by
 * NodeDetailActions, carrying the admin CSRF token.
 *
 * WHO IS HOSTILE: the node that wrote the case. Every value here came off the
 * wire, was capped on intake, and is escaped again as it is printed. Nothing
 * in a case is ever a link; the only links on this card are the plane's own.
 *
 * @version 1.0
 */
class IncidentCaseCard {

	/** How many closed cases the card shows under the open ones. */
	const CLOSED_SHOWN = 10;

	/** The cases for one node, open first (newest first), then the newest closed. */
	public static function cases_for(int $node_id): array {
		$out = [];
		foreach (new MultiIncidentRecord(['node_id' => $node_id, 'status' => IncidentRecord::STATUS_OPEN, 'deleted' => false],
			['inc_id' => 'DESC']) as $row) {
			$out[] = $row;
		}
		foreach (new MultiIncidentRecord(['node_id' => $node_id, 'status' => IncidentRecord::STATUS_CLOSED, 'deleted' => false],
			['inc_id' => 'DESC'], self::CLOSED_SHOWN) as $row) {
			$out[] = $row;
		}
		return $out;
	}

	/** The card body for a node: every case, or the one line saying there are none. */
	public static function render_for_node($node, string $base_url): string {
		$cases = self::cases_for((int)$node->key);
		if (count($cases) === 0) {
			return '<p class="text-muted mb-0">No cases. A case opens when a recipe on the node gives up: three attempts in an hour '
				. 'and its check still fails. Report-only recipes open cases too; that is what the burn-in reads.</p>';
		}
		$html = '';
		foreach ($cases as $case) {
			$html .= self::render_case($case, $base_url, SmAdminCsrf::token());
		}
		return $html;
	}

	/**
	 * One case. Public and pure over the row so a test can hand it a hostile
	 * one and read the HTML back.
	 */
	public static function render_case(IncidentRecord $case, string $base_url, string $csrf_token): string {
		$e = function ($v) { return htmlspecialchars(is_scalar($v) ? (string)$v : '', ENT_QUOTES, 'UTF-8'); };
		$when = function ($stored) {
			$stored = trim((string)$stored);
			if ($stored === '') { return 'unknown'; }
			return gmdate('M j, H:i', strtotime($stored . ' UTC')) . ' UTC';
		};
		$open = $case->is_open();
		$id = (int)$case->key;
		$read = trim((string)$case->get('inc_read_time')) !== '';

		$html = '<div class="border rounded p-3 mb-3' . ($open ? ' border-danger' : '') . '">';
		$html .= '<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">';
		$html .= '<div><span class="badge bg-' . ($open ? 'danger' : 'secondary') . '">' . ($open ? 'open' : 'closed') . '</span> '
			. '<strong>' . $e($case->get('inc_source')) . '</strong> case #' . (int)$case->get('inc_node_case_id')
			. ($read ? ' <span class="badge bg-light text-dark">read</span>' : '') . '</div>';
		$html .= '<small class="text-muted">opened ' . $e($when($case->get('inc_opened_time')))
			. ($open ? '' : ', closed ' . $e($when($case->get('inc_closed_time')))) . '</small>';
		$html .= '</div>';

		$html .= '<div class="mt-2">' . $e($case->get('inc_reason')) . '</div>';
		$notes = (int)$case->get('inc_note_count');
		if ($notes > 0) {
			$html .= '<div class="small text-muted">' . $notes . ' failing tick' . ($notes === 1 ? '' : 's') . ' since; newest '
				. $e($when($case->get('inc_last_note_time'))) . ': ' . $e($case->get('inc_last_note')) . '</div>';
		}
		if (!$open) {
			$html .= '<div class="small">Closed: ' . $e($case->get('inc_close_reason')) . '</div>';
		}

		$body = $case->body();
		if (is_array($body)) {
			$html .= '<details class="mt-2"><summary class="small">What the node tried (' . $e($body['mode'] ?? 'unknown') . ')</summary>';
			$attempts = isset($body['attempts']) && is_array($body['attempts']) ? $body['attempts'] : [];
			if (count($attempts) === 0) {
				$html .= '<div class="small text-muted">No attempts in the body.</div>';
			} else {
				$html .= '<ul class="small mb-1">';
				foreach ($attempts as $a) {
					if (!is_array($a)) { continue; }
					$html .= '<li>' . $e($when($a['started'] ?? '')) . ' ' . $e($a['word'] ?? '') . ': <strong>' . $e($a['outcome'] ?? '') . '</strong>'
						. (trim((string)($a['detail'] ?? '')) !== '' ? ' — ' . $e($a['detail']) : '') . '</li>';
				}
				$html .= '</ul>';
			}
			$hr = $body['host_report'] ?? 'unknown';
			if (is_array($hr)) {
				$units = [];
				foreach ((array)($hr['expected_units'] ?? []) as $unit => $state) {
					$units[] = $e($unit) . ' ' . $e($state);
				}
				$failed = $hr['failed_units'] ?? 'unknown';
				$html .= '<div class="small">Host at the time: ' . implode(', ', $units)
					. '; failed units: ' . (is_array($failed) ? (count($failed) ? $e(implode(', ', array_map('strval', $failed))) : 'none') : $e($failed))
					. '.</div>';
			} else {
				$html .= '<div class="small text-muted">Host report: ' . $e($hr) . '</div>';
			}
			$html .= '<div class="small text-muted">Vocabulary: ' . $e($body['vocabulary'] ?? '') . '; recipes: ' . $e($body['recipes'] ?? '') . '</div>';
			$html .= '</details>';
		}

		// The human's note, and mark-read. Both POST, both through the node
		// page's action dispatcher.
		$note = (string)$case->get('inc_human_note');
		$fw = new FormWriterV2HTML5('case_note_' . $id, ['action' => $base_url . '&tab=overview', 'values' => ['case_note' => $note]]);
		ob_start();
		$fw->begin_form();
		$fw->hiddeninput('action', '', ['value' => 'case_note']);
		$fw->hiddeninput('inc_id', '', ['value' => (string)$id]);
		$fw->hiddeninput(SmAdminCsrf::FIELD, '', ['value' => $csrf_token]);
		$fw->textarea('case_note', 'Your note', ['rows' => 2, 'placeholder' => 'What you saw, what you did, where any backup is']);
		$fw->submitbutton('btn_case_note_' . $id, 'Save note', ['class' => 'btn btn-sm btn-outline-primary']);
		$fw->end_form();
		$html .= '<div class="mt-2">' . ob_get_clean() . '</div>';

		if (!$read) {
			$html .= '<form method="post" action="' . $e($base_url . '&tab=overview') . '" class="mt-1">'
				. '<input type="hidden" name="action" value="case_read">'
				. '<input type="hidden" name="inc_id" value="' . $id . '">'
				. '<input type="hidden" name="' . $e(SmAdminCsrf::FIELD) . '" value="' . $e($csrf_token) . '">'
				. '<button type="submit" class="btn btn-sm btn-outline-secondary">Mark read</button>'
				. '</form>';
		} else {
			$html .= '<div class="small text-muted mt-1">Read ' . $e($when($case->get('inc_read_time'))) . '.</div>';
		}
		$html .= '</div>';
		return $html;
	}
}
?>
