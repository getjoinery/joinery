<?php
/**
 * DirectSpoolPart - one part of a spooled Joinery Direct delivery.
 *
 * Parts are stored individually rather than as one blob because that is the
 * whole point of splitting the envelope from the content: a body, an HTML
 * alternative, and each attachment cross the wire as their own sealed object,
 * so the structure survives encryption and the receiver can list, store and
 * preview each one at unlock without ever having flattened the message.
 *
 * The bytes here are exactly what arrived — sealed by the SENDER to the
 * recipient's vault key when a key was discoverable, plaintext-over-TLS when it
 * was not. Nothing here is ever opened before the recipient's unlock; the
 * per-part hash that proves the bytes were not substituted covers the
 * CIPHERTEXT, so a locked box verifies at receive rather than discovering a
 * substitution at unlock.
 *
 * A part large enough to be worth keeping off the row goes to the file store
 * (`jda_fil_file_id`); small parts ride inline, base64-encoded because the
 * platform's schema vocabulary has no binary column. Either way the reader is
 * DirectSpoolPart::bytes(), and neither shape ever reaches the wire — parts
 * transfer as raw bytes, which is exactly the base64 inflation Direct exists to
 * avoid.
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class DirectSpoolPartException extends SystemBaseException {}

class DirectSpoolPart extends SystemBase {
	public static $prefix = 'jda';

	function authenticate_read($data) {
		throw new SystemAuthenticationError('Direct spool parts are not readable through the API.');
	}

	function authenticate_write($data) {
		throw new SystemAuthenticationError('Direct spool parts are not writable through the API.');
	}

	public static $tablename = 'jda_direct_spool_parts';
	public static $pkey_column = 'jda_direct_spool_part_id';

	protected static $foreign_key_actions = array(
		'jda_jdp_direct_spool_id' => array('action' => 'cascade'),
		'jda_fil_file_id'         => array('action' => 'permanent_delete'),
	);

	public static $field_specifications = array(
		'jda_direct_spool_part_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
		'jda_jdp_direct_spool_id' => array('type'=>'int8', 'is_nullable'=>false, 'index'=>true,
			'foreign_key'=>array('table'=>'jdp_direct_spool', 'column'=>'jdp_direct_spool_id')),
		'jda_sequence'     => array('type'=>'int4', 'is_nullable'=>false, 'default'=>0),
		'jda_role'         => array('type'=>'varchar(20)', 'is_nullable'=>false),   // body_text|body_html|attachment
		'jda_content_type' => array('type'=>'varchar(255)', 'is_nullable'=>false, 'default'=>'application/octet-stream'),
		'jda_filename'     => array('type'=>'varchar(500)', 'is_nullable'=>true),
		'jda_content_id'   => array('type'=>'varchar(255)', 'is_nullable'=>true),
		'jda_is_inline'    => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		// True when the SENDER sealed these bytes to the recipient's vault key.
		'jda_is_sealed'    => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		'jda_bytes'        => array('type'=>'int8', 'is_nullable'=>false, 'default'=>0),
		// BLAKE2b-256 of the delivered (sealed) bytes, kept for audit — it was
		// already verified against the sender's signature before this row existed.
		'jda_hash'         => array('type'=>'varchar(64)', 'is_nullable'=>false, 'default'=>''),
		// Base64, because the platform's schema vocabulary has no binary column
		// and a staging row is the one place the inflation is harmless: it never
		// touches the wire (parts transfer as raw bytes — avoiding base64 is the
		// whole point there), and anything big enough for the third to matter is
		// over INLINE_MAX_BYTES and lives in the file store instead.
		'jda_content'      => array('type'=>'text', 'is_nullable'=>true),
		'jda_fil_file_id'  => array('type'=>'int8', 'is_nullable'=>true),
		'jda_create_time'  => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	public static $timestamp_fields = array('jda_create_time');

	/** Parts above this ride in the file store rather than on the row. */
	const INLINE_MAX_BYTES = 262144;

	/** The delivered bytes, wherever they were put. */
	public function bytes(): string {
		$file_id = intval($this->get('jda_fil_file_id'));
		if ($file_id > 0) {
			require_once(PathHelper::getIncludePath('data/files_class.php'));
			$file = new File($file_id, TRUE);
			if (!$file->key) {
				return '';
			}
			// read_bytes() rather than a filesystem path, so a blob the storage
			// layer has offloaded to the cloud bucket still reads.
			$raw = $file->read_bytes();
			return ($raw === null || $raw === false) ? '' : $raw;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('SELECT jda_content FROM jda_direct_spool_parts WHERE jda_direct_spool_part_id = ?');
		$stmt->execute(array(intval($this->key)));
		$value = $stmt->fetchColumn();
		if ($value === false || $value === null || $value === '') {
			return '';
		}
		$decoded = base64_decode((string)$value, true);
		return $decoded === false ? '' : $decoded;
	}

	/** Parts of one spooled delivery, in transfer order. */
	public static function forSpool(int $spool_id): array {
		// Transfer order is the contract: the commit's signed hash list is
		// positional, so a part read back out of order would fail verification
		// for a message nobody tampered with.
		$multi = new MultiDirectSpoolPart(array('spool_id' => $spool_id), array('jda_sequence' => 'ASC'));
		$multi->load();
		$out = array();
		foreach ($multi as $part) {
			$out[] = $part;
		}
		return $out;
	}
}

class MultiDirectSpoolPart extends SystemMultiBase {
	protected static $model_class = 'DirectSpoolPart';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['spool_id'])) {
			$filters['jda_jdp_direct_spool_id'] = array($this->options['spool_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['role'])) {
			$filters['jda_role'] = array((string)$this->options['role'], PDO::PARAM_STR);
		}

		return $this->_get_resultsv2('jda_direct_spool_parts', $filters, $this->order_by, $only_count, $debug);
	}
}
