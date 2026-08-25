<?php
/**
 * ProvisioningSetup - One-click activation engine for the hosting
 * provisioning pipeline.
 *
 * Everything the activation checklist can do on the control plane itself is
 * done here programmatically: mint the store API service user + key, create
 * the domain Question, write the pipeline settings, and activate the
 * scheduled tasks. The admin page (views/admin/provisioning_setup.php) is a
 * thin status + button surface over these methods.
 *
 * Self-store detection: when the store being polled is this same site (the
 * API URL is our own origin), the service user and key are created locally
 * and the plaintext secret never leaves the server. A remote store cannot be
 * set up from here — the key must be minted on the store site and its values
 * entered in the settings fields.
 *
 * @version 1.4 - the domain-registration leg's status and sealed credentials
 * @version 1.3 - readSecret()/writeSecret() generalize the sealed-setting path
 * @version 1.2
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/settings_class.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/api_keys_class.php'));
require_once(PathHelper::getIncludePath('data/questions_class.php'));
require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_host_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/GetJoineryApiClient.php'));

class ProvisioningSetup {

	const SERVICE_USER_LOCAL_PART = 'provisioning';
	const SERVICE_KEY_NAME = 'Provisioning pipeline';
	/**
	 * The pipeline reads other buyers' order items/requirements and posts
	 * queued emails; the API's model authorization grants cross-user read
	 * only at permission >= 5, so the service user must be an admin-level
	 * account. The API key's own apk_permission axis (CRUD capability) is
	 * kept at 3: read + write, no delete.
	 */
	const SERVICE_USER_PERMISSION = 5;
	const SERVICE_KEY_PERMISSION = 3;
	const DOMAIN_QUESTION_TEXT = 'What domain would you like to use for your site?';

	/**
	 * Task class => display name, all default_frequency every_run.
	 *
	 * Order polling, customer cloud, SSL and the managed-domain leg are PHASES
	 * of one umbrella task, not tasks of their own — they have no task files,
	 * so naming them here would mint sct_ rows pointing at classes the runner
	 * cannot load, and the activate button would seed a broken schedule.
	 */
	const TASK_CLASSES = array(
		'ServerManagerAdvanceProvisioning' => 'Advance Provisioning',
		// Core task, but a hard pipeline requirement: the buyer's welcome
		// email is queued into equ_queued_emails on the store site and only
		// this task drains that queue. (Remote-store case: activate it on the
		// store site.)
		'SendQueuedEmails'                 => 'Send Queued Emails',
	);

	/** TASK_CLASSES entries owned by core — no sct_plugin_name stamp. */
	const CORE_TASK_CLASSES = array('SendQueuedEmails');

	/** This site's own origin, the self-store API URL. */
	public static function selfApiUrl(): string {
		return rtrim(LibraryFunctions::get_absolute_url('/'), '/');
	}

	/** provisioning@<this-host> — the service user's identity. */
	public static function serviceUserEmail(): string {
		$host = parse_url(self::selfApiUrl(), PHP_URL_HOST) ?: 'localhost';
		return self::SERVICE_USER_LOCAL_PART . '@' . $host;
	}

	/**
	 * Read a setting straight from the table (not the request-cached
	 * singleton), so a value written earlier in this request is visible.
	 */
	public static function readSetting(string $name): string {
		$rows = new MultiSetting(array('setting_name' => $name));
		$rows->load();
		foreach ($rows as $row) {
			return (string)$row->get('stg_value');
		}
		return '';
	}

	/** Write (or create) a setting row. */
	public static function writeSetting(string $name, string $value): void {
		$rows = new MultiSetting(array('setting_name' => $name));
		$rows->load();
		foreach ($rows as $row) {
			$row->set('stg_value', $value);
			$row->save();
			return;
		}
		$setting = new Setting(NULL);
		$setting->set('stg_name', $name);
		$setting->set('stg_value', $value);
		$setting->save();
	}

	/**
	 * The pipeline API secret, decrypted for use. It is stored SecretBox-encrypted
	 * at rest; a legacy plaintext value (written before encryption existed, or on a
	 * site without a secret_box_key) is returned as-is, so callers migrate lazily.
	 * This is the one accessor every pipeline consumer reads the secret through.
	 */
	public static function readApiSecret(): string {
		$settings = Globalvars::get_instance();
		return self::decryptSecret((string)$settings->get_setting('server_manager_getjoinery_api_secret_key'));
	}

	/**
	 * Read any SecretBox-sealed setting, decrypted for use.
	 *
	 * The general form of readApiSecret(): a value written by writeSecret()
	 * comes back plaintext, and a legacy or zero-config plaintext value passes
	 * through untouched, so a deployment with no secret_box_key still works.
	 */
	public static function readSecret(string $name): string {
		$settings = Globalvars::get_instance();
		return self::decryptSecret((string)$settings->get_setting($name, false, true));
	}

	/** Write a setting sealed at rest wherever a secret_box_key exists. */
	public static function writeSecret(string $name, string $plaintext): void {
		self::writeSetting($name, $plaintext === '' ? '' : self::encryptSecret($plaintext));
	}

	/**
	 * Encrypt a secret for storage. Returns a SecretBox blob when a key is
	 * available; falls back to the plaintext untouched when SecretBox cannot be
	 * constructed (no configured key), keeping the zero-config install path alive.
	 */
	private static function encryptSecret(string $plaintext): string {
		require_once(PathHelper::getIncludePath('includes/SecretBox.php'));
		try {
			return (new SecretBox())->encrypt($plaintext);
		} catch (\Throwable $e) {
			return $plaintext;
		}
	}

	/** Decrypt a stored secret; a non-encrypted (legacy) value passes through. */
	private static function decryptSecret(string $stored): string {
		if ($stored === '') {
			return '';
		}
		require_once(PathHelper::getIncludePath('includes/SecretBox.php'));
		if (!SecretBox::looksEncrypted($stored)) {
			return $stored;
		}
		try {
			return (new SecretBox())->decrypt($stored);
		} catch (\Throwable $e) {
			return '';
		}
	}

	/**
	 * Mint the store API credential set for the self-store case: service
	 * user (permission 3), machine API key, and the three settings the
	 * pipeline reads. Idempotent — a configured credential set is left
	 * alone unless $rotate, which mints a fresh key (deactivating this
	 * user's previous pipeline keys) and updates the settings.
	 *
	 * @return array{ok:bool, message:string, user_id?:int, api_key_id?:int,
	 *               user_created?:bool, key_created?:bool}
	 */
	public static function setupApiCredentials(bool $rotate = false): array {
		$configured = self::readSetting('server_manager_getjoinery_api_url') !== ''
			&& self::readSetting('server_manager_getjoinery_api_public_key') !== ''
			&& self::readSetting('server_manager_getjoinery_api_secret_key') !== '';
		if ($configured && !$rotate) {
			return array('ok' => true, 'message' => 'API credentials already configured.');
		}

		$email = self::serviceUserEmail();
		$user = User::GetByEmail($email);
		$user_created = false;
		if ($user === NULL) {
			$user = new User(NULL);
			$user->set('usr_first_name', 'Provisioning');
			$user->set('usr_last_name', 'Service');
			$user->set('usr_email', $email);
			$user->set('usr_password', User::GeneratePassword(bin2hex(random_bytes(24))));
			$user->set('usr_permission', self::SERVICE_USER_PERMISSION);
			$user->set('usr_email_is_verified', TRUE);
			// Machine account: never allow password recovery into an
			// admin-level user whose mailbox nobody reads.
			$user->set('usr_password_recovery_disabled', TRUE);
			$user->save();
			$user->load();
			$user_created = true;
		}

		// Retire this user's previous pipeline keys before minting a new one.
		$old_keys = new MultiApiKey(array('user_id' => $user->key));
		$old_keys->load();
		foreach ($old_keys as $old) {
			if ($old->get('apk_name') === self::SERVICE_KEY_NAME && $old->get('apk_is_active')) {
				$old->set('apk_is_active', FALSE);
				$old->save();
			}
		}

		$public_key = 'public_' . LibraryFunctions::random_string(16);
		$secret_plaintext = 'secret_' . LibraryFunctions::random_string(16);

		$api_key = new ApiKey(NULL);
		$api_key->set('apk_usr_user_id', $user->key);
		$api_key->set('apk_name', self::SERVICE_KEY_NAME);
		$api_key->set('apk_public_key', $public_key);
		$api_key->set('apk_secret_key', ApiKey::GenerateKey($secret_plaintext));
		$api_key->set('apk_permission', self::SERVICE_KEY_PERMISSION);
		$api_key->set('apk_is_active', TRUE);
		$api_key->save();
		$api_key->load();

		self::writeSetting('server_manager_getjoinery_api_url', self::selfApiUrl());
		self::writeSetting('server_manager_getjoinery_api_public_key', $public_key);
		self::writeSetting('server_manager_getjoinery_api_secret_key', self::encryptSecret($secret_plaintext));

		return array(
			'ok' => true,
			'message' => ($user_created ? 'Service user ' . $email . ' created; ' : '')
				. 'API key minted and settings written.',
			'user_id' => (int)$user->key,
			'api_key_id' => (int)$api_key->key,
			'user_created' => $user_created,
			'key_created' => true,
		);
	}

	/**
	 * Loopback probe: call the store API with the stored credentials the
	 * same way Poll Hosting Orders does. True when the API answers with a
	 * well-formed envelope (an empty result set counts as success).
	 */
	public static function probeApi(): bool {
		$url = self::readSetting('server_manager_getjoinery_api_url');
		$pub = self::readSetting('server_manager_getjoinery_api_public_key');
		$sec = self::readApiSecret();
		if ($url === '' || $pub === '' || $sec === '') {
			return false;
		}
		$client = new GetJoineryApiClient($url, $pub, $sec);
		$result = $client->get('OrderItemRequirements', array('limit' => 1));
		return is_array($result);
	}

	/** Load an undeleted Question by id, or null when missing/deleted. */
	private static function loadQuestion(int $qid): ?Question {
		$question = new Question($qid);
		if ($question->load() === false || $question->get('qst_delete_time')) {
			return null;
		}
		return $question;
	}

	/**
	 * Ensure the domain Question exists and its ID is in settings.
	 * Idempotent — an existing, undeleted configured question is kept.
	 *
	 * @return array{ok:bool, message:string, question_id:int, created:bool}
	 */
	public static function ensureDomainQuestion(): array {
		$qid = (int)self::readSetting('server_manager_provisioning_domain_question_id');
		if ($qid > 0 && self::loadQuestion($qid) !== null) {
			return array('ok' => true, 'message' => 'Domain question already configured.',
				'question_id' => $qid, 'created' => false);
		}

		$question = new Question(NULL);
		$question->set('qst_question', self::DOMAIN_QUESTION_TEXT);
		$question->set('qst_type', Question::TYPE_SHORT_TEXT);
		$question->set('qst_is_required', TRUE);
		$question->set('qst_is_published', TRUE);
		$question->save();
		$question->load();

		self::writeSetting('server_manager_provisioning_domain_question_id', (string)$question->key);

		return array('ok' => true,
			'message' => 'Domain question created (ID ' . $question->key . ') and setting written.',
			'question_id' => (int)$question->key, 'created' => true);
	}

	/**
	 * Activate the three pipeline scheduled tasks (create missing rows,
	 * resume paused ones). Idempotent.
	 *
	 * @return array{ok:bool, message:string, results:array<string,string>}
	 */
	public static function activateTasks(): array {
		$results = array();
		foreach (self::TASK_CLASSES as $class => $name) {
			$existing = new MultiScheduledTask(array('task_class' => $class, 'deleted' => false));
			$existing->load();
			$task = null;
			foreach ($existing as $row) {
				$task = $row;
				break;
			}
			if ($task === null) {
				$task = new ScheduledTask(NULL);
				$task->set('sct_name', $name);
				$task->set('sct_task_class', $class);
				if (!in_array($class, self::CORE_TASK_CLASSES, true)) {
					$task->set('sct_plugin_name', 'server_manager');
				}
				$task->set('sct_frequency', 'every_run');
				$task->set('sct_is_active', TRUE);
				$task->save();
				$results[$class] = 'activated';
			} elseif (!$task->get('sct_is_active')) {
				$task->set('sct_is_active', TRUE);
				$task->save();
				$results[$class] = 'resumed';
			} else {
				$results[$class] = 'already active';
			}
		}
		return array('ok' => true,
			'message' => 'Scheduled tasks: ' . implode(', ',
				array_map(fn($c, $r) => self::TASK_CLASSES[$c] . ' ' . $r,
					array_keys($results), $results)) . '.',
			'results' => $results);
	}

	/** Default location for the customer-cloud provisioning key. */
	public static function defaultSshKeyPath(): string {
		return PathHelper::getSiteRoot() . '/config/provisioning_key';
	}

	/**
	 * Ensure the customer-cloud provisioning SSH keypair exists and the
	 * path setting points at it. The public half is installed on created
	 * instances; the private half is the control plane's only access to
	 * them (root passwords are random and never stored).
	 *
	 * Idempotent and never destructive: an existing key file is kept, a
	 * missing .pub is re-derived from the private key, and the setting is
	 * only written when blank (a custom path stays untouched). $path
	 * overrides the target location (tests); by default the configured
	 * path or the site-root config/ default is used.
	 *
	 * @return array{ok:bool, message:string, generated:bool, path:string}
	 */
	public static function ensureSshKey(?string $path = null): array {
		$setting = self::readSetting('server_manager_customer_cloud_ssh_key_path');
		if ($path === null) {
			$path = $setting !== '' ? $setting : self::defaultSshKeyPath();
		}

		$generated = false;
		if (!file_exists($path)) {
			$dir = dirname($path);
			if (!is_dir($dir) || !is_writable($dir)) {
				return array('ok' => false, 'generated' => false, 'path' => $path,
					'message' => 'Cannot generate key: directory ' . $dir . ' is missing or not writable.');
			}
			$output = array();
			$code = 0;
			exec('ssh-keygen -t ed25519 -N ' . escapeshellarg('')
				. ' -C ' . escapeshellarg('joinery-provisioning')
				. ' -f ' . escapeshellarg($path) . ' -q 2>&1', $output, $code);
			if ($code !== 0 || !file_exists($path)) {
				return array('ok' => false, 'generated' => false, 'path' => $path,
					'message' => 'ssh-keygen failed: ' . implode(' ', $output));
			}
			$generated = true;
		}

		if (!file_exists($path . '.pub')) {
			exec('ssh-keygen -y -f ' . escapeshellarg($path)
				. ' > ' . escapeshellarg($path . '.pub') . ' 2>/dev/null', $output, $code);
			if (!file_exists($path . '.pub')) {
				return array('ok' => false, 'generated' => $generated, 'path' => $path,
					'message' => 'Key exists but its .pub could not be derived — check the key file.');
			}
		}

		if ($setting === '') {
			self::writeSetting('server_manager_customer_cloud_ssh_key_path', $path);
		}

		return array('ok' => true, 'generated' => $generated, 'path' => $path,
			'message' => $generated
				? 'Provisioning SSH keypair generated at ' . $path . '.'
				: 'Provisioning SSH keypair already present at ' . $path . '.');
	}

	/**
	 * Products the domain question is attached to (checkout requirement) —
	 * the marker that makes a product a hosting product. Returns
	 * [product_id => product_name].
	 */
	public static function attachedProducts(int $question_id): array {
		if ($question_id <= 0) {
			return array();
		}
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			'SELECT p.pro_product_id, p.pro_name
			 FROM prq_product_requirements r
			 JOIN pri_product_requirement_instances i
			   ON i.pri_prq_product_requirement_id = r.prq_product_requirement_id
			  AND i.pri_delete_time IS NULL
			 JOIN pro_products p ON p.pro_product_id = i.pri_pro_product_id
			  AND p.pro_delete_time IS NULL
			 WHERE r.prq_qst_question_id = ? AND r.prq_delete_time IS NULL
			 ORDER BY p.pro_name');
		$stmt->execute(array($question_id));
		$out = array();
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$out[(int)$row['pro_product_id']] = (string)$row['pro_name'];
		}
		return $out;
	}

	/**
	 * The domain-registration leg's configuration, for the setup page.
	 *
	 * The API key is reported as present-or-absent and never returned: this
	 * array reaches a template, and a credential that reaches a template ends
	 * up in a page source sooner or later.
	 */
	public static function domainStatus(): array {
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/domain_registrar/DomainRegistrarRegistry.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/requirements/ManagedDomainRequirement.php'));
		$registrar = DomainRegistrarRegistry::firstConfigured();
		$product_id = (int)self::readSetting('store_domain_registration_product_id');
		// Sellable means the product LOADS and can price a line, not merely
		// that the setting holds a number — a deleted product still would.
		$product_ok = ManagedDomainRequirement::domainProductSellable();
		return array(
			'api_user'    => self::readSetting('server_manager_namecheap_api_user'),
			'key_present' => trim(self::readSecret('server_manager_namecheap_api_key')) !== '',
			'client_ip'   => self::readSetting('server_manager_namecheap_client_ip'),
			'sandbox'     => self::readSetting('server_manager_namecheap_sandbox') !== '',
			'tlds_raw'    => implode(' ', DomainRegistrarRegistry::offeredTlds()),
			'configured'  => $registrar !== null,
			'label'       => $registrar ? $registrar::getLabel() : '',
			'product_id'  => $product_id,
			'product_ok'  => $product_ok,
			'sellable'    => $registrar !== null && $product_ok,
		);
	}

	/**
	 * Full pipeline status for the setup page: each checklist item with
	 * enough detail to render a state badge and decide which action to
	 * offer. Read-only; the probe only runs when credentials are present.
	 */
	public static function status(): array {
		$api_url = self::readSetting('server_manager_getjoinery_api_url');
		$api_pub = self::readSetting('server_manager_getjoinery_api_public_key');
		$api_sec = self::readSetting('server_manager_getjoinery_api_secret_key');
		$api_configured = $api_url !== '' && $api_pub !== '' && $api_sec !== '';

		$qid = (int)self::readSetting('server_manager_provisioning_domain_question_id');
		$question = $qid > 0 ? self::loadQuestion($qid) : null;
		$question_exists = $question !== null;
		$question_text = $question_exists ? (string)$question->get('qst_question') : '';

		$tasks = array();
		foreach (self::TASK_CLASSES as $class => $name) {
			$existing = new MultiScheduledTask(array('task_class' => $class, 'deleted' => false));
			$existing->load();
			$state = 'missing';
			foreach ($existing as $row) {
				$state = $row->get('sct_is_active') ? 'active' : 'paused';
				break;
			}
			$tasks[$class] = array('name' => $name, 'state' => $state);
		}

		$provisioning_hosts = new MultiManagedHost(array(
			'provisioning_enabled' => true, 'deleted' => false));

		require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ProviderRegistry.php'));
		$linode = OAuth2ProviderRegistry::get('linode');
		$oauth_configured = $linode !== null && $linode::isConfigured();

		$ssh_key_path = self::readSetting('server_manager_customer_cloud_ssh_key_path');

		return array(
			'api' => array(
				'configured' => $api_configured,
				'url' => $api_url,
				'is_self' => $api_url === '' || $api_url === self::selfApiUrl(),
				'service_user_email' => self::serviceUserEmail(),
				'service_user_exists' => User::GetByEmail(self::serviceUserEmail()) !== NULL,
				'probe_ok' => $api_configured ? self::probeApi() : false,
			),
			'question' => array(
				'id' => $qid,
				'exists' => $question_exists,
				'text' => $question_text,
				'attached_products' => $question_exists ? self::attachedProducts($qid) : array(),
			),
			'email' => array(
				'welcome_from_email' => self::readSetting('server_manager_provisioning_welcome_from_email'),
				'welcome_from_name' => self::readSetting('server_manager_provisioning_welcome_from_name'),
				'admin_alert_email' => self::readSetting('server_manager_provisioning_admin_alert_email'),
			),
			'tasks' => $tasks,
			'shared_hosts' => array(
				'enabled_count' => (int)$provisioning_hosts->count_all(),
			),
			'domains' => self::domainStatus(),
			'cloud' => array(
				'oauth_configured' => $oauth_configured,
				'ssh_key_path' => $ssh_key_path,
				'ssh_key_exists' => $ssh_key_path !== '' && file_exists($ssh_key_path),
				'ssh_pub_exists' => $ssh_key_path !== '' && file_exists($ssh_key_path . '.pub'),
				'referral_url' => self::readSetting('server_manager_linode_referral_url'),
				'region' => self::readSetting('server_manager_customer_cloud_region'),
				'type' => self::readSetting('server_manager_customer_cloud_type'),
				'image' => self::readSetting('server_manager_customer_cloud_image'),
			),
			'agent' => self::agentStatus(),
		);
	}

	/**
	 * State of the job-executing Go agent. Every job the pipeline creates
	 * (install_node, provision_ssl, ...) sits pending until an agent polling
	 * THIS site's queue claims it — a control plane without a live agent looks
	 * activated but can never execute, so the page surfaces it as a hard
	 * requirement.
	 *
	 * @return array{present:bool, online:bool, name:string, version:string,
	 *               last_heartbeat:string}
	 */
	public static function agentStatus(): array {
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/agent_heartbeat_class.php'));
		$agent = AgentHeartbeat::getLatest();
		if ($agent === null) {
			return array('present' => false, 'online' => false,
				'name' => '', 'version' => '', 'last_heartbeat' => '');
		}
		return array(
			'present' => true,
			'online' => (bool)$agent->is_online(),
			'name' => (string)$agent->get('ahb_agent_name'),
			'version' => (string)$agent->get('ahb_agent_version'),
			'last_heartbeat' => (string)$agent->get('ahb_last_heartbeat'),
		);
	}
}
