<?php
/**
 * DnsPublishBox - the one publish surface, shared by every subsystem that
 * produces a DNS plan.
 *
 * A page hands it a DnsRecordPlan and a return URL; it decides what the box
 * shows, handles the actions, and renders. The mailbox Setup tab and node
 * provisioning use the same box, so a plan from anywhere gets the same states,
 * the same diff-before-write rail and the same credential rules.
 *
 * **The order is diff, then authorize, then write.** Building the diff is a
 * public-DNS read that needs no credential, so a deployment sees the whole
 * safety surface — what is missing, what differs, what conflicts, what would be
 * a cutover — before anything is authorized. Only pressing Apply starts the
 * consent flow (or takes a scoped key), and the credential lives for that one
 * request and is gone. Nothing DNS-write-capable is ever stored, not even
 * sealed.
 *
 * States:
 *   no_provider    no driver at all — the copy-paste table, unchanged
 *   prerequisite   the chosen driver needs something set up first
 *   not_delegated  the domain's nameservers do not point at this provider yet
 *   ready          nothing shown yet; the primary action reveals the diff
 *   diff           the four outcomes, with cutovers called out
 *   all_green      every record is published as planned
 *
 * @version 1.1
 * @changelog 1.1 - Collects an OAuth app registration in place when the chosen provider has none, gated at permission 10, then continues to consent in the same request; carries credentialGuide() through to the box
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsDriverRegistry.php'));
require_once(PathHelper::getIncludePath('includes/dns/DnsReconciler.php'));
require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));

class DnsPublishBox {

	const STATE_NO_PROVIDER     = 'no_provider';
	/** The domain's DNS lives somewhere this platform has no driver for. */
	const STATE_UNKNOWN_HOST    = 'unknown_host';
	const STATE_READY           = 'ready';
	const STATE_DIFF            = 'diff';
	const STATE_ALL_GREEN       = 'all_green';

	/** The OAuth flow purpose the shared callback dispatches on. */
	const OAUTH_PURPOSE = 'dns_publish';

	/** Where the account list from a multi-account grant is parked for one render. */
	const ACCOUNTS_SESSION_KEY = 'dns_publish_accounts';

	/**
	 * Handle a publish action. Returns a LogicResult to redirect on, or null
	 * when the request was not one of ours.
	 *
	 * @param callable $plan_factory  fn(): ?DnsRecordPlan — called only when needed,
	 *                                so a page pays nothing for the box it does not use.
	 */
	public static function handle(array $input, callable $plan_factory, string $return_url): ?LogicResult {
		// Cross-site forgery has nothing to gain here: the only action that
		// changes anything needs either a consent the operator completes at the
		// provider, or a credential typed into this form. A forged POST can at
		// most render a diff. The permission gate below still applies.
		$action = trim((string)($input['dns_action'] ?? ''));
		if ($action === '') {
			return null;
		}

		require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
		$session = SessionControl::get_instance();
		$session->check_permission(5);

		$plan = $plan_factory();
		if (!($plan instanceof DnsRecordPlan) || $plan->isEmpty()) {
			return LogicResult::redirect($return_url);
		}

		$driver_key = self::chosenProviderKey($input, $plan->getDomain());
		$driver_class = DnsDriverRegistry::get($driver_key);
		if ($driver_class === null) {
			self::flash($session, 'This deployment has no driver for the DNS host '
				. $plan->getDomain() . ' uses.', 'DNS host not supported');
			return LogicResult::redirect($return_url);
		}

		// Connecting a provider for the first time: save the app registration and
		// carry straight on to consent, so one press finishes what it started.
		if ($action === 'dns_oauth_config') {
			$blocked = self::saveOauthConfig($input, $driver_class, $return_url, $session);
			if ($blocked !== null) {
				return $blocked;
			}
			return self::startApply($input, $plan, $driver_class, $return_url, $session);
		}

		if ($action === 'dns_apply') {
			return self::startApply($input, $plan, $driver_class, $return_url, $session);
		}

		// 'dns_show' just reveals the diff — a credential-free read. Carry the
		// chosen provider so the next render keeps it.
		return LogicResult::redirect(self::urlWith($return_url, array(
			'dns_show'     => '1',
			'dns_provider' => $driver_key,
		)));
	}

	/**
	 * Apply: OAuth drivers hand off to consent (the token arrives at the
	 * callback, writes, and is gone with the request); API drivers write inline
	 * with the credential the operator just typed and discard it on return.
	 */
	private static function startApply(array $input, DnsRecordPlan $plan, string $driver_class,
			string $return_url, $session): LogicResult {

		$decisions = array(
			'adopt'   => array_values((array)($input['dns_adopt'] ?? array())),
			'cutover' => array_values((array)($input['dns_cutover'] ?? array())),
		);
		$account_id = trim((string)($input['dns_account'] ?? ''));

		if ($driver_class::credentialMode() === DnsProvider::CREDENTIAL_OAUTH2) {
			require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Client.php'));
			try {
				$consent_url = (new OAuth2Client())->beginConsent(
					$driver_class::oauthProviderKey(),
					$driver_class::oauthScopes(),
					self::OAUTH_PURPOSE,
					array(
						'driver'     => $driver_class::getKey(),
						'plan'       => $plan->toArray(),
						'decisions'  => $decisions,
						'account_id' => $account_id,
						'return_url' => $return_url,
					),
					$return_url
				);
			} catch (Throwable $e) {
				// The box collects the app registration itself when it is missing,
				// so this is a genuine failure rather than a setup step: say what
				// went wrong and leave the diff on screen.
				self::flash($session, $e->getMessage(), 'Could not start authorization');
				return LogicResult::redirect(self::urlWith($return_url, array('dns_show' => '1')));
			}
			return LogicResult::redirect($consent_url);
		}

		// API-credential driver: the key exists only inside this request.
		$credential = array('account_id' => $account_id);
		foreach ($driver_class::credentialFields() as $field => $spec) {
			$credential[$field] = trim((string)($input['dns_cred_' . $field] ?? ''));
		}
		$required = array_keys(array_filter($driver_class::credentialFields(), function ($spec) {
			return empty($spec['optional']);
		}));
		foreach ($required as $field) {
			if ($field === 'session_token' || $field === 'client_ip') {
				continue;   // genuinely optional in their drivers
			}
			if ($credential[$field] === '') {
				self::flash($session, 'Enter the ' . $driver_class::getLabel() . ' credential to publish.',
					'Credential required');
				return LogicResult::redirect(self::urlWith($return_url, array('dns_show' => '1')));
			}
		}

		$results = self::publish($driver_class, $credential, $plan, $decisions, DnsReconciler::APPLY_CONFIRMED);
		unset($credential);   // the only copy, gone before the response is built

		self::flash($session, self::summarizeResults($results), 'DNS publish');
		return LogicResult::redirect(self::urlWith($return_url, array('dns_show' => '1')));
	}

	/** The level that may write an OAuth app registration. See saveOauthConfig(). */
	const OAUTH_CONFIG_PERMISSION = 10;

	/**
	 * What the box needs to know about an OAuth driver's app registration.
	 *
	 * Nothing here is a secret: configFields() names settings and configGuide()
	 * describes clicks, both read before any value exists.
	 */
	private static function oauthConfigVars(string $driver_class, array &$vars): void {
		require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ProviderRegistry.php'));
		$provider_class = OAuth2ProviderRegistry::get($driver_class::oauthProviderKey());
		if ($provider_class === null) {
			return;
		}
		if ($provider_class::isConfigured()) {
			return;
		}

		$session = SessionControl::get_instance();
		$vars['oauth_needs_config']  = true;
		$vars['oauth_can_config']    = $session->get_permission() >= self::OAUTH_CONFIG_PERMISSION;
		$vars['oauth_config_fields'] = $provider_class::configFields();
		$vars['oauth_config_guide']  = $provider_class::configGuide();
	}

	/**
	 * Save an OAuth app registration from the box, then carry on to consent.
	 *
	 * Configuration happens where it is needed — nobody is sent to a settings
	 * page to finish a publish they already started. Two things make that safe
	 * rather than merely convenient:
	 *
	 *  - **An app registration is not a DNS-write credential.** It cannot write
	 *    anything on its own; a per-publish user grant is still required and is
	 *    still discarded with the request that used it. So storing it does not
	 *    weaken the rule that nothing DNS-write-capable is ever stored.
	 *  - **It is still a global credential**, shared with any other consumer of
	 *    the same provider — oauth_google_client_id is the same value Google
	 *    sign-in uses. Overwriting it can break sign-in for everyone, which is
	 *    why the write needs permission 10 even though the box itself opens at 5.
	 */
	private static function saveOauthConfig(array $input, string $driver_class, string $return_url, $session): ?LogicResult {
		require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ProviderRegistry.php'));
		require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ProviderConfig.php'));

		$provider_class = OAuth2ProviderRegistry::get($driver_class::oauthProviderKey());
		if ($provider_class === null) {
			self::flash($session, 'This deployment has no OAuth provider for '
				. $driver_class::getLabel() . '.', 'Cannot authorize');
			return LogicResult::redirect(self::urlWith($return_url, array('dns_show' => '1')));
		}

		if ($session->get_permission() < self::OAUTH_CONFIG_PERMISSION) {
			self::flash($session, 'Connecting ' . $provider_class::getLabel()
				. ' for the first time sets an application credential the whole site shares, '
				. 'so it needs a full administrator.', 'Not permitted');
			return LogicResult::redirect(self::urlWith($return_url, array('dns_show' => '1')));
		}

		$error = OAuth2ProviderConfig::save($provider_class, $input, 'dns_oauth_', $session);
		if ($error !== '') {
			self::flash($session, $error, 'Could not save');
			return LogicResult::redirect(self::urlWith($return_url, array('dns_show' => '1')));
		}

		if (!$provider_class::isConfigured()) {
			self::flash($session, 'Enter both the client ID and the client secret for '
				. $provider_class::getLabel() . '.', 'Incomplete');
			return LogicResult::redirect(self::urlWith($return_url, array('dns_show' => '1')));
		}

		return null;   // configured — the caller continues straight to consent
	}

	/**
	 * Build a driver, resolve the zone, and apply. Shared by the inline
	 * API-credential path and the OAuth callback consumer.
	 *
	 * @return array{results:array,error:string,accounts:array}
	 */
	public static function publish(string $driver_class, array $credential, DnsRecordPlan $plan,
			array $decisions, string $mode): array {

		$out = array('results' => array(), 'error' => '', 'accounts' => array());
		try {
			/** @var DnsProvider $driver */
			$driver = new $driver_class($credential);

			// A grant that reaches several accounts is ambiguous, and the
			// ambiguity is resolved at the moment it appears — never by
			// remembering an account.
			if (trim((string)($credential['account_id'] ?? '')) === '') {
				$accounts = $driver->accounts();
				if (count($accounts) > 1) {
					$out['accounts'] = $accounts;
					$out['error'] = 'This login reaches ' . count($accounts) . ' accounts. '
						. 'Choose which one holds the zone and publish again.';
					return $out;
				}
			}

			$zone = $driver->zoneFor($plan->getDomain());
			if ($zone === null) {
				$out['error'] = 'This credential can see no zone covering ' . $plan->getDomain()
					. '. The records below stay as instructions to publish by hand.';
				return $out;
			}

			$reconciler = new DnsReconciler();
			$out['results'] = $reconciler->apply($driver, $zone, $plan, $decisions, $mode);
		} catch (Throwable $e) {
			$out['error'] = $e->getMessage();
		}
		return $out;
	}

	// ==================================================================
	// Render vars
	// ==================================================================

	/**
	 * Everything the renderer needs. Cheap unless the diff is being shown: the
	 * diff itself is a handful of public DNS lookups.
	 */
	public static function build(?DnsRecordPlan $plan, array $input, string $return_url): array {
		$vars = array(
			'plan'          => $plan,
			'state'         => self::STATE_NO_PROVIDER,
			'return_url'    => $return_url,
			'provider_key'  => '',
			'provider_label' => '',
			'provider_class' => null,
			'provider_options' => DnsDriverRegistry::options(),
			'show_chooser'  => !empty($input['dns_choose']),
			'rows'          => array(),
			'counts'        => array(),
			'accounts'      => self::takeAccounts(),
			'settled'       => false,
			'last_written'  => '',
			'live_ns'       => array(),
			'detected_key'   => '',
			'detected_label' => '',
			'prerequisite'  => '',
			'credential_fields' => array(),
			'credential_guide'  => null,
			'oauth_config_fields' => array(),
			'oauth_config_guide'  => null,
			'oauth_needs_config'  => false,
			'oauth_can_config'    => false,
			'domain'        => $plan ? $plan->getDomain() : '',
		);

		if (!($plan instanceof DnsRecordPlan) || $plan->isEmpty()) {
			return $vars;
		}

		// Where this domain's DNS actually lives, read from the NS records it
		// answers with. This is the provider the box leads with — a domain is
		// configured where it already is, not where the deployment would prefer.
		$vars['live_ns'] = self::liveNameservers($plan->getDomain());
		$vars['detected_key'] = (string)DnsDriverRegistry::identifyHost($vars['live_ns']);
		if ($vars['detected_key'] !== '') {
			$detected_class = DnsDriverRegistry::get($vars['detected_key']);
			$vars['detected_label'] = $detected_class ? $detected_class::getLabel() : '';
		}

		$driver_key = self::chosenProviderKey($input, $plan->getDomain(), $vars['detected_key']);
		$driver_class = $driver_key !== '' ? DnsDriverRegistry::get($driver_key) : null;
		if ($driver_class === null) {
			// The domain's DNS host has no driver here. Nothing to offer, and
			// nothing is wrong — the records render as instructions, as always.
			$vars['state'] = self::STATE_UNKNOWN_HOST;
			return $vars;
		}

		$vars['provider_key']      = $driver_key;
		$vars['provider_class']    = $driver_class;
		$vars['provider_label']    = $driver_class::getLabel();
		$vars['prerequisite']      = $driver_class::prerequisiteNote();
		$vars['credential_fields'] = $driver_class::credentialFields();
		$vars['credential_guide']  = $driver_class::credentialGuide();
		$vars['state']             = self::STATE_READY;

		// An OAuth2 driver needs the deployment's app registration before consent
		// can happen at all. When it is missing, the box collects it here rather
		// than sending the operator to a settings page — configuration belongs at
		// the moment it is needed.
		if ($driver_class::credentialMode() === DnsProvider::CREDENTIAL_OAUTH2) {
			self::oauthConfigVars($driver_class, $vars);
		}

		if (!empty($input['dns_show'])) {
			$reconciler = new DnsReconciler();
			$vars['rows']   = $reconciler->diffAgainstPublicDns($plan);
			$vars['counts'] = DnsReconciler::summarize($vars['rows']);
			// Settled, not all-green: a record written moments ago is done as far
			// as the operator is concerned, even though DNS has not caught up.
			// Offering the form again there is what makes a successful publish
			// read as a failed one.
			$vars['settled']      = DnsReconciler::settled($vars['rows']);
			$vars['last_written'] = DnsReconciler::lastWritten($vars['rows']);
			$vars['state']        = $vars['settled'] ? self::STATE_ALL_GREEN : self::STATE_DIFF;
		}

		return $vars;
	}

	// ==================================================================
	// Helpers
	// ==================================================================

	/**
	 * The provider for this request, in priority order:
	 *
	 *  1. what the operator explicitly picked;
	 *  2. the host the domain's DNS already lives at;
	 *  3. the deployment default, when the domain's host could not be identified
	 *     but the resolver did answer — a hosted zone we simply cannot name.
	 *
	 * Returns '' when the domain resolves to a host no shipped driver serves,
	 * which is a supported state, not a failure: the records render as
	 * instructions exactly as they always have.
	 */
	public static function chosenProviderKey(array $input, string $domain = '', ?string $detected = null): string {
		$chosen = trim((string)($input['dns_provider'] ?? ''));
		if ($chosen !== '' && DnsDriverRegistry::get($chosen) !== null) {
			return $chosen;
		}
		if ($detected === null && $domain !== '') {
			$detected = (string)DnsDriverRegistry::identifyHost(self::liveNameservers($domain));
		}
		if ((string)$detected !== '' && DnsDriverRegistry::get((string)$detected) !== null) {
			return (string)$detected;
		}
		// No NS answer at all (a brand-new domain, or a resolver hiccup) is not
		// evidence the host is unsupported, so the default still applies there.
		if ($domain !== '' && !empty(self::liveNameservers($domain))) {
			return '';
		}
		return DnsDriverRegistry::defaultKey();
	}

	/** @var array<string,string[]> One NS lookup per domain per request. */
	private static $ns_cache = array();

	/**
	 * The nameservers answering for a domain's zone.
	 *
	 * A subdomain almost never has NS records of its own — mail.example.com
	 * lives in example.com's zone — so this walks up the label chain until
	 * something answers. Without that, every subdomain would look like a host
	 * we cannot identify. It stops before a bare TLD; a registry's nameservers
	 * match no driver anyway, so an over-long walk identifies nothing rather
	 * than identifying something wrong.
	 *
	 * @return string[] Empty when nothing in the chain answers.
	 */
	private static function liveNameservers(string $domain): array {
		$domain = DnsRecord::normalizeName($domain);
		if (isset(self::$ns_cache[$domain])) {
			return self::$ns_cache[$domain];
		}
		$out = array();
		$labels = explode('.', $domain);
		while (count($labels) >= 2) {
			$candidate = implode('.', $labels);
			try {
				$records = @dns_get_record($candidate, DNS_NS);
				if (is_array($records)) {
					foreach ($records as $record) {
						if (!empty($record['target'])) {
							$out[] = DnsRecord::normalizeName((string)$record['target']);
						}
					}
				}
			} catch (Throwable $e) {
				$out = array();
			}
			if (!empty($out)) {
				break;
			}
			array_shift($labels);
		}
		self::$ns_cache[$domain] = $out;
		return $out;
	}

	/** A one-line, honest summary of a per-record apply. */
	public static function summarizeResults(array $publish): string {
		if ($publish['error'] !== '' && empty($publish['results'])) {
			return $publish['error'];
		}
		$counts = array();
		$failures = array();
		foreach ($publish['results'] as $result) {
			$counts[$result['action']] = ($counts[$result['action']] ?? 0) + 1;
			if (!$result['ok']) {
				$failures[] = $result['record']->describe() . ' — ' . $result['reason'];
			}
		}
		$parts = array();
		foreach (array('created', 'updated', 'adopted', 'unchanged', 'skipped', 'failed') as $action) {
			if (!empty($counts[$action])) {
				$parts[] = $counts[$action] . ' ' . $action;
			}
		}
		$summary = $parts ? implode(', ', $parts) . '.' : 'Nothing to change.';
		if (!empty($failures)) {
			$summary .= ' ' . implode(' ', $failures);
		}
		if ($publish['error'] !== '') {
			$summary .= ' ' . $publish['error'];
		}
		return $summary;
	}

	/** Park a multi-account grant's account list for exactly one render. */
	public static function parkAccounts(array $accounts): void {
		if (session_status() === PHP_SESSION_ACTIVE) {
			$_SESSION[self::ACCOUNTS_SESSION_KEY] = $accounts;
		}
	}

	/** Take and clear the parked account list. */
	private static function takeAccounts(): array {
		if (empty($_SESSION[self::ACCOUNTS_SESSION_KEY])) {
			return array();
		}
		$accounts = (array)$_SESSION[self::ACCOUNTS_SESSION_KEY];
		unset($_SESSION[self::ACCOUNTS_SESSION_KEY]);
		return $accounts;
	}

	/** Add query parameters to a URL that may already carry some. */
	public static function urlWith(string $url, array $params): string {
		foreach ($params as $key => $value) {
			$url .= (strpos($url, '?') === false ? '?' : '&') . rawurlencode($key) . '=' . rawurlencode((string)$value);
		}
		return $url;
	}

	private static function flash($session, string $message, string $title): void {
		$session->save_message(new DisplayMessage(
			$message, $title, '~.*~',
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
	}
}
