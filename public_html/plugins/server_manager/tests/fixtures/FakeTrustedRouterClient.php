<?php
/**
 * FakeTrustedRouterClient — TrustedRouter's key API in memory, for tests.
 *
 * Keys, limits, disabled flags and usage are held in arrays; a test spends on
 * a key with spend(). Every call is recorded in $calls, so a test can see what
 * the leg asked for. fail_next makes the next call throw, as a provider
 * outage would; fail_on makes every call of a kind fail until it is cleared.
 */
class FakeTrustedRouterClient extends TrustedRouterClient {

	/** @var array hash => ['name', 'limit', 'disabled', 'usage', 'key', 'tags'] */
	public $keys = array();
	public $calls = array();
	public $balance = 100000000;
	public $fail_next = false;
	/** @var string[] call prefixes ('delete', 'disable', 'create', 'list', 'limit') that fail until removed */
	public $fail_on = array();
	private $n = 0;

	public function __construct() {
		parent::__construct('fake-management-key');
	}

	private function maybe_fail(string $what): void {
		$this->calls[] = $what;
		foreach ($this->fail_on as $prefix) {
			if (strpos($what, $prefix) === 0) {
				throw new TrustedRouterException('TrustedRouter ' . $what . ' failed (503): the fake refuses ' . $prefix, 503);
			}
		}
		if ($this->fail_next) {
			$this->fail_next = false;
			throw new TrustedRouterException('TrustedRouter ' . $what . ' failed (503): the fake was told to fail', 503);
		}
	}

	public function createKey(string $name, int $limit_micro, array $tags = array()): array {
		$this->maybe_fail('create ' . $name);
		$this->n++;
		$hash = 'hash' . $this->n . '_' . bin2hex(random_bytes(3));
		$key = 'sk-tr-fake-' . $this->n . '-' . bin2hex(random_bytes(6));
		$this->keys[$hash] = array('name' => $name, 'limit' => $limit_micro, 'disabled' => false,
			'usage' => 0, 'key' => $key, 'tags' => $tags);
		return array('hash' => $hash, 'key' => $key);
	}

	public function setLimit(string $hash, int $limit_micro): void {
		$this->maybe_fail('limit ' . $hash . ' ' . $limit_micro);
		if (!isset($this->keys[$hash])) {
			throw new TrustedRouterException('TrustedRouter PATCH keys failed (404): Resource not found', 404);
		}
		$this->keys[$hash]['limit'] = $limit_micro;
	}

	public function setDisabled(string $hash, bool $disabled): void {
		$this->maybe_fail(($disabled ? 'disable ' : 'enable ') . $hash);
		if (isset($this->keys[$hash])) {
			$this->keys[$hash]['disabled'] = $disabled;
		}
	}

	public function deleteKey(string $hash): void {
		$this->maybe_fail('delete ' . $hash);
		unset($this->keys[$hash]);
	}

	public function listUsage(): array {
		$this->maybe_fail('list');
		$out = array();
		foreach ($this->keys as $hash => $k) {
			$out[$hash] = array('usage' => $k['usage'], 'limit' => $k['limit'], 'disabled' => $k['disabled']);
		}
		return $out;
	}

	public function remainingBalance(): int {
		$this->maybe_fail('balance');
		return $this->balance;
	}

	/** Spend on a key as TrustedRouter's non-strict mode would: refused at the cap unless already in flight. */
	public function spend(string $hash, int $micro, bool $in_flight = false): bool {
		$k = $this->keys[$hash] ?? null;
		// A request already running when the key was switched off or capped
		// still finishes and is billed (non-strict mode).
		if ($k === null || (!$in_flight && ($k['disabled'] || $k['usage'] >= $k['limit']))) {
			return false;
		}
		$this->keys[$hash]['usage'] += $micro;
		return true;
	}
}
