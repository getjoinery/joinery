<?php
/**
 * SupportApi — what every support_desk action does before and after its own
 * work: the plugin's rate bucket per address, the request headers, and a
 * refusal turned into the API's answer (specs/support_chatbot.md, Intake).
 *
 * @version 0.1.0
 */
class SupportApi {

	/** The plugin's own bucket per address, on top of the API's general one. Returns a LogicResult when over it. */
	public static function rateLimit(string $action): ?LogicResult {
		$limit = max(1, SupportDesk::intSetting('support_desk_rate_limit_requests', 120));
		$state = RequestLogger::rate_limit_state('support_desk', $limit, 3600);
		if (!$state['allowed']) {
			if (function_exists('api_rate_limited')) {
				api_rate_limited($state, 'This address', $limit, 3600, 'support desk requests');
			}
			return LogicResult::error('Too many requests to the support desk from this address. Try again later.', array('reason' => 'rate_limited'));
		}
		return null;
	}

	/** The request headers, as sent. */
	public static function headers(): array {
		return function_exists('getallheaders') ? (array)getallheaders() : array();
	}

	public static function ip(): string {
		return (string)($_SERVER['REMOTE_ADDR'] ?? '');
	}

	/** Log a call (feeds the rate bucket) and answer it. */
	public static function ok(string $action, array $data): LogicResult {
		RequestLogger::log('support_desk', $action, true, array('status_code' => 200));
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
