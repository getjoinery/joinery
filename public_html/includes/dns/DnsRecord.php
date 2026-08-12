<?php
/**
 * DnsRecord - one record a subsystem wants published, or one record a provider
 * currently holds.
 *
 * The vocabulary is deliberately small: A, AAAA, CNAME, MX, TXT, CAA and SRV.
 * NS and SOA are never expressible — the platform holds record state, it never becomes
 * a nameserver, and a plan that could rewrite delegation would be able to take a
 * zone away from its owner.
 *
 * Comparison rules that matter, because they decide what shows as a diff:
 *  - TTL is optional. A record that omits it means "provider default", and a
 *    live record whose only difference is a TTL the plan never asked for MATCHES.
 *    A zone left on default TTLs therefore never shows permanent diff noise.
 *  - Values are compared canonically, not textually: TXT quoting and chunking is
 *    stripped, hostnames lose their trailing dot and case, MX priority lives in
 *    its own field rather than baked into the value.
 *
 * A record can also be required to be ABSENT (see mustBeAbsent()). A plan of
 * only-things-that-should-exist cannot express a capability that has to go away,
 * and a page that publishes such a plan says "nothing to change" while a check
 * beside it names a record to delete. The two disagreeing about whether a domain
 * is finished is the failure this subsystem exists to end, so absence is part of
 * desired state rather than a hand-edit at the provider.
 *
 * @version 1.2 - SRV joins the vocabulary (Joinery Direct's capability record)
 */

class DnsRecordException extends Exception {}

class DnsRecord {

	const TYPE_A     = 'A';
	const TYPE_AAAA  = 'AAAA';
	const TYPE_CNAME = 'CNAME';
	const TYPE_MX    = 'MX';
	const TYPE_TXT   = 'TXT';
	const TYPE_CAA   = 'CAA';
	/**
	 * SRV carries three numbers and a target rather than a bare value, so its
	 * whole RDATA — "priority weight port target" — travels in the value, the
	 * way CAA's does. Baking the numbers into the value rather than adding two
	 * more optional columns keeps every driver that already passes a value
	 * through working unchanged, and keeps comparison to one string.
	 */
	const TYPE_SRV   = 'SRV';

	/** The whole vocabulary. Anything else — NS, SOA — is refused. */
	const TYPES = array(self::TYPE_A, self::TYPE_AAAA, self::TYPE_CNAME,
		self::TYPE_MX, self::TYPE_TXT, self::TYPE_CAA, self::TYPE_SRV);

	/**
	 * Absent-record value meaning "whatever is published at this name".
	 *
	 * The usual case for a removal: the platform knows a foreign DKIM selector
	 * answers and that it must not, without knowing — or needing to know — the
	 * key it holds. Naming an exact value instead narrows the removal to that
	 * one value, which is what you want when a specific stale record has to go
	 * and anything else at the name is legitimate.
	 */
	const ANY_VALUE = '*';

	/** @var string One of TYPES. */
	public $type;
	/** @var string Fully-qualified, lowercase, no trailing dot. */
	public $name;
	/** @var string Canonical value (see canonicalValue()). */
	public $value;
	/** @var int|null Null means "provider default" and never produces a diff. */
	public $ttl;
	/** @var int|null MX only. */
	public $priority;
	/** @var string Why this record exists — rendered next to it, never compared. */
	public $note;
	/**
	 * @var bool True when changing this record redirects traffic that already
	 * flows (an MX, or an A for a host already resolving somewhere else). Set by
	 * the reconciler, not by the plan author.
	 */
	public $cutover = false;
	/** @var string Provider-side record id. Only ever set on live records. */
	public $provider_id = '';
	/**
	 * @var bool True when the plan requires this record NOT to exist. Set only
	 * by mustBeAbsent(); a record is present-by-default, because a plan that
	 * could delete by accident is worse than one that cannot delete at all.
	 */
	public $absent = false;

	public function __construct(string $type, string $name, string $value,
			?int $ttl = null, ?int $priority = null, string $note = '') {
		$type = strtoupper(trim($type));
		if (!in_array($type, self::TYPES, true)) {
			throw new DnsRecordException('Unsupported DNS record type "' . $type
				. '". The platform publishes only ' . implode(', ', self::TYPES)
				. ' — it never writes NS or SOA.');
		}
		$this->type     = $type;
		$this->name     = self::normalizeName($name);
		$this->value    = self::canonicalValue($type, $value);
		$this->ttl      = $ttl;
		$this->priority = ($type === self::TYPE_MX) ? $priority : null;
		$this->note     = $note;

		if ($this->name === '') {
			throw new DnsRecordException('A DNS record needs a name.');
		}
		if ($this->value === '') {
			throw new DnsRecordException('A ' . $type . ' record for ' . $this->name . ' has no value.');
		}
	}

	/**
	 * A record that must NOT exist.
	 *
	 * The type is part of the requirement, not decoration: a delegated DKIM
	 * selector is a CNAME and a held one is a TXT, and asking for both when only
	 * one is live would put a permanently-green row on the page. Whoever builds
	 * the plan is expected to know which one answers, because they had to look to
	 * know there was anything to remove.
	 *
	 * @param string $value ANY_VALUE (the default) removes whatever is published
	 *                      at the name; a concrete value removes only that one.
	 */
	public static function mustBeAbsent(string $type, string $name,
			string $value = self::ANY_VALUE, string $note = ''): DnsRecord {
		$record = new DnsRecord($type, $name, trim($value) !== '' ? $value : self::ANY_VALUE,
			null, null, $note);
		$record->absent = true;
		return $record;
	}

	/**
	 * Does this live record fall under an absent record's requirement?
	 *
	 * Deliberately not isSatisfiedBy() inverted: TTL and priority have no part in
	 * it. A record with the wrong TTL is still the record that must not be there.
	 */
	public function targets(DnsRecord $live): bool {
		if ($this->type !== $live->type || $this->name !== $live->name) {
			return false;
		}
		return ($this->value === self::ANY_VALUE) || ($this->value === $live->value);
	}

	/** Lowercase, trailing-dot-free, whitespace-free record name. */
	public static function normalizeName(string $name): string {
		return strtolower(rtrim(trim($name), '.'));
	}

	/**
	 * Reduce a provider's or a plan author's spelling of a value to the one form
	 * comparison uses.
	 *
	 * TXT is the interesting case: a value over 255 bytes is served as adjacent
	 * quoted strings, and providers hand it back in every possible shape —
	 * `"a" "b"`, `"a""b"`, or already joined. All of them mean the same record,
	 * so all of them canonicalize to the same string.
	 */
	public static function canonicalValue(string $type, string $value): string {
		$value = trim($value);
		switch (strtoupper($type)) {
			case self::TYPE_TXT:
				if (preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $value, $m) && !empty($m[1])) {
					$parts = array();
					foreach ($m[1] as $chunk) {
						$parts[] = stripslashes($chunk);
					}
					return implode('', $parts);
				}
				return $value;
			case self::TYPE_A:
			case self::TYPE_AAAA:
				return strtolower($value);
			case self::TYPE_CNAME:
			case self::TYPE_MX:
				return strtolower(rtrim($value, '.'));
			case self::TYPE_CAA:
				// "0 issue \"letsencrypt.org\"" — collapse whitespace so a
				// provider's re-spacing is not a difference.
				return preg_replace('/\s+/', ' ', $value);
			case self::TYPE_SRV:
				// "0 5 443 direct.example.com" — collapse whitespace, drop the
				// target's trailing dot and case, so a provider that re-spaces or
				// re-qualifies the target is not a permanent diff.
				$value = strtolower(preg_replace('/\s+/', ' ', $value));
				return preg_replace('/\.$/', '', $value);
		}
		return $value;
	}

	/**
	 * Does a live record satisfy this planned one?
	 *
	 * TTL participates only when the plan asked for one. Priority participates
	 * only for MX, and only when the plan asked for one.
	 */
	public function isSatisfiedBy(DnsRecord $live): bool {
		if ($this->type !== $live->type || $this->name !== $live->name) {
			return false;
		}
		if ($this->value !== $live->value) {
			return false;
		}
		if ($this->ttl !== null && $live->ttl !== null && (int)$this->ttl !== (int)$live->ttl) {
			return false;
		}
		if ($this->type === self::TYPE_MX && $this->priority !== null
				&& $live->priority !== null && (int)$this->priority !== (int)$live->priority) {
			return false;
		}
		return true;
	}

	/** Same slot in the zone: type + name. Two records with the same key are alternatives. */
	public function slotKey(): string {
		return $this->type . '|' . $this->name;
	}

	/**
	 * Stable identity for a specific record, used to key UI decisions.
	 *
	 * Presence is part of it. Publish and remove are opposite instructions, and a
	 * shared key would let a tick confirming one authorize the other.
	 */
	public function key(): string {
		return substr(sha1(($this->absent ? 'absent|' : '') . $this->type . '|'
			. $this->name . '|' . $this->value), 0, 16);
	}

	/** Human-readable one-liner: "MX example.com → 10 mail.example.com". */
	public function describe(): string {
		if ($this->absent) {
			return $this->type . ' ' . $this->name
				. ($this->value === self::ANY_VALUE ? ' — removed' : ' → ' . $this->value . ' — removed');
		}
		$value = $this->value;
		if ($this->type === self::TYPE_MX && $this->priority !== null) {
			$value = $this->priority . ' ' . $value;
		}
		return $this->type . ' ' . $this->name . ' → ' . $value;
	}

	/** Plain-array form for OAuth flow payloads and JSON. */
	public function toArray(): array {
		return array(
			'type'     => $this->type,
			'name'     => $this->name,
			'value'    => $this->value,
			'ttl'      => $this->ttl,
			'priority' => $this->priority,
			'note'     => $this->note,
			'absent'   => $this->absent,
		);
	}

	/** Rebuild from toArray(). */
	public static function fromArray(array $a): DnsRecord {
		$record = new DnsRecord(
			(string)($a['type'] ?? ''),
			(string)($a['name'] ?? ''),
			(string)($a['value'] ?? ''),
			isset($a['ttl']) && $a['ttl'] !== null ? (int)$a['ttl'] : null,
			isset($a['priority']) && $a['priority'] !== null ? (int)$a['priority'] : null,
			(string)($a['note'] ?? '')
		);
		$record->absent = !empty($a['absent']);
		return $record;
	}
}
