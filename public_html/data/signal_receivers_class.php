<?php
/**
 * SignalReceiver and MultiSignalReceiver
 *
 * Somewhere signals are delivered to outside the request that raised them.
 * Each receiver names the signals it wants (a list of names, or "*" for every
 * signal); SignalDeliveryQueue writes one delivery row per matching receiver
 * when a signal fires, and a scheduled drain sends them.
 *
 * Two kinds: `webhook`, an operator-configured HTTPS URL that receives a
 * signed POST (docs/webhooks.md), and `sandbox`, a sandboxed plugin, whose
 * signals core hands to the plugin host (sgr_plugin names it, and its signals
 * are what its admin approved; docs/sandboxed_plugins.md).
 *
 * @version 1.1 - the sandbox kind and sgr_plugin
 * @version 1.0
 */

class SignalReceiverException extends SystemBaseException {}

class SignalReceiver extends SystemBase {
	public static $prefix = 'sgr';
	public static $tablename = 'sgr_signal_receivers';
	public static $access_class = 'config';
	public static $pkey_column = 'sgr_signal_receiver_id';

	const KIND_WEBHOOK = 'webhook';
	const KIND_SANDBOX = 'sandbox';

	/** The value of sgr_signals that means "every signal". */
	const ALL_SIGNALS = '*';

	/** The sealed-secret locator declared in settings.json. */
	const SECRET_LOCATOR = 'sgr_signal_receivers.sgr_signing_secret';

	/** Standard Webhooks secrets are this prefix plus base64 of random bytes. */
	const SECRET_PREFIX = 'whsec_';

	protected static $foreign_key_actions = array();

	public static $field_specifications = array(
		'sgr_signal_receiver_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true),
		'sgr_kind'               => array('type' => 'varchar(20)', 'is_nullable' => false, 'required' => true,
			'default' => self::KIND_WEBHOOK, 'allowed_values' => array(self::KIND_WEBHOOK, self::KIND_SANDBOX)),
		'sgr_name'               => array('type' => 'varchar(255)', 'is_nullable' => false, 'required' => true),
		'sgr_url'                => array('type' => 'varchar(2000)'),
		// The sandboxed plugin a `sandbox` receiver delivers to.
		'sgr_plugin'             => array('type' => 'varchar(20)', 'index' => true),
		// A SecretBox blob (SecretBox::seal under SECRET_LOCATOR), never plain text.
		'sgr_signing_secret'     => array('type' => 'text'),
		// JSON array of signal names, or ["*"].
		'sgr_signals'            => array('type' => 'jsonb', 'is_nullable' => false, 'required' => true),
		'sgr_is_active'          => array('type' => 'bool', 'is_nullable' => false, 'default' => true),
		'sgr_create_time'        => array('type' => 'timestamp(6)', 'default' => 'now()'),
	);

	public static $test_fixture = array(
		'values' => array('sgr_kind' => self::KIND_WEBHOOK, 'sgr_signals' => '["*"]', 'sgr_url' => 'https://example.com/hook'),
		'update_field' => 'sgr_name',
	);

	/** @return string[] the signal names this receiver wants; ['*'] for all */
	public function signals(): array {
		$value = $this->get_json_decoded('sgr_signals');
		if (!is_array($value)) {
			return array();
		}
		return array_values(array_filter($value, 'is_string'));
	}

	public function wants_all_signals(): bool {
		return in_array(self::ALL_SIGNALS, $this->signals(), true);
	}

	public function wants(string $signal): bool {
		$signals = $this->signals();
		return in_array(self::ALL_SIGNALS, $signals, true) || in_array($signal, $signals, true);
	}

	/**
	 * Mint a new signing secret, seal it into the row (unsaved) and return the
	 * plain value so the caller can show it once. The format is the one the
	 * Standard Webhooks libraries read: "whsec_" + base64 of the key bytes.
	 */
	public function new_signing_secret(): string {
		$secret = self::SECRET_PREFIX . base64_encode(random_bytes(32));
		$box = new SecretBox();
		$this->set('sgr_signing_secret', $box->seal(self::SECRET_LOCATOR, $secret));
		return $secret;
	}

	/**
	 * The signing secret, opened. Null when none is stored or it can no longer
	 * be opened (the database moved to a site with another secret_box_key):
	 * the caller must not send unsigned, and the admin page offers a new one.
	 */
	public function signing_secret(): ?string {
		$stored = $this->get('sgr_signing_secret');
		if ($stored === null || $stored === '') {
			return null;
		}
		try {
			$box = new SecretBox();
		} catch (Exception $e) {
			return null;
		}
		$opened = $box->open($stored);
		if ($opened['state'] !== 'ok' || !is_string($opened['value']) || $opened['value'] === '') {
			return null;
		}
		return $opened['value'];
	}

	/**
	 * Why a webhook may not be sent to this URL, or null when it may. The body
	 * carries member data, so it goes over HTTPS only. Where the host points
	 * (no internal addresses) is checked when the message is sent, by
	 * SafeHttpClient, since DNS can change after the URL is saved.
	 */
	public static function url_problem(string $url): ?string {
		$url = trim($url);
		if ($url === '') {
			return 'A webhook needs a URL.';
		}
		$parts = parse_url($url);
		if ($parts === false || empty($parts['host'])) {
			return 'That is not a complete URL.';
		}
		if (strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
			return 'A webhook URL must start with https://.';
		}
		if (isset($parts['user']) || isset($parts['pass'])) {
			return 'Put credentials in the receiving app, not in the URL.';
		}
		return null;
	}

	/** Refuses, with a message an admin can act on, a receiver that cannot be sent to. */
	function prepare() {
		parent::prepare();
		if (trim((string)$this->get('sgr_name')) === '') {
			throw new DisplayableUserException('Give it a name.');
		}
		// save() applies the column default after this runs, so an unset kind
		// is already the default kind here.
		if (($this->get('sgr_kind') ?? self::KIND_WEBHOOK) === self::KIND_WEBHOOK) {
			$problem = self::url_problem((string)$this->get('sgr_url'));
			if ($problem !== null) {
				throw new DisplayableUserException($problem);
			}
		}
		if ($this->get('sgr_kind') === self::KIND_SANDBOX
				&& !preg_match(SandboxManifest::NAME_PATTERN, (string)$this->get('sgr_plugin'))) {
			throw new DisplayableUserException('A sandboxed plugin receiver must name its plugin.');
		}
		if (empty($this->signals())) {
			throw new DisplayableUserException('Choose at least one signal.');
		}
	}

	function display_title() {
		return (string)$this->get('sgr_name');
	}
}

class MultiSignalReceiver extends SystemMultiBase {
	protected static $model_class = 'SignalReceiver';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['kind'])) {
			$filters['sgr_kind'] = array($this->options['kind'], PDO::PARAM_STR);
		}

		if (isset($this->options['active'])) {
			$filters['sgr_is_active'] = $this->options['active'] ? '= TRUE' : '= FALSE';
		}

		if (isset($this->options['plugin'])) {
			$filters['sgr_plugin'] = array($this->options['plugin'], PDO::PARAM_STR);
		}

		return $this->_get_resultsv2('sgr_signal_receivers', $filters, $this->order_by, $only_count, $debug);
	}
}
