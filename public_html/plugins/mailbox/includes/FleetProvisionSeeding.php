<?php
/**
 * FleetProvisionSeeding - order-time fleet enrollment seeding for
 * customer-cloud provisions (specs/mailbox_relay_shared_fleet.md § Follow-up).
 *
 * Runs on the OPERATOR deployment when a customer-cloud provision completes.
 * When the buyer's tier carries the fleet-slot feature, the freshly installed
 * tenant box gets the three fleet-service settings pre-seeded
 * (mailbox_fleet_service_url + the API key pair), so its owner lands on a
 * one-click Enroll in the mailbox Setup tab instead of pasting credentials.
 * The DNS TXT ownership challenges and the MX edit stay manual by nature —
 * the customer proving domain control at their own DNS provider.
 *
 * The API key is minted here for the buyer's own account (the fleet service
 * authenticates /api/v1 calls as that customer and gates on their tier). The
 * secret travels to the tenant box over SSH stdin into a psql heredoc — it is
 * never in a job-step row, an argv, or a log line.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetService.php'));

class FleetProvisionSeeding {

	/** apk_name of the minted tenant credential (one active per buyer). */
	const KEY_NAME = 'Fleet enrollment';

	/** read + write, no delete — same axis the provisioning pipeline key uses. */
	const KEY_PERMISSION = 3;

	/**
	 * Whether a completed provision for this buyer should be seeded: the
	 * fleet service is on, this deployment is its own store (a remote store's
	 * buyer ids are not local users, so entitlement cannot be read), and the
	 * buyer's tier carries the fleet-slot feature.
	 */
	public static function applies(int $buyer_user_id): bool {
		if ($buyer_user_id <= 0) {
			return false;
		}
		$settings = Globalvars::get_instance();
		if ((string)$settings->get_setting('mailbox_fleet_service_enabled') !== '1') {
			return false;
		}
		$store_url = rtrim(trim((string)$settings->get_setting('server_manager_getjoinery_api_url')), '/');
		$self_url = rtrim(LibraryFunctions::get_absolute_url('/'), '/');
		if ($store_url !== '' && $store_url !== $self_url) {
			return false;
		}
		return FleetService::entitled($buyer_user_id);
	}

	/**
	 * Seed the fleet-service settings on a provisioned node's site: mint the
	 * buyer's API key and write the three settings into the site's database
	 * over SSH. Returns ['ok' => bool, 'message' => string]; never throws.
	 */
	public static function seedNode($node, int $buyer_user_id, string $sitename): array {
		try {
			if (!preg_match('/^[a-z0-9][a-z0-9-]{0,60}$/', $sitename)) {
				return array('ok' => false, 'message' => "Refusing to seed: sitename '{$sitename}' is not a plain slug.");
			}

			$key = self::mintTenantKey($buyer_user_id);
			if (!preg_match('/^[a-z0-9_]+$/', $key['secret_key'])) {
				return array('ok' => false, 'message' => 'Refusing to seed: minted secret has an unexpected charset.');
			}
			$service_url = rtrim(LibraryFunctions::get_absolute_url('/'), '/');

			$remote = self::buildRemoteCommand($node, $sitename, $service_url, $key['public_key']);
			$run = self::runSsh($node, $remote, $key['secret_key'] . "\n");

			if (!$run['ok'] || strpos($run['output'], 'FLEET_SEEDED') === false) {
				return array('ok' => false, 'message' => 'Fleet seeding SSH step failed (exit '
					. $run['code'] . '): ' . trim($run['output']));
			}
			return array('ok' => true, 'message' => 'Fleet settings seeded; the owner\'s Setup tab offers one-click Enroll.');
		} catch (\Throwable $e) {
			return array('ok' => false, 'message' => 'Fleet seeding failed: ' . $e->getMessage());
		}
	}

	/**
	 * Mint (or re-mint) the buyer's fleet credential: deactivate this user's
	 * previous keys of the same name, create a fresh machine key. Returns
	 * ['public_key' => string, 'secret_key' => plaintext] — the plaintext
	 * exists only in this request.
	 */
	public static function mintTenantKey(int $buyer_user_id): array {
		require_once(PathHelper::getIncludePath('data/api_keys_class.php'));

		$old_keys = new MultiApiKey(array('user_id' => $buyer_user_id));
		$old_keys->load();
		foreach ($old_keys as $old) {
			if ($old->get('apk_name') === self::KEY_NAME && $old->get('apk_is_active')) {
				$old->set('apk_is_active', FALSE);
				$old->save();
			}
		}

		$public_key = 'public_' . LibraryFunctions::random_string(16);
		$secret_plaintext = 'secret_' . LibraryFunctions::random_string(16);

		$api_key = new ApiKey(NULL);
		$api_key->set('apk_usr_user_id', $buyer_user_id);
		$api_key->set('apk_name', self::KEY_NAME);
		$api_key->set('apk_public_key', $public_key);
		$api_key->set('apk_secret_key', ApiKey::GenerateKey($secret_plaintext));
		$api_key->set('apk_type', ApiKey::TYPE_MACHINE);
		$api_key->set('apk_permission', self::KEY_PERMISSION);
		$api_key->set('apk_is_active', TRUE);
		$api_key->save();

		return array('public_key' => $public_key, 'secret_key' => $secret_plaintext);
	}

	/**
	 * The remote command string: reads the secret from stdin, resolves the
	 * site's DB credentials from its config, and upserts the three settings
	 * through a psql heredoc (stg_name is unique). Docker sites run the same
	 * script inside the container; bare-metal under sudo when not root.
	 * The secret never appears in this string.
	 */
	public static function buildRemoteCommand($node, string $sitename, string $service_url, string $public_key): string {
		// Non-secret values, embedded as SQL literals: keep them boring.
		$url_sql = str_replace("'", "''", $service_url);
		$pub_sql = str_replace("'", "''", $public_key);

		$extract = 'head -1 | cut -d";" -f1 | cut -d"=" -f2 | tr -d " " | sed "s/^.//;s/.\$//"';
		// The secret expands inside the psql heredoc — its charset is asserted
		// quote-free by seedNode() before anything is sent.
		$upsert = "INSERT INTO stg_settings (stg_name, stg_value) VALUES\n"
			. "  ('mailbox_fleet_service_url', '{$url_sql}'),\n"
			. "  ('mailbox_fleet_api_public_key', '{$pub_sql}'),\n"
			. "  ('mailbox_fleet_api_secret_key', '\${FLEET_SECRET}')\n"
			. "ON CONFLICT (stg_name) DO UPDATE SET stg_value = EXCLUDED.stg_value, stg_update_time = now();";

		$inner = 'set -e' . "\n"
			. 'IFS= read -r FLEET_SECRET' . "\n"
			. "CFG=/var/www/html/{$sitename}/config/Globalvars_site.php\n"
			. "DB_NAME=\$(grep dbname \$CFG | {$extract})\n"
			. "DB_USER=\$(grep dbusername \$CFG | {$extract})\n"
			. "export PGPASSWORD=\$(grep dbpassword \$CFG | {$extract})\n"
			. "psql -q -U \"\$DB_USER\" -d \"\$DB_NAME\" <<JOINERY_SQL\n"
			. $upsert . "\n"
			. "JOINERY_SQL\n"
			. 'echo FLEET_SEEDED';

		$is_docker = trim((string)$node->get('mgn_container_name')) !== '';
		$ssh_user = (string)$node->get('mgn_ssh_user') ?: 'root';
		$sudo = ($ssh_user !== 'root') ? 'sudo ' : '';
		if ($is_docker) {
			// -i keeps stdin open so the in-container read sees the secret.
			return $sudo . 'docker exec -i ' . escapeshellarg($sitename)
				. ' bash -c ' . escapeshellarg($inner);
		}
		return $sudo . 'bash -c ' . escapeshellarg($inner);
	}

	/**
	 * Run one SSH command against the node with $stdin piped in. Returns
	 * ['ok' => bool, 'code' => int, 'output' => string].
	 */
	private static function runSsh($node, string $remote_command, string $stdin): array {
		$key_path = (string)$node->get('mgn_ssh_key_path');
		$host = (string)$node->get('mgn_host');
		$user = (string)$node->get('mgn_ssh_user') ?: 'root';
		$port = intval($node->get('mgn_ssh_port')) ?: 22;
		if ($key_path === '' || !is_readable($key_path) || $host === '') {
			return array('ok' => false, 'code' => -1,
				'output' => 'Node SSH coordinates incomplete (key: ' . $key_path . ', host: ' . $host . ').');
		}

		$cmd = array(
			'ssh', '-i', $key_path, '-p', (string)$port,
			'-o', 'BatchMode=yes',
			'-o', 'StrictHostKeyChecking=accept-new',
			'-o', 'ConnectTimeout=15',
			$user . '@' . $host,
			$remote_command,
		);
		$proc = proc_open($cmd, array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		), $pipes);
		if (!is_resource($proc)) {
			return array('ok' => false, 'code' => -1, 'output' => 'Could not start ssh.');
		}
		fwrite($pipes[0], $stdin);
		fclose($pipes[0]);
		$out = (string)stream_get_contents($pipes[1]);
		$err = (string)stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($proc);
		return array('ok' => ($code === 0), 'code' => $code, 'output' => trim($out . "\n" . $err));
	}
}
