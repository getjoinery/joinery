<?php
/**
 * DnsRecordMove - move a site to its copy by changing its own DNS records
 * (specs/site_copy_anything_to_anything.md D1), or by the owner changing them
 * at their DNS host (manual).
 *
 * A record change reaches visitors only as resolvers let go of the old
 * answer, so a DNS switch is the copy and its watch:
 *
 *   plan()        read-only: every A and AAAA record in the zone naming one
 *                 of the source's addresses and not proxied (a proxied one is
 *                 the proxy's method), each with the copy's address of its
 *                 family. Refused, naming the record, when the copy has none.
 *   lower_ttl()   at the switch's start, each planned record's TTL goes to
 *                 TTL seconds, so moving it back, or a resolver that asks
 *                 again, waits a minute, not hours. Each record's old TTL is
 *                 kept in the plan.
 *   move()        the records' values to the copy's addresses.
 *   move_back()   the inverse, from a fresh listing, as ProxiedOriginMove.
 *   restore_ttl() each record's old TTL again, once the move has settled
 *                 (or gone back).
 *   manual_plan() the same names, read from public DNS, for an owner who
 *                 changes them at their DNS host: what to change, and to what.
 *   settled()     the move is done when the public resolvers agree on the
 *                 copy's addresses for every name, and one old TTL has passed
 *                 since the change, so no cache anywhere still holds the old.
 *
 * Until it settles, the frozen source forwards every visitor whose resolver
 * still names it to the copy (site_quiet forward), and the copy believes the
 * source's X-Forwarded-For (trust_forwarder), so the site sees its visitors.
 *
 * The DNS credential lives for one request, as ProxiedOriginMove's does.
 *
 * @version 1.0
 */

class DnsRecordMoveException extends Exception {}

class DnsRecordMove {

	/** The TTL records carry while they move, in seconds. */
	const TTL = 60;

	/**
	 * The longest a watch waits on an old TTL, in seconds: a record with a
	 * week's TTL is still forwarded for two days, and the forwarding covers
	 * any cache older than that.
	 */
	const MAX_WAIT = 172800;

	/**
	 * The records to move, checked. Read-only.
	 *
	 * @return array{zone:string, records:array<int,array{id:string,type:string,name:string,from:string,to:string,ttl_was:?int}>}
	 * @throws DnsRecordMoveException naming what stops the move
	 */
	public static function plan(DnsProvider $driver, string $domain, ManagedNode $source, ManagedNode $copy): array {
		$zone = $driver->zoneFor($domain);
		if ($zone === null) {
			throw new DnsRecordMoveException('The token can see no zone for ' . $domain . '. It needs permission to edit '
				. 'that zone\'s records.');
		}
		$from = ProxiedOriginMove::machine_addresses($source);
		$to = ProxiedOriginMove::machine_addresses($copy);
		if (!$from[4] && !$from[6]) {
			throw new DnsRecordMoveException('This management node knows no public address for the site\'s server, so it '
				. 'cannot tell which records point at it.');
		}
		$site = DnsRecord::normalizeName($domain);
		// A container site shares its server's address with the other sites
		// there: only the site's own names are its records to move.
		$shared = trim((string)$source->get('mgn_container_name')) !== '';
		$own_names = array($site, DnsRecord::normalizeName('www.' . $domain));
		$records = array();
		$why = array();
		foreach ($driver->listRecords($zone) as $r) {
			if (!in_array($r->type, array(DnsRecord::TYPE_A, DnsRecord::TYPE_AAAA), true) || $r->proxied === true) {
				continue;
			}
			$name = DnsRecord::normalizeName($r->name);
			if ($shared && !in_array($name, $own_names, true)) {
				continue;
			}
			$family = $r->type === DnsRecord::TYPE_A ? 4 : 6;
			if (!self::among($r->value, $from[$family])) {
				continue;
			}
			if (!$to[$family]) {
				$why[] = "{$r->name} has an {$r->type} record for the site's server, and this management node knows no "
					. "IPv{$family} address for the copy's. Remove the record, or give the copy an IPv{$family} address, before "
					. 'switching over.';
				continue;
			}
			$records[] = array('id' => (string)$r->provider_id, 'type' => $r->type, 'name' => $name,
				'from' => $r->value, 'to' => $to[$family][0], 'ttl_was' => $r->ttl !== null ? (int)$r->ttl : null);
		}
		if ($why) {
			throw new DnsRecordMoveException(implode(' ', $why));
		}
		$named_site = false;
		foreach ($records as $rec) {
			$named_site = $named_site || $rec['name'] === $site;
		}
		if (!$named_site) {
			throw new DnsRecordMoveException($domain . ' itself has no address record in ' . $zone . ' pointing at the site\'s '
				. 'server (' . implode(', ', array_merge($from[4], $from[6])) . '), so this way cannot move it.');
		}
		return array('zone' => $zone, 'records' => $records);
	}

	/**
	 * The names an owner changes by hand: the site's name and www., each A and
	 * AAAA answer public DNS gives that is one of the source's addresses, with
	 * the copy's address of its family. Read-only.
	 */
	public static function manual_plan(string $domain, ManagedNode $source, ManagedNode $copy): array {
		$from = ProxiedOriginMove::machine_addresses($source);
		$to = ProxiedOriginMove::machine_addresses($copy);
		$records = array();
		foreach (array($domain, 'www.' . $domain) as $name) {
			foreach (array(DnsRecord::TYPE_A => 4, DnsRecord::TYPE_AAAA => 6) as $type => $family) {
				try {
					$values = $type === DnsRecord::TYPE_A ? DnsResolver::getA($name) : DnsResolver::getAaaa($name);
				} catch (Throwable $e) {
					$values = array();
				}
				foreach ($values as $v) {
					if (self::among((string)$v, $from[$family])) {
						$records[] = array('id' => '', 'type' => $type, 'name' => DnsRecord::normalizeName($name),
							'from' => (string)$v, 'to' => $to[$family][0] ?? '', 'ttl_was' => null);
					}
				}
			}
		}
		return array('zone' => '', 'records' => $records);
	}

	/** Each planned record's TTL to TTL seconds. A record already there is left. */
	public static function lower_ttl(DnsProvider $driver, array $plan): void {
		self::set_ttls($driver, $plan, function (array $rec) { return self::TTL; }, 'from');
	}

	/** Each planned record's old TTL again, where one was recorded, at the value it holds now ($at: from or to). */
	public static function restore_ttl(DnsProvider $driver, array $plan, string $at): void {
		self::set_ttls($driver, $plan, function (array $rec) { return $rec['ttl_was']; }, $at);
	}

	private static function set_ttls(DnsProvider $driver, array $plan, callable $ttl_of, string $at): void {
		$live = self::live_by_id($driver, (string)$plan['zone']);
		$failed = array();
		foreach ((array)$plan['records'] as $rec) {
			$ttl = $ttl_of($rec);
			$r = $live[$rec['id']] ?? null;
			if ($ttl === null || !$r || (int)$r->ttl === (int)$ttl || !self::among($r->value, array($rec[$at]))) {
				continue;
			}
			$want = new DnsRecord((string)$rec['type'], (string)$rec['name'], $r->value, (int)$ttl);
			try {
				$driver->updateRecord((string)$plan['zone'], $r, $want);
			} catch (Exception $e) {
				$failed[] = "{$rec['name']} ({$rec['type']}): " . $e->getMessage();
			}
		}
		if ($failed) {
			throw new DnsRecordMoveException('A record\'s TTL could not be changed: ' . implode(' ', $failed));
		}
	}

	/** Point each planned record at the copy. A failed write puts back the ones already written, then throws. */
	public static function move(DnsProvider $driver, array $plan): void {
		self::apply($driver, $plan, 'from', 'to');
	}

	/**
	 * Point each planned record back at the source, from a fresh listing. One
	 * already back is skipped; one that says anything else is left, and named.
	 *
	 * @return string[] the records left alone, as sentences
	 */
	public static function move_back(DnsProvider $driver, array $plan): array {
		$live = self::live_by_id($driver, (string)$plan['zone']);
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
		$live = self::live_by_id($driver, (string)$plan['zone']);
		$done = array();
		foreach ((array)$plan['records'] as $rec) {
			try {
				$r = $live[$rec['id']] ?? null;
				if (!$r) {
					throw new DnsRecordMoveException('it is gone from the zone');
				}
				$driver->updateRecord((string)$plan['zone'], $r,
					new DnsRecord((string)$rec['type'], (string)$rec['name'], (string)$rec[$now], $r->ttl));
				$done[] = $rec;
			} catch (Exception $e) {
				$undone = array();
				$after = self::live_by_id($driver, (string)$plan['zone']);
				foreach (array_reverse($done) as $d) {
					try {
						$r = $after[$d['id']] ?? null;
						if ($r) {
							$driver->updateRecord((string)$plan['zone'], $r,
								new DnsRecord((string)$d['type'], (string)$d['name'], (string)$d[$was], $r->ttl));
						}
					} catch (Exception $e2) {
						$undone[] = "{$d['name']} ({$d['type']}) still points at {$d[$now]}: " . $e2->getMessage();
					}
				}
				throw new DnsRecordMoveException("{$rec['name']} ({$rec['type']}) could not be changed: " . $e->getMessage()
					. ($done ? ($undone ? ' Putting back the records already changed failed too. ' . implode(' ', $undone)
						: ' The records already changed were put back.') : ''));
			}
		}
	}

	/**
	 * Is the move done? Every planned name and type answered by the public
	 * resolvers as the copy's address alone ($at 'to'; 'from' for a move back),
	 * and the old TTL (or MAX_WAIT, the lesser) passed since $moved_utc.
	 *
	 * @return array{settled:bool, agree:bool, wait_until:int, waiting_on:string}
	 */
	public static function settled(array $plan, string $moved_utc, string $at = 'to', ?int $now = null): array {
		$now = $now ?? time();
		$wait = 0;
		$expect = array();
		foreach ((array)$plan['records'] as $rec) {
			$wait = max($wait, (int)($rec['ttl_was'] ?? 3600));
			$expect[$rec['name'] . ' ' . $rec['type']][] = (string)$rec[$at];
		}
		$moved = strtotime($moved_utc . ' UTC') ?: $now;
		$until = $moved + min($wait, self::MAX_WAIT);
		$waiting_on = '';
		foreach ($expect as $key => $values) {
			list($name, $type) = explode(' ', $key, 2);
			if ($values === array('') || !PublicResolvers::agree($name, $type, $values)) {
				$waiting_on = "{$name} ({$type})";
				break;
			}
		}
		$agree = $waiting_on === '';
		return array('settled' => $agree && $now >= $until, 'agree' => $agree, 'wait_until' => $until, 'waiting_on' => $waiting_on);
	}

	/** The zone's records by provider id. */
	private static function live_by_id(DnsProvider $driver, string $zone): array {
		$live = array();
		foreach ($driver->listRecords($zone) as $r) {
			$live[(string)$r->provider_id] = $r;
		}
		return $live;
	}

	/** Is $address one of $list, compared as addresses? */
	private static function among(string $address, array $list): bool {
		$packed = @inet_pton(trim($address));
		if ($packed === false) {
			return false;
		}
		foreach ($list as $a) {
			if (@inet_pton((string)$a) === $packed) {
				return true;
			}
		}
		return false;
	}
}
