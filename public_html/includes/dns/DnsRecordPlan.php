<?php
/**
 * DnsRecordPlan - everything one subsystem wants published for one domain.
 *
 * A plan is desired state and nothing else: no credential, no provider, no
 * opinion about how it gets there. Any subsystem can produce one — the mailbox
 * plugin's setup check, node provisioning, a future registrar flow — and the
 * same reconciler, the same publish box and the same drivers handle all of
 * them. The rendered copy-paste instructions are one consumer of a plan, not
 * its only form.
 *
 * The owner string is the subsystem that will be recorded as responsible for
 * each record it creates or adopts (see ManagedDnsRecord).
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsRecord.php'));

class DnsRecordPlan implements IteratorAggregate, Countable {

	/** @var string The domain this plan is for, lowercase. */
	private $domain;
	/** @var string Owning subsystem key, e.g. 'mailbox' | 'server_manager'. */
	private $owner;
	/** @var DnsRecord[] */
	private $records = array();

	public function __construct(string $domain, string $owner) {
		$this->domain = DnsRecord::normalizeName($domain);
		$this->owner  = trim($owner);
	}

	public function getDomain(): string { return $this->domain; }
	public function getOwner(): string  { return $this->owner; }

	/**
	 * Add a record. Duplicates (same type, name and value) are collapsed so two
	 * plan contributors asking for the same thing produce one row.
	 */
	public function add(DnsRecord $record): DnsRecordPlan {
		foreach ($this->records as $existing) {
			if ($existing->type === $record->type
					&& $existing->name === $record->name
					&& $existing->value === $record->value) {
				return $this;
			}
		}
		$this->records[] = $record;
		return $this;
	}

	/** Convenience: build and add in one call. */
	public function addRecord(string $type, string $name, string $value,
			?int $ttl = null, ?int $priority = null, string $note = ''): DnsRecordPlan {
		return $this->add(new DnsRecord($type, $name, $value, $ttl, $priority, $note));
	}

	/** @return DnsRecord[] */
	public function getRecords(): array { return $this->records; }

	public function isEmpty(): bool { return empty($this->records); }

	public function count(): int { return count($this->records); }

	public function getIterator(): Iterator {
		return new ArrayIterator($this->records);
	}

	/** Fold another plan's records in. The receiving plan keeps its own owner. */
	public function merge(DnsRecordPlan $other): DnsRecordPlan {
		foreach ($other->getRecords() as $record) {
			$this->add($record);
		}
		return $this;
	}

	/** Plain-array form, safe to carry through an OAuth flow payload. */
	public function toArray(): array {
		$records = array();
		foreach ($this->records as $record) {
			$records[] = $record->toArray();
		}
		return array('domain' => $this->domain, 'owner' => $this->owner, 'records' => $records);
	}

	/** Rebuild from toArray(). Unknown record types are refused, not dropped. */
	public static function fromArray(array $a): DnsRecordPlan {
		$plan = new DnsRecordPlan((string)($a['domain'] ?? ''), (string)($a['owner'] ?? ''));
		foreach ((array)($a['records'] ?? array()) as $record) {
			$plan->add(DnsRecord::fromArray((array)$record));
		}
		return $plan;
	}
}
