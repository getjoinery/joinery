<?php
/**
 * Admin: Content Reports — what members reported, and what was decided.
 *
 * Lists reports newest first, open ones by default. Each row shows the
 * content as its feature's handler describes it, with a link to it in
 * context. Two POSTs settle a report and every other open report on the same
 * content: one of the handler's own actions (hide the post, lock the topic),
 * or Dismiss, which leaves the content as it is.
 *
 * See docs/social_features.md § Content reports.
 *
 * @version 1.0.0
 */

function admin_content_reports_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->set_return();

	$back = '/admin/admin_content_reports';
	if (!empty($input['filter'])) {
		$back .= '?filter=' . urlencode((string)$input['filter']);
	}

	// Actions first, before anything renders.
	$action = (string)($input['action'] ?? '');
	if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($action === 'act' || $action === 'dismiss')) {
		$report = new ContentReport((int)($input['rpt_content_report_id'] ?? 0), TRUE);
		if (!$report->key) {
			return LogicResult::error('That report no longer exists.');
		}
		$admin_id = (int)$session->get_user_id();
		$settled_count = $report->open_on_same_content();
		try {
			if ($action === 'act') {
				$message = $report->act((string)($input['report_action'] ?? ''), $admin_id);
			} else {
				$report->dismiss($admin_id);
				$message = 'Dismissed';
			}
			$suffix = $settled_count > 1 ? ' (' . $settled_count . ' reports settled).' : '.';
			$session->save_message(new DisplayMessage(rtrim($message, '.') . $suffix, 'Done', NULL,
				DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		} catch (ContentReportException $e) {
			$session->save_message(new DisplayMessage($e->getMessage(), 'Not done', NULL,
				DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		} catch (SystemBaseException $e) {
			// A handler refusing with a message of its own.
			$session->save_message(new DisplayMessage($e->getMessage(), 'Not done', NULL,
				DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		}
		return LogicResult::redirect($back);
	}

	$statuses = array(
		'Open'      => ContentReport::STATUS_OPEN,
		'Acted on'  => ContentReport::STATUS_ACTIONED,
		'Dismissed' => ContentReport::STATUS_DISMISSED,
	);
	$filter = (string)LibraryFunctions::fetch_variable_local($input, 'filter', ContentReport::STATUS_OPEN);
	if (!in_array($filter, $statuses, true)) {
		$filter = ContentReport::STATUS_OPEN;
	}

	$numperpage = 30;
	$offset = max(0, (int)LibraryFunctions::fetch_variable_local($input, 'offset', 0));
	$reports = new MultiContentReport(array('rpt_status' => $filter),
		array('rpt_create_time' => 'DESC'), $numperpage, $offset);

	// What each row shows about its content, asked of the handler once per row.
	$rows = array();
	foreach ($reports as $report) {
		$handler = $report->handler();
		$entity_id = (int)$report->get('rpt_entity_id');
		$preview = null;
		$actions = array();
		if ($handler !== null) {
			try {
				$preview = $handler->preview($entity_id);
				$actions = $report->is_open() && $preview !== null ? $handler->actions($entity_id) : array();
			} catch (\Throwable $e) {
				error_log('[admin_content_reports] handler ' . $report->get('rpt_entity_type') . ' failed: ' . $e->getMessage());
			}
		}
		$reporter_id = (int)$report->get('rpt_usr_user_id');
		$handled_by = (int)$report->get('rpt_usr_user_id_handled_by');
		$rows[] = array(
			'report'   => $report,
			'label'    => $handler ? $handler->label() : (string)$report->get('rpt_entity_type'),
			'active'   => $handler !== null,
			'preview'  => $preview,
			'actions'  => $actions,
			'reporter' => $reporter_id > 0 ? new User($reporter_id, TRUE) : null,
			'handler'  => $handled_by > 0 ? new User($handled_by, TRUE) : null,
		);
	}

	return LogicResult::render(array(
		'session'    => $session,
		'filter'     => $filter,
		'statuses'   => $statuses,
		'rows'       => $rows,
		'numrecords' => $reports->count_all(),
		'numperpage' => $numperpage,
	));
}
?>
