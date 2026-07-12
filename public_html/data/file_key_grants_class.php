<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

/**
 * FileKeyGrant — a per-user wrapping of one encrypted file's content key
 * (fkg_file_key_grants).
 *
 * Where a FileAccessGrant grants *access* to a Drive file, a FileKeyGrant grants
 * *readability* of an encrypted one: it holds the file key (FK), sealed to that
 * user's `drive`-scope vault public key (X25519 sealed box, produced in the
 * owner's browser). The server stores an OPAQUE blob — it never sees the FK or
 * any plaintext (docs/drive_encryption.md, the client-custody vault). One row per
 * (file, user); the owner always holds a row. Sharing an encrypted file is one
 * new row (the owner's browser unwraps the FK and re-wraps it to the recipient's
 * public key — no content re-encryption); revocation deletes the row.
 *
 * Not a REST/AI resource — all access flows through the drive_* key-grant actions
 * (drive_key_grants_sync / drive_key_grants). Revocation is a hard delete (no
 * delete_time column), the FileAccessGrant precedent.
 *
 * @version 1.0.0
 */
class FileKeyGrant extends SystemBase {
	public static $prefix = 'fkg';
	public static $tablename = 'fkg_file_key_grants';
	public static $pkey_column = 'fkg_file_key_grant_id';

	public static $permanent_delete_actions = array();

	// The grantee reference cascades on user delete. The file reference is
	// four-segment (fkg_fil_file_id) so deletion-rule auto-detection cascades the
	// grant when the file is permanently deleted.
	protected static $foreign_key_actions = array(
		'fkg_usr_user_id'  => array('action' => 'cascade'),
		'fkg_fil_file_id'  => array('action' => 'cascade'),
	);

	public static $field_specifications = array(
		'fkg_file_key_grant_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true),
		'fkg_fil_file_id'       => array('type' => 'int8', 'is_nullable' => false, 'required' => true, 'index' => true),
		'fkg_usr_user_id'       => array('type' => 'int4', 'is_nullable' => false, 'required' => true, 'unique_with' => array('fkg_fil_file_id')),
		// The file key sealed to the grantee's drive-scope vault public key
		// (VaultCrypto.sealToPublicKey → ephPub || IV || ciphertext, base64).
		// Opaque here; only the grantee's browser can open it.
		'fkg_wrapped_file_key'  => array('type' => 'text', 'is_nullable' => false, 'required' => true),
		'fkg_create_time'       => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
	);

	function __construct($key, $and_load = FALSE) {
		parent::__construct($key, $and_load);
	}

	/** The grantee's wrapped file key for a file, or null when they hold none. */
	public static function wrapped_key_for($file_id, $user_id) {
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			"SELECT fkg_wrapped_file_key FROM fkg_file_key_grants
			  WHERE fkg_fil_file_id = ? AND fkg_usr_user_id = ? LIMIT 1");
		$q->execute(array((int)$file_id, (int)$user_id));
		$blob = $q->fetchColumn();
		return $blob === false ? null : (string)$blob;
	}

	/**
	 * Wrapped file keys for a set of files, for one user, as file_id => blob (one
	 * query). Drive listings use this to hand a grantee every encrypted file's
	 * key in a single round trip.
	 */
	public static function wrapped_keys_for_user(array $file_ids, $user_id) {
		$ids = array_values(array_filter(array_map('intval', $file_ids)));
		if (empty($ids)) {
			return array();
		}
		$dblink = DbConnector::get_instance()->get_db_link();
		$in = implode(',', $ids);
		$q = $dblink->prepare(
			"SELECT fkg_fil_file_id, fkg_wrapped_file_key FROM fkg_file_key_grants
			  WHERE fkg_usr_user_id = ? AND fkg_fil_file_id IN ($in)");
		$q->execute(array((int)$user_id));
		$map = array();
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$map[(int)$row['fkg_fil_file_id']] = (string)$row['fkg_wrapped_file_key'];
		}
		return $map;
	}

	/** User ids that hold a key grant for a file. */
	public static function user_ids_for_file($file_id) {
		$grants = new MultiFileKeyGrant(array('file_id' => (int)$file_id));
		$grants->load();
		$ids = array();
		foreach ($grants as $g) {
			$ids[] = (int)$g->get('fkg_usr_user_id');
		}
		return $ids;
	}

	/**
	 * Insert or update one wrapping (file, user) → wrapped_file_key. The blob is
	 * opaque (the owner's browser produced it). Returns the saved grant.
	 */
	public static function put($file_id, $user_id, $wrapped_file_key) {
		$file_id = (int)$file_id;
		$user_id = (int)$user_id;
		$existing = new MultiFileKeyGrant(array('file_id' => $file_id, 'user_id' => $user_id));
		$existing->load();
		$grant = null;
		foreach ($existing as $g) { $grant = $g; break; }
		if (!$grant) {
			$grant = new FileKeyGrant(NULL);
			$grant->set('fkg_fil_file_id', $file_id);
			$grant->set('fkg_usr_user_id', $user_id);
		}
		$grant->set('fkg_wrapped_file_key', (string)$wrapped_file_key);
		$grant->save();
		return $grant;
	}

	/**
	 * Reconcile the key-grant set for a file to $wrapped (user_id =>
	 * wrapped_file_key): insert/update the listed users, hard-delete users not in
	 * the set EXCEPT never the owner (an owner always keeps their own key).
	 * Returns the list of newly-added user ids.
	 */
	public static function sync_for_file($file_id, array $wrapped, $owner_id) {
		$file_id = (int)$file_id;
		$owner_id = (int)$owner_id;

		$existing = new MultiFileKeyGrant(array('file_id' => $file_id));
		$existing->load();
		$current = array();
		foreach ($existing as $g) {
			$current[(int)$g->get('fkg_usr_user_id')] = $g;
		}

		$desired = array();
		foreach ($wrapped as $uid => $blob) {
			$uid = (int)$uid;
			if ($uid > 0 && $blob !== null && $blob !== '') {
				$desired[$uid] = (string)$blob;
			}
		}

		$newly = array();

		// Remove grants no longer wanted (never the owner's own key).
		foreach ($current as $uid => $grant) {
			if ($uid === $owner_id) { continue; }
			if (!isset($desired[$uid])) {
				$grant->permanent_delete();
			}
		}

		// Insert new; update changed blobs.
		foreach ($desired as $uid => $blob) {
			if (!isset($current[$uid])) {
				$grant = new FileKeyGrant(NULL);
				$grant->set('fkg_fil_file_id', $file_id);
				$grant->set('fkg_usr_user_id', $uid);
				$grant->set('fkg_wrapped_file_key', $blob);
				$grant->save();
				$newly[] = $uid;
			} elseif ($current[$uid]->get('fkg_wrapped_file_key') !== $blob) {
				$current[$uid]->set('fkg_wrapped_file_key', $blob);
				$current[$uid]->save();
			}
		}

		return $newly;
	}
}

class MultiFileKeyGrant extends SystemMultiBase {
	protected static $model_class = 'FileKeyGrant';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['file_id'])) {
			$filters['fkg_fil_file_id'] = array((int)$this->options['file_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['user_id'])) {
			$filters['fkg_usr_user_id'] = array((int)$this->options['user_id'], PDO::PARAM_INT);
		}

		return $this->_get_resultsv2('fkg_file_key_grants', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
