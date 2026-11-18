<?php
/**
 * SmHosting — whether this management node sells hosting.
 *
 * Selling is what the hosting pages are for: provisioning sites for buyers,
 * registering their domains, renting mail and backup storage to other
 * people's sites, handing a sold site to its buyer's own cloud account, and
 * the buyer's own pages. A management node that runs only its owner's servers
 * never needs any of them: selling hosting with Joinery is getjoinery's own business.
 *
 * On when the server_manager_offer_hosting setting is on, or when this site
 * already sells: a hosting order or buyer provision, a registered domain, a
 * service tenant, a transfer or a hosted trial is on record. A provision made
 * by an admin (a site copy, an adoption, an install) is not selling and does
 * not count. Every hosting page asks here, so they appear and disappear
 * together; the menus follow the setting.
 *
 * @version 1.10 - support_lines(): a buyer's sites as the support desk is told them (walkthrough Q25)
 * @version 1.9 - DNS_WAIT_SECONDS: how long a buyer's DNS list is held for the site's own records (walkthrough Q16)
 * @version 1.8 - registrar_verify_line(): a buyer whose domain we register is told to expect the registrar's one email (walkthrough Q15)
 * @version 1.7 - BUILD_TIME: the one figure for how long a build takes
 * @version 1.6 - dashboard_section(): a Your sites panel on the member Dashboard (walkthrough Q8)
 * @version 1.5 - email_unconfirmed(): a buyer who can pay but not sign in again is told so (walkthrough Q6)
 * @version 1.4 - site_told(): a built site is Ready to its buyer once it has been told what it comes with (walkthrough Q4)
 * @version 1.3 - buyer_mail_sender(): the hosting emails' From, the site's own default sender when no welcome address is set
 * @version 1.2 - support_email() falls back to the site's own From address, never ours (B25)
 * @version 1.1 - support_email(): where a hosting buyer writes to a person (hosting_after_purchase WP2)
 * @version 1.0
 */
class SmHosting {
	const SETTING = 'server_manager_offer_hosting';

	/**
	 * How long a site takes from payment (or from connecting Linode) to
	 * ready, as every page and email says it: one figure, timed on real
	 * builds, with a little slack (walkthrough Q14).
	 */
	const BUILD_TIME = 'about 15 minutes';

	/**
	 * What a buyer whose domain we register is told about the one email the
	 * registrar sends them. The domain is theirs, so the registrar must
	 * confirm their address and suspends a name left unconfirmed; the email
	 * comes from a company they have not heard of from us (walkthrough Q15).
	 */
	public static function registrar_verify_line(): string {
		$registrar = class_exists('ManagedDomainIntake') ? ManagedDomainIntake::registrar() : null;
		$name = $registrar ? htmlspecialchars((string)$registrar::getLabel(), ENT_QUOTES, 'UTF-8') : '';
		return ($name !== '' ? 'Our domain registrar, ' . $name . ',' : 'Our domain registrar')
			. ' will email you once to confirm your address. Click its link within 15 days to keep your domain.';
	}

	/**
	 * How long after its install a site on its buyer's own domain is waited on
	 * to say what the domain needs before the DNS list is shown without it
	 * (ProvisionBuyerDns).
	 */
	const DNS_WAIT_SECONDS = 1800;

	/** How long a built site is waited on to take word of what it comes with before it is shown Ready anyway. */
	const TOLD_WAIT_SECONDS = 900;

	/**
	 * Whether a built site has been told what it comes with, so its own pages
	 * are right before its owner is sent to it. A site learns that its email
	 * and backups are included from the management node, a minute or two after
	 * the install ends; an owner who signs in sooner is asked for a mail
	 * provider or backup storage they already paid for. So "Ready", and the
	 * password, wait for it:
	 *
	 *  - a hosted site: its hosting banner has landed (htr_pushed_digest);
	 *  - an own-Linode site with the bundle: the node took a services notice;
	 *  - any other site: there is nothing to tell.
	 *
	 * Never for ever: a site whose word has not landed TOLD_WAIT_SECONDS after
	 * its install ended is shown Ready, and its pages say what they can.
	 */
	public static function site_told(CustomerCloudProvision $provision): bool {
		if ((string)$provision->get('cvp_status') !== 'done') {
			return false;
		}
		$hosted = $provision->is_operator_hosted();
		$bundle = !$hosted && (int)$provision->get('cvp_services_order_item_id') > 0;
		if (!$hosted && !$bundle) {
			return true;
		}
		$node_id = (int)$provision->get('cvp_mgn_managed_node_id');
		if ($node_id <= 0) {
			return true;
		}
		if ($hosted) {
			$trial = HostedTrial::for_provision((int)$provision->key);
			if ($trial !== null && trim((string)$trial->get('htr_pushed_digest')) !== '') {
				return true;
			}
		} else {
			$notice = ManagementJob::latestForNode($node_id, 'hosted_plan_notice');
			if ($notice && (string)$notice->get('mjb_status') === 'completed') {
				return true;
			}
		}
		$install = ManagementJob::latestForNode($node_id, 'install_node');
		$ended = $install ? trim((string)$install->get('mjb_completed_time')) : '';
		return $ended === '' || (time() - strtotime($ended . ' UTC')) > self::TOLD_WAIT_SECONDS;
	}

	/** The Dashboard's words for where a site stands. */
	const DASHBOARD_STATES = array(
		'draft'           => 'Not finished',
		'pending_payment' => 'Not paid for yet',
		'pending_connect' => 'Waiting for your Linode connection',
		'ready'           => 'Being built',
		'booting'         => 'Being built',
		'installing'      => 'Being built',
		'done'            => 'Ready',
		'failed'          => 'Needs attention',
		'held'            => 'Being checked by a person',
	);

	/**
	 * The Dashboard's "Your sites" panel: each site this account bought or is
	 * setting up, where it stands, and the way to Your Sites. A buyer who
	 * comes back and signs in lands on the Dashboard, which otherwise shows
	 * their orders and nothing about the site those paid for. Null (no panel)
	 * for an account with no site.
	 */
	public static function dashboard_section($user): ?ProfileDashboardSection {
		if (!$user || !$user->key || !Globalvars::get_instance()->get_setting('server_manager_offer_hosting')) {
			return null;
		}
		$rows = new MultiCustomerCloudProvision(array('user_id' => (int)$user->key, 'deleted' => false),
			array('cvp_customer_cloud_provision_id' => 'DESC'));
		$items = array();
		$count = 0;
		foreach ($rows as $row) {
			$count++;
			if (count($items) >= 5) {
				continue;
			}
			$status = (string)$row->get('cvp_status');
			if ($status === 'done' && !self::site_told($row)) {
				$status = 'installing';
			}
			$words = self::DASHBOARD_STATES[$status] ?? $status;
			$domain = (string)$row->get('cvp_domain');
			$items[] = new ProfileDashboardItem(
				array('domain' => $domain, 'state' => $words),
				$domain, $words, null, null, ManagedSiteDraft::SITES_URL);
		}
		if (!$items) {
			return null;
		}
		return new ProfileDashboardSection('your_sites', 'Your sites', ManagedSiteDraft::SITES_URL,
			new ProfileDashboardStat('your_sites', $count === 1 ? 'Site' : 'Sites', $count, ManagedSiteDraft::SITES_URL),
			$items);
	}

	/**
	 * Whether this buyer must still confirm their email address before they
	 * can sign in again. Sign-up signs a buyer in at once, so they can set a
	 * site up and pay without opening the confirmation email; where the site
	 * requires a confirmed address to sign in, their next visit is refused,
	 * and Your Sites is where the password is shown once. They are told while
	 * they are still signed in.
	 */
	public static function email_unconfirmed(?User $user): bool {
		if ($user === null || !$user->key || trim((string)$user->get('usr_email')) === '') {
			return false;
		}
		return (bool)Globalvars::get_instance()->get_setting('activation_required_login')
			&& !$user->get('usr_is_activated');
	}

	/** What an unconfirmed buyer is told: HTML. */
	public static function email_unconfirmed_line(User $user): string {
		return 'Confirm your email address to be able to sign in again. We sent a link to <strong>'
			. htmlspecialchars((string)$user->get('usr_email'), ENT_QUOTES, 'UTF-8')
			. '</strong>; until you open it, this site will not let you back in once you sign out.';
	}

	/**
	 * Who the hosting emails to a buyer are from: array(address, name). The
	 * welcome address when one is set, else the site's own default sender,
	 * which its mail provider is set up for. Never a fixed address of ours:
	 * another deployment's provider refuses it, and the email is never sent.
	 */
	public static function buyer_mail_sender(): array {
		$settings = Globalvars::get_instance();
		$email = trim((string)$settings->get_setting('server_manager_provisioning_welcome_from_email'))
			?: trim((string)$settings->get_setting('defaultemail'));
		$name = trim((string)$settings->get_setting('server_manager_provisioning_welcome_from_name'))
			?: trim((string)$settings->get_setting('defaultemailname'));
		return array($email !== '' ? $email : 'support@getjoinery.com', $name !== '' ? $name : 'Get Joinery Support');
	}

	/**
	 * Where a hosting buyer writes to a person: the Support email address
	 * setting, else the welcome email's From address.
	 */
	public static function support_email(): string {
		$settings = Globalvars::get_instance();
		// Then the site's own From address: another operator's buyers are
		// never sent to us (hosting_after_purchase B25).
		foreach (array('server_manager_support_email', 'server_manager_provisioning_welcome_from_email', 'defaultemail') as $name) {
			$value = trim((string)$settings->get_setting($name, false, true));
			if ($value !== '') {
				return $value;
			}
		}
		return '';
	}

	/** @var bool|null The in-use answer for this request. */
	private static $in_use = null;

	public static function enabled(): bool {
		try {
			if (Globalvars::get_instance()->get_setting(self::SETTING)) return true;
		} catch (Throwable $e) {
			// Settings unreadable: fall through to what is on record.
		}
		return self::in_use();
	}

	/** Whether anything sold is on record here. */
	public static function in_use(): bool {
		if (self::$in_use !== null) return self::$in_use;
		$checks = array(
			"SELECT 1 FROM cvp_customer_cloud_provisions WHERE cvp_origin IN ('order', 'buyer') LIMIT 1",
			'SELECT 1 FROM rdm_registered_domains LIMIT 1',
			'SELECT 1 FROM svt_service_tenants LIMIT 1',
			'SELECT 1 FROM itx_instance_transfers LIMIT 1',
			'SELECT 1 FROM htr_hosted_trials LIMIT 1',
		);
		$db = DbConnector::get_instance()->get_db_link();
		foreach ($checks as $sql) {
			try {
				$q = $db->query($sql);
				if ($q !== false && $q->fetchColumn() !== false) {
					return self::$in_use = true;
				}
			} catch (Throwable $e) {
				// A table not created yet holds nothing sold.
			}
		}
		return self::$in_use = false;
	}

	/** The sentence an admin hosting page gives where this site does not sell hosting. */
	public static function off_text(): string {
		return 'This management node runs its own servers and does not sell hosting, so this page has nothing '
			. 'to show. Selling hosting is turned on with the "This management node sells hosting" setting.';
	}

	/** An admin hosting page opened where this site does not sell hosting. */
	public static function render_off_page($session, string $title): void {
		$page = new AdminPage();
		$page->admin_header([
			'menu-id' => 'server-manager',
			'page_title' => $title,
			'readable_title' => $title,
			'breadcrumbs' => ['Server Manager' => '/admin/server_manager', $title => ''],
			'session' => $session,
		]);
		$page->begin_box(['title' => 'Not used here']);
		echo '<p class="mb-0">' . htmlspecialchars(self::off_text()) . '</p>';
		$page->end_box();
		$page->admin_footer();
	}

	/** A buyer's or public hosting page where this site does not sell hosting: it does not exist. */
	public static function not_found_unless_enabled(): void {
		if (!self::enabled()) {
			LibraryFunctions::display_404_page();
			exit();
		}
	}

	/**
	 * What this node records about a buyer's sites, one line each, for the
	 * support desk's assistant and whoever a conversation is handed to
	 * (SupportWidget::registerMemberFacts). Recorded facts only: nothing is
	 * read from a site. $route marks the site a buyer asked from
	 * (support_route()).
	 */
	public static function support_lines(int $user_id, string $route = ''): array {
		$lines = array();
		$sites = new MultiCustomerCloudProvision(array('user_id' => $user_id, 'deleted' => false),
			array('cvp_customer_cloud_provision_id' => 'DESC'));
		foreach ($sites as $site) {
			$domain = trim((string)$site->get('cvp_domain'));
			if ($domain === '') {
				continue;
			}
			$plan = ManagedSiteDraft::plan_of($site);
			$parts = array(ManagedSiteDraft::PLANS[$plan]['label'] ?? 'Site');
			$status = (string)$site->get('cvp_status');
			$parts[] = $site->is_pre_payment() ? 'not paid for yet' : 'provisioning status "' . $status . '"';
			$parts[] = (string)$site->get('cvp_domain_source') === 'register' ? 'domain registered through us' : 'the buyer\'s own domain';
			if (!$site->is_pre_payment() && class_exists('ProvisionBuyerDns') && ProvisionBuyerDns::applies($site)) {
				$parts[] = ProvisionBuyerDns::listed($site) ? 'its DNS records are listed on their sites page' : 'its DNS records are not listed yet';
			}
			$mail = trim((string)$site->get('cvp_mail_state'));
			if ($mail !== '') {
				$parts[] = 'email setup "' . $mail . '"';
			}
			$created = trim((string)$site->get('cvp_create_time'));
			if ($created !== '') {
				$parts[] = 'ordered ' . substr($created, 0, 10);
			}
			$lines[] = $domain . ': ' . implode('; ', $parts)
				. ($route === self::support_route((int)$site->key) ? ' (THE SITE THEY ASKED FROM)' : '');
		}
		return $lines;
	}

	/** The page a buyer asks about one site from, as the support desk records it. */
	public static function support_route(int $provision_id): string {
		return '/profile/server_manager/site/' . $provision_id;
	}
}
