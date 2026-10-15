<?php
/**
 * API action: support_desk/desk_threads — a site's threads and their state.
 *
 * POST { site, threads?: [{thread_id, token}], thread_id?, token? }
 *   threads    the threads the site keeps (its own support_threads table):
 *              each one that the token opens comes back with its state and
 *              whether a person's reply is unread
 *   thread_id  with its token: also the whole transcript of that thread, and
 *              opening it clears its unread mark
 * A connected site that signs with its key also gets every thread its account
 * opened from this host, so a reinstalled site still sees its history.
 *
 * @version 0.1.0
 */
function desk_threads_logic(array $input): LogicResult {
	if ($limited = SupportApi::limit('desk_threads')) {
		return $limited;
	}
	try {
		list($host) = SupportIntake::site($input);
		$key = SupportIntake::presentedKey(SupportApi::headers(), $host);
		$out = array('threads' => array());
		$seen = array();
		$list = is_array($input['threads'] ?? null) ? array_slice($input['threads'], 0, 50) : array();
		foreach ($list as $t) {
			if (!is_array($t)) {
				continue;
			}
			try {
				$thread = SupportIntake::openThread((int)($t['thread_id'] ?? 0), (string)($t['token'] ?? ''), $host, $key);
			} catch (SupportRefusal $e) {
				continue;
			}
			$seen[(int)$thread->key] = true;
			$out['threads'][] = SupportIntake::summary($thread);
		}
		if ($key !== null) {
			foreach (new MultiSupportThread(array('account_user_id' => (int)$key['user_id'], 'host' => $host),
					array('sth_last_activity_time' => 'DESC'), 50) as $thread) {
				if (!isset($seen[(int)$thread->key])) {
					$out['threads'][] = SupportIntake::summary($thread) + array('account' => true);
				}
			}
		}
		if ((int)($input['thread_id'] ?? 0) > 0) {
			$thread = SupportIntake::openThread((int)$input['thread_id'], (string)($input['token'] ?? ''), $host, $key);
			if ((bool)$thread->get('sth_unread_reply')) {
				SupportThread::updateColumns((int)$thread->key, array('sth_unread_reply' => false));
				$thread->set('sth_unread_reply', false);
			}
			$out['thread'] = SupportIntake::summary($thread);
			$out['messages'] = SupportIntake::transcript($thread);
		}
		$out['unread'] = count(array_filter($out['threads'], function ($t) { return !empty($t['unread']); }));
		return SupportApi::ok('desk_threads', $out);
	} catch (SupportRefusal $e) {
		return SupportApi::fail('desk_threads', $e);
	}
}

function desk_threads_logic_descriptor(): array {
	return array(
		'description'      => 'A site\'s support threads, their state and unread replies; with thread_id, that thread\'s transcript.',
		'requires_session' => false,
		'input'            => SupportApi::siteInput() + array(
			'threads' => array('type' => 'array', 'required' => false, 'max_items' => 50, 'label' => 'Threads the site keeps',
				'items' => array(
					'thread_id' => array('type' => 'int', 'required' => true, 'label' => 'Thread'),
					'token'     => array('type' => 'text', 'required' => false, 'max_length' => 128, 'label' => 'Thread token (none for an account thread opened by key)'),
				)),
		),
	);
}
