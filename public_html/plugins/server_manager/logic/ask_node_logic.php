<?php
/**
 * server_manager/ask_node — ask a joined node one observe word from a working
 * session and read the answer, without the node page.
 *
 * The 2026-11-10 entry of specs/agent_recipes_and_vocabulary.md's running list:
 * a session checking a release could not ask a node `site_log` without driving
 * the admin pages in a browser. This action asks through ObserveWordAsk, the
 * same path the support desk uses: observe words only, the plane's closed
 * parameter sets, one job on the node's own list, requested_by
 * api_key:{id} so the owner sees on the node page who asked.
 *
 * Reached only by a machine key whose scope names this action (a key made on
 * Admin > API Keys for one session machine), at the look level (7).
 *
 * Input: node (slug), word, params (object of the word's own parameters),
 * wait (seconds, 1..90, default 60). Returns the job id, its status
 * (completed, failed, waiting), the agent's outcome and error, the processed
 * result, and the redacted transcript, cut to TRANSCRIPT_CAP.
 *
 * @version 1.0.0
 */

function ask_node_logic(array $input): LogicResult {
	$transcript_cap = 60000;

	$slug = strtolower(trim((string)($input['node'] ?? '')));
	$word = trim((string)($input['word'] ?? ''));
	$params = $input['params'] ?? array();
	if (is_string($params)) {
		$params = $params === '' ? array() : json_decode($params, true);
	}
	if (!is_array($params)) {
		return LogicResult::error('params must be an object of the word\'s parameters.');
	}
	$wait = isset($input['wait']) && $input['wait'] !== '' ? (int)$input['wait'] : 60;
	$wait = max(1, min(90, $wait));

	if (!ObserveWordAsk::available()) {
		return LogicResult::error('This site is not a management node.');
	}
	if ($slug === '' || $word === '') {
		return LogicResult::error('Give a node slug and a word.');
	}
	if (!in_array($word, ObserveWordAsk::WORDS, true)) {
		return LogicResult::error("'$word' is not an observe word that can be asked from here. Words: "
			. implode(', ', ObserveWordAsk::WORDS) . '.');
	}

	$nodes = new MultiManagedNode(array('slug' => $slug, 'deleted' => false));
	$node = null;
	foreach ($nodes as $candidate) {
		$node = $candidate;
		break;
	}
	if (!$node) {
		return LogicResult::error("No node with the slug '$slug' on this management node.");
	}
	if (!in_array($word, ObserveWordAsk::offered($node), true)) {
		return LogicResult::error("Node '$slug' does not report '$word' (agent " . AgentVocabulary::version($node) . ').');
	}

	$session = SessionControl::get_instance();
	$key = ApiAuth::currentApiKey();
	$requested_by = $key ? 'api_key:' . (int)$key->key : 'session:' . (int)$session->get_user_id();

	try {
		$asked = ObserveWordAsk::ask($node, $word, $params, $requested_by, $session->get_user_id(), $wait);
	} catch (\Throwable $e) {
		return LogicResult::error('Refused: ' . $e->getMessage());
	}

	$job = $asked['job'];
	$out = array(
		'node'          => $slug,
		'word'          => $word,
		'job_id'        => (int)$job->key,
		'status'        => $asked['status'],
		'agent_outcome' => $job->get('mjb_agent_outcome'),
		'error_message' => $job->get('mjb_error_message'),
		'result'        => null,
		'transcript'    => null,
	);
	if ($asked['status'] === 'completed' || $asked['status'] === 'failed') {
		if (JobResultProcessor::process_if_due($job)) {
			$job->load();
		}
		$result = $job->get('mjb_result');
		$out['result'] = $result ? json_decode($result, true) : null;
		$text = SmSecretRedactor::redact((string)$job->transcript());
		$out['transcript_truncated'] = strlen($text) > $transcript_cap;
		$out['transcript'] = substr($text, 0, $transcript_cap);
	}
	return LogicResult::render($out);
}

function ask_node_logic_descriptor(): array {
	return array(
		'description' => 'Ask a joined node one observe word (a read that changes nothing) and return its answer. For a session machine\'s scoped key.',
		'mutates'     => true,
		'requires_session' => true,
		'auth'        => array(
			'min_user_permission'  => 7,
			'requires_machine_key' => true,
			'requires_scoped_key'  => true,
		),
		'input'       => array(
			'node'   => array('type' => 'string', 'required' => true, 'label' => 'Node slug'),
			'word'   => array('type' => 'string', 'required' => true, 'label' => 'Observe word'),
			'params' => array('type' => 'object', 'required' => false, 'label' => 'The word\'s parameters'),
			'wait'   => array('type' => 'int', 'required' => false, 'label' => 'Seconds to wait (1-90)'),
		),
	);
}
?>
