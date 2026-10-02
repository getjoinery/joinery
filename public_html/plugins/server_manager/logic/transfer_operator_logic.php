<?php
/**
 * server_manager/transfer_operator — the operator's side of handing a Managed
 * site's server to its customer's own Linode account
 * (specs/managed_to_self_hosted_transfer.md §4, §6).
 *
 * Input: do, and provision_id (check, start) or transfer_id (everything else).
 *   check       run the eligibility check and return it (recorded on an open row)
 *   start       check, then invite the customer
 *   issue_code  issue the transfer code now (a customer walked through it on a call)
 *   cancel      withdraw it, while the code has not been redeemed
 *   resend      send the customer's email for the current state again
 *   todo_done   tick off the row's operator to-do
 *
 * The code itself is never returned here: it is shown only on the customer's
 * own signed-in page. Superadmin only (floor 10).
 *
 * @version 1.0.0
 */

function transfer_operator_logic(array $input): LogicResult {
	if (!LibraryFunctions::isFormSubmission()) {
		return LogicResult::error('This is a POST.');
	}
	$session = SessionControl::get_instance();
	if ((int)$session->get_permission() < 10) {
		return LogicResult::error('Only a superadmin can move a site between accounts.');
	}
	$do = trim((string)($input['do'] ?? ''));

	try {
		if ($do === 'check' || $do === 'start') {
			$provision = new CustomerCloudProvision((int)($input['provision_id'] ?? 0), TRUE);
			if (!$provision->key || $provision->get('cvp_delete_time')) {
				return LogicResult::error('There is no such site.');
			}
			if ($do === 'check') {
				$check = InstanceTransfers::check($provision);
				$open = InstanceTransfer::open_for_provision((int)$provision->key);
				if ($open !== null) {
					$open->set('itx_check_result', $check);
					$open->save();
				}
				return LogicResult::render(array('check' => $check, 'transfer' => transfer_operator_row($open)));
			}
			$row = InstanceTransfers::start($provision);
			return LogicResult::render(array('check' => $row->check_result(), 'transfer' => transfer_operator_row($row),
				'message' => 'Started. The customer has been sent the invitation.'));
		}

		$row = new InstanceTransfer((int)($input['transfer_id'] ?? 0), TRUE);
		if (!$row->key || $row->get('itx_delete_time')) {
			return LogicResult::error('There is no such transfer.');
		}
		switch ($do) {
			case 'issue_code':
				InstanceTransfers::issue_code($row, 'operator');
				$message = 'Code issued. The customer can see it on their sites page; it is valid until '
					. $row->get('itx_token_expiry') . ' UTC.';
				break;
			case 'cancel':
				InstanceTransfers::cancel($row, 'operator');
				$message = 'Canceled.';
				break;
			case 'resend':
				$kind = InstanceTransfers::resend($row);
				$message = 'Sent the ' . str_replace('_', ' ', $kind) . ' email again.';
				break;
			case 'todo_done':
				InstanceTransfers::todo_done($row);
				$message = 'To-do cleared.';
				break;
			default:
				return LogicResult::error('Say what to do: check, start, issue_code, cancel, resend or todo_done.');
		}
		return LogicResult::render(array('transfer' => transfer_operator_row($row), 'message' => $message));
	} catch (InstanceTransferFlowException $e) {
		return LogicResult::error($e->getMessage());
	} catch (CloudComputeException $e) {
		return LogicResult::error('Linode: ' . $e->getMessage());
	}
}

/** What the queue shows of a row. Never the code. */
function transfer_operator_row(?InstanceTransfer $row): ?array {
	if ($row === null) {
		return null;
	}
	return array(
		'id'            => (int)$row->key,
		'state'         => $row->state(),
		'state_label'   => $row->state_label(),
		'code_expiry'   => (string)$row->get('itx_token_expiry'),
		'linode_status' => (string)$row->get('itx_linode_status'),
		'error'         => (string)$row->get('itx_error'),
		'operator_todo' => (string)$row->get('itx_operator_todo'),
	);
}

function transfer_operator_logic_descriptor(): array {
	return [
		'description' => 'Operator actions on moving a Managed site\'s server to its customer\'s own Linode account: check, start, issue a code, cancel, resend an email, clear a to-do.',
		'mutates'     => true,
		'requires_session' => true,
		'auth'        => ['min_user_permission' => 10, 'requires_browser_session' => true],
		'input'       => [
			'do'           => ['type' => 'string', 'required' => true,
				'enum' => ['check', 'start', 'issue_code', 'cancel', 'resend', 'todo_done'], 'label' => 'Action'],
			'provision_id' => ['type' => 'int', 'required' => false, 'label' => 'Site (provision)'],
			'transfer_id'  => ['type' => 'int', 'required' => false, 'label' => 'Transfer'],
		],
	];
}
?>
