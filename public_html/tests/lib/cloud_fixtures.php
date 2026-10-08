<?php
/**
 * Shared cloud-storage test doubles for the offload-engine, lifecycle, private-
 * offload, and mailbox raw-storage suites.
 *
 * Two driver doubles, because the suites want opposite things from a driver:
 *
 *   - RecordingMockDriver — records an ordered ops log and fabricates bytes on
 *     get(), for tests that assert WHICH cloud operations the engine issued and
 *     in what order. Supports failure injection (fail every put, or a named set
 *     of keys) and an on_put side-effect hook (to race a DB change mid-push).
 *   - InMemoryBlobDriver — actually stores the bytes it is given and round-trips
 *     them, for tests that assert content fidelity (raw-message store). get()
 *     on an unknown key throws, like a real bucket.
 *
 * One profile double:
 *
 *   - ScratchTableProfile — a StorageProfile backed by a caller-owned scratch
 *     table and on-disk base dir. Eligibility and ownership are supplied as
 *     options so one class serves the plain and the ownership-gated cases. It
 *     answers visibility() 'private', as every profile of the one store does.
 *
 * @version 1.3 - the file store is a target row (specs/storage_targets.md WP6): ScratchTableProfile records
 *                its store and key (target_id, remote_key columns), names forward items and reads its
 *                recorded key back; cloud_test_store() is a CloudFileStore over a test driver, with no
 *                folder unless given one, so a key is the item's name; putMany() is gone
 * @version 1.2 - ScratchTableProfile answers lastErrorColumn()
 * @version 1.1 - one private store: ScratchTableProfile answers private, with no visibility option
 * @version 1.0
 */

if (!class_exists('RecordingMockDriver')) {

/**
 * A file store over a test driver: where syncBatch() writes in a test. The
 * target id is whatever the test's rows should record (no row is needed);
 * with no folder, an item's key is its name.
 */
function cloud_test_store(CloudStorageDriver $driver, int $target_id = 1, string $prefix = ''): CloudFileStore {
	return new CloudFileStore($target_id, $prefix, $driver);
}

/**
 * Records ops; get() writes synthetic bytes; put failures are injectable.
 * Used where a test asserts the engine's cloud-side call sequence, not content.
 */
class RecordingMockDriver implements CloudStorageDriver {
	/** @var array ordered log of ['op'=>..., 'key'=>...] */
	public $calls = [];
	/** @var bool when true, every put fails */
	public $fail_all = false;
	/** @var string[] remote_keys whose put should fail */
	public $fail_keys = [];
	/** @var callable|null closure(remote_key): void — a side effect during push */
	public $on_put = null;

	public function put(string $local_path, string $remote_key, string $content_type): void {
		$this->calls[] = ['op' => 'put', 'key' => $remote_key];
		if ($this->on_put) { ($this->on_put)($remote_key); }
		if ($this->fail_all || in_array($remote_key, $this->fail_keys, true)) { throw new RuntimeException('mock put failure'); }
	}

	public function get(string $remote_key, string $local_path): void {
		$this->calls[] = ['op' => 'get', 'key' => $remote_key];
		$dir = dirname($local_path);
		if (!is_dir($dir)) { mkdir($dir, 0777, true); }
		file_put_contents($local_path, "bytes:$remote_key\n");
	}

	public function get_range(string $remote_key, string $local_path, int $start, int $end): void {
		$this->calls[] = ['op' => 'get_range', 'key' => $remote_key, 'start' => $start, 'end' => $end];
		$dir = dirname($local_path);
		if (!is_dir($dir)) { mkdir($dir, 0777, true); }
		file_put_contents($local_path, substr("bytes:$remote_key\n", $start, $end - $start + 1));
	}

	public function delete(string $remote_key): void {
		$this->calls[] = ['op' => 'delete', 'key' => $remote_key];
	}

	public function url(string $remote_key): string { return 'https://mock/' . $remote_key; }
	public function ping(): array { return ['ok' => true, 'message' => 'mock']; }

	/** @var string[] remote_keys head() answers as absent (everything else is present at its synthetic size) */
	public $absent_keys = [];

	public function head(string $remote_key): ?array {
		$this->calls[] = ['op' => 'head', 'key' => $remote_key];
		if (in_array($remote_key, $this->absent_keys, true)) { return null; }
		$bytes = "bytes:$remote_key\n";
		return ['size' => strlen($bytes), 'etag' => md5($bytes)];
	}

	/** The ops log, optionally filtered to one op ('put'|'get'|'delete'|'head'). */
	public function ops($filter = null): array {
		return array_values(array_filter($this->calls, function ($c) use ($filter) {
			return $filter === null || $c['op'] === $filter;
		}));
	}
}

/**
 * Stores bytes and round-trips them; get() on an unknown key throws, like a real
 * bucket. Used where a test asserts content fidelity.
 */
class InMemoryBlobDriver implements CloudStorageDriver {
	/** @var array remote_key => stored bytes */
	public $objects = [];

	public function put(string $local_path, string $remote_key, string $content_type): void {
		$this->objects[$remote_key] = (string)file_get_contents($local_path);
	}
	public function get(string $remote_key, string $local_path): void {
		if (!array_key_exists($remote_key, $this->objects)) {
			throw new RuntimeException('mock: no such object ' . $remote_key);
		}
		$dir = dirname($local_path);
		if (!is_dir($dir)) { mkdir($dir, 0777, true); }
		file_put_contents($local_path, $this->objects[$remote_key]);
	}
	public function get_range(string $remote_key, string $local_path, int $start, int $end): void {
		if (!array_key_exists($remote_key, $this->objects)) {
			throw new RuntimeException('mock: no such object ' . $remote_key);
		}
		$dir = dirname($local_path);
		if (!is_dir($dir)) { mkdir($dir, 0777, true); }
		file_put_contents($local_path, substr($this->objects[$remote_key], $start, $end - $start + 1));
	}
	public function delete(string $remote_key): void { unset($this->objects[$remote_key]); }
	public function url(string $remote_key): string { return ''; }
	public function ping(): array { return ['ok' => true, 'message' => 'mock']; }
	public function head(string $remote_key): ?array {
		if (!array_key_exists($remote_key, $this->objects)) { return null; }
		return ['size' => strlen($this->objects[$remote_key]), 'etag' => md5($this->objects[$remote_key])];
	}
}

/**
 * A StorageProfile over a caller-owned scratch table + on-disk base dir.
 *
 * Options (all optional):
 *   pkey, driver_col, failed_col, last_attempt_col, last_error_col, target_col,
 *     key_col — column names (defaults 'id' / 'drv' / 'failed' / 'last_attempt' /
 *     'last_error' / 'target_id' / 'remote_key'); the caller's table carries them
 *   eligibility_where        — forward-offload SQL gate (default 'TRUE')
 *   reverse_eligibility_where — reverse (restore) ownership gate; default ''
 *     means "no reverse gate", identical to a profile that omits the method
 *     (the engine trims the result, so '' == absent)
 *   is_eligible              — callable(array $row): bool, ANDed with the
 *     local-driver check in isEligibleRow(); default null == always (given local)
 */
class ScratchTableProfile implements StorageProfile {
	private $table;
	private $base;
	private $opts;

	public function __construct(string $table, string $base, array $opts = []) {
		$this->table = $table;
		$this->base  = $base;
		$this->opts  = $opts + [
			'pkey'                      => 'id',
			'driver_col'                => 'drv',
			'failed_col'                => 'failed',
			'last_attempt_col'          => 'last_attempt',
			'last_error_col'            => 'last_error',
			'target_col'                => 'target_id',
			'key_col'                   => 'remote_key',
			'eligibility_where'         => 'TRUE',
			'reverse_eligibility_where' => '',
			'is_eligible'               => null,
			// Extra object suffixes a row carries beyond 'original' (e.g.
			// ['thumb']). Lets a test exercise a multi-object row — the case where
			// a later PUT fails and the engine must roll back the earlier pushes.
			'variants'                  => [],
		];
	}

	public function table(): string { return $this->table; }
	public function pkeyColumn(): string { return $this->opts['pkey']; }
	public function driverColumn(): string { return $this->opts['driver_col']; }
	public function failedCountColumn(): string { return $this->opts['failed_col']; }
	public function lastAttemptColumn(): string { return $this->opts['last_attempt_col']; }
	public function lastErrorColumn(): string   { return $this->opts['last_error_col']; }
	public function targetColumn(): string      { return $this->opts['target_col']; }
	public function remoteKeyColumn(): string   { return $this->opts['key_col']; }
	public function visibility(): string { return 'private'; }
	public function eligibilityWhere(): string { return $this->opts['eligibility_where']; }
	public function reverseEligibilityWhere(): string { return $this->opts['reverse_eligibility_where']; }

	private function _row($id) {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT * FROM {$this->table} WHERE {$this->opts['pkey']} = ?");
		$q->execute([$id]);
		return $q->fetch(PDO::FETCH_ASSOC);
	}

	public function rowExists(int $id): bool { return (bool)$this->_row($id); }

	public function isEligibleRow(int $id): bool {
		$r = $this->_row($id);
		if (!$r) return false;
		$drv = $r[$this->opts['driver_col']];
		$local = ($drv === null || $drv === '' || $drv === 'local');
		if (!$local) return false;
		$pred = $this->opts['is_eligible'];
		return $pred === null ? true : (bool)$pred($r);
	}

	public function itemsForRow(int $id): ?array {
		$path = $this->base . '/disk/' . $id . '/original';
		if (!file_exists($path)) return null;
		$items = [['local_path' => $path, 'name' => $id . '/original', 'content_type' => 'application/octet-stream']];
		foreach ($this->opts['variants'] as $variant) {
			$items[] = [
				'local_path'   => $this->base . '/disk/' . $id . '/' . $variant,
				'name'         => $id . '/' . $variant,
				'content_type' => 'application/octet-stream',
			];
		}
		return $items;
	}

	/** The recorded key of the original; a variant sits beside it, as a blob's does. */
	public function reverseItemsForRow(int $id): array {
		$r = $this->_row($id);
		$recorded = $r ? (string)($r[$this->opts['key_col']] ?? '') : '';
		$key = $recorded !== '' ? $recorded : $id . '/original';
		$folder = substr($key, 0, strlen($key) - strlen('original'));
		$items = [[
			'remote_key'   => $key,
			'name'         => $id . '/original',
			'local_path'   => $this->base . '/restore/' . $id . '/original',
			'content_type' => 'application/octet-stream',
		]];
		foreach ($this->opts['variants'] as $variant) {
			$items[] = [
				'remote_key'   => $folder . $variant,
				'name'         => $id . '/' . $variant,
				'local_path'   => $this->base . '/restore/' . $id . '/' . $variant,
				'content_type' => 'application/octet-stream',
			];
		}
		return $items;
	}
}

} // class_exists guard
