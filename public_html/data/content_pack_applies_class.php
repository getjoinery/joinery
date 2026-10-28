<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

/**
 * One content pack on this site: an upload, or a pack a plugin ships. It holds
 * the file, who checked it, and, once applied, the plan, what was ticked and
 * every field's value before and after. An upload's row is made at check time
 * (status 'checked'), so a pack that was looked at and never applied is on
 * record too; a plugin's pack gets its row when it is applied or fails.
 *
 * See specs/content_pack_feature.md and includes/ContentPack.php.
 */
class ContentPackApply extends SystemBase {
	public static $prefix = 'cpa';
	public static $tablename = 'cpa_content_pack_applies';
	public static $access_class = 'keys';
	// The user column records who acted; the row is a log, not theirs.
	public static $owner_column = false;
	public static $pkey_column = 'cpa_content_pack_apply_id';

	const STATUS_CHECKED = 'checked';
	const STATUS_APPLIED = 'applied';
	const STATUS_FAILED  = 'failed';

	protected static $foreign_key_actions = array(
		'cpa_usr_user_id' => array('action' => 'set_value', 'value' => User::USER_DELETED),
	);

	public static $field_specifications = array(
		'cpa_content_pack_apply_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'cpa_usr_user_id'           => array('type'=>'int4'),
		'cpa_pack_name'             => array('type'=>'varchar(100)', 'required'=>true),
		'cpa_pack_version'          => array('type'=>'varchar(50)'),
		// 'upload' for a pack checked on the Content Packs page; 'plugin:{name}/{folder}'
		// for one a plugin ships, applied when the plugin is installed.
		'cpa_source'                => array('type'=>'varchar(150)', 'default'=>'upload'),
		'cpa_file_sha256'           => array('type'=>'varchar(64)', 'required'=>true),
		// The pack as received, base64: the uploaded zip, or for a plugin's pack the
		// JSON of its folder's files by name. A pack holds no credential.
		'cpa_file'                  => array('type'=>'text', 'required'=>true),
		'cpa_status'                => array('type'=>'varchar(20)', 'required'=>true),
		'cpa_plan'                  => array('type'=>'jsonb'),
		'cpa_ticks'                 => array('type'=>'jsonb'),
		'cpa_result'                => array('type'=>'jsonb'),
		'cpa_error'                 => array('type'=>'text'),
		'cpa_create_time'           => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'cpa_applied_time'          => array('type'=>'timestamp(6)'),
	);

	/** The stored zip's bytes. */
	public function file_bytes(): string {
		return (string)base64_decode((string)$this->get('cpa_file'), true);
	}
}

class MultiContentPackApply extends SystemMultiBase {
	protected static $model_class = 'ContentPackApply';

	protected function getMultiResults($only_count = false, $debug = false) {
		$sorts = !empty($this->order_by) ? $this->order_by : array('cpa_content_pack_apply_id' => 'DESC');
		return $this->_get_resultsv2('cpa_content_pack_applies', array(), $sorts, $only_count, $debug);
	}
}
