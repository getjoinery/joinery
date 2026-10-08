<?php
/**
 * IncidentNotice — the one header line on every admin page that says what
 * needs a person (incident_triage.md, Noticing):
 *
 *   "3 incidents need you, 1 still happening. 2 new problem reports."
 *
 * Red when a critical incident that needs you is still happening, amber when
 * any incident needs you, blue when only problem reports wait, and absent
 * when nothing does. It names no nodes, so it stays one line however large
 * the fleet grows; the Incidents page has the rest.
 *
 * Problem reports from sites stay on their own page (the bug_reports plugin)
 * and are counted here beside incidents, linked there (Q2). That plugin's own
 * notice yields to this line for the superadmins who see it.
 *
 * Reads STORED facts only, in one query per source, superadmin only.
 *
 * @version 1.0
 */
class IncidentNotice {

	public static function render(): string {
		if ((int)($_SESSION['permission'] ?? 0) < 10) {
			return '';
		}
		$counts = IncidentRecord::needs_you_counts();
		$reports = 0;
		if (class_exists('ReceivedBugReport')) {
			try {
				$reports = count(new MultiReceivedBugReport(array('status' => ReceivedBugReport::STATUS_NEW)));
			} catch (Throwable $e) {
				$reports = 0; // its table is not built yet
			}
		}
		return self::line_for($counts, $reports);
	}

	/** The number beside Server Manager → Incidents: incidents that need a person. */
	public static function menu_count(): int {
		if ((int)($_SESSION['permission'] ?? 0) < 10) {
			return 0;
		}
		return IncidentRecord::needs_you_counts()['needs_you'];
	}

	/**
	 * The line for a set of counts (IncidentRecord::needs_you_counts) and a
	 * number of new problem reports. Public and pure so the wording and the
	 * colour can be tested.
	 */
	public static function line_for(array $counts, int $reports): string {
		$need = (int)($counts['needs_you'] ?? 0);
		$active = (int)($counts['active'] ?? 0);
		$critical = (int)($counts['critical'] ?? 0);
		if ($need === 0 && $reports === 0) {
			return '';
		}
		$parts = array();
		$links = array();
		if ($need > 0) {
			$parts[] = $need . ' incident' . ($need === 1 ? ' needs' : 's need') . ' you'
				. ($active > 0 ? ', ' . $active . ' still happening' : '') . '.';
			$links[] = '<a href="' . IncidentViews::LIST_URL . '" class="alert-link">Review</a>';
		}
		if ($reports > 0) {
			$parts[] = $reports . ' new problem report' . ($reports === 1 ? '' : 's') . ' from sites.';
			$links[] = '<a href="/plugins/bug_reports/admin/admin_bug_reports" class="alert-link">Read ' . ($reports === 1 ? 'it' : 'them') . '</a>';
		}
		$cls = $critical > 0 ? 'alert-danger' : ($need > 0 ? 'alert-warning' : 'alert-info');
		return '<div class="alert ' . $cls . '" role="' . ($critical > 0 ? 'alert' : 'status') . '"><div class="alert-body"><strong>'
			. htmlspecialchars(implode(' ', $parts), ENT_QUOTES, 'UTF-8') . '</strong> ' . implode(' · ', $links) . '</div></div>';
	}
}
?>
