<?php
/**
 * DnsReconciler - the diff, and the apply.
 *
 * The diff is the whole point of this subsystem. Every DNS defect the platform
 * has produced was a human-in-the-loop defect: the system knew the right answer
 * and the deployment did something else, and nothing connected the two. A diff
 * turns each of those into something visible.
 *
 * Each planned record lands on exactly one outcome:
 *   MATCHES   nothing to do — and, on a publish, the record is adopted
 *   MISSING   nothing is there; creating it takes nothing away
 *   DIFFERS   the platform owns this slot and its value has drifted
 *   CONFLICTS something is there that the platform does not own
 *   UNKNOWN   the public resolver did not answer, so nothing is claimed
 *
 * Two things make it safe:
 *
 *  - **Building the diff needs no credential.** Live DNS is public, so the whole
 *    reconciliation value — the visible diff, the adoption bookkeeping, the
 *    conflict detection — costs nothing at rest. A credential is required only
 *    for the write.
 *  - **Applying is per-record and best-effort.** A plan is several records and
 *    several provider calls. If one fails — a rate limit, a transient error, a
 *    managed-record refusal — the others still land and the failed record
 *    reports its own reason. There is no all-or-nothing transaction, because the
 *    only rollback would be another write; re-running publish converges whatever
 *    is left, which is why re-publishing a correct domain is a no-op.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));
require_once(PathHelper::getIncludePath('includes/dns/DnsProvider.php'));
require_once(PathHelper::getIncludePath('includes/dns/DnsRecordPlan.php'));
require_once(PathHelper::getIncludePath('includes/dns/DnsOwnershipStore.php'));

class DnsReconciler {

	const MATCHES   = 'matches';
	const MISSING   = 'missing';
	const DIFFERS   = 'differs';
	const CONFLICTS = 'conflicts';
	const UNKNOWN   = 'unknown';
	/**
	 * Written here, and public DNS has not caught up yet.
	 *
	 * Only ever produced by the public-DNS diff, and only from the write receipt:
	 * "never published" and "published a minute ago" look identical to a
	 * resolver and mean opposite things to whoever is watching. Without this the
	 * page would show a freshly-published record as missing and invite the
	 * operator to publish it again — which reads as the write having failed.
	 */
	const PENDING   = 'pending';

	/**
	 * How long a write is allowed to be invisible before the page stops making
	 * excuses for it. Past this, an unresolvable record goes back to reading as
	 * missing, because by then something really is wrong.
	 */
	const PENDING_WINDOW_MINUTES = 60;

	/** Apply everything the operator confirmed. */
	const APPLY_CONFIRMED = 'confirmed';
	/**
	 * Apply only what cannot take anything away: records the zone does not have
	 * at all, and no cutovers. What adding a domain does by itself.
	 */
	const APPLY_ADDITIVE = 'additive';

	/** @var DnsOwnershipStore */
	private $store;

	public function __construct(?DnsOwnershipStore $store = null) {
		$this->store = $store ?: new DbDnsOwnershipStore();
	}

	public function getStore(): DnsOwnershipStore { return $this->store; }

	// ==================================================================
	// Diff
	// ==================================================================

	/**
	 * The diff against public DNS. No credential, no provider, no writes — this
	 * is what a deployment sees before it authorizes anything, and what it can
	 * re-check for free forever.
	 *
	 * @return array[] One row per planned record (see rowFor()).
	 */
	public function diffAgainstPublicDns(DnsRecordPlan $plan): array {
		$rows = array();
		$cache = array();
		foreach ($plan->getRecords() as $record) {
			$slot = $record->slotKey();
			if (!array_key_exists($slot, $cache)) {
				$cache[$slot] = $this->resolveSlot($record);
			}
			list($live, $resolved) = $cache[$slot];
			$rows[] = $this->rowFor($plan, $record, $live, $resolved, true);
		}
		return $rows;
	}

	/**
	 * The diff against what the provider actually holds. Authoritative — public
	 * DNS lags a write and can be served from a cache — so this is what the
	 * apply compares against.
	 *
	 * @return array[]
	 */
	public function diffAgainstProvider(DnsProvider $driver, string $zone, DnsRecordPlan $plan): array {
		$live_by_slot = array();
		foreach ($driver->listRecords($zone) as $live) {
			$live_by_slot[$live->slotKey()][] = $live;
		}
		$rows = array();
		foreach ($plan->getRecords() as $record) {
			$live = $live_by_slot[$record->slotKey()] ?? array();
			$rows[] = $this->rowFor($plan, $record, $live, true);
		}
		return $rows;
	}

	/**
	 * One diff row.
	 *
	 * @param DnsRecord[] $live     Records the zone already holds in this slot.
	 * @param bool        $resolved False when the lookup itself failed.
	 */
	private function rowFor(DnsRecordPlan $plan, DnsRecord $record, array $live, bool $resolved,
			bool $trust_receipt = false): array {
		$owned = $this->store->isOwned($plan->getDomain(), $record->type, $record->name);
		$written = '';

		if (!$resolved) {
			$outcome = self::UNKNOWN;
		} else {
			$satisfied = false;
			foreach ($live as $existing) {
				if ($record->isSatisfiedBy($existing)) {
					$satisfied = true;
					break;
				}
			}
			if ($satisfied) {
				$outcome = self::MATCHES;
			} elseif (empty($live)) {
				$outcome = self::MISSING;
			} else {
				$outcome = $owned ? self::DIFFERS : self::CONFLICTS;
			}

			// A record we wrote a moment ago is not missing — public DNS simply
			// has not caught up. Only the public-DNS diff consults the receipt;
			// the provider's own view has no propagation delay, so a record
			// absent THERE really is absent.
			if ($trust_receipt && ($outcome === self::MISSING || $outcome === self::DIFFERS)) {
				$written = $this->recentWriteOf($plan->getDomain(), $record);
				if ($written !== '') {
					$outcome = self::PENDING;
				}
			}
		}

		$cutover = $this->isCutover($record, $live, $outcome);

		return array(
			'key'          => $record->key(),
			'record'       => $record,
			'outcome'      => $outcome,
			'live'         => $live,
			'owned'        => $owned,
			'written'      => $written,
			'cutover'      => $cutover,
			'cutover_note' => $cutover ? $this->cutoverNote($record, $live) : '',
			'note'         => $record->note,
		);
	}

	/**
	 * When this exact record was written here, if recently enough to explain why
	 * public DNS has not shown it yet. '' when never written, written with a
	 * different value, or written so long ago that propagation has stopped being
	 * a credible explanation.
	 */
	private function recentWriteOf(string $domain, DnsRecord $record): string {
		$receipt = $this->store->ownedRecord($domain, $record->type, $record->name);
		if ($receipt === null || (string)($receipt['written'] ?? '') === '') {
			return '';
		}
		// A drifted record we own is genuinely out of date, not in flight.
		if (DnsRecord::canonicalValue($record->type, (string)$receipt['value']) !== $record->value) {
			return '';
		}
		$cutoff = LibraryFunctions::time_shift(gmdate('Y-m-d H:i:s'),
			'-' . self::PENDING_WINDOW_MINUTES . ' minutes', 'Y-m-d H:i:s');
		return ((string)$receipt['written'] > $cutoff) ? (string)$receipt['written'] : '';
	}

	/**
	 * Does changing this record redirect traffic that already flows?
	 *
	 * Creating a TXT record breaks nothing. Replacing MX moves live mail, and
	 * repointing an A record for a host that already resolves moves whatever is
	 * talking to it. Those get their own confirmation, stating what currently
	 * receives and that it will stop.
	 *
	 * @param DnsRecord[] $live
	 */
	private function isCutover(DnsRecord $record, array $live, string $outcome): bool {
		if ($outcome === self::MATCHES || $outcome === self::UNKNOWN || empty($live)) {
			return false;
		}
		return in_array($record->type, array(
			DnsRecord::TYPE_MX, DnsRecord::TYPE_A, DnsRecord::TYPE_AAAA, DnsRecord::TYPE_CNAME
		), true);
	}

	/** @param DnsRecord[] $live */
	private function cutoverNote(DnsRecord $record, array $live): string {
		$current = array();
		foreach ($live as $existing) {
			$current[] = $existing->value;
		}
		$current = implode(', ', array_unique($current));
		if ($record->type === DnsRecord::TYPE_MX) {
			return 'Mail for ' . $record->name . ' is delivered to ' . $current
				. ' today. Publishing this record stops that and sends it to ' . $record->value . ' instead.';
		}
		return $record->name . ' resolves to ' . $current . ' today. Publishing this record points it at '
			. $record->value . ' instead, and anything still talking to ' . $current . ' stops arriving.';
	}

	/** Read a slot from public DNS. @return array{0:DnsRecord[],1:bool} */
	private function resolveSlot(DnsRecord $record): array {
		$live = array();
		try {
			switch ($record->type) {
				case DnsRecord::TYPE_A:
					foreach (DnsResolver::getA($record->name) as $ip) {
						$live[] = new DnsRecord(DnsRecord::TYPE_A, $record->name, $ip);
					}
					break;
				case DnsRecord::TYPE_AAAA:
					foreach (DnsResolver::getAaaa($record->name) as $ip) {
						$live[] = new DnsRecord(DnsRecord::TYPE_AAAA, $record->name, $ip);
					}
					break;
				case DnsRecord::TYPE_CNAME:
					$target = DnsResolver::getCname($record->name);
					if ($target !== null && $target !== '') {
						$live[] = new DnsRecord(DnsRecord::TYPE_CNAME, $record->name, $target);
					}
					break;
				case DnsRecord::TYPE_MX:
					foreach (DnsResolver::getMx($record->name) as $mx) {
						$live[] = new DnsRecord(DnsRecord::TYPE_MX, $record->name, $mx['host'], null, (int)$mx['pri']);
					}
					break;
				case DnsRecord::TYPE_TXT:
					foreach (DnsResolver::getTxt($record->name) as $txt) {
						$live[] = new DnsRecord(DnsRecord::TYPE_TXT, $record->name, $txt);
					}
					break;
				case DnsRecord::TYPE_CAA:
					foreach (DnsResolver::getCaa($record->name) as $caa) {
						$live[] = new DnsRecord(DnsRecord::TYPE_CAA, $record->name, $caa);
					}
					break;
			}
		} catch (Throwable $e) {
			// A resolver failure is not evidence of absence. UNKNOWN keeps the
			// row honest rather than proposing a create for a record that may
			// already be there.
			return array(array(), false);
		}
		return array($live, true);
	}

	// ==================================================================
	// Apply
	// ==================================================================

	/**
	 * Write the plan, one record at a time.
	 *
	 * @param array  $decisions Per-record confirmations from the diff the
	 *                          operator saw: ['adopt' => [key, …], 'cutover' => [key, …]].
	 * @param string $mode      APPLY_CONFIRMED or APPLY_ADDITIVE.
	 * @return array[] One result per planned record:
	 *   [key, record, action, ok, reason] where action is
	 *   created|updated|adopted|unchanged|skipped|failed.
	 */
	public function apply(DnsProvider $driver, string $zone, DnsRecordPlan $plan,
			array $decisions = array(), string $mode = self::APPLY_CONFIRMED): array {

		$adopt_ok   = array_flip((array)($decisions['adopt'] ?? array()));
		$cutover_ok = array_flip((array)($decisions['cutover'] ?? array()));
		$provider_key = $driver::getKey();

		$results = array();
		$written = array();

		foreach ($this->diffAgainstProvider($driver, $zone, $plan) as $row) {
			$record  = $row['record'];
			$key     = $row['key'];
			$outcome = $row['outcome'];

			// A record whose live value is already exactly what the plan wants is
			// adopted without touching DNS: the platform and the zone already
			// agree, so recording who is responsible is bookkeeping, not a change.
			if ($outcome === self::MATCHES) {
				$already = $row['owned'];
				$this->store->remember($plan->getDomain(), $record, $plan->getOwner(),
					$provider_key, $zone, !$already);
				$results[] = $this->result($key, $record, $already ? 'unchanged' : 'adopted', true,
					$already ? 'Already published and already managed here.'
					         : 'Already published exactly as planned — adopted without a DNS write.');
				continue;
			}

			if ($mode === self::APPLY_ADDITIVE && $outcome !== self::MISSING) {
				$results[] = $this->result($key, $record, 'skipped', true,
					$outcome === self::CONFLICTS
						? 'A record already exists here that the platform does not own — it needs an explicit choice.'
						: 'Changing an existing record needs an explicit confirmation.');
				continue;
			}

			if ($row['cutover'] && !isset($cutover_ok[$key])) {
				$results[] = $this->result($key, $record, 'skipped', true,
					'This change redirects traffic that already flows, so it needs its own confirmation.');
				continue;
			}

			if ($outcome === self::CONFLICTS && !isset($adopt_ok[$key])) {
				$results[] = $this->result($key, $record, 'skipped', true,
					'A record already exists here that the platform does not own. It is never overwritten '
					. 'without an explicit adopt choice.');
				continue;
			}

			try {
				if ($outcome === self::MISSING) {
					$driver->createRecord($zone, $record);
					$action = 'created';
				} else {
					$driver->updateRecord($zone, $this->replaceable($row), $record);
					$action = 'updated';
				}
				$this->store->remember($plan->getDomain(), $record, $plan->getOwner(),
					$provider_key, $zone, $outcome === self::CONFLICTS);
				$written[] = $record;
				$results[] = $this->result($key, $record, $action, true, '');
			} catch (DnsManagedRecordException $e) {
				$results[] = $this->result($key, $record, 'failed', false, $e->getMessage());
			} catch (Throwable $e) {
				$results[] = $this->result($key, $record, 'failed', false, $e->getMessage());
			}
		}

		if (!empty($written)) {
			try {
				// Some vendors must be told to re-read DNS before they will trust
				// a record; publishing everything and leaving a domain unverified
				// is one of the failures this subsystem exists to end.
				$driver->afterPublish($zone, $written);
			} catch (Throwable $e) {
				error_log('DnsReconciler: post-publish verification hook failed for ' . $zone . ': ' . $e->getMessage());
			}
		}

		return $results;
	}

	/** The live record an update should replace: the first in the slot. */
	private function replaceable(array $row): DnsRecord {
		$live = $row['live'];
		return !empty($live) ? $live[0] : $row['record'];
	}

	private function result(string $key, DnsRecord $record, string $action, bool $ok, string $reason): array {
		return array('key' => $key, 'record' => $record, 'action' => $action, 'ok' => $ok, 'reason' => $reason);
	}

	// ==================================================================
	// Withdrawal
	// ==================================================================

	/**
	 * Remove the records the platform owns for a domain, and nothing else.
	 *
	 * @return array[] Same result shape as apply().
	 */
	public function withdraw(DnsProvider $driver, string $zone, string $domain): array {
		$owned = $this->store->ownedFor($domain);
		if (empty($owned)) {
			return array();
		}
		$live_by_slot = array();
		foreach ($driver->listRecords($zone) as $live) {
			$live_by_slot[$live->slotKey()][] = $live;
		}

		$results = array();
		foreach ($owned as $row) {
			$slot = strtoupper($row['type']) . '|' . DnsRecord::normalizeName($row['name']);
			$record = new DnsRecord($row['type'], $row['name'], $row['value'] !== '' ? $row['value'] : '.');
			$matches = array();
			foreach ($live_by_slot[$slot] ?? array() as $live) {
				if ($live->value === DnsRecord::canonicalValue($row['type'], $row['value'])) {
					$matches[] = $live;
				}
			}
			try {
				foreach ($matches as $live) {
					$driver->deleteRecord($zone, $live);
				}
				$this->store->forget($domain, $row['type'], $row['name']);
				$results[] = $this->result($record->key(), $record,
					empty($matches) ? 'skipped' : 'deleted', true,
					empty($matches) ? 'Already gone from the zone; the platform simply stopped claiming it.' : '');
			} catch (Throwable $e) {
				$results[] = $this->result($record->key(), $record, 'failed', false, $e->getMessage());
			}
		}
		return $results;
	}

	/**
	 * Whether a zone is safe to delete: it holds nothing the platform does not
	 * own. A zone with foreign records is never deleted, however authoritative
	 * the platform is for it.
	 */
	public function zoneHoldsOnlyOurRecords(DnsProvider $driver, string $zone, string $domain): bool {
		$owned = array();
		foreach ($this->store->ownedFor($domain) as $row) {
			$owned[strtoupper($row['type']) . '|' . DnsRecord::normalizeName($row['name'])] = true;
		}
		foreach ($driver->listRecords($zone) as $live) {
			if (!isset($owned[$live->slotKey()])) {
				return false;
			}
		}
		return true;
	}

	// ==================================================================
	// Summaries
	// ==================================================================

	/** Count of each outcome in a diff, for a one-line page summary. */
	public static function summarize(array $rows): array {
		$counts = array(self::MATCHES => 0, self::MISSING => 0, self::DIFFERS => 0,
			self::CONFLICTS => 0, self::UNKNOWN => 0, self::PENDING => 0, 'cutover' => 0);
		foreach ($rows as $row) {
			$counts[$row['outcome']] = ($counts[$row['outcome']] ?? 0) + 1;
			if (!empty($row['cutover'])) {
				$counts['cutover']++;
			}
		}
		return $counts;
	}

	/** True when every planned record is already published as planned. */
	public static function allGreen(array $rows): bool {
		foreach ($rows as $row) {
			if ($row['outcome'] !== self::MATCHES) {
				return false;
			}
		}
		return !empty($rows);
	}

	/**
	 * Is there anything left for an operator to do?
	 *
	 * A record already published and one written moments ago both count as
	 * settled: neither wants another click, and offering the form for a record
	 * that is already in flight is what makes a successful publish look like a
	 * failed one.
	 */
	public static function settled(array $rows): bool {
		foreach ($rows as $row) {
			if ($row['outcome'] !== self::MATCHES && $row['outcome'] !== self::PENDING) {
				return false;
			}
		}
		return !empty($rows);
	}

	/** The most recent write receipt across a diff, or '' if nothing was written here. */
	public static function lastWritten(array $rows): string {
		$latest = '';
		foreach ($rows as $row) {
			if (!empty($row['written']) && $row['written'] > $latest) {
				$latest = (string)$row['written'];
			}
		}
		return $latest;
	}
}
