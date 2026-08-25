<?php
/**
 * Live availability and one-year price for a domain, for the checkout field.
 *
 * Guest-reachable and browser-credential only: the page JS calls this as the
 * buyer types, before they have an account. API keys are refused because this
 * is a registry lookup on the operator's registrar quota, and the CSRF-bound
 * browser credential keeps callers to same-origin page JS. The field's own
 * debounce plus the registrar's limits are the whole rate story at v1 volume.
 *
 * This answer is a courtesy, never the authority. The number that gets charged
 * is derived again, server-side, by ManagedDomainRequirement::validate() when
 * the form is submitted.
 *
 * @version 1.0.0
 */

function domain_check_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/domain_registrar/DomainRegistrarRegistry.php'));

	$domain = DomainRegistrarRegistry::normalizeName((string)($input['domain'] ?? ''));

	$unavailable = function ($message) {
		return LogicResult::render(array(
			'available'     => false,
			'price_year'    => null,
			'price_display' => '',
			'message'       => $message,
		));
	};

	if ($domain === '') {
		return $unavailable('Enter a domain name.');
	}
	if (!DomainRegistrarRegistry::isRegistrableName($domain)) {
		return $unavailable('Enter it like smithfamily.com.');
	}
	if (!DomainRegistrarRegistry::tldOffered($domain)) {
		return $unavailable('We can register ' . DomainRegistrarRegistry::offeredTldsPhrase() . ' names.');
	}

	$registrar = DomainRegistrarRegistry::firstConfigured();
	if ($registrar === null) {
		return $unavailable('Domain registration is not available right now.');
	}

	try {
		$answers = $registrar->checkAvailability(array($domain));
	} catch (DomainRegistrarException $e) {
		error_log('domain_check_logic: ' . $domain . ': ' . $e->getMessage());
		return LogicResult::error('Availability check failed — try again.');
	}

	$answer = $answers[$domain] ?? null;
	if ($answer === null) {
		return $unavailable('We could not check that name — try again in a moment.');
	}

	$price = $answer['price_year'] ?? null;
	$settings = Globalvars::get_instance();
	$symbol = CurrencyHelper::symbol(strtolower((string)$settings->get_setting('site_currency'))) ?: '$';

	return LogicResult::render(array(
		'available'     => !empty($answer['available']),
		'price_year'    => $price,
		'price_display' => $price !== null ? $symbol . $price : '',
		'message'       => (string)($answer['message'] ?? ''),
	));
}

function domain_check_logic_descriptor(): array {
	return array(
		'description'      => 'Live availability and one-year price for a managed-domain checkout.',
		'requires_session' => true,
		'mutates'          => false,
		'requires_setting' => 'server_manager_namecheap_api_user',
		'auth'             => array(
			'allow_guest'              => true,
			'requires_browser_session' => true,
		),
		'input'            => array(
			'domain' => array('type' => 'string', 'required' => true,
				'max_length' => 253, 'label' => 'Domain'),
		),
	);
}
