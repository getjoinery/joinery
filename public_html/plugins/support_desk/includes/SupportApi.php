<?php
/**
 * SupportApi — what every support_desk action does before and after its own
 * work: the plugin's rate buckets per address, the request headers, and a
 * refusal turned into the API's answer (specs/support_chatbot.md, Intake).
 *
 * @version 0.1.0
 */
class SupportApi {

	/** Refused calls accepted per hour from one address before it is turned away. */
	const REFUSALS_PER_HOUR = 60;

	/**
	 * The plugin's buckets per address. Returns a LogicResult when over one.
	 *
	 *   Every call: the address's refused calls this hour. A call that holds a
	 *   thread (its token, or the account's key) is otherwise not counted: it
	 *   already proves itself, and a panel polls while an answer is written.
	 *   $opens (a question with no thread yet): the new threads this address
	 *   opened this hour (support_desk_new_threads_per_hour). Each one costs a
	 *   call back to the site, so it is counted when tried, not when it works.
	 *
	 * The API's general limits apply as well.
	 */
	public static function limit(string $action, bool $opens = false): ?LogicResult {
		$state = RequestLogger::rate_limit_state('support_desk', self::REFUSALS_PER_HOUR, 3600, false);
		if (!$state['allowed']) {
			return self::limited($state, self::REFUSALS_PER_HOUR, 'refused support desk requests');
		}
		if ($opens) {
			$max = max(1, SupportDesk::intSetting('support_desk_new_threads_per_hour', 20));
			$state = RequestLogger::rate_limit_state('support_desk_open', $max, 3600);
			if (!$state['allowed']) {
				return self::limited($state, $max, 'new support threads');
			}
			RequestLogger::log('support_desk_open', $action, true);
		}
		return null;
	}

	private static function limited(array $state, int $max, string $what): LogicResult {
		if (function_exists('api_rate_limited')) {
			api_rate_limited($state, 'This address', $max, 3600, $what);
		}
		return LogicResult::error('Too many requests to the support desk from this address. Try again later.', array('reason' => 'rate_limited'));
	}

	/** The request headers, as sent. */
	public static function headers(): array {
		return function_exists('getallheaders') ? (array)getallheaders() : array();
	}

	public static function ip(): string {
		return (string)($_SERVER['REMOTE_ADDR'] ?? '');
	}

	/** Answer a call, logged unless it is a poll (a panel polls about once a second while an answer is written). */
	public static function ok(string $action, array $data, bool $log = true): LogicResult {
		if ($log) {
			RequestLogger::log('support_desk', $action, true, array('status_code' => 200));
		}
		return LogicResult::render($data);
	}

	public static function fail(string $action, SupportRefusal $e): LogicResult {
		RequestLogger::log('support_desk', $action, false, array('status_code' => $e->status, 'note' => $e->getMessage()));
		if ($e->status === 429 && $e->reason !== 'budget' && function_exists('api_error')) {
			api_error($e->getMessage(), 'RateLimitError', 429);
		}
		return LogicResult::error($e->getMessage(), array('reason' => $e->reason));
	}

	/** The thread a later call names, proven by its token, host and key. @throws SupportRefusal */
	public static function thread(array $input): SupportThread {
		list($host) = SupportIntake::site($input);
		$key = SupportIntake::presentedKey(self::headers(), $host);
		return SupportIntake::openThread((int)($input['thread_id'] ?? 0), (string)($input['token'] ?? ''), $host, $key);
	}

	/** The descriptor fields every call of a site carries. */
	public static function siteInput(): array {
		return array(
			'site'      => array('type' => 'object', 'required' => true, 'label' => 'The asking site: {host, version}'),
			'thread_id' => array('type' => 'int', 'required' => false, 'label' => 'Thread'),
			'token'     => array('type' => 'text', 'required' => false, 'max_length' => 128, 'label' => 'Thread token'),
		);
	}
}
