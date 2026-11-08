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
 * @version 1.0
 */
class SmHosting {
	const SETTING = 'server_manager_offer_hosting';

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
}
