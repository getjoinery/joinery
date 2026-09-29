<?php
/**
 * DriveFolderLevel — a top-level Drive folder tree as a ProtectionLevelChange
 * scope. Standard <-> Private only: the server holds the key wrapping for both,
 * so it can convert. Fortress is the browser's (doctrine D2), so a Fortress
 * folder is not a rung this scope moves between and a Fortress file is never
 * converted here.
 *
 * The folder's level is the promise; each file's own level is the truth about
 * its bytes. flip() changes the whole subtree's promise at once, and the files
 * catch up afterwards through ProtectionLevelChange::convergeBatch().
 *
 * Going Private ends the tree's public links and member grants (Private
 * carries neither). Reporting them and asking first is drive_level_change's
 * product rule; flip() always revokes whatever is there by then, in the same
 * transaction that flips the level, after every prerequisite has passed — a
 * link made between the prompt and the flip does not survive it. Each member
 * who lost a grant is told through the change feed, as a share sync tells them.
 *
 * @version 1.1 - flip always revokes when going Private and tells each member who lost a
 *   grant; hasWork()/drain() finish a change the dialog did not, as the vault's deferred work
 * @version 1.0
 */
class DriveFolderLevel implements ProtectionLevelScope {

	/** Files a converge pass looks at most (bytes: ProtectionLevelChange::BYTE_BUDGET). */
	const BATCH_ROWS = 500;

	private $folder;
	private $owner_id;
	/** @var array<int,File|null> files loaded this pass, by id */
	private $files = array();

	public function __construct(Folder $folder, int $owner_id) {
		require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
		VaultUnlock::loadConsumerBootstraps();   // DriveSealed loads only through the loader
		$this->folder = $folder;
		$this->owner_id = $owner_id;
	}

	public function label(): string {
		return 'this folder';
	}

	public function levels(): array {
		return array(ProtectionLevel::STANDARD, ProtectionLevel::PRIVATE_);
	}

	public function currentLevel(): string {
		return DriveHelper::folder_level($this->folder);
	}

	/**
	 * A protected tree is a top-level tree: raising a folder inside a Standard
	 * parent would leave a protected subtree under an unprotected one, which the
	 * create and move rules refuse. And Private seals to the owner's vault.
	 */
	public function blockers(string $target, int $actor_id): ?string {
		if ($target === ProtectionLevel::STANDARD) {
			return null;
		}
		if ((int)$this->folder->get('fol_parent_folder_id') > 0) {
			return 'Only a top-level folder can be made ' . ProtectionLevel::label($target)
				. '. Move it to the Drive root first.';
		}
		if (DriveSealed::vaultFor($this->owner_id) === null) {
			return 'Set up your vault before making a folder ' . ProtectionLevel::label($target) . '.';
		}
		return null;
	}

	/** The whole subtree at once: a child never sits below its parent. */
	public function flip(string $target): void {
		$ids = DriveSealed::subtreeFolderIds((int)$this->folder->key);
		$db = DbConnector::get_instance()->get_db_link();
		$own = !$db->inTransaction();
		$lost = array();
		if ($own) $db->beginTransaction();
		try {
			if ($target === ProtectionLevel::PRIVATE_) {
				$lost = self::revokeSharing($ids);
			}
			$db->prepare("UPDATE fol_folders SET fol_protection_level = ?
			              WHERE fol_folder_id IN (" . DriveHelper::int_in_list($ids) . ")")
				->execute(array($target));
			if ($own) $db->commit();
		} catch (Throwable $e) {
			if ($own && $db->inTransaction()) $db->rollBack();
			throw $e;
		}
		$this->folder->set('fol_protection_level', $target);
		FileChange::record(FileChange::KIND_LEVEL_CHANGED, DriveHelper::ENTITY_FOLDER, (int)$this->folder->key,
			$this->owner_id, $this->owner_id);
		// A member who lost a grant matches nothing in the feed once the grant row
		// is gone: one row each, addressed to them (drive_share_sync does the same).
		foreach ($lost as $g) {
			FileChange::record(FileChange::KIND_GRANT_CHANGED, $g['entity_type'], $g['entity_id'],
				$this->owner_id, $this->owner_id, $g['user_id']);
		}
	}

	public function pending(int $limit): array {
		$ids = DriveSealed::subtreeFolderIds((int)$this->folder->key);
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			"SELECT fil_file_id FROM fil_files
			 WHERE fil_fol_folder_id IN (" . DriveHelper::int_in_list($ids) . ")
			   AND fil_protection_level <> ? AND fil_protection_level <> '" . ProtectionLevel::FORTRESS . "'
			   AND fil_delete_time IS NULL AND " . self::readySql('fil_level_attempt_time') . "
			 ORDER BY fil_file_id ASC LIMIT " . intval($limit));
		$stmt->execute(array($this->currentLevel()));
		return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
	}

	public function browserSealed($item): bool {
		$file = $this->file((int)$item);
		return $file !== null && $file->is_encrypted();
	}

	/**
	 * Raising needs only the owner's public key; lowering decrypts, so it needs
	 * the window. A failure is stamped, so passes take the files behind it (and
	 * the deferred work stops waking for it) for RETRY_SECONDS.
	 */
	public function convertOne($item): ?int {
		$file = $this->file((int)$item);
		if ($file === null) {
			return null;
		}
		$db = DbConnector::get_instance()->get_db_link();
		try {
			$bytes = ($this->currentLevel() === ProtectionLevel::PRIVATE_)
				? DriveSealed::sealExistingFile($file)
				: DriveSealed::unsealExistingFile($file);
		} catch (VaultLockedException $e) {
			throw $e;
		} catch (Throwable $e) {
			$db->prepare("UPDATE fil_files SET fil_level_attempt_time = NOW() AT TIME ZONE 'UTC' WHERE fil_file_id = ?")
				->execute(array((int)$item));
			throw $e;
		}
		$db->prepare('UPDATE fil_files SET fil_level_attempt_time = NULL WHERE fil_file_id = ? AND fil_level_attempt_time IS NOT NULL')
			->execute(array((int)$item));
		return $bytes;
	}

	public function remaining(): int {
		return DriveSealed::transitionBacklog((int)$this->folder->key, $this->currentLevel())['files'];
	}

	public function budget(): array {
		return array('rows' => self::BATCH_ROWS);
	}

	// ------------------------------------------------------ the deferred driver

	/**
	 * SQL: $owner's folders holding a file not yet at the folder's level that a
	 * pass may take now (Fortress files are never the server's; a file that
	 * failed recently waits out RETRY_SECONDS). A
	 * change whose batch loop stopped (the dialog closed) is finished by the
	 * vault's deferred work in the owner's window; the dialog is only the fast
	 * path. A folder's level is uniform down its subtree, so each file is judged
	 * against its own folder.
	 */
	private static function unconvergedSql(): string {
		return "FROM fil_files f
			JOIN fol_folders d ON d.fol_folder_id = f.fil_fol_folder_id
			WHERE d.fol_usr_user_id = ? AND d.fol_delete_time IS NULL AND f.fil_delete_time IS NULL
			  AND COALESCE(d.fol_protection_level, 'standard') IN ('" . ProtectionLevel::STANDARD . "','" . ProtectionLevel::PRIVATE_ . "')
			  AND f.fil_protection_level <> COALESCE(d.fol_protection_level, 'standard')
			  AND f.fil_protection_level <> '" . ProtectionLevel::FORTRESS . "'
			  AND " . self::readySql('f.fil_level_attempt_time');
	}

	/** How long a pass passes by a file whose conversion failed before trying it again. */
	const RETRY_SECONDS = 3600;

	/** SQL: a file a pass may take now — it has not failed within RETRY_SECONDS. */
	private static function readySql(string $column): string {
		return '(' . $column . ' IS NULL OR ' . $column . " < NOW() AT TIME ZONE 'UTC' - INTERVAL '"
			. self::RETRY_SECONDS . " seconds')";
	}

	/** The deferred-work predicate. */
	public static function hasWork(int $owner_id): bool {
		if ($owner_id <= 0) return false;
		$stmt = DbConnector::get_instance()->get_db_link()->prepare('SELECT 1 ' . self::unconvergedSql() . ' LIMIT 1');
		$stmt->execute(array($owner_id));
		return (bool)$stmt->fetchColumn();
	}

	/** Converge $owner's unfinished folders until $deadline; returns the files converted. */
	public static function drain(int $owner_id, float $deadline): int {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT DISTINCT d.fol_folder_id ' . self::unconvergedSql() . ' ORDER BY d.fol_folder_id LIMIT 20');
		$stmt->execute(array($owner_id));
		$done = 0;
		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $fid) {
			if (microtime(true) >= $deadline) break;
			$folder = DriveHelper::load_folder((int)$fid);
			if (!$folder) continue;
			$scope = new self($folder, $owner_id);
			// Pass after pass on this scope until it is done, stuck, or out of time.
			do {
				$pass = ProtectionLevelChange::convergeBatch($scope, null, $deadline);
				$done += $pass['converted'];
			} while (!$pass['locked'] && $pass['converted'] > 0 && $pass['remaining'] > 0 && microtime(true) < $deadline);
			if ($pass['locked']) break;
		}
		return $done;
	}

	/** Public links and member grants anywhere in the subtree — what going Private will end. */
	public static function sharingBlockers(int $folder_id): array {
		$in = DriveHelper::int_in_list(DriveSealed::subtreeFolderIds($folder_id));
		$db = DbConnector::get_instance()->get_db_link();
		$out = array();
		$links = (int)$db->query(
			"SELECT COUNT(*) FROM fsl_file_share_links
			 WHERE fsl_revoked_time IS NULL
			   AND ((fsl_entity_type = 'folder' AND fsl_entity_id IN ($in))
			     OR (fsl_entity_type = 'file' AND fsl_entity_id IN
			         (SELECT fil_file_id FROM fil_files WHERE fil_fol_folder_id IN ($in))))")->fetchColumn();
		if ($links > 0) {
			$out[] = array('kind' => 'links', 'count' => $links,
				'label' => $links . ' public link' . ($links === 1 ? '' : 's') . ' will stop working');
		}
		$grants = (int)$db->query(
			"SELECT COUNT(*) FROM fga_file_access_grants
			 WHERE (fga_entity_type = 'folder' AND fga_entity_id IN ($in))
			    OR (fga_entity_type = 'file' AND fga_entity_id IN
			        (SELECT fil_file_id FROM fil_files WHERE fil_fol_folder_id IN ($in)))")->fetchColumn();
		if ($grants > 0) {
			$out[] = array('kind' => 'grants', 'count' => $grants,
				'label' => $grants . ' member' . ($grants === 1 ? '' : 's') . ' will lose access');
		}
		return $out;
	}

	/**
	 * Revoke what Private cannot carry, across the subtree. Returns the grants
	 * removed, [{entity_type, entity_id, user_id}], so their holders can be told.
	 */
	private static function revokeSharing(array $folder_ids): array {
		$in = DriveHelper::int_in_list($folder_ids);
		$db = DbConnector::get_instance()->get_db_link();
		$grants_sql = "FROM fga_file_access_grants
			 WHERE (fga_entity_type = 'folder' AND fga_entity_id IN ($in))
			    OR (fga_entity_type = 'file' AND fga_entity_id IN
			        (SELECT fil_file_id FROM fil_files WHERE fil_fol_folder_id IN ($in)))";
		$lost = array();
		foreach ($db->query("SELECT fga_entity_type, fga_entity_id, fga_usr_user_id $grants_sql")->fetchAll(PDO::FETCH_ASSOC) as $g) {
			$lost[] = array('entity_type' => (string)$g['fga_entity_type'], 'entity_id' => (int)$g['fga_entity_id'],
				'user_id' => (int)$g['fga_usr_user_id']);
		}
		$db->exec(
			"UPDATE fsl_file_share_links SET fsl_revoked_time = now()
			 WHERE fsl_revoked_time IS NULL
			   AND ((fsl_entity_type = 'folder' AND fsl_entity_id IN ($in))
			     OR (fsl_entity_type = 'file' AND fsl_entity_id IN
			         (SELECT fil_file_id FROM fil_files WHERE fil_fol_folder_id IN ($in))))");
		$db->exec("DELETE $grants_sql");
		return $lost;
	}

	private function file(int $id): ?File {
		if (!array_key_exists($id, $this->files)) {
			$this->files[$id] = DriveHelper::load_file($id);
		}
		return $this->files[$id];
	}
}
?>
