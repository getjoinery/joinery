<?php
/**
 * RelayCloudProvision - one act against the customer's own cloud account,
 * carried out by THIS deployment (specs/mailbox_relay_cloud_provisioning.md).
 *
 * A run creates an instance on the customer's account and turns it into
 * this deployment's relay (registering the MailboxRelay row, disabled, on
 * success). The platform never deletes a customer's running server — removal
 * happens at the provider, by the customer; the only instance deletion is the
 * cleanup of a failed run's own half-built instance.
 *
 * Two kinds, sharing one engine and one credential ceremony:
 *   provision - create a new instance and turn it into this deployment's relay
 *   upgrade   - replace an EXISTING relay's contents in place, because a relay
 *               cannot be logged in to and so cannot be patched
 *               (specs/mailbox_relay_upgrade_without_server_manager.md)
 *
 * Status flow:
 *   awaiting_grant - run created from the Setup tab form; waiting for the
 *                    just-in-time credential step (a short-lived provider
 *                    token the customer mints for this one act)
 *   ready          - credential received (token sealed on the row); instance
 *                    not yet created
 *   draining       - UPGRADE ONLY: pulling the relay's spool empty before the
 *                    wipe, because the wipe destroys whatever is left on it
 *   rebuilding     - UPGRADE ONLY: the provider is replacing every disk; the
 *                    instance and its public IPv4 survive, so the MX record does
 *   booting        - instance created (or rebuilt); waiting for running + public IP
 *   provisioning   - SSH provisioning underway (the long step)
 *   done | failed  - terminal. BOTH erase the sealed token and SSH key
 *                    (grant-per-act custody); a failed PROVISION destroys the
 *                    instance it created within the same grant. A failed UPGRADE
 *                    never destroys anything: the instance is the customer's
 *                    existing relay, not this run's to throw away.

 * The sealed columns hold the in-flight credentials ONLY while a run is live:
 * the provider access token and the per-run root SSH private key, both
 * SecretBox-sealed and erased at terminal state.
 *
 * @version 1.3 - the 'upgrade' kind, with the relay it targets
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/SecretBox.php'));

class RelayCloudProvisionException extends SystemBaseException {}

class RelayCloudProvision extends SystemBase {
	public static $prefix = 'rcp';
	public static $tablename = 'rcp_relay_cloud_provisions';
	public static $pkey_column = 'rcp_id';

	protected static $foreign_key_actions = array(
		'rcp_mrl_mailbox_relay_id' => array('action' => 'null'),
		'rcp_mfs_shard_id' => array('action' => 'null'),
	);

	public static $field_specifications = array(
		'rcp_id'              => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'rcp_kind'            => array('type'=>'varchar(10)', 'is_nullable'=>false, 'default'=>'provision', 'allowed_values'=>array('provision', 'upgrade')),
		'rcp_status'          => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>'awaiting_grant'),
		'rcp_provider'        => array('type'=>'varchar(32)', 'is_nullable'=>false, 'default'=>'linode'),
		'rcp_mail_hostname'   => array('type'=>'varchar(255)'),
		'rcp_region'          => array('type'=>'varchar(50)'),
		'rcp_instance_type'   => array('type'=>'varchar(50)'),
		// Upgrade runs only: the relay being replaced. A provision run has no
		// relay yet — it creates one — so this stays null there.
		'rcp_mrl_mailbox_relay_id' => array('type'=>'int8'),
		'rcp_instance_id'     => array('type'=>'varchar(50)'),
		'rcp_instance_ip'     => array('type'=>'varchar(64)'),
		'rcp_sealed_token'    => array('type'=>'text'),
		// A relay born from user-data (specs/relay_without_a_shell.md): the
		// one-time run token the first-boot script presents to fetch the bundle
		// and post the birth report, sealed like the provider token and erased
		// with it; when it stops being valid; whether the birth report spent it;
		// and the sha256 of the run's own copy of the support bundle.
		'rcp_sealed_run_token' => array('type'=>'text'),
		'rcp_run_token_expires'=> array('type'=>'timestamp(6)'),
		'rcp_run_token_spent'  => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		'rcp_bundle_sha256'    => array('type'=>'varchar(64)'),
		// A fleet SHARD is born the same way, skeleton only: no tenant main, the
		// operator's public key in its registry, and its birth lands on the
		// MailboxFleetShard row this names instead of a MailboxRelay row.
		'rcp_mfs_shard_id'     => array('type'=>'int8'),
		'rcp_error'           => array('type'=>'text'),
		'rcp_create_time'     => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'rcp_update_time'     => array('type'=>'timestamp(6)'),
		'rcp_delete_time'     => array('type'=>'timestamp(6)'),
	);

	function prepare() {
		$this->set('rcp_update_time', gmdate('Y-m-d H:i:s'));
	}

	// Mandatory stamping lives in save(): prepare() is not guaranteed to run first.
	function save($debug = false) {
		$this->set('rcp_update_time', gmdate('Y-m-d H:i:s'));
		return parent::save($debug);
	}

	/** Seal the provider access token onto the row (not saved here). */
	public function sealToken(string $access_token): void {
		$box = new SecretBox();
		$this->set('rcp_sealed_token', $box->seal('rcp_relay_cloud_provisions.rcp_sealed_token', $access_token));
	}

	/** @return string '' when no token is held (or it is unreadable here). */
	public function unsealToken(): string {
		$stored = (string)$this->get('rcp_sealed_token');
		if ($stored === '') {
			return '';
		}
		return (string)((new SecretBox())->open($stored)['value'] ?? '');
	}

	/**
	 * Mint the one-time run token: 32 random bytes, hex. Sealed on the row;
	 * returned once so the caller can put it in the user-data. Live until
	 * $expires (the boot timeout), or until the birth report spends it.
	 */
	public function issueRunToken(int $ttl_seconds): string {
		$token = bin2hex(random_bytes(32));
		require_once(PathHelper::getIncludePath('includes/SecretBox.php'));
		$this->set('rcp_sealed_run_token',
			(new SecretBox())->seal('rcp_relay_cloud_provisions.rcp_sealed_run_token', $token));
		$this->set('rcp_run_token_expires', gmdate('Y-m-d H:i:s', time() + max(60, $ttl_seconds)));
		$this->set('rcp_run_token_spent', false);
		return $token;
	}

	/**
	 * Is $presented this run's live, unspent token? Constant-time compare; a
	 * run that is done or failed, or whose token expired, never matches.
	 */
	public function runTokenMatches(string $presented): bool {
		$presented = trim($presented);
		if ($presented === '' || !$this->isLive() || (bool)$this->get('rcp_run_token_spent')) {
			return false;
		}
		$expires = (string)$this->get('rcp_run_token_expires');
		if ($expires === '' || strtotime($expires . ' UTC') < time()) {
			return false;
		}
		$sealed = (string)$this->get('rcp_sealed_run_token');
		if ($sealed === '') {
			return false;
		}
		require_once(PathHelper::getIncludePath('includes/SecretBox.php'));
		$opened = (new SecretBox())->open($sealed);
		if ($opened['value'] === null) {
			return false;
		}
		return hash_equals((string)$opened['value'], $presented);
	}

	/** The birth report spends the token: nothing presents it twice. */
	public function spendRunToken(): void {
		$this->set('rcp_run_token_spent', true);
	}

	/**
	 * Where this run's own copy of the support bundle lives. A publish keeps
	 * only one bundle on a deployment, so the run copies the bytes it was
	 * created against and serves THAT copy, whatever lands in agent_dist later.
	 */
	public function bundlePath(): string {
		return PathHelper::getSiteRoot() . '/relay_runs/' . intval($this->key) . '/support_bundle.tar.gz';
	}

	/**
	 * Copy the deployment's support bundle onto the run and record its sha256.
	 * Returns the hash. Throws when there is no bundle to copy: a run with no
	 * bundle would be a relay that fails half an hour later on a box nobody can
	 * log into.
	 */
	public function copyBundle(): string {
		$source = PathHelper::getIncludePath('agent_dist/support_bundle.tar.gz');
		if (!is_file($source)) {
			throw new RuntimeException('This deployment carries no support bundle (agent_dist/support_bundle.tar.gz); '
				. 'a relay is born from it, so none can be created until a release that ships one is installed.');
		}
		$dest = $this->bundlePath();
		if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0750, true)) {
			throw new RuntimeException('Cannot create the run directory ' . dirname($dest));
		}
		if (!copy($source, $dest)) {
			throw new RuntimeException('Cannot copy the support bundle onto run ' . $this->key);
		}
		$sha = hash_file('sha256', $dest);
		$this->set('rcp_bundle_sha256', $sha);
		return $sha;
	}

	/** Remove the run's bundle copy and its directory (a terminal state). */
	public function eraseBundle(): void {
		$dest = $this->bundlePath();
		@unlink($dest);
		@rmdir(dirname($dest));
	}

	public function eraseCredentials(): void {
		$this->set('rcp_sealed_token', null);
		$this->set('rcp_sealed_run_token', null);
		$this->eraseBundle();
		$this->save();
	}

	/** Record a terminal failure and erase credentials. Saves. */
	public function fail(string $message): void {
		$this->set('rcp_status', 'failed');
		$this->set('rcp_error', mb_substr($message, 0, 4000));
		$this->eraseCredentials();
	}

	/** True while the run is neither done nor failed. */
	public function isLive(): bool {
		return !in_array((string)$this->get('rcp_status'), array('done', 'failed'), true);
	}

	/** True when this run replaces an existing relay rather than creating one. */
	/** Is this run for a fleet shard (skeleton only) rather than this deployment's relay? */
	public function isShard(): bool {
		return intval($this->get('rcp_mfs_shard_id')) > 0;
	}

	public function isUpgrade(): bool {
		return (string)$this->get('rcp_kind') === 'upgrade';
	}

	/**
	 * The relay an upgrade run targets, or null (including for provision runs,
	 * which have no relay until they finish making one).
	 */
	public function relay(): ?MailboxRelay {
		$id = intval($this->get('rcp_mrl_mailbox_relay_id'));
		if ($id <= 0) {
			return null;
		}
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));
		try {
			$relay = new MailboxRelay($id, true);
		} catch (\Throwable $e) {
			return null;
		}
		return ($relay->key) ? $relay : null;
	}

	/** The deployment's single live run, or null (one act at a time). */
	public static function live(): ?RelayCloudProvision {
		$multi = new MultiRelayCloudProvision(array('live' => true, 'deleted' => false),
			array('rcp_id' => 'DESC'), 1);
		$multi->load();
		foreach ($multi as $run) {
			return $run;
		}
		return null;
	}

	/** The most recent run of any state, or null (the section shows its outcome). */
	public static function latest(): ?RelayCloudProvision {
		$multi = new MultiRelayCloudProvision(array('deleted' => false), array('rcp_id' => 'DESC'), 1);
		$multi->load();
		foreach ($multi as $run) {
			return $run;
		}
		return null;
	}
}

class MultiRelayCloudProvision extends SystemMultiBase {
	protected static $model_class = 'RelayCloudProvision';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['status'])) {
			$filters['rcp_status'] = [$this->options['status'], PDO::PARAM_STR];
		}

		if (isset($this->options['live'])) {
			$filters['rcp_status'] = $this->options['live']
				? "NOT IN ('done', 'failed')" : "IN ('done', 'failed')";
		}

		if (isset($this->options['kind'])) {
			$filters['rcp_kind'] = [$this->options['kind'], PDO::PARAM_STR];
		}


		return $this->_get_resultsv2('rcp_relay_cloud_provisions', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
