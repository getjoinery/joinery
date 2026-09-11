<?php
/**
 * AdminNotices — the site-wide notices every admin page shows above its content.
 *
 * A notice is one thing an operator must know wherever they are in the admin,
 * not only on the page that owns the fact: the hosting arrangement, a domain
 * about to lapse, mail that has stopped arriving. The admin header renders the
 * whole set through this one registry, so a subsystem that learns such a fact
 * registers a renderer here instead of asking the header to know about it.
 *
 * A renderer is a callable returning HTML, or '' when it has nothing to say —
 * silence is the normal state, which is what keeps a notice meaningful when it
 * appears. Renderers run on every admin page load, so they read STORED facts
 * (a settings row, a column a task stamped) and never probe anything.
 *
 * Core notices are fixed and render first. Plugins register from their
 * bootstrap (docs/plugin_developer_guide.md § Bootstrap); the registry pulls
 * the bootstraps in itself before rendering, so a bootstrap-side registration
 * is all a plugin needs. A renderer that throws is logged and skipped — an
 * admin page never fails to render because a notice could not decide.
 *
 * @version 1.4 - certificates joins the core notices (specs/tls_and_origin_trust.md WP11)
 * @version 1.3 - host_converger joins the core notices (specs/implemented/host_converger.md)
 * @version 1.2 - parser_jail joins the core notices (specs/parser_jail.md)
 * @version 1.1 - second_factor joins the core notices (security_inventory S4)
 */
class AdminNotices {
	/** @var array<string, callable> plugin renderers, by name */
	private static $renderers = array();

	/** Core notices, in render order. Each is a callable returning HTML or ''. */
	private static function coreRenderers(): array {
		return array(
			// A deployment whose domain was registered for it at checkout has one
			// thing its owner must eventually do: move the domain into their own
			// registrar account before it expires. Silent everywhere else.
			'managed_domain' => array('ManagedDomainNotice', 'render'),
			// A deployment somebody else hosts says so where its admins look:
			// what the arrangement is, when the next date falls, and where an
			// allowance is running out. Silent everywhere else.
			'hosted_plan'    => array('HostedPlanNotice', 'render'),
			// Admins with no second factor are the route a stolen session
			// takes. Named here, with the fix in place, never as a gate.
			'second_factor'  => array('AdminSecondFactorNotice', 'render'),
			// A node parsing strangers' bytes as the web user says so to the
			// one admin who can run the installer. Silent once it is installed.
			'parser_jail'    => array('ParserJailNotice', 'render'),
			// A box whose root timer stopped following its upgrades says so to
			// the one admin who can reinstall it. Silent while it runs.
			'host_converger' => array('HostConvergerNotice', 'render'),
			// A box whose own certificate has stopped renewing, or would fail a
			// Strict flip, says so to the admin who can run certbot. Silent
			// while renewal is on schedule.
			'certificates'   => array('CertificateNotice', 'render'),
		);
	}

	/**
	 * Register a notice renderer. Registering the same name again replaces the
	 * earlier renderer, so a test can stand in for a plugin's.
	 */
	public static function register(string $name, callable $renderer): void {
		self::$renderers[$name] = $renderer;
	}

	/** Names of the registered plugin renderers, in registration order. */
	public static function registered(): array {
		return array_keys(self::$renderers);
	}

	/** Every notice with something to say, concatenated. '' when all are quiet. */
	public static function render(): string {
		// Plugin bootstraps are lazy; the registry pulls them in so a bootstrap-
		// side registration is live before the first admin page asks.
		if (class_exists('PluginBootstraps')) {
			try {
				PluginBootstraps::load();
			} catch (\Throwable $e) {
				error_log('[AdminNotices] plugin bootstraps failed to load: ' . $e->getMessage());
			}
		}

		$out = '';
		foreach (self::coreRenderers() + self::$renderers as $name => $renderer) {
			try {
				$out .= (string)call_user_func($renderer);
			} catch (\Throwable $e) {
				error_log('[AdminNotices] notice "' . $name . '" failed to render: ' . $e->getMessage());
			}
		}
		return $out;
	}

	/** Forget every plugin registration (tests only). */
	public static function resetForTests(): void {
		self::$renderers = array();
	}
}
?>
