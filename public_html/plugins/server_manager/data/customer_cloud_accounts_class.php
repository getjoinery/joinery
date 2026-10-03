<?php
/**
 * CustomerCloudAccount - A user's OAuth-linked cloud provider account.
 *
 * One row per (user, provider). Holds the OAuth token set for acting on the
 * customer's own cloud account (instances created with it are billed by the
 * provider to the customer). Access and refresh tokens are SecretBox-encrypted
 * at rest; use storeToken()/getToken() rather than touching the columns.
 *
 * Status: 'active' (usable), 'refresh_failed' (token refresh failed; needs
 * re-connect), 'revoked' (provider rejected the grant; needs re-connect).
 *
 * @version 1.2 - cca_account_name (the provider's own name for the account, read at connect) and
 *                 provision_targets(): one list of the accounts a server can be created on, named as the provider names them
 * @version 1.1 - grant_expired(): whether a stored grant can still be used, so pages can say so up front
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/SecretBox.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Token.php'));

class CustomerCloudAccountException extends SystemBaseException {}

class CustomerCloudAccount extends SystemBase {
	public static $prefix = 'cca';
	public static $tablename = 'cca_customer_cloud_accounts';
	public static $pkey_column = 'cca_customer_cloud_account_id';

	protected static $foreign_key_actions = array(
		'cca_usr_user_id' => array('action' => 'permanent_delete'),
	);

	public static $field_specifications = array(
		'cca_customer_cloud_account_id'            => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'cca_usr_user_id'   => array('type'=>'int8', 'required'=>true, 'is_nullable'=>false),
		'cca_provider'      => array('type'=>'varchar(32)', 'is_nullable'=>false, 'default'=>'linode'),
		'cca_access_token'  => array('type'=>'text'),
		'cca_token_expires' => array('type'=>'timestamp(6)'),
		'cca_refresh_token' => array('type'=>'text'),
		'cca_scopes'        => array('type'=>'varchar(255)'),
		'cca_account_name'  => array('type'=>'varchar(255)'),
		'cca_status'        => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>'active'),
		'cca_create_time'   => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'cca_update_time'   => array('type'=>'timestamp(6)'),
		'cca_delete_time'   => array('type'=>'timestamp(6)'),
	);

	/**
	 * Whether this account's grant is past its expiry with no way to refresh
	 * it — Linode issues no refresh token, so an expired grant means the owner
	 * must re-consent before anything can use the account. A missing expiry
	 * is treated as usable (the consumer finds out on first use).
	 */
	public static function grant_expired($account): bool {
		$expires = trim((string)$account->get('cca_token_expires'));
		if ($expires === '') {
			return false;
		}
		return strtotime($expires . ' UTC') < time() && trim((string)$account->get('cca_refresh_token')) === '';
	}

	/**
	 * The accounts a new server can be created on, each named as its
	 * provider names it: the operator token's account first, then every
	 * connected account still usable. A connection whose grant has expired
	 * is not offered; it comes back as a sentence saying who must re-connect.
	 *
	 * @return array{options: array<string,string>, unusable: string[]} options keyed 'operator' or the account id
	 */
	public static function provision_targets(bool $with_operator = true): array {
		$options = array();
		$unusable = array();
		if ($with_operator && ProvisionCustomerCloud::operator_compute_token() !== '') {
			$name = trim((string)Globalvars::get_instance()->get_setting('server_manager_operator_cloud_account'));
			$options['operator'] = 'Linode — ' . ($name !== '' ? $name : 'account name not read yet; save the hosted card on '
				. 'Provisioning Setup to read it') . ' (operator token)';
		}
		foreach (new MultiCustomerCloudAccount(array('status' => 'active', 'deleted' => false)) as $ca) {
			$ca_user = new User($ca->get('cca_usr_user_id'), TRUE);
			$who = $ca_user->key ? trim($ca_user->get('usr_first_name') . ' ' . $ca_user->get('usr_last_name'))
				: ('user #' . $ca->get('cca_usr_user_id'));
			$provider = ucfirst((string)$ca->get('cca_provider'));
			$name = trim((string)$ca->get('cca_account_name'));
			if (self::grant_expired($ca)) {
				$unusable[] = $provider . ' account connected by ' . $who . ' on ' . $ca->get_local('cca_create_time', 'M j, Y')
					. ': its permission expired. ' . $who . ' re-connects it from their profile.';
				continue;
			}
			$options[(string)$ca->key] = $provider . ' — ' . ($name !== '' ? $name : 'account name not read') . ', connected by ' . $who;
		}
		return array('options' => $options, 'unusable' => $unusable);
	}

	function prepare() {
		if (empty($this->get('cca_usr_user_id'))) {
			throw new CustomerCloudAccountException('User is required.');
		}
		if (empty($this->get('cca_provider'))) {
			throw new CustomerCloudAccountException('Provider is required.');
		}
		$this->set('cca_update_time', gmdate('Y-m-d H:i:s'));
	}

	/**
	 * Persist an OAuth2Token set on this record (encrypted). Does not save.
	 */
	public function storeToken(OAuth2Token $token) {
		$box = new SecretBox();
		$this->set('cca_access_token', $box->seal('cca_customer_cloud_accounts.cca_access_token', $token->getAccessToken()));
		$refresh = $token->getRefreshToken();
		$this->set('cca_refresh_token', $refresh !== null ? $box->seal('cca_customer_cloud_accounts.cca_refresh_token', $refresh) : null);
		$this->set('cca_token_expires', $token->getExpiresAt());
		$this->set('cca_scopes', $token->getScope());
	}

	/**
	 * Reconstruct the stored token set. Returns null if no token is stored.
	 */
	public function getToken(): ?OAuth2Token {
		$access_stored = (string)$this->get('cca_access_token');
		if ($access_stored === '') {
			return null;
		}
		$box = new SecretBox();
		$access = $box->open($access_stored)['value'];
		if ($access === null) {
			// Dead access token (moved database / rotated key): degrade to no token.
			return null;
		}
		$refresh_stored = (string)$this->get('cca_refresh_token');
		return new OAuth2Token(
			$access,
			$refresh_stored !== '' ? $box->open($refresh_stored)['value'] : null,
			$this->get('cca_token_expires') ?: null,
			(string)$this->get('cca_scopes')
		);
	}

	/**
	 * Load a user's active account link for a provider, or null.
	 */
	public static function get_for_user($user_id, $provider = 'linode'): ?CustomerCloudAccount {
		$multi = new MultiCustomerCloudAccount(array(
			'user_id'  => $user_id,
			'provider' => $provider,
			'deleted'  => false,
		));
		$multi->load();
		foreach ($multi as $account) {
			return $account;
		}
		return null;
	}
}

class MultiCustomerCloudAccount extends SystemMultiBase {
	protected static $model_class = 'CustomerCloudAccount';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['user_id'])) {
			$filters['cca_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['provider'])) {
			$filters['cca_provider'] = [$this->options['provider'], PDO::PARAM_STR];
		}

		if (isset($this->options['status'])) {
			$filters['cca_status'] = [$this->options['status'], PDO::PARAM_STR];
		}


		return $this->_get_resultsv2('cca_customer_cloud_accounts', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
