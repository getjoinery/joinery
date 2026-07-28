<?php
/**
 * UploadPurposeRegistry — what a chunked upload is FOR.
 *
 * The platform has one resumable, chunked upload transport
 * (DriveUploadTransport, PUT /api/v1/drive_upload/{token}), and it is the only way
 * to get a file larger than one web request onto the server. It knows nothing
 * about Drive — it appends bytes against a FileUpload row — but the logic at
 * either end of it did, so nothing else could use it.
 *
 * A purpose is the policy that surrounds those bytes: who may open an upload, what
 * kind of File comes out, and what should happen once it exists. Core owns the
 * transport and the assembly; the consumer owns the policy, because only it knows
 * what its own rules are.
 *
 * This is the same shape as File::registerDecryptHook(), keyed the same way — on
 * the origin tag a file carries — so a subsystem that already stamps fil_source has
 * one obvious name to register under.
 *
 * Registering a purpose:
 *
 *   UploadPurposeRegistry::register('mail_import_archive', array(
 *       'source'       => File::SOURCE_MAIL_IMPORT_ARCHIVE,
 *       'label'        => 'mail archive',
 *       'max_bytes'    => 0,
 *       'authorize'    => function (int $user_id, array $input): ?string {
 *           return $ok ? null : 'Why not.';
 *       },
 *       'restrictions' => array('fil_private' => true),
 *       'on_complete'  => function (File $file, $upload, int $user_id): void { … },
 *   ));
 *
 * DRIVE IS NOT REGISTERED HERE, deliberately. Its upload does new versions,
 * client-side encryption, per-reader key grants and encrypted thumbnails — none of
 * it shared, none of it optional. It keeps its own path and this registry serves
 * the simple case, which is what every other purpose is.
 *
 * See specs/chunked_upload_purposes.md.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('data/files_class.php'));

class UploadPurposeRegistry {

	/** The purpose name Drive uses. Never registered — it has its own path. */
	const PURPOSE_DRIVE = 'drive';

	/** @var array<string,array> purpose name => spec */
	private static $purposes = array();

	/**
	 * Declare a purpose. Called from a plugin bootstrap or a core include; the
	 * last registration for a name wins, so a deployment can override one.
	 */
	public static function register(string $purpose, array $spec): void {
		$purpose = trim($purpose);
		if ($purpose === '' || $purpose === self::PURPOSE_DRIVE) {
			// Drive's uploads do not come through here, so accepting the name would
			// only create a registration that never fires.
			throw new InvalidArgumentException('Invalid upload purpose name: ' . $purpose);
		}
		if (empty($spec['source'])) {
			throw new InvalidArgumentException('Upload purpose ' . $purpose . ' must declare a fil_source.');
		}
		self::$purposes[$purpose] = $spec;
	}

	/** True when this name is Drive's, which is handled outside this registry. */
	public static function isDrive(?string $purpose): bool {
		$purpose = trim((string)$purpose);
		return $purpose === '' || $purpose === self::PURPOSE_DRIVE;
	}

	/** The spec for a purpose, or null when nothing registered it. */
	public static function get(string $purpose): ?array {
		self::loadConsumers();
		return self::$purposes[trim($purpose)] ?? null;
	}

	/** Every registered purpose name — for diagnostics and the API's error text. */
	public static function names(): array {
		self::loadConsumers();
		return array_keys(self::$purposes);
	}

	/**
	 * May this user open an upload for this purpose? Returns null when allowed, or
	 * the reason to show them.
	 *
	 * A purpose with no authorize hook is refused rather than allowed: an upload
	 * endpoint that accepts anything from anyone is not a sensible default, and
	 * forgetting the hook should fail loudly at the first attempt.
	 */
	public static function authorize(string $purpose, int $user_id, array $input): ?string {
		$spec = self::get($purpose);
		if ($spec === null) {
			return 'Unknown upload purpose.';
		}
		if (empty($spec['authorize']) || !is_callable($spec['authorize'])) {
			return 'That upload purpose is not accepting uploads.';
		}
		$size = intval($input['size_bytes'] ?? 0);
		$cap  = intval($spec['max_bytes'] ?? 0);
		if ($cap > 0 && $size > $cap) {
			return 'That file is larger than this upload allows.';
		}
		return ($spec['authorize'])($user_id, $input);
	}

	/**
	 * Turn staged bytes into the File this purpose wants, run its completion hook,
	 * and hand the File back.
	 *
	 * The hook runs AFTER the File exists and is best-effort: a consumer's
	 * bookkeeping failing must not lose a file whose bytes are already stored and
	 * whose upload the caller believes finished.
	 */
	public static function finalize(string $purpose, string $staged_path, string $name,
			string $mime, int $user_id, $upload): ?File {
		$spec = self::get($purpose);
		if ($spec === null) {
			return null;
		}

		$restrictions = (array)($spec['restrictions'] ?? array());
		$restrictions['fil_source'] = $spec['source'];

		$file = File::createFromUpload($staged_path, $name, $mime, $user_id, $restrictions);
		if (!$file || !$file->key) {
			return null;
		}

		if (!empty($spec['on_complete']) && is_callable($spec['on_complete'])) {
			try {
				($spec['on_complete'])($file, $upload, $user_id);
			} catch (Throwable $e) {
				error_log('UploadPurposeRegistry: on_complete for ' . $purpose
					. ' failed for file ' . $file->key . ' — ' . $e->getMessage());
			}
		}

		return $file;
	}

	/** Human name for the thing being uploaded, for messages. */
	public static function label(string $purpose): string {
		$spec = self::get($purpose);
		return (string)($spec['label'] ?? 'file');
	}

	/**
	 * Give every consumer a chance to register before the first lookup.
	 *
	 * Purposes are declared by the subsystems that own them, which are not
	 * necessarily loaded when an upload endpoint runs — the API dispatches straight
	 * to one logic file and includes nothing else. Active plugins are booted here so
	 * a purpose is available without every caller knowing who provides it.
	 */
	private static $loaded = false;

	private static function loadConsumers(): void {
		if (self::$loaded) {
			return;
		}
		self::$loaded = true; // set first: a consumer that throws must not retry forever
		try {
			// The same plugin bootstraps the Sealed Vault loads for its own consumer
			// hooks. One loader, one list, one place a plugin declares itself —
			// rather than a second mechanism that could drift out of step with it.
			require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
			VaultUnlock::loadConsumerBootstraps();
		} catch (Throwable $e) {
			error_log('UploadPurposeRegistry: could not load purpose consumers — ' . $e->getMessage());
		}
	}
}
?>
