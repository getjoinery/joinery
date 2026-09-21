<?php
/**
 * ServicesClient — this site's side of the services it rents from a Joinery
 * deployment: outbound mail and backup storage
 * (specs/services_phase2_platform.md §4, §9).
 *
 * The connection is three core settings — services_url, and the key pair
 * the Connect flow sealed here — and the key pair is this site's identity
 * at the operator. Everything the site asks goes through the one ServiceClient
 * shape: enrol, status and release for each service, and the five shelf
 * broker calls the backup engine makes for a `managed` target.
 *
 * What the answers do to this site lives here too: applyMailEnrolment()
 * writes the nine send settings through HostedMailSettingsMap (the same list
 * a Managed node's script uses) and records what the operator said about the
 * sender domain; storeConnection() seals the pair; the daily poll writes the
 * banner settings from status().
 *
 * @version 1.0
 */
class ServicesClient extends ServiceClient {

	/** The email_service value a site sends through the operator's account with. */
	const MAIL_SERVICE_KEY = 'joinery_services';

	public function __construct() {
		parent::__construct('services_url', 'services_api_public_key', 'services_api_secret_key', 'server_manager');
	}

	protected function label(): string {
		return 'getjoinery';
	}

	protected function notConfiguredMessage(): string {
		return 'This site is not connected to a getjoinery account yet. Use the Connect button on the setup wizard\'s Email or Backups step.';
	}

	/** Write a setting and drop the cached read, so this request sees what it wrote. */
	private static function put(string $name, string $value): void {
		Setting::put($name, $value);
		Globalvars::get_instance()->forget_setting($name);
	}

	// ── The connection ────────────────────────────────────────────────────────

	/** Is this site linked to an account at the operator? */
	public static function connected(): bool {
		return (new self())->configured();
	}

	/** The account the site is linked to, as the Connect return recorded it. */
	public static function account(): string {
		return trim((string)Globalvars::get_instance()->get_setting('services_account', false, true));
	}

	/** The operator's base URL, or '' when unset. */
	public static function operatorUrl(): string {
		return (new self())->serviceUrl();
	}

	/** This site's own hostname: the label the operator records, and the sender domain's parent. */
	public static function host(): string {
		$web = trim((string)Globalvars::get_instance()->get_setting('webDir'));
		$host = $web !== '' ? preg_replace('#^https?://#', '', rtrim($web, '/')) : (string)($_SERVER['HTTP_HOST'] ?? '');
		$host = strtolower(trim((string)preg_replace('/:\d+$/', '', (string)$host)));
		return (string)preg_replace('#/.*$#', '', $host);
	}

	/**
	 * Seal the pair the Connect return delivered, and remember the account.
	 * The secret is sealed wherever this site has a secret_box_key.
	 */
	public static function storeConnection(string $public_key, string $secret_key, string $account): void {
		self::put('services_api_public_key', trim($public_key));
		self::put('services_api_secret_key', ServiceClient::storedSecret('services_api_secret_key', trim($secret_key)));
		self::put('services_account', trim($account));
	}

	/** Forget the pair. Refused while a service is still held — release first (§4). */
	public static function clearConnection(): void {
		self::put('services_api_public_key', '');
		self::put('services_api_secret_key', '');
		self::put('services_account', '');
		self::put('services_mail_state', '');
	}

	// ── The Connect flow, this side ───────────────────────────────────────────

	/** The page the operator sends the key back to. */
	public static function returnUrl(): string {
		$host = self::host();
		$scheme = (in_array($host, array('localhost', '127.0.0.1'), true)) ? 'http' : 'https';
		return $scheme . '://' . $host . '/services_connected';
	}

	/**
	 * Start the link: mint the single-use state bound to this browser session
	 * and answer the operator's authorise URL to send the owner to.
	 */
	public static function connectUrl(string $step = 'mail_send'): string {
		$operator = self::operatorUrl();
		if ($operator === '') {
			throw new ServiceClientException('No services URL is set on this site.');
		}
		$state = OAuth2State::issue('getjoinery', 'services_connect', array(), array('step' => $step), self::returnUrl());
		return $operator . '/services/authorize?' . http_build_query(array(
			'site' => self::host(), 'return' => self::returnUrl(), 'state' => $state,
		));
	}

	/**
	 * The landing: the state is consumed (a redirect that lands twice, or
	 * with a spent state, is refused), the pair is sealed, the account is
	 * read from the operator. Answers where to send the owner next and what
	 * to tell them.
	 *
	 * @return array{ok:bool, message:string, step:string}
	 */
	public static function finishConnect(array $query): array {
		$flow = OAuth2State::validate(trim((string)($query['state'] ?? '')));
		$step = is_array($flow) ? (string)($flow['payload']['step'] ?? 'mail_send') : 'mail_send';
		if ($flow === null || ($flow['purpose'] ?? '') !== 'services_connect') {
			return array('ok' => false, 'step' => $step,
				'message' => 'That connection link has expired or was already used. Press Connect again.');
		}
		if ((string)($query['error'] ?? '') !== '') {
			return array('ok' => false, 'step' => $step, 'message' => 'The link was not approved on getjoinery. Nothing changed here.');
		}
		$public_key = trim((string)($query['public_key'] ?? ''));
		$secret_key = trim((string)($query['secret_key'] ?? ''));
		if (!preg_match('/^public_[a-z0-9]{8,64}$/i', $public_key) || !preg_match('/^secret_[a-z0-9]{8,64}$/i', $secret_key)) {
			return array('ok' => false, 'step' => $step, 'message' => 'The key that came back is not in the shape getjoinery mints. Press Connect again.');
		}
		self::storeConnection($public_key, $secret_key, '');
		$account = '';
		try {
			$status = (new self())->status();
			$account = trim((string)($status['account'] ?? ''));
		} catch (\Throwable $e) {
			error_log('ServicesClient: status after connect failed: ' . $e->getMessage());
		}
		if ($account !== '') {
			self::put('services_account', $account);
		}
		return array('ok' => true, 'step' => $step,
			'message' => 'This site is connected to your getjoinery account' . ($account !== '' ? ' (' . $account . ')' : '') . '.');
	}

	// ── The services ──────────────────────────────────────────────────────────

	public function enrolMail(): array {
		$answer = $this->call('services_enroll', array('service' => 'mail', 'host' => self::host()));
		self::recordMailAnswer($answer);
		return $answer;
	}

	public function enrolShelf(): array {
		return $this->call('services_enroll', array('service' => 'shelf', 'host' => self::host()));
	}

	/** Every service this site holds, in the C2 shape; the mail answer is recorded. */
	public function status(): array {
		$answer = $this->call('services_status', array('host' => self::host()));
		if (isset($answer['services']['mail']) && is_array($answer['services']['mail'])) {
			self::recordMailAnswer($answer['services']['mail']);
		}
		return $answer;
	}

	public function release(string $service): array {
		$answer = $this->call('services_release', array('service' => $service));
		if ($service === 'mail') {
			self::recordMailAnswer($answer);
		}
		return $answer;
	}

	// ── The backup storage broker ──────────────────────────────────────────────────────

	public function shelfBeginRun(string $profile, string $chain, array $artifacts): array {
		return $this->call('shelf_begin_run', array('profile' => $profile, 'chain' => $chain, 'artifacts' => $artifacts));
	}

	public function shelfSign(int $run_id, string $name, string $operation, array $args = array()): array {
		return $this->call('shelf_sign', array('run_id' => $run_id, 'name' => $name, 'operation' => $operation) + $args);
	}

	public function shelfList(string $prefix = ''): array {
		return $this->call('shelf_list', array('prefix' => $prefix));
	}

	public function shelfFinishRun(int $run_id, array $completed): array {
		return $this->call('shelf_finish_run', array('run_id' => $run_id, 'completed' => $completed));
	}

	public function shelfStatus(): array {
		return $this->call('shelf_status', array());
	}

	// ── What the mail answers do here ─────────────────────────────────────────

	/**
	 * An entitled enrol answer carries the send values: write them through
	 * the shared map (email_service = getjoinery, the SMTP values beneath)
	 * and keep what the operator said about the sender domain.
	 */
	public static function applyMailEnrolment(array $answer): array {
		if (empty($answer['entitled']) || empty($answer['mail']) || !is_array($answer['mail'])) {
			return array();
		}
		$values = $answer['mail'];
		$values['service'] = self::MAIL_SERVICE_KEY;
		$written = HostedMailSettingsMap::apply($values);
		self::recordMailAnswer($answer);
		return $written;
	}

	/**
	 * The last word from the operator on this site's mail: sender domain,
	 * whether it has verified, the records, the state, the date, the notice.
	 */
	public static function mailState(): array {
		$raw = trim((string)Globalvars::get_instance()->get_setting('services_mail_state', false, true));
		$decoded = $raw === '' ? null : json_decode($raw, true);
		return is_array($decoded) ? $decoded : array();
	}

	/** Keep the C2 fields for mail, never a credential. */
	public static function recordMailAnswer(array $answer): void {
		$mail = isset($answer['mail']) && is_array($answer['mail']) ? $answer['mail'] : array();
		$state = array(
			'domain'       => (string)($answer['domain'] ?? $mail['domain'] ?? ''),
			'domain_state' => (string)($answer['domain_state'] ?? $mail['domain_state'] ?? ''),
			'records'      => (array)($answer['records'] ?? $mail['records'] ?? array()),
			'state'        => (string)($answer['state'] ?? ''),
			'entitled'     => !empty($answer['entitled']),
			'paid_until'   => (string)($answer['paid_until'] ?? ''),
			'notice'       => (string)($answer['notice'] ?? ''),
			'manage_url'   => (string)($answer['manage_url'] ?? ''),
			'checked_time' => gmdate('Y-m-d H:i:s'),
		);
		self::put('services_mail_state', json_encode($state));
	}

	/** Is this site's outbound mail the operator's? */
	public static function mailEnrolled(): bool {
		return trim((string)Globalvars::get_instance()->get_setting('email_service', false, true)) === self::MAIL_SERVICE_KEY;
	}
}
