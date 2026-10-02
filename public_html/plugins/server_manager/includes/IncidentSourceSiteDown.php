<?php
/**
 * IncidentSourceSiteDown — plane:site_down: uptime monitoring says the site
 * is down (incident_triage.md, Types; WP2).
 *
 * Reads what RunNodeUptimeChecks stored: mgn_uptime_last_status is 'down'
 * after two failing probes in a row, and goes back to 'up' on the first that
 * passes. A probe that could not conclude (this machine's own resolver
 * failed, the check is misconfigured) never changes it, so it never opens
 * or clears this incident. Critical: visitors cannot reach the site.
 *
 * @version 1.0
 */
class IncidentSourceSiteDown implements IncidentSource {

	const NAME = 'plane:site_down';

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		if (!$node->get('mgn_enabled') || !$node->get('mgn_uptime_enabled')) {
			return null;
		}
		if ((string)$node->get('mgn_uptime_last_status') !== 'down') {
			return null;
		}
		$detail = array();
		$url = trim((string)$node->get('mgn_site_url'));
		if ($url !== '') {
			$detail['Site'] = $url;
		}
		$detail['Check'] = (string)$node->get('mgn_uptime_check_type');
		$since = trim((string)$node->get('mgn_uptime_down_since'));
		if ($since !== '') {
			$detail['Down since'] = $since . ' UTC';
		}
		$why = trim((string)$node->get('mgn_uptime_down_reason'));
		if ($why !== '') {
			$detail['What the check saw'] = $why;
		}
		return array(
			'title'    => 'The site does not answer',
			'severity' => IncidentRecord::SEVERITY_CRITICAL,
			'detail'   => $detail,
		);
	}

	public function cleared_text(ManagedNode $node): string {
		if (!$node->get('mgn_enabled') || !$node->get('mgn_uptime_enabled')) {
			return 'Uptime monitoring for this node was turned off, so whether the site answers is no longer checked.';
		}
		return 'The site answers again.';
	}
}
?>
