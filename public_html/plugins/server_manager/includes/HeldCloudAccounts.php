<?php
/**
 * HeldCloudAccounts — every cloud account this management node can act on,
 * ours and customers', and which one a server is in (the cloud_accounts_list
 * spec).
 *
 * Two tables hold them: our accounts (OperatorCloudAccount, added on the
 * Hosting tab of Provisioning Setup) and customers' connected accounts
 * (CustomerCloudAccount, connected from a customer's own profile). Everything
 * that lists every account — the account choice on the forms that create a
 * server, the daily transfer watch, adopting a server — reads both through
 * here, and knows an account by one key: 'oca:<id>' for ours, 'cca:<id>' for
 * a customer's.
 *
 * A server in one of our accounts names its account on its provision record
 * (cvp_oca_operator_cloud_account_id), and everything done to it later uses
 * that account's credential: provision_token(). A server whose account is no
 * longer held (removed, or a sign-in that expired) gets the reason instead.
 *
 * @version 1.1 - each account's driver is its provider's (ComputeDriverRegistry); a server's credential names its
 *               provider; the watch reads every account whose driver lists machines, of any provider
 *               (specs/site_copy_anything_to_anything.md D2)
 * @version 1.0
 */

class HeldCloudAccounts {

	// ── The choice on a form ─────────────────────────────────────────────────

	/**
	 * The accounts a new server can be created on: ours that are usable, the
	 * default first, then customers' connected accounts still usable. An entry
	 * that cannot be used comes back as a sentence saying why and who fixes it.
	 *
	 * @return array{options: array<string,string>, default: string, unusable: string[]}
	 */
	public static function targets(): array {
		$options = array();
		$unusable = array();
		$default = '';
		foreach (OperatorCloudAccount::held() as $oca) {
			$why = $oca->unusable_reason();
			if ($why !== '') {
				$unusable[] = $why;
				continue;
			}
			if ($oca->granted_scopes() !== null && !$oca->can_create()) {
				$unusable[] = $oca->label() . ': its credential cannot create servers (it lacks '
					. OperatorCloudAccount::CREATE_SCOPE . '). Replace it on the Hosting tab of Provisioning Setup.';
				continue;
			}
			$label = $oca->label() . ($oca->is_default() ? ' (ours, default)' : ' (ours)')
				. ((string)$oca->get('oca_method') === OperatorCloudAccount::METHOD_CONNECT
					? ', signed in until ' . $oca->get_local('oca_token_expires', 'g:i a') : '');
			$options[$oca->choice_key()] = $label;
			if ($oca->is_default()) {
				$default = $oca->choice_key();
			}
		}
		foreach (new MultiCustomerCloudAccount(array('status' => 'active', 'deleted' => false)) as $ca) {
			$ca_user = new User($ca->get('cca_usr_user_id'), TRUE);
			$who = $ca_user->key ? trim($ca_user->get('usr_first_name') . ' ' . $ca_user->get('usr_last_name'))
				: ('user #' . $ca->get('cca_usr_user_id'));
			$provider = ComputeDriverRegistry::label((string)$ca->get('cca_provider'));
			$name = trim((string)$ca->get('cca_account_name'));
			if (CustomerCloudAccount::grant_expired($ca)) {
				$unusable[] = $provider . ' account connected by ' . $who . ' on ' . $ca->get_local('cca_create_time', 'M j, Y')
					. ': its permission expired. ' . $who . ' re-connects it from their profile.';
				continue;
			}
			$options['cca:' . (int)$ca->key] = $provider . ' — ' . ($name !== '' ? $name : 'account name not read')
				. ', connected by ' . $who . ' (the customer\'s)';
		}
		return array('options' => $options, 'default' => $default, 'unusable' => $unusable);
	}

	/**
	 * What a chosen account key means for a new provision:
	 *   hosting_mode  'operator' (one of ours) or 'customer'
	 *   oca           the OperatorCloudAccount, or null
	 *   cca           the CustomerCloudAccount, or null
	 *   provider      the provider key
	 *   token         the credential to act with now
	 *   owner_id      the user a customer's account belongs to (0 for ours)
	 *
	 * @throws DisplayableUserException saying what to do, when the key names nothing usable
	 */
	public static function resolve(string $key): array {
		if (preg_match('/^oca:(\d+)$/', $key, $m)) {
			$oca = new OperatorCloudAccount((int)$m[1], TRUE);
			$why = $oca->unusable_reason();
			if ($why !== '') {
				throw new DisplayableUserException($why);
			}
			return array('hosting_mode' => 'operator', 'oca' => $oca, 'cca' => null,
				'provider' => (string)$oca->get('oca_provider'), 'token' => $oca->token(), 'owner_id' => 0);
		}
		if (preg_match('/^cca:(\d+)$/', $key, $m)) {
			$cca = new CustomerCloudAccount((int)$m[1], TRUE);
			if (!$cca->key || $cca->get('cca_status') !== 'active' || $cca->get('cca_delete_time')) {
				throw new DisplayableUserException('Choose an active connected cloud account.');
			}
			if (CustomerCloudAccount::grant_expired($cca)) {
				// A Linode grant lasts two hours and cannot be refreshed.
				throw new DisplayableUserException('That account\'s cloud grant has expired. Its owner re-connects it at '
					. '/profile/server_manager/connect_cloud, then return here.');
			}
			$token = $cca->getToken();
			return array('hosting_mode' => 'customer', 'oca' => null, 'cca' => $cca,
				'provider' => (string)$cca->get('cca_provider'), 'token' => $token ? $token->getAccessToken() : '',
				'owner_id' => (int)$cca->get('cca_usr_user_id'));
		}
		throw new DisplayableUserException('Choose a cloud account.');
	}

	/** Plain words for an account key. */
	public static function name(string $key): string {
		if (preg_match('/^oca:(\d+)$/', $key, $m)) {
			$oca = new OperatorCloudAccount((int)$m[1], TRUE);
			return $oca->key ? $oca->label() : 'our account #' . $m[1];
		}
		if (preg_match('/^cca:(\d+)$/', $key, $m)) {
			$cca = new CustomerCloudAccount((int)$m[1], TRUE);
			$name = $cca->key ? trim((string)$cca->get('cca_account_name')) : '';
			return 'connected account #' . $m[1] . ($name !== '' ? ' (' . $name . ')' : '');
		}
		return $key;
	}

	// ── Every account, for a watch ───────────────────────────────────────────

	/**
	 * The accounts this node can read now: [key => driver], and the sentences
	 * for those it could not use. A customer's grant that has expired is
	 * skipped silently: their profile already asks them to reconnect. One of
	 * ours that cannot be used is a problem, said, since nobody else will.
	 */
	public static function drivers(): array {
		$accounts = array();
		$problems = array();
		foreach (OperatorCloudAccount::held() as $oca) {
			if (!ComputeDriverRegistry::supports($oca->provider(), 'CloudMachineTransfer')) {
				continue;
			}
			// An expired sign-in did its one job; it is not a fault to report daily.
			if ($oca->expired()) {
				continue;
			}
			$why = $oca->unusable_reason();
			if ($why !== '') {
				$problems[] = $why;
				continue;
			}
			$accounts[$oca->choice_key()] = $oca->driver();
		}
		foreach (new MultiCustomerCloudAccount(array('status' => 'active', 'deleted' => false)) as $account) {
			if (!ComputeDriverRegistry::supports((string)$account->get('cca_provider'), 'CloudMachineTransfer')
					|| CustomerCloudAccount::grant_expired($account)) {
				continue;
			}
			// A read-only watch never parks an account: a failed refresh is a
			// problem in this run's report, and the account stays as it was.
			$resolved = ProvisionCustomerCloud::account_driver($account, false);
			if ($resolved['driver'] === null) {
				$problems[] = 'Connected account ' . $account->key . ': ' . $resolved['reason'];
				continue;
			}
			$accounts['cca:' . $account->key] = $resolved['driver'];
		}
		return array($accounts, $problems);
	}

	// ── Which account a server is in ─────────────────────────────────────────

	/** The account of ours a provision's server is in, held or not, or null when it names none. */
	public static function provision_account($provision): ?OperatorCloudAccount {
		$id = (int)$provision->get('cvp_oca_operator_cloud_account_id');
		if ($id <= 0) {
			return null;
		}
		$oca = new OperatorCloudAccount($id, TRUE);
		return $oca->key ? $oca : null;
	}

	/**
	 * The credential for a server in one of our accounts:
	 * ['token' => string, 'reason' => why there is none, 'account' => ?OperatorCloudAccount,
	 *  'provider' => the provider its driver is built for].
	 */
	public static function provision_token($provision): array {
		$oca = self::provision_account($provision);
		$provider = $oca ? $oca->provider() : (string)($provision->get('cvp_provider') ?: 'linode');
		if ($oca === null) {
			return array('token' => '', 'account' => null, 'provider' => $provider, 'reason' =>
				'This server\'s record names none of our cloud accounts, so this management node holds no credential for it. '
				. 'The cloud accounts are on the Hosting tab of Provisioning Setup.');
		}
		$why = $oca->unusable_reason();
		if ($why !== '') {
			return array('token' => '', 'account' => $oca, 'provider' => $provider, 'reason' => $why);
		}
		return array('token' => $oca->token(), 'account' => $oca, 'provider' => $provider, 'reason' => '');
	}

	/**
	 * The driver for a server in one of our accounts, or null with the reason:
	 * ['driver' => ?CloudComputeProvider, 'reason' => string].
	 */
	public static function provision_driver($provision): array {
		$held = self::provision_token($provision);
		if ($held['token'] === '') {
			return array('driver' => null, 'reason' => $held['reason']);
		}
		try {
			return array('driver' => OperatorCloudAccount::driver_for($held['token'], $held['provider']), 'reason' => '');
		} catch (CloudComputeException $e) {
			return array('driver' => null, 'reason' => $e->getMessage());
		}
	}

	/** The provider of an account key ('oca:<id>' or 'cca:<id>'), or '' when it names none held. */
	public static function provider_of_key(string $key): string {
		if (preg_match('/^oca:(\d+)$/', $key, $m)) {
			$oca = new OperatorCloudAccount((int)$m[1], TRUE);
			return $oca->key ? $oca->provider() : '';
		}
		if (preg_match('/^cca:(\d+)$/', $key, $m)) {
			$cca = new CustomerCloudAccount((int)$m[1], TRUE);
			return $cca->key ? (string)$cca->get('cca_provider') : '';
		}
		return '';
	}

	/**
	 * The account of ours a node's machine is in, from its newest provision
	 * record in one of our accounts (removed records included: a node keeps
	 * its machine after a record goes), or null when none names one.
	 */
	public static function node_account(ManagedNode $node): ?OperatorCloudAccount {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT cvp_oca_operator_cloud_account_id FROM cvp_customer_cloud_provisions
			WHERE cvp_mgn_managed_node_id = ? AND cvp_oca_operator_cloud_account_id IS NOT NULL
			  AND cvp_hosting_mode = 'operator'
			ORDER BY cvp_customer_cloud_provision_id DESC LIMIT 1");
		$q->execute(array((int)$node->key));
		$id = (int)$q->fetchColumn();
		if ($id <= 0) {
			return null;
		}
		$oca = new OperatorCloudAccount($id, TRUE);
		return $oca->key ? $oca : null;
	}
}
