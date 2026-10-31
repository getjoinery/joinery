<?php
/**
 * Smtp2GoClient — the operator's SMTP2GO account, acting for one customer.
 *
 * Every hosted customer gets a SUBACCOUNT, and the subaccount is the unit of
 * isolation: its own SMTP users, its own sender domains, its own usage counter
 * and its own monthly cap. One customer cannot see another's logs, send as
 * another's domain, or spend another's allowance. That is why a subaccount
 * provider was chosen over a cheaper one whose only per-customer boundary is a
 * naming convention.
 *
 * EVERY CALL HERE IS MADE WITH THE MASTER KEY, AND THE MASTER KEY NEVER LEAVES
 * THIS MACHINE. `subaccount_id` on a request is how a master account acts for
 * one of its subaccounts. What reaches a customer's box is one SMTP username
 * and password, minted inside their own subaccount, revocable on its own — a
 * credential cut to that customer's slice, which is the whole doctrine
 * (specs/hosted_trial_provisioning.md §2, §4.3).
 *
 * The cap is the provider's, not ours. SMTP2GO counts a subaccount's
 * month-to-date sends against its `limit` and refuses past it (with a 10%
 * overrun). Nothing on the platform sits in the send path, so nothing on the
 * platform can be bypassed by editing a setting on the customer's own box —
 * which they administer, and where any limit we wrote would be advisory.
 *
 * A note on what this class does NOT do: it never deletes a subaccount. Closing
 * one stops its sending and is reversible; removing its SMTP user is the softer
 * step and is the first response to a complaint threshold. Neither destroys the
 * customer's own record of what they sent.
 *
 * The domain endpoints' answers are read by Smtp2GoProvider (the core SMTP2GO
 * email provider), which owns the one description of what SMTP2GO's domain
 * responses look like. A hosted customer's sending domain and a self-hosted
 * site's are the same thing at the provider, so they must not be read by two
 * pieces of code that can drift apart.
 *
 * @version 1.9 - missingPermissions() lets a failed call's Smtp2GoException through, so the Hosted
 *   tier tab says why it could not check instead of only that it could not
 * @version 1.8 - missingPermissions(): the endpoints below that the master key may not call, read from
 *   api_keys/permissions, so the Hosted tier tab names a key too narrow before a purchase finds it (B179)
 * @version 1.7 - monthToDateSends() reads the subaccount's plan_used from subaccounts/search: the
 *   provider refuses stats/email_summary for a subaccount to a master key (403, live 2026-10-27; B162)
 * @version 1.6 - subaccountName(): the provider refuses some characters in a subaccount name (live
 *   2026-10-27) and does not say which, so the name is letters and digits joined by hyphens
 * @version 1.5 - the subaccount calls match the provider's published API, checked live 2026-10-26:
 *   subaccount/add takes fullname and limit and no email (an email invites that address to
 *   the provider's console); edit, close and reopen name the subaccount as id; a limit is
 *   one of the provider's fixed sizes (subaccountLimit)
 * @version 1.4 - removeDomain(): a services tenant that changes host releases its old sender domain
 * @version 1.3 - mintUsername() takes a prefix (test_ for a test purchase)
 * @version 1.2 - addSmtpUser() can mint the user in the provider's sandbox status (accepted, never delivered)
 * @version 1.1
 */

require_once(PathHelper::getComposerAutoloadPath());

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class Smtp2GoException extends Exception {}

class Smtp2GoClient {

	const API_BASE = 'https://api.smtp2go.com/v3/';

	/**
	 * The monthly limits the provider accepts on a subaccount, and no others
	 * (subaccount/add and subaccount/edit, "Valid values").
	 */
	const SUBACCOUNT_LIMITS = array(2000, 5000, 10000, 20000, 30000, 40000, 50000, 60000, 80000,
		100000, 250000, 500000, 1000000, 2000000, 3000000, 5000000, 10000000);

	/** @var Client */
	private $http;
	/** @var string */
	private $api_key;

	public function __construct(string $api_key, ?Client $http = null) {
		$this->api_key = $api_key;
		$this->http = $http ?: new Client([
			'base_uri'        => self::API_BASE,
			'timeout'         => 30,
			'connect_timeout' => 10,
		]);
	}

	/**
	 * The configured client, or null when no master key is set.
	 *
	 * Null rather than an exception: a deployment that sells no hosted tier has
	 * no SMTP2GO account, and the mail leg reads that as "not configured" and
	 * says so on the row instead of failing a provision.
	 */
	/**
	 * Every endpoint this class calls, with what it is for and the permission
	 * group SMTP2GO's API key screen grants it under.
	 */
	const ENDPOINTS_USED = array(
		'subaccount/add'     => array('create a customer\'s subaccount', 'Subaccounts'),
		'subaccount/edit'    => array('set a subaccount\'s monthly limit', 'Subaccounts'),
		'subaccount/close'   => array('close a subaccount on non-payment', 'Subaccounts'),
		'subaccount/reopen'  => array('reopen a subaccount', 'Subaccounts'),
		'subaccounts/search' => array('read a subaccount\'s sends this month', 'Subaccounts'),
		'users/smtp/add'     => array('create the SMTP user a customer\'s site sends with', 'SMTP Users'),
		'users/smtp/remove'  => array('remove a customer\'s SMTP user', 'SMTP Users'),
		'domain/add'         => array('register a customer\'s sending domain', 'Sender Domains'),
		'domain/view'        => array('read the domain\'s DNS records', 'Sender Domains'),
		'domain/verify'      => array('ask SMTP2GO to re-check DNS', 'Sender Domains'),
		'domain/remove'      => array('release a domain a customer moved away from', 'Sender Domains'),
	);

	/**
	 * The endpoints in ENDPOINTS_USED this key may not call, as path =>
	 * [what for, permission group]. Null when the provider listed nothing (a
	 * list it did not give is not a key that can do nothing). A failed call
	 * throws Smtp2GoException, whose message says why. api_keys/permissions
	 * answers for every key.
	 *
	 * @throws Smtp2GoException
	 */
	public function missingPermissions(): ?array {
		$data = $this->post('api_keys/permissions', array());
		$allowed = array();
		array_walk_recursive($data, function ($value) use (&$allowed) {
			if (is_string($value) && strpos($value, '/') !== false) {
				$allowed[ltrim($value, '/')] = true;
			}
		});
		if (!$allowed) {
			return null;
		}
		$missing = array();
		foreach (self::ENDPOINTS_USED as $path => $spec) {
			if (!isset($allowed[$path])) {
				$missing[$path] = $spec;
			}
		}
		return $missing;
	}

	public static function fromSettings(): ?Smtp2GoClient {
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ProvisioningSetup.php'));
		$key = trim(ProvisioningSetup::readSecret('server_manager_smtp2go_api_key'));
		return $key === '' ? null : new self($key);
	}

	// ── Subaccounts ───────────────────────────────────────────────────────────

	/**
	 * Create the customer's subaccount and return its id.
	 *
	 * The name carries the customer's domain so a person looking at the
	 * provider's console can tell whose it is without a lookup table here.
	 * No email is sent: the provider invites that address into its console as
	 * the subaccount's first team member, and a hosted customer never has an
	 * account at the provider. The limit is set here too, so the subaccount
	 * never stands at the provider's default even for a moment.
	 */
	public function addSubaccount(string $label, int $allowance): string {
		$data = $this->post('subaccount/add', array(
			'fullname' => self::subaccountName($label),
			'limit'    => self::subaccountLimit($allowance),
		));
		$id = self::firstScalar($data, array('subaccount_id', 'id'), array('subaccount', 'subaccounts'));
		if ($id === '') {
			throw new Smtp2GoException('The provider created a subaccount but did not say which one.');
		}
		return $id;
	}

	/**
	 * Set the subaccount's monthly send limit.
	 *
	 * Called at creation and again whenever the allowance setting changes, so
	 * one number in one place governs the whole hosted fleet. Convergent: it is
	 * safe to call with the value already in force.
	 */
	public function setSubaccountLimit(string $subaccount_id, int $allowance): void {
		$this->post('subaccount/edit', array(
			'id'    => $subaccount_id,
			'limit' => self::subaccountLimit($allowance),
		));
	}

	/**
	 * The name the provider is given for a label. It refuses some characters
	 * ("Please ensure the Name field only contains valid characters") without
	 * saying which, and a subaccount cannot be deleted, so names are not found
	 * out by trial: every run of anything but letters and digits becomes one
	 * hyphen ("test_Joinery hosted — a.example.com" is
	 * "test-Joinery-hosted-a-example-com").
	 */
	public static function subaccountName(string $label): string {
		$name = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $label), '-');
		return substr($name !== '' ? $name : 'Joinery', 0, 100);
	}

	/**
	 * The provider limit for an allowance: the smallest size it accepts that
	 * covers the allowance, so a customer is never capped below what they were
	 * promised. Above the largest size, the largest.
	 */
	public static function subaccountLimit(int $allowance): int {
		foreach (self::SUBACCOUNT_LIMITS as $size) {
			if ($size >= $allowance) {
				return $size;
			}
		}
		return end(self::SUBACCOUNT_LIMITS);
	}

	/**
	 * Stop a subaccount sending. Reversible — see reopenSubaccount(). The
	 * subaccount calls name it as id; subaccount_id elsewhere means acting on
	 * its behalf.
	 */
	public function closeSubaccount(string $subaccount_id): void {
		$this->post('subaccount/close', array('id' => $subaccount_id));
	}

	/** Let a closed subaccount send again. */
	public function reopenSubaccount(string $subaccount_id): void {
		$this->post('subaccount/reopen', array('id' => $subaccount_id));
	}

	// ── Sender domains ────────────────────────────────────────────────────────

	/**
	 * Register the customer's sending domain inside their subaccount and hand
	 * back the DNS records it needs.
	 *
	 * @return array{id:string, records:array} records are
	 *         [{type, name, value, priority|null, note}] in the shape
	 *         DnsRecordPlan takes, so the caller publishes them without
	 *         knowing whose records they are.
	 */
	public function addDomain(string $subaccount_id, string $domain): array {
		$data = $this->post('domain/add', array(
			'subaccount_id' => $subaccount_id,
			'domain'        => $domain,
		));
		$entry = Smtp2GoProvider::entryFor($data, $domain);
		return array(
			'id'      => trim((string)($entry['domain']['fulldomain'] ?? '')),
			'records' => Smtp2GoProvider::recordsOf($entry),
		);
	}

	/**
	 * Ask the provider to check the domain's records, and say whether it is
	 * verified now. False is a wait, not a failure: DNS takes time to be
	 * visible, and the caller comes back on a later tick.
	 */
	public function verifyDomain(string $subaccount_id, string $domain): bool {
		$data = $this->post('domain/verify', array(
			'subaccount_id' => $subaccount_id,
			'domain'        => $domain,
		));
		return Smtp2GoProvider::stateOf(Smtp2GoProvider::entryFor($data, $domain)) === 'active';
	}

	/**
	 * Take a sender domain out of the subaccount. Used when a services tenant
	 * changes host: the old sending identity is released so its DNS records
	 * stop meaning anything at the provider.
	 */
	public function removeDomain(string $subaccount_id, string $domain): void {
		$this->post('domain/remove', array(
			'subaccount_id' => $subaccount_id,
			'domain'        => $domain,
		));
	}

	/** The domain's current state and the records it still wants. */
	public function getDomain(string $subaccount_id, string $domain): array {
		$data = $this->post('domain/view', array(
			'subaccount_id' => $subaccount_id,
			'domain'        => $domain,
		));
		$entry = Smtp2GoProvider::entryFor($data, $domain);
		return array(
			'verified' => Smtp2GoProvider::stateOf($entry) === 'active',
			'records'  => Smtp2GoProvider::recordsOf($entry),
		);
	}

	// ── SMTP users ────────────────────────────────────────────────────────────

	/**
	 * Mint the one credential that reaches the customer's box.
	 *
	 * @return array{username:string, password:string, id:string}
	 */
	public function addSmtpUser(string $subaccount_id, string $username, string $password, bool $sandbox = false): array {
		$params = array(
			'subaccount_id'  => $subaccount_id,
			'username'       => $username,
			'email_password' => $password,
		);
		if ($sandbox) {
			// The provider's own sandbox: mail sent through this user is
			// accepted and counted but never delivered. A rehearsal plane
			// mints its customers' users this way so nothing it builds can
			// email anyone.
			$params['status'] = 'sandbox';
		}
		$data = $this->post('users/smtp/add', $params);
		return array(
			'username' => $username,
			'password' => $password,
			'id'       => self::firstScalar($data, array('smtp_user_id', 'id', 'username'), array('user', 'users')),
		);
	}

	/**
	 * Remove the SMTP user. The softer half of the abuse response: sending
	 * stops immediately, the subaccount and its history stay, and a new user
	 * can be minted once the cause is understood.
	 */
	public function removeSmtpUser(string $subaccount_id, string $username): void {
		$this->post('users/smtp/remove', array(
			'subaccount_id' => $subaccount_id,
			'username'      => $username,
		));
	}

	// ── Usage ─────────────────────────────────────────────────────────────────

	/**
	 * Month-to-date sends for one subaccount, from the provider's own count.
	 *
	 * The provider's figure and never the webhook tally: a webhook can be
	 * spoofed or dropped, and this number is what a banner tells a customer
	 * about the allowance they are paying for. It is plan_used on the
	 * subaccount's own listing, the count its limit is enforced against, for
	 * the current billing cycle: stats/email_summary would say the same but
	 * refuses a master key acting for a subaccount.
	 */
	public function monthToDateSends(string $subaccount_id): int {
		$data = $this->post('subaccounts/search', array('id' => $subaccount_id));
		foreach ((array)($data['subaccounts'] ?? array()) as $entry) {
			if (is_array($entry) && (string)($entry['id'] ?? '') === $subaccount_id
					&& isset($entry['plan_used']) && is_numeric($entry['plan_used'])) {
				return (int)$entry['plan_used'];
			}
		}
		throw new Smtp2GoException('The provider did not list subaccount ' . $subaccount_id . ' with a send count.');
	}

	// ── Transport ─────────────────────────────────────────────────────────────

	/**
	 * One API call. The key travels in a header rather than the body, so it
	 * never lands in a request log that records payloads.
	 *
	 * Returns the envelope's `data` object. A non-2xx, or a `data.error`, is a
	 * Smtp2GoException carrying the provider's own words — the caller records
	 * that on the row, where an operator can read it.
	 */
	private function post(string $path, array $body): array {
		try {
			$response = $this->http->request('POST', $path, array(
				'headers' => array(
					'X-Smtp2go-Api-Key' => $this->api_key,
					'Accept'            => 'application/json',
				),
				'json' => $body,
			));
		} catch (RequestException $e) {
			$status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
			throw new Smtp2GoException('SMTP2GO ' . $path . ' failed (' . $status . '): '
				. self::extractError($e), $status, $e);
		}
		$decoded = json_decode((string)$response->getBody(), true);
		$data = (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data']))
			? $decoded['data'] : (is_array($decoded) ? $decoded : array());
		if (!empty($data['error'])) {
			throw new Smtp2GoException('SMTP2GO ' . $path . ': ' . (string)$data['error'],
				(int)($data['error_code'] ?? 0));
		}
		return $data;
	}

	private static function extractError(RequestException $e): string {
		if (!$e->getResponse()) {
			return $e->getMessage();
		}
		$decoded = json_decode((string)$e->getResponse()->getBody(), true);
		foreach (array(array('data', 'error'), array('error'), array('data', 'field_validation_errors', 'message')) as $path) {
			$node = $decoded;
			foreach ($path as $key) {
				if (!is_array($node) || !isset($node[$key])) { $node = null; break; }
				$node = $node[$key];
			}
			if (is_string($node) && $node !== '') {
				return $node;
			}
		}
		return $e->getMessage();
	}

	/**
	 * The first scalar found under any of $keys, looking both at the top level
	 * and inside a single-item list under any of $containers.
	 *
	 * The provider answers some calls with the object and others with a
	 * one-element list of it. Reading both here keeps that shape out of every
	 * caller, and an unrecognised shape returns '' — which the caller reports
	 * as "the provider did not say", rather than storing a wrong id.
	 */
	private static function firstScalar(array $data, array $keys, array $containers): string {
		foreach ($keys as $key) {
			if (isset($data[$key]) && is_scalar($data[$key])) {
				return (string)$data[$key];
			}
		}
		foreach ($containers as $container) {
			$node = $data[$container] ?? null;
			if (is_array($node)) {
				$candidate = (isset($node[0]) && is_array($node[0])) ? $node[0] : $node;
				foreach ($keys as $key) {
					if (isset($candidate[$key]) && is_scalar($candidate[$key])) {
						return (string)$candidate[$key];
					}
				}
			}
		}
		return '';
	}

	/**
	 * The SMTP username minted for one customer: their slug, bounded to what
	 * the provider accepts, with a short random tail so a re-provision of the
	 * same domain never collides with a user that still exists.
	 */
	public static function mintUsername(string $slug, string $prefix = ''): string {
		$base = strtolower(preg_replace('/[^a-z0-9]/i', '', $slug));
		$base = substr($base, 0, 24);
		if ($base === '') { $base = 'site'; }
		// A prefix (test_ for a test purchase) is kept as given: the provider
		// accepts letters, digits, dashes and underscores in a username.
		return $prefix . $base . '-' . bin2hex(random_bytes(3));
	}

	/** A password for that user. Read by nobody: it is pushed, not shown. */
	public static function mintPassword(): string {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
		$out = '';
		for ($i = 0; $i < 28; $i++) {
			$out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
		}
		return $out;
	}
}
