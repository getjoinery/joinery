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
 * @version 1.2 - targetColumn() and remoteKeyColumn(): an offloaded row records the file store target and the
 *                full key its object went to, and every reader follows them (specs/storage_targets.md WP6);
 *                forward items carry a relative 'name', composed into a key under the store's folder by
 *                the engine; reverse items carry the recorded 'remote_key' and the 'name' beside it
 * @version 1.1 - lastErrorColumn(): why a row did not move, so a record with no bytes is told apart from a failed push
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

	/** Column holding why the last attempt on a row did not move it (NULL once it does). */
	public function lastErrorColumn(): string;

	/** Column recording the file store target (bkt_backup_target_id) an offloaded row's object is in. */
	public function targetColumn(): string;

	/**
	 * Column recording the full object key of an offloaded row's primary
	 * object (the first item itemsForRow() names), as written. Any other
	 * object of the row is named from it.
	 */
	public function remoteKeyColumn(): string;

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
	 * ['local_path', 'name', 'content_type'], filtered to what is present on
	 * disk, the primary object first. 'name' is relative; the engine stores it
	 * under the file store's folder. Returns null when the row's required
	 * bytes are missing on disk (the engine records a failure).
	 */
	public function itemsForRow(int $id): ?array;

	/**
	 * REVERSE enumeration: every object of an offloaded row, each
	 * ['remote_key', 'name', 'local_path', 'content_type'], from the key the
	 * row recorded and its placement, WITHOUT requiring local bytes (on
	 * pull-back none exist yet). remote_key is the full key in the row's own
	 * store; name is the relative name a move stores it under elsewhere;
	 * local_path is the final on-disk destination. The primary object first.
	 */
	public function reverseItemsForRow(int $id): array;
}
