<?php
/**
 * TestSiteDiscard — clears a test site away in one click: its server, its DNS
 * records, its mail subaccount, its backups, its test subscription and its
 * records here.
 *
 * Offered only for a provision whose server is in one of our cloud accounts
 * flagged test. That covers a test-mode purchase and an admin's rehearsal
 * alike. A site in a container on a shared host (a Starter site) is a test
 * site when its HOST is on the Test side of the dashboard, and its account is
 * the host machine's own (or, for a host made by hand, the test account held
 * here). The flag only offers the button. Nothing is deleted until the
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
 *   - the server, at the test account. A site in a container has no server of
 *     its own: its host and the host's other sites are never touched. Its
 *     container is removed by its host through Permanently Delete Site's own
 *     job (the site approves its own removal), asked for here; nothing else
 *     is removed until the host has verified the container gone, because the
 *     approval is given on the site, which needs its name to still reach it.
 *     A site that never stood up has no container to remove;
 *   - in the test zones (TestCloudCleanup::DNS_ZONES), every record at or
 *     under the site's own name, and every A and AAAA record naming the
 *     server's addresses (a container site names no address of its own, so
 *     only the records under its name go: its host's address is shared);
 *   - the mail subaccount at SMTP2GO, closed (its SMTP user and webhook go
 *     with it);
 *   - the site's backups, from every storage space its nodes own;
 *   - a test-mode order's subscription, cancelled at once in Stripe's test
 *     mode (the test charge is left as it is);
 *   - the site's node and, when the server is the site's own Docker host,
 *     the host's node (never a shared host's); with them the provision and
 *     its hosted trial
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
 * is in: that is a customer, not a test. Refused too when a box it would
 * remove is on the Main side of the dashboard: the provision's account and the
 * dashboard's record must agree that the site is a test one.
 *
 * @version 1.3 - a site in a container on a shared host (Starter): a test site by its host's side and account;
 *                its container removed by its host, its host, the host's node and the other sites never touched
 * @version 1.2 - refused when a box it would remove is on the Main side
 * @version 1.1 - each node removal is reported before the notes it caused
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
		if ($provision->is_container()) {
			return self::container_account($provision);
		}
		$account = HeldCloudAccounts::provision_account($provision);
		return ($account !== null && $account->get('oca_is_test')) ? $account : null;
	}

	/** The shared host a container site is placed or reserved on, or null. */
	public static function container_host(CustomerCloudProvision $provision): ?ManagedHost {
		$host_id = (int)$provision->get('cvp_mgh_managed_host_id');
		if ($host_id <= 0) {
			$node_id = (int)$provision->get('cvp_mgn_managed_node_id');
			$node = $node_id > 0 ? new ManagedNode($node_id, TRUE) : null;
			$host_id = ($node !== null && $node->key) ? (int)$node->get('mgn_mgh_managed_host_id') : 0;
		}
		if ($host_id <= 0) {
			return null;
		}
		$host = new ManagedHost($host_id, TRUE);
		return ($host->key && !$host->get('mgh_delete_time')) ? $host : null;
	}

	/**
	 * A container site's test account: its row names no account, because it
	 * has no server of its own. It is a test site when its host is on the
	 * Test side, and the account is the one the host machine's own record
	 * names. A host made by hand has no such record: the test side's boxes
	 * are then taken to be in the test account held here (a usable one
	 * first). A host whose own record names a main account is never a test
	 * host, whatever side it was put on.
	 */
	private static function container_account(CustomerCloudProvision $provision): ?OperatorCloudAccount {
		$host = self::container_host($provision);
		if ($host === null || CloudAccounts::of_host($host) !== CloudAccounts::TEST) {
			return null;
		}
		$host_node = $host->host_node();
		$own = $host_node ? HeldCloudAccounts::node_account($host_node) : null;
		if ($own !== null) {
			return $own->get('oca_is_test') ? $own : null;
		}
		$fallback = null;
		foreach (OperatorCloudAccount::held() as $account) {
			if (!$account->get('oca_is_test')) {
				continue;
			}
			if ($account->usable()) {
				return $account;
			}
			$fallback = $fallback ?? $account;
		}
		return $fallback;
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
			// The provision's account says where its server was made; the
			// dashboard's own record of the box must agree. A record pointing
			// at a main-side box (the management node itself, say) would have
			// its server reported gone at the test account and then the box
			// removed from the dashboard.
			if (CloudAccounts::of_node($node) !== CloudAccounts::TEST) {
				return $node->get('mgn_name') . ' is on the Main side of the dashboard, not the Test side, so it is not a test site.';
			}
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
		// A site in a container on a shared host: the site's node alone. The
		// host, its agent's node and the other sites there are not this site's.
		if ($provision->is_container()) {
			return array(array($site), $others);
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
		if ($provision->is_container()) {
			// Its container first, and nothing else until it is gone: the site
			// approves its own removal on its own pages, which its DNS records
			// and its mail are still needed to reach.
			self::container($provision, $out);
			if ($out['failed']) {
				return $out;
			}
		} else {
			self::server($provision, $driver, $out);
		}
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
			// The site's node is last, and names the host it sat on.
			$host_id = (int)end($nodes)->get('mgn_mgh_managed_host_id');
			foreach ($nodes as $node) {
				try {
					$notes = $node->remove_permanently();
					$out['done'][] = 'Removed ' . $node->get('mgn_name') . ' from the dashboard.';
					foreach ($notes as $note) {
						$out['done'][] = $note;
					}
				} catch (Throwable $e) {
					$out['failed'][] = $node->get('mgn_name') . ' was not removed from the dashboard: ' . $e->getMessage()
						. ' Click Discard again to finish.';
					return $out;
				}
			}
			// Each removal asks the host to retire, but its agent goes first,
			// while the site is still on it, and a host that takes provisions
			// retires only on its agent's removal: so it is asked once more,
			// now that both are gone.
			if (!$provision->is_container() && $host_id > 0 && ManagedHost::retire_if_empty($host_id, true)) {
				$out['done'][] = 'Its Docker host had nothing left on it and was removed from the dashboard too.';
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

	/**
	 * A container site's container, removed by its host. Nothing to do for a
	 * site that was never placed, never stood a site up, or whose host has
	 * verified it gone (NodeRemoval's own rule for removing its record).
	 * Otherwise the host is asked through Permanently Delete Site's job, once,
	 * and the click reports what is still owed: the removal is approved on the
	 * site itself, so this cannot finish in one click.
	 */
	private static function container(CustomerCloudProvision $provision, array &$out): void {
		list($nodes) = self::nodes($provision);
		$site = $nodes ? end($nodes) : null;
		if (!$site || NodeRemoval::container_refusal($site) === '') {
			return;
		}
		$name = trim((string)$site->get('mgn_container_name'));
		$after = ' The site approves its own removal on its Backups page; click Discard again once its host has verified the container gone.';
		try {
			$host_node = JobCommandBuilder::decommission_host_node_for($site);
			$db = DbConnector::get_instance()->get_db_link();
			$q = $db->prepare("SELECT mjb_management_job_id FROM mjb_management_jobs
				WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = 'decommission_node' AND mjb_delete_time IS NULL
				  AND mjb_parameters->>'victim_node_id' = ? AND mjb_status IN ('pending', 'queued', 'running')
				ORDER BY mjb_management_job_id DESC LIMIT 1");
			$q->execute(array((int)$host_node->key, (string)(int)$site->key));
			$open = (int)$q->fetchColumn();
			if ($open > 0) {
				$out['failed'][] = 'Its container ' . $name . ' is being removed by its host (job #' . $open . ').' . $after;
				return;
			}
			$built = JobCommandBuilder::build_decommission_node($site, array());
			$job = ManagementJob::createFromBuild($host_node->key, 'decommission_node', $built,
				array('victim_node_id' => (int)$site->key, 'site' => JobCommandBuilder::decommission_site_name($site)), null);
			// Its agent dies with the container: a deliberate silence.
			$site->set('mgn_agent_quiet_time', gmdate('Y-m-d H:i:s'));
			$site->save();
			$out['failed'][] = 'Its host was asked to remove its container ' . $name . ' (job #' . (int)$job->key . '). Nothing else was removed yet.' . $after;
		} catch (Throwable $e) {
			$out['failed'][] = 'Its container ' . $name . ' could not be removed by its host: ' . $e->getMessage()
				. ' Nothing else was removed. Remove it with Permanently Delete Site on its node\'s page, then click Discard again.';
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
