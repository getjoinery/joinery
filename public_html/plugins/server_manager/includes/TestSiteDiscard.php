<?php
/**
 * TestSiteDiscard — clears a test site away in one click: its server, its DNS
 * records, its mail subaccount, its backups, its test subscription and its
 * records here.
 *
 * Offered only for a provision whose server is in one of our cloud accounts
 * flagged test. That covers a test-mode purchase and an admin's rehearsal
 * alike. The flag only offers the button. Nothing is deleted until the
 * account's company name, read from the provider at the click, is
 * TestCloudCleanup::COMPANY, the same safety catch the nightly test cleanup
 * uses. A live account never carries that name, so a live server can never
 * be deleted from here.
 *
 * Each step is safe to run again and skips what is already gone, so a click
 * that stops part way is finished by the next one. The records here are
 * removed last, and only when every step before succeeded: until then the
 * provision still names what is left, and the board still shows it.
 *
 * What goes:
 *   - the server, at the test account;
 *   - in the test zones (TestCloudCleanup::DNS_ZONES), every record at or
 *     under the site's own name, and every A and AAAA record naming the
 *     server's addresses;
 *   - the mail subaccount at SMTP2GO, closed (its SMTP user and webhook go
 *     with it);
 *   - the site's backups, from every storage space its nodes own;
 *   - a test-mode order's subscription, cancelled at once in Stripe's test
 *     mode (the test charge is left as it is);
 *   - the site's node and, when the server is the site's own Docker host,
 *     the host's node; with them the provision and its hosted trial
 *     (ManagedNode::release_site_records). A provision that never got a node
 *     is removed with its trial directly.
 *
 * A domain row bought for the site is parked on the Domains page, as on any
 * removal. Records for a domain outside the test zones are not ours to
 * touch, and are left.
 *
 * A server tagged keep at the provider goes too: the nightly cleanup spares
 * it from age, and this is a person choosing one site to delete.
 *
 * Refused for a site bought with a live payment, whichever account its server
 * is in: that is a customer, not a test.
 *
 * @version 1.0
 */

class TestSiteDiscard {

	/** Statuses a provision may be discarded in: anything not still building. */
	const STATUSES = array('failed', 'pending_connect', 'held', 'done');

	/** The account the provision's server is in, when it is one of ours flagged test; else null. Asks no provider. */
	public static function test_account(CustomerCloudProvision $provision): ?OperatorCloudAccount {
		if ((string)$provision->get('cvp_hosting_mode') !== 'operator' || $provision->get('cvp_delete_time')) {
			return null;
		}
		$account = HeldCloudAccounts::provision_account($provision);
		return ($account !== null && $account->get('oca_is_test')) ? $account : null;
	}

	/** Is the button offered? Asks no provider. */
	public static function offered(CustomerCloudProvision $provision): bool {
		return self::test_account($provision) !== null;
	}

	/** Why it cannot be discarded now, or '' when it can. Asks no provider. */
	public static function refusal(CustomerCloudProvision $provision): string {
		$account = self::test_account($provision);
		if ($account === null) {
			return 'Only a site whose server is in the test cloud account can be discarded.';
		}
		if ((int)$provision->get('cvp_external_order_item_id') > 0 && !$provision->is_test_purchase()) {
			return 'It was bought with a live payment, so it is a customer\'s site, not a test site.';
		}
		$status = (string)$provision->get('cvp_status');
		if (!in_array($status, self::STATUSES, true)) {
			return 'It is still being built (status \'' . $status . '\'). Wait for it to finish or fail, then discard it.';
		}
		$why = $account->unusable_reason();
		if ($why !== '') {
			return 'The test cloud account cannot be used: ' . $why;
		}
		$inside = self::sites_under($provision);
		if ($inside) {
			return 'Its DNS name holds other sites\' names (' . implode(', ', $inside) . '), whose records this would delete.';
		}
		list($nodes, $others) = self::nodes($provision);
		if ($others) {
			return 'Its server also hosts ' . implode(', ', $others) . ', which this would delete with it.';
		}
		foreach ($nodes as $node) {
			$why = NodeRemoval::copy_refusal($node);
			if ($why !== '') {
				return $why;
			}
		}
		return '';
	}

	/** The other live provisions whose domain sits under this one's, as their domains. */
	public static function sites_under(CustomerCloudProvision $provision): array {
		$domain = strtolower(rtrim(trim((string)$provision->get('cvp_domain')), '.'));
		if ($domain === '') {
			return array();
		}
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT cvp_domain FROM cvp_customer_cloud_provisions
			WHERE cvp_delete_time IS NULL AND cvp_customer_cloud_provision_id <> ? AND LOWER(cvp_domain) LIKE ?");
		$q->execute(array((int)$provision->key, '%.' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $domain)));
		return array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
	}

	/**
	 * The nodes that go with the site: [nodes, names of other sites on the
	 * same server]. The Docker host's node when every site on that host is
	 * this one, then the site's node.
	 *
	 * @return array{0: ManagedNode[], 1: string[]}
	 */
	public static function nodes(CustomerCloudProvision $provision): array {
		$nodes = array();
		$others = array();
		$site_id = (int)$provision->get('cvp_mgn_managed_node_id');
		$site = $site_id > 0 ? new ManagedNode($site_id, TRUE) : null;
		if ($site === null || !$site->key) {
			return array($nodes, $others);
		}
		$host_id = (int)$site->get('mgn_mgh_managed_host_id');
		if ($host_id <= 0) {
			return array(array($site), $others);
		}
		$host = new ManagedHost($host_id, TRUE);
		$agent_id = $host->key ? (int)$host->get('mgh_mgn_managed_node_id') : 0;
		foreach (new MultiManagedNode(array('mgn_mgh_managed_host_id' => $host_id)) as $n) {
			if ((int)$n->key !== $site_id && (int)$n->key !== $agent_id) {
				$others[] = (string)$n->get('mgn_name');
			}
		}
		if ($agent_id > 0 && $agent_id !== $site_id) {
			$agent = new ManagedNode($agent_id, TRUE);
			if ($agent->key) {
				$nodes[] = $agent;
			}
		}
		// The site's node last: it is what finds the host on a later click,
		// so a removal that stops between the two is finished by the next.
		$nodes[] = $site;
		return array($nodes, $others);
	}

	/**
	 * Discard it. Throws DisplayableUserException, having done nothing, when
	 * refusal() refuses or the account fails its safety catch.
	 *
	 * $seams, for tests: 'driver' (the account's compute driver), 'dns' (the
	 * test zones' DNS driver, or false for none), 'mail' (an Smtp2GoClient, or
	 * false for none), 'cancel' (callable(OrderItem) that cancels the
	 * subscription).
	 *
	 * @return array{done: string[], failed: string[], finished: bool}
	 *   done: one sentence per thing removed; failed: one per step that did
	 *   not, to be retried by clicking again; finished: the records here are
	 *   gone too.
	 */
	public static function discard(CustomerCloudProvision $provision, array $seams = array()): array {
		$why = self::refusal($provision);
		if ($why !== '') {
			throw new DisplayableUserException($why);
		}
		$account = self::test_account($provision);
		$driver = array_key_exists('driver', $seams) ? $seams['driver'] : $account->driver();
		if (!$driver instanceof CloudAccountCleanup || !$driver instanceof CloudComputeProvider) {
			throw new DisplayableUserException('This cloud provider\'s driver cannot read the account\'s company name, so nothing was deleted.');
		}
		try {
			$company = $driver->accountCompany();
		} catch (CloudComputeException $e) {
			throw new DisplayableUserException('The test account\'s company name could not be read (' . $e->getMessage() . '), so nothing was deleted.');
		}
		if ($company !== TestCloudCleanup::COMPANY) {
			throw new DisplayableUserException('The account\'s company name is ' . ($company === '' ? 'empty' : '"' . $company . '"')
				. ', not "' . TestCloudCleanup::COMPANY . '", so it is not the test account and nothing was deleted.');
		}

		$out = array('done' => array(), 'failed' => array(), 'finished' => false);
		self::server($provision, $driver, $out);
		self::dns($provision, array_key_exists('dns', $seams) ? $seams['dns'] : TestCloudCleanup::zone_holder(TestCloudCleanup::accounts()), $out);
		self::mail($provision, array_key_exists('mail', $seams) ? $seams['mail'] : (Smtp2GoClient::fromSettings() ?? false), $out);
		list($nodes) = self::nodes($provision);
		self::backups($nodes, $out);
		self::subscription($provision, $seams['cancel'] ?? null, $out);

		if ($out['failed']) {
			$out['failed'][] = 'Its records here are kept until every step above succeeds; click Discard again to finish.';
			return $out;
		}
		$domain = (string)$provision->get('cvp_domain');
		if ($nodes) {
			foreach ($nodes as $node) {
				try {
					foreach ($node->remove_permanently() as $note) {
						$out['done'][] = $note;
					}
					$out['done'][] = 'Removed ' . $node->get('mgn_name') . ' from the dashboard.';
				} catch (Throwable $e) {
					$out['failed'][] = $node->get('mgn_name') . ' was not removed from the dashboard: ' . $e->getMessage()
						. ' Click Discard again to finish.';
					return $out;
				}
			}
		} else {
			$trial = HostedTrial::for_provision($provision->key);
			if ($trial !== null) {
				$trial->soft_delete();
			}
			$provision->soft_delete();
			$out['done'][] = 'Removed the provisioning record for ' . $domain . ($trial !== null ? ' and its hosted trial.' : '.');
		}
		$out['finished'] = true;
		return $out;
	}

	// ── The steps ─────────────────────────────────────────────────────────────

	private static function server(CustomerCloudProvision $provision, $driver, array &$out): void {
		$id = trim((string)$provision->get('cvp_instance_id'));
		if ($id === '') {
			return;
		}
		try {
			$instance = $driver->getInstance($id);
		} catch (CloudComputeException $e) {
			if ((int)$e->getCode() === 404) {
				return;
			}
			$out['failed'][] = 'Could not ask the test account about server ' . $id . ': ' . $e->getMessage();
			return;
		}
		try {
			$driver->deleteInstance($id);
			$out['done'][] = 'Deleted server ' . ($instance['label'] ?? '') . ' (' . $id . ') at the test account.';
		} catch (CloudComputeException $e) {
			$out['failed'][] = 'Server ' . $id . ' was not deleted: ' . $e->getMessage();
		}
	}

	/** $dns: the test zones' DNS driver, or false when no held test account holds them. */
	private static function dns(CustomerCloudProvision $provision, $dns, array &$out): void {
		$domain = strtolower(rtrim(trim((string)$provision->get('cvp_domain')), '.'));
		$addresses = array();
		foreach ($provision->machine_addresses() as $a) {
			if (($b = IpAddress::binary($a)) !== null) { $addresses[$b] = true; }
		}
		foreach (TestCloudCleanup::DNS_ZONES as $zone_name) {
			$under = $domain !== '' && substr($domain, -strlen('.' . $zone_name)) === '.' . $zone_name;
			if (!$under && !$addresses) {
				continue;
			}
			if ($dns === false || $dns === null) {
				$out['failed'][] = 'The ' . $zone_name . ' records were not checked: no Linode test account holding that zone is held here.';
				continue;
			}
			try {
				$zone = $dns->zoneFor($zone_name);
				$records = $zone === null ? array() : $dns->listRecords($zone);
			} catch (Exception $e) {
				$out['failed'][] = 'The ' . $zone_name . ' records could not be read: ' . $e->getMessage();
				continue;
			}
			$gone = array();
			foreach ($records as $record) {
				$name = strtolower((string)$record->name);
				$ours = $under && ($name === $domain || substr($name, -strlen('.' . $domain)) === '.' . $domain);
				if (!$ours && in_array($record->type, array('A', 'AAAA'), true)) {
					$b = IpAddress::binary((string)$record->value);
					$ours = $b !== null && isset($addresses[$b]);
				}
				if (!$ours) {
					continue;
				}
				try {
					$dns->deleteRecord($zone, $record);
					$gone[] = $record->type . ' ' . $record->name;
				} catch (Exception $e) {
					$out['failed'][] = 'Record ' . $record->type . ' ' . $record->name . ' was not deleted: ' . $e->getMessage();
				}
			}
			if ($gone) {
				$out['done'][] = 'Deleted ' . count($gone) . ' DNS record' . (count($gone) === 1 ? '' : 's') . ' in ' . $zone_name . ': ' . implode(', ', $gone) . '.';
			}
		}
	}

	/** $client: an Smtp2GoClient, or false when this site holds no SMTP2GO key. */
	private static function mail(CustomerCloudProvision $provision, $client, array &$out): void {
		$sub = trim((string)$provision->get('cvp_smtp2go_subaccount_id'));
		if ($sub === '') {
			return;
		}
		if ($client === false || $client === null) {
			$out['failed'][] = 'Mail subaccount ' . $sub . ' was not closed: this site holds no SMTP2GO key.';
			return;
		}
		try {
			$state = $client->subaccountState($sub);
			if ($state === '') {
				$out['done'][] = 'Mail subaccount ' . $sub . ' is no longer listed at SMTP2GO; nothing to close.';
			} elseif ($state !== 'closed') {
				$client->closeSubaccount($sub);
				$out['done'][] = 'Closed mail subaccount ' . $sub . ' at SMTP2GO, with its SMTP user and webhook.';
			}
		} catch (Smtp2GoException $e) {
			$out['failed'][] = 'Mail subaccount ' . $sub . ' was not closed: ' . $e->getMessage();
			return;
		}
		$provision->set('cvp_smtp2go_subaccount_id', null);
		$provision->set('cvp_smtp2go_domain_id', null);
		$provision->set('cvp_smtp2go_user_id', null);
		$provision->save();
	}

	/**
	 * Live spaces only: a retired space is empty, and its folder may since
	 * belong to another owner.
	 *
	 * @param ManagedNode[] $nodes
	 */
	private static function backups(array $nodes, array &$out): void {
		foreach ($nodes as $node) {
			foreach (StorageSpace::of_owner(StorageSpace::OWNER_NODE, (int)$node->key) as $space) {
				try {
					list($target, $creds, $bucket) = $space->reach();
					$n = 0;
					foreach (S3Signer::list($creds, $bucket, $space->base()) as $obj) {
						TargetBackups::delete_object($target, (string)$obj['key']);
						$n++;
					}
				} catch (Throwable $e) {
					$out['failed'][] = 'Backups of ' . $node->get('mgn_name') . ' in ' . $space->describe() . ' were not deleted: ' . $e->getMessage();
					continue;
				}
				if ($n > 0) {
					$out['done'][] = 'Deleted ' . $n . ' backup file' . ($n === 1 ? '' : 's') . ' of ' . $node->get('mgn_name') . ' from ' . $space->describe() . '.';
				}
			}
		}
	}

	/**
	 * Cancel on the test side, where a test-mode order's subscription lives,
	 * whatever side the clicking session is on (TestMode::on_test_side()). A
	 * Stripe subscription already cancelled there is taken as done, and the
	 * line is stamped cancelled when the store's own refresh did not.
	 *
	 * $cancel: callable(OrderItem) doing the provider's part, for tests.
	 */
	private static function subscription(CustomerCloudProvision $provision, ?callable $cancel, array &$out): void {
		$item_id = (int)$provision->get('cvp_external_order_item_id');
		if ($item_id <= 0 || !$provision->is_test_purchase()) {
			return;
		}
		$item = new OrderItem($item_id, TRUE);
		if (!$item->key || !$item->get('odi_is_subscription') || $item->get('odi_subscription_cancelled_time')) {
			return;
		}
		try {
			TestMode::on_test_side(function () use ($item, $cancel) {
				if ($cancel !== null) {
					$cancel($item);
					return;
				}
				$sub_id = trim((string)$item->get('odi_stripe_subscription_id'));
				if ($item->get_payment_source() === 'stripe' && $sub_id !== ''
						&& (string)(new StripeHelper())->get_subscription($sub_id)->status === 'canceled') {
					return;
				}
				$item->cancel_subscription_by_system('immediate');
			});
			$item = new OrderItem($item_id, TRUE);
			if (!$item->get('odi_subscription_cancelled_time')) {
				$item->set('odi_subscription_cancelled_time', gmdate('Y-m-d H:i:s'));
				$item->set('odi_subscription_status', 'canceled');
				$item->save();
			}
			$out['done'][] = 'Cancelled the test subscription of order ' . (int)$item->get('odi_ord_order_id') . '.';
		} catch (Throwable $e) {
			$out['failed'][] = 'The test subscription of order ' . (int)$item->get('odi_ord_order_id') . ' was not cancelled: ' . $e->getMessage();
		}
	}
}
