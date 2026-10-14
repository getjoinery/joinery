<?php
/**
 * API action: content_report — report someone else's content to the admins.
 *
 * POST /api/v1/action/content_report (browser session). Params:
 *   entity_type  string  a registered ContentReportHandler type ('forum_post')
 *   entity_id    int     that content's id
 *   reason       string  spam | abuse | off_topic | other
 *   note         string  optional, at most ContentReport::NOTE_MAX characters
 *
 * The handler decides whether this person may report the content (they can
 * see it and it is live). A person who has already reported it gets the same
 * answer again and no second row or alert. Rate-limited per person in the
 * `content_report` bucket. A new report dispatches `content.reported`, whose
 * audience is every admin.
 *
 * See docs/social_features.md § Content reports.
 *
 * @version 1.0.0
 */

function content_report_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	if ($user_id <= 0) {
		return LogicResult::error('Sign in to report something.');
	}

	$settings = Globalvars::get_instance();
	$limit  = max(1, (int)$settings->get_setting('content_report_rate_limit_requests', true, true) ?: 10);
	$window = max(60, (int)$settings->get_setting('content_report_rate_limit_window', true, true) ?: 3600);
	$state = RequestLogger::rate_limit_state('content_report', $limit, $window, null, $user_id);
	if (!$state['allowed']) {
		if (function_exists('api_rate_limited')) {
			api_rate_limited($state, 'You', $limit, $window, 'reports');
		}
		return LogicResult::error('You have sent a lot of reports in a short time. Try again later.');
	}

	$entity_type = (string)($input['entity_type'] ?? '');
	$entity_id   = (int)($input['entity_id'] ?? 0);
	try {
		list($report, $created) = ContentReport::file($entity_type, $entity_id, $user_id,
			(string)($input['reason'] ?? ''), isset($input['note']) ? (string)$input['note'] : null);
	} catch (ContentReportException $e) {
		// A refused attempt counts toward the limit too: probing is reporting.
		RequestLogger::log('content_report', 'content_report', false, array('user_id' => $user_id, 'status_code' => 400));
		return LogicResult::error($e->getMessage());
	}

	RequestLogger::log('content_report', 'content_report', true, array('user_id' => $user_id, 'status_code' => 200));

	if ($created) {
		$handler = ContentReportRegistry::get($entity_type);
		$preview = $handler ? $handler->preview($entity_id) : null;
		$reporter = new User($user_id, TRUE);
		SignalBus::dispatch('content.reported', array(
			'report_id'      => (int)$report->key,
			'entity_type'    => $entity_type,
			'entity_id'      => $entity_id,
			'content_label'  => $handler ? $handler->label() : $entity_type,
			'content_title'  => $preview ? (string)$preview['title'] : '',
			'reason_label'   => $report->reason_label(),
			'reporter_name'  => $reporter->display_name(),
			'source_user_id' => $user_id,
		));
	}

	return LogicResult::render(array(
		'report_id' => (int)$report->key,
		'message'   => 'Thanks. An admin will take a look.',
	));
}

function content_report_logic_descriptor(): array {
	return array(
		'description'      => 'Report a piece of someone else\'s content (a forum post, a chat message) to the site\'s admins.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'entity_type' => array('type' => 'string', 'required' => true, 'label' => 'What kind of content', 'max_length' => 64),
			'entity_id'   => array('type' => 'int', 'required' => true, 'label' => 'Which one'),
			'reason'      => array('type' => 'string', 'required' => true, 'label' => 'Reason',
				'enum' => array_keys(ContentReport::reasons())),
			'note'        => array('type' => 'text', 'required' => false, 'label' => 'Note', 'max_length' => ContentReport::NOTE_MAX),
		),
		'auth'             => array('capability' => 'write', 'min_user_permission' => 0),
		'ai_agent'         => 'confirm',
	);
}
?>
