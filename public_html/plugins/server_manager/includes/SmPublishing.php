<?php
/**
 * SmPublishing — whether this management node publishes releases.
 *
 * A management node for one's own sites applies the releases its upgrade
 * source offers and never builds its own. Publishing, the Upgrades page and
 * staged rollouts belong to a site that serves releases to others, which is
 * DeploymentHelper::isUpgradeServer(): the upgrade_server_active setting, or a
 * site that has already published one. Every publishing control asks here, so
 * they appear and disappear together.
 *
 * @version 1.0
 */
class SmPublishing {
	public static function enabled(): bool {
		return DeploymentHelper::isUpgradeServer();
	}

	/** The sentence every refused or hidden publishing control gives. */
	public static function off_text(): string {
		return 'This site does not publish releases. It applies the ones its upgrade source offers, and so do '
			. 'the sites it manages. To build and publish releases from here, turn on "This deployment publishes '
			. 'upgrades" in Settings.';
	}

	/**
	 * A publishing page opened on a site that does not publish: the page's
	 * header, the sentence, and nothing it could act on.
	 */
	public static function render_off_page($session, string $title): void {
		$page = new AdminPage();
		$page->admin_header([
			'menu-id' => 'server-manager',
			'page_title' => $title,
			'readable_title' => $title,
			'breadcrumbs' => ['Server Manager' => '/admin/server_manager', $title => ''],
			'session' => $session,
		]);
		$page->begin_box(['title' => 'Publishing is off']);
		echo '<p class="mb-0">' . htmlspecialchars(self::off_text()) . '</p>';
		$page->end_box();
		$page->admin_footer();
	}
}
