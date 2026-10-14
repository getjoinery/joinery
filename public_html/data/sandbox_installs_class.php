<?php
/**
 * SandboxInstall and MultiSandboxInstall — what a sandboxed plugin was granted.
 *
 * One row per installed sandboxed plugin, beside its plg_plugins row:
 *
 *   - its API credential: the scoped machine key minted for exactly what its
 *     manifest declares (door 1), and that key's secret, sealed with
 *     SecretBox, because core hands it to the plugin host with every call;
 *   - what the admin consented to when it was installed or last upgraded
 *     (permissions, signals, schedules, outbound hosts), so an upgrade that
 *     asks for more stops and asks again.
 *
 * Minting and revoking are SandboxCredentials'; consent is SandboxConsent's.
 *
 * @version 1.0
 */

class SandboxInstallException extends SystemBaseException {}

class SandboxInstall extends SystemBase {
	public static $prefix = 'sbi';
	public static $tablename = 'sbi_sandbox_installs';
	public static $pkey_column = 'sbi_sandbox_install_id';

	/** The sealed-secret locator declared in settings.json. */
	const SECRET_LOCATOR = 'sbi_sandbox_installs.sbi_api_secret';

	protected static $foreign_key_actions = array(
		'sbi_apk_api_key_id' => array('action' => 'null'),
		'sbi_usr_user_id'    => array('action' => 'null'),
	);

	public static $field_specifications = array(
		'sbi_sandbox_install_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true),
		'sbi_plugin'             => array('type' => 'varchar(20)', 'is_nullable' => false, 'required' => true, 'unique' => true),
		'sbi_apk_api_key_id'     => array('type' => 'int8'),
		// A SecretBox blob (SecretBox::seal under SECRET_LOCATOR), never plain text.
		'sbi_api_secret'         => array('type' => 'text'),
		// What the admin consented to: SandboxConsent::grantsOf() of the manifest.
		'sbi_consent'            => array('type' => 'jsonb'),
		'sbi_consented_version'  => array('type' => 'varchar(20)'),
		// The admin who consented; the plugin's credential acts as them.
		'sbi_usr_user_id'        => array('type' => 'int4'),
		'sbi_consent_time'       => array('type' => 'timestamp(6)'),
		'sbi_create_time'        => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
	);

	public static $test_fixture = array(
		'values' => array('sbi_consent' => '{}'),
		'update_field' => 'sbi_consented_version',
	);

	public static function forPlugin(string $plugin): ?self {
		$rows = new MultiSandboxInstall(array('plugin' => $plugin));
		foreach ($rows as $row) {
			return $row;
		}
		return null;
	}

	/**
	 * The plugin's API credential as the host needs it, or null when it has
	 * none (not yet activated, revoked) or its secret can no longer be opened.
	 *
	 * @return array{public_key:string,secret_key:string}|null
	 */
	public static function credentialFor(string $plugin): ?array {
		$row = self::forPlugin($plugin);
		if ($row === null || !$row->get('sbi_apk_api_key_id') || !$row->get('sbi_api_secret')) {
			return null;
		}
		$key = new ApiKey((int)$row->get('sbi_apk_api_key_id'), TRUE);
		if (!$key->key || $key->get('apk_delete_time') || !$key->get('apk_is_active')) {
			return null;
		}
		try {
			$opened = (new SecretBox())->open((string)$row->get('sbi_api_secret'));
		} catch (Throwable $e) {
			return null;
		}
		if ($opened['state'] !== 'ok' || !is_string($opened['value']) || $opened['value'] === '') {
			return null;
		}
		return array('public_key' => (string)$key->get('apk_public_key'), 'secret_key' => $opened['value']);
	}

	/** @return array what was consented to, decoded */
	public function consent(): array {
		$c = $this->get_json_decoded('sbi_consent');
		return is_array($c) ? $c : array();
	}
}

class MultiSandboxInstall extends SystemMultiBase {
	protected static $model_class = 'SandboxInstall';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['plugin'])) {
			$filters['sbi_plugin'] = array($this->options['plugin'], PDO::PARAM_STR);
		}
		return $this->_get_resultsv2('sbi_sandbox_installs', $filters, $this->order_by, $only_count, $debug);
	}
}
