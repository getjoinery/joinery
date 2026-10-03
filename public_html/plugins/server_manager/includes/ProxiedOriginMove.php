<?php
/**
 * ProxiedOriginMove - move a site behind a proxy (Cloudflare's orange cloud)
 * from its server to its copy's, by changing the address the proxy forwards to
 * (specs/site_copy.md WP7a, step 9).
 *
 * Visitors only ever reach the proxy, so they follow within seconds: no
 * resolver cache holds the old address. That is also the limit of the method:
 * every record naming the old server's address must be proxied, or the
 * visitors that record serves would keep reaching the old server for as long
 * as their resolvers cache it.
 *
 *   plan()       read-only: the records naming the source's address, each
 *                checked, with the address it moves to. Refuses, naming the
 *                record, when one is not proxied, when the site's own name
 *                points somewhere this management node does not know as the
 *                source, or when the copy has no address of a record's family.
 *   move()       point each planned record at the copy. A write that fails
 *                puts back the ones already written.
 *   move_back()  the inverse, from a fresh listing: each record still at the
 *                copy's address goes back to the source's. One that now says
 *                something else is left alone and named.
 *   prove()      ask the site's name, through the proxy, for the copy's look
 *                path. Only the copy knows that path, so its answer (a 303
 *                setting the look cookie) proves the proxy reaches the copy.
 *                The other ways of moving the address prove themselves the
 *                same way (an IP swap, a DNS change the owner made).
 *
 * The DNS credential lives for one request: the page builds the driver from
 * what the owner typed and hands it here; nothing here keeps it. These are the
 * owner's own records, changed on their press with a token they gave for it,
 * so nothing is recorded in the platform's DNS ownership table.
 *
 * @version 1.2 - a container site moves only its own names (the domain and www.): its server's address is
 *                 shared with other sites, whose records stay
 * @version 1.1 - prove() takes how long to keep asking, so a step that is called again can ask briefly each time, and
 *                whether to check the certificate (a copy from backups has none yet)
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsProxiedOrigin.php'));

class ProxiedOriginMoveException extends Exception {}

class ProxiedOriginMove {

	/** How long prove() waits for the proxy to reach the copy, in seconds. */
	const PROVE_SECONDS = 60;

	/** Between two asks of prove(), in seconds. */
	const PROVE_INTERVAL = 3;

	/**
	 * Asks a URL once: fn(string $url): array{status:int, set_cookie:string,
	 * error:string}. Null asks over HTTPS with curl. A variable only so a test
	 * can stand in for the proxy; nothing in production sets it.
	 *
	 * @var callable|null
	 */
	public static $asker = null;

	/** Waits between asks; a test sets a no-op. @var callable|null */
	public static $sleeper = null;

	/**
	 * The driver that can move this domain's proxied records, built from the
	 * credential the owner typed, or null when the domain's DNS host has none.
	 * The host is the one the domain's NS records name.
	 */
	public static function driver_class_for(string $domain): ?string {
		require_once(PathHelper::getIncludePath('includes/dns/DnsDriverRegistry.php'));
		require_once(PathHelper::getIncludePath('includes/dns/DnsPublishBox.php'));
		$key = (string)DnsDriverRegistry::identifyHost(DnsPublishBox::liveNameservers($domain));
		$class = $key !== '' ? DnsDriverRegistry::get($key) : null;
		return ($class && is_subclass_of($class, 'DnsProxiedOrigin')) ? $class : null;
	}

	/**
	 * Every public address this management node knows for a node's machine:
	 * its host, the provision that created it, and the addresses its agent
	 * reported when it joined with the key the row holds now.
	 *
	 * @return array{4: string[], 6: string[]}
	 */
	public static function machine_addresses(ManagedNode $node): array {
		$candidates = array((string)$node->get('mgn_host'));
		$key = trim((string)$node->get('mgn_agent_public_key'));
		foreach (new MultiCustomerCloudProvision(array('node_id' => (int)$node->key, 'deleted' => false)) as $p) {
			$candidates[] = (string)$p->get('cvp_instance_ip');
			$candidates[] = (string)$p->get('cvp_instance_ipv6');
		}
		if ($key !== '') {
			foreach (new MultiAgentJoinRequest(array('ajr_public_key' => $key, 'deleted' => false)) as $request) {
				foreach ($request->addresses() as $a) {
					$candidates[] = $a;
				}
			}
		}
		$out = array(4 => array(), 6 => array());
		foreach ($candidates as $a) {
			$a = trim($a);
			if (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
				continue;
			}
			$family = filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 4 : 6;
			$packed = inet_pton($a);
			$known = false;
			foreach ($out[$family] as $have) {
				$known = $known || inet_pton($have) === $packed;
			}
			if (!$known) {
				$out[$family][] = $a;
			}
		}
		return $out;
	}

	/** Is $address one of $list, compared as addresses? */
	private static function among(string $address, array $list): bool {
		$packed = @inet_pton(trim($address));
		if ($packed === false) {
			return false;
		}
		foreach ($list as $a) {
			if (@inet_pton($a) === $packed) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The records to move, checked. Read-only.
	 *
	 * @return array{zone:string, records:array<int,array{id:string,type:string,name:string,from:string,to:string}>}
	 * @throws ProxiedOriginMoveException naming what stops the move
	 */
	public static function plan(DnsProvider $driver, string $domain, ManagedNode $source, ManagedNode $copy): array {
		if (!($driver instanceof DnsProxiedOrigin)) {
			throw new ProxiedOriginMoveException('This DNS host cannot move a proxied record.');
		}
		$zone = $driver->zoneFor($domain);
		if ($zone === null) {
			throw new ProxiedOriginMoveException('The token can see no zone for ' . $domain . '. It needs Zone, DNS, Edit '
				. 'on that zone.');
		}
		$from = self::machine_addresses($source);
		$to = self::machine_addresses($copy);
		if (!$from[4] && !$from[6]) {
			throw new ProxiedOriginMoveException('This management node knows no public address for the site\'s server, '
				. 'so it cannot tell which records point at it.');
		}

		$records = array();
		$why = array();
		$site = DnsRecord::normalizeName($domain);
		// A container site shares its server's address with the other sites
		// there: only the site's own names are its records to move.
		$shared = trim((string)$source->get('mgn_container_name')) !== '';
		$own_names = array($site, DnsRecord::normalizeName('www.' . $domain));
		foreach ($driver->listRecords($zone) as $r) {
			if (!in_array($r->type, array(DnsRecord::TYPE_A, DnsRecord::TYPE_AAAA), true)) {
				continue;
			}
			if ($shared && !in_array(DnsRecord::normalizeName($r->name), $own_names, true)) {
				continue;
			}
			$family = $r->type === DnsRecord::TYPE_A ? 4 : 6;
			$at_site = DnsRecord::normalizeName($r->name) === $site;
			if (!self::among($r->value, $from[$family])) {
				if ($at_site) {
					$why[] = "{$r->name} has an {$r->type} record for {$r->value}, which is not an address this management "
						. 'node knows for the site\'s server. Remove it, or correct it, before switching over.';
				}
				continue;
			}
			if ($r->proxied !== true) {
				$why[] = "{$r->name} ({$r->type} {$r->value}) is not proxied. Visitors it serves reach the server directly, "
					. 'and would keep reaching the old one for as long as their resolvers cache it. Proxy it, or remove it, '
					. 'before switching over this way.';
				continue;
			}
			if (!$to[$family]) {
				$why[] = "{$r->name} has an {$r->type} record, and this management node knows no IPv{$family} address for "
					. 'the copy\'s server. Remove the record before switching over (the proxy still serves visitors over '
					. 'both IPv4 and IPv6).';
				continue;
			}
			$records[] = array('id' => $r->provider_id, 'type' => $r->type, 'name' => DnsRecord::normalizeName($r->name),
				'from' => $r->value, 'to' => $to[$family][0]);
		}
		if ($why) {
			throw new ProxiedOriginMoveException(implode(' ', $why));
		}
		if (!$records) {
			throw new ProxiedOriginMoveException('No record in ' . $zone . ' points at the site\'s server ('
				. implode(', ', array_merge($from[4], $from[6])) . '), so there is nothing to move.');
		}
		$named_site = false;
		foreach ($records as $rec) {
			$named_site = $named_site || $rec['name'] === $site;
		}
		if (!$named_site) {
			throw new ProxiedOriginMoveException($domain . ' itself has no proxied address record pointing at the site\'s '
				. 'server.');
		}
		return array('zone' => $zone, 'records' => $records);
	}

	/**
	 * Point each planned record at its new address. A failed write puts back
	 * the ones already written, then throws.
	 */
	public static function move(DnsProvider $driver, array $plan): void {
		self::apply($driver, $plan, 'from', 'to');
	}

	/**
	 * Point each planned record back at the source, from a fresh listing. A
	 * record already back is skipped; one that says anything else is left as
	 * it is, and named.
	 *
	 * @return string[] the records left alone, as sentences
	 */
	public static function move_back(DnsProvider $driver, array $plan): array {
		$live = array();
		foreach ($driver->listRecords((string)$plan['zone']) as $r) {
			$live[$r->provider_id] = $r;
		}
		$left = array();
		$back = array('zone' => $plan['zone'], 'records' => array());
		foreach ((array)$plan['records'] as $rec) {
			$r = $live[$rec['id']] ?? null;
			if (!$r) {
				$left[] = "{$rec['name']} ({$rec['type']}) is gone from the zone; nothing was put back for it.";
			} elseif (self::among($r->value, array($rec['from']))) {
				continue;
			} elseif (!self::among($r->value, array($rec['to']))) {
				$left[] = "{$rec['name']} ({$rec['type']}) now points at {$r->value}, not the copy's {$rec['to']}; it was left as it is.";
			} else {
				$back['records'][] = $rec;
			}
		}
		self::apply($driver, $back, 'to', 'from');
		return $left;
	}

	private static function apply(DnsProvider $driver, array $plan, string $was, string $now): void {
		$done = array();
		foreach ((array)$plan['records'] as $rec) {
			try {
				$driver->setProxiedOrigin((string)$plan['zone'], self::live($rec, $rec[$was]), $rec[$now]);
				$done[] = $rec;
			} catch (Exception $e) {
				$undone = array();
				foreach (array_reverse($done) as $d) {
					try {
						$driver->setProxiedOrigin((string)$plan['zone'], self::live($d, $d[$now]), $d[$was]);
					} catch (Exception $e2) {
						$undone[] = "{$d['name']} ({$d['type']}) still points at {$d[$now]}: " . $e2->getMessage();
					}
				}
				throw new ProxiedOriginMoveException("{$rec['name']} ({$rec['type']}) could not be changed: " . $e->getMessage()
					. ($done ? ($undone ? ' Putting back the records already changed failed too. ' . implode(' ', $undone)
						: ' The records already changed were put back.') : ''));
			}
		}
	}

	/** A planned record as the driver lists it: proxied, with its id. */
	private static function live(array $rec, string $value): DnsRecord {
		$r = new DnsRecord((string)$rec['type'], (string)$rec['name'], $value);
		$r->provider_id = (string)$rec['id'];
		$r->proxied = true;
		return $r;
	}

	/**
	 * Does the site's name reach the copy? Asks https://<domain><look path>
	 * until the copy's answer comes back or $seconds (PROVE_SECONDS when
	 * null) pass. The proof is the 303 setting the copy's secret cookie, which
	 * only the copy can give; $verify_tls false asks without checking the
	 * certificate, for a copy made from backups, which has none until it is
	 * the site. Each ask carries a fresh
	 * query string, so no cache answers in the copy's place.
	 *
	 * @return array{proven:bool, asks:int, seconds:int, last:string}
	 */
	public static function prove(string $domain, string $look_path, ?int $seconds = null, bool $verify_tls = true): array {
		$seconds = $seconds ?? self::PROVE_SECONDS;
		$start = time();
		$asks = 0;
		$last = '';
		$secret = substr($look_path, strlen('/.joinery-look/'));
		if (!preg_match('#^/\.joinery-look/[0-9a-f]{32}$#', $look_path)) {
			return array('proven' => false, 'asks' => 0, 'seconds' => 0,
				'last' => 'the copy reported no look path, so nothing only the copy answers can be asked');
		}
		while ($asks < 100) {
			$asks++;
			$url = 'https://' . $domain . $look_path . '?switch_probe=' . bin2hex(random_bytes(6));
			$got = self::$asker ? (self::$asker)($url) : self::ask($url, $verify_tls);
			if ((int)$got['status'] === 303 && strpos((string)$got['set_cookie'], 'joinery_look=' . $secret) !== false) {
				return array('proven' => true, 'asks' => $asks, 'seconds' => time() - $start, 'last' => '303 from the copy');
			}
			$last = $got['error'] !== '' ? $got['error'] : 'HTTP ' . (int)$got['status'];
			if (time() - $start >= $seconds) {
				break;
			}
			self::$sleeper ? (self::$sleeper)(self::PROVE_INTERVAL) : sleep(self::PROVE_INTERVAL);
		}
		return array('proven' => false, 'asks' => $asks, 'seconds' => time() - $start, 'last' => $last);
	}

	/** One HTTPS ask, no redirect followed, the certificate checked unless told not to. */
	private static function ask(string $url, bool $verify_tls = true): array {
		$cookie = '';
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => 10,
			CURLOPT_SSL_VERIFYPEER => $verify_tls,
			CURLOPT_SSL_VERIFYHOST => $verify_tls ? 2 : 0,
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
			CURLOPT_HTTPHEADER     => array('Cache-Control: no-cache'),
			CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$cookie) {
				if (stripos($line, 'set-cookie:') === 0) {
					$cookie .= trim(substr($line, 11)) . "\n";
				}
				return strlen($line);
			},
		));
		curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_errno($ch) ? curl_error($ch) : '';
		curl_close($ch);
		return array('status' => $status, 'set_cookie' => $cookie, 'error' => $error);
	}
}
