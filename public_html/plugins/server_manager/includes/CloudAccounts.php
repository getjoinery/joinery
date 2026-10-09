<?php
/**
 * CloudAccounts — which cloud account a box belongs to: the main account or the
 * disposable test one. The Server Manager dashboard has a tab for each and shows
 * only that tab's boxes (the server_manager_account_tabs spec).
 *
 * An account is recorded on a host (mgh_cloud_account) or a node
 * (mgn_cloud_account); empty means main, so an unmarked box is never hidden from
 * the main tab. A node placed on a host takes its host's account.
 *
 * @version 1.0
 */
class CloudAccounts {

	const MAIN = 'main';
	const TEST = 'test';

	const LABELS = array(
		self::MAIN => 'Joinery Main Linode',
		self::TEST => 'Joinery Test Linode',
	);

	/** The operator token's account company, read from the provider when the hosted card is saved. */
	const COMPANY_SETTING = 'server_manager_operator_cloud_company';

	/** The day dev's operator token became the test account's; older boxes are not test boxes. */
	const TEST_ACCOUNT_SINCE = '2026-10-07 00:00:00';

	public static function valid(string $account): bool {
		return isset(self::LABELS[$account]);
	}

	public static function label(string $account): string {
		return self::LABELS[$account] ?? self::LABELS[self::MAIN];
	}

	/** A stored value as a tab: anything unrecognised reads as main. */
	public static function normalize($account): string {
		$account = trim((string)$account);
		return self::valid($account) ? $account : self::MAIN;
	}

	/** The account a company name marks: the disposable test company is test, anything else is main. */
	public static function for_company(string $company): string {
		return trim($company) === TestCloudCleanup::COMPANY ? self::TEST : self::MAIN;
	}

	/** The account this plane's own operator token belongs to; boxes this plane creates are marked with it. */
	public static function plane_account(): string {
		return self::for_company((string)Globalvars::get_instance()->get_setting(self::COMPANY_SETTING));
	}

	/** A host's account. */
	public static function of_host($host): string {
		return self::normalize($host ? $host->get('mgh_cloud_account') : '');
	}

	/**
	 * A node's account: its host's when placed on a live host, else its own.
	 * $host_accounts (host id => account, live hosts only) saves a lookup per node
	 * when a page has the hosts already; a host id missing from it is not live.
	 */
	public static function of_node($node, ?array $host_accounts = null): string {
		if (!$node) {
			return self::MAIN;
		}
		$host_id = (int)$node->get('mgn_mgh_managed_host_id');
		if ($host_id) {
			if ($host_accounts !== null) {
				if (isset($host_accounts[$host_id])) {
					return $host_accounts[$host_id];
				}
			} else {
				try {
					$host = new ManagedHost($host_id, TRUE);
					if ($host->key && !$host->get('mgh_delete_time')) {
						return self::of_host($host);
					}
				} catch (Throwable $e) {
					// a placement that points at no host: the node stands on its own
				}
			}
		}
		return self::normalize($node->get('mgn_cloud_account'));
	}

	/**
	 * Mark a record that has no account yet with this plane's. Called from
	 * prepare() on both models, so every code path that creates a box is covered;
	 * a form that asks the operator sets the field first and is left alone.
	 */
	public static function stamp_new($record, string $field): void {
		if (trim((string)$record->get($field)) === '') {
			$record->set($field, self::plane_account());
		}
	}

	/**
	 * One-time marking for a plane whose own account is the test one: every box with no
	 * account created since the token became the test account's is a test box.
	 *
	 * @return int how many rows were marked
	 */
	public static function backfill($dblink, ?string $company = null): int {
		if (($company === null ? self::plane_account() : self::for_company($company)) !== self::TEST) {
			return 0;
		}
		$marked = 0;
		foreach (array('mgh_managed_hosts' => 'mgh', 'mgn_managed_nodes' => 'mgn') as $table => $p) {
			$q = $dblink->prepare("UPDATE $table SET {$p}_cloud_account = ?
				WHERE ({$p}_cloud_account IS NULL OR {$p}_cloud_account = '') AND {$p}_create_time >= ?");
			$q->execute(array(self::TEST, self::TEST_ACCOUNT_SINCE));
			$marked += $q->rowCount();
		}
		return $marked;
	}

	/** The selected tab: the request's choice (remembered in a cookie), else the cookie, else main. */
	public static function selected(?array $get = null, ?array $cookie = null): string {
		$get = $get ?? $_GET;
		$cookie = $cookie ?? $_COOKIE;
		if (isset($get['account']) && self::valid((string)$get['account'])) {
			return (string)$get['account'];
		}
		return self::normalize($cookie['svm_account'] ?? '');
	}
}
