<?php
/**
 * IncidentSourceMailGateway — plane:mail_gateway: the mail apps gateway on the
 * node (plugins/mail_apps) has a site turned on and is not serving it, keeps
 * restarting, or its certificate for a site is near expiry.
 *
 * Read from the host report's mail_gateway (host_report.sh 1.20) through the
 * same sanitiser the Host card uses. Quiet where no site is turned on (an
 * installed gateway nobody uses is not a problem), and where the unit has
 * failed outright: plane:failed_units already names it, and one fault raises
 * one incident. Clears when a later report shows every site answering.
 *
 * Severity: critical when a site's mail programs cannot connect (no greeting
 * on 993 or 465, the unit not running) or its certificate has fewer than
 * CRITICAL_DAYS left; a warning for restarts and for a certificate inside
 * WARNING_DAYS.
 *
 * @version 1.0
 */
class IncidentSourceMailGateway implements IncidentSource {

	const NAME = 'plane:mail_gateway';

	/** Fewer days than this left on a site's certificate is critical. */
	const CRITICAL_DAYS = 7;
	/** Fewer days than this is a warning; certbot renews at 30. */
	const WARNING_DAYS = 14;
	/** This many automatic restarts within RESTART_WINDOW of the last start means it keeps crashing. */
	const RESTARTS = 3;
	const RESTART_WINDOW = 3600;

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		$g = self::gateway($node);
		if (!is_array($g) || !$g['sites'] || $g['state'] === 'failed') {
			return null;
		}
		$silent = array();
		$expiring = array();
		$critical_cert = false;
		foreach ($g['sites'] as $s) {
			$label = $s['name'] !== '' ? $s['name'] : $s['site'];
			$dead = array();
			if ($s['imap'] === 'no_answer') { $dead[] = 'IMAP (993)'; }
			if ($s['submission'] === 'no_answer') { $dead[] = 'sending (465)'; }
			if ($dead) {
				$silent[] = $label . ': ' . implode(' and ', $dead);
			}
			if (is_int($s['days_left']) && $s['days_left'] < self::WARNING_DAYS) {
				$expiring[] = $label . ': ' . ($s['days_left'] < 0 ? 'expired' : $s['days_left'] . ' days left');
				$critical_cert = $critical_cert || $s['days_left'] < self::CRITICAL_DAYS;
			}
		}
		$not_running = $g['state'] !== 'active' && $g['state'] !== 'unknown';
		$crashing = is_int($g['restarts']) && $g['restarts'] >= self::RESTARTS
			&& is_int($g['up_seconds']) && $g['up_seconds'] < self::RESTART_WINDOW;
		if (!$not_running && !$silent && !$expiring && !$crashing) {
			return null;
		}

		if ($not_running) {
			$title = 'The mail apps gateway is not running';
		} elseif ($silent) {
			$title = 'The mail apps gateway does not answer mail programs';
		} elseif ($crashing) {
			$title = 'The mail apps gateway keeps restarting';
		} else {
			$title = 'A mail apps certificate is about to expire';
		}
		$critical = $not_running || $silent || $critical_cert;
		$detail = array('Gateway' => 'version ' . $g['version'] . ', ' . $g['state']
			. (is_int($g['connections']) ? ', ' . $g['connections'] . ' open connection(s)' : ''));
		if ($silent) {
			$detail['Not answering'] = implode('; ', $silent);
		}
		if ($crashing) {
			$detail['Restarts'] = $g['restarts'] . ' automatic restart(s), up ' . intdiv($g['up_seconds'], 60) . ' min since the last';
		}
		if ($expiring) {
			$detail['Certificates'] = implode('; ', $expiring);
		}
		$detail['What to do'] = 'On the node page, Restart joinery-mail-gateway and read its journal (Why?). '
			. 'Members using Thunderbird or another mail program cannot reach their mail until it answers.';
		return array(
			'title'    => $title,
			'severity' => $critical ? IncidentRecord::SEVERITY_CRITICAL : IncidentRecord::SEVERITY_WARNING,
			'detail'   => $detail,
		);
	}

	public function cleared_text(ManagedNode $node): string {
		$g = self::gateway($node);
		if (!is_array($g) || !$g['sites']) {
			return 'No site on the machine has mail apps turned on any more.';
		}
		return 'The latest host report shows the mail apps gateway answering for every site.';
	}

	/** The stored report's mail_gateway, sanitised, or null where there is none. */
	private static function gateway(ManagedNode $node): ?array {
		$report = $node->get('mgn_last_host_report');
		if (is_string($report)) {
			$report = json_decode($report, true);
		}
		if (!is_array($report)) {
			return null;
		}
		$g = JobResultProcessor::sanitise_host_report($report)['mail_gateway'];
		return is_array($g) ? $g : null;
	}
}
?>
