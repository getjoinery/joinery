<?php
/**
 * RecoveryReadiness — the registry behind the Recovery Readiness page
 * (/admin/admin_recovery_readiness): every secret that must exist OUTSIDE the
 * platform for data to survive, derived live from system state, each with a
 * verify tool and a canonical password-manager label.
 *
 * Two sources of items:
 *   - Core: the signed-in user's Sealed Vault recovery codes, one item per
 *     scope that actually has a vault.
 *   - Plugins: a `recoveryReadiness` array in plugin.json (the PluginProvisioning
 *     declaration pattern), each entry `{"call": "Class::method"}` resolving into
 *     the plugin's includes/ directory. The method returns a list of item arrays.
 *
 * Item shape (normalized):
 *   key         stable ledger key, e.g. 'backup_recovery_key'
 *   title       short name shown as the card heading
 *   protects    plain-language sentence: what is lost without it
 *   label       canonical password-manager entry name (with fingerprint)
 *   facts       [display label => value] non-secret facts (fingerprint, bucket…)
 *   verify      'ceremony' | 'dry_run' | 'attested' | null
 *   state       'ready' | 'not_configured' | 'error'
 *   state_text  shown when not ready (e.g. "no recovery story configured")
 *   warnings    [strings] e.g. low recovery codes, no passkey
 *   ceremony    (verify=ceremony) [challenge, public_key, cli_command]
 *   scope       (verify=dry_run) vault scope; custody 'server'|'client'
 *
 * The ledger (RecoveryVerification) stores pass/fail + when, per user — never
 * the secret. Staleness = newest passed row older than STALE_DAYS (or none).
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/PathHelper.php');
require_once(PathHelper::getIncludePath('data/recovery_verifications_class.php'));

class RecoveryReadiness {

	const STALE_DAYS = 180;
	const MANIFEST_KEY = 'recoveryReadiness';
	const LOW_CODE_THRESHOLD = 3;

	/** Vault scope -> what the user reads. */
	private static $scope_titles = array(
		'user'      => 'Mail & messages vault recovery codes',
		'drive'     => 'Drive vault recovery codes',
		'passwords' => 'Password vault recovery codes',
	);

	/**
	 * Every must-save item for this session's user, ledger state attached,
	 * ordered: platform items first, then the user's vault scopes.
	 */
	public static function items(SessionControl $session) {
		$items = array();
		foreach (self::pluginItems() as $item) {
			$items[] = $item;
		}
		foreach (self::vaultItems((int)$session->get_user_id()) as $item) {
			$items[] = $item;
		}
		return self::attachLedger($items, (int)$session->get_user_id());
	}

	/**
	 * The signed-in user's vault cards only — the member-facing surface
	 * (/profile/security). Same items, same ledger, no platform items.
	 */
	public static function memberVaultItems(SessionControl $session) {
		return self::attachLedger(
			self::vaultItems((int)$session->get_user_id()),
			(int)$session->get_user_id()
		);
	}

	private static function attachLedger(array $items, $user_id) {
		$latest = RecoveryVerification::latest_passed(
			array_map(function ($i) { return $i['key']; }, $items),
			$user_id
		);
		$stale_before = gmdate('Y-m-d H:i:s', time() - self::STALE_DAYS * 86400);
		foreach ($items as &$item) {
			$item['last_verified'] = isset($latest[$item['key']]) ? $latest[$item['key']] : null;
			$item['stale'] = ($item['state'] === 'ready')
				&& ($item['last_verified'] === null || $item['last_verified'] < $stale_before);
		}
		unset($item);
		return $items;
	}

	// ── Plugin items ───────────────────────────────────────────────────────

	private static function pluginItems() {
		require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
		$items = array();
		foreach (PluginHelper::getActivePlugins() as $plugin => $helper) {
			$declared = $helper->get(self::MANIFEST_KEY, array());
			if (!is_array($declared)) {
				continue;
			}
			foreach ($declared as $declaration) {
				$call = is_array($declaration) ? ($declaration['call'] ?? '') : '';
				$provider = self::resolveCall($plugin, $call);
				if ($provider === null) {
					$items[] = self::errorItem($plugin, "Readiness provider {$call} could not be loaded.");
					continue;
				}
				try {
					$provided = call_user_func($provider);
				} catch (\Throwable $e) {
					$items[] = self::errorItem($plugin, 'Readiness provider failed: ' . $e->getMessage());
					continue;
				}
				foreach (is_array($provided) ? $provided : array() as $item) {
					if (!is_array($item) || empty($item['key'])) {
						continue;
					}
					$item['_plugin'] = $plugin;
					$items[] = self::normalize($item);
				}
			}
		}
		return $items;
	}

	private static function errorItem($plugin, $reason) {
		return self::normalize(array(
			'key'        => 'plugin_error_' . $plugin,
			'title'      => ucfirst($plugin) . ' readiness items',
			'state'      => 'error',
			'state_text' => $reason,
		));
	}

	private static function normalize(array $item) {
		return array_merge(array(
			'title'        => $item['key'],
			'protects'     => '',
			'label'        => '',
			'facts'        => array(),
			'verify'       => null,
			'state'        => 'ready',
			'state_text'   => '',
			'warnings'     => array(),
			'instructions' => '',
			'action_url'   => '',
			'action_url_label' => '',
			'_plugin'      => null,
		), $item);
	}

	/**
	 * Resolve "Class::method" against a plugin's includes/ directory (the
	 * PluginProvisioning convention). Returns a callable or null.
	 */
	private static function resolveCall($plugin, $call) {
		if (!is_string($call) || substr_count($call, '::') !== 1) {
			return null;
		}
		list($class, $method) = explode('::', $call, 2);
		if (!class_exists($class)) {
			$file = PathHelper::getIncludePath('plugins/' . $plugin . '/includes/' . $class . '.php');
			if (is_file($file)) {
				require_once($file);
			}
		}
		if (!class_exists($class) || !method_exists($class, $method)) {
			return null;
		}
		return array($class, $method);
	}

	// ── Core vault items ───────────────────────────────────────────────────

	private static function vaultItems($user_id) {
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
		require_once(PathHelper::getIncludePath('data/user_encryption_wrappings_class.php'));

		$vaults = new MultiUserEncryptionVault(array('user_id' => $user_id));
		$vaults->load();

		$items = array();
		foreach ($vaults as $vault) {
			$scope = (string)$vault->get('uev_scope');
			$counts = self::wrappingCounts((int)$vault->key);

			$warnings = array();
			if ($counts['recovery'] < self::LOW_CODE_THRESHOLD) {
				$warnings[] = 'Only ' . $counts['recovery'] . ' unused recovery '
					. ($counts['recovery'] === 1 ? 'code is' : 'codes are') . ' left — generate a fresh set.';
			}
			if ($counts['passkey'] === 0) {
				$warnings[] = 'No passkey is enrolled for this vault — recovery codes and '
					. ($counts['passphrase'] > 0 ? 'the passphrase are' : 'nothing else is')
					. ' the only way in.';
			}

			$custody = (string)$vault->get('uev_custody');

			// Client custody: the code never reaches the server, so the browser
			// needs the user's own wrapping rows to attempt the unwrap locally.
			// These are the same opaque blobs vault_client_status already hands
			// the member-area keyring — nothing new is exposed.
			$client_wrappings = array();
			if ($custody === UserEncryptionVault::CUSTODY_CLIENT) {
				$client_wrappings = self::clientRecoveryWrappings($vault, $scope);
			}

			$items[] = self::normalize(array(
				'key'      => 'vault_codes_' . $scope,
				'title'    => isset(self::$scope_titles[$scope]) ? self::$scope_titles[$scope] : ucfirst($scope) . ' vault recovery codes',
				'protects' => $custody === 'client'
					? 'This content is end-to-end encrypted. If every unlocker is lost, nobody — including this server — can ever open it again.'
					: 'Encrypted content in this vault. Losing every unlocker makes it permanently unreadable.',
				'label'    => '{site} — ' . $scope . ' vault recovery codes ({account})',
				'facts'    => array(
					'Unused recovery codes' => (string)$counts['recovery'],
					'Passkeys enrolled'     => (string)$counts['passkey'],
					'Bypass passphrase'     => $counts['passphrase'] > 0 ? 'set' : 'not set',
					'Custody'               => $custody === 'client' ? 'client (browser-held keys)' : 'server',
				),
				'verify'   => 'dry_run',
				'scope'    => $scope,
				'custody'  => $custody,
				'client_wrappings' => $client_wrappings,
				'warnings' => $warnings,
			));
		}
		return $items;
	}

	/**
	 * Unused recovery wrappings for a client-custody vault, shaped for the
	 * browser dry run: [{wrapped, salt, ad}]. The AD string mirrors
	 * vault-keyring.js adFor(scope, 'recovery') — the binding these blobs were
	 * wrapped under in the browser.
	 */
	private static function clientRecoveryWrappings($vault, $scope) {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT uew_wrapped_secret_key, uew_salt
			   FROM uew_user_encryption_wrappings
			  WHERE uew_uev_user_encryption_vault_id = ?
			    AND uew_unlocker_type = 'recovery' AND uew_is_used = false AND uew_delete_time IS NULL");
		$q->execute(array((int)$vault->key));
		$rows = array();
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$salt = (string)$row['uew_salt'];
			if ($salt === '') {
				$salt = (string)$vault->get('uev_salt');
			}
			$rows[] = array(
				'wrapped' => (string)$row['uew_wrapped_secret_key'],
				'salt'    => $salt,
				'ad'      => 'vault:' . $scope . ':recovery',
			);
		}
		return $rows;
	}

	private static function wrappingCounts($vault_id) {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT
			    COUNT(*) FILTER (WHERE uew_unlocker_type = 'recovery' AND uew_is_used = false AND uew_delete_time IS NULL) AS recovery,
			    COUNT(*) FILTER (WHERE uew_unlocker_type = 'passkey' AND uew_delete_time IS NULL) AS passkey,
			    COUNT(*) FILTER (WHERE uew_unlocker_type = 'passphrase' AND uew_delete_time IS NULL) AS passphrase
			   FROM uew_user_encryption_wrappings
			  WHERE uew_uev_user_encryption_vault_id = ?");
		$q->execute(array($vault_id));
		$row = $q->fetch(PDO::FETCH_ASSOC) ?: array();
		return array(
			'recovery'   => (int)($row['recovery'] ?? 0),
			'passkey'    => (int)($row['passkey'] ?? 0),
			'passphrase' => (int)($row['passphrase'] ?? 0),
		);
	}

	// ── Verification ───────────────────────────────────────────────────────

	/**
	 * Verify an item and append the ledger row. Returns
	 * ['ok' => bool, 'message' => string]. The caller has already gated on
	 * permission and step-up; this dispatches by the item's verify type.
	 */
	public static function verifyItem($item_key, array $input, SessionControl $session) {
		$found = null;
		foreach (self::items($session) as $item) {
			if ($item['key'] === $item_key) {
				$found = $item;
				break;
			}
		}
		if ($found === null || $found['state'] !== 'ready') {
			return array('ok' => false, 'message' => 'Unknown or unavailable item.');
		}
		$user_id = (int)$session->get_user_id();

		switch ($found['verify']) {
			case 'ceremony':
				$verifier = self::resolveCall($found['_plugin'], (string)($found['verify_call'] ?? ''));
				if ($verifier === null) {
					return array('ok' => false, 'message' => 'This item has no working verifier.');
				}
				try {
					$outcome = call_user_func($verifier, $input);
				} catch (\Throwable $e) {
					$outcome = array('ok' => false, 'message' => $e->getMessage());
				}
				$ok = !empty($outcome['ok']);
				RecoveryVerification::record($item_key, RecoveryVerification::METHOD_CEREMONY, $user_id, $ok);
				return array('ok' => $ok, 'message' => (string)($outcome['message'] ?? ''));

			case 'attested':
				RecoveryVerification::record($item_key, RecoveryVerification::METHOD_ATTESTED, $user_id, true);
				return array('ok' => true, 'message' => 'Recorded. This one is on your honor — the platform cannot check it for you.');

			case 'dry_run':
				$outcome = self::dryRunVaultCode($user_id, (string)$found['scope'], (string)($input['code'] ?? ''));
				RecoveryVerification::record($item_key, RecoveryVerification::METHOD_DRY_RUN, $user_id, $outcome['ok']);
				return $outcome;
		}
		return array('ok' => false, 'message' => 'This item has no verify tool.');
	}

	/**
	 * Server-custody dry run: derive the KEK from the code and attempt the
	 * unwrap exactly as VaultCeremonies::unlockWithRecoveryCode() does, but STOP
	 * before anything mutates — the code is not consumed, no window opens, the
	 * unwrapped secret is discarded immediately.
	 *
	 * Client-custody scopes are refused here: their codes must never reach the
	 * server, so the page runs the same dry run in the browser and records the
	 * result via recordClientDryRun().
	 */
	public static function dryRunVaultCode($user_id, $scope, $code) {
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
		require_once(PathHelper::getIncludePath('data/user_encryption_wrappings_class.php'));
		require_once(PathHelper::getIncludePath('includes/SealedBox.php'));

		if (trim($code) === '') {
			return array('ok' => false, 'message' => 'Enter a recovery code.');
		}

		$vault = UserEncryptionVault::loadForUser((int)$user_id, $scope);
		if (!$vault) {
			return array('ok' => false, 'message' => 'No vault exists for this scope.');
		}
		if ((string)$vault->get('uev_custody') === UserEncryptionVault::CUSTODY_CLIENT) {
			return array('ok' => false, 'message' => 'This vault is client-custody — its codes are checked in your browser, never sent here.');
		}

		$wrappings = new MultiUserEncryptionWrapping(array(
			'vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_RECOVERY, 'is_used' => false,
		));
		$wrappings->load();

		$box = new SealedBox();
		$keks = array();
		foreach ($wrappings as $wrapping) {
			$salt = (string)$wrapping->get('uew_salt');
			if ($salt === '') {
				$salt = (string)$vault->get('uev_salt');
			}
			if (!array_key_exists($salt, $keks)) {
				try {
					$keks[$salt] = $box->kekFromRecoveryCode($code, $salt);
				} catch (Exception $e) {
					$keks[$salt] = null;
				}
			}
			if ($keks[$salt] === null) {
				continue;
			}
			try {
				$ad = UserEncryptionWrapping::adFor((int)$vault->key, $wrapping->key);
				$secret = $box->unwrapKey($wrapping->get('uew_wrapped_secret_key'), $keks[$salt], $ad);
				if (is_string($secret)) {
					sodium_memzero($secret);
				}
				return array('ok' => true, 'message' => 'That code works. It was not used up by this check.');
			} catch (Exception $e) {
				continue; // wrong code for this row — try the next
			}
		}
		return array('ok' => false, 'message' => 'That code does not open this vault — it is wrong, already used, or from an old set.');
	}

	/**
	 * Ledger a browser-side dry run for a client-custody scope. The server
	 * cannot re-check the claim (that is the custody model); a forged pass only
	 * misleads the person forging it, so self-reporting is acceptable here.
	 */
	public static function recordClientDryRun($user_id, $scope, $passed, SessionControl $session) {
		$item_key = 'vault_codes_' . $scope;
		foreach (self::memberVaultItems($session) as $item) {
			if ($item['key'] === $item_key && ($item['custody'] ?? '') === 'client') {
				RecoveryVerification::record($item_key, RecoveryVerification::METHOD_DRY_RUN, (int)$user_id, (bool)$passed);
				return array('ok' => (bool)$passed, 'message' => $passed
					? 'That code works. It was not used up by this check.'
					: 'That code does not open this vault.');
			}
		}
		return array('ok' => false, 'message' => 'Unknown client-custody item.');
	}

	/**
	 * Member-side verify: the signed-in user's own vault codes only. The
	 * platform items (recovery key, bucket attestations) are operator surfaces
	 * and are not reachable through this path.
	 */
	public static function verifyMemberVaultCode(SessionControl $session, $scope, $code) {
		foreach (self::memberVaultItems($session) as $item) {
			if ($item['key'] === 'vault_codes_' . $scope && $item['verify'] === 'dry_run') {
				$outcome = self::dryRunVaultCode((int)$session->get_user_id(), (string)$scope, (string)$code);
				RecoveryVerification::record($item['key'], RecoveryVerification::METHOD_DRY_RUN,
					(int)$session->get_user_id(), $outcome['ok']);
				return $outcome;
			}
		}
		return array('ok' => false, 'message' => 'No such vault.');
	}

	/**
	 * Operator visibility over OTHER users' vaults: accounts whose unlocker
	 * margin is thin (few unused recovery codes, or no passkey). Metadata a
	 * superadmin can already see — never code material, nothing verifiable
	 * from here. Excludes the viewing operator (their own cards are above).
	 */
	public static function vaultAggregate($exclude_user_id, $limit = 50) {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT u.usr_email, v.uev_scope, v.uev_custody,
			        COUNT(*) FILTER (WHERE w.uew_unlocker_type = 'recovery' AND w.uew_is_used = false AND w.uew_delete_time IS NULL) AS unused_codes,
			        COUNT(*) FILTER (WHERE w.uew_unlocker_type = 'passkey' AND w.uew_delete_time IS NULL) AS passkeys,
			        COUNT(*) FILTER (WHERE w.uew_unlocker_type = 'passphrase' AND w.uew_delete_time IS NULL) AS passphrases
			   FROM uev_user_encryption_vaults v
			   JOIN usr_users u ON u.usr_user_id = v.uev_usr_user_id
			   LEFT JOIN uew_user_encryption_wrappings w ON w.uew_uev_user_encryption_vault_id = v.uev_user_encryption_vault_id
			  WHERE v.uev_usr_user_id <> ?
			  GROUP BY u.usr_email, v.uev_scope, v.uev_custody
			 HAVING COUNT(*) FILTER (WHERE w.uew_unlocker_type = 'recovery' AND w.uew_is_used = false AND w.uew_delete_time IS NULL) < ?
			     OR COUNT(*) FILTER (WHERE w.uew_unlocker_type = 'passkey' AND w.uew_delete_time IS NULL) = 0
			  ORDER BY u.usr_email, v.uev_scope
			  LIMIT " . (int)$limit);
		$q->execute(array((int)$exclude_user_id, self::LOW_CODE_THRESHOLD));
		$rows = array();
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$issues = array();
			if ((int)$row['unused_codes'] < self::LOW_CODE_THRESHOLD) {
				$issues[] = 'only ' . (int)$row['unused_codes'] . ' unused recovery ' . ((int)$row['unused_codes'] === 1 ? 'code' : 'codes');
			}
			if ((int)$row['passkeys'] === 0) {
				$issues[] = 'no passkey';
			}
			$rows[] = array(
				'email'   => $row['usr_email'],
				'scope'   => $row['uev_scope'],
				'custody' => $row['uev_custody'],
				'issues'  => implode(', ', $issues),
			);
		}
		return $rows;
	}

	// ── Dashboard summary ──────────────────────────────────────────────────

	/**
	 * One-line attention summary: how many ready items were never verified,
	 * how many are stale, how many carry warnings. All zero => all good.
	 */
	public static function attention(SessionControl $session) {
		$never = 0; $stale = 0; $warnings = 0;
		foreach (self::items($session) as $item) {
			if ($item['state'] !== 'ready') {
				continue;
			}
			if ($item['last_verified'] === null) {
				$never++;
			} elseif ($item['stale']) {
				$stale++;
			}
			if (count($item['warnings'])) {
				$warnings++;
			}
		}
		return array('never' => $never, 'stale' => $stale, 'warnings' => $warnings);
	}
}
