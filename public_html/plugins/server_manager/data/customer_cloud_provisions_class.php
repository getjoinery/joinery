<?php
/**
 * CustomerCloudProvision - One cloud-instance provision, request to running site.
 *
 * Three origins (cvp_origin):
 *   order - created by PollHostingOrders when a paid order's product declares
 *           pro_fulfillment_provider = 'customer_cloud'; carries the order
 *           item linkage that drives the buyer welcome email.
 *   admin - created by the Install New Node form's cloud-instance target;
 *           no order item, no welcome email.
 *   buyer - created by the buyer on the configure page BEFORE they pay
 *           (specs/managed_hosting_phase1_purchase.md). It starts as a draft
 *           and holds nothing — no instance, no domain, no slug — until the
 *           store's fulfilment activates it with the paid order item, at which
 *           point it is an order-shaped row and the pipeline takes it.
 *
 * Install parameters travel on the row (cvp_docker_mode, cvp_install_mode,
 * cvp_source_node_id, cvp_backup_source, cvp_port) and are honored by the
 * ProvisionCustomerCloud scheduled task, which advances every provision.
 *
 * Status flow:
 *   draft           - a buyer's unfinished site setup; editable, expires
 *   pending_payment - the setup is complete and frozen; its cart line was built
 *                     from it, so it must not move under the line. Edit returns
 *                     it to draft (and the cart line, if any, goes stale).
 *   pending_connect - waiting for the buyer's OAuth grant (Connect page)
 *   ready           - grant available; instance not yet created
 *   booting         - instance created on the customer's account; waiting for
 *                     it to reach running + have an IP
 *   installing      - managed node created, install_node job dispatched
 *   done            - install succeeded (SSL + welcome email flow from there
 *                     is the standard pipeline)
 *   failed          - terminal; cvp_error says why. Admin alert sent.
 *
 * Alongside the status, cvp_install_password tracks the one credential a
 * keyless provision ever has (specs/keyless_provisioning.md): held while the
 * machine still accepts it and this row still holds it, retiring while the
 * retire_install_password job runs, retired once neither is true, or
 * retire_failed when the job could not prove the machine refuses it (the
 * password is kept, so the machine stays reachable).
 *
 * @version 1.10 - is_test_purchase() / external_name_prefix(): a site bought with a test-mode payment names
 *                 everything the pipeline creates for it outside the platform with test_ in front
 * @version 1.9 - the buyer origin and the pre-payment states (draft, pending_payment); the draft's
 *                domain source, frozen domain-year quote and sealed registrant block; held_by_live()
 *                for the slug/domain rule at activation; sweep filters on the collection
 * @version 1.8 - cvp_instance_ipv6 + normalize_address()/machine_addresses(): a provision is found by either of its instance's addresses
 * @version 1.7 - dismiss_blockers()/can_dismiss(): a provision holding nothing — no instance, no node,
 *                no mail subaccount, no live install password, no paid order — can be cleared off the
 *                dashboard, and one holding something says what
 * @version 1.6 - hosted tier (specs/hosted_trial_provisioning.md): cvp_hosting_mode chooses whose cloud
 *                account the instance is born on, cvp_admin_pass_sealed carries the buyer's first
 *                admin password until they reveal it once, and the mail leg's SMTP2GO identifiers
 *                and state ride the row beside the compute ones
 * @version 1.5 - cvp_install_password: the install password's lifecycle, held → retiring → retired,
 *                and the 'open' filter that keeps a done provision on the dashboard until it is retired
 * @version 1.4 - cvp_clone_key_sealed (the key a from_backup provision armed its source with) and
 *                cvp_fleet_seed_state (fleet seeding waits for the node's agent to pair)
 * @version 1.3
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class CustomerCloudProvisionException extends SystemBaseException {}

class CustomerCloudProvision extends SystemBase {
	public static $prefix = 'cvp';
	public static $tablename = 'cvp_customer_cloud_provisions';
	public static $pkey_column = 'cvp_customer_cloud_provision_id';

	public static $json_vars = array('cvp_mail_records');

	protected static $foreign_key_actions = array(
		'cvp_usr_user_id'    => array('action' => 'prevent', 'message' => 'this user has cloud provisions - deprovision them first'),
		'cvp_cca_customer_cloud_account_id' => array('action' => 'null'),
		'cvp_mgn_managed_node_id'    => array('action' => 'null'),
	);

	// Admin origin needs no order item, so spec-generated test rows validate;
	// the update test must mutate a free-form field, not a code-enforced enum.
	public static $test_fixture = array(
		'values'       => array('cvp_origin' => 'admin'),
		'update_field' => 'cvp_domain',
	);

	public static $field_specifications = array(
		'cvp_customer_cloud_provision_id'                     => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'cvp_origin'                 => array('type'=>'varchar(10)', 'is_nullable'=>false, 'default'=>'order', 'allowed_values'=>array('order', 'admin', 'buyer')),
		'cvp_external_order_item_id' => array('type'=>'int8', 'unique'=>true),
		'cvp_usr_user_id'            => array('type'=>'int8', 'required'=>true, 'is_nullable'=>false),
		'cvp_domain'                 => array('type'=>'varchar(255)', 'required'=>true, 'is_nullable'=>false),
		'cvp_slug'                   => array('type'=>'varchar(50)', 'required'=>true, 'is_nullable'=>false),
		'cvp_sitename'               => array('type'=>'varchar(50)'),
		'cvp_buyer_email'            => array('type'=>'varchar(255)'),
		'cvp_buyer_name'             => array('type'=>'varchar(255)'),
		'cvp_status'                 => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>'pending_connect'),
		'cvp_cca_customer_cloud_account_id'         => array('type'=>'int8'),
		'cvp_provider'               => array('type'=>'varchar(32)', 'is_nullable'=>false, 'default'=>'linode'),
		'cvp_instance_id'            => array('type'=>'varchar(50)'),
		'cvp_instance_ip'            => array('type'=>'varchar(64)'),
		'cvp_instance_ipv6'          => array('type'=>'varchar(64)'),
		'cvp_region'                 => array('type'=>'varchar(50)'),
		'cvp_instance_type'          => array('type'=>'varchar(50)'),
		'cvp_mgn_managed_node_id'            => array('type'=>'int8'),
		'cvp_docker_mode'            => array('type'=>'varchar(12)', 'is_nullable'=>false, 'default'=>'docker', 'allowed_values'=>array('docker', 'bare-metal')),
		'cvp_install_mode'           => array('type'=>'varchar(12)', 'is_nullable'=>false, 'default'=>'fresh', 'allowed_values'=>array('fresh', 'from_backup', 'bare')),
		'cvp_source_node_id'         => array('type'=>'int8'),
		'cvp_backup_source'          => array('type'=>'varchar(10)'),
		'cvp_port'                   => array('type'=>'int4', 'is_nullable'=>false, 'default'=>8080),
		// The instance's root password, sealed (SecretBox) for the length of the
		// install only. It is the sole credential for a keyless provision — no
		// SSH key is ever placed on a machine we create — and it is erased when
		// the install password is retired, once the agent's join is approved.
		// NULL means either a pre-keyless provision or a provision whose install
		// password has been retired.
		'cvp_root_pass_sealed'       => array('type'=>'text'),
		// The install password's lifecycle. held: the machine accepts it and
		// this row holds it (from the moment it is sealed, through the install,
		// until every agent the install put on the machine has been admitted).
		// retiring: the retire_install_password job is queued or running.
		// retired: the machine refuses it and this row no longer holds it.
		// retire_failed: the job could not prove the machine refuses it, so the
		// password is KEPT and cvp_error says why; re-running the job from its
		// detail page retries. NULL: a provision that predates keyless
		// provisioning, or one that never had an instance to hold a password for.
		'cvp_install_password'       => array('type'=>'varchar(16)', 'allowed_values'=>array('held', 'retiring', 'retired', 'retire_failed')),
		// from_backup: the export key this provision armed its SOURCE with
		// (clone_export_arm), sealed for the length of the provision. The
		// target presents it over HTTPS; the plane disarms the source and
		// erases this when the provision is done. NULL otherwise.
		'cvp_clone_key_sealed'       => array('type'=>'text'),
		// Fleet enrollment seeding (mailbox plugin) travels over the agent
		// channel, so it waits for the node's agent to pair: pending →
		// dispatched → done | failed. NULL means no seeding applies.
		'cvp_fleet_seed_state'       => array('type'=>'varchar(12)', 'allowed_values'=>array('pending', 'dispatched', 'done', 'failed')),
		// Whose cloud account the instance is created on, decided by the
		// PRODUCT and never by the buyer (specs/hosted_trial_provisioning.md
		// §4.1). customer: the buyer granted us access to their own account
		// and the provider bills them. operator: the plane's own token creates
		// it on our account, there is no Connect wait, and the hosted legs —
		// mail, trial, banners — apply.
		'cvp_hosting_mode'           => array('type'=>'varchar(10)', 'is_nullable'=>false, 'default'=>'customer', 'allowed_values'=>array('customer', 'operator')),
		// The site admin account's first password, sealed. Generated on the
		// plane, handed to the install over the bootstrap session's stdin (it
		// never appears in the job's stored steps or its output), and shown to
		// the buyer ONCE on their own sites page — which erases it in the same
		// request and stamps cvp_admin_pass_revealed_time. The site forces a
		// password change at first login, and once the mail leg is done the
		// site's own forgot-password covers a lost reveal.
		'cvp_admin_pass_sealed'      => array('type'=>'text'),
		'cvp_admin_pass_revealed_time' => array('type'=>'timestamp(6)'),
		// The hosted mail leg's provider-side identifiers (SMTP2GO), and where
		// the leg stands. NULL state means the leg does not apply (a
		// customer-cloud provision, or one whose mail is not ours to set up).
		'cvp_smtp2go_subaccount_id'  => array('type'=>'varchar(64)'),
		'cvp_smtp2go_domain_id'      => array('type'=>'varchar(64)'),
		'cvp_smtp2go_user_id'        => array('type'=>'varchar(64)'),
		'cvp_mail_state'             => array('type'=>'varchar(24)', 'allowed_values'=>array(
			'pending', 'subaccount_created', 'domain_added', 'records_published',
			'domain_verified', 'smtp_user_created', 'done', 'failed')),
		// The DNS records the sending domain needs, as the provider described
		// them. Kept on the row because they outlive the call that produced
		// them: where the plane does not hold the zone, these are what the
		// customer (or the operator) has to publish, and the leg says so
		// instead of pretending the domain is set up.
		'cvp_mail_records'           => array('type'=>'jsonb'),
		'cvp_mail_error'             => array('type'=>'text'),
		// The buyer-origin draft (specs/managed_hosting_phase1_purchase.md §5.1).
		// own: the buyer brings a name they hold; the welcome email carries the
		// A-record instruction. register: this plane buys the name for them
		// after payment, from the quote frozen here at configure time and the
		// registrant block sealed here. NULL on rows from the other origins.
		'cvp_domain_source'          => array('type'=>'varchar(10)', 'allowed_values'=>array('own', 'register')),
		'cvp_domain_quote'           => array('type'=>'numeric(10,2)'),
		'cvp_domain_quote_time'      => array('type'=>'timestamp(6)'),
		'cvp_registrant_sealed'      => array('type'=>'text'),
		'cvp_error'                  => array('type'=>'text'),
		'cvp_create_time'            => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'cvp_update_time'            => array('type'=>'timestamp(6)'),
		'cvp_delete_time'            => array('type'=>'timestamp(6)'),
	);

	function prepare() {
		$this->validate_row();
		$this->set('cvp_update_time', gmdate('Y-m-d H:i:s'));
	}

	// Validation lives in save(): prepare() is not guaranteed to run first.
	function save($debug = false) {
		$this->validate_row();
		$this->set('cvp_update_time', gmdate('Y-m-d H:i:s'));
		return parent::save($debug);
	}

	private function validate_row() {
		$origin = $this->get('cvp_origin') ?: 'order';
		if (!in_array($origin, array('order', 'admin', 'buyer'), true)) {
			throw new CustomerCloudProvisionException("Unknown origin '{$origin}'.");
		}
		if ($origin === 'order' && empty($this->get('cvp_external_order_item_id'))) {
			throw new CustomerCloudProvisionException('Order item id is required for order-origin provisions.');
		}
		$status = (string)($this->get('cvp_status') ?: 'pending_connect');
		if ($origin === 'buyer' && !$this->is_pre_payment() && empty($this->get('cvp_external_order_item_id'))) {
			// A buyer's row leaves the pre-payment states only by activation,
			// and activation is what stamps the paid order item. A buyer row at
			// ready with no order item is a site about to be built for free.
			throw new CustomerCloudProvisionException(
				"A buyer-origin provision at '{$status}' must carry the paid order item.");
		}
		if ($origin !== 'buyer' && $this->is_pre_payment()) {
			throw new CustomerCloudProvisionException("Only a buyer-origin provision may be at '{$status}'.");
		}
		$domain_source = (string)$this->get('cvp_domain_source');
		if ($domain_source !== '' && !in_array($domain_source, array('own', 'register'), true)) {
			throw new CustomerCloudProvisionException("Unknown domain source '{$domain_source}'.");
		}
		$docker_mode = $this->get('cvp_docker_mode') ?: 'docker';
		if (!in_array($docker_mode, array('docker', 'bare-metal'), true)) {
			throw new CustomerCloudProvisionException("Unknown docker mode '{$docker_mode}'.");
		}
		$install_mode = $this->get('cvp_install_mode') ?: 'fresh';
		if (!in_array($install_mode, array('fresh', 'from_backup', 'bare'), true)) {
			throw new CustomerCloudProvisionException("Unknown install mode '{$install_mode}'.");
		}
		if ($install_mode === 'from_backup' && empty($this->get('cvp_source_node_id'))) {
			throw new CustomerCloudProvisionException('Source node is required for from-backup provisions.');
		}
		// A bare instance (no site install) has no order to fulfill — it exists
		// for infrastructure roles like relay shards, which only admins create.
		if ($install_mode === 'bare' && $origin !== 'admin') {
			throw new CustomerCloudProvisionException('Bare provisions must be admin-origin.');
		}
		$hosting_mode = $this->get('cvp_hosting_mode') ?: 'customer';
		if (!in_array($hosting_mode, array('customer', 'operator'), true)) {
			throw new CustomerCloudProvisionException("Unknown hosting mode '{$hosting_mode}'.");
		}
		if (empty($this->get('cvp_usr_user_id'))) {
			throw new CustomerCloudProvisionException('User is required.');
		}
		if (empty($this->get('cvp_domain'))) {
			throw new CustomerCloudProvisionException('Domain is required.');
		}
	}

	/** The provision statuses still working toward a running site, or stuck. */
	const OPEN_STATUSES = array('pending_connect', 'ready', 'booting', 'installing', 'failed');

	/**
	 * The states a buyer's row passes through before any money moves. A row in
	 * one of these holds nothing — no instance, no domain, no slug — and is the
	 * buyer's to edit or delete; nothing in the pipeline can see it.
	 */
	const PRE_PAYMENT_STATUSES = array('draft', 'pending_payment');

	/** Is this row still before payment (draft or frozen for the cart)? */
	public function is_pre_payment(): bool {
		return in_array((string)$this->get('cvp_status'), self::PRE_PAYMENT_STATUSES, true);
	}

	/**
	 * Is this domain or slug already held by a provision that is past payment?
	 *
	 * Any status from ready on counts, failed included: a failed provision may
	 * still hold an instance under that slug, and only a person can say
	 * otherwise. Drafts never hold anything, so two buyers may draft the same
	 * name and the second to pay is the one refused here — at activation, where
	 * the paid order gets an alert and a person, never silently.
	 */
	public static function held_by_live(string $domain, string $slug, int $except_id = 0): ?CustomerCloudProvision {
		$domain = strtolower(trim($domain));
		$slug = strtolower(trim($slug));
		foreach (array('domain' => $domain, 'slug' => $slug) as $option => $value) {
			if ($value === '') {
				continue;
			}
			$rows = new MultiCustomerCloudProvision(array(
				$option => $value, 'past_payment' => true, 'deleted' => false,
			), array('cvp_customer_cloud_provision_id' => 'DESC'));
			foreach ($rows as $row) {
				if ((int)$row->key !== $except_id) {
					return $row;
				}
			}
		}
		return null;
	}

	// ------------------------------------------------------------------
	// The draft's registrant snapshot (register source only)
	// ------------------------------------------------------------------

	/**
	 * Hold the contact block the domain will be registered with, sealed. It is
	 * a home address and phone number, kept only until activation copies it to
	 * the registration row. Same zero-config tolerance as RegisteredDomain:
	 * readable JSON where no secret_box_key exists.
	 */
	public function seal_registrant(array $contact): void {
		$json = json_encode($contact);
		try {
			$box = new SecretBox();
		} catch (\Throwable $e) {
			$this->set('cvp_registrant_sealed', $json);
			return;
		}
		$this->set('cvp_registrant_sealed',
			json_encode(array('enc' => $box->seal('cvp_customer_cloud_provisions.cvp_registrant_sealed', $json))));
	}

	/** The stored contact block, or null when there is none / it cannot be read. */
	public function open_registrant(): ?array {
		$stored = trim((string)$this->get('cvp_registrant_sealed'));
		if ($stored === '') {
			return null;
		}
		$decoded = json_decode($stored, true);
		if (!is_array($decoded)) {
			return null;
		}
		if (isset($decoded['enc']) && is_string($decoded['enc']) && SecretBox::looksEncrypted($decoded['enc'])) {
			$opened = (new SecretBox())->open($decoded['enc']);
			if ($opened['value'] === null) {
				return null;
			}
			$inner = json_decode($opened['value'], true);
			return is_array($inner) ? $inner : null;
		}
		return $decoded;
	}

	/**
	 * Every stored registrant blob, for the sealed-secret reconciler — the
	 * column is a JSON {"enc":"<blob>"} envelope, which this unwraps.
	 *
	 * @return array<array{ref:string, blob:?string}>
	 */
	public static function eachRegistrantBlob(): array {
		$out = array();
		$rows = new MultiCustomerCloudProvision(array('deleted' => false, 'has_registrant' => true));
		foreach ($rows as $row) {
			$decoded = json_decode((string)$row->get('cvp_registrant_sealed'), true);
			$blob = (is_array($decoded) && isset($decoded['enc']) && is_string($decoded['enc'])
				&& SecretBox::looksEncrypted($decoded['enc'])) ? $decoded['enc'] : null;
			$out[] = array('ref' => 'provision #' . $row->key . ' ' . $row->get('cvp_domain'), 'blob' => $blob);
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Test purchases name what they create
	// ------------------------------------------------------------------

	/** What every external thing a test purchase creates is named with, in front. */
	const TEST_NAME_PREFIX = 'test_';

	/** @var ?bool memo of is_test_purchase() for this row */
	private $test_purchase = null;

	/**
	 * Was this site bought with a test-mode payment? Then everything the
	 * pipeline creates for it outside this platform — the cloud instance, the
	 * mail subaccount and its SMTP user, the node's own name — is named with
	 * TEST_NAME_PREFIX in front, so a rehearsal's leftovers can be told from a
	 * customer's at a glance in every provider's console and never mistaken
	 * for something to keep. Decided by the paid order's own test flag; a row
	 * with no order (an admin's install) is not a test purchase.
	 */
	public function is_test_purchase(): bool {
		if ($this->test_purchase !== null) {
			return $this->test_purchase;
		}
		$this->test_purchase = false;
		$item_id = (int)$this->get('cvp_external_order_item_id');
		if ($item_id > 0 && class_exists('OrderItem')) {
			try {
				$item = new OrderItem($item_id, TRUE);
				$order_id = $item->key ? (int)$item->get('odi_ord_order_id') : 0;
				if ($order_id > 0) {
					$order = new Order($order_id, TRUE);
					$this->test_purchase = $order->key && (bool)$order->get('ord_test_mode');
				}
			} catch (\Throwable $e) {
				error_log('CustomerCloudProvision: could not read the order behind provision #' . $this->key
					. ' for its test flag: ' . $e->getMessage());
			}
		}
		return $this->test_purchase;
	}

	/** TEST_NAME_PREFIX for a test purchase, '' otherwise: put it in front of every external name. */
	public function external_name_prefix(): string {
		return $this->is_test_purchase() ? self::TEST_NAME_PREFIX : '';
	}

	/** The install-password states in which the plane still holds a password. */
	const PASSWORD_HELD_STATES = array('held', 'retiring', 'retire_failed');

	/**
	 * The provision whose instance lives at this address, or null. Used when a
	 * join request arrives: a request from a provision's own instance address
	 * is that machine asking to be managed, and approval can check the claim
	 * against the provider. Newest first, so a re-provision of the same
	 * address names the current row.
	 */
	/**
	 * One spelling for an address: IPv6 in its compressed lowercase form so a
	 * source address and a provider report compare as strings; IPv4 as given.
	 */
	public static function normalize_address(string $ip): string {
		$ip = trim($ip);
		if ($ip === '') {
			return '';
		}
		$packed = @inet_pton($ip);
		return $packed === false ? $ip : (string)inet_ntop($packed);
	}

	/** Every address the provider has reported for this instance, normalized. */
	public function machine_addresses(): array {
		$out = [];
		foreach (['cvp_instance_ip', 'cvp_instance_ipv6'] as $col) {
			$a = self::normalize_address((string)$this->get($col));
			if ($a !== '') {
				$out[] = $a;
			}
		}
		return $out;
	}

	public static function for_machine_address(string $ip) {
		$ip = self::normalize_address($ip);
		if ($ip === '') {
			return null;
		}
		// A dual-stack machine reaches the plane over whichever family routes;
		// the instance is known by both its IPv4 and its IPv6.
		$filter = (strpos($ip, ':') !== false)
			? ['cvp_instance_ipv6' => $ip, 'deleted' => false]
			: ['instance_ip' => $ip, 'deleted' => false];
		$rows = new MultiCustomerCloudProvision($filter, ['cvp_customer_cloud_provision_id' => 'DESC']);
		foreach ($rows as $row) {
			if (trim((string)$row->get('cvp_instance_id')) !== '') {
				return $row;
			}
		}
		return null;
	}

	/** Is this provision's instance created on the operator's own account? */
	public function is_operator_hosted(): bool {
		return ($this->get('cvp_hosting_mode') ?: 'customer') === 'operator';
	}

	/**
	 * Where the buyer's first admin password stands:
	 *   none      never minted (a bare instance, or a provision that predates it)
	 *   sealed    minted and still readable — the buyer can reveal it once
	 *   revealed  shown once and erased; the site's forgot-password is the way back
	 */
	public function admin_password_state(): string {
		if (trim((string)$this->get('cvp_admin_pass_sealed')) !== '') {
			return 'sealed';
		}
		return $this->get('cvp_admin_pass_revealed_time') ? 'revealed' : 'none';
	}

	/**
	 * The newest live provision for a node, or null. The install password, the
	 * admin password and the hosting mode all live on the provision rather than
	 * the node, so anything working on a node's install has to find its row.
	 */
	public static function latest_for_node($node_id) {
		$node_id = (int)$node_id;
		if (!$node_id) {
			return null;
		}
		$rows = new MultiCustomerCloudProvision(['node_id' => $node_id, 'deleted' => false], ['cvp_customer_cloud_provision_id' => 'DESC']);
		foreach ($rows as $row) {
			return $row;
		}
		return null;
	}

	/**
	 * Does this node's provision still seal an admin password the install can
	 * be handed? A retry after the buyer revealed it installs without one.
	 */
	public static function holds_admin_password($node_id): bool {
		$provision = self::latest_for_node($node_id);
		return $provision !== null && $provision->admin_password_state() === 'sealed';
	}

	/** The statuses a provision can be cleared off the board in. */
	const DISMISSIBLE_STATUSES = array('failed', 'pending_connect');

	/**
	 * Why this provision cannot be cleared off the board, or an empty array if
	 * it can be.
	 *
	 * This row is the only record the plane keeps of anything the provision
	 * brought into existence: an instance running on someone's cloud account,
	 * a node, a mail subaccount, a password a machine still accepts. Hiding
	 * the row would leave that thing real, still billed, and on no page — so
	 * a provision is dismissible exactly when it holds none of them. What is
	 * left then is a note about something that did not happen, and clearing it
	 * should not take hand-written SQL.
	 *
	 * The reasons are the message. A caller shows them instead of a bare
	 * refusal, so an operator can see what has to be dealt with first.
	 */
	public function dismiss_blockers(): array {
		$blockers = array();

		$status = (string)$this->get('cvp_status');
		if (!in_array($status, self::DISMISSIBLE_STATUSES, true)) {
			$blockers[] = ($status === 'done')
				? 'it succeeded, and a provision that built a site keeps its record'
				: "it is still working (status '{$status}') — wait for it to finish or fail";
		}
		if (trim((string)$this->get('cvp_instance_id')) !== '') {
			$blockers[] = 'an instance was created on a cloud account and is still billed — deprovision it first';
		}
		if ((int)$this->get('cvp_mgn_managed_node_id')) {
			$blockers[] = 'it has a managed node — remove the node instead';
		}
		if (trim((string)$this->get('cvp_smtp2go_subaccount_id')) !== '') {
			$blockers[] = 'it created a mail subaccount that is still live';
		}
		if (in_array((string)$this->get('cvp_install_password'), self::PASSWORD_HELD_STATES, true)) {
			$blockers[] = 'this plane still holds an install password the machine accepts';
		}
		if ((int)$this->get('cvp_external_order_item_id')) {
			$blockers[] = 'a paid order is waiting on it';
		}

		return $blockers;
	}

	/** Can this provision be cleared off the board? See dismiss_blockers(). */
	public function can_dismiss(): bool {
		return count($this->dismiss_blockers()) === 0;
	}

	/**
	 * Record a terminal failure. Saves.
	 */
	public function fail($message) {
		$this->set('cvp_status', 'failed');
		$this->set('cvp_error', mb_substr((string)$message, 0, 4000));
		$this->save();
	}
}

class MultiCustomerCloudProvision extends SystemMultiBase {
	protected static $model_class = 'CustomerCloudProvision';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['user_id'])) {
			$filters['cvp_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['status'])) {
			$filters['cvp_status'] = [$this->options['status'], PDO::PARAM_STR];
		}

		if (isset($this->options['statuses']) && is_array($this->options['statuses']) && count($this->options['statuses'])) {
			$quoted = array_map(function ($s) {
				return "'" . preg_replace('/[^a-z_]/', '', $s) . "'";
			}, $this->options['statuses']);
			$filters['cvp_status'] = "IN (" . implode(',', $quoted) . ")";
		}

		if (isset($this->options['external_order_item_id'])) {
			$filters['cvp_external_order_item_id'] = [$this->options['external_order_item_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['account_id'])) {
			$filters['cvp_cca_customer_cloud_account_id'] = [$this->options['account_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['node_id'])) {
			$filters['cvp_mgn_managed_node_id'] = [$this->options['node_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['fleet_seed_states']) && is_array($this->options['fleet_seed_states']) && count($this->options['fleet_seed_states'])) {
			$quoted = array_map(function ($s) {
				return "'" . preg_replace('/[^a-z_]/', '', $s) . "'";
			}, $this->options['fleet_seed_states']);
			$filters['cvp_fleet_seed_state'] = "IN (" . implode(',', $quoted) . ")";
		}

		if (isset($this->options['install_password_states']) && is_array($this->options['install_password_states']) && count($this->options['install_password_states'])) {
			$quoted = array_map(function ($s) {
				return "'" . preg_replace('/[^a-z_]/', '', $s) . "'";
			}, $this->options['install_password_states']);
			$filters['cvp_install_password'] = "IN (" . implode(',', $quoted) . ")";
		}

		if (isset($this->options['hosting_mode'])) {
			$filters['cvp_hosting_mode'] = [$this->options['hosting_mode'], PDO::PARAM_STR];
		}

		if (isset($this->options['mail_states']) && is_array($this->options['mail_states']) && count($this->options['mail_states'])) {
			$quoted = array_map(function ($s) {
				return "'" . preg_replace('/[^a-z_]/', '', $s) . "'";
			}, $this->options['mail_states']);
			$filters['cvp_mail_state'] = "IN (" . implode(',', $quoted) . ")";
		}

		if (isset($this->options['instance_ip'])) {
			$filters['cvp_instance_ip'] = [$this->options['instance_ip'], PDO::PARAM_STR];
		}

		if (isset($this->options['origin'])) {
			$filters['cvp_origin'] = [$this->options['origin'], PDO::PARAM_STR];
		}

		if (isset($this->options['domain'])) {
			$filters['cvp_domain'] = [strtolower(trim((string)$this->options['domain'])), PDO::PARAM_STR];
		}

		if (isset($this->options['slug'])) {
			$filters['cvp_slug'] = [strtolower(trim((string)$this->options['slug'])), PDO::PARAM_STR];
		}

		// Rows that are past payment: everything but the buyer's pre-payment
		// states. The complement of the draft sweep's view of the table.
		if (!empty($this->options['past_payment'])) {
			$pre = "'" . implode("','", CustomerCloudProvision::PRE_PAYMENT_STATUSES) . "'";
			$filters['cvp_status'] = "NOT IN ({$pre})";
		}

		if (!empty($this->options['has_registrant'])) {
			$filters['cvp_registrant_sealed'] = "IS NOT NULL";
		}

		// The draft sweep: rows whose last change is older than a UTC timestamp
		// (gmdate('Y-m-d H:i:s')). The value is validated to that shape here, so
		// the literal condition below can never carry anything else.
		if (isset($this->options['updated_before'])) {
			$before = (string)$this->options['updated_before'];
			if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $before)) {
				throw new CustomerCloudProvisionException('updated_before must be a Y-m-d H:i:s UTC timestamp.');
			}
			$filters['cvp_update_time'] = "< '" . $before . "'";
		}

		// Everything an operator still has a reason to watch: a provision working
		// toward a site (or stuck), and a finished one whose install password
		// this plane still holds.
		if (!empty($this->options['open'])) {
			$statuses = "'" . implode("','", CustomerCloudProvision::OPEN_STATUSES) . "'";
			$held = "'" . implode("','", CustomerCloudProvision::PASSWORD_HELD_STATES) . "'";
			$filters['(cvp_status'] = "IN ({$statuses}) OR cvp_install_password IN ({$held}))";
		}


		return $this->_get_resultsv2('cvp_customer_cloud_provisions', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
