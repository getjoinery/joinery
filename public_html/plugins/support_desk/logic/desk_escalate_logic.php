<?php
/**
 * API action: support_desk/desk_escalate — "Talk to a person".
 *
 * POST { site, thread_id, token, note? }. The thread waits on a person; the
 * desk's operator is emailed (at most once an hour for a thread).
 *
 * @version 0.1.1
 * @changelog 0.1.1 - the asker's write runs under rule_checked_write() behind the token proof
 */
function desk_escalate_logic(array $input): LogicResult {
	if ($limited = SupportApi::limit('desk_escalate')) {
		return $limited;
	}
	try {
		$thread = SupportApi::thread($input);
		$note = trim((string)($input['note'] ?? ''));
		// The thread's token is proven by SupportApi::thread() above.
		SystemBase::rule_checked_write(function () use ($thread, $note) {
			SupportNotify::escalate($thread, $note !== '' ? 'The person asked for a person: ' . mb_substr($note, 0, 300) : 'The person asked for a person.');
		});
		return SupportApi::ok('desk_escalate', array('state' => SupportThread::STATE_WAITING));
	} catch (SupportRefusal $e) {
		return SupportApi::fail('desk_escalate', $e);
	}
}

function desk_escalate_logic_descriptor(): array {
	return array(
		'auth' => array('min_user_permission' => 0),
		'description'      => 'Hand a support thread to a person.',
		'requires_session' => false,
		'mutates'          => true,
		'input'            => SupportApi::siteInput() + array(
			'note' => array('type' => 'text', 'required' => false, 'max_length' => 1000, 'label' => 'Note'),
		),
	);
}
