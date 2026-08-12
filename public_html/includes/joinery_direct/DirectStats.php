<?php
/**
 * DirectStats - what Joinery Direct did lately, for the operator.
 *
 * Silence is a wire posture toward SENDERS, never toward the operator. Every
 * request-level refusal and every send-side downgrade is a fact worth seeing,
 * because the failure mode this channel is prone to is invisible by design: a
 * box whose clock has drifted fails every inbound freshness check and quietly
 * falls back to SMTP on every outbound send, with no user-visible symptom at
 * all. Mail keeps flowing, nothing is marked verified, and nobody notices for
 * weeks.
 *
 * The counters come from Direct's own request log, which the receiver and the
 * mail adapter already write as they go — no separate metrics store, and no
 * counting that could itself fail and take a delivery with it.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectProtocol.php'));

class DirectStats {

	/**
	 * A window's worth of activity.
	 *
	 * @return array{delivered:int,refused:int,downgrades:array<string,int>,
	 *               downgrade_total:int,peers:int,held:int,held_bytes:int,reasons:array<string,int>}
	 */
	public static function summary(int $window_hours = 24): array {
		$db = DbConnector::get_instance()->get_db_link();
		$since = "now() - (interval '1 hour' * " . max(1, $window_hours) . ")";

		$counts = array('delivered' => 0, 'refused' => 0, 'downgrade_total' => 0, 'peers' => 0);
		$downgrades = array();

		$stmt = $db->prepare("SELECT rql_action, COUNT(*) AS n FROM rql_request_logs
			WHERE rql_feature = ? AND rql_create_time > $since GROUP BY rql_action");
		$stmt->execute(array(DirectProtocol::LOG_FEATURE));
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$action = (string)$row['rql_action'];
			$n = (int)$row['n'];
			if ($action === 'delivered') {
				$counts['delivered'] += $n;
			} elseif ($action === 'refused') {
				$counts['refused'] += $n;
			} elseif (strpos($action, 'downgrade:') === 0) {
				$downgrades[substr($action, 10)] = $n;
				$counts['downgrade_total'] += $n;
			} elseif (strpos($action, 'preflight:') === 0) {
				$counts['peers']++;
			}
		}

		// WHY a refusal happened is the diagnostic; the note carries it. Withheld
		// on a sealed-hot request, which is why this reads as a distribution and
		// never as a complete ledger.
		$reasons = array();
		$stmt = $db->prepare("SELECT COALESCE(rql_note, '') AS reason, COUNT(*) AS n FROM rql_request_logs
			WHERE rql_feature = ? AND rql_action = 'refused' AND rql_create_time > $since
			GROUP BY 1 ORDER BY 2 DESC LIMIT 10");
		$stmt->execute(array(DirectProtocol::LOG_FEATURE));
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$reasons[(string)$row['reason'] !== '' ? (string)$row['reason'] : 'unrecorded'] = (int)$row['n'];
		}

		$held = array('held' => 0, 'held_bytes' => 0);
		try {
			require_once(PathHelper::getIncludePath('data/direct_spool_class.php'));
			$stmt = $db->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(jdp_bytes), 0) AS bytes
				FROM jdp_direct_spool WHERE jdp_state IN (?, ?) AND jdp_delete_time IS NULL');
			$stmt->execute(array(DirectSpool::STATE_STAGING, DirectSpool::STATE_HELD));
			$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: array();
			$held['held'] = (int)($row['n'] ?? 0);
			$held['held_bytes'] = (int)($row['bytes'] ?? 0);
		} catch (\Throwable $e) {
			// The spool table not existing yet is not a reason to fail the page.
			error_log('DirectStats: could not read the spool: ' . $e->getMessage());
		}

		return array_merge($counts, $held, array('downgrades' => $downgrades, 'reasons' => $reasons));
	}

	/**
	 * The one sentence an operator needs.
	 *
	 * Written to name the diagnosis rather than the symptom: "every attempt fell
	 * back" is what a clock-drifted or record-less deployment looks like from
	 * here, and saying so is the whole point of the surface.
	 */
	public static function headline(array $summary): string {
		$attempts = $summary['delivered'] + $summary['downgrade_total'];
		if ($attempts === 0) {
			// Inbound activity is a different sentence from outbound, and saying
			// "0 of 0 attempts went directly" about a box that simply has not sent
			// anything reads as a fault where there is none.
			return $summary['refused'] > 0
				? 'Nothing has been sent over Joinery Direct in this window; inbound attempts were turned away (see below).'
				: 'Nothing has used Joinery Direct in this window.';
		}
		if ($summary['delivered'] === 0) {
			return 'Every outbound attempt fell back to ordinary email. Check that this domain\'s '
				. 'Joinery Direct records are published and that the server clock is right.';
		}
		if ($summary['delivered'] > 0 && $summary['downgrade_total'] === 0) {
			return 'Every outbound attempt went directly.';
		}
		return $summary['delivered'] . ' of ' . $attempts . ' outbound attempts went directly; '
			. 'the rest fell back to ordinary email.';
	}
}
