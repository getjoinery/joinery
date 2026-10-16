<?php
/**
 * IncidentSourceCloudAccountKey — plane:cloud_account_key: the key of the
 * cloud account an encrypted machine is in does not work, or soon will not
 * (specs/hosted_data_promise_disk_encryption.md D3, Tier 2; B77).
 *
 * Before this management node opens an encrypted machine whose key it holds,
 * it reads the machine's activity log at its provider with that account's
 * key (EscrowTier2). A key that stops working (refused, or past the expiry
 * its provider reported) cannot read it, and the boot is refused rather than
 * answered unchecked: the machine waits for its recovery key until the key is
 * replaced. Nothing else says so before the reboot that needs it, so:
 *
 *   - the key does not work, or has never been checked (its scopes unread, so
 *     the boot is refused all the same): critical, since the machine's next
 *     reboot will not open by itself;
 *   - the key expires within a week: a warning, with the day.
 *
 * Read from the account's row, which the daily watch refreshes for every key
 * (MachineTransferWatch::check_keys) and a boot's refusal marks on a 401.
 * Raised on each machine Tier 2 covers, so the machines are named.
 *
 * Clears when the key works and is not about to expire: it was replaced, or
 * the provider answers to it again.
 *
 * @version 1.1 - a key never checked is critical too: its boots are refused until it is (reviewer1 F1)
 * @version 1.0
 */
class IncidentSourceCloudAccountKey implements IncidentSource {

	const NAME = 'plane:cloud_account_key';

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		$row = DataRootEscrow::for_node((int)$node->key);
		if (!$row || (string)$row->get('dre_status') !== 'enrolled') {
			return null;
		}
		$account = EscrowTier2::account_for($row);
		if ($account === null) {
			return null;
		}
		$where = 'the Hosting tab of Provisioning Setup (/admin/server_manager/provisioning_setup?tab=hosting)';
		if ($account->missing_scopes(array(EscrowTier2::EVENTS_SCOPE => '')) === null) {
			return array(
				'title'    => 'Its cloud account\'s key has never been checked: after a reboot it will not open by itself',
				'severity' => IncidentRecord::SEVERITY_CRITICAL,
				'detail'   => array(
					'Cloud account' => $account->label(),
					'Why'           => 'what the key may do was never read from the provider, so this management node cannot tell whether it reads the activity log it checks before opening the machine',
					'Fix'           => 'press Check for the account on ' . $where . ' (the daily check does it too)',
				),
			);
		}
		if (!$account->usable()) {
			return array(
				'title'    => 'Its cloud account\'s key does not work: after a reboot it will not open by itself',
				'severity' => IncidentRecord::SEVERITY_CRITICAL,
				'detail'   => array(
					'Cloud account' => $account->label(),
					'Why'           => $account->unusable_reason(),
					'Until then'    => 'its boot is refused here: this management node reads the machine\'s activity log at its provider before it opens it, and never opens one it cannot check. A reboot waits for its recovery key.',
					'Fix'           => 'replace the key on ' . $where . ', with the events:read_only scope among the rest',
				),
			);
		}
		$expires = $account->key_expires_within(OperatorCloudAccount::KEY_EXPIRY_WARNING_SECONDS);
		if ($expires !== null) {
			return array(
				'title'    => 'Its cloud account\'s key expires on ' . $account->get_local('oca_token_expires', 'M j'),
				'severity' => IncidentRecord::SEVERITY_WARNING,
				'detail'   => array(
					'Cloud account' => $account->label(),
					'Expires'       => $expires . ' UTC',
					'After that'    => 'this machine\'s boots are refused here, and a reboot waits for its recovery key, until the key is replaced',
					'Fix'           => 'replace the key before then on ' . $where,
				),
			);
		}
		return null;
	}

	public function cleared_text(ManagedNode $node): string {
		return 'Its cloud account\'s key works, and is not about to expire.';
	}
}
?>
