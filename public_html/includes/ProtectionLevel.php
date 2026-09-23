<?php
/**
 * ProtectionLevel — the platform's one protection-level vocabulary.
 *
 * Every service that stores member content draws its levels from this ladder,
 * and a rung means the same thing wherever it appears:
 *
 *   standard  the server manages this for you (plaintext)
 *   private   encrypted at rest, server custody, opened only inside the
 *             owner's unlock window
 *   fortress  end-to-end, client custody — plaintext never exists on the
 *             server (Drive and the password vault)
 *
 * A service shows only the rungs it implements: mail, chat and messenger offer
 * standard / private; Drive offers standard / private / fortress; the password
 * vault is fortress-only with no picker at all.
 *
 * Hardening a service applies at one of its own doors — where content comes
 * in, where it goes out, or who may act in the member's name — is not a rung.
 * It is an ADD-ON: a separate flag the service stores beside the level and
 * offers on Private (and Fortress, where the door exists). An add-on never
 * changes custody, and no level requires one. The picker renders them
 * (ProtectionLevelPicker `addons`); the rules are in
 * specs/protection_levels_platform.md § Add-ons.
 *
 * This class owns the ORDER and the spelling, nothing else. Card copy — the
 * promise wording a member reads when choosing — belongs with the shared level
 * picker component so it cannot drift between services.
 *
 * @version 1.2.0 - fromInput(): a requested level that is not a rung is refused, never downgraded
 * @changelog 1.1.0 - three rungs; the old middle rung is Private plus add-ons
 */
class ProtectionLevel {

	const STANDARD = 'standard';
	const PRIVATE_ = 'private';   // PRIVATE is a PHP reserved word
	const FORTRESS = 'fortress';

	/**
	 * The ladder, weakest first. Position IS the rank — comparisons everywhere
	 * read from this one array, so adding a rung never means hunting for
	 * hardcoded numbers.
	 */
	const ORDER = array(self::STANDARD, self::PRIVATE_, self::FORTRESS);

	/** The subset Drive offers (docs/drive.md). */
	const DRIVE_LEVELS = array(self::STANDARD, self::PRIVATE_, self::FORTRESS);

	/** Is this a level this platform knows? */
	public static function isValid($level): bool {
		return is_string($level) && in_array($level, self::ORDER, true);
	}

	/**
	 * Coerce whatever arrived — a client string, a NULL column on a row that
	 * predates the level, a stray case — into a real rung. Anything
	 * unrecognized reads as standard, which is the honest answer: an
	 * unrecognized value has never had protection applied to it.
	 */
	public static function normalize($value, string $default = self::STANDARD): string {
		$value = strtolower(trim((string)$value));
		return self::isValid($value) ? $value : $default;
	}

	/**
	 * Read a level a caller ASKED for. Unlike normalize(), an unrecognized
	 * value is refused (null), never read as standard: someone who asked for a
	 * protected level and mistyped it must hear so, not get an unprotected
	 * resource. Absent or empty input yields $default.
	 */
	public static function fromInput($value, string $default = self::STANDARD): ?string {
		if ($value === null || (is_string($value) && trim($value) === '')) {
			return $default;
		}
		$value = strtolower(trim((string)$value));
		return self::isValid($value) ? $value : null;
	}

	/** Position on the ladder; an unknown level ranks as standard. */
	public static function rank($level): int {
		$idx = array_search(self::normalize($level), self::ORDER, true);
		return $idx === false ? 0 : (int)$idx;
	}

	/**
	 * Is $level at least as protective as $floor? The containment rule every
	 * tree-shaped consumer needs: a parent's level is the floor for everything
	 * inside it.
	 */
	public static function isAtLeast($level, $floor): bool {
		return self::rank($level) >= self::rank($floor);
	}

	/** The stronger of two levels. */
	public static function max($a, $b): string {
		return self::rank($a) >= self::rank($b) ? self::normalize($a) : self::normalize($b);
	}

	/** One-word name for UI chips and refusal messages. */
	public static function label($level): string {
		switch (self::normalize($level)) {
			case self::PRIVATE_: return 'Private';
			case self::FORTRESS: return 'Fortress';
			default:             return 'Standard';
		}
	}
}
?>
