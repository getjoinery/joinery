<?php
/**
 * IncidentSourceMachineTransfer — plane:machine_transfer: a cloud machine is
 * sending far more than its share of the account's transfer allowance
 * (specs/node_outbound_and_transfer.md WP1).
 *
 * Read from the machine's MachineTransfer row, which the daily watch fills
 * from the provider. Three conditions, worst first, any one opening the
 * incident: the month's allowance passed (critical: it is billed past the
 * pool once the pool runs out), a day at more than three times the machine's
 * daily share, and a month on pace past one and a half times the allowance.
 * Raised only on the node that speaks for the machine, so a server of twelve
 * sites is one incident; it names the others.
 *
 * Clears when the condition is gone: the next day's rate is ordinary, or the
 * month turns and the figure starts again.
 *
 * @version 1.0
 */
class IncidentSourceMachineTransfer implements IncidentSource {

	const NAME = 'plane:machine_transfer';

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		$row = self::row_for($node);
		if ($row === null) {
			return null;
		}
		$conditions = MachineTransferWatch::conditions($row, time());
		if (!$conditions) {
			return null;
		}
		$worst = $conditions[0];
		$detail = array('Machine' => trim($row->get('mtr_label') . ' (' . $row->get('mtr_provider') . ' '
			. $row->get('mtr_instance_id') . ')'));
		$others = self::other_sites($node, $row);
		if ($others) {
			$detail['Also on it'] = implode(', ', $others);
		}
		$detail += $worst['detail'];
		$more = array_column(array_slice($conditions, 1), 'title');
		if ($more) {
			$detail['Also'] = implode('; ', $more);
		}
		return array('title' => $worst['title'], 'severity' => $worst['severity'], 'detail' => $detail);
	}

	public function cleared_text(ManagedNode $node): string {
		return 'The server\'s transfer is back within its share of the allowance.';
	}

	/** The machine row this node speaks for, or null. */
	private static function row_for(ManagedNode $node): ?MachineTransfer {
		$id = (int)$node->get('mgn_mtr_machine_transfer_id');
		if (!$id) {
			return null;
		}
		$row = new MachineTransfer($id, TRUE);
		if (!$row->key || (int)$row->get('mtr_mgn_managed_node_id') !== (int)$node->key) {
			return null;
		}
		return $row;
	}

	/** The names of the other live nodes on the same machine. */
	private static function other_sites(ManagedNode $node, MachineTransfer $row): array {
		$names = array();
		foreach (new MultiManagedNode(array('mgn_mtr_machine_transfer_id' => (int)$row->key, 'deleted' => false)) as $n) {
			if ((int)$n->key !== (int)$node->key) {
				$names[] = (string)$n->get('mgn_name');
			}
		}
		return $names;
	}
}
?>
