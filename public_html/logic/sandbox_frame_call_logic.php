<?php
/**
 * The one door out of a sandboxed plugin's framed page (design 9): core's
 * bridge on the outer page (assets/js/sandbox-frame.js) passes the frame's
 * "call my handler X with this data" here, as the signed-in viewer, and the
 * plugin's own handler answers it in the fence (on_ui, as a page form's
 * submission is). The page must be one this viewer may open
 * (SandboxPages::framedPage()). Its answer goes back into the frame.
 *
 * Only a handler the page declares (its manifest's "handlers") is called,
 * and the plugin is told where the call came from: input.source 'frame',
 * input.kind and input.page.
 *
 * The API action sandbox_frame_call, for a browser session only: the bridge
 * calls it with the page's CSRF header; nothing else can make the frame's
 * calls.
 *
 * @version 1.1 - only the page's declared handlers; the call says it came from the frame (reviewer2 K1)
 * @version 1.0
 */

function sandbox_frame_call_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$kind = ($input['kind'] ?? '') === 'admin' ? 'admin' : 'member';
	$page = SandboxPages::framedPage((string)($input['plugin'] ?? ''), $kind, (string)($input['page'] ?? ''), $session);
	if (!is_array($page)) {
		return LogicResult::error($page);
	}
	$handler = (string)($input['handler'] ?? '');
	// Only a handler the framed page declares: an admin form's handler is
	// reached through the admin page alone, whoever's browser asks.
	if (!in_array($handler, (array)($page['handlers'] ?? array()), true)) {
		return LogicResult::error('This page may not call the handler ' . mb_substr($handler, 0, 40) . '.');
	}
	$data = is_array($input['data'] ?? null) ? $input['data'] : array();
	if (strlen((string)json_encode($data)) > 1048576) {
		return LogicResult::error('The frame sent more than 1 MB.');
	}
	$result = SandboxHost::ui((string)$input['plugin'], $handler, $data, new User($session->get_user_id(), TRUE),
		array('source' => 'frame', 'kind' => $kind, 'page' => (string)$page['slug']));
	return LogicResult::render(array(
		'ok'      => $result['status'] === SandboxLog::STATUS_OK,
		'status'  => $result['status'],
		'output'  => $result['status'] === SandboxLog::STATUS_OK ? $result['output'] : null,
		'message' => $result['status'] === SandboxLog::STATUS_OK ? '' : (string)$result['message'],
	));
}

function sandbox_frame_call_logic_descriptor(): array {
	return [
		'description'      => 'Pass a call from a sandboxed plugin\'s framed page to the plugin\'s own handler.',
		'requires_session' => true,
		// The viewer's browser only: the bridge on the plugin's outer page.
		'auth'             => ['min_user_permission' => 0, 'requires_person_credential' => true, 'capability' => 'write'],
		'mutates'          => true,
		'ai_agent'         => 'deny',
		'input'            => [
			'plugin'  => ['type' => 'string', 'required' => true, 'max_length' => 20, 'label' => 'Plugin'],
			'kind'    => ['type' => 'string', 'required' => true, 'max_length' => 6, 'label' => 'admin or member'],
			'page'    => ['type' => 'string', 'required' => true, 'max_length' => 30, 'label' => 'Page'],
			'handler' => ['type' => 'string', 'required' => true, 'max_length' => 40, 'label' => 'Handler'],
			'data'    => ['type' => 'object', 'required' => false, 'label' => 'What the frame sent'],
		],
	];
}
