<?php
/**
 * PollInstanceTransfers — follows every Managed site's server that is on its
 * way to its customer's own Linode account
 * (specs/managed_to_self_hosted_transfer.md §4, §5, §6).
 *
 * One phase of ServerManagerAdvanceProvisioning, before the hosted phases: a
 * site whose move just completed must stop being hosted before the hosted
 * watch looks at it again. For every row with a code out, accepted, or
 * finishing:
 *
 *   - the provider is asked where the transfer stands, and the row follows
 *     (InstanceTransfers::apply_status): a code that ran out goes back to
 *     waiting for the customer, a failure is told to the operator and the
 *     customer, a completion hands the row to the finish;
 *   - a finishing row is worked forward, one recorded step at a time
 *     (InstanceTransferFinish).
 *
 * Nothing is asked of the provider for a row with no code out: there is
 * nothing there to ask about.
 *
 * @version 1.0
 */
class PollInstanceTransfers {

	/** @var array Human-readable problems for the run summary. */
	private $errors = array();

	public function run(array $config): array {
		$rows = new MultiInstanceTransfer(array('polled' => true, 'deleted' => false),
			array('itx_instance_transfer_id' => 'ASC'));
		$rows->load();
		if (count($rows) === 0) {
			return array('status' => 'skipped', 'message' => 'No server transfers under way.');
		}

		$moved = 0;
		$finished = 0;
		foreach ($rows as $row) {
			try {
				if ($row->state() !== InstanceTransfer::STATE_FINISHING && InstanceTransfers::poll($row)) {
					$moved++;
				}
				if ($row->state() === InstanceTransfer::STATE_FINISHING && InstanceTransferFinish::run($row)) {
					$finished++;
				}
			} catch (Throwable $e) {
				$this->errors[] = 'transfer #' . (int)$row->key . ': ' . $e->getMessage();
				error_log('PollInstanceTransfers: transfer #' . (int)$row->key . ': ' . $e->getMessage());
			}
		}

		$message = 'Server transfers: ' . count($rows) . ' followed, ' . $moved . ' moved, ' . $finished . ' finished.';
		if ($this->errors) {
			$message .= ' ' . count($this->errors) . ' problem(s): ' . implode('; ', array_slice($this->errors, 0, 3));
			return array('status' => 'error', 'message' => $message);
		}
		return array('status' => 'success', 'message' => $message);
	}
}
