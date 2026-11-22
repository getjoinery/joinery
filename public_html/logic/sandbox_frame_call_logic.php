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
 * calls. A visitor not signed in may call it for a public page only, as
 * guest checkout's actions are called (allow_guest), and that call is a
 * visitor's: SandboxHost's anonymous pool, and read only, since no form the
 * site checked was sent (spec sandboxed_plugin_public_pages design 3).
 *
 * @version 1.2 - public pages, for a visitor signed in or not (spec sandboxed_plugin_public_pages)
 * @version 1.1 - only the page's declared handlers; the call says it came from the frame (reviewer2 K1)
 * @version 1.0
 */

function sandbox_frame_call_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$kind = SandboxPages::kind($input['kind'] ?? '');
	// A member's or an admin's page is for a signed-in viewer only; a guest
	// (allow_guest admits one) is refused as for a page that is not there (P17).
	if ($kind !== 'public' && !$session->is_logged_in()) {
		return LogicResult::error('That page does not exist.');
	}
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
	$viewer = $session->is_logged_in() ? new User($session->get_user_id(), TRUE) : null;
	$result = SandboxHost::ui((string)$input['plugin'], $handler, $data, $viewer,
		array('source' => 'frame', 'kind' => $kind, 'page' => (string)$page['slug'],
			'access' => $viewer === null ? array('checked_post' => false) : null));
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
		// A person's credential (the bridge in a browser, or an app's
		// session), never a machine key. A visitor not signed in, as the
		// anonymous browser principal, for a public page only (the logic checks).
		'auth'             => ['min_user_permission' => 0, 'requires_person_credential' => true, 'allow_guest' => true, 'capability' => 'write'],
		'mutates'          => true,
		'input'            => [
			'plugin'  => ['type' => 'string', 'required' => true, 'max_length' => 20, 'label' => 'Plugin'],
			'kind'    => ['type' => 'string', 'required' => true, 'max_length' => 6, 'label' => 'admin, member or public'],
			'page'    => ['type' => 'string', 'required' => true, 'max_length' => 30, 'label' => 'Page'],
			'handler' => ['type' => 'string', 'required' => true, 'max_length' => 40, 'label' => 'Handler'],
			'data'    => ['type' => 'object', 'required' => false, 'label' => 'What the frame sent'],
		],
	];
}
