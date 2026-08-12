<?php
/**
 * DnsRrsetDriverBase - shared behaviour for providers that model DNS as record
 * SETS rather than individual records.
 *
 * Gandi, deSEC, Route 53, Google Cloud DNS, Azure DNS and GoDaddy all store one
 * object per (name, type) holding a list of values. There are no per-record ids,
 * so creating one record means reading the set, adding a value and writing the
 * whole set back — and doing it any other way silently destroys the siblings
 * that were already there. That read-modify-write lives here once, so no driver
 * can get it wrong on its own.
 *
 * A subclass implements three primitives:
 *   readRrset()   — the current values and TTL for one (name, type), or null
 *   writeRrset()  — replace a whole (name, type) set
 *   deleteRrset() — remove a whole (name, type) set
 *
 * plus listRecords() and zoneFor(). Values move through the primitives in the
 * vendor's own wire spelling (quoted TXT, "10 mail.example.com" for MX);
 * rrsetValue() and recordFromValue() are the one place that translation happens.
 *
 * @version 1.1 - an SRV set-value carries an absolute (dotted) target
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsDriverBase.php'));

abstract class DnsRrsetDriverBase extends DnsDriverBase {

	/**
	 * Current state of one record set.
	 * @return array{values:string[],ttl:?int}|null Null when the set does not exist.
	 */
	abstract protected function readRrset(string $zone, string $name, string $type): ?array;

	/** Replace a whole record set with $values. */
	abstract protected function writeRrset(string $zone, string $name, string $type, array $values, ?int $ttl): void;

	/** Remove a whole record set. */
	abstract protected function deleteRrset(string $zone, string $name, string $type): void;

	// ------------------------------------------------------------------
	// The interface, expressed in those three primitives
	// ------------------------------------------------------------------

	public function createRecord(string $zone, DnsRecord $record): void {
		$existing = $this->readRrset($zone, $record->name, $record->type);
		$values = $existing !== null ? $existing['values'] : array();
		$wanted = $this->rrsetValue($record);
		if (!in_array($wanted, $values, true)) {
			$values[] = $wanted;
		}
		$ttl = $record->ttl !== null ? $record->ttl : ($existing['ttl'] ?? null);
		$this->writeRrset($zone, $record->name, $record->type, $values, $ttl);
	}

	public function updateRecord(string $zone, DnsRecord $live, DnsRecord $desired): void {
		$existing = $this->readRrset($zone, $desired->name, $desired->type);
		$values = $existing !== null ? $existing['values'] : array();
		$old = $this->rrsetValue($live);
		$new = $this->rrsetValue($desired);
		$replaced = false;
		foreach ($values as $i => $value) {
			if ($value === $old) {
				$values[$i] = $new;
				$replaced = true;
			}
		}
		if (!$replaced && !in_array($new, $values, true)) {
			$values[] = $new;
		}
		$values = array_values(array_unique($values));
		$ttl = $desired->ttl !== null ? $desired->ttl : ($existing['ttl'] ?? null);
		$this->writeRrset($zone, $desired->name, $desired->type, $values, $ttl);
	}

	public function deleteRecord(string $zone, DnsRecord $live): void {
		$existing = $this->readRrset($zone, $live->name, $live->type);
		if ($existing === null) {
			return;   // already gone
		}
		$target = $this->rrsetValue($live);
		$values = array_values(array_filter($existing['values'], function ($value) use ($target) {
			return $value !== $target;
		}));
		if (empty($values)) {
			// The platform only ever deletes records it owns, so an emptied set
			// is a set it filled.
			$this->deleteRrset($zone, $live->name, $live->type);
			return;
		}
		$this->writeRrset($zone, $live->name, $live->type, $values, $existing['ttl']);
	}

	// ------------------------------------------------------------------
	// Value translation
	// ------------------------------------------------------------------

	/** A planned record as one entry in the vendor's value list. */
	protected function rrsetValue(DnsRecord $record): string {
		switch ($record->type) {
			case DnsRecord::TYPE_TXT:
				// A set member always carries its quoting: unlike a single-record
				// API there is no field boundary to imply it.
				return self::quoteTxt($record->value);
			case DnsRecord::TYPE_MX:
				return ($record->priority !== null ? (int)$record->priority : 10) . ' ' . $record->value . '.';
			case DnsRecord::TYPE_CNAME:
				return $record->value . '.';
			case DnsRecord::TYPE_SRV:
				// The RDATA's target is a hostname, and a set-based vendor that takes
				// the RDATA verbatim reads a target without a trailing dot as relative
				// to the zone — Gandi re-appends it (direct.example.com.example.com),
				// deSEC and Google Cloud reject it. Absolute it here, like MX and
				// CNAME, so the one set-value form is correct on the wire and matches
				// what those vendors store and hand back for delete/replace.
				$srv = self::parseSrv($record->value);
				return $srv['priority'] . ' ' . $srv['weight'] . ' ' . $srv['port'] . ' ' . $srv['target'] . '.';
			default:
				return $record->value;
		}
	}

	/** One entry from the vendor's value list, back as a DnsRecord. */
	protected function recordFromValue(string $type, string $name, string $value, ?int $ttl): ?DnsRecord {
		$type = strtoupper($type);
		if (!in_array($type, DnsRecord::TYPES, true)) {
			return null;
		}
		$priority = null;
		if ($type === DnsRecord::TYPE_MX && preg_match('/^\s*(\d+)\s+(.+)$/', $value, $m)) {
			$priority = (int)$m[1];
			$value = $m[2];
		}
		return new DnsRecord($type, $name, $value, $ttl, $priority);
	}
}
