<?php
/**
 * DirectKindHandler - the entire surface a payload has to implement to ride
 * Joinery Direct. Two pure functions, and nothing else.
 *
 * The hard-won properties of this channel — exactly two gate answers, request-
 * level refusals kept in a separate indistinguishable bucket, unconditional
 * accept with a decoy key at the sealed tiers, no lock-state oracle, never a
 * bounce — must hold for every kind, because a receiving endpoint is only as
 * oracle-free as its leakiest kind. So they are not conventions a handler is
 * trusted to follow. They are structure a handler cannot break: the framework
 * owns every wire answer, and a handler never sees the wire at all.
 *
 *   Framework, identical for every kind: signature verification; freshness and
 *   replay; manifest size bounds; per-instance and per-peer rate limits; spool
 *   byte caps; kind dispatch; every wire answer including the key and the
 *   sealed-tier decoy; sealed-byte hash verification; spool-while-locked and
 *   unlock scheduling.
 *
 *   Kind handler: gate() and ingest().
 *
 * With that shape a kind cannot produce a third wire answer, a lock-state
 * distinguisher, or a bounce, and reviewing a new kind means reviewing two pure
 * functions.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectEnvelope.php'));

interface DirectKindHandler {

	/**
	 * Does this recipient accept this kind from this sender? Nothing else.
	 *
	 * It never sees vault lock state, never composes a wire response, and at
	 * Private and Fortress is NOT CALLED AT RECEIVE — the framework accepts
	 * unconditionally there and defers the gate to the next unlock. A decline
	 * becomes the wire's `declined` at Standard and a silent local filing
	 * decision at the sealed tiers; the handler cannot tell which, and that is
	 * the point.
	 *
	 * A handler whose kind declares `"gate": "contacts"` never implements this —
	 * the framework runs the canned contact gate itself.
	 *
	 * @return bool true = accept, false = decline
	 */
	public function gate(DirectEnvelope $envelope): bool;

	/**
	 * Store the delivered payload in the kind's own model.
	 *
	 * Runs only after the framework has verified every sealed-byte hash. On the
	 * live path it is called only on accept, so $gate_accepted is always true
	 * there. On the deferred path it is called at unlock for EVERY spooled
	 * delivery, carrying the deferred gate's outcome — because the sender was
	 * already answered `accept`, a deferred decline is a local disposition, not
	 * a drop: mail files a declined message exactly where SMTP would have put
	 * it, never losing it and never signalling the sender.
	 *
	 * The verified-sender fact and transport tag arrive with the envelope, so a
	 * kind can drive its own UI the way mail's verified-direct mark does.
	 *
	 * @param DirectPart[] $parts
	 */
	public function ingest(DirectEnvelope $envelope, array $parts, bool $gate_accepted): void;
}
