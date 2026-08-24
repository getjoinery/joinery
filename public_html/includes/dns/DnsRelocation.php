<?php
/**
 * DnsRelocation - moves a domain's DNS answering to a host the platform can
 * automate (specs/wizard_dns_relocation.md, Part 3).
 *
 * The problem it solves: a domain whose DNS lives at a gated registrar
 * (apiGateNote) cannot take an automatic publish, today or ever. Registration
 * and DNS hosting are separable, so the operator keeps the domain where it is
 * and changes its nameservers once — an ordinary dashboard setting, no API —
 * to a free host whose API is open to everyone. This class does everything
 * around that one manual step.
 *
 * **The order is the whole design.** The destination zone is created and
 * seeded — with everything the deployment needs AND everything the domain
 * visibly answers today — BEFORE the operator flips nameservers. Flipping
 * first would take the site and its mail down while the new zone sat empty.
 *
 * **The copy is honest about its limits.** DNS cannot be enumerated from
 * outside: only guessable names (the apex, www, _dmarc, common DKIM
 * selectors) are resolved and recreated verbatim. seed() returns exactly what
 * was copied so the page can show it and say plainly that a subdomain it did
 * not guess will not carry over.
 *
 * **Copied reality wins over the plan at seed time.** A domain's existing SPF
 * or DMARC is recreated verbatim even when the deployment's plan wants a
 * different value — the move must change nothing observable. After
 * delegation, the ordinary publish rail surfaces any difference as a diff the
 * operator confirms, which is where a change of value belongs.
 *
 * Nothing about the move is persisted, matching the wizard's standing rule:
 * before the nameserver change, pressing the button again re-runs an
 * idempotent seed; after it, the domain's own NS records identify the new
 * host and every surface takes its normal automatable path.
 *
 * **The move is only offered to a domain that serves nothing but this
 * deployment.** foreignUse() reads the apex — addresses, mail routing, sender
 * policy — and answers with the first sign the domain is lived-in: mail
 * delivered somewhere else, an address record for another server, an SPF for
 * another setup. A lived-in domain is exactly the one whose unguessable
 * records make a relocation dangerous, and its operator pastes a handful of
 * records by hand instead. The check fails closed: when this server's own
 * address cannot be established, the offer is withheld, never mis-shown.
 *
 * @version 1.2 - foreignUse()/classifyForeign(): the lived-in detection that
 *                gates whether a move is offered at all
 * @version 1.1 - registrarNameserverHelp knows a Cloudflare-hosted source: the
 *                nameserver change happens at the registrar, not Cloudflare
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsDriverRegistry.php'));
require_once(PathHelper::getIncludePath('includes/dns/DnsPublishBox.php'));
require_once(PathHelper::getIncludePath('includes/dns/DnsReconciler.php'));
require_once(PathHelper::getIncludePath('includes/dns/DnsRecordPlan.php'));

class DnsRelocation {

	/**
	 * DKIM selector names worth guessing at {selector}._domainkey.{domain}.
	 * Google, Microsoft, Mailgun, SendGrid, Postmark, Zoho, Fastmail and the
	 * generic defaults between them cover most real domains.
	 */
	const DKIM_SELECTORS = array(
		'default', 'google', 'selector1', 'selector2', 'k1', 'k2', 'k3',
		's1', 's2', 'mx', 'smtp', 'mail', 'dkim', 'zoho', 'pm',
		'fm1', 'fm2', 'fm3', 'mandrill', 'everlytickey1', 'everlytickey2',
	);

	/**
	 * The destinations the platform recommends, as [key => driver class].
	 *
	 * A deliberate pair, not a capability scan: Linode (free with any active
	 * Linode service — the quick-deploy audience holds one by definition) and
	 * Cloudflare's free plan (free for anyone). Every other open-API host
	 * stays reachable through the normal publish path once a domain is
	 * delegated to it; recommending all of them here would turn one clear
	 * choice into a quiz.
	 */
	public static function targets(): array {
		$out = array();
		foreach (array('linode', 'cloudflare') as $key) {
			$class = DnsDriverRegistry::get($key);
			if ($class !== null
					&& $class::credentialMode() === DnsProvider::CREDENTIAL_API
					&& $class::apiGateNote() === '') {
				$out[$key] = $class;
			}
		}
		return $out;
	}

	/**
	 * Create (if the driver can) and seed the destination zone, and return
	 * everything the handover page needs. Idempotent: the zone create skips an
	 * existing zone and the publish is additive, so pressing the button twice
	 * changes nothing.
	 *
	 * @param string        $driver_class A targets() driver.
	 * @param array         $credential   Used inside this call and never stored.
	 * @param string        $domain       The zone to move.
	 * @param DnsRecordPlan $own_plan     What the deployment itself needs
	 *                                    published (may be empty).
	 * @return array{error:string,zone_created:bool,nameservers:string[],
	 *               copied:array,summary:string}
	 */
	public static function seed(string $driver_class, array $credential, string $domain,
			DnsRecordPlan $own_plan): array {

		$out = array('error' => '', 'zone_created' => false, 'nameservers' => array(),
			'copied' => array(), 'summary' => '');
		$domain = DnsRecord::normalizeName($domain);
		if ($domain === '') {
			$out['error'] = 'No domain to move.';
			return $out;
		}
		try {
			/** @var DnsProvider $driver */
			$driver = new $driver_class($credential);
			$zone = $driver->zoneFor($domain);
			if ($zone === null) {
				if ($driver_class::supportsZones()) {
					$zone = $driver->createZone($domain);
					$out['zone_created'] = true;
				} else {
					// Cloudflare-shaped vendors create zones in their own
					// dashboard (which also shows the operator their assigned
					// nameservers). Say the one step and stop.
					$out['error'] = 'This ' . $driver_class::getLabel() . ' account has no zone for '
						. $domain . ' yet. Add the domain as a site there first — its dashboard walks '
						. 'you through it on the free plan — then press this again.';
					return $out;
				}
			}

			$copied = self::visibleRecords($domain);
			$plan = self::seedPlan($own_plan, $copied, $domain);
			$publish = DnsPublishBox::publish($driver_class, $credential, $plan,
				array(), DnsReconciler::APPLY_ADDITIVE);
			if (!empty($publish['error'])) {
				$out['error'] = (string)$publish['error'];
				return $out;
			}
			$out['summary'] = DnsPublishBox::summarizeResults($publish);
			foreach ($copied as $record) {
				$out['copied'][] = array(
					'type' => $record->type, 'name' => $record->name, 'value' => $record->value,
				);
			}
			$out['nameservers'] = $driver->zoneNameservers($zone);
		} catch (Throwable $e) {
			$out['error'] = $e->getMessage();
		}
		return $out;
	}

	/**
	 * The first visible sign that the domain is used for anything beyond this
	 * deployment, or '' when the move is safe to offer. Four resolver
	 * questions, apex only — cheap enough for a render.
	 *
	 * @param string             $domain   The domain the move would relocate.
	 * @param DnsRecordPlan|null $own_plan What this deployment itself wants
	 *                                     published; its values count as ours.
	 */
	public static function foreignUse(string $domain, ?DnsRecordPlan $own_plan): string {
		$domain = DnsRecord::normalizeName($domain);
		if ($domain === '') {
			return '';
		}
		$visible = array();
		foreach (self::lookup($domain, DNS_A) as $row) {
			$visible[] = new DnsRecord(DnsRecord::TYPE_A, $domain, (string)$row['ip']);
		}
		foreach (self::lookup($domain, DNS_AAAA) as $row) {
			$visible[] = new DnsRecord(DnsRecord::TYPE_AAAA, $domain, (string)$row['ipv6']);
		}
		foreach (self::lookup($domain, DNS_MX) as $row) {
			$visible[] = new DnsRecord(DnsRecord::TYPE_MX, $domain,
				DnsRecord::normalizeName((string)$row['target']), null, (int)($row['pri'] ?? 10));
		}
		foreach (self::lookup($domain, DNS_TXT) as $row) {
			$visible[] = new DnsRecord(DnsRecord::TYPE_TXT, $domain, (string)$row['txt']);
		}
		return self::classifyForeign($domain, $visible, $own_plan, self::serverAddresses());
	}

	/**
	 * Every address this machine answers as, from the request socket and the
	 * local interfaces — no network call. On the bare-VPS topology this
	 * platform deploys to, the public address is on an interface, so the apex
	 * pointing here is recognized from the CLI as well as from a request. A
	 * fronted deployment's apex holds the proxy's address instead, which no
	 * list here contains — the offer is withheld there, which is fail-closed
	 * and correct: a proxied domain is configured, not fresh.
	 */
	private static function serverAddresses(): array {
		$out = array();
		$addr = trim((string)($_SERVER['SERVER_ADDR'] ?? ''));
		if ($addr !== '') {
			$out[] = $addr;
		}
		if (function_exists('net_get_interfaces')) {
			foreach ((array)@net_get_interfaces() as $iface) {
				foreach ((array)($iface['unicast'] ?? array()) as $unicast) {
					$ip = (string)($unicast['address'] ?? '');
					if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
						$out[] = $ip;
					}
				}
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * The classification itself, separated from the lookups so it is testable.
	 * A record is ours when the deployment's own plan carries its value or it
	 * names this server; anything else visible at the apex is evidence of a
	 * lived-in domain. Only the signals whose loss is catastrophic are read —
	 * mail routing, addresses, sender policy. A benign apex TXT (a site
	 * verification) blocks nothing: it is copied verbatim by the seed anyway.
	 *
	 * @param DnsRecord[] $visible What the apex answers.
	 * @return string '' when nothing forecloses the move, else one sentence.
	 */
	public static function classifyForeign(string $domain, array $visible,
			?DnsRecordPlan $own, array $server_ips = array()): string {

		$plan_mx = array();
		$plan_addr = array();
		$plan_spf = '';
		if ($own !== null) {
			foreach ($own->getRecords() as $record) {
				if (!empty($record->absent)) {
					continue;
				}
				if ($record->type === DnsRecord::TYPE_MX) {
					$plan_mx[DnsRecord::normalizeName($record->value)] = true;
				} elseif ($record->type === DnsRecord::TYPE_A || $record->type === DnsRecord::TYPE_AAAA) {
					// Keyed by value alone on purpose: any address the plan
					// publishes anywhere in the zone is this deployment's.
					$plan_addr[$record->value] = true;
				} elseif ($record->type === DnsRecord::TYPE_TXT
						&& DnsRecord::normalizeName($record->name) === $domain
						&& stripos(trim($record->value), 'v=spf1') === 0) {
					$plan_spf = trim($record->value);
				}
			}
		}

		foreach ($visible as $record) {
			if ($record->type === DnsRecord::TYPE_MX) {
				$target = DnsRecord::normalizeName($record->value);
				if (!isset($plan_mx[$target])) {
					return 'mail for ' . $domain . ' is already delivered elsewhere (MX ' . $target . ')';
				}
			} elseif ($record->type === DnsRecord::TYPE_A || $record->type === DnsRecord::TYPE_AAAA) {
				if (!in_array($record->value, $server_ips, true) && !isset($plan_addr[$record->value])) {
					return $domain . ' points at a server that is not this one (' . $record->value . ')';
				}
			} elseif ($record->type === DnsRecord::TYPE_TXT
					&& stripos(trim($record->value), 'v=spf1') === 0) {
				if (trim($record->value) !== $plan_spf) {
					return $domain . ' already publishes a sender policy for another setup (SPF)';
				}
			}
		}
		return '';
	}

	/**
	 * What the domain visibly answers today, at the names worth guessing.
	 * Recreated verbatim — copy, never improve. TTLs are left to the
	 * destination's default: a resolver's answer carries only the time
	 * remaining in its cache, which is not the zone's TTL.
	 *
	 * @return DnsRecord[]
	 */
	public static function visibleRecords(string $domain): array {
		$domain = DnsRecord::normalizeName($domain);
		$out = array();

		// Apex: address records, mail routing, free-form TXT, issuance policy.
		foreach (self::lookup($domain, DNS_A) as $row) {
			$out[] = new DnsRecord(DnsRecord::TYPE_A, $domain, (string)$row['ip']);
		}
		foreach (self::lookup($domain, DNS_AAAA) as $row) {
			$out[] = new DnsRecord(DnsRecord::TYPE_AAAA, $domain, (string)$row['ipv6']);
		}
		foreach (self::lookup($domain, DNS_MX) as $row) {
			$out[] = new DnsRecord(DnsRecord::TYPE_MX, $domain,
				DnsRecord::normalizeName((string)$row['target']), null, (int)($row['pri'] ?? 10));
		}
		foreach (self::lookup($domain, DNS_TXT) as $row) {
			$out[] = new DnsRecord(DnsRecord::TYPE_TXT, $domain, (string)$row['txt']);
		}
		foreach (self::lookup($domain, DNS_CAA) as $row) {
			$out[] = new DnsRecord(DnsRecord::TYPE_CAA, $domain,
				DnsDriverBase::formatCaa((int)($row['flags'] ?? 0),
					(string)($row['tag'] ?? 'issue'), (string)($row['value'] ?? '')));
		}

		// www: a CNAME excludes everything else at the name, so it wins.
		$out = array_merge($out, self::hostRecords('www.' . $domain));

		// _dmarc and the guessable DKIM selectors: TXT, or the CNAME many
		// providers delegate them through.
		foreach (self::lookup('_dmarc.' . $domain, DNS_TXT) as $row) {
			$out[] = new DnsRecord(DnsRecord::TYPE_TXT, '_dmarc.' . $domain, (string)$row['txt']);
		}
		foreach (self::DKIM_SELECTORS as $selector) {
			$name = $selector . '._domainkey.' . $domain;
			$cname = self::lookup($name, DNS_CNAME);
			if ($cname) {
				$out[] = new DnsRecord(DnsRecord::TYPE_CNAME, $name,
					DnsRecord::normalizeName((string)$cname[0]['target']));
				continue;
			}
			foreach (self::lookup($name, DNS_TXT) as $row) {
				$out[] = new DnsRecord(DnsRecord::TYPE_TXT, $name, (string)$row['txt']);
			}
		}
		return $out;
	}

	/**
	 * The seed plan: copied reality first and verbatim, then the deployment's
	 * own records wherever they do not collide with something copied. The
	 * collision rules exist so the seed can never publish a contradiction —
	 * two SPF records is a permanent SPF failure, a second DMARC is undefined,
	 * and nothing may sit beside a CNAME.
	 */
	public static function seedPlan(DnsRecordPlan $own, array $copied, string $domain): DnsRecordPlan {
		$plan = new DnsRecordPlan($domain, $own->getOwner() !== '' ? $own->getOwner() : 'dns_relocation');
		$cname_names = array();
		$spf_names   = array();
		$typed       = array();
		$exact       = array();

		foreach ($copied as $record) {
			$plan->add($record);
			$name = DnsRecord::normalizeName($record->name);
			$exact[$record->type . '|' . $name . '|' . $record->value] = true;
			$typed[$record->type . '|' . $name] = true;
			if ($record->type === DnsRecord::TYPE_CNAME) {
				$cname_names[$name] = true;
			}
			if ($record->type === DnsRecord::TYPE_TXT && stripos(trim($record->value), 'v=spf1') === 0) {
				$spf_names[$name] = true;
			}
		}

		foreach ($own->getRecords() as $record) {
			if (!empty($record->absent)) {
				continue;   // a removal has no meaning in a zone being born
			}
			$name = DnsRecord::normalizeName($record->name);
			if (isset($exact[$record->type . '|' . $name . '|' . $record->value])) {
				continue;   // already copied verbatim
			}
			if (isset($cname_names[$name])) {
				continue;   // nothing coexists with a copied CNAME
			}
			if ($record->type === DnsRecord::TYPE_TXT
					&& stripos(trim($record->value), 'v=spf1') === 0 && isset($spf_names[$name])) {
				continue;   // the domain already publishes an SPF — copied wins
			}
			if (strpos($name, '_dmarc.') === 0 && isset($typed[DnsRecord::TYPE_TXT . '|' . $name])) {
				continue;   // an existing DMARC policy outranks the plan default
			}
			if (in_array($record->type, array(DnsRecord::TYPE_CNAME, DnsRecord::TYPE_MX), true)
					&& isset($typed[$record->type . '|' . $name])) {
				continue;   // routing the domain already has is not changed by a move
			}
			$plan->add($record);
		}
		return $plan;
	}

	/**
	 * Where the nameserver setting lives at the source host, in that vendor's
	 * own menu words where they are known.
	 */
	public static function registrarNameserverHelp(string $source_key): string {
		switch ($source_key) {
			case 'namecheap':
				return 'At Namecheap: open Domain List, press Manage next to the domain, and find the '
					. 'Nameservers box — switch it to Custom DNS and enter the names above.';
			case 'godaddy':
				return 'At GoDaddy: open My Products, choose DNS next to the domain, then Nameservers, '
					. 'press Change, and enter the names above.';
			case 'cloudflare':
				// Cloudflare answers the DNS but the nameservers are set at the
				// registrar the domain was bought from — pointing someone at
				// Cloudflare's dashboard for this change would strand them.
				return 'Your DNS is answered by Cloudflare, but nameservers are set where the domain '
					. 'is registered — go to that registrar\'s domain settings and replace the two '
					. 'Cloudflare names with the ones above.';
		}
		return 'At your registrar, find the domain\'s nameserver setting (usually under domain '
			. 'management) and replace the current names with the ones above.';
	}

	// ------------------------------------------------------------------

	/** A CNAME excludes everything else at a name, so it wins when present. */
	private static function hostRecords(string $name): array {
		$out = array();
		$cname = self::lookup($name, DNS_CNAME);
		if ($cname) {
			$out[] = new DnsRecord(DnsRecord::TYPE_CNAME, $name,
				DnsRecord::normalizeName((string)$cname[0]['target']));
			return $out;
		}
		foreach (self::lookup($name, DNS_A) as $row) {
			$out[] = new DnsRecord(DnsRecord::TYPE_A, $name, (string)$row['ip']);
		}
		foreach (self::lookup($name, DNS_AAAA) as $row) {
			$out[] = new DnsRecord(DnsRecord::TYPE_AAAA, $name, (string)$row['ipv6']);
		}
		return $out;
	}

	/** One resolver question that can never throw a page off the rails. */
	private static function lookup(string $name, int $type): array {
		try {
			$rows = @dns_get_record($name, $type);
		} catch (Throwable $e) {
			return array();
		}
		return is_array($rows) ? $rows : array();
	}
}
