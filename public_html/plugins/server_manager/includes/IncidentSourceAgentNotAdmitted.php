<?php
/**
 * IncidentSourceAgentNotAdmitted - plane:agent_not_admitted: a site this
 * plane built whose agent was never let in.
 *
 * Every server this plane builds runs an agent that asks to be managed, and a
 * request from a machine the plane built is approved by itself within about a
 * minute (JoinAutoApproval). When that does not happen the buyer's page still
 * says Ready, but the site is not under management: no updates, no backups,
 * and for a site that bought email with it, no email. Nothing else says so
 * (specs/hosting_purchase_walkthrough_test.md, findings 82 and 83).
 *
 * Open while the node's install ended more than WAIT_SECONDS ago and no agent
 * has paired with it. Read from the plane's own rows; the provider is not
 * asked here.
 *
 * @version 1.0
 */
class IncidentSourceAgentNotAdmitted implements IncidentSource {

	const NAME = 'plane:agent_not_admitted';

	/** How long after its install a site's agent is given to be let in. */
	const WAIT_SECONDS = 900;

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		if (trim((string)$node->get('mgn_agent_public_key')) !== '') {
			return null;
		}
		$provision = CustomerCloudProvision::latest_for_node((int)$node->key);
		if (!$provision || (string)$provision->get('cvp_status') !== 'done') {
			return null;
		}
		$install = ManagementJob::latestForNode((int)$node->key, 'install_node');
		$ended = $install ? trim((string)$install->get('mjb_completed_time')) : '';
		if ($ended === '' || time() - strtotime($ended . ' UTC') < self::WAIT_SECONDS) {
			return null;
		}
		$waiting = self::waiting_requests($provision);
		$next = $provision->is_operator_hosted()
			? 'Open the join requests on the Server Manager dashboard and approve this site\'s, after comparing its key.'
			: 'The site is on its buyer\'s own cloud account, and admitting its agent asks that account whether the server is '
				. 'running: if the buyer\'s connection is gone, ask them to connect again on their sites page, and the agent '
				. 'is let in by itself. Otherwise approve its join request on the Server Manager dashboard, after comparing its key.';
		return array(
			'title'    => 'This site was built but its agent was never let in',
			'severity' => IncidentRecord::SEVERITY_CRITICAL,
			'detail'   => array(
				'Site'          => (string)$provision->get('cvp_domain'),
				'Install ended' => $ended . ' UTC',
				'Join requests' => $waiting > 0 ? $waiting . ' waiting from this machine' : 'none waiting: its requests may have expired, and the agent asks again',
				'What it means' => 'The buyer\'s page says Ready, but this site is not under management: no updates or backups reach it, '
					. 'and email bought with it is not set up.',
				'What to do'    => $next,
			),
		);
	}

	public function cleared_text(ManagedNode $node): string {
		return trim((string)$node->get('mgn_agent_public_key')) !== ''
			? 'The site\'s agent has been let in.'
			: 'The site is no longer a built site waiting for its agent.';
	}

	/** Pending join requests JoinAutoApproval attributes to this provision's machine. */
	private static function waiting_requests(CustomerCloudProvision $provision): int {
		$n = 0;
		try {
			foreach (AgentJoinRequest::pending() as $request) {
				$v = JoinAutoApproval::verdict($request);
				if (!empty($v['provision']) && (int)$v['provision']->key === (int)$provision->key) {
					$n++;
				}
			}
		} catch (Throwable $e) {
			return 0;
		}
		return $n;
	}
}
?>
