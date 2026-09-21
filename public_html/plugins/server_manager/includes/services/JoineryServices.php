<?php
/**
 * JoineryServices — the operator side of the services a self-hosted site
 * rents from this plane: outbound mail and backup storage
 * (specs/services_phase2_platform.md §2–§6, umbrella contract C2).
 *
 * A site reaches this through three API actions over its connected key
 * (services_enroll, services_status, services_release); an operator reaches
 * it from the Service Tenants admin page (grant, release); the reconcile
 * reaches it through the two ladder acts (suspend, reactivate). Every path
 * lands on one ServiceTenant row per site per service, keyed by the key.
 *
 * WHAT DECIDES: the date. A row with no svt_paid_until, or one that has
 * passed, is not entitled; enrol says so and mints nothing. A row with a date
 * ahead gets the service built in the enrol call itself — a self-hosted site
 * has no node to wait for, so mail's subaccount, sender domain and SMTP user
 * are minted at once and the credential goes back in the response — and the
 * shelf needs nothing minted at all: a slug and a prefix, and the broker
 * signs inside them.
 *
 * ENROL IS IDEMPOTENT ON THE ROW, and for mail it means a working credential
 * every time: the SMTP password is never stored on this plane, so a second
 * enrol removes the tenant's SMTP user and mints a fresh one inside the same
 * subaccount. The subaccount and the sender domain are created once and kept.
 *
 * THE MASTER KEYS NEVER LEAVE THIS MACHINE. What crosses to a site is one
 * SMTP username and password inside its own subaccount, or nothing (backup storage).
 *
 * @version 1.0
 */
class JoineryServicesException extends Exception {}

class JoineryServices {

	/** Percentage of an allowance at which the site's banner warns. */
	const WARN_PERCENT = 80;

	// ── Plane settings ────────────────────────────────────────────────────────

	/** Blank reads as the declared default; 0 is a real answer. */
	public static function graceDays(): int {
		$raw = trim((string)Globalvars::get_instance()->get_setting('server_manager_services_grace_days', false, true));
		return $raw === '' ? 14 : max(0, intval($raw));
	}

	/** The allowance in the service's own unit: sends a month, or bytes in backup storage. */
	public static function allowance(string $service): int {
		if ($service === ServiceTenant::SERVICE_MAIL) {
			return Smtp2GoLeg::sendAllowance();
		}
		$gb = (int)Globalvars::get_instance()->get_setting('server_manager_hosted_shelf_allowance_gb', true, true);
		return max(1, $gb) * 1073741824;
	}

	/** The first door: the referral link for the service's own-account path, or ''. */
	public static function referralUrl(string $service): string {
		$name = $service === ServiceTenant::SERVICE_MAIL
			? 'server_manager_smtp2go_referral_url' : 'server_manager_storage_referral_url';
		$url = trim((string)Globalvars::get_instance()->get_setting($name, false, true));
		return strpos($url, 'https://') === 0 ? $url : '';
	}

	public static function actionLabel(string $service): string {
		return $service === ServiceTenant::SERVICE_MAIL ? 'Use your own email account' : 'Use your own storage';
	}

	/** Where the account holder sees their connected sites and each service's date. */
	public static function manageUrl(): string {
		$host = trim((string)Globalvars::get_instance()->get_setting('webDir'));
		return $host === '' ? '' : 'https://' . $host . '/profile/server_manager/services';
	}

	/**
	 * The plane's backup storage target: the row the setting names, else the one
	 * enabled target. Null when there is none or the choice is ambiguous.
	 */
	public static function shelfTarget(): ?BackupTarget {
		$id = (int)Globalvars::get_instance()->get_setting('server_manager_services_shelf_target_id', false, true);
		if ($id > 0) {
			try {
				$target = new BackupTarget($id, TRUE);
				return ($target->key && $target->get('bkt_enabled') && !$target->get('bkt_delete_time')) ? $target : null;
			} catch (\Throwable $e) {
				return null;
			}
		}
		$sole = null;
		$count = 0;
		$enabled = new MultiBackupTarget(array('enabled' => true, 'deleted' => false));
		foreach ($enabled as $candidate) {
			$count++;
			$sole = $candidate;
		}
		return $count === 1 ? $sole : null;
	}

	/** Backup storage's path prefix (no trailing slash); every tenant lives under {prefix}/{slug}/. */
	public static function shelfPathPrefix(BackupTarget $target): string {
		return rtrim(trim((string)$target->get('bkt_path_prefix')) ?: 'joinery-backups', '/');
	}

	// ── The row ───────────────────────────────────────────────────────────────

	/** A host label as the site reports it; '' when it is not a hostname. */
	public static function cleanHost(string $host): string {
		$host = strtolower(trim($host));
		$host = preg_replace('#^https?://#', '', $host);
		$host = rtrim($host, '/');
		if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $host)) {
			return '';
		}
		return $host;
	}

	/**
	 * The tenant row for this key and service, created at `unpaid` on first
	 * contact. The host label is refreshed every time the site says it.
	 */
	public static function tenant(int $user_id, int $key_id, string $service, string $host): ServiceTenant {
		if (!in_array($service, ServiceTenant::SERVICES, true)) {
			throw new JoineryServicesException('Unknown service: ' . $service);
		}
		if ($key_id <= 0) {
			throw new JoineryServicesException('A site is identified by its connected key; this request carried none.');
		}
		$row = ServiceTenant::forKey($key_id, $service);
		if ($row === null) {
			$row = new ServiceTenant(NULL);
			$row->set('svt_usr_user_id', $user_id);
			$row->set('svt_apk_api_key_id', $key_id);
			$row->set('svt_service', $service);
			$row->set('svt_state', ServiceTenant::STATE_UNPAID);
			$row->set('svt_allowance', self::allowance($service));
		} elseif ((int)$row->get('svt_usr_user_id') !== $user_id) {
			throw new JoineryServicesException('This key belongs to a different account than the tenant row it names.');
		}
		if ($host !== '' && $host !== (string)$row->get('svt_host')) {
			$row->set('svt_host', $host);
		}
		$row->save();
		if (trim((string)$row->get('svt_slug')) === '') {
			$row->set('svt_slug', 't' . intval($row->key));
			$row->save();
		}
		return $row;
	}

	// ── Enrol ─────────────────────────────────────────────────────────────────

	/**
	 * Enrol the site for one service. Returns the response the site acts on;
	 * throws JoineryServicesException with a user-facing sentence on refusal.
	 */
	public static function enrol(int $user_id, int $key_id, string $service, string $host,
			?Smtp2GoClient $client = null): array {
		$host = self::cleanHost($host);
		if ($host === '') {
			throw new JoineryServicesException('The site has to say its own hostname to enrol.');
		}
		$row = self::tenant($user_id, $key_id, $service, $host);
		$now = gmdate('Y-m-d H:i:s');

		if (!$row->entitled($now)) {
			return self::statusOf($row) + array('entitled' => false);
		}

		// Entitled from a stopped rung: back in place first.
		$state = (string)$row->get('svt_state');
		if ($state === ServiceTenant::STATE_SUSPENDED || $state === ServiceTenant::STATE_RELEASED) {
			self::reactivate($row, $client);
		}

		if ($service === ServiceTenant::SERVICE_MAIL) {
			$out = self::enrolMail($row, $host, $client ?? Smtp2GoClient::fromSettings());
		} else {
			$out = self::enrolShelf($row);
		}
		return self::statusOf($row) + array('entitled' => true) + $out;
	}

	/**
	 * Build the tenant's mail at the provider, all at once, and hand back the
	 * send values plus the DNS records. The password is not kept here.
	 */
	private static function enrolMail(ServiceTenant $row, string $host, ?Smtp2GoClient $client): array {
		if ($client === null) {
			$row->set('svt_notice', 'Outbound mail is not configured on this plane yet.');
			$row->save();
			throw new JoineryServicesException('Outbound mail is not available from this operator yet: no mail provider is configured.');
		}
		$user = new User((int)$row->get('svt_usr_user_id'), TRUE);
		$prefix = self::externalNamePrefix($row);
		if ((string)$row->get('svt_state') === ServiceTenant::STATE_UNPAID) {
			$row->set('svt_state', ServiceTenant::STATE_PROVISIONING);
			$row->save();
		}

		try {
			// The subaccount, once. Saved the moment it exists: a crash before
			// the limit call must not orphan it at the provider.
			$subaccount = trim((string)$row->get('svt_provider_subaccount_id'));
			if ($subaccount === '') {
				$subaccount = Smtp2GoLeg::createSubaccount($client,
					$prefix . 'Joinery services — ' . $host, trim((string)$user->get('usr_email')));
				$row->set('svt_provider_subaccount_id', $subaccount);
				$row->save();
			}
			Smtp2GoLeg::setLimit($client, $subaccount, self::allowance(ServiceTenant::SERVICE_MAIL));

			// The sender domain is the host's: a host change adds the new one
			// and releases the old (D2). Same host, same domain, nothing to do.
			$sender = Smtp2GoLeg::sendingDomain($host);
			$current = trim((string)$row->get('svt_provider_domain'));
			if ($current !== $sender) {
				if ($current !== '') {
					try {
						$client->removeDomain($subaccount, $current);
					} catch (\Throwable $e) {
						error_log('JoineryServices: releasing old sender domain ' . $current . ' failed: ' . $e->getMessage());
					}
				}
				$result = Smtp2GoLeg::addSenderDomain($client, $subaccount, $sender);
				$row->set('svt_provider_domain', $sender);
				$row->set('svt_mail_records', json_encode($result['records']));
				$row->set('svt_mail_state', ServiceTenant::MAIL_DOMAIN_ADDED);
				$row->save();
			}

			// The credential: fresh every enrol, the previous one removed. The
			// password lives in this response and in the site's sealed setting,
			// nowhere on this plane.
			$previous = trim((string)$row->get('svt_provider_user_id'));
			if ($previous !== '') {
				try {
					$client->removeSmtpUser($subaccount, $previous);
				} catch (\Throwable $e) {
					error_log('JoineryServices: removing SMTP user ' . $previous . ' failed: ' . $e->getMessage());
				}
			}
			$minted = Smtp2GoLeg::mintSmtpUser($client, $subaccount, (string)$row->get('svt_slug'), $prefix);
			$row->set('svt_provider_user_id', $minted['username'] !== '' ? $minted['username'] : $minted['id']);
			$row->set('svt_state', ServiceTenant::STATE_ACTIVE);
			$row->set('svt_notice', null);
			$row->save();

			self::probeMailDomain($row, $client);
		} catch (Smtp2GoLegException $e) {
			$row->set('svt_notice', $e->getMessage());
			$row->save();
			throw new JoineryServicesException($e->getMessage());
		} catch (Smtp2GoException $e) {
			$row->set('svt_notice', 'The mail provider refused a step: ' . $e->getMessage());
			$row->save();
			throw new JoineryServicesException('The mail provider refused a step: ' . $e->getMessage());
		}

		return array('mail' => Smtp2GoLeg::sendValues($minted['username'], $minted['password'], $sender)
			+ array('domain' => $sender, 'domain_state' => (string)$row->get('svt_mail_state'),
				'records' => $row->mailRecords()));
	}

	/** Ask the provider whether the sender domain verifies yet; record the answer. */
	public static function probeMailDomain(ServiceTenant $row, ?Smtp2GoClient $client): void {
		if ($client === null || (string)$row->get('svt_mail_state') === ServiceTenant::MAIL_DOMAIN_VERIFIED) {
			return;
		}
		$subaccount = trim((string)$row->get('svt_provider_subaccount_id'));
		$domain = trim((string)$row->get('svt_provider_domain'));
		if ($subaccount === '' || $domain === '') {
			return;
		}
		try {
			if ($client->verifyDomain($subaccount, $domain)) {
				$row->set('svt_mail_state', ServiceTenant::MAIL_DOMAIN_VERIFIED);
				$row->save();
			}
		} catch (\Throwable $e) {
			error_log('JoineryServices: verify probe for ' . $domain . ' failed: ' . $e->getMessage());
		}
	}

	/** Backup storage: nothing minted. A slug and a prefix, and the broker signs inside them. */
	private static function enrolShelf(ServiceTenant $row): array {
		$target = self::shelfTarget();
		if ($target === null) {
			$row->set('svt_notice', 'No backup storage target is configured on this plane.');
			$row->save();
			throw new JoineryServicesException('Backup storage is not available from this operator yet: no backup storage target is configured.');
		}
		$row->set('svt_state', ServiceTenant::STATE_ACTIVE);
		$row->set('svt_notice', null);
		$row->save();
		return array('shelf' => self::shelfCoordinates($row, $target));
	}

	/** What the site writes into its `managed` target row: no credential in it. */
	public static function shelfCoordinates(ServiceTenant $row, BackupTarget $target): array {
		$creds = array();
		try {
			$creds = (array)$target->get_credentials();
		} catch (\Throwable $e) {
			// A shelf whose credential cannot be opened is the plane's problem
			// to notice (the target's Test button); the site still gets its
			// coordinates and the broker says so when it is asked to sign.
		}
		$path_prefix = self::shelfPathPrefix($target);
		$slug = (string)$row->get('svt_slug');
		return array(
			'slug'           => $slug,
			'path_prefix'    => $path_prefix,
			'prefix'         => $path_prefix . '/' . $slug . '/',
			'retention_days' => ServiceTenant::RETENTION_DAYS,
			'provider'       => (string)$target->get('bkt_provider'),
			'bucket'         => (string)$target->get('bkt_bucket'),
			'region'         => (string)($creds['region'] ?? ''),
			'endpoint'       => (string)($creds['endpoint'] ?? ''),
		);
	}

	// ── Status ────────────────────────────────────────────────────────────────

	/**
	 * Every service the site holds a row for, in the C2 shape. Refreshes the
	 * host label and, for mail not yet verified, asks the provider once.
	 */
	public static function status(int $user_id, int $key_id, string $host, ?Smtp2GoClient $client = null): array {
		$host = self::cleanHost($host);
		$services = array();
		$rows = new MultiServiceTenant(array('api_key_id' => $key_id, 'deleted' => false),
			array('svt_service_tenant_id' => 'ASC'));
		$probe_client = null;
		$probe_resolved = false;
		foreach ($rows as $row) {
			if ((int)$row->get('svt_usr_user_id') !== $user_id) {
				continue;
			}
			if ($host !== '' && $host !== (string)$row->get('svt_host')) {
				$row->set('svt_host', $host);
				$row->save();
			}
			if ((string)$row->get('svt_service') === ServiceTenant::SERVICE_MAIL
					&& (string)$row->get('svt_mail_state') === ServiceTenant::MAIL_DOMAIN_ADDED) {
				if (!$probe_resolved) {
					$probe_client = $client ?? Smtp2GoClient::fromSettings();
					$probe_resolved = true;
				}
				self::probeMailDomain($row, $probe_client);
			}
			$services[(string)$row->get('svt_service')] = self::statusOf($row);
		}
		$account = '';
		try {
			$user = new User($user_id, TRUE);
			$account = $user->key ? (string)$user->get('usr_email') : '';
		} catch (\Throwable $e) {
		}
		return array(
			'connected'  => true,
			'account'    => $account,
			'manage_url' => self::manageUrl(),
			'services'   => $services,
		);
	}

	/** One row's C2 fields. */
	public static function statusOf(ServiceTenant $row): array {
		$service = (string)$row->get('svt_service');
		$allowance = (int)$row->get('svt_allowance') ?: self::allowance($service);
		$figure = self::currentFigure($row);
		$percent = $allowance > 0 ? (int)round(100 * $figure / $allowance) : 0;
		$out = array(
			'service'      => $service,
			'state'        => (string)$row->get('svt_state'),
			'paid_until'   => (string)$row->get('svt_paid_until') ?: null,
			'entitled'     => $row->entitled(),
			'figure'       => $figure,
			'allowance'    => $allowance,
			'unit'         => $service === ServiceTenant::SERVICE_MAIL ? 'sends' : 'bytes',
			'label'        => $service === ServiceTenant::SERVICE_MAIL ? 'Email sent this month' : 'Backups stored',
			'used_label'   => self::formatFigure($service, $figure),
			'allowance_label' => self::formatFigure($service, $allowance),
			'percent'      => $percent,
			'notice'       => (string)$row->get('svt_notice'),
			'action_label' => self::actionLabel($service),
			'action_url'   => self::referralUrl($service),
			'manage_url'   => self::manageUrl(),
			'grace_ends'   => ServiceTenantLadder::graceEnds($row, 'svt_lapse_time', self::graceDays()),
			'prune_after'  => (string)$row->get('svt_prune_after_time') ?: null,
		);
		if ($service === ServiceTenant::SERVICE_MAIL) {
			$out['domain'] = (string)$row->get('svt_provider_domain');
			$out['domain_state'] = (string)$row->get('svt_mail_state');
			$out['records'] = $row->mailRecords();
		} else {
			$out['slug'] = (string)$row->get('svt_slug');
			$out['retention_days'] = ServiceTenant::RETENTION_DAYS;
		}
		return $out;
	}

	/**
	 * The figure as of now. A mail figure is a month-to-date count, so one
	 * measured in an earlier month reads as 0 until this month's first event.
	 */
	public static function currentFigure(ServiceTenant $row): int {
		if ((string)$row->get('svt_service') === ServiceTenant::SERVICE_MAIL) {
			$measured = trim((string)$row->get('svt_figure_time'));
			if ($measured === '' || substr($measured, 0, 7) !== gmdate('Y-m')) {
				return 0;
			}
		}
		return (int)$row->get('svt_figure');
	}

	public static function formatFigure(string $service, int $value): string {
		if ($service === ServiceTenant::SERVICE_MAIL) {
			return number_format($value);
		}
		// The same shape the hosted banner uses, so the two never disagree.
		$gb = $value / 1073741824;
		if ($gb < 0.1) {
			return round($gb * 1024) . ' MB';
		}
		return round($gb, 1) . ' GB';
	}

	/**
	 * The webhook's nudge: one delivered event for the SMTP username (or the
	 * subaccount) a tenant holds. Returns true when a tenant took it. The
	 * reconcile overwrites the figure with the provider's own count each pass,
	 * so this only moves the banner between reads.
	 */
	public static function countWebhookSend(string $username, string $subaccount): bool {
		$row = null;
		if ($username !== '') {
			$rows = new MultiServiceTenant(array('provider_user_id' => $username, 'service' => ServiceTenant::SERVICE_MAIL,
				'deleted' => false), array('svt_service_tenant_id' => 'DESC'), 1);
			foreach ($rows as $r) { $row = $r; }
		}
		if ($row === null && $subaccount !== '') {
			$rows = new MultiServiceTenant(array('provider_subaccount_id' => $subaccount, 'service' => ServiceTenant::SERVICE_MAIL,
				'deleted' => false), array('svt_service_tenant_id' => 'DESC'), 1);
			foreach ($rows as $r) { $row = $r; }
		}
		if ($row === null) {
			return false;
		}
		$row->set('svt_figure', self::currentFigure($row) + 1);
		$row->set('svt_figure_time', gmdate('Y-m-d H:i:s'));
		$row->save();
		return true;
	}

	// ── Release, grant, and the ladder's two acts ─────────────────────────────

	/** The customer's exit: stop the service now, start the retention clock. */
	public static function release(int $user_id, int $key_id, string $service, ?Smtp2GoClient $client = null): array {
		$row = ServiceTenant::forKey($key_id, $service);
		if ($row === null || (int)$row->get('svt_usr_user_id') !== $user_id) {
			throw new JoineryServicesException('This site holds no ' . $service . ' service to release.');
		}
		if ((string)$row->get('svt_state') !== ServiceTenant::STATE_RELEASED) {
			self::stop($row, ServiceTenant::STATE_RELEASED, $client);
		}
		return self::statusOf($row) + array('released' => true);
	}

	/** Release from the operator's side (the admin page, the Disconnect path). */
	public static function releaseRow(ServiceTenant $row, ?Smtp2GoClient $client = null): void {
		if ((string)$row->get('svt_state') !== ServiceTenant::STATE_RELEASED) {
			self::stop($row, ServiceTenant::STATE_RELEASED, $client);
		}
	}

	/**
	 * The ladder's suspend act, once the row is saved suspended: the provider
	 * side stops, and the retention clock starts.
	 */
	public static function suspend(ServiceTenant $row, ?Smtp2GoClient $client = null, ?string $now = null): void {
		self::stop($row, ServiceTenant::STATE_SUSPENDED, $client, $now);
	}

	private static function stop(ServiceTenant $row, string $state, ?Smtp2GoClient $client, ?string $now = null): void {
		$now = $now ?? gmdate('Y-m-d H:i:s');
		$service = (string)$row->get('svt_service');
		if ($service === ServiceTenant::SERVICE_MAIL) {
			$subaccount = trim((string)$row->get('svt_provider_subaccount_id'));
			if ($subaccount !== '') {
				$client = $client ?? Smtp2GoClient::fromSettings();
				if ($client === null) {
					throw new JoineryServicesException('Outbound mail cannot be stopped: no mail provider is configured on this plane.');
				}
				$client->closeSubaccount($subaccount);
			}
		}
		$row->set('svt_state', $state);
		$row->set('svt_revoked_time', $now);
		$row->set('svt_prune_after_time', $service === ServiceTenant::SERVICE_SHELF
			? LibraryFunctions::time_shift($now, ServiceTenant::RETENTION_DAYS . ' days', 'Y-m-d H:i:s') : null);
		$row->set('svt_notice', $state === ServiceTenant::STATE_RELEASED
			? self::releasedNotice($service)
			: self::suspendedNotice($service));
		$row->save();
	}

	/**
	 * The ladder's reactivate act, and the enrol path's from a stopped rung:
	 * back in place, the clocks cleared.
	 */
	public static function reactivate(ServiceTenant $row, ?Smtp2GoClient $client = null): void {
		if ((string)$row->get('svt_service') === ServiceTenant::SERVICE_MAIL) {
			$subaccount = trim((string)$row->get('svt_provider_subaccount_id'));
			if ($subaccount !== '') {
				$client = $client ?? Smtp2GoClient::fromSettings();
				if ($client === null) {
					throw new JoineryServicesException('Outbound mail cannot be restarted: no mail provider is configured on this plane.');
				}
				$client->reopenSubaccount($subaccount);
			}
		}
		$row->set('svt_state', ServiceTenant::STATE_ACTIVE);
		$row->set('svt_lapse_time', null);
		$row->set('svt_revoked_time', null);
		$row->set('svt_prune_after_time', null);
		$row->set('svt_notice', null);
		$row->save();
	}

	/**
	 * Write the paid-through date. In this phase an operator's act; a payment
	 * writes the same column later. A stopped row with a date ahead comes
	 * back in place now, not on the next reconcile; an unpaid row waits for
	 * the site's next enrol, which is what mints.
	 */
	public static function grant(ServiceTenant $row, ?string $paid_until, ?Smtp2GoClient $client = null): void {
		$until = null;
		if ($paid_until !== null && trim($paid_until) !== '') {
			// A bare date means the whole of that day.
			$stamp = strtotime(trim($paid_until) . (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($paid_until)) ? ' 23:59:59' : '') . ' UTC');
			if ($stamp === false) {
				throw new JoineryServicesException('That is not a date.');
			}
			$until = gmdate('Y-m-d H:i:s', $stamp);
		}
		$row->set('svt_paid_until', $until);
		$row->set('svt_allowance', self::allowance((string)$row->get('svt_service')));
		$row->save();
		$state = (string)$row->get('svt_state');
		if ($row->entitled() && ($state === ServiceTenant::STATE_SUSPENDED || $state === ServiceTenant::STATE_RELEASED)) {
			self::reactivate($row, $client);
		}
	}

	public static function suspendedNotice(string $service): string {
		if ($service === ServiceTenant::SERVICE_MAIL) {
			return 'Outbound mail through getjoinery has stopped: the paid-through date has passed. '
				. 'Renew to send again, or move to your own email account.';
		}
		return 'Offsite backups to the getjoinery backup storage have stopped: the paid-through date has passed. '
			. 'Your local backups continue. Backup storage is kept ' . ServiceTenant::RETENTION_DAYS
			. ' days from today; renew before then and it carries on in place.';
	}

	public static function releasedNotice(string $service): string {
		if ($service === ServiceTenant::SERVICE_MAIL) {
			return 'Outbound mail through getjoinery is closed. The mail.<domain> records it published can be removed.';
		}
		return 'The getjoinery backup storage is no longer used. Its copies are kept ' . ServiceTenant::RETENTION_DAYS
			. ' days from today and then pruned.';
	}

	/**
	 * What a test-mode account's things at the provider are named with, so
	 * nothing minted for a rehearsal reads as real. A tenant has no purchase to
	 * read it from in this phase; the plane's own sandbox switch is the signal.
	 */
	public static function externalNamePrefix(ServiceTenant $row): string {
		return Smtp2GoLeg::sandboxUsers() ? CustomerCloudProvision::TEST_NAME_PREFIX : '';
	}
}
