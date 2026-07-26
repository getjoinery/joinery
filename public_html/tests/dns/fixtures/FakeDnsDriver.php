<?php
/**
 * FakeDnsDriver - an in-memory DNS host for the reconciler suite.
 *
 * Holds a zone as a plain list of records, counts every write, and can be told
 * to fail one specific record so the best-effort apply path is exercised without
 * touching a real provider. Loaded only by tests.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/dns/DnsDriverBase.php'));

class FakeDnsDriver extends DnsDriverBase {

	/** @var array<string,DnsRecord[]> zone => records */
	public $zones = array();
	/** @var array<int,string> Every write this driver performed, in order. */
	public $writes = array();
	/** @var string A record value that createRecord/updateRecord will refuse. */
	public $fail_value = '';
	/** @var string Feature name for a managed-record refusal, when failing. */
	public $fail_feature = '';
	/** @var array[] Accounts this credential reaches. */
	public $account_list = array();
	/** @var int Times afterPublish() ran. */
	public $after_publish_calls = 0;

	private $next_id = 1;

	public static function getKey(): string { return 'fake'; }
	public static function getLabel(): string { return 'Fake DNS'; }
	public static function supportsZones(): bool { return true; }

	public function seed(string $zone, array $records): void {
		foreach ($records as $record) {
			$record->provider_id = 'r' . $this->next_id++;
			$this->zones[$zone][] = $record;
		}
	}

	public function accounts(): array {
		return $this->account_list ?: parent::accounts();
	}

	public function zoneFor(string $domain): ?string {
		$map = array();
		foreach (array_keys($this->zones) as $zone) {
			$map[$zone] = $zone;
		}
		return self::matchZone($domain, $map);
	}

	public function listRecords(string $zone): array {
		$out = array();
		foreach ($this->zones[$zone] ?? array() as $record) {
			$copy = new DnsRecord($record->type, $record->name, $record->value, $record->ttl, $record->priority);
			$copy->provider_id = $record->provider_id;
			$out[] = $copy;
		}
		return $out;
	}

	public function createRecord(string $zone, DnsRecord $record): void {
		$this->refuseIfConfigured($record);
		$this->writes[] = 'create ' . $record->describe();
		$stored = new DnsRecord($record->type, $record->name, $record->value, $record->ttl, $record->priority);
		$stored->provider_id = 'r' . $this->next_id++;
		$this->zones[$zone][] = $stored;
	}

	public function updateRecord(string $zone, DnsRecord $live, DnsRecord $desired): void {
		$this->refuseIfConfigured($desired);
		$this->writes[] = 'update ' . $live->describe() . ' => ' . $desired->describe();
		foreach ($this->zones[$zone] ?? array() as $i => $record) {
			if ($record->provider_id === $live->provider_id) {
				$stored = new DnsRecord($desired->type, $desired->name, $desired->value,
					$desired->ttl, $desired->priority);
				$stored->provider_id = $record->provider_id;
				$this->zones[$zone][$i] = $stored;
				return;
			}
		}
	}

	public function deleteRecord(string $zone, DnsRecord $live): void {
		$this->writes[] = 'delete ' . $live->describe();
		foreach ($this->zones[$zone] ?? array() as $i => $record) {
			if ($record->provider_id === $live->provider_id) {
				unset($this->zones[$zone][$i]);
				$this->zones[$zone] = array_values($this->zones[$zone]);
				return;
			}
		}
	}

	public function afterPublish(string $zone, array $applied): void {
		$this->after_publish_calls++;
	}

	private function refuseIfConfigured(DnsRecord $record): void {
		if ($this->fail_value === '' || $record->value !== $this->fail_value) {
			return;
		}
		if ($this->fail_feature !== '') {
			throw new DnsManagedRecordException($this->fail_feature,
				'This record is managed by ' . $this->fail_feature . '; disable it and publish again.');
		}
		throw new DnsProviderException('rate limited');
	}
}
