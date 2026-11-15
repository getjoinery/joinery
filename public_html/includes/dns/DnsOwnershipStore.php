<?php
/**
 * DnsOwnershipStore - the reconciler's view of "is this record ours?".
 *
 * The rule the reconciler enforces — never modify or delete a record the
 * platform does not own — needs one question answered and one fact recorded.
 * Putting that behind a tiny interface keeps the diff logic testable without a
 * database and keeps the persistence in one place (ManagedDnsRecord).
 *
 * A slot is (domain, type, name, kind): kind is DnsRecord::txtKind() for a TXT
 * and '' for every other type, so the apex SPF and a verification record
 * beside it are owned, remembered and forgotten separately.
 *
 * @version 1.1 - slots are kept per TXT kind
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsRecord.php'));

interface DnsOwnershipStore {

	/** Is the platform responsible for this (domain, type, name, kind) slot? */
	public function isOwned(string $domain, string $type, string $name, string $kind = ''): bool;

	/**
	 * What the platform last wrote into a slot, and when — the write receipt.
	 *
	 * This is what lets the page distinguish "not published" from "published a
	 * moment ago and DNS has not caught up", which look identical to a public
	 * resolver and mean opposite things to whoever is watching.
	 *
	 * @return array{value:string,owner:string,provider:string,written:string}|null
	 */
	public function ownedRecord(string $domain, string $type, string $name, string $kind = ''): ?array;

	/** Record responsibility for the slot of $record's kind. $adopted marks ownership acquired by agreement. */
	public function remember(string $domain, DnsRecord $record, string $owner,
		string $provider, string $zone, bool $adopted): void;

	/** Drop responsibility for a slot. Never touches DNS itself. */
	public function forget(string $domain, string $type, string $name, string $kind = ''): void;

	/**
	 * Every slot the platform owns in a domain.
	 * @return array<int,array{type:string,name:string,kind:string,value:string,owner:string}>
	 */
	public function ownedFor(string $domain): array;
}

/**
 * The real store, backed by dnr_managed_dns_records.
 *
 * Reads fail soft. On a deployment whose code has landed but whose
 * update_database run has not, the table does not exist yet — and "the platform
 * owns nothing here" is the correct, safe answer in that window: every existing
 * record reads as a conflict, which is refused rather than overwritten. A write
 * in that state does throw, because silently forgetting who is responsible for a
 * record the platform just published is the one outcome worth failing loudly on.
 */
class DbDnsOwnershipStore implements DnsOwnershipStore {

	public function __construct() {
		require_once(PathHelper::getIncludePath('data/managed_dns_records_class.php'));
	}

	public function isOwned(string $domain, string $type, string $name, string $kind = ''): bool {
		try {
			return ManagedDnsRecord::IsOwned($domain, $type, $name, $kind);
		} catch (Throwable $e) {
			return false;
		}
	}

	public function ownedRecord(string $domain, string $type, string $name, string $kind = ''): ?array {
		try {
			$row = ManagedDnsRecord::Find($domain, $type, $name, $kind);
		} catch (Throwable $e) {
			return null;
		}
		if ($row === null) {
			return null;
		}
		return array(
			'value'    => (string)$row->get('dnr_value'),
			'owner'    => (string)$row->get('dnr_owner'),
			'provider' => (string)$row->get('dnr_provider'),
			'written'  => (string)($row->get('dnr_update_time') ?: $row->get('dnr_create_time')),
		);
	}

	public function remember(string $domain, DnsRecord $record, string $owner,
			string $provider, string $zone, bool $adopted): void {
		ManagedDnsRecord::Remember($domain, $record->type, $record->name, $record->value,
			$owner, $provider, $zone, $adopted);
	}

	public function forget(string $domain, string $type, string $name, string $kind = ''): void {
		ManagedDnsRecord::Forget($domain, $type, $name, $kind);
	}

	public function ownedFor(string $domain): array {
		$out = array();
		try {
			$rows = ManagedDnsRecord::OwnedFor($domain);
		} catch (Throwable $e) {
			return $out;
		}
		foreach ($rows as $row) {
			$out[] = array(
				'type'  => (string)$row->get('dnr_type'),
				'name'  => (string)$row->get('dnr_name'),
				'kind'  => $row->kind(),
				'value' => (string)$row->get('dnr_value'),
				'owner' => (string)$row->get('dnr_owner'),
			);
		}
		return $out;
	}
}

/** An in-memory store, for tests and for a dry run that must record nothing. */
class MemoryDnsOwnershipStore implements DnsOwnershipStore {

	/** @var array<string,array> slot key => row */
	private $rows = array();

	private function slot(string $domain, string $type, string $name, string $kind = ''): string {
		$type = strtoupper($type);
		return strtolower($domain) . '|' . $type . '|' . DnsRecord::normalizeName($name)
			. '|' . ($type === DnsRecord::TYPE_TXT ? $kind : '');
	}

	public function isOwned(string $domain, string $type, string $name, string $kind = ''): bool {
		return isset($this->rows[$this->slot($domain, $type, $name, $kind)]);
	}

	public function ownedRecord(string $domain, string $type, string $name, string $kind = ''): ?array {
		return $this->rows[$this->slot($domain, $type, $name, $kind)] ?? null;
	}

	public function remember(string $domain, DnsRecord $record, string $owner,
			string $provider, string $zone, bool $adopted): void {
		$this->rows[$this->slot($domain, $record->type, $record->name, $record->txtKind())] = array(
			'type'     => $record->type,
			'name'     => $record->name,
			'kind'     => $record->txtKind(),
			'value'    => $record->value,
			'owner'    => $owner,
			'provider' => $provider,
			'adopted'  => $adopted,
			'written'  => gmdate('Y-m-d H:i:s'),
		);
	}

	public function forget(string $domain, string $type, string $name, string $kind = ''): void {
		unset($this->rows[$this->slot($domain, $type, $name, $kind)]);
	}

	public function ownedFor(string $domain): array {
		$out = array();
		$prefix = strtolower($domain) . '|';
		foreach ($this->rows as $slot => $row) {
			if (strpos($slot, $prefix) === 0) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Backdate a write receipt. Tests only — it is the one property of this
	 * store that cannot be produced by exercising the real interface, because
	 * remember() always stamps the present.
	 */
	public function ageWriteForTest(string $domain, string $type, string $name, string $interval, string $kind = ''): void {
		$slot = $this->slot($domain, $type, $name, $kind);
		if (isset($this->rows[$slot])) {
			$this->rows[$slot]['written'] = LibraryFunctions::time_shift(
				gmdate('Y-m-d H:i:s'), $interval, 'Y-m-d H:i:s');
		}
	}
}
