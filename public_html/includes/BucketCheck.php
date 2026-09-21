<?php
/**
 * BucketCheck — the questions both bucket forms ask before they save.
 *
 * A site keeps two kinds of bucket: backup storage (a backup target) and the
 * file store (the cloud storage page's public and private buckets). Each is
 * safe only when it is not the other, when it is read by nobody it should
 * not be, and when its key can do the job and not much more. These are the
 * shared pieces; TargetTester runs them for a backup target and
 * CloudStorageLifecycle::testConnection() for the file store. Every answer
 * is a step: ['label', 'status' => pass|warn|fail, 'message'] in words an
 * operator can act on.
 *
 * Tests set $test_hooks to answer the account-wide questions without a
 * database row or a provider call:
 *   'file_store_buckets'    => fn(): array           the file store's buckets
 *   'backup_target_buckets' => fn(?int $except): array  the backup targets' buckets
 *   'b2_allowed'            => fn($key_id, $app_key): array  what Backblaze says a key may do
 *   'anonymous_status'      => fn($url): int         the HTTP status an anonymous GET gets
 *   'is_b2'                 => bool                  treat any endpoint as Backblaze
 *
 * @version 1.0 - specs/storage_bucket_and_key_check.md
 */

class BucketCheck {

	public static $test_hooks = array();

	/** What a backup target's main key must be able to do on Backblaze: list, read, write, and prune. */
	const B2_BACKUP_CAPABILITIES = array('listFiles', 'readFiles', 'writeFiles', 'deleteFiles');
	/** What a key must be able to do to mint a per-run key. */
	const B2_MINT_CAPABILITIES = array('writeKeys', 'listKeys', 'deleteKeys');
	/** What the file store's key must be able to do: serve, store, and permanently delete. */
	const B2_FILE_STORE_CAPABILITIES = array('listFiles', 'readFiles', 'writeFiles', 'deleteFiles');

	// ── Same bucket on both sides ───────────────────────────────────────

	/**
	 * Two bucket bindings name the same bucket when the names match and the
	 * endpoints, where both are known, are on the same host. Bucket names are
	 * unique within a provider, so the host is what tells two providers apart.
	 */
	public static function same_bucket($bucket, $endpoint, $other_bucket, $other_endpoint) {
		$bucket = strtolower(trim((string)$bucket));
		$other_bucket = strtolower(trim((string)$other_bucket));
		if ($bucket === '' || $bucket !== $other_bucket) {
			return false;
		}
		$host = self::host($endpoint);
		$other_host = self::host($other_endpoint);
		if ($host === '' || $other_host === '') {
			return true;
		}
		return $host === $other_host;
	}

	/** The file store's buckets: [['bucket', 'endpoint', 'label'], …], only those set. */
	public static function file_store_buckets() {
		if (isset(self::$test_hooks['file_store_buckets'])) {
			return call_user_func(self::$test_hooks['file_store_buckets']);
		}
		$settings = Globalvars::get_instance();
		$endpoint = (string)$settings->get_setting('cloud_storage_endpoint');
		$out = array();
		foreach (array('cloud_storage_bucket' => 'the public file store', 'cloud_storage_private_bucket' => 'the private file store') as $name => $label) {
			$bucket = trim((string)$settings->get_setting($name));
			if ($bucket !== '') {
				$out[] = array('bucket' => $bucket, 'endpoint' => $endpoint, 'label' => $label);
			}
		}
		return $out;
	}

	/** Every backup target's bucket, except the one being edited: [['bucket', 'endpoint', 'label'], …]. */
	public static function backup_target_buckets($except_id = null) {
		if (isset(self::$test_hooks['backup_target_buckets'])) {
			return call_user_func(self::$test_hooks['backup_target_buckets'], $except_id);
		}
		$out = array();
		foreach (new MultiBackupTarget(array('deleted' => false)) as $target) {
			if ($except_id !== null && (int)$target->key === (int)$except_id) {
				continue;
			}
			$endpoint = '';
			try {
				$creds = $target->get_credentials();
				$endpoint = (string)($creds['endpoint'] ?? '');
			} catch (Exception $e) {
				// An unreadable credential still names its bucket; the name is what matters here.
			}
			$out[] = array(
				'bucket'   => (string)$target->get('bkt_bucket'),
				'endpoint' => $endpoint,
				'label'    => 'the backup target "' . $target->get('bkt_name') . '"',
			);
		}
		return $out;
	}

	/**
	 * The step that says whether $bucket is already one of $others. A match is
	 * a fail: files and backups in one bucket means one mistaken deletion, or
	 * one public-read setting, takes both.
	 *
	 * @param string $bucket   the bucket being saved
	 * @param string $endpoint its endpoint
	 * @param array  $others   the other side's buckets, from file_store_buckets() or backup_target_buckets()
	 * @param string $purpose  what this bucket is for, for the pass line: 'backups' | 'files'
	 */
	public static function collision_step($bucket, $endpoint, array $others, $purpose) {
		foreach ($others as $other) {
			if (self::same_bucket($bucket, $endpoint, $other['bucket'], $other['endpoint'])) {
				return array('label' => 'Its own bucket', 'status' => 'fail',
					'message' => 'The bucket "' . $bucket . '" is already ' . $other['label'] . '. '
						. ($purpose === 'backups'
							? 'Backups need a bucket of their own: a file bucket is public, and one deletion or one public-read setting would take files and backups together. Make a private bucket for backups and name it here.'
							: 'Files need a bucket of their own: this one holds backups, and one deletion or one public-read setting would take files and backups together. Make a bucket for files and name it here.'));
			}
		}
		return array('label' => 'Its own bucket', 'status' => 'pass',
			'message' => $purpose === 'backups' ? 'No file store uses this bucket.' : 'No backup target uses this bucket.');
	}

	// ── Who can read it ─────────────────────────────────────────────────

	/** HTTP status an anonymous GET of $url gets, or 0 when no connection could be made. */
	public static function anonymous_status($url) {
		if (isset(self::$test_hooks['anonymous_status'])) {
			return (int)call_user_func(self::$test_hooks['anonymous_status'], $url);
		}
		$context = stream_context_create(array('http' => array('method' => 'GET', 'timeout' => 5, 'ignore_errors' => true)));
		$lines = @get_headers($url, false, $context);
		if (!$lines || !is_array($lines)) {
			return 0;
		}
		if (preg_match('/\s(\d{3})\s/', ' ' . $lines[0] . ' ', $m)) {
			return (int)$m[1];
		}
		return 0;
	}

	/** The path-style address of one object, the form the signer uses. */
	public static function object_url(array $creds, $bucket, $key) {
		return rtrim((string)$creds['endpoint'], '/') . '/' . rawurlencode($bucket) . '/' . ltrim(str_replace('%2F', '/', rawurlencode($key)), '/');
	}

	/**
	 * The step that says whether a bucket meant to be private is. An anonymous
	 * read that succeeds is a fail: backups are encrypted, but nobody outside
	 * should be able to fetch them at all.
	 */
	public static function private_read_step($url) {
		$status = self::anonymous_status($url);
		if ($status >= 200 && $status < 300) {
			return array('label' => 'Private', 'status' => 'fail',
				'message' => 'Anyone can read this bucket without a key (an anonymous request got HTTP ' . $status . '). '
					. 'Backups belong in a private bucket. Set the bucket to private at the provider and save again.');
		}
		return array('label' => 'Private', 'status' => 'pass',
			'message' => 'Nobody can read this bucket without a key' . ($status > 0 ? ' (anonymous request got HTTP ' . $status . ')' : '') . '.');
	}

	// ── What a Backblaze key may do ─────────────────────────────────────

	/** True when this endpoint is Backblaze, where a key states what it may do. */
	public static function is_b2($endpoint) {
		if (array_key_exists('is_b2', self::$test_hooks)) {
			return (bool)self::$test_hooks['is_b2'];
		}
		return (bool)preg_match('/\.backblazeb2\.com$/i', self::host($endpoint));
	}

	/**
	 * What Backblaze says a key may do: its capabilities, and the one bucket it
	 * is pinned to (empty for a key that opens every bucket on the account).
	 * Throws on a refused or unreachable authorize.
	 *
	 * @return array{capabilities: array, bucketName: string, namePrefix: string}
	 */
	public static function b2_allowed($key_id, $app_key) {
		if (isset(self::$test_hooks['b2_allowed'])) {
			$allowed = call_user_func(self::$test_hooks['b2_allowed'], $key_id, $app_key);
		} else {
			$allowed = (new B2Client((string)$key_id, (string)$app_key))->authorize()['allowed'] ?? array();
		}
		return array(
			'capabilities' => array_values(array_map('strval', (array)($allowed['capabilities'] ?? array()))),
			'bucketName'   => (string)($allowed['bucketName'] ?? ''),
			'namePrefix'   => (string)($allowed['namePrefix'] ?? ''),
		);
	}

	/**
	 * The steps for one Backblaze key: whether it reaches this bucket and no
	 * more, and whether it can do what $needed names.
	 *
	 * A key pinned to another bucket is a fail (nothing here would work). A
	 * key that opens every bucket on the account is a warn, and names which of
	 * the other side's buckets it also opens: that is the blast radius of a
	 * leaked key, and a key made for one bucket shrinks it. A missing
	 * capability is a fail that names it.
	 *
	 * @param array  $creds   access_key / secret_key
	 * @param string $bucket  the bucket this key is for
	 * @param array  $needed  capabilities the job needs (self::B2_* constants)
	 * @param string $role    'main key' | 'node key' | 'file store key', for the labels
	 * @param array  $others  the other side's buckets, so an account-wide warning can name them
	 */
	public static function b2_key_steps(array $creds, $bucket, array $needed, $role, array $others = array()) {
		try {
			$allowed = self::b2_allowed($creds['access_key'] ?? '', $creds['secret_key'] ?? '');
		} catch (Exception $e) {
			return array(array('label' => ucfirst($role) . ' reach', 'status' => 'fail',
				'message' => 'Backblaze refused the ' . $role . ' (' . $e->getMessage() . '). Check the key id and application key.'));
		}
		$steps = array();
		$pinned = $allowed['bucketName'];
		if ($pinned !== '' && strtolower($pinned) !== strtolower((string)$bucket)) {
			$steps[] = array('label' => ucfirst($role) . ' reach', 'status' => 'fail',
				'message' => 'The ' . $role . ' is made for the bucket "' . $pinned . '", not "' . $bucket . '". Use a key made for this bucket.');
		} elseif ($pinned === '') {
			$also = array();
			foreach ($others as $other) {
				if (self::host($other['endpoint']) === '' || self::is_b2($other['endpoint'])) {
					$also[] = $other['bucket'] . ' (' . $other['label'] . ')';
				}
			}
			$steps[] = array('label' => ucfirst($role) . ' reach', 'status' => 'warn',
				'message' => 'The ' . $role . ' opens every bucket on the account'
					. ($also ? ', including ' . implode(', ', $also) : '')
					. '. It works, but a key made for this bucket alone limits what a leaked key can reach.');
		} else {
			$steps[] = array('label' => ucfirst($role) . ' reach', 'status' => 'pass',
				'message' => 'The ' . $role . ' is made for this bucket' . ($allowed['namePrefix'] !== '' ? ' (names under "' . $allowed['namePrefix'] . '")' : '') . '.');
		}
		$missing = array_values(array_diff($needed, $allowed['capabilities']));
		if ($missing) {
			$steps[] = array('label' => ucfirst($role) . ' capabilities', 'status' => 'fail',
				'message' => 'The ' . $role . ' cannot ' . implode(', ', $missing) . '. '
					. self::capability_consequence($missing) . ' Make the key with '
					. implode(', ', $needed) . ' and enter it here.');
		} else {
			$steps[] = array('label' => ucfirst($role) . ' capabilities', 'status' => 'pass',
				'message' => 'The ' . $role . ' can ' . implode(', ', $needed) . '.');
		}
		return $steps;
	}

	/** Which job a missing Backblaze capability would break, in words. */
	private static function capability_consequence(array $missing) {
		$why = array();
		if (in_array('deleteFiles', $missing, true)) { $why[] = 'retention could never prune, so the bucket would only grow'; }
		if (in_array('writeFiles', $missing, true)) { $why[] = 'nothing could be stored'; }
		if (in_array('readFiles', $missing, true)) { $why[] = 'nothing could be read back'; }
		if (in_array('listFiles', $missing, true)) { $why[] = 'nothing could be listed'; }
		if (array_intersect(array('writeKeys', 'listKeys', 'deleteKeys'), $missing)) { $why[] = 'no per-run key could be minted, and every run would fail'; }
		return $why ? ucfirst(implode('; ', $why)) . '.' : '';
	}

	// ── Helpers ─────────────────────────────────────────────────────────

	/** The host of an endpoint, lower-cased, '' when none. */
	public static function host($endpoint) {
		$endpoint = trim((string)$endpoint);
		if ($endpoint === '') {
			return '';
		}
		if (strpos($endpoint, '://') === false) {
			$endpoint = 'https://' . $endpoint;
		}
		$host = parse_url($endpoint, PHP_URL_HOST);
		return strtolower((string)$host);
	}

	/** True when any step failed. */
	public static function failed(array $steps) {
		foreach ($steps as $step) {
			if (($step['status'] ?? '') === 'fail') {
				return true;
			}
		}
		return false;
	}

	/** The messages of every step at $status, in order. */
	public static function messages(array $steps, $status) {
		$out = array();
		foreach ($steps as $step) {
			if (($step['status'] ?? '') === $status) {
				$out[] = (string)$step['message'];
			}
		}
		return $out;
	}
}
