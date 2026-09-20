<?php
/**
 * services_enroll - a self-hosted site asks for one of this operator's
 * services: outbound mail, or the backup shelf.
 *
 * (specs/services_phase2_platform.md §4). Called by the SITE against this
 * plane's /api/v1 over its connected key; runs as the account that key
 * belongs to, and the key itself is the site's identity (D2). The tenant row
 * is created at `unpaid` on first contact and the answer is *not entitled*
 * until an operator grants a date; a site that is entitled gets the service
 * built in this call and the response carries what it writes: the send
 * values and the DNS records for mail, the slug and prefix for the shelf.
 *
 * Idempotent on the row. For mail a repeat mints a fresh SMTP user inside the
 * same subaccount (the password is never kept on this plane), so the answer
 * is always a working credential.
 *
 * @version 1.0
 */
function services_enroll_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	$session = SessionControl::get_instance();
	$user_id = intval($session->get_user_id());
	if (!$user_id) {
		return LogicResult::error('You must be signed in to enrol.');
	}
	$key_id = intval($session->get_api_key_id());
	$service = trim((string)($input['service'] ?? ''));
	$host = trim((string)($input['host'] ?? ''));

	try {
		$data = JoineryServices::enrol($user_id, $key_id, $service, $host);
	} catch (JoineryServicesException $e) {
		return LogicResult::error($e->getMessage());
	}
	return LogicResult::render($data);
}

function services_enroll_logic_descriptor(): array {
	return array(
		'description'      => 'Enrol this site for one of the operator\'s services (mail or shelf). Answers the tenant\'s state and, when entitled, what the site writes: SMTP send values and DNS records for mail, the shelf slug and prefix for backups.',
		'requires_session' => true,
		'mutates'          => true,
		'input'            => array(
			'service' => array('type' => 'string', 'required' => true, 'label' => 'Service: mail or shelf'),
			'host'    => array('type' => 'string', 'required' => true, 'label' => 'This site\'s hostname'),
		),
	);
}
?>
