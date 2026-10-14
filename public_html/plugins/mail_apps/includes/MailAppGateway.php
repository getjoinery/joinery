<?php
/**
 * MailAppGateway - the admin side of the mail gateway: the Allow mail apps
 * switch, and the health the admin page shows.
 *
 * The gateway is a separate program (provisioning/mail-gateway, Go) that runs
 * on the host beside the web server: it holds mail programs' long connections
 * on 993 (IMAP) and 465 (SMTP submission), TLS only, and turns each command
 * into a call to this site's mail_apps/mail_* actions. It has no database and
 * no vault access (specs/mail_apps_plugin.md § Architecture).
 *
 * Turning it on is a guarded platform action, never host surgery: a narrow
 * root helper, /usr/local/sbin/joinery-mail-gateway on|off|status SITE,
 * installed with the plugin's host installer (provisioning/
 * install_mail_gateway.sh), run through sudo by the web user — the mailbox
 * listener switch's pattern. A site in a container has no host to change: it
 * asks its Docker host in its own storage, and the host's converge timer
 * serves it (specs/mail_apps_plugin.md § A4).
 *
 * @version 1.9.0 - a site on a shared relay (relay 3.13) passes mail programs through it under its slot's mail name
 * @version 1.8.1 - containerNameProblem() no longer repeats the typed text (a message is shown unescaped)
 * @version 1.8.0 - a container site's own mail name (mail_apps_container_name, containerNameProblem()): for a web
 *                  name behind Cloudflare, a DNS-only name under it that the host gets a certificate for
 * @version 1.7.0 - withdrawFromHost(): an uninstalled container site takes its request away, so its host forgets it
 * @version 1.6.0 - relayReport(): the relay's own view of the pass-through, from the report the site already keeps
 *                  (whether the server answers on each onward port, the last connection it refused and why)
 * @version 1.5.0 - a site in a container: programs connect to its web name on its Docker host; Allow and Stop write
 *                storage/mail_apps/request.json for the host's converge, hostAnswer() reads its answer, the checks
 *                dial the served name (checkAddress()), and a web name behind Cloudflare is refused
 * @version 1.4.0 - the route is recorded and kept (mail_apps_route): routeState() is the one predicate for a changed
 *                name or route (Switch offer), a public address under a relay, and a gone relay; allow()/disallow()
 *                hold the switch; keepReachable() follows a relay's new address, re-points a gone route and renews;
 *                standInForHelper() for tests; nameDrift() folded into routeState()
 * @version 1.3.0 - through a relay: mailHostname() is the relay's name; cannotTurnOn() says what the relay lacks;
 *                turnOn() obtains the gateway's certificate through the relay (csr, mailAppsCertificate,
 *                install-cert) and serves the site via_relay; renewRelayCertificate() for the daily task
 * @version 1.2.0 - run('remove'), for the plugin's uninstall (install_mail_gateway.sh 1.4)
 * @version 1.1.0 - programs connect to the mail host name (servedName()); cannotTurnOn()
 * @version 1.0.0
 */

class MailAppGateway {

	const HELPER = '/usr/local/sbin/joinery-mail-gateway';
	const IMAP_PORT = 993;
	const SUBMISSION_PORT = 465;

	/** The site's name as the host installer knows it (the directory above public_html). */
	public static function siteName(): string {
		return basename(dirname(PathHelper::getIncludePath('')));
	}

	/**
	 * The name programs connect to. While mail apps are on, the name the
	 * gateway was turned on with (recorded by the switch) — the one it really
	 * serves, even if the mail host name setting has changed since. Otherwise
	 * the mail host name, else the web name.
	 */
	public static function servedName(): string {
		$recorded = self::recordedName();
		if ($recorded !== null && Globalvars::get_instance()->get_setting('mail_apps_enabled')) {
			return $recorded;
		}
		return self::mailHostname() ?? self::hostname();
	}

	/** The name the gateway was last turned on with, or null. */
	public static function recordedName(): ?string {
		$name = strtolower(trim((string)Globalvars::get_instance()->get_setting('mail_apps_served_name')));
		return $name !== '' ? $name : null;
	}

	const ROUTE_DIRECT = 'direct';
	const ROUTE_RELAY = 'relay';

	/**
	 * The route the rule picks now (specs/mail_apps_plugin.md § Which route a
	 * deployment uses): through a relay of this deployment's own, else direct;
	 * null where mail apps cannot be on, and cannotTurnOn() says why.
	 */
	public static function preferredRoute(): ?string {
		if (self::cannotTurnOn() !== null) {
			return null;
		}
		return self::relay() !== null ? self::ROUTE_RELAY : self::ROUTE_DIRECT;
	}

	/**
	 * The route mail apps were turned on with, or null while they are off. A
	 * site turned on before the route was recorded is through the relay when
	 * the name it serves is the active relay's.
	 */
	public static function recordedRoute(): ?string {
		$settings = Globalvars::get_instance();
		if (!$settings->get_setting('mail_apps_enabled')) {
			return null;
		}
		$route = (string)$settings->get_setting('mail_apps_route');
		if ($route === self::ROUTE_DIRECT || $route === self::ROUTE_RELAY) {
			return $route;
		}
		return self::relayCarries(self::recordedName()) ? self::ROUTE_RELAY : self::ROUTE_DIRECT;
	}

	/** Whether any relay this deployment still has (enabled or not) is named $name. */
	private static function relayRowCarries(string $name): bool {
		if (!class_exists('MultiMailboxRelay')) {
			return false;
		}
		foreach (new MultiMailboxRelay(array('deleted' => false)) as $relay) {
			if (strtolower(trim((string)$relay->get('mrl_mx_hostname'))) === $name) {
				return true;
			}
		}
		return false;
	}

	/** Whether the active relay's mail host name is $name. */
	private static function relayCarries(?string $name): bool {
		$relay = self::relay();
		return $relay !== null && $name !== null && strtolower(trim((string)$relay->get('mrl_mx_hostname'))) === $name;
	}

	/**
	 * Where mail apps stand against the rule, one answer for every case
	 * (specs/mail_apps_plugin.md § Which route a deployment uses). Null while
	 * mail apps are off or match the rule. Otherwise:
	 *   offer    the rule picks another name or route; nothing changes until
	 *            the admin presses Switch (members must change the server name)
	 *   exposed  on directly while mail now arrives through a relay that cannot
	 *            pass programs through: the direct name keeps this server's
	 *            address public; no switch target
	 *   gone     on through a relay that no longer carries the name (deleted,
	 *            or replaced): keepReachable() re-points or turns off by itself
	 *
	 * A relay that moved address is not a state of the route; keepReachable()
	 * follows it (relayAddressMoved()).
	 *
	 * @return array{state:string, served:string, route:string, name:?string, preferred:?string, reason:?string}|null
	 */
	public static function routeState(): ?array {
		$route = self::recordedRoute();
		$served = self::recordedName();
		if ($route === null || $served === null) {
			return null;
		}
		$preferred = self::preferredRoute();
		$name = self::mailHostname();
		$answer = array('served' => $served, 'route' => $route, 'name' => $name, 'preferred' => $preferred,
			'reason' => self::cannotTurnOn());
		if ($route === self::ROUTE_RELAY && !self::relayCarries($served)) {
			// A relay the admin only disabled (mailbox's emergency stop) still
			// has its row: programs wait for it like mail does. Going direct
			// then would publish the address it hides.
			return self::relayRowCarries($served) && self::relay() === null ? null : array('state' => 'gone') + $answer;
		}
		if ($preferred === null) {
			// Mail arrives through a relay that passes nothing through (shared,
			// or older than RelayVersion::PASSES_MAIL_APPS), while programs still reach this server
			// directly: nothing to switch to, but the address is public.
			return ($route === self::ROUTE_DIRECT && self::relay() !== null && !self::inContainer())
				? array('state' => 'exposed') + $answer : null;
		}
		if ($preferred !== $route || $name !== $served) {
			return array('state' => 'offer') + $answer;
		}
		return null;
	}

	/**
	 * Turn mail apps on by the route the rule picks now, and record it: the
	 * Allow and Switch buttons, and keepReachable() re-pointing a gone route.
	 * A relay that passes programs through is sent this server's address.
	 *
	 * @return array{ok:bool, message:string}
	 */
	public static function allow(): array {
		$why_not = self::cannotTurnOn();
		if ($why_not !== null) {
			return array('ok' => false, 'message' => $why_not);
		}
		$route = self::preferredRoute();
		$run = self::turnOn();
		if (!$run['ok']) {
			return $run;
		}
		Setting::put('mail_apps_enabled', true);
		Setting::put('mail_apps_served_name', (string)self::mailHostname());
		Setting::put('mail_apps_route', (string)$route);
		if (self::inContainer()) {
			return array('ok' => true, 'message' => 'Asked this site\'s server to let mail programs connect to '
				. self::servedName() . ' on ports 993 and 465. It does so within a minute; this page shows its answer.');
		}
		return array('ok' => true, 'message' => 'Mail apps are on: mail programs connect to ' . self::servedName()
			. ' on ports 993 and 465.' . self::pushRelay($route === self::ROUTE_RELAY
				? ' The relay passes mail programs through to this server.' : ''));
	}

	/**
	 * Turn mail apps off and record it: the gateway stops serving this site,
	 * and every borrowed window a mail credential holds ends now.
	 *
	 * @return array{ok:bool, message:string}
	 */
	public static function disallow(): array {
		$was_through = self::recordedRoute() === self::ROUTE_RELAY && self::relayCarries(self::recordedName());
		$run = self::turnOff();
		if (!$run['ok']) {
			return $run;
		}
		Setting::put('mail_apps_enabled', false);
		Setting::put('mail_apps_served_name', '');
		Setting::put('mail_apps_route', '');
		// Credentials of a switched-off purpose are refused from now on
		// (AppAccess::isAvailable()); their borrowed windows end now.
		ApiKey::LockBorrowedWindowsForPurpose('mail');
		return array('ok' => true, 'message' => 'Mail apps are off: the gateway no longer answers for this site, and '
			. 'members\' app passwords stop working at once.' . self::pushRelay($was_through
				? ' The relay no longer passes them through.' : ''));
	}

	/**
	 * Send the active relay the change now (RelayMapExporter reads the
	 * settings just written); a push that fails is retried by the relay
	 * reconcile task. Returns $done, what to tell the admin when it went
	 * through, or why it has not; '' with no relay.
	 */
	private static function pushRelay(string $done): string {
		if (($relay = self::relay()) === null) {
			return '';
		}
		$push = RelayMapSync::push($relay);
		if (($push['status'] ?? '') === 'error') {
			return ' The relay has not taken the change yet (' . $push['message'] . '); it is retried within minutes.';
		}
		return $done;
	}

	/**
	 * Through a relay, whether the relay's address has changed since the
	 * gateway was told it (re-provisioned, or moved to another shard): the
	 * firewall and the gateway's trusted list still name the old one, so
	 * programs cannot reach this server until it is followed.
	 */
	public static function relayAddressMoved(): bool {
		$relay = self::relay();
		if (self::recordedRoute() !== self::ROUTE_RELAY || $relay === null || !self::relayCarries(self::recordedName())) {
			return false;
		}
		$status = self::helper(array('status', self::siteName()), 'GATEWAY_STATUS');
		if (!$status['ok'] || !preg_match('/^relays=(.*)$/m', $status['message'], $m)) {
			return false;
		}
		$told = preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY);
		return !in_array(trim((string)$relay->get('mrl_public_ip')), $told, true);
	}

	/**
	 * Keep mail programs able to connect (the task KeepMailAppsReachable,
	 * hourly): follow a relay that moved address, re-point a route that can no
	 * longer work, and renew a certificate obtained through the relay. Never
	 * changes a working route: a better one is the admin's Switch.
	 *
	 * @return array{ok:bool, message:string}
	 */
	public static function keepReachable(): array {
		if (!Globalvars::get_instance()->get_setting('mail_apps_enabled') || self::inContainer() || !self::helperInstalled()) {
			return array('ok' => true, 'message' => 'Mail apps are off here; nothing to keep.');
		}
		$state = self::routeState();
		if ($state !== null && $state['state'] === 'gone') {
			return self::repoint($state);
		}
		$messages = array();
		if (self::relayAddressMoved()) {
			$run = self::turnOn();
			if (!$run['ok']) {
				return array('ok' => false, 'message' => 'The relay moved to ' . trim((string)self::relay()->get('mrl_public_ip'))
					. ' and the gateway could not follow it: ' . $run['message']);
			}
			$messages[] = 'Followed the relay to its new address, ' . trim((string)self::relay()->get('mrl_public_ip')) . '.';
		}
		$renewed = self::renewRelayCertificate();
		$messages[] = $renewed['message'];
		return array('ok' => $renewed['ok'], 'message' => implode(' ', $messages));
	}

	/**
	 * The relay mail apps were turned on through is gone: turn them on by
	 * whatever the rule picks now, or off when nothing can carry them, and
	 * leave the admin a notice (members must change the server name).
	 */
	private static function repoint(array $state): array {
		$old = $state['served'];
		if ($state['preferred'] !== null) {
			$run = self::allow();
			if ($run['ok']) {
				self::noteRouteChange('Mail programs connected to ' . $old . ' through a relay that no longer carries it. '
					. 'They now connect to ' . self::servedName() . '. Each member must change the server name in their '
					. 'mail program to ' . self::servedName() . '.');
				return array('ok' => true, 'message' => 'Re-pointed mail apps from ' . $old . ' to ' . self::servedName() . '.');
			}
			$why = $run['message'];
		} else {
			$why = (string)$state['reason'];
		}
		$off = self::disallow();
		self::noteRouteChange('Mail programs connected to ' . $old . ' through a relay that no longer carries it, and '
			. 'they cannot be carried another way, so mail apps were turned off: ' . $why);
		return array('ok' => $off['ok'], 'message' => 'Turned mail apps off: the relay no longer carries ' . $old . ' ('
			. $why . ')' . ($off['ok'] ? '' : '; turning the gateway off failed too: ' . $off['message']));
	}

	/** Record a change made without the admin, for the admin page to show until it is dismissed. */
	private static function noteRouteChange(string $text): void {
		Setting::put('mail_apps_route_notice', gmdate('Y-m-d H:i:s') . '|' . $text);
	}

	/**
	 * The last change made without the admin, or null.
	 *
	 * @return array{time:string, text:string}|null
	 */
	public static function routeNotice(): ?array {
		$raw = (string)Globalvars::get_instance()->get_setting('mail_apps_route_notice');
		if (strpos($raw, '|') === false) {
			return null;
		}
		list($time, $text) = explode('|', $raw, 2);
		return array('time' => $time, 'text' => $text);
	}

	public static function dismissRouteNotice(): void {
		Setting::put('mail_apps_route_notice', '');
	}

	/** The site's public web host name (how the gateway reaches the site over loopback). */
	public static function hostname(): string {
		return (string)parse_url(LibraryFunctions::get_absolute_url('/'), PHP_URL_HOST);
	}

	/**
	 * The name mail programs connect to. Directly: the deployment's mail host
	 * name (`mailbox_mail_hostname`, its MX target), which points at this
	 * server. Not the web name, which often sits behind a CDN that carries web
	 * ports only. Through a relay: the relay's own mail host name, which the
	 * relay passes through to this server, so this server's address stays
	 * hidden (specs/mail_apps_plugin.md § Where programs connect). Null when
	 * there is none.
	 */
	public static function mailHostname(): ?string {
		if (self::inContainer()) {
			// A container's programs connect straight to its Docker host, never
			// through a relay (§ Which route a deployment uses, A4): under the
			// site's own mail name when it has one, else its web name.
			$own = self::containerMailName();
			if ($own !== null) {
				return $own;
			}
			$host = strtolower(trim(self::hostname()));
			return $host !== '' ? $host : null;
		}
		$relay = self::relay();
		$host = $relay !== null ? (string)$relay->get('mrl_mx_hostname')
			: (string)Globalvars::get_instance()->get_setting('mailbox_mail_hostname');
		$host = strtolower(trim($host));
		return $host !== '' ? $host : null;
	}

	/** In a container: the mail name the admin set (mail_apps_container_name), or null for the web name. */
	public static function containerMailName(): ?string {
		$name = strtolower(trim((string)Globalvars::get_instance()->get_setting('mail_apps_container_name')));
		return $name !== '' ? $name : null;
	}

	/**
	 * The web name with a leading www. dropped: the names under it are the ones
	 * a container site may use for mail. Its host checks the same rule, and also
	 * that no other site on it owns the name (install_mail_gateway.sh converge).
	 */
	public static function containerNameBase(): string {
		$web = strtolower(trim(self::hostname()));
		return strpos($web, 'www.') === 0 ? substr($web, 4) : $web;
	}

	/**
	 * Why $name cannot be this container site's mail name, or null when it can:
	 * a host name under the site's web name (mail.example.com for example.com or
	 * www.example.com), so no site can claim a name that belongs to another.
	 */
	public static function containerNameProblem(string $name): ?string {
		$name = strtolower(trim($name));
		$base = self::containerNameBase();
		if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $name)) {
			return 'That is not a host name: letters, digits and hyphens, in parts joined by dots, such as mail.' . $base . '.';
		}
		if ($base === '' || substr($name, -strlen('.' . $base)) !== '.' . $base) {
			return 'The mail name must be a name under this site\'s web name, such as mail.' . $base
				. ', so that it can only be this site\'s.';
		}
		return null;
	}

	/**
	 * In a container: set the mail name (an empty one goes back to the web
	 * name). It takes effect at Allow, or Switch while mail apps are on.
	 *
	 * @return array{ok:bool, message:string}
	 */
	public static function setContainerMailName(string $name): array {
		$name = strtolower(trim($name));
		if ($name !== '' && $name !== strtolower(self::hostname())) {
			$why = self::containerNameProblem($name);
			if ($why !== null) {
				return array('ok' => false, 'message' => $why);
			}
		} else {
			$name = '';
		}
		Setting::put('mail_apps_container_name', $name);
		return array('ok' => true, 'message' => $name === ''
			? 'Mail programs will connect to the web name, ' . self::hostname() . '.'
			: 'Mail programs will connect to ' . $name . '. Point it straight at this site\'s server (DNS only, '
				. 'not through Cloudflare); the server gets its certificate when mail apps are allowed.');
	}

	/** The relay this deployment's mail arrives through, or null. */
	public static function relay(): ?MailboxRelay {
		return class_exists('MailboxRelay') ? MailboxRelay::active() : null;
	}

	/**
	 * The relay's own view of the pass-through, from the last report this site
	 * stored (mailbox's relay health poll keeps the whole ping), or null when
	 * mail apps are not on through a relay or no report has one. reaches maps
	 * each program port (993, 465) to the server port the relay dials and
	 * whether the server answered there at the relay's last check (relay 3.12):
	 * a provider's outbound block or a firewall not opened to the relay shows
	 * here before a member meets it.
	 *
	 * @return array{report_time:?int, reaches:?array, checked:?int, passed_1h:int, refused_1h:int,
	 *               last_refusal:?string, last_refusal_time:?int}|null
	 */
	public static function relayReport(): ?array {
		$relay = self::relay();
		if ($relay === null || self::recordedRoute() !== self::ROUTE_RELAY) {
			return null;
		}
		$health = json_decode((string)$relay->get('mrl_last_health_json'), true);
		$group = is_array($health) ? ($health['ping']['mail_apps'] ?? null) : null;
		if (!is_array($group)) {
			return null;
		}
		$time = function ($v) {
			$t = is_string($v) && $v !== '' ? strtotime($v) : false;
			return $t === false ? null : $t;
		};
		$reaches = null;
		if (is_array($group['reaches_server'] ?? null)) {
			$reaches = array();
			foreach ($group['reaches_server'] as $role => $r) {
				if (is_array($r)) {
					$reaches[(string)$role] = array('port' => (int)($r['port'] ?? 0), 'answers' => !empty($r['answers']));
				}
			}
			ksort($reaches);
		}
		$report = (string)$relay->get('mrl_last_health_time');
		return array(
			'report_time'       => $report !== '' ? $time($report . ' UTC') : null,
			'reaches'           => $reaches,
			'checked'           => $time($group['reaches_server_utc'] ?? null),
			'passed_1h'         => (int)($group['spliced_1h'] ?? 0),
			'refused_1h'        => (int)($group['refused_1h'] ?? 0),
			'last_refusal'      => isset($group['last_refusal']) ? (string)$group['last_refusal'] : null,
			'last_refusal_time' => $time($group['last_refusal_utc'] ?? null),
		);
	}

	/** This server's public IPv4, which the relay reaches the gateway on, or null. */
	public static function originAddress(): ?string {
		$ip = trim((string)Globalvars::get_instance()->get_setting('mailbox_public_ip'));
		return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
			? $ip : null;
	}

	/**
	 * Why mail apps cannot be turned on here, in words, or null when they can.
	 */
	public static function cannotTurnOn(): ?string {
		if (self::inContainer()) {
			$name = self::mailHostname();
			if ($name === null) {
				return 'This site has no web name yet; mail programs connect to it.';
			}
			if (self::behindCloudflare($name)) {
				return 'Mail programs connect to ' . $name . ', which goes through Cloudflare. Cloudflare carries web '
					. 'pages only, not mail (ports 993 and 465). '
					. (self::containerMailName() === null
						? 'Give mail programs a name of their own below, such as mail.' . self::containerNameBase()
							. ', set to DNS only and pointing straight at this site\'s server.'
						: 'Turn Cloudflare\'s proxy off for this name (DNS only) so it points straight at this site\'s server.');
			}
			if (!is_dir(self::storageDir()) && !@mkdir(self::storageDir(), 0755, true)) {
				return 'This site cannot write its request for the host (' . self::storageDir() . ').';
			}
			return null;
		}
		$relay = self::relay();
		if ($relay !== null) {
			$running = trim($relay->provisionedVersion());
			if ($relay->get('mrl_is_hosted')) {
				// A shared shard passes each tenant's programs under its slot's
				// own mail name (mrl_mx_hostname), from relay 3.13.
				if (!RelayVersion::passesMailAppsShared($running)) {
					return 'Mail arrives here through a shared relay, which keeps this server\'s address hidden. It runs '
						. 'version ' . ($running !== '' ? $running : 'unknown') . '; passing mail programs through a shared '
						. 'relay needs ' . RelayVersion::PASSES_MAIL_APPS_SHARED . ', which arrives when the relay\'s operator '
						. 'renews it.';
				}
			} elseif (!RelayVersion::passesMailApps($running)) {
				return 'Mail arrives here through a relay, which keeps this server\'s address hidden, and mail programs '
					. 'reach this server through it. The relay runs version ' . ($running !== '' ? $running : 'unknown')
					. '; passing mail programs through needs ' . RelayVersion::PASSES_MAIL_APPS
					. '. Upgrade the relay from the Relay section of the Mailbox setup page.';
			}
			if (self::mailHostname() === null || trim((string)$relay->get('mrl_public_ip')) === '') {
				return 'The relay has no mail host name or public address yet. Mail apps wait until it does.';
			}
			if (self::originAddress() === null) {
				return 'This server\'s public IPv4 address is not set (the Mailbox setting mailbox_public_ip). The relay '
					. 'reaches the mail gateway on it.';
			}
			return null;
		}
		if (self::mailHostname() === null) {
			return 'This site has no mail host name yet. Set it on the Mailbox setup page; mail programs connect to it.';
		}
		return null;
	}

	/** A certificate with fewer days than this left is renewed through the relay. */
	const RELAY_CERT_RENEW_DAYS = 30;

	/**
	 * Turn the gateway on for this site. Through a relay, first make sure the
	 * gateway holds a certificate for the relay's name with RELAY_CERT_RENEW_DAYS
	 * left (obtained through the relay if not), then serve the site via_relay,
	 * reachable from the relay's address alone. Never throws.
	 *
	 * @return array{ok:bool, message:string}
	 */
	public static function turnOn(): array {
		$name = self::mailHostname();
		if (self::inContainer()) {
			return self::askHost(true, $name);
		}
		$relay = self::relay();
		if ($relay === null) {
			return self::helper(array('on', self::siteName(), (string)$name), 'GATEWAY_ON');
		}
		$expires = self::relayCertificateExpires();
		if ($expires === null || $expires - time() < self::RELAY_CERT_RENEW_DAYS * 86400) {
			$got = self::obtainRelayCertificate($relay, (string)$name);
			if (!$got['ok']) {
				return $got;
			}
		}
		return self::helper(array('on', self::siteName(), (string)$name, trim((string)$relay->get('mrl_public_ip'))), 'GATEWAY_ON');
	}

	/** Turn the gateway off for this site. Never throws. */
	public static function turnOff(): array {
		if (self::inContainer()) {
			return self::askHost(false, null);
		}
		return self::helper(array('off', self::siteName()), 'GATEWAY_OFF');
	}

	/**
	 * The renewal (keepReachable()): while mail apps are on
	 * through a relay and the certificate is inside RELAY_CERT_RENEW_DAYS of
	 * expiry, obtain a new one; the helper restarts the gateway onto it.
	 *
	 * @return array{ok:bool, message:string}
	 */
	public static function renewRelayCertificate(): array {
		$relay = self::relay();
		if ($relay === null || self::recordedRoute() !== self::ROUTE_RELAY || !self::relayCarries(self::recordedName())
				|| !self::helperInstalled()) {
			return array('ok' => true, 'message' => 'Mail apps are not on through a relay; nothing to renew.');
		}
		$expires = self::relayCertificateExpires();
		if ($expires !== null && $expires - time() >= self::RELAY_CERT_RENEW_DAYS * 86400) {
			return array('ok' => true, 'message' => 'The certificate is good until ' . gmdate('Y-m-d', $expires) . '.');
		}
		$name = self::recordedName() ?? (string)self::mailHostname();
		return self::obtainRelayCertificate($relay, $name);
	}

	/**
	 * A new key and request here, the certificate from the relay (which
	 * completes the CA's order for its own name), installed by the helper.
	 * The key never leaves this server.
	 */
	public static function obtainRelayCertificate(MailboxRelay $relay, string $name): array {
		$csr = self::helper(array('csr', self::siteName(), $name), 'GATEWAY_CSR');
		if (!$csr['ok'] || !preg_match('/-----BEGIN CERTIFICATE REQUEST-----.+?-----END CERTIFICATE REQUEST-----/s', $csr['message'], $m)) {
			return array('ok' => false, 'message' => 'The certificate request could not be made: ' . $csr['message']);
		}
		try {
			$answer = $relay->withApi(function (RelayClient $c) use ($m) {
				return $c->mailAppsCertificate($m[0] . "\n");
			});
		} catch (\Throwable $e) {
			return array('ok' => false, 'message' => 'The relay could not be reached for the certificate: ' . $e->getMessage());
		}
		if (!is_array($answer) || empty($answer['ok'])) {
			return array('ok' => false, 'message' => 'The relay did not issue the certificate: '
				. (is_array($answer) ? (string)($answer['reason'] ?? 'no answer') : 'no answer'));
		}
		$installed = self::helper(array('install-cert', self::siteName(), $name), 'GATEWAY_CERT_INSTALLED', (string)$answer['chain']);
		if (!$installed['ok']) {
			return array('ok' => false, 'message' => 'The relay issued a certificate, but it was not installed: ' . $installed['message']);
		}
		return array('ok' => true, 'message' => 'Certificate for ' . $name . ' issued through the relay, valid until '
			. substr((string)($answer['not_after'] ?? ''), 0, 10) . '.');
	}

	/** When the certificate obtained through the relay expires (unix time), or null when there is none. */
	public static function relayCertificateExpires(): ?int {
		if (!self::helperInstalled()) {
			return null;
		}
		$status = self::helper(array('status', self::siteName()), 'GATEWAY_STATUS');
		if (!$status['ok'] || !preg_match('/^relay_cert_expires=(\d+)$/m', $status['message'], $m)) {
			return null;
		}
		return (int)$m[1];
	}

	/** Whether $name resolves to a Cloudflare edge address (a proxied name carries no mail ports). */
	public static function behindCloudflare(string $name): bool {
		$records = @dns_get_record($name, DNS_A | DNS_AAAA);
		foreach (is_array($records) ? $records : array() as $r) {
			$ip = $r['ip'] ?? ($r['ipv6'] ?? '');
			if ($ip !== '' && SessionControl::ip_is_cloudflare_edge($ip)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * In a container: where the site asks its Docker host for mail apps and
	 * reads the answer (storage/mail_apps/request.json and answer.json). The
	 * host's converge timer reads the request from this volume and writes the
	 * answer beside it (install_mail_gateway.sh, helper converge).
	 */
	public static function storageDir(): string {
		if (self::$container_stand_in !== null) {
			return self::$container_stand_in;
		}
		return rtrim(PathHelper::getSiteRoot(), '/') . '/storage/mail_apps';
	}

	/** @var string|null A storage folder standing in for a container's (tests only; see standInForContainer()). */
	private static $container_stand_in = null;

	/** Tests only: behave as a site in a container whose storage/mail_apps is $dir; null ends it. */
	public static function standInForContainer(?string $dir): void {
		self::$container_stand_in = $dir;
	}

	/** In a container: ask the host to serve this site under $name, or to stop. */
	private static function askHost(bool $on, ?string $name): array {
		$dir = self::storageDir();
		if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
			return array('ok' => false, 'message' => 'This site cannot write its request for the host (' . $dir . ').');
		}
		$body = json_encode($on ? array('on' => true, 'name' => (string)$name) : array('on' => false)) . "\n";
		$tmp = $dir . '/.request.json.' . getmypid();
		if (@file_put_contents($tmp, $body) === false || !@rename($tmp, $dir . '/request.json')) {
			@unlink($tmp);
			return array('ok' => false, 'message' => 'This site could not write its request for the host.');
		}
		return array('ok' => true, 'message' => $on ? 'GATEWAY_ON' : 'GATEWAY_OFF');
	}

	/**
	 * In a container, on uninstall: take the request away (and the host's last
	 * answer with it). A site with no request is one the host no longer knows:
	 * its next converge stops serving it if it was, and forgets its entry and
	 * certificate. Never throws.
	 *
	 * @return array{ok:bool, message:string}
	 */
	public static function withdrawFromHost(): array {
		$dir = self::storageDir();
		foreach (array('request.json', 'answer.json') as $f) {
			if (file_exists($dir . '/' . $f) && !@unlink($dir . '/' . $f)) {
				return array('ok' => false, 'message' => 'This site could not take back its request for the host (' . $dir . '/' . $f . ').');
			}
		}
		@rmdir($dir);
		return array('ok' => true, 'message' => 'GATEWAY_WITHDRAWN');
	}

	/**
	 * In a container: the host's last answer, or null before it has given one.
	 *
	 * @return array{state:string, name:?string, cert_expires:?int, error:?string, time:?int}|null
	 */
	public static function hostAnswer(): ?array {
		$raw = @file_get_contents(self::storageDir() . '/answer.json');
		$j = is_string($raw) ? json_decode($raw, true) : null;
		if (!is_array($j) || !in_array($j['state'] ?? '', array('on', 'off', 'failed'), true)) {
			return null;
		}
		return array('state' => (string)$j['state'], 'name' => isset($j['name']) ? (string)$j['name'] : null,
			'cert_expires' => isset($j['cert_expires']) ? (int)$j['cert_expires'] : null,
			'error' => isset($j['error']) ? (string)$j['error'] : null, 'time' => isset($j['time']) ? (int)$j['time'] : null);
	}

	/** Where checks connect: this machine's loopback, or in a container the name programs use (its host). */
	public static function checkAddress(): string {
		return self::inContainer() ? self::servedName() : '127.0.0.1';
	}

	/** A site container has no host of its own to change. */
	public static function inContainer(): bool {
		if (self::$container_stand_in !== null) {
			return true;
		}
		return Globalvars::get_instance()->get_setting('deployment_environment', true, true) === 'docker';
	}

	public static function helperInstalled(): bool {
		return self::$helper_stand_in !== null || is_file(self::HELPER);
	}

	/** @var callable|null Answers helper calls in place of the root helper (tests only; see standInForHelper()). */
	private static $helper_stand_in = null;

	/**
	 * Tests only: answer helper calls with $fn(array $args, ?string $stdin):
	 * array{code:int, output:string} instead of running the root helper, and
	 * count it as installed. Null puts the real helper back.
	 */
	public static function standInForHelper(?callable $fn): void {
		self::$helper_stand_in = $fn;
	}

	/** Whether something accepts connections on $port: on this machine, or in a container on its host. */
	public static function listening(int $port): bool {
		$sock = @stream_socket_client('tcp://' . self::checkAddress() . ':' . $port, $errno, $errstr, 2);
		if ($sock) {
			@fclose($sock);
			return true;
		}
		return false;
	}

	/**
	 * The certificate the gateway presents for this site's name on $port:
	 * whether it covers the name, and when it expires.
	 *
	 * @return array{ok:bool, covers:bool, expires:?int, error:?string}
	 */
	public static function certificate(int $port): array {
		$host = self::servedName();
		// Verified as a mail program verifies it: the chain to a public root
		// and the name. A self-signed or broken chain fails here, as it would
		// in Thunderbird; the unverified look below only says why.
		$verified = @stream_socket_client('ssl://' . self::checkAddress() . ':' . $port, $verrno, $verrstr, 5, STREAM_CLIENT_CONNECT,
			stream_context_create(array('ssl' => array(
				'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host))));
		if ($verified) {
			@fclose($verified);
		}
		$ctx = stream_context_create(array('ssl' => array(
			'capture_peer_cert' => true,
			'verify_peer'       => false,
			'verify_peer_name'  => false,
			'SNI_enabled'       => true,
			'peer_name'         => $host,
		)));
		$sock = @stream_socket_client('ssl://' . self::checkAddress() . ':' . $port, $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
		if (!$sock) {
			return array('ok' => false, 'covers' => false, 'expires' => null, 'error' => $errstr ?: 'no TLS answer');
		}
		$params = stream_context_get_params($sock);
		@fclose($sock);
		$cert = $params['options']['ssl']['peer_certificate'] ?? null;
		$info = $cert ? openssl_x509_parse($cert) : null;
		if (!$info) {
			return array('ok' => false, 'covers' => false, 'expires' => null, 'error' => 'no certificate');
		}
		$names = array(strtolower((string)($info['subject']['CN'] ?? '')));
		foreach (explode(',', (string)($info['extensions']['subjectAltName'] ?? '')) as $alt) {
			$alt = trim($alt);
			if (stripos($alt, 'DNS:') === 0) {
				$names[] = strtolower(substr($alt, 4));
			}
		}
		$covers = false;
		foreach ($names as $name) {
			if ($name === strtolower($host)
				|| (strpos($name, '*.') === 0 && substr(strtolower($host), strpos(strtolower($host), '.')) === substr($name, 1))) {
				$covers = true;
			}
		}
		$expires = isset($info['validTo_time_t']) ? intval($info['validTo_time_t']) : null;
		return array('ok' => (bool)$verified && $covers && $expires !== null && $expires > time(), 'covers' => $covers,
			'expires' => $expires,
			'error' => $verified ? null : 'Mail programs will refuse this certificate (it does not verify to a public root).');
	}

	/**
	 * Run one helper verb as root via the sudoers rule and demand its success
	 * marker. Never throws.
	 *
	 * @param string $verb on | off | status | remove (the plugin's uninstall)
	 * @return array{ok:bool, message:string}
	 */
	public static function run(string $verb): array {
		if (!in_array($verb, array('on', 'off', 'status', 'remove'), true)) {
			return array('ok' => false, 'message' => 'Unknown verb.');
		}
		if (!self::helperInstalled()) {
			return array('ok' => false, 'message' => 'The mail gateway is not installed on this server yet. The host '
				. 'installer puts it in place within a few minutes of the plugin being switched on; or run once as '
				. 'root: sudo bash plugins/mail_apps/provisioning/install_mail_gateway.sh');
		}
		if ($verb === 'on') {
			return self::turnOn();
		}
		$marker = array('off' => 'GATEWAY_OFF', 'status' => 'GATEWAY_STATUS', 'remove' => 'GATEWAY_REMOVED')[$verb];
		return self::helper(array($verb, self::siteName()), $marker);
	}

	/**
	 * Run the root helper with these arguments (and $stdin on its standard
	 * input) through the sudoers rule, and demand its success marker. Never throws.
	 *
	 * @return array{ok:bool, message:string}
	 */
	private static function helper(array $args, string $marker, ?string $stdin = null): array {
		if (!self::helperInstalled()) {
			return array('ok' => false, 'message' => 'The mail gateway is not installed on this server yet. The host '
				. 'installer puts it in place within a few minutes of the plugin being switched on; or run once as '
				. 'root: sudo bash plugins/mail_apps/provisioning/install_mail_gateway.sh');
		}
		if (self::$helper_stand_in !== null) {
			$answer = (self::$helper_stand_in)($args, $stdin);
			$text = trim((string)($answer['output'] ?? ''));
			$code = (int)($answer['code'] ?? 0);
		} else {
			$cmd = 'sudo -n ' . escapeshellarg(self::HELPER) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
			$proc = proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w')), $pipes);
			if (!is_resource($proc)) {
				return array('ok' => false, 'message' => 'The mail gateway helper could not be started.');
			}
			if ($stdin !== null) {
				fwrite($pipes[0], $stdin);
			}
			fclose($pipes[0]);
			$text = trim((string)stream_get_contents($pipes[1]));
			fclose($pipes[1]);
			$code = proc_close($proc);
		}
		if ($code !== 0 || strpos($text, $marker) === false) {
			return array('ok' => false, 'message' => 'The mail gateway helper did not finish (exit ' . intval($code) . '): '
				. ($text !== '' ? $text : 'no output'));
		}
		return array('ok' => true, 'message' => $text);
	}

	/**
	 * The round trip: sign in over IMAP with a throwaway app password, SELECT
	 * INBOX, sign out, revoke the password. Proves the whole path — TLS, the
	 * gateway, the API, app access, the folder — with the admin's own mailbox.
	 *
	 * @return array{ok:bool, steps:string[], error:?string}
	 */
	public static function roundTrip(int $user_id): array {
		$steps = array();
		$aliases = InboundEmailMailboxGrant::alias_ids_for_user($user_id);
		$address = null;
		foreach ($aliases as $alias_id) {
			$alias = new InboundEmailAlias(intval($alias_id), TRUE);
			if ($alias->key && !$alias->get('iea_delete_time') && !$alias->is_fortress()) {
				$address = strtolower($alias->get_full_address());
				break;
			}
		}
		if ($address === null) {
			return array('ok' => false, 'steps' => $steps,
				'error' => 'You hold no Standard or Private mailbox to sign in to; the round trip needs one.');
		}
		try {
			$minted = ApiKey::CreateAppPassword($user_id, 'mail', 'Health check');
		} catch (\Throwable $e) {
			return array('ok' => false, 'steps' => $steps, 'error' => 'Could not make a throwaway app password: ' . $e->getMessage());
		}
		$steps[] = 'Made a throwaway app password';
		$error = null;
		try {
			$ctx = stream_context_create(array('ssl' => array(
				// Verified, as a program would: a round trip that passes on a
				// certificate Thunderbird refuses proves nothing.
				'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => self::servedName(),
			)));
			$sock = @stream_socket_client('ssl://' . self::checkAddress() . ':' . self::IMAP_PORT, $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
			if (!$sock) {
				throw new RuntimeException('No TLS connection to port ' . self::IMAP_PORT . ': ' . $errstr);
			}
			stream_set_timeout($sock, 20);
			$greeting = (string)fgets($sock);
			if (strpos($greeting, '* OK') !== 0) {
				throw new RuntimeException('Unexpected greeting: ' . trim($greeting));
			}
			$steps[] = 'Connected over TLS';
			$quote = function (string $s): string { return '"' . addcslashes($s, "\"\\") . '"'; };
			$command = function (string $tag, string $line) use ($sock): string {
				fwrite($sock, $tag . ' ' . $line . "\r\n");
				while (($reply = fgets($sock)) !== false) {
					if (strpos($reply, $tag . ' ') === 0) {
						return trim($reply);
					}
				}
				throw new RuntimeException('The connection closed during ' . strtok($line, ' '));
			};
			$reply = $command('a1', 'LOGIN ' . $quote($address) . ' ' . $quote($minted['password']));
			if (strpos($reply, 'a1 OK') !== 0) {
				throw new RuntimeException('Sign-in refused: ' . $reply);
			}
			$steps[] = 'Signed in as ' . $address;
			$reply = $command('a2', 'SELECT INBOX');
			if (strpos($reply, 'a2 OK') !== 0) {
				throw new RuntimeException('Opening INBOX refused: ' . $reply);
			}
			$steps[] = 'Opened INBOX';
			$command('a3', 'LOGOUT');
			$steps[] = 'Signed out';
			fclose($sock);
		} catch (\Throwable $e) {
			$error = $e->getMessage();
		} finally {
			$minted['api_key']->soft_delete();
			$steps[] = 'Revoked the throwaway app password';
		}
		return array('ok' => $error === null, 'steps' => $steps, 'error' => $error);
	}
}
?>
