<?php
/**
 * LogTileReader - reads a Rekor v2 log as it is served: its signed
 * checkpoint, and its entries in bundles of 256 (C2SP tlog-tiles).
 *
 * Rekor v2 has no search. What a log serves is its latest checkpoint
 * (/api/v2/checkpoint, a signed note naming the tree size and root) and its
 * entries, 256 to a file, at /api/v2/tile/entries/<N> - N being the entry
 * index divided by 256, written in groups of three digits with every group
 * but the last prefixed by x (index 142718338 is bundle 557493, path
 * x557/493). The newest bundle of a tree whose size is not a multiple of 256
 * is partial and is served as <path>.p/<count>; once the tree fills it, the
 * full bundle is served and the partial one may not be. An entry bundle is each
 * entry's bytes behind a two-byte big-endian length.
 *
 * Used by utils/verify_release.php to compare a release's stored entry with
 * what the log serves at its index. A node never reads the log.
 *
 * @version 1.0
 */
class LogTileReader {

	const ENTRIES_PER_TILE = 256;

	/** @var callable (string $url): array{status:int, body:string} */
	private $transport;

	/** @param callable|null $transport HTTP GET, injected by tests; curl by default */
	public function __construct($transport = null) {
		$this->transport = $transport ?: array(__CLASS__, 'curlGet');
	}

	/**
	 * The log's current checkpoint, verified against the key held for its
	 * origin: {origin, tree_size, root_hash}.
	 *
	 * @param array $log_keys origin => Ed25519 DER
	 */
	public function checkpoint($origin, array $log_keys) {
		$note = $this->get(self::base($origin) . '/api/v2/checkpoint');
		$checkpoint = TransparencyProof::verifyCheckpoint($note, $log_keys);
		if ($checkpoint['origin'] !== $origin) {
			throw new TransparencyProofException("the checkpoint served by {$origin} is for {$checkpoint['origin']}");
		}
		return $checkpoint;
	}

	/** The bytes of entry $index, from the log's entry bundle holding it. */
	public function entry($origin, $index, $tree_size) {
		if ($index < 0 || $index >= $tree_size) {
			throw new TransparencyProofException("entry {$index} is past the end of {$origin}'s tree ({$tree_size} entries)");
		}
		$tile = intdiv($index, self::ENTRIES_PER_TILE);
		$in_tile = min(self::ENTRIES_PER_TILE, $tree_size - $tile * self::ENTRIES_PER_TILE);
		$full = self::base($origin) . '/api/v2/tile/entries/' . self::tilePath($tile);
		if ($in_tile < self::ENTRIES_PER_TILE) {
			// The newest bundle is partial. The tree may have filled it since
			// the checkpoint was read, and a log may stop serving a partial
			// bundle once the full one exists, so a miss asks for the full one.
			$res = call_user_func($this->transport, $full . '.p/' . $in_tile);
			$bytes = (int)$res['status'] === 200 ? (string)$res['body'] : $this->get($full);
		} else {
			$bytes = $this->get($full);
		}
		$entries = self::bundleEntries($bytes);
		$at = $index % self::ENTRIES_PER_TILE;
		if (!isset($entries[$at])) {
			throw new TransparencyProofException("{$origin}'s entry bundle {$tile} holds " . count($entries) . " entries, not entry {$index}");
		}
		return $entries[$at];
	}

	/** A tile number as C2SP writes it in a path: 557493 is x557/493, 5 is 005. */
	public static function tilePath($n) {
		$groups = array();
		do {
			array_unshift($groups, sprintf('%03d', $n % 1000));
			$n = intdiv($n, 1000);
		} while ($n > 0);
		$last = array_pop($groups);
		return implode('', array_map(function ($g) { return 'x' . $g . '/'; }, $groups)) . $last;
	}

	/** The entries of an entry bundle, in order. */
	public static function bundleEntries($bytes) {
		$entries = array();
		$at = 0;
		$len = strlen($bytes);
		while ($at < $len) {
			if ($at + 2 > $len) {
				throw new TransparencyProofException('an entry bundle ends inside an entry length');
			}
			$size = unpack('n', substr($bytes, $at, 2))[1];
			if ($at + 2 + $size > $len) {
				throw new TransparencyProofException('an entry bundle ends inside an entry');
			}
			$entries[] = substr($bytes, $at + 2, $size);
			$at += 2 + $size;
		}
		return $entries;
	}

	private static function base($origin) {
		if (!preg_match('/^[a-z0-9.-]+$/', (string)$origin)) {
			throw new TransparencyProofException("{$origin} is not a log origin");
		}
		return 'https://' . $origin;
	}

	private function get($url) {
		$res = call_user_func($this->transport, $url);
		if ((int)$res['status'] !== 200) {
			throw new TransparencyProofException("{$url} answered HTTP {$res['status']}");
		}
		return (string)$res['body'];
	}

	/** Default transport: curl, TLS verified. */
	public static function curlGet($url) {
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_SSL_VERIFYPEER => true,
		));
		$body = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$error = curl_error($ch);
		curl_close($ch);
		if ($body === false) {
			throw new TransparencyProofException("could not fetch {$url}: {$error}");
		}
		return array('status' => $status, 'body' => (string)$body);
	}
}
