<?php
/**
 * Record a visitor's cookie-consent choices (GDPR/CCPA audit trail). Called
 * by the consent banner JS on every page, logged-in or not — guest-reachable
 * via the anonymous browser credential (docs/api.md § Authentication), which
 * replaces the hand-rolled Origin/Referer check the legacy endpoint used.
 *
 * @version 1.0.0
 */

function consent_record_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/ConsentHelper.php'));

	$analytics = !empty($input['analytics']);
	$marketing = !empty($input['marketing']);

	// Tie the audit row to the analytics visitor id when one exists, else the
	// session uniqid — the same visitor identity SessionControl records page
	// views under. vse_visitor_id is varchar(20); both sources fit.
	$visitor_id = isset($_COOKIE['visitor_id'])
		? substr((string) $_COOKIE['visitor_id'], 0, 20)
		: SessionControl::get_instance()->get_uniqid();

	ConsentHelper::get_instance()->recordConsent($visitor_id, $analytics, $marketing);

	return LogicResult::render(array('recorded' => true));
}

function consent_record_logic_descriptor(): array {
	return [
		'description'      => 'Record the visitor\'s cookie-consent choices for the compliance audit trail.',
		'requires_session' => true,
		'mutates'          => true,
		'auth'             => [
			'allow_guest'              => true,
			'requires_browser_session' => true,
		],
		'input'            => [
			'analytics' => ['type' => 'bool', 'required' => false, 'label' => 'Analytics cookies allowed'],
			'marketing' => ['type' => 'bool', 'required' => false, 'label' => 'Marketing cookies allowed'],
		],
	];
}
?>
