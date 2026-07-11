<?php
require_once(__DIR__ . '/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));

/**
 * DriveHelper — shared tree + access logic for the member Drive.
 *
 * The drive_* action logic files stay thin by delegating here: entity loading,
 * owner/grant access checks, folder-depth and cycle validation, sibling-name
 * uniqueness, and the soft-delete cascade / selective-restore recipes that the
 * folder trash lifecycle needs.
 *
 * Access is owner-scoped in the core; sharing (viewer/editor grants and
 * ancestor-folder reach) layers on in DriveHelper::can_read/can_write via the
 * FileAccessGrant hooks.
 */
class DriveHelper {

	const ENTITY_FILE = 'file';
	const ENTITY_FOLDER = 'folder';

	public static function require_classes() {
		require_once(PathHelper::getIncludePath('data/folders_class.php'));
		require_once(PathHelper::getIncludePath('data/files_class.php'));
		require_once(PathHelper::getIncludePath('data/drive_usage_class.php'));
	}

	public static function max_depth() {
		$d = (int)Globalvars::get_instance()->get_setting('drive_max_folder_depth');
		return $d > 0 ? $d : 32;
	}

	// ------------------------------------------------------------------
	// Entity loading
	// ------------------------------------------------------------------

	/** Load a Folder by id, or null when missing / already hard-gone. */
	public static function load_folder($id) {
		self::require_classes();
		$id = (int)$id;
		if ($id <= 0) {
			return null;
		}
		$f = new Folder($id, true);
		return $f->key ? $f : null;
	}

	/**
	 * Load a File by id, or null when missing. Only Drive files
	 * (fil_source='drive') resolve — every Drive verb goes through here, so
	 * Drive can never list, trash, restore, share, or purge a file another
	 * subsystem owns (avatars, mail attachments, store images, ...).
	 */
	public static function load_file($id) {
		self::require_classes();
		$id = (int)$id;
		if ($id <= 0) {
			return null;
		}
		$f = new File($id, true);
		if (!$f->key || $f->get('fil_source') !== File::SOURCE_DRIVE) {
			return null;
		}
		return $f;
	}

	/** Load either entity type into a model, or null. */
	public static function load_entity($entity_type, $id) {
		if ($entity_type === self::ENTITY_FOLDER) {
			return self::load_folder($id);
		}
		if ($entity_type === self::ENTITY_FILE) {
			return self::load_file($id);
		}
		return null;
	}

	public static function owner_id_of($entity_type, $entity) {
		if ($entity_type === self::ENTITY_FOLDER) {
			return (int)$entity->get('fol_usr_user_id');
		}
		return (int)$entity->get('fil_usr_user_id');
	}

	// ------------------------------------------------------------------
	// Access — owner-scoped, extended by grants (sharing phase)
	// ------------------------------------------------------------------

	/** True when $user_id owns the given loaded entity. */
	public static function owns($entity_type, $entity, $user_id) {
		return self::owner_id_of($entity_type, $entity) === (int)$user_id;
	}

	/**
	 * May $user_id read (list / download) this entity? Owner always may. Shared
	 * viewers/editors and holders of a grant on an ancestor folder also may —
	 * FileAccessGrant supplies that reach once the sharing phase has shipped.
	 */
	public static function can_read($entity_type, $entity, $user_id, $permission = 0) {
		if (self::owns($entity_type, $entity, $user_id)) {
			return true;
		}
		return self::grant_reaches($entity_type, $entity, $user_id, array('viewer', 'editor'));
	}

	/**
	 * May $user_id write (rename / move / add a version) this entity? Owner always
	 * may; an editor grant on the entity or an ancestor folder also grants write.
	 * Delete and share stay owner-only and are checked directly by their actions.
	 */
	public static function can_write($entity_type, $entity, $user_id, $permission = 0) {
		if (self::owns($entity_type, $entity, $user_id)) {
			return true;
		}
		return self::grant_reaches($entity_type, $entity, $user_id, array('editor'));
	}

	/**
	 * Does $user_id hold a grant of one of $roles on this entity or any of its
	 * ancestor folders? The single grant-reach implementation — File::is_viewable
	 * and the drive_* actions all resolve sharing through here.
	 */
	public static function grant_reaches($entity_type, $entity, $user_id, array $roles) {
		require_once(PathHelper::getIncludePath('data/file_access_grants_class.php'));

		// Direct grant on this entity.
		$entity_id = ($entity_type === self::ENTITY_FOLDER)
			? (int)$entity->get('fol_folder_id')
			: (int)$entity->get('fil_file_id');
		$role = FileAccessGrant::role_for($entity_type, $entity_id, $user_id);
		if ($role !== null && in_array($role, $roles, true)) {
			return true;
		}

		// Grant on any ancestor folder.
		$start_folder = ($entity_type === self::ENTITY_FOLDER)
			? (int)$entity->get('fol_parent_folder_id')
			: (int)$entity->get('fil_fol_folder_id');
		if ($start_folder > 0) {
			// The entity's own folder counts as an ancestor for a file.
			$chain = array_merge(array($start_folder), self::ancestors($start_folder));
			foreach ($chain as $anc_id) {
				$role = FileAccessGrant::role_for(self::ENTITY_FOLDER, $anc_id, $user_id);
				if ($role !== null && in_array($role, $roles, true)) {
					return true;
				}
			}
		}
		return false;
	}

	// ------------------------------------------------------------------
	// JSON export for the UI / change clients
	// ------------------------------------------------------------------

	/** Serialize a folder row for a listing payload. */
	public static function folder_export($folder) {
		return array(
			'entity_type' => self::ENTITY_FOLDER,
			'id'          => (int)$folder->key,
			'name'        => $folder->get('fol_name'),
			'parent_id'   => $folder->get('fol_parent_folder_id') !== null ? (int)$folder->get('fol_parent_folder_id') : null,
			'owner_id'    => (int)$folder->get('fol_usr_user_id'),
			'create_time' => $folder->get('fol_create_time'),
			'deleted'     => $folder->get('fol_delete_time') !== null && $folder->get('fol_delete_time') !== '',
		);
	}

	/**
	 * Serialize a file row for a listing payload.
	 *
	 * @param File     $file
	 * @param int|null $size     precomputed blob size (avoids an extra load); null to look it up
	 * @param bool|null $starred precomputed star flag; null to look it up
	 */
	public static function file_export($file, $size = null, $starred = null) {
		self::require_classes();
		$file_id = (int)$file->key;

		if ($size === null) {
			$blob_id = (int)$file->get('fil_fbb_file_blob_id');
			$size = 0;
			if ($blob_id > 0) {
				$dblink = DbConnector::get_instance()->get_db_link();
				$q = $dblink->prepare("SELECT fbb_size_bytes FROM fbb_file_blobs WHERE fbb_file_blob_id = ?");
				$q->execute(array($blob_id));
				$size = (int)$q->fetchColumn();
			}
		}

		if ($starred === null) {
			require_once(PathHelper::getIncludePath('data/reactions_class.php'));
			$uid = SessionControl::get_instance()->get_user_id();
			$starred = Reaction::has_reacted($uid, 'file', $file_id);
		}

		$mime = $file->get('fil_type');
		$is_image = File::is_inline_safe_type($mime);

		$out = array(
			'entity_type'  => self::ENTITY_FILE,
			'id'           => $file_id,
			'name'         => $file->get('fil_title'),
			'size'         => (int)$size,
			'mime'         => $mime,
			'is_image'     => $is_image,
			'starred'      => (bool)$starred,
			'folder_id'    => $file->get('fil_fol_folder_id') !== null ? (int)$file->get('fil_fol_folder_id') : null,
			'owner_id'     => (int)$file->get('fil_usr_user_id'),
			'create_time'  => $file->get('fil_create_time'),
			'deleted'      => $file->get('fil_delete_time') !== null && $file->get('fil_delete_time') !== '',
			'download_url' => $file->mintSignedUrl('original', 3600),
		);
		if ($is_image) {
			$thumb = self::thumb_size_key();
			if ($thumb !== null) {
				$out['thumb_url'] = $file->mintSignedUrl($thumb, 3600);
			}
		}
		return $out;
	}

	/** The smallest configured image size variant key, for thumbnails (or null). */
	public static function thumb_size_key() {
		require_once(PathHelper::getIncludePath('includes/ImageSizeRegistry.php'));
		$sizes = ImageSizeRegistry::get_sizes();
		if (empty($sizes)) {
			return null;
		}
		// Pick the variant with the smallest configured width.
		$best = null;
		$best_w = PHP_INT_MAX;
		foreach ($sizes as $key => $cfg) {
			$w = isset($cfg['width']) ? (int)$cfg['width'] : PHP_INT_MAX;
			if ($w > 0 && $w < $best_w) {
				$best_w = $w;
				$best = $key;
			}
		}
		return $best;
	}

	/** @var array<int,array<int,bool>> per-request starred-set memo, by user id */
	private static $starred_memo = array();

	/** Starred file ids for a user, as a set keyed by id (one query per request). */
	public static function starred_file_ids($user_id) {
		$user_id = (int)$user_id;
		if (isset(self::$starred_memo[$user_id])) {
			return self::$starred_memo[$user_id];
		}
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			"SELECT rct_entity_id FROM rct_reactions
			  WHERE rct_usr_user_id = ? AND rct_entity_type = 'file' AND rct_delete_time IS NULL");
		$q->execute(array($user_id));
		$set = array();
		foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
			$set[(int)$id] = true;
		}
		self::$starred_memo[$user_id] = $set;
		return $set;
	}

	/**
	 * Every live folder a user owns, flattened with a display path, for move /
	 * destination pickers. One query; paths computed in PHP.
	 * Returns array of ['id'=>int, 'name'=>string, 'path'=>string] sorted by path.
	 */
	public static function all_folders_flat($user_id) {
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			"SELECT fol_folder_id, fol_parent_folder_id, fol_name
			   FROM fol_folders
			  WHERE fol_usr_user_id = ? AND fol_delete_time IS NULL");
		$q->execute(array((int)$user_id));
		$rows = $q->fetchAll(PDO::FETCH_ASSOC);

		$by_id = array();
		foreach ($rows as $r) {
			$by_id[(int)$r['fol_folder_id']] = array(
				'name'   => $r['fol_name'],
				'parent' => $r['fol_parent_folder_id'] !== null ? (int)$r['fol_parent_folder_id'] : 0,
			);
		}

		$path_of = function ($id) use (&$path_of, $by_id) {
			$parts = array();
			$cap = 64;
			$cur = (int)$id;
			while ($cur > 0 && isset($by_id[$cur]) && $cap-- > 0) {
				array_unshift($parts, $by_id[$cur]['name']);
				$cur = $by_id[$cur]['parent'];
			}
			return '/' . implode('/', $parts);
		};

		$out = array();
		foreach ($by_id as $id => $meta) {
			$out[] = array('id' => (int)$id, 'name' => $meta['name'], 'path' => $path_of($id));
		}
		usort($out, function ($a, $b) { return strcmp($a['path'], $b['path']); });
		return $out;
	}

	/** Blob sizes for a set of file ids, as id => bytes (one query). */
	public static function file_sizes(array $file_ids) {
		$ids = array_values(array_filter(array_map('intval', $file_ids)));
		if (empty($ids)) {
			return array();
		}
		$dblink = DbConnector::get_instance()->get_db_link();
		$in = implode(',', $ids);
		$q = $dblink->query(
			"SELECT f.fil_file_id, COALESCE(b.fbb_size_bytes, 0) AS size
			   FROM fil_files f
			   LEFT JOIN fbb_file_blobs b ON b.fbb_file_blob_id = f.fil_fbb_file_blob_id
			  WHERE f.fil_file_id IN ($in)");
		$map = array();
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$map[(int)$row['fil_file_id']] = (int)$row['size'];
		}
		return $map;
	}

	// ------------------------------------------------------------------
	// Quota serialization — one storage admission at a time per owner
	// ------------------------------------------------------------------

	const QUOTA_LOCK_CLASS = 42002; // pg advisory lock namespace for Drive quota

	/**
	 * Serialize quota admission for an owner (session-scoped Postgres advisory
	 * lock): upload_complete takes this, recomputes usage fresh, and only then
	 * ingests — so N uploads opened while under quota cannot all land past it.
	 * Always pair with quota_unlock() in a finally.
	 */
	public static function quota_lock($user_id) {
		$q = DbConnector::get_instance()->get_db_link()->prepare("SELECT pg_advisory_lock(?, ?)");
		$q->execute(array(self::QUOTA_LOCK_CLASS, (int)$user_id));
	}

	public static function quota_unlock($user_id) {
		$q = DbConnector::get_instance()->get_db_link()->prepare("SELECT pg_advisory_unlock(?, ?)");
		$q->execute(array(self::QUOTA_LOCK_CLASS, (int)$user_id));
	}

	// ------------------------------------------------------------------
	// Tree — depth, ancestry, cycles
	// ------------------------------------------------------------------

	/**
	 * Ancestor folder ids of $folder_id, immediate parent first up to the root.
	 * One recursive-CTE query, bounded by the depth cap (a malformed cycle
	 * can't loop forever).
	 */
	public static function ancestors($folder_id) {
		$folder_id = (int)$folder_id;
		if ($folder_id <= 0) {
			return array();
		}
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			"WITH RECURSIVE chain AS (
			    SELECT f.fol_parent_folder_id AS id, 1 AS lvl
			      FROM fol_folders f WHERE f.fol_folder_id = :start
			  UNION ALL
			    SELECT f.fol_parent_folder_id, c.lvl + 1
			      FROM fol_folders f
			      JOIN chain c ON f.fol_folder_id = c.id
			     WHERE c.id IS NOT NULL AND c.lvl < :cap
			 )
			 SELECT id FROM chain WHERE id IS NOT NULL ORDER BY lvl ASC");
		$q->bindValue(':start', $folder_id, PDO::PARAM_INT);
		$q->bindValue(':cap', self::max_depth() + 2, PDO::PARAM_INT);
		$q->execute();
		return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
	}

	/** Depth of a folder: 0 at the root's direct children's parent... i.e. a
	 *  root-level folder (null parent) has depth 1; each level adds one. */
	public static function depth($folder_id) {
		if ((int)$folder_id <= 0) {
			return 0; // the implicit drive root
		}
		return 1 + count(self::ancestors($folder_id));
	}

	/**
	 * Would moving $folder_id under $new_parent_id create a cycle? True when the
	 * new parent is the folder itself or one of its descendants.
	 */
	public static function would_create_cycle($folder_id, $new_parent_id) {
		$folder_id = (int)$folder_id;
		$new_parent_id = (int)$new_parent_id;
		if ($new_parent_id <= 0) {
			return false; // moving to root never cycles
		}
		if ($new_parent_id === $folder_id) {
			return true;
		}
		// If folder_id appears among the new parent's ancestors, the new parent is
		// a descendant of folder_id -> cycle.
		return in_array($folder_id, self::ancestors($new_parent_id), true);
	}

	/**
	 * Deepest level reachable below $folder_id, relative to that folder (0 when it
	 * has no live subfolders). One recursive-CTE query, depth-capped. Used to keep
	 * a moved subtree within the depth cap.
	 */
	public static function subtree_height($folder_id) {
		$folder_id = (int)$folder_id;
		if ($folder_id <= 0) {
			return 0;
		}
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			"WITH RECURSIVE sub AS (
			    SELECT f.fol_folder_id AS id, 1 AS lvl
			      FROM fol_folders f
			     WHERE f.fol_parent_folder_id = :start AND f.fol_delete_time IS NULL
			  UNION ALL
			    SELECT f.fol_folder_id, s.lvl + 1
			      FROM fol_folders f
			      JOIN sub s ON f.fol_parent_folder_id = s.id
			     WHERE f.fol_delete_time IS NULL AND s.lvl < :cap
			 )
			 SELECT COALESCE(MAX(lvl), 0) FROM sub");
		$q->bindValue(':start', $folder_id, PDO::PARAM_INT);
		$q->bindValue(':cap', self::max_depth() + 2, PDO::PARAM_INT);
		$q->execute();
		return (int)$q->fetchColumn();
	}

	/**
	 * Every live descendant folder id below $folder_id (the folder itself
	 * excluded), depth-capped. One recursive-CTE query — shared-folder search
	 * expands granted folders to their subtrees with this.
	 */
	public static function descendant_folder_ids($folder_id) {
		$folder_id = (int)$folder_id;
		if ($folder_id <= 0) {
			return array();
		}
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			"WITH RECURSIVE sub AS (
			    SELECT f.fol_folder_id AS id, 1 AS lvl
			      FROM fol_folders f
			     WHERE f.fol_parent_folder_id = :start AND f.fol_delete_time IS NULL
			  UNION ALL
			    SELECT f.fol_folder_id, s.lvl + 1
			      FROM fol_folders f
			      JOIN sub s ON f.fol_parent_folder_id = s.id
			     WHERE f.fol_delete_time IS NULL AND s.lvl < :cap
			 )
			 SELECT id FROM sub");
		$q->bindValue(':start', $folder_id, PDO::PARAM_INT);
		$q->bindValue(':cap', self::max_depth() + 2, PDO::PARAM_INT);
		$q->execute();
		return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
	}

	// ------------------------------------------------------------------
	// Sibling-name uniqueness (folders only; files may share names)
	// ------------------------------------------------------------------

	/**
	 * Is there a live sibling folder owned by $user_id under $parent_id already
	 * named $name (case-sensitive, excluding $exclude_id)? The partial unique
	 * index is the backstop; this returns a clean error instead of a DB throw.
	 */
	public static function folder_name_taken($user_id, $parent_id, $name, $exclude_id = 0) {
		self::require_classes();
		$dblink = DbConnector::get_instance()->get_db_link();
		$sql = "SELECT fol_folder_id FROM fol_folders
		         WHERE fol_usr_user_id = :uid
		           AND COALESCE(fol_parent_folder_id, 0) = :pid
		           AND fol_name = :name
		           AND fol_delete_time IS NULL
		           AND fol_folder_id <> :exclude
		         LIMIT 1";
		$q = $dblink->prepare($sql);
		$q->execute(array(
			':uid'     => (int)$user_id,
			':pid'     => (int)$parent_id,
			':name'    => $name,
			':exclude' => (int)$exclude_id,
		));
		return $q->fetchColumn() !== false;
	}

	// ------------------------------------------------------------------
	// Trash lifecycle — soft-delete cascade and selective restore
	// ------------------------------------------------------------------

	/**
	 * Soft-delete a folder and every descendant folder and file. All rows are
	 * stamped at (about) the same time so restore can select exactly this cascade.
	 */
	public static function soft_delete_folder_cascade($folder) {
		self::require_classes();
		$folder_id = (int)$folder->key;

		// Delete this folder FIRST so its delete_time is the earliest in the
		// cascade — selective restore keeps only descendants with a delete_time
		// at/after the folder's, so an independently-trashed child (deleted before
		// this cascade) is left in the trash.
		$folder->soft_delete();

		// Files directly in this folder.
		$files = new MultiFile(array('folder_id' => $folder_id, 'deleted' => false));
		$files->load();
		foreach ($files as $file) {
			$file->soft_delete();
		}

		// Descendant folders (depth-first).
		$subfolders = new MultiFolder(array('parent_id' => $folder_id, 'deleted' => false));
		$subfolders->load();
		foreach ($subfolders as $sub) {
			self::soft_delete_folder_cascade($sub);
		}
	}

	/**
	 * Impact summary for a "Delete forever" confirmation: how many files and
	 * folders will be destroyed and how many bytes reclaimed. For a file it is
	 * just that file; for a folder it is the whole subtree (live or trashed).
	 */
	public static function delete_impact($entity_type, $entity) {
		self::require_classes();
		if ($entity_type === self::ENTITY_FILE) {
			$size = 0;
			$blob_id = (int)$entity->get('fil_fbb_file_blob_id');
			if ($blob_id > 0) {
				$dblink = DbConnector::get_instance()->get_db_link();
				$q = $dblink->prepare("SELECT fbb_size_bytes FROM fbb_file_blobs WHERE fbb_file_blob_id = ?");
				$q->execute(array($blob_id));
				$size = (int)$q->fetchColumn();
			}
			return array('files' => 1, 'folders' => 0, 'bytes' => $size);
		}

		$impact = array('files' => 0, 'folders' => 1, 'bytes' => 0);
		$folder_id = (int)$entity->get('fol_folder_id');

		// Files directly under this folder (live or trashed).
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			"SELECT COUNT(*) AS c, COALESCE(SUM(b.fbb_size_bytes), 0) AS bytes
			   FROM fil_files f
			   LEFT JOIN fbb_file_blobs b ON b.fbb_file_blob_id = f.fil_fbb_file_blob_id
			  WHERE f.fil_fol_folder_id = ?");
		$q->execute(array($folder_id));
		$row = $q->fetch(PDO::FETCH_ASSOC);
		$impact['files'] += (int)$row['c'];
		$impact['bytes'] += (int)$row['bytes'];

		// Recurse into all subfolders (live or trashed).
		$qs = $dblink->prepare("SELECT fol_folder_id FROM fol_folders WHERE fol_parent_folder_id = ?");
		$qs->execute(array($folder_id));
		foreach ($qs->fetchAll(PDO::FETCH_COLUMN) as $sid) {
			$sub = self::load_folder($sid);
			if ($sub) {
				$sub_impact = self::delete_impact(self::ENTITY_FOLDER, $sub);
				$impact['files']   += $sub_impact['files'];
				$impact['folders'] += $sub_impact['folders'];
				$impact['bytes']   += $sub_impact['bytes'];
			}
		}
		return $impact;
	}

	/**
	 * Permanently delete a file or a whole folder subtree. For a folder this
	 * recursively permanent-deletes every descendant file (releasing blob
	 * refcounts) and subfolder before the folder itself — the deliberate
	 * "user asked for it gone" path, distinct from the FK 'null' orphan rule that
	 * covers an out-of-band folder permanent-delete.
	 */
	public static function permanent_delete_tree($entity_type, $entity) {
		self::require_classes();
		if ($entity_type === self::ENTITY_FILE) {
			$entity->permanent_delete();
			return;
		}

		$folder_id = (int)$entity->get('fol_folder_id');
		$dblink = DbConnector::get_instance()->get_db_link();

		// Files directly under this folder (live or trashed).
		$qf = $dblink->prepare("SELECT fil_file_id FROM fil_files WHERE fil_fol_folder_id = ?");
		$qf->execute(array($folder_id));
		foreach ($qf->fetchAll(PDO::FETCH_COLUMN) as $fid) {
			$file = self::load_file($fid);
			if ($file) {
				$file->permanent_delete();
			}
		}

		// Subfolders (recurse), then this folder.
		$qs = $dblink->prepare("SELECT fol_folder_id FROM fol_folders WHERE fol_parent_folder_id = ?");
		$qs->execute(array($folder_id));
		foreach ($qs->fetchAll(PDO::FETCH_COLUMN) as $sid) {
			$sub = self::load_folder($sid);
			if ($sub) {
				self::permanent_delete_tree(self::ENTITY_FOLDER, $sub);
			}
		}

		$entity->permanent_delete();
	}

	/**
	 * Restore a soft-deleted folder and only the descendants that were deleted
	 * with it (delete_time >= the folder's own delete_time), per the
	 * deletion-system selective-restore recipe. Children deleted independently
	 * earlier stay in the trash.
	 */
	public static function restore_folder_cascade($folder) {
		self::require_classes();
		$folder_id = (int)$folder->get('fol_folder_id');
		$cutoff = $folder->get('fol_delete_time'); // capture BEFORE undelete

		$folder->undelete();

		if ($cutoff === null || $cutoff === '') {
			return; // wasn't actually trashed
		}

		$dblink = DbConnector::get_instance()->get_db_link();

		// Restore files in this folder deleted at/after the cutoff. Go through the
		// model's undelete() so the blob's visibility placement is flipped back.
		$qf = $dblink->prepare(
			"SELECT fil_file_id FROM fil_files
			  WHERE fil_fol_folder_id = ? AND fil_delete_time >= ?");
		$qf->execute(array($folder_id, $cutoff));
		$file_ids = $qf->fetchAll(PDO::FETCH_COLUMN);
		foreach ($file_ids as $fid) {
			$file = self::load_file($fid); // pkey load includes soft-deleted rows
			if ($file) {
				$file->undelete();
			}
		}

		// Recurse into subfolders trashed at/after the cutoff.
		$qs = $dblink->prepare(
			"SELECT fol_folder_id FROM fol_folders
			  WHERE fol_parent_folder_id = ? AND fol_delete_time >= ?");
		$qs->execute(array($folder_id, $cutoff));
		$sub_ids = $qs->fetchAll(PDO::FETCH_COLUMN);
		foreach ($sub_ids as $sid) {
			$sub = self::load_folder($sid);
			if ($sub) {
				self::restore_folder_cascade($sub);
			}
		}
	}
}
?>
