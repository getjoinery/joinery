<?php
/**
 * IncidentSourceCertificate — plane:certificate: the certificate the node
 * serves for its site has a problem (incident_triage.md, Types; WP3).
 *
 * Read from mgn_cert_problem, which the uptime task's certificate check
 * stores on every check: renewal overdue, expiry near, www reaching the
 * origin uncovered, an origin presenting another name's certificate, or an
 * origin still answering with its placeholder. Critical once fewer than seven
 * days remain (or it has expired); a warning before that. Watched only while
 * the node's uptime monitoring runs, since that is what checks it.
 *
 * A new site answers with its placeholder until its name reaches it and the
 * retry timer issues the real certificate, which is a wait, not a fault: the
 * placeholder is an incident only once it has been served for a day, and
 * never while this plane is still provisioning the node's certificate.
 *
 * @version 1.1 - an origin on its placeholder for PLACEHOLDER_GRACE_SECONDS, outside a pending provision, is a
 *                warning (B112)
 * @version 1.0
 */
class IncidentSourceCertificate implements IncidentSource {

	const NAME = 'plane:certificate';

	/** Fewer days than this left on the certificate is critical. */
	const CRITICAL_DAYS = 7;

	/** How long an origin may answer with its placeholder before it is an incident. */
	const PLACEHOLDER_GRACE_SECONDS = 86400;

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
		if (($p['reason'] ?? '') === 'placeholder') {
			// While this plane is still getting the node its certificate
			// (ProvisionPendingSsl), the placeholder is expected and that task
			// says what it waits on.
			if ((string)$node->get('mgn_ssl_state') === 'pending'
					|| time() - (int)($p['since'] ?? 0) < self::PLACEHOLDER_GRACE_SECONDS) {
				return null;
			}
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
