<?php
/**
 * API action: mention_search — the people the editor's @ picker offers.
 *
 * POST /api/v1/action/mention_search (browser session). Params:
 *   space  string  the space being posted in, as 'type:id' (e.g. 'forum_category:12')
 *   q      string  what was typed after the @ (two characters at least)
 *
 * Answers at most eight people whose first name, last name, "first last" or
 * nickname starts with q — never a match on email — and only people who may
 * read that space (SpaceAccess). It is a picker, not a directory: a space the
 * caller cannot post in answers nobody, and the calls are rate-limited.
 *
 * @version 1.0.0
 */

define('MENTION_SEARCH_LIMIT', 8);
define('MENTION_SEARCH_CANDIDATES', 40);
define('MENTION_SEARCH_RATE', 60);         // calls …
define('MENTION_SEARCH_WINDOW', 60);       // … per this many seconds, per user

function mention_search_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	if ($user_id <= 0) {
		return LogicResult::error('Sign in required.');
	}

	$state = RequestLogger::rate_limit_state('mention_search', MENTION_SEARCH_RATE, MENTION_SEARCH_WINDOW, null, $user_id);
	if (!$state['allowed']) {
		return LogicResult::error('Too many searches. Try again in ' . $state['retry_after'] . ' seconds.');
	}
	RequestLogger::log('mention_search', 'search', true, array('user_id' => $user_id));

	$q = trim((string)($input['q'] ?? ''));
	$space = SpaceAccess::resolve((string)($input['space'] ?? ''));
	if ($space === null || !SpaceAccess::mayPost($space, $user_id)) {
		return LogicResult::render(array('people' => array()));
	}
	if ((function_exists('mb_strlen') ? mb_strlen($q) : strlen($q)) < 2) {
		return LogicResult::render(array('people' => array()));
	}

	// A prefix, with LIKE's own wildcards taken literally.
	$prefix = str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $q) . '%';
	$q_sql = DbConnector::GetPreparedStatement(
		"SELECT usr_user_id FROM usr_users
		 WHERE usr_delete_time IS NULL AND usr_is_disabled IS NOT TRUE AND usr_is_activated = TRUE
		   AND usr_user_id <> ?
		   AND (usr_first_name ILIKE ? OR usr_last_name ILIKE ? OR usr_nickname ILIKE ?
		        OR (COALESCE(usr_first_name, '') || ' ' || COALESCE(usr_last_name, '')) ILIKE ?)
		 ORDER BY usr_first_name, usr_last_name, usr_user_id
		 LIMIT " . MENTION_SEARCH_CANDIDATES);
	$q_sql->execute(array($user_id, $prefix, $prefix, $prefix, $prefix));

	$people = array();
	foreach ($q_sql->fetchAll(PDO::FETCH_COLUMN) as $candidate_id) {
		$candidate_id = (int)$candidate_id;
		$name = MentionParser::mentionableName($candidate_id, $space);
		if ($name === null) {
			continue;
		}
		$people[] = array(
			'id'    => $candidate_id,
			'name'  => $name,
			'token' => MentionParser::token($candidate_id, $name),
		);
		if (count($people) >= MENTION_SEARCH_LIMIT) {
			break;
		}
	}
	return LogicResult::render(array('people' => $people));
}

function mention_search_logic_descriptor(): array {
	return array(
		'description'      => 'Find people to @mention in a post, among those who can read the space.',
		'mutates'          => false,
		'requires_session' => true,
		'input'            => array(
			'space' => array('type' => 'string', 'required' => true, 'label' => 'Space'),
			'q'     => array('type' => 'string', 'required' => true, 'label' => 'Name starts with'),
		),
		'auth'             => array('capability' => 'write', 'min_user_permission' => 0),
	);
}
