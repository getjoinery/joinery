<?php
/**
 * DaemonVaultKey - the VaultKey held by the unseal daemon behind a handle
 * (specs/unseal_daemon.md § The PHP seam, § The operations).
 *
 * The secret never enters PHP. What this object holds is the daemon's
 * 32-byte window handle (64 hex characters), the key's public half, and the
 * owner and scope the window was opened for. unseal() is the daemon's
 * `unwrap`, openPrevious() its `open_previous`, close() its `close`; open()
 * is `open`, the only call that ever hands back wrappings or a chain KEK, and
 * only for an unlocker presented in the same request (B1, B2).
 *
 * The window slot (VaultUnlock's store entry) holds the handle, not the key:
 * worth something only while the daemon still holds that window, worthless
 * the moment it ends or the daemon restarts.
 *
 * Clocks. The daemon keeps the window's idle clock and absolute cap and ends
 * it on its own. Its idle clock moves with VaultUnlock's accounting: touch()
 * is an `unwrap` flagged as activity of a blob sealed to this key at open
 * (an unwrap that decrypts, which a bare refresh is not allowed to be), sent
 * whenever VaultUnlock counts a content read. Every other unwrap of a window
 * is not activity, so the two clocks are one. A key no window holds (a
 * rotation's old generation, a probe) counts every unwrap as activity: its
 * window is this request's alone, and VaultUnlock closes it when the request
 * ends.
 *
 * Failures. A handle the daemon no longer knows (`unknown_handle`) is a
 * window that ended; VaultUnlock::windowEnded() closes the slots that held it,
 * with the reason, and the read reads as locked. A daemon that does not
 * answer reads as locked too, without closing anything: if it was a blip the
 * next request reads on.
 *
 * @version 1.0
 */
class DaemonVaultKey implements VaultKey {

	/** The prefix of a slot value holding a daemon window. */
	const SLOT_TAG = 'unseal1.';

	/** Errors that mean the daemon could not be reached or did not answer in frame. */
	const UNREACHABLE = array(UnsealClient::ERR_CONNECT, UnsealClient::ERR_TRANSPORT);

	/** @var array<string,UnsealClient> one connection per socket per request */
	private static $clients = array();

	/** @var string */
	private $socket;
	/** @var string 64 hex */
	private $handle;
	/** @var string base64url */
	private $public;
	/** @var int */
	private $user_id;
	/** @var string */
	private $scope;
	/** @var string raw sealed blob touch() unwraps; '' for a child */
	private $touch;
	/** @var int unix time the window was opened */
	private $opened_at;
	/** @var bool whether a window slot holds this handle (see the class comment) */
	private $armed = false;
	/** @var ?DaemonVaultKey the window a child (a retired key) lives under */
	private $parent = null;

	private function __construct(string $socket, string $handle, string $public, int $user_id, string $scope,
			string $touch, int $opened_at) {
		if (!preg_match('/^[0-9a-f]{64}$/', $handle)) {
			throw new RuntimeException('DaemonVaultKey: malformed handle.');
		}
		$this->socket = $socket;
		$this->handle = $handle;
		$this->public = $public;
		$this->user_id = $user_id;
		$this->scope = $scope;
		$this->touch = $touch;
		$this->opened_at = $opened_at;
	}

	/** The connection for $socket, made once per request. */
	public static function client(string $socket): UnsealClient {
		if (!isset(self::$clients[$socket])) {
			self::$clients[$socket] = new UnsealClient($socket, 3.0);
		}
		return self::$clients[$socket];
	}

	/** Drop the request's connections. Tests only, between daemons. */
	public static function resetClientsForTests(): void {
		foreach (self::$clients as $c) {
			$c->disconnect();
		}
		self::$clients = array();
	}

	/**
	 * The daemon's `open`. $unlocker is ['wrapped', 'kek', 'ad'] or null to
	 * mint; $wrap_under a list of ['kek', 'ad']; $reveal sealed chain KEKs
	 * (raw `v2.seal.` blobs, framed), only with an unlocker. The KEK and the
	 * wrapped blob stay with the caller (PHP zeroes a string only at its last
	 * reference); the client zeroes the frame it sent.
	 *
	 * @return array{key: DaemonVaultKey, wrappings: string[], revealed: array<int|string, array{kek: ?string, scope: string}>}
	 * @throws DaemonUnreachableException when nothing answered
	 * @throws VaultHeldException when the daemon is held
	 * @throws RuntimeException when the unlocker did not open (or another refusal)
	 */
	public static function open(string $socket, int $user_id, string $scope, int $idle, int $absolute,
			?array $unlocker, array $wrap_under = array(), array $reveal = array()): array {
		$fields = array('op' => 'open', 'user' => $user_id, 'scope' => $scope, 'idle' => $idle, 'absolute' => $absolute);
		$parts = array();
		if ($unlocker !== null) {
			$fields['unlock'] = true;
			$parts[] = (string)$unlocker['wrapped'];
			$parts[] = (string)$unlocker['kek'];
			$parts[] = (string)$unlocker['ad'];
		}
		$wrap_keys = array_keys($wrap_under);
		foreach ($wrap_under as $entry) {
			$parts[] = (string)$entry['kek'];
			$parts[] = (string)$entry['ad'];
		}
		if ($wrap_under) {
			$fields['wrap'] = count($wrap_under);
		}
		$reveal_keys = array_keys($reveal);
		foreach ($reveal as $sealed) {
			[, $raw] = SealedBox::unframeSeal((string)$sealed);
			$parts[] = $raw;
		}
		if ($reveal) {
			$fields['reveal'] = count($reveal);
		}
		$reply = self::client($socket)->call($fields, $parts);
		$parts = null;
		$f = $reply['fields'];
		self::raise($f);
		$out = $reply['parts'];
		$public_raw = (string)($out[0] ?? '');
		if (strlen($public_raw) === 0 || !is_string($f['handle'] ?? null) || (int)($f['version'] ?? 0) !== SealedBox::V2
				|| count($out) !== 1 + count($wrap_under) + count($reveal)) {
			throw new RuntimeException('DaemonVaultKey: the daemon\'s open reply was malformed.');
		}
		$public = SealedBox::b64url($public_raw);
		$touch = SealedBox::sealV2(random_bytes(16), $public_raw);
		$key = new self($socket, $f['handle'], $public, $user_id, $scope, $touch, time());
		$wrappings = array();
		foreach ($wrap_keys as $i => $slot) {
			$wrappings[$slot] = $out[1 + $i];
		}
		$revealed = array();
		$scopes = (array)($f['revealed'] ?? array());
		foreach ($reveal_keys as $i => $slot) {
			$kek = $out[1 + count($wrap_under) + $i];
			$revealed[$slot] = array('kek' => $kek === '' ? null : $kek, 'scope' => (string)($scopes[$i] ?? ''));
		}
		return array('key' => $key, 'wrappings' => $wrappings, 'revealed' => $revealed);
	}

	/**
	 * Raise for a refusal: unreachable, held, a handle the daemon no longer
	 * knows, busy, and anything else as a RuntimeException naming the code.
	 */
	private static function raise(array $f): void {
		if (($f['ok'] ?? false) === true) {
			return;
		}
		$code = (string)($f['error'] ?? 'unknown');
		if (in_array($code, self::UNREACHABLE, true)) {
			throw new DaemonUnreachableException('The unseal daemon did not answer: ' . (string)($f['message'] ?? $code));
		}
		if ($code === 'held') {
			throw new VaultHeldException();
		}
		if ($code === 'unknown_handle') {
			throw new DaemonWindowGoneException();
		}
		if ($code === 'busy' || $code === 'at_capacity') {
			throw new DaemonBusyException('The unseal daemon is ' . ($code === 'busy' ? 'busy' : 'at its window limit') . '.');
		}
		throw new RuntimeException('The unseal daemon refused: ' . $code);
	}

	public function id(): string {
		return hash('sha256', 'joinery:vault-key:daemon:' . $this->handle);
	}

	public function publicKey(): string {
		return $this->public;
	}

	public function version(): int {
		return SealedBox::V2;
	}

	public function userId(): int {
		return $this->user_id;
	}

	public function scope(): string {
		return $this->scope;
	}

	/** When the window was opened (unix time), so an ended one can be told from a restart. */
	public function openedAt(): int {
		return $this->opened_at;
	}

	/** Whether $other names the same daemon window. */
	public function sameWindow(DaemonVaultKey $other): bool {
		return hash_equals($this->handle, $other->handle);
	}

	/** VaultUnlock marks a key a slot holds; from then on only touch() is activity. */
	public function markArmed(): void {
		$this->armed = true;
	}

	public function isArmed(): bool {
		return $this->armed;
	}

	public function unseal(array $sealed): array {
		if (!$sealed) {
			return array();
		}
		$keys = array_keys($sealed);
		$reply = $this->call(array('op' => 'unwrap', 'handle' => $this->handle, 'activity' => !$this->rootArmed()),
			array_values(array_map('strval', $sealed)));
		if (count($reply['parts']) !== count($keys)) {
			throw new RuntimeException('DaemonVaultKey: the daemon\'s unwrap reply was malformed.');
		}
		return array_combine($keys, $reply['parts']);
	}

	public function unsealEdge(array $sealed): array {
		// For an X-Wing key the browser's format is the v2 raw form in base64:
		// decoded here, opened as a raw box (unseal_daemon.md § WP2 as built).
		$raw = array();
		foreach ($sealed as $slot => $blob) {
			$bytes = SealedBox::b64any_decode((string)$blob);
			if ($bytes === false) {
				throw new RuntimeException('DaemonVaultKey: malformed edge-sealed blob.');
			}
			$raw[$slot] = $bytes;
		}
		return $this->unseal($raw);
	}

	/**
	 * The daemon's `open_previous`: a retired key from the vault's chain, held
	 * as a child window of this one (same clocks, closed with it).
	 */
	public function openPrevious(string $sealed_kek, string $wrapped, string $ad): VaultKey {
		[, $raw] = SealedBox::unframeSeal($sealed_kek);
		$reply = $this->call(array('op' => 'open_previous', 'handle' => $this->handle), array($raw, $wrapped, $ad));
		$f = $reply['fields'];
		$public_raw = (string)($reply['parts'][0] ?? '');
		if (!is_string($f['handle'] ?? null) || $public_raw === '') {
			throw new RuntimeException('DaemonVaultKey: the daemon\'s open_previous reply was malformed.');
		}
		$child = new self($this->socket, $f['handle'], SealedBox::b64url($public_raw), $this->user_id, $this->scope,
			'', $this->opened_at);
		$child->parent = $this;
		return $child;
	}

	/**
	 * Count a content read on the daemon's idle clock: an activity unwrap of
	 * this key's own touch blob. True when the window is still there.
	 */
	public function touch(): bool {
		if ($this->touch === '') {
			return true;
		}
		try {
			$this->call(array('op' => 'unwrap', 'handle' => $this->handle, 'activity' => true), array($this->touch));
			return true;
		} catch (VaultLockedException $e) {
			return false;
		}
	}

	/**
	 * The daemon's `close` for this handle: [closed, unwraps], or null when
	 * the daemon did not answer (it then ends the window on its own clock).
	 */
	public function close(): ?array {
		$reply = self::client($this->socket)->call(array('op' => 'close', 'handle' => $this->handle));
		if (($reply['fields']['ok'] ?? false) !== true) {
			return null;
		}
		return array('closed' => (int)($reply['fields']['closed'] ?? 0), 'unwraps' => (int)($reply['fields']['unwraps'] ?? 0));
	}

	/** A child's activity follows the window it lives under. */
	private function rootArmed(): bool {
		return $this->parent !== null ? $this->parent->rootArmed() : $this->armed;
	}

	/**
	 * One request on this window. A handle the daemon no longer knows tells
	 * VaultUnlock (which closes the slots that held it) and reads as locked;
	 * an unreachable daemon reads as locked; anything else is an error.
	 */
	private function call(array $fields, array $parts): array {
		$reply = self::client($this->socket)->call($fields, $parts);
		try {
			self::raise($reply['fields']);
		} catch (DaemonWindowGoneException $e) {
			VaultUnlock::windowEnded($this->parent ?? $this);
			throw new VaultLockedException();
		} catch (DaemonUnreachableException $e) {
			error_log('DaemonVaultKey: ' . $e->getMessage() . ' (user ' . $this->user_id . ' reads as locked)');
			throw new VaultLockedException();
		}
		return $reply;
	}

	// ---- The window slot. VaultUnlock is the only caller of these. ----

	/** The slot value: the handle and what PHP needs beside it, never a secret. */
	public function slotValue(): string {
		return self::SLOT_TAG . SealedBox::b64url(json_encode(array(
			'h' => $this->handle, 'p' => $this->public, 'u' => $this->user_id, 's' => $this->scope,
			't' => SealedBox::b64url($this->touch), 'a' => $this->opened_at,
		)));
	}

	/** The key a slot value names, or null when it is not a daemon window's. */
	public static function fromSlotValue(string $value, string $socket): ?DaemonVaultKey {
		if (strncmp($value, self::SLOT_TAG, strlen(self::SLOT_TAG)) !== 0) {
			return null;
		}
		$json = SealedBox::b64url_decode(substr($value, strlen(self::SLOT_TAG)));
		$d = $json === false ? null : json_decode($json, true);
		if (!is_array($d) || !is_string($d['h'] ?? null) || !is_string($d['p'] ?? null) || !is_int($d['u'] ?? null)
				|| !is_string($d['s'] ?? null) || !is_string($d['t'] ?? null) || !is_int($d['a'] ?? null)) {
			return null;
		}
		$touch = SealedBox::b64url_decode($d['t']);
		try {
			$key = new self($socket, $d['h'], $d['p'], $d['u'], $d['s'], $touch === false ? '' : $touch, $d['a']);
		} catch (RuntimeException $e) {
			return null;
		}
		$key->armed = true;
		return $key;
	}

	public function __debugInfo(): array {
		return array('id' => $this->id(), 'version' => SealedBox::V2, 'public' => $this->public, 'user' => $this->user_id);
	}

	public function __serialize(): array {
		throw new RuntimeException('DaemonVaultKey cannot be serialized.');
	}

	public function __unserialize(array $data): void {
		throw new RuntimeException('DaemonVaultKey cannot be unserialized.');
	}
}

/** The unseal daemon did not answer at its socket. */
class DaemonUnreachableException extends RuntimeException {}

/** The daemon answered but cannot take this now: its bytes in flight or its window count. */
class DaemonBusyException extends RuntimeException {}

/** The daemon no longer holds the window a handle named. Internal to DaemonVaultKey. */
class DaemonWindowGoneException extends RuntimeException {}
?>
