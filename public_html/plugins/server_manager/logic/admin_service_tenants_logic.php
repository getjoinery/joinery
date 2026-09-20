<?php
/**
 * admin_service_tenants_logic - the operator's Service Tenants page.
 *
 * (specs/services_phase2_platform.md §10 item 5). Every self-hosted site
 * renting our mail or our shelf, one row per service: state, figure, the
 * paid-through date, and the ladder's timestamps. Two acts and no more, both
 * POSTs:
 *
 *   grant    write the paid-through date (or clear it). In this phase this
 *            is what entitles a tenant; a payment writes the same column
 *            later. A stopped row with a date ahead comes back in place now.
 *   release  stop the service from the operator's side: the same path the
 *            customer's own release and the reconcile use.
 *
 * Managed sites are invisible here by construction: they have no tenant row.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

const ADMIN_SERVICE_TENANTS_LIMIT = 300;

function admin_service_tenants_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$page_url = '/admin/server_manager/service_tenants';
	$page_regex = '/\/admin\/server_manager\/service_tenants/';

	if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
		$action = (string)($input['action'] ?? '');
		$row = null;
		if ((int)($input['svt_service_tenant_id'] ?? 0) > 0) {
			$row = new ServiceTenant((int)$input['svt_service_tenant_id'], TRUE);
			if (!$row->key) { $row = null; }
		}

		$message = null;
		$error = null;
		if ($row === null) {
			$error = 'That tenant row no longer exists.';
		} elseif ($action === 'grant') {
			$formwriter = new FormWriterV2HTML5('grant_' . (int)$row->key);
			if (!$formwriter->validateCSRF($input)) {
				$error = 'That request token has expired. Try again.';
			} else {
				try {
					JoineryServices::grant($row, (string)($input['paid_until'] ?? ''));
					$until = $row->get('svt_paid_until');
					$message = $row->get('svt_host') . ' (' . $row->get('svt_service') . '): '
						. ($until ? 'paid through ' . $row->get_local('svt_paid_until', 'M j, Y') : 'the date is cleared; the tenant is no longer entitled')
						. '. The site sees it on its next enrol or status call.';
				} catch (\Throwable $e) {
					$error = 'The date was not written: ' . $e->getMessage();
				}
			}
		} elseif ($action === 'release') {
			try {
				JoineryServices::releaseRow($row);
				$message = $row->get('svt_host') . ' (' . $row->get('svt_service') . ') is released.';
			} catch (\Throwable $e) {
				$error = 'Not released: ' . $e->getMessage();
			}
		} else {
			$error = 'Unknown action.';
		}

		if ($message) {
			$session->save_message(new DisplayMessage($message, 'Success', $page_regex,
				DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		}
		if ($error) {
			$session->save_message(new DisplayMessage($error, 'Error', $page_regex,
				DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		}
		return LogicResult::redirect($page_url);
	}

	$rows = new MultiServiceTenant(array('deleted' => false),
		array('svt_host' => 'ASC', 'svt_service' => 'ASC'), ADMIN_SERVICE_TENANTS_LIMIT, 0);
	$total = (int)$rows->count_all();
	$rows->load();

	$tenants = array();
	$grace_days = JoineryServices::graceDays();
	foreach ($rows as $row) {
		$account = '';
		try {
			$user = new User((int)$row->get('svt_usr_user_id'), TRUE);
			$account = $user->key ? (string)$user->get('usr_email') : '';
		} catch (\Throwable $e) {
		}
		$tenants[] = array(
			'row'         => $row,
			'status'      => JoineryServices::statusOf($row),
			'account'     => $account,
			'grace_ends'  => ServiceTenantLadder::graceEnds($row, 'svt_lapse_time', $grace_days),
		);
	}

	return LogicResult::render(array(
		'session'       => $session,
		'tenants'       => $tenants,
		'total'         => $total,
		'limit'         => ADMIN_SERVICE_TENANTS_LIMIT,
		'grace_days'    => $grace_days,
		'shelf_target'  => JoineryServices::shelfTarget(),
		'mail_ready'    => Smtp2GoClient::fromSettings() !== null,
		'page_url'      => $page_url,
	));
}
