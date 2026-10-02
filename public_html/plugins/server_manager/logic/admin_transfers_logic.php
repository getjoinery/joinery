<?php
/**
 * admin_transfers_logic - the operator's queue of Managed sites moving to
 * their customers' own Linode accounts (specs/managed_to_self_hosted_transfer.md §6).
 *
 * One row per transfer, with everything the queue shows already resolved: the
 * site, the customer, where it stands, the code's expiry (never the code), what
 * Linode last said and when, the last email, the last check, and any to-do.
 * The acts are API actions (server_manager/transfer_operator) the page's
 * buttons call; this page only reads.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

const ADMIN_TRANSFERS_LIMIT = 200;

function admin_transfers_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$filter = (string)($input['filter'] ?? 'open');
	if (!in_array($filter, array('open', 'done', 'all'), true)) {
		$filter = 'open';
	}
	$only = (int)($input['itx_instance_transfer_id'] ?? 0);

	$options = array('deleted' => false);
	if ($only > 0) {
		$options['itx_instance_transfer_id'] = $only;
	} elseif ($filter === 'open') {
		$options['open'] = true;
	} elseif ($filter === 'done') {
		$options['state'] = InstanceTransfer::STATE_DONE;
	}
	$rows = new MultiInstanceTransfer($options, array('itx_instance_transfer_id' => 'DESC'), ADMIN_TRANSFERS_LIMIT);

	$transfers = array();
	foreach ($rows as $row) {
		$provision = new CustomerCloudProvision((int)$row->get('itx_cvp_customer_cloud_provision_id'), TRUE);
		$check = $row->check_result();
		list($email_kind, $email_time) = $row->last_email();
		$transfers[] = array(
			'row'         => $row,
			'domain'      => $provision->key ? (string)$provision->get('cvp_domain') : '(site removed)',
			'customer'    => $provision->key ? (string)$provision->get('cvp_buyer_email') : '',
			'provision_id' => (int)$provision->key,
			'node_id'     => (int)$row->get('itx_mgn_managed_node_id'),
			'check'       => $check,
			'email_kind'  => $email_kind,
			'email_time'  => $email_time,
		);
	}

	return LogicResult::render(array(
		'session'   => $session,
		'filter'    => $filter,
		'only'      => $only,
		'transfers' => $transfers,
	));
}
