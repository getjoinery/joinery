<?php
/**
 * IncidentSourceSiteBuildFailed - plane:site_build_failed: a site somebody
 * ordered could not be built and waits for a person.
 *
 * A build that fails for good (the cloud account refuses another server, an
 * install that does not finish) stops with its reason on the row. The buyer's
 * page says we have been told. This is how we are: a failed build has often
 * no node of its own (the server was never made), so the incident is raised
 * on this management node's own node and names every failed site, each with
 * the reason it stopped on (specs/hosting_purchase_walkthrough_test.md,
 * re-run finding 111: the test cloud account was full, a paid site failed,
 * and the only word of it was one email).
 *
 * Open while any provision is failed and still on the board. Clears when each
 * was retried (Retry setup, or Retry install on its node) or removed.
 *
 * @version 1.0
 */
class IncidentSourceSiteBuildFailed implements IncidentSource {

	const NAME = 'plane:site_build_failed';

	/** The most sites one incident names; the rest are counted. */
	const NAMED = 10;

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		if (!ReleaseLogWatch::isSelf($node)) {
			return null;
		}
		return self::condition();
	}

	/** The incident for the failed builds on the board, or null when there are none. */
	public static function condition(): ?array {
		$failed = new MultiCustomerCloudProvision(
			array('statuses' => array('failed'), 'deleted' => false),
			array('cvp_customer_cloud_provision_id' => 'DESC')
		);
		$count = count($failed);
		if ($count === 0) {
			return null;
		}
		$detail = array();
		foreach ($failed as $provision) {
			if (count($detail) >= self::NAMED) {
				break;
			}
			$reason = trim((string)$provision->get('cvp_error'));
			$detail[(string)$provision->get('cvp_domain')] = ($reason !== '' ? mb_substr($reason, 0, 300) : 'No reason was recorded.')
				. ((int)$provision->get('cvp_mgn_managed_node_id') > 0 ? ' It has a node: use Retry install there.' : '');
		}
		if ($count > self::NAMED) {
			$detail['And'] = ($count - self::NAMED) . ' more, on the dashboard.';
		}
		$detail['What it means'] = 'Each buyer was told something went wrong and that we would email them. Nothing more happens until a person acts.';
		$detail['What to do'] = 'Fix the cause, then press Retry setup beside the site under Cloud provisions on the dashboard (/admin/server_manager). '
			. 'A paid order is not refunded by removing the site.';
		return array(
			'title'    => $count === 1 ? 'A site that was ordered could not be built' : $count . ' sites that were ordered could not be built',
			'severity' => IncidentRecord::SEVERITY_CRITICAL,
			'detail'   => $detail,
		);
	}

	public function cleared_text(ManagedNode $node): string {
		return 'No ordered site is waiting on a failed build.';
	}
}
?>
