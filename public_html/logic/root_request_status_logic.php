<?php
/**
 * root_request_status — what became of a queued root request.
 *
 * The page that submitted a request (an upgrade, a plugin install, a docs save)
 * polls this while root carries it out, so the operator watches a transcript
 * instead of a spinner (specs/read_only_tree.md).
 *
 * Read-only, and it reads only a request the caller named. The id shape is
 * fixed inside RootRequest, so nothing here builds a path out of user input.
 *
 * @version 1.0
 */
function root_request_status_logic($get, $post) {
	$session = SessionControl::get_instance();

	// Everything a request does is an operator action — upgrading the site,
	// installing a plugin, writing a doc — so reading one's transcript is an
	// administrator's business and nobody else's.
	if (!$session->is_logged_in() || (int)$session->get_permission() < 8) {
		return LogicResult::error('You do not have permission to read root requests.');
	}

	$id = (string)($get['id'] ?? $post['id'] ?? '');
	if ($id === '') {
		return LogicResult::error('No request was named.');
	}

	$status = RootRequest::status($id);
	if ($status['state'] === 'unknown') {
		return LogicResult::error('No such request.');
	}

	return LogicResult::render(array(
		'id'         => $status['id'],
		'state'      => $status['state'],
		'kind'       => $status['kind'],
		'exit_code'  => $status['exit_code'],
		'abandoned'  => !empty($status['abandoned']),
		'finished'   => in_array($status['state'], array('done', 'failed'), true),
		'transcript' => RootRequest::transcript($id),
		'actor'      => RootRequest::actorState(),
	));
}

function root_request_status_logic_descriptor(): array {
	return [
		'description' => 'Progress and transcript of a queued root request (upgrade, extension install, docs save).',
		'mutates'     => false,
		'requires_session' => true,
		'min_permission'   => 8,
		'input'       => [
			'id' => ['type' => 'string', 'required' => true, 'label' => 'Request id'],
		],
	];
}
