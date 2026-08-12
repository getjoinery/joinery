<?php
/**
 * DirectDecoyKeys - the key a sealed-tier receiver hands back for an address
 * that does not exist.
 *
 * At Private and Fortress the receiver accepts unconditionally so that
 * acceptance discloses nothing — but a key-bearing `accept` would reopen exactly
 * what that closed. Verifying an instance signature proves a sender is who it
 * claims, never that it is welcome, so any instance could preflight a guessed
 * address and learn from a real key coming back that the address exists. So the
 * receiver returns a key unconditionally too. The sender seals to it, the
 * message arrives, and nobody can ever open it — which costs nothing, because
 * mail to a nonexistent address was going nowhere in any case.
 *
 * Two properties make a decoy hold up:
 *
 *   - **It must be a valid curve point**, or a malformed-key error at the sender
 *     would identify it. So the derived value is used as an X25519 scalar and
 *     the published decoy is its base point multiple: a genuine public key,
 *     indistinguishable from a real one. The scalar is discarded the instant the
 *     point is computed and is never stored anywhere, so nothing on this box —
 *     or anywhere else — can open a message sealed to a decoy.
 *
 *   - **It must be deterministic**, since a key that changed between probes of
 *     the same address would itself be the tell. HMAC over a stable domain
 *     secret and the lowercased address gives that.
 *
 * The `accept` also carries a key generation, and a decoy reports generation 1 —
 * the value a freshly created, never-rotated vault carries. Most real vaults
 * never rotate, so generation 1 is the overwhelmingly common real answer and a
 * decoy blends into that cohort.
 *
 * **One residual distinguisher, on the record.** A real vault that HAS rotated
 * reports a higher generation, so an attacker who has already correctly guessed
 * such an address can tell it from a decoy; equivalently, probing one address
 * across a rotation would see a real key advance while a decoy stands still.
 * Both are the same weak, one-sided leak: they can only CONFIRM existence for an
 * address the attacker already guessed AND whose owner has rotated, and they
 * never DENY existence for anything. Closing it fully would require a decoy to
 * forge a plausible per-address rotation history, which is complex, fragile, and
 * buys little. The decoy therefore holds at generation 1.
 *
 * Decoys are a sealed-tier mechanism, not a uniform behaviour: at Standard the
 * contact gate refuses a stranger with `declined` before any key is offered, so
 * the only sender who ever receives a key there is one already in the
 * recipient's contacts — who knows the address exists. There is no oracle to
 * close at Standard, and a decoy path there would be dead code.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectSettings.php'));

class DirectDecoyKeys {

	/** The generation a decoy always reports. See the class note. */
	const DECOY_GENERATION = 1;

	/**
	 * Derive (minting if absent) and discard the domain secret, so a caller can
	 * confirm decoys are available BEFORE it branches on whether an address
	 * exists. Throws if a stored secret is present but unreadable. The receiver
	 * calls this once for every sealed-tier preflight and, on failure, refuses the
	 * whole tier uniformly — a real address and a nonexistent one fail identically
	 * rather than the nonexistent one alone erroring while reals still accept.
	 */
	public static function warm(): void {
		DirectSettings::decoySecret();
	}

	/**
	 * The decoy public key for $address, b64url-encoded exactly as a real
	 * `uev_public_key` is, so the two are the same shape on the wire.
	 */
	public static function publicKeyFor(string $address): string {
		$scalar = hash_hmac('sha256', strtolower(trim($address)), DirectSettings::decoySecret(), true);
		// Clamped the way X25519 clamps a scalar, so the point is on the curve
		// and in the right subgroup — a real key in every observable respect.
		$scalar[0]  = chr(ord($scalar[0]) & 248);
		$scalar[31] = chr((ord($scalar[31]) & 127) | 64);
		$public = sodium_crypto_scalarmult_base($scalar);
		sodium_memzero($scalar);
		return SealedBox::b64url($public);
	}
}
