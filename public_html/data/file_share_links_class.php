<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

/**
 * FileShareLink — a durable, revocable public link that grants VIEW of a Drive
 * file or folder (fsl_file_share_links).
 *
 * The raw token is shown once at creation and never stored (only its SHA-256).
 * A live link (not expired, not revoked, password satisfied) lets an anonymous
 * visitor view the entity: folders render a read-only listing, files stream via
 * a short-lived signed URL. The link is the durable grant; the signed URL stays
 * the transport.
 *
 * @version 1.0.0
 */
class FileShareLink extends SystemBase {
	public static $prefix = 'fsl';
	public static $tablename = 'fsl_file_share_links';
	public static $pkey_column = 'fsl_file_share_link_id';

	protected static $foreign_key_actions = array(
		'fsl_usr_user_id' => array('action' => 'cascade'),
	);

	public static $field_specifications = array(
		'fsl_file_share_link_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true),
		'fsl_entity_type'        => array('type' => 'varchar(16)', 'is_nullable' => false, 'required' => true, 'index_with' => array('fsl_entity_id')),
		'fsl_entity_id'          => array('type' => 'int8', 'is_nullable' => false, 'required' => true),
		'fsl_token_sha256'       => array('type' => 'varchar(64)', 'is_nullable' => false, 'required' => true, 'unique' => true),
		'fsl_usr_user_id'        => array('type' => 'int4', 'is_nullable' => false, 'required' => true),
		'fsl_expires_time'       => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'fsl_password_hash'      => array('type' => 'varchar(255)', 'is_nullable' => true),
		'fsl_revoked_time'       => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'fsl_access_count'       => array('type' => 'int4', 'is_nullable' => false, 'default' => 0, 'zero_on_create' => true),
		'fsl_create_time'        => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
	);

	/**
	 * Mint a new share link. Returns array('link' => FileShareLink, 'token' =>
	 * raw token). The raw token is returned only here.
	 */
	public static function mint($entity_type, $entity_id, $user_id, $expires_time = null, $password = null) {
		$raw = bin2hex(random_bytes(24));
		$link = new FileShareLink(NULL);
		$link->set('fsl_entity_type', $entity_type);
		$link->set('fsl_entity_id', (int)$entity_id);
		$link->set('fsl_token_sha256', hash('sha256', $raw));
		$link->set('fsl_usr_user_id', (int)$user_id);
		if ($expires_time) {
			$link->set('fsl_expires_time', $expires_time);
		}
		if ($password !== null && $password !== '') {
			$link->set('fsl_password_hash', password_hash($password, PASSWORD_DEFAULT));
		}
		$link->set('fsl_access_count', 0);
		$link->save();
		$link->load();
		return array('link' => $link, 'token' => $raw);
	}

	/** Load a live-or-dead link by its raw token, or null. */
	public static function load_by_token($raw_token) {
		if (!is_string($raw_token) || $raw_token === '') {
			return null;
		}
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare("SELECT fsl_file_share_link_id FROM fsl_file_share_links WHERE fsl_token_sha256 = ? LIMIT 1");
		$q->execute(array(hash('sha256', $raw_token)));
		$id = $q->fetchColumn();
		return $id === false ? null : new self((int)$id, true);
	}

	/** Not revoked and not past expiry. */
	public function is_live() {
		if ($this->get('fsl_revoked_time')) {
			return false;
		}
		$exp = $this->get('fsl_expires_time');
		if ($exp && $exp < gmdate('Y-m-d H:i:s')) {
			return false;
		}
		return true;
	}

	public function requires_password() {
		return (string)$this->get('fsl_password_hash') !== '';
	}

	public function check_password($password) {
		if (!$this->requires_password()) {
			return true;
		}
		return password_verify((string)$password, (string)$this->get('fsl_password_hash'));
	}

	public function revoke() {
		$this->set('fsl_revoked_time', gmdate('Y-m-d H:i:s'));
		$this->save();
	}

	public function record_access() {
		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare("UPDATE fsl_file_share_links SET fsl_access_count = fsl_access_count + 1 WHERE fsl_file_share_link_id = ?");
		$q->execute(array((int)$this->key));
	}
}

class MultiFileShareLink extends SystemMultiBase {
	protected static $model_class = 'FileShareLink';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['user_id'])) {
			$filters['fsl_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['entity_type'])) {
			$filters['fsl_entity_type'] = array($this->options['entity_type'], PDO::PARAM_STR);
		}
		if (isset($this->options['entity_id'])) {
			$filters['fsl_entity_id'] = array($this->options['entity_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['active'])) {
			$filters['fsl_revoked_time'] = $this->options['active'] ? 'IS NULL' : 'IS NOT NULL';
		}

		return $this->_get_resultsv2('fsl_file_share_links', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
