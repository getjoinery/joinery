<?php
/**
 * ManagedDomainWatch - counts the domain down, and hands it over.
 *
 * The buyer legally owns their domain from the moment it is registered. What
 * still has to move is management and billing: while the name sits in the
 * operator's registrar account, its renewal bills the OPERATOR, and the
 * platform never fronts a renewal. So the domain has to reach the buyer's own
 * account before its first expiry, or it lapses.
 *
 * That deadline is the only reason this class exists, and it shapes every
 * decision in it:
 *
 *  - **Nothing is said for the first six months.** A buyer who just bought
 *    hosting does not need a chore. The first mention of ownership transfer is
 *    a notice on their own box at expiry minus six months, escalating as the
 *    date approaches.
 *  - **The prompts are in-product, not one email.** A single email can be
 *    missed; a notice on the site they administer cannot.
 *  - **There is no renewal call to make.** The registrar seam does not even
 *    expose one. This class watches and informs; the buyer renews in their own
 *    account, on their own card, once custody is theirs.
 *
 * Custody moves by the registrar's own account push, which at Namecheap has no
 * API — so the pipeline queues it as an operator task and this class watches
 * for the domain to leave the account. inAccount() returning false IS the
 * success signal.
 *
 * @version 1.2 - the sweep mark never steps past an order nobody was told about
 * @version 1.1 - sweeps for domain years paid for but never registered
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/domain_registrar/DomainRegistrarRegistry.php'));

class ManagedDomainWatch {

	/** How often an expiry date is worth re-reading from the registrar. */
	const EXPIRY_REFRESH_DAYS = 7;

	/**
	 * How long a paid domain line is given to produce its registration row
	 * before it counts as unclaimed. The row is written in the same request as
	 * the charge, so this only has to outlast a slow one.
	 */
	const ORPHAN_SETTLE_MINUTES = 15;

	/** How many unclaimed lines one tick will look at. */
	const ORPHAN_SWEEP_LIMIT = 100;

	/** @var array Human-readable notes for the run summary. */
	private $notes = array();

	/** @var DomainRegistrarProvider|null */
	private $registrar = null;

	public function run(array $config): array {
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/registered_domains_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
		require_once(PathHelper::getIncludePath('includes/EmailSender.php'));

		if ($this->get_registrar() === null) {
			return array('status' => 'skipped', 'message' => 'No registrar configured');
		}

		$worked = $this->sweep_unclaimed_domain_lines();

		$rows = new MultiRegisteredDomain(array(
			'status'  => RegisteredDomain::STATUS_ACTIVE,
			'deleted' => false,
		));
		$rows->load();
		if (count($rows) === 0 && $worked === 0) {
			return array('status' => 'skipped', 'message' => 'No active managed domains.');
		}

		foreach ($rows as $row) {
			try {
				$worked += $this->watch($row);
			} catch (Throwable $e) {
				$this->notes[] = $row->get('rdm_domain') . ': ' . $e->getMessage();
				error_log('ManagedDomainWatch: ' . $row->get('rdm_domain') . ': ' . $e->getMessage());
			}
		}

		if ($worked === 0 && empty($this->notes)) {
			return array('status' => 'skipped', 'message' => 'Nothing due.');
		}
		return array(
			'status'  => 'success',
			'message' => 'Domain watch: ' . $worked . ' update(s). ' . implode('; ', array_slice($this->notes, 0, 3)),
		);
	}

	/** One row. Returns the number of things that changed. */
	private function watch($row): int {
		$state = (string)$row->get('rdm_graduation_state');
		if ($state === RegisteredDomain::GRAD_SELF) {
			return 0;   // finished; nothing left to watch
		}

		$changed = 0;
		$changed += $this->refresh_expiry($row);
		$changed += $this->check_custody($row);
		$changed += $this->push_prompt($row);
		return $changed;
	}

	// ------------------------------------------------------------------

	/**
	 * Keep the expiry current, at most weekly.
	 *
	 * Weekly rather than every tick because the number moves once a year and
	 * every read spends the operator's registrar API quota — but often enough
	 * that a renewal made in the buyer's own account is noticed long before
	 * the countdown would have expired.
	 */
	private function refresh_expiry($row): int {
		$checked = trim((string)$row->get('rdm_expiry_checked_time'));
		if ($checked !== '') {
			$age = time() - (int)strtotime($checked . ' UTC');
			if ($age < self::EXPIRY_REFRESH_DAYS * 86400) {
				return 0;
			}
		}

		try {
			$expiry = $this->get_registrar()->getExpiry((string)$row->get('rdm_domain'));
		} catch (DomainRegistrarException $e) {
			$this->notes[] = $row->get('rdm_domain') . ': expiry read failed, will retry.';
			return 0;
		}

		$row->set('rdm_expiry_checked_time', gmdate('Y-m-d H:i:s'));
		if ($expiry !== null && $expiry !== (string)$row->get('rdm_expiry_time')) {
			$row->set('rdm_expiry_time', $expiry);
			$row->save();
			$this->pushIfLive($row);
			return 1;
		}
		$row->save();
		return 0;
	}

	/**
	 * Has the domain left the operator's account?
	 *
	 * Only asked once a push is in flight — before that the answer is always
	 * yes and the question costs API quota for nothing.
	 */
	private function check_custody($row): int {
		$state = (string)$row->get('rdm_graduation_state');
		if ($state !== RegisteredDomain::GRAD_REQUESTED && $state !== RegisteredDomain::GRAD_SENT) {
			return 0;
		}

		try {
			$still_ours = $this->get_registrar()->inAccount((string)$row->get('rdm_domain'));
		} catch (DomainRegistrarException $e) {
			$this->notes[] = $row->get('rdm_domain') . ': custody check failed, will retry.';
			return 0;
		}
		if ($still_ours) {
			return 0;
		}

		$row->set('rdm_graduation_state', RegisteredDomain::GRAD_SELF);
		$row->save();
		$this->pushIfLive($row);
		$this->send_self_custody_email($row);
		$this->notes[] = $row->get('rdm_domain') . ' is now in the buyer\'s own account.';
		return 1;
	}

	/**
	 * At six months out, tell the box to start showing the notice.
	 *
	 * This push is the buyer's FIRST mention of graduation anywhere — not the
	 * setup wizard, not the welcome email. Before the threshold the box holds
	 * the domain and expiry but an empty custody state, which renders nothing.
	 */
	private function push_prompt($row): int {
		if ($row->get('rdm_prompt_pushed_time') || !$row->in_prompt_window()) {
			return 0;
		}
		if (!$this->pushIfLive($row)) {
			return 0;   // transient; retried next tick
		}
		$row->set('rdm_prompt_pushed_time', gmdate('Y-m-d H:i:s'));
		$row->save();
		$this->notes[] = $row->get('rdm_domain') . ': take-ownership prompt is now live on the box.';
		return 1;
	}

	// ------------------------------------------------------------------
	// The other direction: paid for, never queued
	// ------------------------------------------------------------------

	/**
	 * Find domain years a buyer paid for that produced no registration.
	 *
	 * The pipeline's other guard runs from the domain row outward and refuses
	 * to register a name the order did not pay for. This is the mirror image,
	 * and it needs its own sweep because there is nothing to run from: a buyer
	 * who removes the HOSTING line from their cart and keeps the domain line
	 * pays for a domain year whose intake never fires, so no row is ever
	 * written and no queue ever shows it. Left alone that is money taken for
	 * nothing, invisible to everyone.
	 *
	 * The signature is arithmetic on the order: more paid domain-year lines
	 * than registration rows. A high-water mark over order-item ids makes the
	 * alert once-only, and the sweep stops at the first line too young to have
	 * settled, so nothing is skipped past.
	 *
	 * Every non-deleted registration row counts, whatever its status — the
	 * question here is only whether the intake ever fired. A row sitting at
	 * pending or parked at failed has already been seen by the pipeline and
	 * has its own queue entry; reporting it again from this side would be a
	 * second voice on a problem somebody is already looking at.
	 *
	 * @return int alerts sent.
	 */
	private function sweep_unclaimed_domain_lines(): int {
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ProvisioningSetup.php'));
		require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));

		$settings = Globalvars::get_instance();
		$product_id = (int)$settings->get_setting('store_domain_registration_product_id', false, true);
		if ($product_id <= 0) {
			return 0;   // the store sells no domain years here
		}

		// Straight from the table, not the request-cached singleton: the mark is
		// advanced by this same method, and a cached read would let a second
		// sweep in one process re-report everything the first one just did.
		$watermark = (int)ProvisioningSetup::readSetting('server_manager_domain_orphan_swept_id');

		try {
			$db = DbConnector::get_instance()->get_db_link();
			// One order at a time, because the question is per-order arithmetic:
			// a cart with two hosting items pays for two domains and must file
			// two rows. Expressed as a join because no model spans the store's
			// order items and this plugin's registrations.
			//
			// The arithmetic assumes an order's domain lines are paid together
			// and therefore age together: paid_lines counts only lines past the
			// watermark, while filed_rows counts every registration on the
			// order, so lines of one order straddling the settle boundary on
			// different ticks would undercount and hide an orphan. True today —
			// one charge, one timestamp — but a partial-payment feature would
			// break it silently, so it is written down rather than assumed.
			$settle = (int)self::ORPHAN_SETTLE_MINUTES;
			$q = $db->prepare(
				"SELECT d.odi_ord_order_id AS order_id,
				        count(*) AS paid_lines,
				        max(d.odi_order_item_id) AS max_item_id,
				        (SELECT count(*) FROM rdm_registered_domains r
				         JOIN odi_order_items p ON p.odi_order_item_id = r.rdm_external_order_item_id
				         WHERE p.odi_ord_order_id = d.odi_ord_order_id
				           AND r.rdm_delete_time IS NULL) AS filed_rows
				 FROM odi_order_items d
				 WHERE d.odi_pro_product_id = :product_id
				   AND d.odi_status = :paid
				   AND d.odi_order_item_id > :watermark
				   AND d.odi_status_change_time < now() - interval '{$settle} minutes'
				 GROUP BY d.odi_ord_order_id
				 ORDER BY max_item_id ASC
				 LIMIT :sweep_limit");
			$q->bindValue(':product_id', $product_id, PDO::PARAM_INT);
			$q->bindValue(':paid', OrderItem::STATUS_PAID, PDO::PARAM_INT);
			$q->bindValue(':watermark', $watermark, PDO::PARAM_INT);
			$q->bindValue(':sweep_limit', self::ORPHAN_SWEEP_LIMIT, PDO::PARAM_INT);
			$q->execute();
			$orders = $q->fetchAll(PDO::FETCH_ASSOC);
		} catch (Throwable $e) {
			error_log('ManagedDomainWatch: unclaimed-line sweep failed: ' . $e->getMessage());
			return 0;
		}

		$alerts = 0;
		$highest = $watermark;
		foreach ($orders as $order) {
			$unclaimed = (int)$order['paid_lines'] - (int)$order['filed_rows'];
			if ($unclaimed > 0 && !$this->alert_unclaimed_order((int)$order['order_id'], $unclaimed)) {
				// The alert did not go — no recipient configured, or the send
				// failed. Stop here WITHOUT advancing: the mark is what makes
				// this report once-only, so stepping over an order nobody was
				// told about would lose it permanently, which is the exact
				// failure this whole sweep exists to prevent. Rows are handled
				// in ascending id order, so stopping leaves this order and
				// everything after it for the next tick, with no duplicates.
				break;
			}
			if ($unclaimed > 0) {
				$alerts++;
			}
			$highest = max($highest, (int)$order['max_item_id']);
		}

		// Advance only past orders that were fully handled. Anything newer was
		// too young to judge, beyond this tick's limit, or left behind by a
		// failed alert — each gets its turn next time.
		if ($highest > $watermark) {
			try {
				Setting::put('server_manager_domain_orphan_swept_id', (string)$highest);
			} catch (Throwable $e) {
				error_log('ManagedDomainWatch: could not advance the sweep watermark: ' . $e->getMessage());
			}
		}
		if ($alerts > 0) {
			$this->notes[] = $alerts . ' order(s) paid for a domain that was never registered.';
		}
		return $alerts;
	}

	/** Tell the operator about one order that paid for a domain it never got. */
	protected function alert_unclaimed_order(int $order_id, int $unclaimed): bool {
		$to = self::resolve_alert_recipient();
		if ($to === '') {
			error_log('ManagedDomainWatch: order ' . $order_id . ' paid for ' . $unclaimed
				. ' unregistered domain(s) and no alert recipient is configured.');
			return false;
		}

		// The name the buyer asked for, if any line on the order recorded one.
		$names = array();
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$q = $db->prepare(
				'SELECT DISTINCT r.oir_answer
				 FROM oir_order_item_requirements r
				 JOIN odi_order_items i ON i.odi_order_item_id = r.oir_odi_order_item_id
				 WHERE i.odi_ord_order_id = ? AND r.oir_label = ?');
			$q->execute(array($order_id, 'Registered domain'));
			$names = $q->fetchAll(PDO::FETCH_COLUMN) ?: array();
		} catch (Throwable $e) {
			error_log('ManagedDomainWatch: could not read the domain name for order '
				. $order_id . ': ' . $e->getMessage());
		}

		$body = "An order paid for a domain registration that never reached the queue.\n\n"
			. 'Order: ' . $order_id . "\n"
			. 'Unregistered domain years paid for: ' . $unclaimed . "\n"
			. 'Name(s) asked for: ' . ($names ? implode(', ', $names) : 'none recorded') . "\n\n"
			. "The usual cause is the hosting line being removed from the cart while the domain\n"
			. "line stayed in it, so nothing ever asked for the registration. Nothing is registered\n"
			. "and nothing will retry on its own: refund the domain line, or register the name and\n"
			. "file the row by hand.\n\n"
			. "This is reported once per order.\n";
		try {
			EmailSender::quickSend($to, '[managed-domain] Paid but never registered: order ' . $order_id, $body);
			return true;
		} catch (Throwable $e) {
			error_log('ManagedDomainWatch: unclaimed-order alert send failed: ' . $e->getMessage());
			return false;
		}
	}

	/** provisioning_admin_alert_email -> webmaster_email -> first superadmin. */
	private static function resolve_alert_recipient(): string {
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionManagedDomains.php'));
		return ProvisionManagedDomains::resolve_alert_recipient();
	}

	// ------------------------------------------------------------------
	// The node banner
	// ------------------------------------------------------------------

	/** Push the row's current state, if a node is linked. */
	protected function pushIfLive($row): bool {
		$node_id = (int)$row->get('rdm_mgn_node_id');
		if ($node_id <= 0) {
			return false;
		}
		$node = new ManagedNode($node_id, TRUE);
		if (!$node->key) {
			return false;
		}
		// Before the six-month threshold the box is given no custody state, so
		// it holds the facts and says nothing.
		$state = ($row->get('rdm_prompt_pushed_time') || $row->in_prompt_window())
			? (string)$row->get('rdm_graduation_state') : '';
		return $this->pushBannerState($row, $node, $state);
	}

	/**
	 * Write the four managed settings onto the node.
	 *
	 * The values are non-secret, so they go in the SQL directly rather than
	 * through the stdin dance the credential seeder needs. The settings are
	 * declared `managed` in core, which keeps them off the node's settings page
	 * — the control plane is their only author.
	 */
	protected function pushBannerState($row, $node, string $state): bool {
		$command = self::buildBannerCommand($row, $node, $state);
		if ($command === '') {
			return false;
		}
		$run = $this->sendToNode($node, $command);
		if (!$run['ok']) {
			error_log('ManagedDomainWatch: banner push to ' . $node->get('mgn_name') . ' failed ('
				. $run['code'] . '): ' . $run['output']);
			return false;
		}
		return true;
	}

	/**
	 * The remote command, built separately so a test can assert what would be
	 * sent without an SSH connection existing.
	 */
	public static function buildBannerCommand($row, $node, string $state): string {
		$sitename = self::sitenameFor($node);
		if ($sitename === '') {
			return '';
		}

		$values = array(
			'managed_domain_name'        => (string)$row->get('rdm_domain'),
			'managed_domain_expiry_time' => (string)$row->get('rdm_expiry_time'),
			'managed_domain_state'       => $state,
			'managed_domain_manage_url'  => self::manageUrl(),
		);
		$tuples = array();
		foreach ($values as $name => $value) {
			$tuples[] = "  ('" . str_replace("'", "''", $name) . "', '"
				. str_replace("'", "''", $value) . "')";
		}
		$upsert = "INSERT INTO stg_settings (stg_name, stg_value) VALUES\n"
			. implode(",\n", $tuples) . "\n"
			. 'ON CONFLICT (stg_name) DO UPDATE SET stg_value = EXCLUDED.stg_value, stg_update_time = now();';

		$extract = 'head -1 | cut -d";" -f1 | cut -d"=" -f2 | tr -d " " | sed "s/^.//;s/.$//"';
		$inner = 'set -e' . "\n"
			. 'CFG=/var/www/html/' . $sitename . "/config/Globalvars_site.php\n"
			. "DB_NAME=\$(grep dbname \$CFG | {$extract})\n"
			. "DB_USER=\$(grep dbusername \$CFG | {$extract})\n"
			. "export PGPASSWORD=\$(grep dbpassword \$CFG | {$extract})\n"
			. "psql -q -U \"\$DB_USER\" -d \"\$DB_NAME\" <<JOINERY_SQL\n"
			. $upsert . "\n"
			. "JOINERY_SQL\n"
			. 'echo DOMAIN_BANNER_PUSHED';

		$container = trim((string)$node->get('mgn_container_name'));
		$ssh_user = (string)$node->get('mgn_ssh_user') ?: 'root';
		$sudo = ($ssh_user !== 'root') ? 'sudo ' : '';
		if ($container !== '') {
			return $sudo . 'docker exec -i ' . escapeshellarg($container)
				. ' bash -c ' . escapeshellarg($inner);
		}
		return $sudo . 'bash -c ' . escapeshellarg($inner);
	}

	/** The site directory name on the node, from its web root or container. */
	private static function sitenameFor($node): string {
		$container = trim((string)$node->get('mgn_container_name'));
		if ($container !== '') {
			return $container;
		}
		$web_root = trim((string)$node->get('mgn_web_root'));
		if ($web_root === '') {
			return '';
		}
		return basename(dirname($web_root));
	}

	/** Where the take-ownership flow lives, on this control plane. */
	public static function manageUrl(): string {
		require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
		return LibraryFunctions::get_absolute_url('/profile/server_manager/domain');
	}

	/** One SSH command against a node. The test seam. */
	protected function sendToNode($node, string $remote_command): array {
		return self::runSsh($node, $remote_command);
	}

	/** One SSH command against a node. */
	private static function runSsh($node, string $remote_command): array {
		$key_path = (string)$node->get('mgn_ssh_key_path');
		$host = (string)$node->get('mgn_host');
		$user = (string)$node->get('mgn_ssh_user') ?: 'root';
		$port = intval($node->get('mgn_ssh_port')) ?: 22;
		if ($key_path === '' || !is_readable($key_path) || $host === '') {
			return array('ok' => false, 'code' => -1,
				'output' => 'Node SSH coordinates incomplete (key: ' . $key_path . ', host: ' . $host . ').');
		}

		$cmd = array('ssh', '-i', $key_path, '-p', (string)$port,
			'-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=accept-new',
			'-o', 'ConnectTimeout=15', $user . '@' . $host, $remote_command);
		$proc = proc_open($cmd, array(
			0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
		if (!is_resource($proc)) {
			return array('ok' => false, 'code' => -1, 'output' => 'Could not start ssh.');
		}
		fclose($pipes[0]);
		$out = (string)stream_get_contents($pipes[1]);
		$err = (string)stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($proc);
		return array('ok' => ($code === 0), 'code' => $code, 'output' => trim($out . "\n" . $err));
	}

	// ------------------------------------------------------------------

	/** Tell the buyer the handover finished, and what they must do next. */
	private function send_self_custody_email($row): void {
		$to = trim((string)$row->get('rdm_buyer_email'));
		if ($to === '') {
			return;
		}
		$domain = (string)$row->get('rdm_domain');
		$expiry = $row->get_local('rdm_expiry_time', 'F j, Y');
		$body = "Your domain is now fully yours.\n\n"
			. $domain . " has moved into your own registrar account. You were always its legal owner;\n"
			. "now you manage and pay for it directly too, and we are out of the loop entirely.\n\n"
			. ($expiry ? "One thing still needs doing: it expires on " . $expiry . ".\n"
				. "Add a payment method in your registrar account and turn on auto-renew, or the name\n"
				. "will lapse on that date. We do not renew it for you.\n\n" : '')
			. "Your website and email keep working exactly as they are — the DNS records moved with\n"
			. "the domain and nothing needs changing.\n";
		try {
			EmailSender::quickSend($to, 'Your domain ' . $domain . ' is now fully yours', $body);
		} catch (Throwable $e) {
			error_log('ManagedDomainWatch: self-custody email to ' . $to . ' failed: ' . $e->getMessage());
		}
	}

	/** The configured registrar, or null. Overridable for tests. */
	protected function get_registrar() {
		if ($this->registrar === null) {
			$this->registrar = DomainRegistrarRegistry::firstConfigured();
		}
		return $this->registrar;
	}
}
