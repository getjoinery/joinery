<?php
/**
 * ServiceClient - the tenant side of a service another deployment runs.
 *
 * (specs/services_phase2_platform.md §4, §14 E1). One deployment rents
 * something from another: a relay slot on the mailbox fleet, outbound mail,
 * the backup storage. Whatever the service, the tenant reaches it the same way:
 * the operator's `/api/v1/action/{segment}/{action}` endpoint, as JSON, with
 * the tenant account's API key pair in the request headers — the pair alone
 * is the identity on the wire, and the operator resolves the tenant from the
 * key's user. Nothing about a specific service lives here: a service's client
 * extends this with its own actions and what it does with the answers.
 *
 * Three settings name the connection: the service URL, the public key and the
 * secret key. The secret is read through SecretBox::open(), so a value the
 * reconciler has sealed and a plaintext one both work.
 *
 * @version 1.1 - secretKey() answers on a deployment with no secret_box_key instead of throwing
 * @version 1.0 - lifted from the mailbox FleetClient's configured() and call()
 */

class ServiceClientException extends Exception {}

class ServiceClient {

	/** @var Globalvars */
	protected $settings;

	private $url_setting;
	private $public_key_setting;
	private $secret_key_setting;
	private $segment;

	/**
	 * @param string $url_setting        The setting holding the service base URL.
	 * @param string $public_key_setting The setting holding the API public key.
	 * @param string $secret_key_setting The setting holding the API secret key
	 *                                   (sealed or plaintext).
	 * @param string $segment            The action namespace on the operator:
	 *                                   `/api/v1/action/{segment}/{action}`.
	 */
	public function __construct(string $url_setting, string $public_key_setting,
			string $secret_key_setting, string $segment) {
		$this->settings = Globalvars::get_instance();
		$this->url_setting = $url_setting;
		$this->public_key_setting = $public_key_setting;
		$this->secret_key_setting = $secret_key_setting;
		$this->segment = trim($segment, '/');
	}

	/** True when the three connection settings are filled in. */
	public function configured(): bool {
		return $this->serviceUrl() !== ''
			&& $this->publicKey() !== ''
			&& $this->secretKey() !== '';
	}

	/** The service base URL with no trailing slash ('' when unset). */
	public function serviceUrl(): string {
		return rtrim(trim((string)$this->settings->get_setting($this->url_setting, false, true)), '/');
	}

	public function publicKey(): string {
		return trim((string)$this->settings->get_setting($this->public_key_setting, false, true));
	}

	/**
	 * The secret key, plaintext for use. A sealed value opens; a plaintext
	 * value passes through; a dead blob reads as '' (not configured).
	 */
	protected function secretKey(): string {
		$stored = trim((string)$this->settings->get_setting($this->secret_key_setting, false, true));
		if ($stored === '') {
			return '';
		}
		try {
			$box = new SecretBox();
		} catch (\Throwable $e) {
			// No secret_box_key on this deployment: a plaintext value is usable
			// as it is; a sealed one cannot be opened here, so it is not configured.
			return SecretBox::looksEncrypted($stored) ? '' : $stored;
		}
		return trim((string)($box->open($stored)['value'] ?? ''));
	}

	/**
	 * A secret-key setting's value as it is stored: sealed wherever a
	 * secret_box_key exists, plaintext on a zero-config deployment (where the
	 * reconciler seals it on its next cold pass). The locator must be declared
	 * in a `sealed_secrets` block; an undeclared one fails loudly.
	 */
	public static function storedSecret(string $setting_name, string $plaintext): string {
		if ($plaintext === '') {
			return '';
		}
		try {
			$box = new SecretBox();
		} catch (\Throwable $e) {
			return $plaintext;
		}
		return $box->seal($setting_name, $plaintext);
	}

	/** The name the service goes by in a message to a person. */
	protected function label(): string {
		return 'Service';
	}

	/** What to say when the connection settings are missing. */
	protected function notConfiguredMessage(): string {
		return $this->label() . ' is not configured — set the service URL and API keys.';
	}

	/**
	 * One service API call. Returns the response's data array; throws
	 * ServiceClientException with a user-facing message on any failure.
	 */
	public function call(string $action, array $payload): array {
		if (!$this->configured()) {
			throw new ServiceClientException($this->notConfiguredMessage());
		}
		$url = $this->serviceUrl() . '/api/v1/action/' . $this->segment . '/' . rawurlencode($action);
		$headers = array(
			'Content-Type: application/json',
			// Dash spelling: Apache→FPM stacks silently drop header names
			// containing underscores (see api/apiv1.php's normalization).
			'public-key: ' . $this->publicKey(),
			'secret-key: ' . $this->secretKey(),
		);
		$response = $this->transport($url, $headers, (string)json_encode($payload));

		if ($response['body'] === false) {
			throw new ServiceClientException('Could not reach ' . lcfirst($this->label()) . ': ' . $response['error']);
		}
		$decoded = json_decode((string)$response['body'], true);
		if (!is_array($decoded)) {
			throw new ServiceClientException($this->label() . ' returned an unreadable response (HTTP ' . $response['http'] . ').');
		}
		if ($response['http'] !== 200) {
			$msg = (string)($decoded['error'] ?? $decoded['error_message'] ?? $decoded['message'] ?? ('HTTP ' . $response['http']));
			throw new ServiceClientException($this->label() . ': ' . $msg);
		}
		return is_array($decoded['data'] ?? null) ? $decoded['data'] : array();
	}

	/**
	 * The HTTP leg, on its own so a test can stand in for the network.
	 *
	 * @return array{body:string|false, http:int, error:string}
	 */
	protected function transport(string $url, array $headers, string $body): array {
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_HTTPHEADER     => $headers,
		));
		$out = curl_exec($ch);
		$err = curl_error($ch);
		$http = intval(curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
		curl_close($ch);
		return array('body' => $out, 'http' => $http, 'error' => (string)$err);
	}
}
