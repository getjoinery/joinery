<?php
/**
 * server_manager/transfer_customer — the customer's side of moving their
 * Managed site's server into their own Linode account
 * (specs/managed_to_self_hosted_transfer.md §4, §6), from Your sites.
 *
 * Input: do, provision_id.
 *   request        ask to move (D3); the operator starts it
 *   get_code       "I'm ready — get my transfer code"
 *   reveal_code    show the code (the page reveals it behind a button)
 *   cancel         withdraw it, while the code has not been redeemed
 *   stop_managing  after the move: stop our management of the site (D2)
 *
 * Only the site's own buyer, signed in through the browser: the code is a
 * bearer secret for the whole machine, and no API key reaches it.
 *
 * @version 1.0.0
 */

function transfer_customer_logic(array $input): LogicResult {
	if (!LibraryFunctions::isFormSubmission()) {
		return LogicResult::error('This is a POST.');
	}
	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	if ($user_id <= 0) {
		return LogicResult::error('Sign in first.');
	}
	$provision = new CustomerCloudProvision((int)($input['provision_id'] ?? 0), TRUE);
	if (!$provision->key || $provision->get('cvp_delete_time') || (int)$provision->get('cvp_usr_user_id') !== $user_id) {
		return LogicResult::error('That site is not one of yours.');
	}
	$do = trim((string)($input['do'] ?? ''));

	try {
		if ($do === 'request') {
			$row = InstanceTransfers::request($provision);
			return LogicResult::render(array('state' => $row->state(),
				'message' => 'Thanks — we will check your server and email you the next step.'));
		}
		if ($do === 'stop_managing') {
			$result = InstanceTransferFinish::stop_managing($provision);
			return LogicResult::render(array('stopped' => true,
				'message' => 'We have stopped managing ' . $provision->get('cvp_domain') . '. Backups through us have stopped; '
					. 'the ones already stored are deleted after ' . ServiceTenant::RETENTION_DAYS . ' days. Outbound email carries on.'));
		}

		$row = InstanceTransfer::open_for_provision((int)$provision->key);
		if ($row === null) {
			return LogicResult::error('There is no move under way for this site.');
		}
		switch ($do) {
			case 'get_code':
				if ($row->state() !== InstanceTransfer::STATE_INVITED) {
					return LogicResult::error('A code is not available right now: ' . $row->state_label() . '.');
				}
				InstanceTransfers::issue_code($row, 'customer');
				return LogicResult::render(array('state' => $row->state(), 'code_expiry' => (string)$row->get('itx_token_expiry'),
					'message' => 'Your transfer code is ready.'));
			case 'reveal_code':
				if ($row->state() !== InstanceTransfer::STATE_CODE_ISSUED) {
					return LogicResult::error('There is no code out right now.');
				}
				$code = $row->open_token();
				if ($code === '') {
					return LogicResult::error('Your code cannot be read. Cancel it and get a new one.');
				}
				return LogicResult::render(array('code' => $code, 'code_expiry' => (string)$row->get('itx_token_expiry')));
			case 'cancel':
				InstanceTransfers::cancel($row, 'customer');
				return LogicResult::render(array('state' => $row->state(), 'message' => 'Canceled. Your site stays with us as it is.'));
			default:
				return LogicResult::error('Say what to do: request, get_code, reveal_code, cancel or stop_managing.');
		}
	} catch (InstanceTransferFlowException $e) {
		return LogicResult::error($e->getMessage());
	} catch (CloudComputeException $e) {
		return LogicResult::error('Linode could not be reached just now. Try again in a few minutes.');
	}
}

function transfer_customer_logic_descriptor(): array {
	return [
		'description' => 'A Managed site\'s buyer moving its server into their own Linode account: request, get the transfer code, see it, cancel, or stop our management afterwards.',
		'mutates'     => true,
		'requires_session' => true,
		'auth'        => ['requires_browser_session' => true],
		'input'       => [
			'do'           => ['type' => 'string', 'required' => true,
				'enum' => ['request', 'get_code', 'reveal_code', 'cancel', 'stop_managing'], 'label' => 'Action'],
			'provision_id' => ['type' => 'int', 'required' => true, 'label' => 'Site'],
		],
	];
}
?>
