<?php
/**
 * TrustedRouterClient — our TrustedRouter account, acting for the sites we
 * give AI credit (specs/hosted_ai_credit.md §2, WP2).
 *
 * Each site gets one inference key in our account, created with a lifetime
 * dollar limit. TrustedRouter enforces the limit (HTTP 402 past it), so the
 * site's admin, who is permission 10 on their own box, cannot spend more than
 * their credit by editing anything there. This class only creates, caps,
 * disables, lists and deletes those keys, and reads our own balance.
 *
 * EVERY CALL IS MADE WITH THE MANAGEMENT KEY, AND IT NEVER LEAVES THIS
 * MACHINE. What reaches a site is its own inference key, returned once by
 * createKey() and never stored here.
 *
 * Shapes are TrustedRouter's published source (github.com/Lore-Hex/quill-router
 * at f0bff0a, routes/keys.py and routes/billing.py): every answer is
 * {"data": …}; a created key's secret is the top-level "key"; usage and
 * limits come back in microdollars beside the dollar figures. Limits are sent
 * as dollar strings to six places, which TrustedRouter reads as a Decimal, so
 * nothing is lost to a float.
 *
 * @version 1.0
 */

require_once(PathHelper::getComposerAutoloadPath());

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\GuzzleException;

class TrustedRouterException extends Exception {}

class TrustedRouterClient {

	const API_BASE = 'https://trustedrouter.com/v1/';

	/** The most keys one list call returns. */
	const PAGE = 1000;

	/** @var Client */
	private $http;
	/** @var string */
	private $management_key;

	public function __construct(string $management_key, ?Client $http = null) {
		$this->management_key = $management_key;
		$this->http = $http ?: new Client(array(
			'base_uri'        => self::API_BASE,
			'timeout'         => 30,
			'connect_timeout' => 10,
		));
	}

	/**
	 * The configured client, or null when no management key is set: a
	 * deployment that sells no AI credit has no account, and the leg reads
	 * that as "not configured".
	 */
	public static function fromSettings(): ?TrustedRouterClient {
		$key = trim(ProvisioningSetup::readSecret('server_manager_trustedrouter_api_key'));
		return $key === '' ? null : new self($key);
	}

	/**
	 * Create a site's key at $limit_micro. Returns ['hash' => …, 'key' => …].
	 * The key is shown once: the caller pushes it and forgets it.
	 */
	public function createKey(string $name, int $limit_micro, array $tags = array()): array {
		$body = array(
			'name'  => mb_substr($name, 0, 120),
			'limit' => self::dollars($limit_micro),
		);
		if ($tags) {
			$body['tags'] = $tags;
		}
		$answer = $this->call('POST', 'keys', $body);
		$hash = trim((string)($answer['data']['hash'] ?? ''));
		$key = (string)($answer['key'] ?? '');
		if ($hash === '' || $key === '') {
			throw new TrustedRouterException('TrustedRouter created a key but did not return its id and secret.');
		}
		return array('hash' => $hash, 'key' => $key);
	}

	/** Set a key's lifetime limit. Raising it never resets spend so far. */
	public function setLimit(string $hash, int $limit_micro): void {
		$this->call('PATCH', 'keys/' . rawurlencode($hash), array('limit' => self::dollars($limit_micro)));
	}

	/** Refuse (true) or allow (false) every request on a key, at once. */
	public function setDisabled(string $hash, bool $disabled): void {
		$this->call('PATCH', 'keys/' . rawurlencode($hash), array('disabled' => $disabled));
	}

	/** Delete a key. One already gone counts as deleted. */
	public function deleteKey(string $hash): void {
		try {
			$this->call('DELETE', 'keys/' . rawurlencode($hash), null);
		} catch (TrustedRouterException $e) {
			if ($e->getCode() !== 404) {
				throw $e;
			}
		}
	}

	/**
	 * Every key's lifetime usage, hash => ['usage' => micro, 'limit' => micro|null,
	 * 'disabled' => bool], read a page of up to 1,000 keys at a time. Usage is
	 * at most about five seconds old.
	 */
	public function listUsage(): array {
		$out = array();
		$offset = 0;
		for ($page = 0; $page < 1000; $page++) {
			$answer = $this->call('GET', 'keys?limit=' . self::PAGE . '&offset=' . $offset, null);
			foreach ((array)($answer['data'] ?? array()) as $row) {
				$hash = trim((string)($row['hash'] ?? ''));
				if ($hash === '') { continue; }
				$out[$hash] = array(
					'usage'    => self::micro_of($row, 'usage'),
					'limit'    => isset($row['limit_microdollars']) ? (int)$row['limit_microdollars'] : null,
					'disabled' => !empty($row['disabled']),
				);
			}
			$next = $answer['next_offset'] ?? null;
			if ($next === null || (int)$next <= $offset) {
				break;
			}
			$offset = (int)$next;
		}
		return $out;
	}

	/** Our account's spendable balance, microdollars. */
	public function remainingBalance(): int {
		$answer = $this->call('GET', 'credits/summary', null);
		return self::micro_of((array)($answer['data'] ?? array()), 'remaining');
	}

	/** A dollar figure TrustedRouter reads exactly: six places, as a string. */
	public static function dollars(int $micro): string {
		return number_format(max(0, $micro) / 1000000, 6, '.', '');
	}

	private static function micro_of(array $row, string $field): int {
		if (isset($row[$field . '_microdollars'])) {
			return (int)$row[$field . '_microdollars'];
		}
		return (int)round((float)($row[$field] ?? 0) * 1000000);
	}

	/**
	 * One call. The management key travels in the Authorization header, never
	 * in a URL or body. A non-2xx is a TrustedRouterException carrying the
	 * status as its code and TrustedRouter's own words, for the row's note.
	 */
	private function call(string $method, string $path, ?array $body): array {
		$options = array('headers' => array(
			'Authorization' => 'Bearer ' . $this->management_key,
			'Accept'        => 'application/json',
		));
		if ($body !== null) {
			$options['json'] = $body;
		}
		try {
			$response = $this->http->request($method, $path, $options);
		} catch (RequestException $e) {
			$status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
			throw new TrustedRouterException('TrustedRouter ' . $method . ' ' . strtok($path, '?')
				. ' failed (' . $status . '): ' . self::error_of($e), $status, $e);
		} catch (GuzzleException $e) {
			throw new TrustedRouterException('TrustedRouter ' . $method . ' ' . strtok($path, '?')
				. ' could not be reached: ' . $e->getMessage(), 0, $e);
		}
		$decoded = json_decode((string)$response->getBody(), true);
		return is_array($decoded) ? $decoded : array();
	}

	private static function error_of(RequestException $e): string {
		if (!$e->getResponse()) {
			return $e->getMessage();
		}
		$decoded = json_decode((string)$e->getResponse()->getBody(), true);
		$message = is_array($decoded)
			? ($decoded['error']['message'] ?? $decoded['detail'] ?? $decoded['message'] ?? '')
			: '';
		if (is_array($message)) {
			$message = json_encode($message);
		}
		$message = trim((string)$message);
		return $message !== '' ? mb_substr($message, 0, 300) : $e->getResponse()->getReasonPhrase();
	}
}
