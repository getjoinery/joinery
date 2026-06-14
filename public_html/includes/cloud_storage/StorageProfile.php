<?php
/**
 * StorageProfile — the per-consumer seam for the unified cloud offload engine.
 *
 * The offload orchestration (CloudOffloadEngine) and the admin lifecycle
 * (CloudStorageLifecycle) are table-agnostic. A profile declares only what
 * differs between consumers: which table holds the offload descriptor, what an
 * object-per-row looks like on disk and in the bucket, and whether its bytes
 * are public or private. Everything that follows from visibility — which
 * bucket, how bytes are read back, and the privacy guarantee — is owned by the
 * storage layer, not the profile.
 *
 * Implementations must have a no-argument constructor: the registry
 * instantiates each declared class with `new $class()`.
 *
 * @version 1.0
 */

interface StorageProfile {

	// --- identity ----------------------------------------------------------

	/** Table holding the offload descriptor + counters (e.g. 'fil_files'). */
	public function table(): string;

	/** Primary-key column of that table (e.g. 'fil_file_id'). */
	public function pkeyColumn(): string;

	/** Column carrying the storage driver flag: 'local' | 'cloud' (NULL = local). */
	public function driverColumn(): string;

	/** Column counting consecutive offload failures (capped). */
	public function failedCountColumn(): string;

	/** Column stamped with the last offload attempt time. */
	public function lastAttemptColumn(): string;

	// --- visibility — the only public/private signal a consumer gives ------

	/** 'public' | 'private'. The storage layer maps this to a store. */
	public function visibility(): string;

	// --- batch selection ---------------------------------------------------

	/**
	 * Extra AND-conditions identifying an offload-eligible 'local' row, as a
	 * raw SQL fragment (no leading AND). '' = no extra gate. Must reference no
	 * bound parameters — it is concatenated into the batch SELECT.
	 */
	public function eligibilityWhere(): string;

	// --- per-row -----------------------------------------------------------

	/** True if the row still exists (not gone / not hard-deleted out from under us). */
	public function rowExists(int $id): bool;

	/**
	 * Re-check, under the per-row lock, that the row is still an offload
	 * candidate (driver still local AND any per-consumer gate still holds).
	 */
	public function isEligibleRow(int $id): bool;

	/**
	 * FORWARD enumeration: the objects to push for this row, each
	 * ['local_path', 'remote_key', 'content_type'], filtered to what is present
	 * on disk. Returns null when the row's required bytes are missing on disk
	 * (the engine records a failure).
	 */
	public function itemsForRow(int $id): ?array;

	/**
	 * REVERSE enumeration: the objects to pull back for this row, each
	 * ['remote_key', 'local_path', 'content_type'], computed from the row's key
	 * scheme + placement WITHOUT requiring local bytes (on pull-back none exist
	 * yet). local_path is the final on-disk destination.
	 */
	public function reverseItemsForRow(int $id): array;

	// --- task identity (per consumer, for scheduler tracking) --------------

	public function forwardTaskClass(): string;
	public function reverseTaskClass(): string;
}
