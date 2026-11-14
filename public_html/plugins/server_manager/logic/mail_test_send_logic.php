<?php
/**
 * server_manager/mail_test_send — make a joined site send one fixed test
 * message from one of its own ordinary mail domains to the probe mailbox, and
 * return what the send did, from a working session.
 *
 * The operate half of what a session needs to check a mailbox release alone
 * (specs/agent_recipes_and_vocabulary.md, 2026-11-14): ask_node reads, and
 * stays observe-only; this sends. The message's token comes back, and the
 * session reads the receiver's SPF, DKIM and DMARC verdicts on that message.
 *
 * Reached only by a machine key whose scope names this action, at the change
 * level (8). The node refuses a domain it does not host, a protected domain,
 * test mode, and a second send for a domain within ten minutes.
 *
 * Input: node (slug), domain, wait (1..90 seconds, default 60).
 *
 * @version 1.0.0
 */

function mail_test_send_logic(array $input): LogicResult {
	$slug = strtolower(trim((string)($input['node'] ?? '')));
	$domain = strtolower(trim((string)($input['domain'] ?? '')));
	$wait = isset($input['wait']) && $input['wait'] !== '' ? max(1, min(90, (int)$input['wait'])) : 60;

	if (!ObserveWordAsk::available()) {
		return LogicResult::error('This site is not a management node.');
	}
	if ($slug === '' || $domain === '') {
		return LogicResult::error('Give a node slug and a domain.');
	}
	$node = null;
	foreach (new MultiManagedNode(array('slug' => $slug, 'deleted' => false)) as $candidate) {
		$node = $candidate;
		break;
	}
	if (!$node) {
		return LogicResult::error("No node with the slug '$slug' on this management node.");
	}

	$session = SessionControl::get_instance();
	$key = ApiAuth::currentApiKey();
	$requested_by = $key ? 'api_key:' . (int)$key->key : 'session:' . (int)$session->get_user_id();
	try {
		$built = JobCommandBuilder::build_mail_test_send($node, $domain);
		$job = ManagementJob::createFromBuild((int)$node->key, 'mail_test_send', $built,
			array('requested_by' => $requested_by), $session->get_user_id());
	} catch (\Throwable $e) {
		return LogicResult::error('Refused: ' . $e->getMessage());
	}
	$asked = ObserveWordAsk::waitFor($job, $wait);
	$job = $asked['job'];
	$out = array(
		'node'          => $slug,
		'domain'        => $domain,
		'job_id'        => (int)$job->key,
		'status'        => $asked['status'],
		'agent_outcome' => $job->get('mjb_agent_outcome'),
		'error_message' => $job->get('mjb_error_message'),
		'result'        => null,
	);
	if ($asked['status'] === 'completed' || $asked['status'] === 'failed') {
		if (JobResultProcessor::process_if_due($job)) {
			$job->load();
		}
		$result = $job->get('mjb_result');
		$out['result'] = $result ? json_decode($result, true) : null;
	}
	return LogicResult::render($out);
}

function mail_test_send_logic_descriptor(): array {
	return array(
		'description' => 'Make a joined site send one fixed test message from one of its ordinary mail domains to the probe mailbox, and return its token. For a session machine\'s scoped key.',
		'mutates'     => true,
		'requires_session' => true,
		'auth'        => array(
			'min_user_permission'  => 8,
			'requires_machine_key' => true,
			'requires_scoped_key'  => true,
		),
		'input'       => array(
			'node'   => array('type' => 'string', 'required' => true, 'label' => 'Node slug'),
			'domain' => array('type' => 'string', 'required' => true, 'label' => 'Mail domain'),
			'wait'   => array('type' => 'int', 'required' => false, 'label' => 'Seconds to wait (1-90)'),
		),
	);
}
?>
