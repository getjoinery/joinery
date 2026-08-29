<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
$settings = Globalvars::get_instance();
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));

class FileException extends SystemBaseException {}

/**
 * What a streaming decrypt hook hands back (File::registerStreamingDecryptHook).
 *
 * Sealed content that can be opened a span at a time implements this, and gets
 * honest Range support in return: the server reads and decrypts only the chunks
 * covering what was asked for.
 */
interface FileStreamingDecryptor {
	/**
	 * Acquire whatever reading needs — typically the in-window key. Called
	 * BEFORE any header is written, so a closed vault becomes a clean 423
	 * instead of a truncated 200.
	 *
	 * @throws VaultLockedException when the owner's unlock window is closed
	 */
	public function prepare(string $path): void;

	/** The plaintext length of the container at $path. */
	public function plainSize(string $path): int;

	/**
	 * Decrypt [$offset, $offset+$length) of the plaintext, passing each piece to
	 * $sink as it is produced. A null $length means "to the end".
	 *
	 * @return int bytes emitted
	 */
	public function stream(string $path, callable $sink, int $offset = 0, ?int $length = null): int;
}

/**
 * File — uploaded file records: storage (local/cloud), visibility, resizing,
 * serving gates, and signed URLs (docs/file_signed_urls.md).
 *
 * @version 1.10.2
 * @changelog 1.10.0 - SOURCE_MESSENGER_ATTACHMENT: photos and files sent in a
 *   conversation, gated on that conversation rather than owned privately.
 */
class File extends SystemBase {	public static $prefix = 'fil';
	public static $tablename = 'fil_files';
	public static $pkey_column = 'fil_file_id';

	// AI auto-discovery (read)
	public static $ai_readable        = true;
	public static $ai_description     = 'Files the user has uploaded.';
	public static $ai_excluded_fields = [];
	public static $ai_untrusted_fields = ['fil_title', 'fil_description', 'fil_name'];

	protected static $foreign_key_actions = [
		'fil_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED],
		// A permanently deleted folder orphans its files to the drive root rather
		// than destroying them. Normal folder deletion goes through the trash logic
		// (soft-delete cascade), not raw permanent_delete.
		'fil_fol_folder_id' => ['action' => 'null'],
		'fil_fbb_file_blob_id' => ['action' => 'prevent', 'message' => 'files still reference this blob'],
		'fil_grp_group_id' => ['action' => 'prevent', 'message' => 'files still use this group for access control - reassign them first']
	];

	// Retention: Drive trash. Declared here rather than on Folder because files
	// must be purged first (that is what releases blob refcounts) and the same
	// pass then takes the folders — one rule, one owner. 0 means never purge.
	public static $retention_policy = array(
		'label'          => 'Drive trash',
		'purge_method'   => 'purgeExpiredTrash',
		'window_setting' => 'drive_trash_retention_days',
	);

	// Origin tags for fil_source: a short, self-describing key saying where a file
	// came from, stamped by whatever code created it. NULL means "unspecified /
	// legacy." Subsystems that create files stamp one of these; a new creation site
	// adds its own constant here AND a row in source_catalog() below.
	const SOURCE_USER_UPLOAD      = 'user_upload';       // deliberate admin/user file upload
	const SOURCE_ENTITY_PHOTO     = 'entity_photo';      // avatar / event / location / gallery photo
	const SOURCE_EMAIL_ATTACHMENT = 'email_attachment';  // inbound-email attachment
	const SOURCE_AI_CHAT_UPLOAD   = 'ai_chat_upload';    // file uploaded into a joinery_ai chat
	const SOURCE_DRIVE            = 'drive';             // member Drive item — the whole Drive surface (listings, trash, purge, quota) scopes to this tag
	const SOURCE_MAILBOX_SEARCH_INDEX = 'mailbox_search_index'; // sealed FTS5 blob (MailboxIndex) — read server-side only, never streamed via serve_from_path
	const SOURCE_MAIL_IMPORT_ARCHIVE  = 'mail_import_archive';  // mbox/zip/tar uploaded to be imported into a mailbox — held for the life of the run, not a Drive item
	const SOURCE_MESSENGER_ATTACHMENT = 'messenger_attachment'; // photo or file sent in a messenger conversation

	/** The catalog key standing in for "no origin tag" (legacy rows). */
	const SOURCE_UNCLASSIFIED = '_none';

	/**
	 * What each origin tag IS — one declaration, read by every surface that lists
	 * files. Keyed by the tag; SOURCE_UNCLASSIFIED covers NULL/legacy rows.
	 *
	 *   label        Human name. Listing filters and pickers show this.
	 *   internal     A file the system made for its own use. Browse surfaces must
	 *                not list it at all.
	 *   default_view Included when a listing opens with no filter chosen.
	 *
	 * INTERNAL IS NOT A PERMISSION. It means "don't list this", never "this is
	 * secret" — is_viewable() alone decides who may read any file's bytes, and
	 * nothing here widens or narrows that. A caller reaching for this to answer an
	 * access question is asking the wrong method.
	 *
	 * Classification belongs to the SOURCE, not the row: whether a file should be
	 * listed is a property of what created it, not of the file itself. Every sealed
	 * search index is internal — there is no user-visible one — so the tag already
	 * stamped at creation carries the answer, with no per-row column for a writer to
	 * get wrong and no backfill for rows that already exist.
	 *
	 * @return array<string,array{label:string,internal:bool,default_view:bool}>
	 */
	public static function source_catalog() {
		return array(
			// Legacy rows predate the origin tags. They are the site's actual
			// history, so they stay visible and stay in the default view —
			// excluding them would empty the listing on any deployment older
			// than the tags themselves.
			self::SOURCE_UNCLASSIFIED       => array('label' => 'Unclassified',         'internal' => false, 'default_view' => true),
			self::SOURCE_USER_UPLOAD        => array('label' => 'Uploads',              'internal' => false, 'default_view' => true),
			self::SOURCE_ENTITY_PHOTO       => array('label' => 'Photos',               'internal' => false, 'default_view' => true),
			self::SOURCE_EMAIL_ATTACHMENT   => array('label' => 'Mail attachments',     'internal' => false, 'default_view' => false),
			self::SOURCE_AI_CHAT_UPLOAD     => array('label' => 'AI chat uploads',      'internal' => false, 'default_view' => false),
			self::SOURCE_MESSENGER_ATTACHMENT => array('label' => 'Message attachments', 'internal' => false, 'default_view' => false),
			self::SOURCE_DRIVE              => array('label' => 'Drive',                'internal' => false, 'default_view' => false),
			// Not internal on purpose: someone uploaded this mbox deliberately.
			// Either the import run cleans it up or it is sitting there consuming
			// space, and whoever pays for the space has to be able to see it.
			// Hiding it would turn a storage leak into an invisible one.
			self::SOURCE_MAIL_IMPORT_ARCHIVE => array('label' => 'Mail import archives', 'internal' => false, 'default_view' => false),
			// Machine-owned: a sealed FTS5 blob read server-side and never streamed.
			self::SOURCE_MAILBOX_SEARCH_INDEX => array('label' => 'Search index',        'internal' => true,  'default_view' => false),
		);
	}

	/**
	 * Tags whose files no browse surface should list. Feed to MultiFile's
	 * 'sources_not', which preserves NULL-source rows.
	 *
	 * @return string[]
	 */
	public static function internal_sources() {
		$out = array();
		foreach (self::source_catalog() as $key => $spec) {
			if (!empty($spec['internal']) && $key !== self::SOURCE_UNCLASSIFIED) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * Tags a listing shows when nothing has been picked. SOURCE_UNCLASSIFIED may be
	 * in here, and it means NULL — a caller filtering on these has to handle that
	 * (see MultiFile's 'sources', which maps it to an IS NULL branch).
	 *
	 * @return string[]
	 */
	public static function default_view_sources() {
		$out = array();
		foreach (self::source_catalog() as $key => $spec) {
			if (!empty($spec['default_view'])) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * Human name for an origin tag. An unregistered tag gets its own key back, so a
	 * source someone forgot to declare reads as itself rather than as nothing.
	 *
	 * @param string|null $source NULL/'' means the unclassified bucket
	 * @return string
	 */
	public static function source_label($source) {
		$key = ($source === null || $source === '') ? self::SOURCE_UNCLASSIFIED : (string)$source;
		$catalog = self::source_catalog();
		return isset($catalog[$key]) ? $catalog[$key]['label'] : $key;
	}

	/**
	 * Is this tag one a browse surface may list? An unregistered tag answers TRUE:
	 * a subsystem that forgets to declare itself surfaces in an admin listing where
	 * someone notices and classifies it, rather than having its files silently
	 * vanish.
	 *
	 * @param string|null $source
	 * @return bool
	 */
	public static function source_is_listable($source) {
		$key = ($source === null || $source === '') ? self::SOURCE_UNCLASSIFIED : (string)$source;
		$catalog = self::source_catalog();
		return isset($catalog[$key]) ? empty($catalog[$key]['internal']) : true;
	}

	// MIME types safe to render inline in the browser. Only raster image
	// formats that cannot carry executable script belong here. SVG is
	// deliberately excluded: it is XML and can embed <script>, so an inline
	// SVG served from our origin is stored XSS. Everything not on this list is
	// served as a download (Content-Disposition: attachment). See is_inline_safe_type().
	const INLINE_SAFE_TYPES = array(
		'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif',
	);

		/**
	 * Field specifications define database column properties and validation rules
	 * 
	 * Database schema properties (used by update_database):
	 *   'type' => 'varchar(255)' | 'int4' | 'int8' | 'text' | 'timestamp' | 'bool' | etc.
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'serial' => true/false - Auto-incrementing field
	 * 
	 * Validation and behavior properties (used by SystemBase):
	 *   'required' => true/false - Field must have non-empty value on save
	 *   'default' => mixed - Default value for new records (applied on INSERT only)
	 *   'zero_on_create' => true/false - Set to 0 when creating if NULL (INSERT only)
	 * 
	 * Note: Timestamp fields are auto-detected based on type for smart_get() and export_as_array()
	 */
	public static $field_specifications = array(
	    'fil_file_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'fil_name' => array('type'=>'varchar(255)'),
	    'fil_title' => array('type'=>'varchar(255)'),
	    'fil_description' => array('type'=>'text'),
	    'fil_type' => array('type'=>'varchar(128)'),
	    'fil_usr_user_id' => array('type'=>'int4'),
	    'fil_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'fil_delete_time' => array('type'=>'timestamp(6)'),
	    'fil_min_permission' => array('type'=>'int2'),
	    'fil_grp_group_id' => array('type'=>'int4'),
	    'fil_access_provider' => array('type'=>'varchar(32)', 'is_nullable'=>true),
	    'fil_access_ref' => array('type'=>'int4', 'is_nullable'=>true),
	    'fil_tier_min_level' => array('type'=>'int4', 'is_nullable'=>true),
	    'fil_private' => array('type'=>'bool', 'is_nullable'=>false, 'default'=>'false'),
	    // Physical bytes live in a FileBlob (fbb_file_blobs), referenced here.
	    // Nullable at the schema layer, but always set by the single ingestion
	    // path (File::createFromUpload → FileBlob::createFromPath): a File without
	    // a blob is never created. The storage driver + offload counters that used
	    // to sit on fil_files now live on the blob and are shared by every file
	    // that references it.
	    'fil_fbb_file_blob_id' => array('type'=>'int8', 'is_nullable'=>true, 'index'=>true),
	    'fil_source' => array('type'=>'varchar(64)', 'is_nullable'=>true),
	    // Drive folder placement. NULL = the drive root (implicit, no root row).
	    // A file only carries this when it lives in a user's Drive; every other
	    // creation site leaves it NULL.
	    'fil_fol_folder_id' => array('type'=>'int8', 'is_nullable'=>true, 'index'=>true),
	    // How this file is protected — the destination folder's level at the
	    // time it was stored (docs/drive.md). One of:
	    //   standard  plaintext bytes; every content feature applies.
	    //   private   server custody: the stored bytes are a SealedFileContainer
	    //             sealed under a per-file key, itself wrapped to the owner's
	    //             vault public key in fil_sealed_key below. The server opens
	    //             them only inside the owner's unlock window. Names, sizes
	    //             and types stay plaintext (see fil_plain_size_bytes).
	    //   fortress  client custody (docs/drive_encryption.md): ciphertext the
	    //             server never interprets — no thumbnails/previews/search/
	    //             AI/office. fil_name/fil_title hold an opaque identifier;
	    //             the real name, MIME type and thumbnail live in the
	    //             FK-encrypted metadata blob below.
	    'fil_protection_level' => array('type'=>'varchar(16)', 'is_nullable'=>false, 'default'=>'standard'),
	    // Layer 0 sealed-columns wrapping (docs/sealed_vault.md), used at the
	    // private level only. $sealed_fields is deliberately empty: no DB column
	    // here holds ciphertext — the per-file key exists to seal the BLOB and
	    // its thumbnail variant, the same shape mail uses where a message DEK
	    // seals related Files. fil_key_generation is 0 on an unsealed row and
	    // matches the vault generation the key is wrapped to on a sealed one, so
	    // the rotation sweep can select exactly the old generation.
	    'fil_content_sealed' => array('type'=>'bool', 'is_nullable'=>false, 'default'=>'false'),
	    'fil_sealed_key' => array('type'=>'text', 'is_nullable'=>true),
	    'fil_sealed_owner_user_id' => array('type'=>'int8', 'is_nullable'=>true),
	    'fil_key_generation' => array('type'=>'int4', 'is_nullable'=>false, 'default'=>0),
	    // Plaintext byte count for a sealed file, recorded at seal time. The blob
	    // carries the CIPHERTEXT size (what storage and quota are charged for —
	    // the ima_size_bytes precedent); this is what the member is shown and
	    // what the read path answers Content-Length and Range against.
	    'fil_plain_size_bytes' => array('type'=>'int8', 'is_nullable'=>true),
	    // Per-file metadata (name, mime, size, chunk size, content id, thumb flag)
	    // JSON-encoded then encrypted under the file key, produced in the browser.
	    // Opaque here.
	    'fil_encrypted_metadata' => array('type'=>'text', 'is_nullable'=>true),
	    // The content's own modification time on the client that uploaded it, as
	    // distinct from fil_create_time (when the server first saw it). Sync
	    // clients set it so a file copied back down keeps its original timestamp
	    // and so a local mtime change is comparable to the server's. Plaintext
	    // files only: an encrypted file's true mtime lives inside its encrypted
	    // metadata blob, because a plaintext timestamp on ciphertext would leak
	    // when the file was last worked on.
	    'fil_content_modified_time' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
	);

	// Sibling-name uniqueness inside a Drive folder, among live rows — the same
	// rule fol_folders has, and for the same reason: two items a user cannot
	// tell apart cannot both live at one path.
	//
	// Scoped to fil_source = 'drive' because fil_files is platform-wide. An
	// avatar, a store image and a mail attachment all sit at folder NULL, and
	// duplicate titles among them are ordinary — a rule written for Drive must
	// not reach into subsystems that never asked for it.
	//
	// NULL folder is the drive root, so it coalesces to 0 the way the folder
	// index does; without that, root files would be exempt from the very rule
	// this exists to enforce.
	//
	// What it cannot catch, honestly: a fortress file's fil_title is an opaque
	// enc-… identifier and its real name lives inside encrypted metadata the
	// server cannot read. Two client-encrypted files can therefore share a real
	// name and differ here. That is the price of the server not being able to
	// see the name, and it is the client's job to notice.
	public static $index_specifications = array(
		array(
			'columns' => array('fil_usr_user_id', 'COALESCE(fil_fol_folder_id, 0)', 'fil_title'),
			'unique'  => true,
			'where'   => "fil_delete_time IS NULL AND fil_source = 'drive'",
		),
	);

public static function get_by_name($name, $search_deleted = false) {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = "SELECT fil_file_id FROM fil_files WHERE fil_name = :fil_name";
		if (!$search_deleted) {
			$sql .= " AND fil_delete_time IS NULL";
		}
		$sql .= " ORDER BY fil_file_id DESC LIMIT 1";

		try{
			$q = $dblink->prepare($sql);
			$q->bindValue(':fil_name', $name, PDO::PARAM_STR);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}

		$r = $q->fetch();
		if (!$r) {
			return FALSE;
		}

		return new File($r->fil_file_id, TRUE);
	}
	
	/**
	 * Mint a File from bytes staged at a path — the one byte-ingestion path every
	 * caller routes through ($_FILES uploads, generated/received content). The
	 * physical bytes become (or dedup onto) a FileBlob; this File is the logical
	 * record pointing at it.
	 *
	 * A collision-free name is minted from $display_name and used both as the
	 * URL identity (fil_name) and — for a freshly-stored blob — the blob's
	 * physical stored name, so the two are equal for a fresh upload and diverge
	 * only under dedup. The blob detects the honest MIME type from the bytes;
	 * that becomes fil_type. No image variants are generated — a caller that
	 * wants them calls resize() itself.
	 *
	 * @param string $src_path     bytes to ingest (consumed: moved into place or
	 *                             discarded on a dedup hit)
	 * @param string $display_name original/display name, e.g. "invoice.pdf" (kept as fil_title)
	 * @param string $content_type MIME hint (fallback; the blob's magic-byte detection wins)
	 * @param int    $owner_id     fil_usr_user_id — the honest owner
	 * @param array  $restrictions extra columns to set at creation, e.g. ['fil_private' => true]
	 * @return File the saved, reloaded File
	 * @throws FileException when the bytes cannot be placed
	 */
	public static function createFromUpload($src_path, $display_name, $content_type, $owner_id, array $restrictions = array()) {
		require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));

		if (!is_file($src_path)) {
			throw new FileException('createFromUpload: source not found: ' . $src_path);
		}

		// Desired visibility class from the restriction columns, before any bytes
		// are placed (the blob must land in the matching store).
		$probe = new File(NULL);
		$probe->set('fil_usr_user_id', $owner_id);
		foreach ($restrictions as $col => $val) {
			$probe->set($col, $val);
		}
		$is_private = !$probe->is_public();

		// Mint the URL-and-physical name, unique among active files and stored
		// blob names, then stage the bytes under it so a fresh blob adopts it.
		$name = self::_mint_unique_name($display_name);
		$stage_dir = sys_get_temp_dir() . '/fil_stage_' . bin2hex(random_bytes(4));
		@mkdir($stage_dir, 0777, true);
		$staged = $stage_dir . '/' . $name;
		if (!@rename($src_path, $staged)) {
			if (!@copy($src_path, $staged)) {
				@rmdir($stage_dir);
				throw new FileException('createFromUpload: unable to stage bytes at ' . $staged);
			}
			@unlink($src_path);
		}

		try {
			$blob = FileBlob::createFromPath($staged, $content_type, $is_private);
		} finally {
			if (is_file($staged)) {
				@unlink($staged);
			}
			@rmdir($stage_dir);
		}

		// The blob arrives already counting one reference, on the promise that
		// the row below will hold it. Every way out from here has to keep or
		// return that reference: the insert can fail for an ordinary reason --
		// two uploads racing for one name, where the partial unique index
		// refuses the loser -- and a caller that reads it as a polite name
		// clash would otherwise leave bytes nothing points at, which are never
		// reclaimed, keep counting against the owner's quota, and cannot be
		// reached again by anyone.
		try {
			$file = new File(NULL);
			$file->set('fil_name', $name);
			$file->set('fil_title', substr((string)$display_name, 0, 255));
			$file->set('fil_type', $blob->get('fbb_mime_type'));
			$file->set('fil_usr_user_id', $owner_id);
			$file->set('fil_fbb_file_blob_id', $blob->key);
			foreach ($restrictions as $col => $val) {
				$file->set($col, $val);
			}
			$file->save();
			$file->load();
		} catch (Exception $e) {
			FileBlob::release($blob->key);
			throw $e;
		}
		if (!$file->key) {
			// A save that reported nothing rather than throwing still leaves no
			// row to hold the reference. Give it back, and hand the caller the
			// same keyless File it has always got here.
			FileBlob::release($blob->key);
		}
		return $file;
	}

	/**
	 * Mint a File from in-memory bytes — a generated, received, or fetched blob
	 * that never passed through a $_FILES upload (inbound email attachments are a
	 * consumer). Writes the bytes to a temp file and routes through
	 * createFromUpload(), so it shares the one blob-backed ingestion path.
	 *
	 * @param string $bytes        the file content
	 * @param string $filename     original/display name, e.g. "invoice.pdf"
	 * @param string $content_type MIME hint (fallback; magic-byte detection wins)
	 * @param int    $owner_id     fil_usr_user_id — the honest owner
	 * @param array  $restrictions extra columns to set at creation, e.g. ['fil_private' => true]
	 * @return File the saved, reloaded File
	 * @throws FileException when the bytes cannot be written to disk
	 */
	public static function createFromBytes($bytes, $filename, $content_type, $owner_id, array $restrictions = array()) {
		$tmp = tempnam(sys_get_temp_dir(), 'fil_bytes_');
		if ($tmp === false || file_put_contents($tmp, $bytes) === false) {
			if ($tmp !== false) { @unlink($tmp); }
			throw new FileException('createFromBytes: unable to write bytes to temp file');
		}
		@chmod($tmp, 0666);
		try {
			return self::createFromUpload($tmp, ($filename !== null && $filename !== '') ? $filename : 'attachment', $content_type, $owner_id, $restrictions);
		} finally {
			if (is_file($tmp)) {
				@unlink($tmp);
			}
		}
	}

	/**
	 * A collision-free file name (URL identity): a random token inserted before
	 * the extension, sanitized, and checked unique among active fil_name rows and
	 * stored blob names. Used for both fil_name and — via staging — a fresh
	 * blob's fbb_stored_name.
	 */
	/**
	 * Create a logical File that references an already-stored blob, without
	 * ingesting any bytes — the dedup short-circuit (Drive upload_init matches a
	 * file's sha256 to an existing blob). The blob is retained (+1 refcount) and
	 * the new file gets its own unique fil_name while sharing the physical bytes.
	 * Returns the saved File, or FALSE if the blob was reclaimed out from under us
	 * (the caller then falls back to a real ingest).
	 *
	 * @param FileBlob $blob          the existing blob to reference
	 * @param string   $display_name  user-facing name (fil_title + fil_name seed)
	 * @param string   $mime          fallback MIME (the blob's own type wins)
	 * @param int      $owner_id
	 * @param array    $restrictions  visibility columns (e.g. ['fil_private'=>true])
	 * @return File|false
	 */
	public static function createFromExistingBlob(FileBlob $blob, $display_name, $mime, $owner_id, array $restrictions = array()) {
		require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
		if (!$blob->key || !FileBlob::retain($blob->key)) {
			return false;
		}
		// The reference is taken before the row that will hold it exists, so every
		// way out of here from this point has to give it back. The insert can fail
		// for an ordinary reason -- two uploads racing for one name, where the
		// partial unique index refuses the loser -- and a caller that turns that
		// into a polite 'name taken' would otherwise leave the count one too high
		// forever. Bytes with a reference nothing holds are never reclaimed, keep
		// counting against the owner's quota, and cannot be reached by anyone.
		try {
			$name = self::_mint_unique_name($display_name);
			$file = new File(NULL);
			$file->set('fil_name', $name);
			$file->set('fil_title', substr((string)($display_name !== '' ? $display_name : 'file'), 0, 255));
			$file->set('fil_type', $blob->get('fbb_mime_type') ?: $mime);
			$file->set('fil_usr_user_id', (int)$owner_id);
			$file->set('fil_fbb_file_blob_id', (int)$blob->key);
			foreach ($restrictions as $col => $val) {
				$file->set($col, $val);
			}
			$file->save();
			$file->load();
		} catch (Exception $e) {
			FileBlob::release($blob->key);
			throw $e;
		}
		if (!$file->key) {
			// A save that reported nothing rather than throwing still leaves no row
			// to hold the reference.
			FileBlob::release($blob->key);
			return false;
		}
		return $file;
	}

	private static function _mint_unique_name($display_name) {
		require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
		$base = ($display_name !== null && $display_name !== '') ? $display_name : 'attachment';
		if (strpos($base, '.') === false) {
			$base .= '.bin';
		}
		for ($i = 0; $i < 12; $i++) {
			$rand = '_' . LibraryFunctions::random_string(8) . '.';
			$dotpos = strrpos($base, '.');
			$name = substr($base, 0, $dotpos) . $rand . substr($base, $dotpos + 1);
			$name = str_replace(' ', '_', $name);
			$name = preg_replace('/[^A-Za-z0-9\.\-\_]/', '', $name);
			$name = preg_replace('/_+/', '_', $name);
			if (!self::get_by_name($name) && !FileBlob::stored_name_exists($name)) {
				return $name;
			}
		}
		return preg_replace('/[^A-Za-z0-9\.\-\_]/', '', LibraryFunctions::random_string(24)) . '.bin';
	}

	/**
	 * Return this file's raw bytes, resolving local disk or cloud storage. For
	 * server-side consumers that need the content directly (attachment
	 * download/forward, AI triage) rather than a URL. Returns null when the bytes
	 * cannot be read (missing on disk, cloud outage, unconfigured driver). This
	 * does NOT authorize — a caller serving to a user gates with is_viewable()
	 * first.
	 *
	 * @param string $size_key 'original' or an ImageSizeRegistry variant key
	 * @return string|null
	 */
	function read_bytes($size_key = 'original') {
		$blob = $this->_blob();
		return $blob ? $blob->read_bytes($size_key) : null;
	}

	/**
	 * Replace this file's stored bytes in place — for a consumer that rewrites one
	 * file's content (sealing/unsealing a protected attachment's ciphertext). The
	 * new bytes become private to THIS file: if dedup gave the underlying blob more
	 * than one reference, it is first copy-on-write split into a fresh
	 * single-reference blob and this file repointed, so siblings — and any future
	 * identical upload that deduped onto the shared original — are never touched.
	 * The write itself (hash-clear, size update, stale-variant drop) is delegated
	 * to the now-exclusive blob.
	 *
	 * @param string $new_bytes replacement content
	 * @return bool whether the bytes were written
	 */
	function replace_bytes($new_bytes) {
		return $this->_replace_bytes_with(function ($blob) use ($new_bytes) {
			return $blob->overwriteBytes($new_bytes);
		}, function ($path) use ($new_bytes) {
			return @file_put_contents($path, $new_bytes) !== false;
		});
	}

	/**
	 * Replace this file's stored bytes from a file on disk, with the same
	 * copy-on-write protection replace_bytes() gives — and without ever holding
	 * the content in memory. A protection-level change rewrites entire files, so
	 * the string form is not an option there.
	 *
	 * @param string $src_path replacement bytes; MOVED into place (gone on success)
	 * @return bool
	 */
	function replace_bytes_from_path($src_path) {
		return $this->_replace_bytes_with(function ($blob) use ($src_path) {
			return $blob->overwriteBytesFromPath($src_path);
		}, function ($path) use ($src_path) {
			return @rename($src_path, $path);
		});
	}

	/**
	 * The shared half of the two replace_bytes forms: split a shared blob before
	 * mutating it, then let the caller write. $write receives the now-exclusive
	 * blob; $write_direct handles the legacy blob-less row that owns its bytes 1:1.
	 */
	private function _replace_bytes_with(callable $write, callable $write_direct) {
		require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
		$blob_id = (int)$this->get('fil_fbb_file_blob_id');
		if (!$blob_id) {
			// Legacy blob-less row owns its bytes 1:1 — write straight through.
			$path = $this->get_filesystem_path('original');
			return ($path !== '' && $path !== null && $write_direct($path));
		}
		// Load fresh (not the memoized copy) so the reference count is current.
		$blob = new FileBlob($blob_id, true);
		if (!$blob->key) {
			return false;
		}
		if ((int)$blob->get('fbb_reference_count') > 1) {
			// Shared bytes: split off a private copy before mutating, so no sibling
			// or future dedup candidate sees this file's rewritten content.
			$copy = $blob->splitCopy($blob->is_private_bool());
			$dblink = DbConnector::get_instance()->get_db_link();
			$q = $dblink->prepare("UPDATE fil_files SET fil_fbb_file_blob_id = ? WHERE fil_file_id = ?");
			$q->execute([$copy->key, $this->key]);
			$this->set('fil_fbb_file_blob_id', $copy->key, false);
			FileBlob::release($blob->key);
			$blob = $copy;
		}
		$this->_blob_cache = $blob;
		$this->_blob_cache_id = (int)$blob->key;
		return $write($blob);
	}

	/** @var FileBlob|null Memoized blob for this file's physical bytes. */
	private $_blob_cache = null;
	private $_blob_cache_id = null;

	/**
	 * The FileBlob holding this file's physical bytes, or null when the file has
	 * no blob (a not-yet-backfilled legacy row). Memoized. Physical operations
	 * (paths, reads, resize, offload, deletion) delegate here — File owns only
	 * identity, ownership and visibility.
	 */
	function _blob() {
		$id = $this->get('fil_fbb_file_blob_id');
		if (!$id) {
			return null;
		}
		if ($this->_blob_cache !== null && $this->_blob_cache_id === (int)$id) {
			return $this->_blob_cache;
		}
		require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
		$blob = new FileBlob((int)$id, true);
		$this->_blob_cache = $blob;
		$this->_blob_cache_id = (int)$id;
		return $blob;
	}

	/**
	 * Size of this file's bytes, in bytes.
	 *
	 * There is no fil_size column — the byte count belongs to the BLOB, because
	 * several files can share one set of bytes through dedup and only the blob
	 * knows how big they are. Reading `fil_size` off a File silently yields
	 * nothing, so anything showing a size to a user should come through here.
	 *
	 * Returns 0 for a blob-less row rather than guessing from the filesystem.
	 */
	function size_bytes(): int {
		$blob = $this->_blob();
		return $blob ? (int)$blob->get('fbb_size_bytes') : 0;
	}

	/**
	 * This file's storage driver ('local' | 'cloud'), read from its blob. Null
	 * when the file has no blob. The serving path and cloud-aware consumers
	 * branch on this instead of a former fil_files column.
	 */
	function storage_driver() {
		$blob = $this->_blob();
		return $blob ? $blob->get('fbb_storage_driver') : null;
	}

	function get_name() {
		if($this->get('fil_title')){
			return $this->get('fil_title');
		}
		else{
			return $this->get('fil_name');
		}
	}
	
	/**
	 * Is this a raster image the platform decodes and resizes (GD)? Keyed to
	 * the same allowlist as inline serving — never "starts with image/",
	 * because image/svg+xml is an image type GD cannot decode and the browser
	 * must not render inline. SVG and other exotic image/* types are plain
	 * files here: no size variants, no thumbnail. If resizable-set and
	 * inline-safe-set ever need to diverge, split the constant then.
	 */
	function is_image(){
		if ($this->is_encrypted() || $this->is_sealed()) {
			// Ciphertext on disk is never a server-decodable image. A sealed file
			// answers false for the same reason its bytes are sealed, even though
			// fil_type names a real image type — the resize pipeline reads the
			// stored bytes, and those are a container. Its thumbnail is made once
			// at upload, from the plaintext, and stored sealed.
			return false;
		}
		return self::is_inline_safe_type($this->get('fil_type'));
	}

	/** This file's protection level, normalized (see ProtectionLevel). */
	function protection_level(){
		require_once(PathHelper::getIncludePath('includes/ProtectionLevel.php'));
		return ProtectionLevel::normalize($this->get('fil_protection_level'));
	}

	/**
	 * Is this file client-side end-to-end encrypted — the Fortress level
	 * (docs/drive_encryption.md)? The stored bytes are ciphertext the server
	 * never interprets, so this stays the skip-list gate for every
	 * content-understanding feature (thumbnails, previews, search, AI, photo
	 * eligibility, office editing).
	 *
	 * A Private file is NOT encrypted in this sense: the server holds its key
	 * wrapping and can open the bytes inside the owner's window, which is what
	 * lets those same features work there. Ask is_sealed() for that one.
	 */
	function is_encrypted(){
		require_once(PathHelper::getIncludePath('includes/ProtectionLevel.php'));
		return $this->protection_level() === ProtectionLevel::FORTRESS;
	}

	/**
	 * Is this file sealed under server custody — the Private level? The bytes on
	 * disk are a SealedFileContainer; every read goes through the streaming
	 * decrypt path and needs the owner's unlock window open.
	 */
	function is_sealed(){
		require_once(PathHelper::getIncludePath('includes/ProtectionLevel.php'));
		return $this->protection_level() === ProtectionLevel::PRIVATE_;
	}

	/**
	 * The size the member is shown: plaintext bytes. Sealed files record it at
	 * seal time because the blob measures ciphertext; everything else is its
	 * blob size unchanged.
	 */
	function plain_size_bytes(): int {
		if ($this->is_sealed()) {
			$recorded = $this->get('fil_plain_size_bytes');
			if ($recorded !== null && $recorded !== '') {
				return (int)$recorded;
			}
		}
		return $this->size_bytes();
	}

	/**
	 * Is this MIME type safe to render inline in a browser? True only for the
	 * raster image allowlist (INLINE_SAFE_TYPES). Any other type — SVG, HTML,
	 * PDF, office docs, unknown — must be served as a download. The decision is
	 * an allowlist, not "starts with image/", because image/svg+xml is a
	 * script-bearing image. Tolerates a trailing ";charset=" parameter.
	 *
	 * @param string $mime
	 * @return bool
	 */
	/**
	 * The icon that stands in for this file when its own bytes can't be shown —
	 * no thumbnail was ever made, the variant can't be produced (a cloud blob),
	 * or the file isn't an image at all.
	 *
	 * One place decides what a file type looks like, so a listing, a picker and
	 * a detail page can't drift into three different answers. Keyed on the MIME
	 * type first (set from the bytes at ingestion, so it's trustworthy) and only
	 * then on the filename, which is the caller's word for it.
	 *
	 * @return string URL of an 80px icon asset
	 */
	public function type_icon_url() {
		$mime = strtolower(trim((string)$this->get('fil_type')));
		$semi = strpos($mime, ';');
		if ($semi !== false) {
			$mime = trim(substr($mime, 0, $semi));
		}
		$name = strtolower((string)$this->get('fil_name'));

		if ($mime === 'application/pdf' || substr($name, -4) === '.pdf') {
			return '/assets/images/pdf_icon_80px.png';
		}
		if ($mime === 'application/msword'
			|| strpos($mime, 'wordprocessingml.document') !== false
			|| substr($name, -4) === '.doc' || substr($name, -5) === '.docx') {
			return '/assets/images/microsoft_word_icon_80px.png';
		}
		if ($mime === 'application/vnd.ms-excel'
			|| strpos($mime, 'spreadsheetml.sheet') !== false
			|| substr($name, -4) === '.xls' || substr($name, -5) === '.xlsx') {
			return '/assets/images/excel_icon_80px.png';
		}
		if (strpos($mime, 'image/') === 0) {
			// An image we can't show a thumbnail of — say "image", not "file".
			return '/assets/images/image_icon_80px.svg';
		}
		return '/assets/images/file_icon_80px.svg';
	}

	/**
	 * Thumbnail markup for a listing: the real thumbnail when there is one to
	 * ask for, otherwise this file's type icon — and the icon again if the
	 * thumbnail turns out not to load.
	 *
	 * The fallback has to be client-side because "is there a variant" is not a
	 * question the server can answer cheaply for every row: on a cloud blob it
	 * would be a HEAD per image per page. assets/js/file-thumb.js swaps in
	 * data-thumb-fallback on error, so a missing size degrades to an icon
	 * instead of a broken-image glyph, whatever the reason for the miss.
	 *
	 * @param string $size_key Registered ImageSizeRegistry size
	 * @param string $css_class Optional class for the <img>
	 * @return string HTML
	 */
	public function thumbnail_html($size_key = 'avatar', $css_class = '') {
		$icon  = htmlspecialchars($this->type_icon_url());
		$class = $css_class !== '' ? ' class="' . htmlspecialchars($css_class) . '"' : '';

		if (!$this->is_image()) {
			// Carries jy-thumb-icon from the start: it is already the icon, and
			// an icon should look the same however it got on the page.
			$icon_class = trim($css_class . ' jy-thumb-icon');
			return '<img loading="lazy" src="' . $icon . '" alt=""'
				. ' class="' . htmlspecialchars($icon_class) . '">';
		}
		return '<img loading="lazy" src="' . htmlspecialchars($this->get_url($size_key)) . '"'
			. ' data-thumb-fallback="' . $icon . '" alt=""' . $class . '>';
	}

	public static function is_inline_safe_type($mime) {
		$mime = strtolower(trim((string)$mime));
		$semi = strpos($mime, ';');
		if ($semi !== false) {
			$mime = trim(substr($mime, 0, $semi));
		}
		return in_array($mime, self::INLINE_SAFE_TYPES, true);
	}

	/**
	 * Stream this file's bytes to the browser with the headers every
	 * uploaded-file response must carry: the stored (magic-byte-detected)
	 * Content-Type, X-Content-Type-Options: nosniff, and inline rendering
	 * only for INLINE_SAFE_TYPES — everything else (SVG, HTML, PDF, office,
	 * unknown) is forced to download. Owning the whole header set here means
	 * a serving branch cannot half-apply the policy. The one uploaded-bytes
	 * path that doesn't come through here is RouteHelper's pre-boot fast
	 * serve for public files, which cannot load this model and carries its
	 * own conservative backstop (see RouteHelper::serveStaticFile).
	 *
	 * This does NOT authorize — the caller gates (is_viewable(), signed URL)
	 * before serving.
	 *
	 * @param string $path          on-disk bytes to stream: a local file, or a
	 *                              downloaded tmp copy of a cloud object (the
	 *                              caller still unlinks its tmp file)
	 * @param string $cache_control Cache-Control header value — the caller's
	 *                              only serving decision ('private, no-store'
	 *                              for gated/signed streams; 'public,
	 *                              max-age=...' for public bytes)
	 */
	/** @var array<string,callable> fil_source => decryptor(string $ciphertext, File $file): string
	 *  Sealed Vault decrypt hook (docs/sealed_vault.md) - a consumer with
	 *  sealed attachments registers its source tag here once, at bootstrap. */
	private static $decrypt_hooks = array();

	/**
	 * Register the decryptor for a fil_source tag. The decryptor reads the
	 * in-window vault secret key itself (VaultUnlock::secretKey()) and throws
	 * VaultLockedException when the vault is locked - serve_from_path()
	 * turns that into a generic 423 response, never a raw error.
	 */
	public static function registerDecryptHook(string $source, callable $decryptor): void {
		self::$decrypt_hooks[$source] = $decryptor;
	}

	private static function resolve_decrypt_hook($source) {
		return ($source !== null && isset(self::$decrypt_hooks[$source])) ? self::$decrypt_hooks[$source] : null;
	}

	/** @var array<string,callable> fil_source => opener(File): ?FileStreamingDecryptor */
	private static $streaming_decrypt_hooks = array();

	/**
	 * Register a STREAMING decryptor for a fil_source tag — the shape for sealed
	 * content that can be opened a piece at a time.
	 *
	 * The whole-bytes hook (registerDecryptHook) reads the entire ciphertext into
	 * memory, so a file served through it can answer no range honestly and
	 * advertises none. A streaming consumer hands back an object that can open an
	 * arbitrary span, which is what lets a sealed video seek and a sealed download
	 * resume. A source may register either shape; the streaming one wins when both
	 * are present.
	 *
	 * The opener runs per request and may return null, meaning "this particular
	 * file is not sealed — stream it unchanged". It must NOT need the vault: the
	 * decryptor's prepare() is where the unlock window is required, so that a
	 * locked vault becomes a 423 before any header is sent.
	 *
	 * The opener is handed the size key being served ('original' or an image
	 * variant), because a consumer's integrity checks differ between the two: a
	 * file's row records the plaintext size of its ORIGINAL and knows nothing
	 * about a variant's. A caller that cannot say passes null, and a consumer
	 * must treat that as "unknown", never as "original".
	 */
	public static function registerStreamingDecryptHook(string $source, callable $opener): void {
		self::$streaming_decrypt_hooks[$source] = $opener;
	}

	private function resolve_streaming_decryptor($size_key = null) {
		$source = $this->get('fil_source');
		if ($source === null || !isset(self::$streaming_decrypt_hooks[$source])) {
			return null;
		}
		$decryptor = call_user_func(self::$streaming_decrypt_hooks[$source], $this, $size_key);
		return ($decryptor instanceof FileStreamingDecryptor) ? $decryptor : null;
	}

	/**
	 * Parse a Range request header against a known object size.
	 *
	 * Single ranges only. A multi-range request, an unparseable header, or a
	 * unit we do not speak all come back as "no range": RFC 7233 lets a server
	 * ignore a Range it cannot honor, and serving the whole thing is always a
	 * correct answer. Only a syntactically valid range that falls outside the
	 * object is a refusal, because that one is the client being wrong about the
	 * file rather than asking for something we chose not to do.
	 *
	 * @param string|null $header the raw Range header value
	 * @param int         $total  the full object size in bytes
	 * @return array{start:int,end:int}|null|false range, null to ignore, false = unsatisfiable (416)
	 */
	public static function parse_range_header($header, $total) {
		if (!is_string($header) || trim($header) === '') {
			return null;
		}
		$total = (int)$total;
		if (!preg_match('/^\s*bytes\s*=\s*(\d*)\s*-\s*(\d*)\s*$/i', $header, $m)) {
			return null; // multi-range, another unit, or malformed — serve it whole
		}
		$first = $m[1];
		$last  = $m[2];
		if ($first === '' && $last === '') {
			return null;
		}

		if ($first === '') {
			// Suffix form: the last N bytes. N of 0 asks for nothing, which is
			// the one suffix form that cannot be satisfied.
			$suffix = (int)$last;
			if ($suffix <= 0 || $total <= 0) {
				return false;
			}
			$start = max(0, $total - $suffix);
			return array('start' => $start, 'end' => $total - 1);
		}

		$start = (int)$first;
		if ($total <= 0 || $start >= $total) {
			return false;
		}
		$end = ($last === '') ? $total - 1 : (int)$last;
		if ($end < $start) {
			return false;
		}
		if ($end > $total - 1) {
			$end = $total - 1; // a client may ask past the end; clamp, do not refuse
		}
		return array('start' => $start, 'end' => $end);
	}

	/**
	 * Stream a file's bytes with the serve-back headers this File owns.
	 *
	 * @param string     $path          bytes to serve
	 * @param string     $cache_control Cache-Control header value
	 * @param array|null $range_info    when the caller has ALREADY resolved a
	 *        range and $path holds only that slice (the cloud path asks the
	 *        storage driver for the byte span rather than pulling the whole
	 *        object): ['start' => int, 'end' => int, 'total' => int]. Null means
	 *        $path is the complete object and any Range header is resolved here.
	 */
	function serve_from_path($path, $cache_control, $range_info = null, $size_key = null) {
		require_once(PathHelper::getIncludePath('includes/VaultUnlock.php')); // declares VaultLockedException
		VaultUnlock::loadConsumerBootstraps(); // a consumer's decrypt hook is registered lazily, at first use

		// Sealed content that streams gets the whole serve path to itself: it can
		// answer ranges, so it must own Content-Length, 206 and Accept-Ranges
		// rather than have them decided for plaintext bytes it isn't. The size key
		// travels with it so a consumer can tell the original from a variant.
		$streamer = $this->resolve_streaming_decryptor($size_key);
		if ($streamer !== null) {
			$this->_serve_streaming_decrypt($streamer, $path, $cache_control);
			return;
		}

		$bytes = null;
		$decryptor = self::resolve_decrypt_hook($this->get('fil_source'));
		if ($decryptor) {
			$ciphertext = @file_get_contents($path);
			if ($ciphertext === false) {
				require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
				LibraryFunctions::display_404_page();
				return;
			}
			try {
				$bytes = call_user_func($decryptor, $ciphertext, $this);
			} catch (VaultLockedException $e) {
				http_response_code(423);
				header('Content-Type: text/plain; charset=utf-8');
				echo 'This file is locked. Unlock your vault to view it.';
				return;
			}
		}

		$content_type = $this->get('fil_type') ?: 'application/octet-stream';
		header('Content-Type: ' . $content_type);
		header('X-Content-Type-Options: nosniff');
		header('Cache-Control: ' . $cache_control);
		if (!self::is_inline_safe_type($content_type)) {
			header('Content-Disposition: attachment; filename="' . basename($this->get('fil_name')) . '"');
		}
		if ($bytes !== null) {
			// Served through a decrypt hook: the plaintext is produced in memory
			// from the whole ciphertext, so there is nothing to seek into and no
			// honest way to answer a range. No Accept-Ranges is advertised, and a
			// Range header is ignored rather than half-honored.
			header('Content-Length: ' . strlen($bytes));
			echo $bytes;
			return;
		}

		header('Accept-Ranges: bytes');

		// The caller already fetched exactly the requested span.
		if (is_array($range_info)) {
			$start = (int)$range_info['start'];
			$end   = (int)$range_info['end'];
			$total = (int)$range_info['total'];
			http_response_code(206);
			header('Content-Range: bytes ' . $start . '-' . $end . '/' . $total);
			header('Content-Length: ' . ($end - $start + 1));
			readfile($path);
			return;
		}

		$total = @filesize($path);
		$total = ($total === false) ? 0 : (int)$total;
		$range = self::parse_range_header($_SERVER['HTTP_RANGE'] ?? null, $total);

		if ($range === false) {
			http_response_code(416);
			header('Content-Range: bytes */' . $total);
			header('Content-Length: 0');
			return;
		}

		if ($range === null) {
			header('Content-Length: ' . $total);
			readfile($path);
			return;
		}

		$length = $range['end'] - $range['start'] + 1;
		http_response_code(206);
		header('Content-Range: bytes ' . $range['start'] . '-' . $range['end'] . '/' . $total);
		header('Content-Length: ' . $length);

		$fh = @fopen($path, 'rb');
		if ($fh === false) {
			return;
		}
		fseek($fh, $range['start']);
		$remaining = $length;
		while ($remaining > 0 && !feof($fh)) {
			$chunk = fread($fh, min(262144, $remaining));
			if ($chunk === false || $chunk === '') {
				break;
			}
			echo $chunk;
			$remaining -= strlen($chunk);
		}
		fclose($fh);
	}

	/**
	 * Serve sealed bytes through a streaming decryptor, with real Range support.
	 *
	 * The order matters: everything that can fail — the unlock window, a corrupt
	 * container, an unsatisfiable range — is resolved BEFORE the first header, so
	 * a failure is a clean 423/416/404 rather than a half-written 200. Once
	 * headers are out, the only remaining work is decrypt-and-echo.
	 */
	private function _serve_streaming_decrypt(FileStreamingDecryptor $streamer, $path, $cache_control) {
		try {
			$streamer->prepare($path);
			$total = $streamer->plainSize($path);
		} catch (VaultLockedException $e) {
			http_response_code(423);
			header('Content-Type: text/plain; charset=utf-8');
			echo 'This file is locked. Unlock your vault to view it.';
			return;
		} catch (Exception $e) {
			error_log('Sealed serve failed for fil=' . (int)$this->key . ': ' . $e->getMessage());
			require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
			LibraryFunctions::display_404_page();
			return;
		}

		$range = self::parse_range_header($_SERVER['HTTP_RANGE'] ?? null, $total);
		if ($range === false) {
			http_response_code(416);
			header('Accept-Ranges: bytes');
			header('Content-Range: bytes */' . $total);
			header('Content-Length: 0');
			return;
		}

		$content_type = $this->get('fil_type') ?: 'application/octet-stream';
		header('Content-Type: ' . $content_type);
		header('X-Content-Type-Options: nosniff');
		header('Cache-Control: ' . $cache_control);
		if (!self::is_inline_safe_type($content_type)) {
			header('Content-Disposition: attachment; filename="' . basename($this->get('fil_name')) . '"');
		}
		header('Accept-Ranges: bytes');

		$offset = 0;
		$length = $total;
		if (is_array($range)) {
			$offset = $range['start'];
			$length = $range['end'] - $range['start'] + 1;
			http_response_code(206);
			header('Content-Range: bytes ' . $range['start'] . '-' . $range['end'] . '/' . $total);
		}
		header('Content-Length: ' . $length);

		try {
			$streamer->stream($path, function ($piece) { echo $piece; }, $offset, $length);
		} catch (Exception $e) {
			// Headers and probably bytes are already out; there is no status left
			// to send. Log it and stop rather than append an error into the file.
			error_log('Sealed stream aborted for fil=' . (int)$this->key . ': ' . $e->getMessage());
		}
	}

	/**
	 * Detect a file's real MIME type from its magic bytes, never from the
	 * client-supplied extension or Content-Type (both spoofable).
	 *
	 * Return contract:
	 *   - A concrete type string when finfo or the signature table recognizes it.
	 *   - `application/octet-stream` when the bytes are recognized by NEITHER —
	 *     the fail-closed "unrecognized binary" answer callers should reject on.
	 *     This is a real return value, not a stand-in for null.
	 *   - `null` ONLY when finfo itself is unavailable or the path is unreadable
	 *     (a broken-server condition, not a property of the file), so callers may
	 *     fall back to a client-supplied value as a last resort.
	 *
	 * libmagic only matches a signature within a limited window from the start of
	 * the file, so a valid PDF/image whose header sits past that window comes back
	 * as `application/octet-stream`. When finfo yields that sentinel, a magic-byte
	 * signature sniff (exactly as trustworthy as finfo — same bytes) covers the
	 * gap before the fail-closed answer is returned.
	 *
	 * @param string $path filesystem path to the stored bytes
	 * @return string|null detected MIME type, or null if finfo is unavailable
	 */
	public static function detect_mime_file($path) {
		if (!function_exists('finfo_open') || !is_readable($path)) {
			return null;
		}
		$finfo = finfo_open(FILEINFO_MIME_TYPE);
		if ($finfo === false) {
			return null;
		}
		$mime = finfo_file($finfo, $path);
		if ($mime === false || $mime === '') {
			return null;   // finfo failed on readable bytes — treat as unavailable
		}
		if ($mime === 'application/octet-stream') {
			$head = @file_get_contents($path, false, null, 0, 4096);
			$sniff = ($head === false) ? null : self::sniff_signature($head);
			return $sniff !== null ? $sniff : 'application/octet-stream';
		}
		return $mime;
	}

	/**
	 * Magic-byte MIME detection from an in-memory byte string. Same contract as
	 * detect_mime_file() — including the signature-sniff fallback for finfo's
	 * `application/octet-stream` sentinel and the null-only-when-finfo-unavailable
	 * rule.
	 *
	 * @param string $bytes
	 * @return string|null detected MIME type, or null if finfo is unavailable
	 */
	public static function detect_mime_bytes($bytes) {
		if (!function_exists('finfo_open')) {
			return null;
		}
		$finfo = finfo_open(FILEINFO_MIME_TYPE);
		if ($finfo === false) {
			return null;
		}
		$mime = finfo_buffer($finfo, (string)$bytes);
		if ($mime === false || $mime === '') {
			return null;
		}
		if ($mime === 'application/octet-stream') {
			$sniff = self::sniff_signature(substr((string)$bytes, 0, 4096));
			return $sniff !== null ? $sniff : 'application/octet-stream';
		}
		return $mime;
	}

	/**
	 * Recognize an accepted binary type from its leading magic bytes, or null when
	 * no signature matches. This is finfo's safety net for its scan-window blind
	 * spot: a signature is read from the same bytes finfo reads, so it never trusts
	 * the client extension/Content-Type. Only types the platform actually accepts
	 * as binary are listed; anything else (SVG, HTML, office) is deliberately NOT
	 * sniffed and stays unrecognized.
	 *
	 * @param string $head the file's leading bytes (a 4 KB prefix is sufficient)
	 * @return string|null the detected MIME, or null if no signature matches
	 */
	private static function sniff_signature($head) {
		$head = (string)$head;
		if ($head === '') return null;
		if (strpos(substr($head, 0, 4096), '%PDF-') !== false) return 'application/pdf';
		if (strncmp($head, "\x89PNG\r\n\x1a\n", 8) === 0)        return 'image/png';
		if (strncmp($head, "\xFF\xD8\xFF", 3) === 0)             return 'image/jpeg';
		if (strncmp($head, 'GIF87a', 6) === 0
				|| strncmp($head, 'GIF89a', 6) === 0)            return 'image/gif';
		if (strncmp($head, 'RIFF', 4) === 0
				&& substr($head, 8, 4) === 'WEBP')               return 'image/webp';
		if (substr($head, 4, 4) === 'ftyp') {
			$brand = substr($head, 8, 4);
			if ($brand === 'avif' || $brand === 'avis')          return 'image/avif';
		}
		return null;
	}

	/**
	 * Determine whether this file should be in the public (fast-serve) directory.
	 * A file is public when it has no permission restrictions and is not deleted.
	 *
	 * @return bool
	 */
	/**
	 * Is this file owner-or-admin private? Interprets the fil_private column
	 * robustly across representations: PDO may hand back a native bool, a pg
	 * 't'/'f' string, and a not-yet-reloaded new row carries the declared string
	 * default 'false' (which is truthy in PHP) — so test membership of the "true"
	 * set rather than raw truthiness. Only genuine true values count as private.
	 */
	private function _is_private() {
		$v = $this->get('fil_private');
		return ($v === true || $v === 't' || $v === 'true' || $v === '1' || $v === 1);
	}

	function is_public() {
		if ($this->get('fil_delete_time')) return false;
		if ($this->_is_private()) return false;
		if ($this->get('fil_min_permission')) return false;
		if ($this->get('fil_grp_group_id')) return false;
		if ($this->get('fil_access_provider')) return false;
		if ($this->get('fil_tier_min_level')) return false;
		return true;
	}

	/**
	 * Bucket object key (without the driver-applied path prefix) for a size
	 * variant — resolved through the blob (keyed on fbb_stored_name), so files
	 * sharing a blob share its keys. Falls back to fil_name for a blob-less row.
	 */
	function remote_key_for($size_key = 'original') {
		$blob = $this->_blob();
		if ($blob) {
			return $blob->remote_key_for($size_key);
		}
		$filename = $this->get('fil_name');
		return ($size_key === 'original') ? $filename : $size_key . '/' . $filename;
	}

	/**
	 * On-disk path for a size variant, resolved through the blob (keyed on
	 * fbb_stored_name). Falls back to fil_name-based lookup for a blob-less row.
	 *
	 * @param string $size_key Size key from ImageSizeRegistry, or 'original'
	 * @return string Filesystem path (may not exist if the bytes are cloud-resident or missing)
	 */
	function get_filesystem_path($size_key = 'original') {
		$blob = $this->_blob();
		if ($blob) {
			return $blob->filesystem_path($size_key);
		}
		// Blob-less (not-yet-backfilled) row: the bytes still live under fil_name.
		// A public legacy file sits in the fast-serve dir, a restricted one in the
		// upload dir — search both (as the pre-blob code did), falling back to the
		// upload dir's expected path.
		$settings = Globalvars::get_instance();
		$filename  = $this->get('fil_name');
		$upload_dir = $settings->get_setting('upload_dir');
		$fast_dir   = dirname($upload_dir) . '/static_files/uploads';
		foreach (array($fast_dir, $upload_dir) as $dir) {
			$path = ($size_key === 'original') ? $dir . '/' . $filename : $dir . '/' . $size_key . '/' . $filename;
			if (file_exists($path)) {
				return $path;
			}
		}
		return ($size_key === 'original') ? $upload_dir . '/' . $filename : $upload_dir . '/' . $size_key . '/' . $filename;
	}

	/**
	 * Maintain the visibility invariant: every file referencing a blob must be in
	 * the same visibility class. Called after save / soft_delete / undelete, i.e.
	 * whenever this file's placement class may have changed.
	 *
	 * When the file's desired class already matches its blob's, nothing to do.
	 * Otherwise, at refcount 1 the blob itself flips (bytes move between the
	 * fast-serve and restricted dirs, or pull home from the wrong bucket); at
	 * refcount > 1 the bytes copy-on-write into a fresh blob in the target class
	 * and this file repoints, decrementing the old blob.
	 *
	 * Soft-delete exception: deleting one of several references does NOT drag the
	 * shared blob private — the deleted file's own URLs are already gated by
	 * is_viewable(), and the siblings keep serving the public bytes.
	 */
	function move_to_correct_directory() {
		$blob_id = $this->get('fil_fbb_file_blob_id');
		if (!$blob_id) {
			return;
		}
		// Load the blob FRESH (not the memoized copy): a concurrent dedup may have
		// bumped its reference count since this file last read it, and the
		// flip-vs-split decision below hinges on the current count.
		require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
		$blob = new FileBlob((int)$blob_id, true);
		if (!$blob->key) {
			return;
		}
		$this->_blob_cache = $blob;
		$this->_blob_cache_id = (int)$blob_id;

		$desired_private = !$this->is_public();
		$current_private = $blob->is_private_bool();
		if ($desired_private === $current_private) {
			return; // invariant already holds
		}

		$refcount = (int)$blob->get('fbb_reference_count');
		if ($refcount <= 1) {
			$blob->flipVisibility($desired_private);
			return;
		}

		// refcount > 1 and this file is being soft-deleted: the deleted file's own
		// URLs are already gated, and a live public sibling still needs the bytes
		// public — so leave the shared blob alone UNLESS this was the last public
		// referrer. When no live file still needs these bytes world-served, flip the
		// (now all-deleted / none-public) blob private so its bytes leave the
		// Apache-served fast-serve dir instead of staying fetchable by stored name.
		if ($this->get('fil_delete_time')) {
			if ($desired_private && !$current_private
					&& !self::blob_has_public_referrer((int)$blob->key, (int)$this->key)) {
				$blob->flipVisibility(true);
			}
			return;
		}

		// A live restriction change on a shared blob: copy-on-write split.
		$new = $blob->splitCopy($desired_private);
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare("UPDATE fil_files SET fil_fbb_file_blob_id = ? WHERE fil_file_id = ?");
		$q->execute([$new->key, $this->key]);
		$this->set('fil_fbb_file_blob_id', $new->key, false);
		$this->_blob_cache = $new;
		$this->_blob_cache_id = (int)$new->key;
		FileBlob::release($blob->key);
	}

	/**
	 * Does any live (non-deleted) file OTHER than $exclude_file_id reference this
	 * blob and require it public? Used when soft-deleting one of several references
	 * to decide whether the shared public bytes may leave the world-served dir.
	 */
	private static function blob_has_public_referrer($blob_id, $exclude_file_id) {
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			"SELECT fil_file_id FROM fil_files
			 WHERE fil_fbb_file_blob_id = ? AND fil_file_id <> ? AND fil_delete_time IS NULL");
		$q->execute([(int)$blob_id, (int)$exclude_file_id]);
		$ids = $q->fetchAll(PDO::FETCH_COLUMN);
		foreach ($ids as $id) {
			$other = new File((int)$id, true);
			if ($other->key && $other->is_public()) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Save the file record, then maintain the blob visibility invariant.
	 *
	 * On first insert of a blob-backed file, fil_type is forced to the blob's
	 * magic-byte-detected type (fbb_mime_type). The sanctioned ingestion path
	 * already sets it, but this closes the defense-in-depth gap for any caller that
	 * builds a File directly and save()s with a spoofable client-supplied fil_type:
	 * the served Content-Type is always the honest detected one, never trusted
	 * caller input. Later saves (e.g. an attachment re-asserting its type after
	 * sealing) are left untouched.
	 */
	function save($debug = false) {
		// On a new row the blob's magic-byte detection wins over any
		// client-supplied type — it describes the bytes actually stored.
		//
		// That reasoning inverts for a protected file: the stored bytes are a
		// container, so sniffing them reports application/octet-stream no matter
		// what is inside. The real type was detected from the plaintext before it
		// was sealed and is already on the row, so leave it alone.
		if (!$this->key && $this->protection_level() === ProtectionLevel::STANDARD) {
			$blob = $this->_blob();
			if ($blob && $blob->get('fbb_mime_type')) {
				$this->set('fil_type', $blob->get('fbb_mime_type'));
			}
		}
		$result = parent::save($debug);
		$this->move_to_correct_directory();
		return $result;
	}

	/**
	 * Soft delete, then re-evaluate the blob's placement (a refcount-1 blob flips
	 * private; a shared blob is left public — see move_to_correct_directory()).
	 */
	function soft_delete() {
		$result = parent::soft_delete();
		$this->move_to_correct_directory();
		return $result;
	}

	/**
	 * Undelete and re-evaluate the blob's placement.
	 */
	function undelete() {
		$result = parent::undelete();
		$this->move_to_correct_directory();
		return $result;
	}

	/**
	 * Get URL for a specific image size
	 *
	 * @param string $size_key Size key from ImageSizeRegistry, or 'original' for full size
	 * @param string $format 'short' for relative URL, 'full' for absolute URL
	 * @return string
	 */
	function get_url($size_key='original', $format='short') {

		// Cloud-stored files. PUBLIC files: the world-readable bucket URL goes
		// straight to the browser, no PHP in the loop — stable and cacheable.
		// ($format=='short' is treated as 'full' because the bucket is a
		// different domain.) PRIVATE files must NEVER expose a bucket URL — they
		// fall through to the local /uploads/* pattern, which serve.php
		// gate-streams from the verified-private bucket after is_viewable().
		if ($this->storage_driver() === 'cloud') {
			if ($this->is_public()) {
				require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriverFactory.php'));
				$driver = CloudStorageDriverFactory::default();
				if ($driver) {
					return $driver->url($this->remote_key_for($size_key));
				}
				// Falls through to the local URL pattern if cloud is unconfigured.
				// /uploads/* will then 302-redirect once cloud is reconfigured —
				// or 404 if the bucket bytes are gone.
			}
			// Private (or public-but-unconfigured): fall through to the local
			// /uploads/* URL, which routes through serve.php (gated stream for
			// private cloud bytes; the "never url()" rule).
		}

		$settings = Globalvars::get_instance();
		$upload_web_dir = $settings->get_setting('upload_web_dir');

		// A public file's bytes are fetched straight from the fast-serve dir, where
		// they live under the blob's stored name. Point the URL there so a dedup
		// secondary (whose fil_name has no file of its own) is served directly by
		// Apache with no serve.php round trip. Private files keep fil_name: their
		// URLs route through serve.php's gate, which must resolve and authorize THIS
		// file, not the blob's primary.
		$url_name = $this->get('fil_name');
		if ($this->is_public()) {
			$blob = $this->_blob();
			if ($blob && $blob->get('fbb_stored_name')) {
				$url_name = $blob->get('fbb_stored_name');
			}
		}

		if ($size_key === 'original') {
			$file_path = $upload_web_dir . '/' . $url_name;
		} else {
			$file_path = $upload_web_dir . '/' . $size_key . '/' . $url_name;
		}

		// Ensure leading slash
		if ($file_path[0] !== '/') {
			$file_path = '/' . $file_path;
		}

		if ($format == 'full') {
			return LibraryFunctions::get_absolute_url($file_path);
		} else {
			return $file_path;
		}
	}

	// ------------------------------------------------------------------
	// Signed URLs — short-lived, self-authorizing links to private files
	// (docs/file_signed_urls.md).
	//
	// Minting IS the authorization statement: only code that has already
	// verified the viewer may access this file calls mintSignedUrl(). The
	// /uploads route in serve.php checks the signature before the normal
	// is_viewable() gate; an invalid or expired signature simply falls
	// through to that gate — a signed miss is never its own error.
	// ------------------------------------------------------------------

	const SIGNING_KEY_SETTING = 'file_signed_url_key';

	/** @var string|null|false Per-request signing key cache (false = checked, absent). */
	private static $signing_key = null;

	/**
	 * Mint a short-lived signed URL for this file.
	 *
	 * The signature covers (file id, size key, expiry), so a thumbnail URL
	 * cannot fetch the original and the expiry cannot be extended. The URL
	 * is always the local /uploads pattern so it routes through serve.php's
	 * gate — never a bucket URL.
	 *
	 * @param string $size_key 'original' or an ImageSizeRegistry variant key
	 * @param int    $ttl_seconds Lifetime — keep short (default 5 minutes)
	 * @param string $format 'short' for relative URL, 'full' for absolute
	 * @return string /uploads/... URL with expires and sig query parameters
	 */
	function mintSignedUrl($size_key = 'original', $ttl_seconds = 300, $format = 'short') {
		$expires = time() + max(1, (int)$ttl_seconds);
		$sig = hash_hmac('sha256', $this->_signed_url_payload($size_key, $expires), self::_get_signing_key(true));

		$settings = Globalvars::get_instance();
		$upload_web_dir = $settings->get_setting('upload_web_dir');
		if ($size_key === 'original') {
			$path = $upload_web_dir . '/' . $this->get('fil_name');
		} else {
			$path = $upload_web_dir . '/' . $size_key . '/' . $this->get('fil_name');
		}
		if ($path[0] !== '/') {
			$path = '/' . $path;
		}
		$url = $path . '?expires=' . $expires . '&sig=' . $sig;
		return ($format == 'full') ? LibraryFunctions::get_absolute_url($url) : $url;
	}

	/**
	 * Verify a signed /uploads request: true only for a well-formed,
	 * unexpired signature over this exact file + size key (constant-time
	 * compare). False for everything else — the caller then applies the
	 * normal is_viewable() gate.
	 *
	 * @param string $size_key Size key derived from the URL path
	 * @param mixed  $expires 'expires' query parameter
	 * @param mixed  $sig 'sig' query parameter
	 * @return bool
	 */
	function verify_signed_request($size_key, $expires, $sig) {
		if (!$this->key || !is_string($sig) || $sig === '' || !ctype_digit((string)$expires)) {
			return false;
		}
		if ((int)$expires < time()) {
			return false;
		}
		$key = self::_get_signing_key(false);
		if ($key === null) {
			return false; // No key provisioned — nothing was ever minted.
		}
		$expected = hash_hmac('sha256', $this->_signed_url_payload($size_key, (int)$expires), $key);
		return hash_equals($expected, $sig);
	}

	private function _signed_url_payload($size_key, $expires) {
		return $this->key . ':' . $size_key . ':' . $expires;
	}

	/**
	 * Mint the signing key now if the deployment has none, so that no later
	 * request has to.
	 *
	 * Called from update_database, which runs on every install and upgrade.
	 * The point is the moment, not the act: minting writes a long encrypted
	 * blob to stg_settings, and stg_settings cannot seal anything to a user,
	 * so the write is refused outright (SealedEgressGuard) if it happens in a
	 * request that has already opened sealed content. Opening a protected mail
	 * thread with an attachment is exactly such a request — it decrypts the
	 * bodies, then mints signed URLs for the attachments. Provisioning here,
	 * cold, keeps that first mint out of a hot request.
	 *
	 * @return bool True once a key exists.
	 */
	public static function provisionSigningKey() {
		return self::_get_signing_key(true) !== null;
	}

	/**
	 * The dedicated file-URL signing key: 32 random bytes, generated on
	 * first mint, stored SecretBox-encrypted in stg_settings. Deliberately
	 * separate from secret_box_key (key separation): deleting the row
	 * rotates the key, invalidating all outstanding signed URLs and
	 * nothing else.
	 *
	 * @param bool $create Generate and persist the key if absent
	 * @return string|null Raw 32-byte key, or null when absent and !$create
	 */
	private static function _get_signing_key($create = false) {
		if (is_string(self::$signing_key)) {
			return self::$signing_key;
		}
		if (self::$signing_key === false && !$create) {
			return null;
		}

		require_once(PathHelper::getIncludePath('includes/SecretBox.php'));
		$dblink = DbConnector::get_instance()->get_db_link();

		$read = function() use ($dblink) {
			$q = $dblink->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
			$q->execute(array(self::SIGNING_KEY_SETTING));
			$blob = $q->fetchColumn();
			return ($blob === false || $blob === '') ? null : (string)$blob;
		};

		$blob = $read();
		if ($blob === null) {
			if (!$create) {
				self::$signing_key = false;
				return null;
			}
			// Race-safe first-mint provisioning, then re-read so a concurrent
			// winner's key is the one we use.
			//
			// The row usually already exists and is empty: file_signed_url_key is
			// declared in settings.json, so every install seeds it with its ''
			// default long before anything mints. First-mint therefore has to fill
			// an existing row, not merely insert one — a plain DO NOTHING leaves
			// the row empty forever and every signed URL on the deployment fails.
			// Filling only when empty is still race-safe: ON CONFLICT locks the
			// conflicting row and evaluates this WHERE against its latest version,
			// so a concurrent loser sees the winner's key and leaves it alone, and
			// a key already in use is never overwritten.
			// Raw rather than Setting::put(): the WHERE clause below is the race
			// guard — two requests provisioning at once must not overwrite each
			// other's key, and put() has no conditional form.
			$box = new SecretBox();
			$new_blob = $box->seal(self::SIGNING_KEY_SETTING, base64_encode(random_bytes(32)));
			$ins = $dblink->prepare(
				"INSERT INTO stg_settings
					(stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name)
				 VALUES (?, ?, 1, NOW(), NOW(), 'files')
				 ON CONFLICT (stg_name) DO UPDATE
					SET stg_value = EXCLUDED.stg_value, stg_update_time = NOW()
					WHERE stg_settings.stg_value IS NULL OR stg_settings.stg_value = ''"
			);
			$ins->execute(array(self::SIGNING_KEY_SETTING, $new_blob));
			$blob = $read();
			if ($blob === null) {
				throw new FileException('Signed URL key could not be provisioned.');
			}
		}

		$box = isset($box) ? $box : new SecretBox();
		$opened = $box->open($blob);
		if ($opened['value'] === null) {
			// Dead (wrong key / moved database): behave as if no key is
			// provisioned. A hot request treats it as absent; the reconciler
			// re-mints it on its next cold pass (this is a `regenerable` secret).
			self::$signing_key = false;
			return null;
		}
		$key = base64_decode((string)$opened['value'], true);
		if ($key === false || strlen($key) !== 32) {
			throw new FileException('Signed URL key is malformed.');
		}
		self::$signing_key = $key;
		return $key;
	}

	function permanent_delete($debug=false){
		// Clean up all entity_photos rows referencing this file
		$dbconnector = DbConnector::get_instance();
		$dblink = $dbconnector->get_db_link();
		$sql = "DELETE FROM eph_entity_photos WHERE eph_fil_file_id = ?";
		try {
			$q = $dblink->prepare($sql);
			$q->execute([$this->key]);
		} catch (PDOException $e) {
			// Table may not exist yet during initial setup
			error_log('EntityPhoto cleanup on file delete: ' . $e->getMessage());
		}

		// Let go of the physical bytes: the blob decrements its reference count
		// and, at zero, deletes the original + every variant (local or cloud) and
		// its own row. A blob still referenced by another file is untouched.
		//
		// The head is read from the ROW, under the same lock version work takes,
		// and never from this copy of the file. A File object can be minutes old
		// by the time somebody deletes it, and a version saved in the meantime
		// moves the head: the blob this copy remembers is then the one a VERSION
		// row holds. Releasing that reference lets go of something this delete is
		// not deleting -- and the cascade below releases it again when it removes
		// that version row, so the bytes can be reclaimed out from under any other
		// file that deduped onto them -- while the head the file actually has is
		// never released at all, leaving bytes pinned for good, still counted
		// against the owner's quota and reachable by nobody. Both drifts, from one
		// stale read.
		require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));
		$own_tx = !$dblink->inTransaction();
		if ($own_tx) { $dblink->beginTransaction(); }
		try {
			$hq = $dblink->prepare('SELECT fil_fbb_file_blob_id FROM fil_files WHERE fil_file_id = ? FOR UPDATE');
			$hq->execute([$this->key]);
			$blob_id = (int)$hq->fetchColumn();
			if ($blob_id) {
				FileBlob::release($blob_id);
			}
			parent::permanent_delete($debug);
			if ($own_tx) { $dblink->commit(); }
		} catch (Exception $e) {
			if ($own_tx && $dblink->inTransaction()) { $dblink->rollBack(); }
			throw $e;
		}
		return true;
	}

	/**
	 * Generate resized variants — delegated to the blob, which owns the physical
	 * bytes and the <size>/<stored_name> variant layout shared by every file
	 * that references it.
	 */
	function resize($size_key='all'){
		if ($this->is_encrypted() || $this->is_sealed()) {
			// Skip-list: the stored bytes are a container either way, and resizing
			// them would produce garbage variants. A sealed file's thumbnail is
			// made from the plaintext at upload and stored sealed instead.
			return false;
		}
		$blob = $this->_blob();
		return $blob ? $blob->resize($size_key) : false;
	}

	/**
	 * Make sure one variant exists, generating just that size on demand —
	 * delegated to the blob. Returns the path, or false if it cannot be made.
	 *
	 * This is what lets get_url('avatar') stay truthful for a file whose
	 * ingestion path never called resize(): the thumbnail is produced the first
	 * time someone actually looks at it, and no other size is written.
	 */
	function ensure_variant($size_key){
		if ($this->is_encrypted() || $this->is_sealed()) {
			// Same skip-list as resize(): the stored bytes are a container, so
			// resizing them would produce a garbage variant.
			return false;
		}
		$blob = $this->_blob();
		return $blob ? $blob->ensure_variant($size_key) : false;
	}

	/**
	 * Delete resized variants — delegated to the blob.
	 */
	function delete_resized($size_key = 'all'){
		$blob = $this->_blob();
		return $blob ? $blob->delete_resized($size_key) : false;
	}

	// authenticate_write() is inherited from SystemBase — it applies the shared
	// owner-or-admin rule (is_owner_or_admin), the same rule is_viewable() uses for
	// private files. No File-specific override is needed.

	/**
	 * Strict ownership: does exactly this user own this file, and is it not
	 * deleted? This is the check for "the user is handing the system their OWN
	 * file" (attaching to an AI chat/recipe, consuming in a detached worker).
	 * It is deliberately narrower than is_viewable(): no admin bypass, no
	 * group/event/tier sharing — those mean "may view", not "owns" — and it
	 * needs no session, so it works in detached CLI workers and re-derives
	 * cleanly at send time (TOCTOU: a file reassigned, deleted, or swapped
	 * after attach fails here).
	 *
	 * @param int $user_id the owner to require (e.g. a run's owner id)
	 * @return bool
	 */
	function is_owned_by($user_id) {
		$user_id = (int)$user_id;
		if ($user_id <= 0 || !$this->key) {
			return false;
		}
		if ($this->get('fil_delete_time')) {
			return false;
		}
		return (int)$this->get('fil_usr_user_id') === $user_id;
	}

	/**
	 * Content-visibility gate for the file-serving path (serve.php) — NOT API row
	 * authorization. Returns bool: may this session view the file? For a private
	 * file (fil_private) the rule is owner-or-admin — the identical rule
	 * authenticate_read uses for the record, via the shared is_owner_or_admin()
	 * helper. Otherwise the four restriction columns apply (min-permission, group
	 * membership, event registration, tier gating). fil_private is an alternative
	 * to those columns, not combined with them. For "does the user OWN this
	 * file" — attachment/consumption checks — use is_owned_by() instead;
	 * "viewable" is strictly broader than "owned".
	 */
	/**
	 * Does $user_id hold a Drive access grant that reaches this file — directly,
	 * or via a grant on any ancestor folder? Delegates to the one grant-reach
	 * implementation in DriveHelper.
	 */
	private function _has_drive_grant($user_id) {
		$user_id = (int)$user_id;
		if ($user_id <= 0) {
			return false;
		}
		require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
		return DriveHelper::grant_reaches(DriveHelper::ENTITY_FILE, $this, $user_id, array('viewer', 'editor'));
	}

	function is_viewable($session){
		if(!$session){
			throw new SystemDisplayablePermanentError("Session is not present to authenticate.");
		}

		if($this->get('fil_delete_time')){
			return false;
		}

		if ($this->_is_private()) {
			if ($this->is_owner_or_admin($session->get_user_id(), $session->get_permission())) {
				return true;
			}
			// Drive sharing: a viewer/editor grant on this file, or on any ancestor
			// folder, also grants view of a private Drive file.
			return $this->_has_drive_grant($session->get_user_id());
		}

		if($this->get('fil_min_permission')){
			if (!$session->get_permission()) {
				return false;
			}
			if ($session->get_permission() < $this->get('fil_min_permission')){
				return false;
			}
	
		}	
	
		if ($group_id = $this->get('fil_grp_group_id')){
			require_once(PathHelper::getIncludePath('data/groups_class.php'));
			//CHECK TO SEE IF USER IS IN AUTHORIZED GROUP
			$group = new Group($group_id, TRUE);
			if(!$group->is_member_in_group($session->get_user_id())){
				return false;
			}
		}
		
		// Provider-based access gate (event registration, or any future gate kind).
		// Ungated → allowed; a gate whose provider is absent → denied (fail-closed).
		require_once(PathHelper::getIncludePath('includes/AccessGateRegistry.php'));
		if (!AccessGateRegistry::userMayAccess($this->get('fil_access_provider'), $this->get('fil_access_ref'), $session->get_user_id())){
			return false;
		}

		// Tier gating check
		if ($this->get('fil_tier_min_level')) {
			$tier_access = $this->authenticate_tier($session);
			if (!$tier_access['allowed']) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Permanently delete Drive items that have sat in the trash past the window.
	 *
	 * Files first: permanent_delete() releases the blob refcount, so the bytes
	 * only free once the last reference lets go. Folders follow.
	 *
	 * ONLY Drive files (fil_source = 'drive'). fil_files is platform-wide, and a
	 * soft-deleted avatar, store image or mail attachment belongs to its own
	 * subsystem's lifecycle — Drive trash retention must never destroy it.
	 *
	 * @param int $days  Retention window from the setting
	 * @return array     removed, message
	 */
	public static function purgeExpiredTrash($days) {
		require_once(PathHelper::getIncludePath('data/folders_class.php'));
		require_once(PathHelper::getIncludePath('data/drive_usage_class.php'));

		$dblink = DbConnector::get_instance()->get_db_link();
		$cutoff = "now() - (INTERVAL '1 day' * :days)";

		$qf = $dblink->prepare(
			"SELECT fil_file_id, fil_usr_user_id FROM fil_files
			  WHERE fil_delete_time IS NOT NULL AND fil_delete_time < $cutoff
			    AND fil_source = 'drive'");
		$qf->execute(array(':days' => (int)$days));
		$owners = array();
		$files_deleted = 0;
		foreach ($qf->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$file = new self((int)$row['fil_file_id'], true);
			if ($file->key) {
				$file->permanent_delete();
				$files_deleted++;
				$owners[(int)$row['fil_usr_user_id']] = true;
			}
		}

		// Then folders. Any file still under one is orphaned to root by the FK
		// 'null' rule, but every trashed descendant was already caught above.
		$qfo = $dblink->prepare(
			"SELECT fol_folder_id FROM fol_folders
			  WHERE fol_delete_time IS NOT NULL AND fol_delete_time < $cutoff");
		$qfo->execute(array(':days' => (int)$days));
		$folders_deleted = 0;
		foreach ($qfo->fetchAll(PDO::FETCH_COLUMN) as $fid) {
			$folder = new Folder((int)$fid, true);
			if ($folder->key) {
				$folder->permanent_delete();
				$folders_deleted++;
			}
		}

		foreach (array_keys($owners) as $uid) {
			DriveUsage::recompute($uid);
		}

		if ($files_deleted === 0 && $folders_deleted === 0) {
			return array('removed' => 0, 'message' => 'no trashed Drive items past the window');
		}
		return array(
			'removed' => $files_deleted + $folders_deleted,
			'message' => $files_deleted . ' file(s), ' . $folders_deleted . ' folder(s)',
		);
	}

}

class MultiFile extends SystemMultiBase {
	protected static $model_class = 'File';

	function get_file_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $file) {
			$items[$file->key] = '('.$file->key.') '.$file->get('fil_title');
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;
	}

	function get_image_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $file) {
			$items[$file->key] = '<span class="dropimagewidth"><img loading="lazy" src="'.$file->get_url('avatar').'"></span>('.$file->key.') '.$file->get('fil_title');
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;
	}
	
	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['user_id'])) {
			$filters['fil_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['group_id'])) {
			$filters['fil_grp_group_id'] = [$this->options['group_id'], PDO::PARAM_INT];
		}

		// Drive folder placement. An explicit NULL / 0 means the drive root
		// (IS NULL); a positive id means that folder's direct files.
		if (array_key_exists('folder_id', $this->options)) {
			$fid = $this->options['folder_id'];
			if ($fid === null || $fid === '' || (int)$fid === 0) {
				$filters['fil_fol_folder_id'] = 'IS NULL';
			} else {
				$filters['fil_fol_folder_id'] = [(int)$fid, PDO::PARAM_INT];
			}
		}

		// id-set restriction for "Shared with me" / grant listings.
		if (isset($this->options['file_ids']) && is_array($this->options['file_ids'])) {
			$ids = array_values(array_filter(array_map('intval', $this->options['file_ids'])));
			$filters['fil_file_id'] = empty($ids) ? 'IN (NULL)' : 'IN (' . implode(',', $ids) . ')';
		}

		// folder-set restriction: files directly inside any of these folders
		// (shared-folder search expands granted folders to their subtrees).
		if (isset($this->options['folder_ids']) && is_array($this->options['folder_ids'])) {
			$fids = array_values(array_filter(array_map('intval', $this->options['folder_ids'])));
			$filters['fil_fol_folder_id'] = empty($fids) ? 'IN (NULL)' : 'IN (' . implode(',', $fids) . ')';
		}

		// Drive filename search (v1): match the display title, case-insensitive.
		if (isset($this->options['title_like'])) {
			$dblink = DbConnector::get_instance()->get_db_link();
			$filters['fil_title'] = 'ILIKE ' . $dblink->quote('%' . $this->options['title_like'] . '%');
		}


		// 'picture' mirrors File::is_image(): the raster allowlist, not
		// LIKE 'image/%' (which would sweep in image/svg+xml — a file with no
		// size variants). Constant values only; nothing user-supplied.
		if (isset($this->options['picture'])) {
			$in_list = "('" . implode("','", File::INLINE_SAFE_TYPES) . "')";
			if ($this->options['picture']) {
				$filters['fil_type'] = "IN " . $in_list;
			} else {
				$filters['(fil_type'] = "NOT IN " . $in_list . " OR fil_type IS NULL)";
			}
		}

		if (isset($this->options['filename_like'])) {
			$filters['fil_name'] = 'ILIKE \'%'.$this->options['filename_like'].'%\'';
		}

		// Origin filter: match exactly one source.
		if (isset($this->options['source'])) {
			$filters['fil_source'] = [$this->options['source'], PDO::PARAM_STR];
		}

		// ------------------------------------------------------------------
		// Origin filters across SEVERAL sources, emitted as exactly ONE condition.
		//
		//   'sources'      allowlist — only these origins
		//   'sources_not'  blocklist — everything but these (e.g. a browse surface
		//                  passing File::internal_sources())
		//   'source_not'   the single-value form of the blocklist
		//
		// They MUST resolve to one filter entry. A grouped condition is written under
		// the split-parenthesis key '(fil_source', and $filters is an array — so two
		// of them would collide on that key and the later one would silently replace
		// the earlier, quietly widening a listing that asked to be narrowed.
		//
		// Combining them is a set operation, not a second SQL clause: an allowlist
		// with a blocklist is just the allowlist minus the blocked entries. NULL
		// (legacy/untagged) rows are never what a blocklist is naming, so they
		// survive an exclude — and they can only ENTER a result through an explicit
		// File::SOURCE_UNCLASSIFIED in the allowlist, because `fil_source IN (...)`
		// never matches NULL.
		// ------------------------------------------------------------------
		$exclude_sources = array();
		if (isset($this->options['source_not'])) {
			$exclude_sources[] = (string)$this->options['source_not'];
		}
		if (isset($this->options['sources_not'])) {
			foreach ((array)$this->options['sources_not'] as $source) {
				$exclude_sources[] = (string)$source;
			}
		}
		$exclude_sources = array_unique($exclude_sources);

		$has_include = isset($this->options['sources']);
		if ($has_include || !empty($exclude_sources)) {
			$dblink = DbConnector::get_instance()->get_db_link();

			if ($has_include) {
				// Allowlist, with any blocked entries subtracted from it.
				$want_null = false;
				$names = array();
				foreach ((array)$this->options['sources'] as $source) {
					$source = (string)$source;
					if ($source === File::SOURCE_UNCLASSIFIED) {
						$want_null = true;
						continue;
					}
					if (!in_array($source, $exclude_sources, true)) {
						$names[] = $source;
					}
				}
				$quoted = array();
				foreach (array_unique($names) as $source) {
					$quoted[] = $dblink->quote($source);
				}
				if (empty($quoted)) {
					// An empty allowlist matches nothing rather than everything, so a
					// caller that computed no sources gets no rows.
					$filters['fil_source'] = $want_null ? 'IS NULL' : 'IN (NULL)';
				} elseif ($want_null) {
					$filters['(fil_source'] = 'IN (' . implode(',', $quoted) . ') OR fil_source IS NULL)';
				} else {
					$filters['fil_source'] = 'IN (' . implode(',', $quoted) . ')';
				}
			} else {
				// Blocklist only.
				$quoted = array();
				foreach ($exclude_sources as $source) {
					$quoted[] = $dblink->quote($source);
				}
				$filters['(fil_source'] = 'NOT IN (' . implode(',', $quoted) . ') OR fil_source IS NULL)';
			}
		}

		return $this->_get_resultsv2('fil_files', $filters, $this->order_by, $only_count, $debug);
	}

}

?>
