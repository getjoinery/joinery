<?php
/**
 * IncidentSourceCertificate — plane:certificate: the certificate the node
 * serves for its site has a problem (incident_triage.md, Types; WP3).
 *
 * Read from mgn_cert_problem, which the uptime task's certificate check
 * stores on every check: renewal overdue, expiry near, www reaching the
 * origin uncovered, or an origin presenting another name's certificate.
 * Critical once fewer than seven days remain (or it has expired); a warning
 * before that. Watched only while the node's uptime monitoring runs, since
 * that is what checks it.
 *
 * @version 1.0
 */
class IncidentSourceCertificate implements IncidentSource {

	const NAME = 'plane:certificate';

	/** Fewer days than this left on the certificate is critical. */
	const CRITICAL_DAYS = 7;

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		if (!$node->get('mgn_enabled') || !$node->get('mgn_uptime_enabled')) {
			return null;
		}
		$p = $node->get('mgn_cert_problem');
		if (is_string($p)) {
			$p = json_decode($p, true);
		}
		if (!is_array($p) || trim((string)($p['title'] ?? '')) === '') {
			return null;
		}
		$not_after = (int)($p['not_after'] ?? 0);
		$critical = $not_after > 0 && $not_after - time() < self::CRITICAL_DAYS * 86400;
		return array(
			'title'    => (string)$p['title'],
			'severity' => $critical ? IncidentRecord::SEVERITY_CRITICAL : IncidentRecord::SEVERITY_WARNING,
			'detail'   => is_array($p['detail'] ?? null) ? $p['detail'] : array(),
		);
	}

	public function cleared_text(ManagedNode $node): string {
		if (!$node->get('mgn_enabled') || !$node->get('mgn_uptime_enabled')) {
			return 'Uptime monitoring for this node was turned off, so its certificate is no longer checked.';
		}
		return 'The certificate the node serves is fine again.';
	}
}
?>
