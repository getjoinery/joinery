<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class AgentFileException extends SystemBaseException {}

class AgentFile extends SystemBase {
	public static $prefix = 'agf';
	public static $tablename = 'agf_agent_files';
	public static $pkey_column = 'agf_agent_file_id';

	public static $ai_readable    = true;
	public static $ai_description = 'Agent instruction files (CLAUDE.md, GEMINI.md, etc.) managed in the database; written to disk on demand.';

	public static $field_specifications = array(
		'agf_agent_file_id'     => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'agf_name'              => array('type'=>'varchar(255)', 'required'=>true),
		'agf_target_filenames'  => array('type'=>'jsonb'),
		'agf_content'           => array('type'=>'text'),
		'agf_last_written_time' => array('type'=>'timestamp(6)'),
		'agf_last_written_hash' => array('type'=>'varchar(64)'),
		'agf_create_time'       => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'agf_delete_time'       => array('type'=>'timestamp(6)'),
	);

	public static $json_vars = array('agf_target_filenames');

	public function get_target_filenames_array() {
		$value = $this->get_json_decoded('agf_target_filenames');
		if (is_array($value)) {
			return array_values($value);
		}
		return array();
	}

	public static function validate_target_filename($filename) {
		if (!is_string($filename) || $filename === '') {
			throw new AgentFileException('Target filename must be a non-empty string.');
		}
		if (strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
			throw new AgentFileException('Target filename "'.$filename.'" cannot contain directory separators.');
		}
		if ($filename === '.' || $filename === '..' || strpos($filename, '..') !== false) {
			throw new AgentFileException('Target filename "'.$filename.'" cannot contain ".." segments.');
		}
		if (strpos($filename, "\0") !== false) {
			throw new AgentFileException('Target filename cannot contain NUL bytes.');
		}
	}

	protected function validate_target_filenames_unique() {
		$my_targets = $this->get_target_filenames_array();
		if (empty($my_targets)) {
			return;
		}

		$db = DbConnector::get_instance()->get_db_link();
		$sql = "SELECT agf_agent_file_id, agf_name, agf_target_filenames
				FROM agf_agent_files
				WHERE agf_delete_time IS NULL";
		$params = array();
		if ($this->key) {
			$sql .= " AND agf_agent_file_id <> ?";
			$params[] = $this->key;
		}
		$q = $db->prepare($sql);
		$q->execute($params);

		while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
			$raw = $row['agf_target_filenames'];
			if ($raw === NULL || $raw === '') continue;
			$other_targets = is_array($raw) ? $raw : json_decode($raw, true);
			if (!is_array($other_targets)) continue;

			$collisions = array_intersect($my_targets, $other_targets);
			if (!empty($collisions)) {
				throw new AgentFileException(
					'Target filename(s) "'.implode(', ', $collisions).
					'" already used by agent file "'.$row['agf_name'].'".'
				);
			}
		}
	}

	public function prepare() {
		$targets = $this->get_target_filenames_array();
		foreach ($targets as $filename) {
			self::validate_target_filename($filename);
		}
		$this->validate_target_filenames_unique();
	}

	public function save($debug = false) {
		$this->prepare();

		// Determine whether this save should produce a new content version snapshot.
		$current_content  = (string)$this->get('agf_content');
		$previous_content = '';
		$needs_version    = false;
		if ($this->key) {
			try {
				$previous         = new AgentFile($this->key, true);
				$previous_content = (string)$previous->get('agf_content');
				if ($previous_content !== $current_content) {
					$needs_version = true;
				}
			} catch (\Throwable $e) {
				// If the previous can't be loaded, skip versioning rather than blocking the save.
			}
		} elseif ($current_content !== '') {
			$needs_version = true;
		}

		$dropped = $this->compute_dropped_targets();

		$result = parent::save($debug);

		if ($needs_version && $this->key && class_exists('ContentVersion')) {
			try {
				$description = ($previous_content === '') ? 'Created' : 'Updated';
				ContentVersion::NewVersion(
					ContentVersion::TYPE_AGENT_FILE,
					$this->key,
					$current_content,
					$description
				);
			} catch (\Throwable $e) {
				// Versioning is a side effect — don't fail the save if it errors.
				error_log('AgentFile content version save failed: ' . $e->getMessage());
			}
		}

		foreach ($dropped as $filename) {
			$this->unlink_target_file($filename);
		}

		return $result;
	}

	protected function compute_dropped_targets() {
		if (!$this->key) {
			return array();
		}
		try {
			$previous = new AgentFile($this->key, true);
		} catch (\Throwable $e) {
			return array();
		}
		$previous_targets = $previous->get_target_filenames_array();
		$current_targets  = $this->get_target_filenames_array();
		return array_values(array_diff($previous_targets, $current_targets));
	}

	public function soft_delete() {
		$targets = $this->get_target_filenames_array();
		$result  = parent::soft_delete();
		if ($result) {
			foreach ($targets as $filename) {
				$this->unlink_target_file($filename);
			}
		}
		return $result;
	}

	protected function unlink_target_file($filename) {
		try {
			self::validate_target_filename($filename);
		} catch (\Throwable $e) {
			return;
		}
		$full_path = self::get_project_root() . '/' . $filename;
		if (file_exists($full_path)) {
			@unlink($full_path);
		}
	}

	public function write_to_disk() {
		if ($this->get('agf_delete_time')) {
			throw new AgentFileException('Cannot write to disk: this agent file is deleted.');
		}

		$targets = $this->get_target_filenames_array();
		if (empty($targets)) {
			throw new AgentFileException('Cannot write to disk: no target filenames configured.');
		}

		$content      = (string)$this->get('agf_content');
		$project_root = self::get_project_root();

		foreach ($targets as $filename) {
			self::validate_target_filename($filename);
			$full_path = $project_root . '/' . $filename;
			$tmp_path  = $full_path . '.tmp.' . getmypid();

			if (file_put_contents($tmp_path, $content) === false) {
				throw new AgentFileException('Failed to write temp file for "'.$filename.'".');
			}
			if (!rename($tmp_path, $full_path)) {
				@unlink($tmp_path);
				throw new AgentFileException('Failed to rename temp file to "'.$filename.'".');
			}
			@chmod($full_path, 0666);
		}

		$this->set('agf_last_written_time', 'now()');
		$this->set('agf_last_written_hash', hash('sha256', $content));
		parent::save();
	}

	public function disk_sync_status() {
		$targets = $this->get_target_filenames_array();
		if (empty($targets) || !$this->get('agf_last_written_time')) {
			return 'never';
		}
		$current_hash = hash('sha256', (string)$this->get('agf_content'));
		$stored_hash  = $this->get('agf_last_written_hash');
		if ($stored_hash !== $current_hash) {
			return 'differs';
		}
		$project_root = self::get_project_root();
		foreach ($targets as $filename) {
			$full_path = $project_root . '/' . $filename;
			if (!file_exists($full_path)) {
				return 'missing';
			}
			if (hash_file('sha256', $full_path) !== $stored_hash) {
				return 'differs';
			}
		}
		return 'matches';
	}

	public static function get_project_root() {
		return PathHelper::getRootDir();
	}

	public function authenticate_write($data) {
		if ($data['current_user_permission'] < 10) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '.static::$tablename
			);
		}
	}
}

class MultiAgentFile extends SystemMultiBase {
	protected static $model_class = 'AgentFile';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['deleted'])) {
			$filters['agf_delete_time'] = $this->options['deleted'] ? 'IS NOT NULL' : 'IS NULL';
		}

		if (isset($this->options['written'])) {
			$filters['agf_last_written_time'] = $this->options['written'] ? 'IS NOT NULL' : 'IS NULL';
		}

		if (isset($this->options['name'])) {
			$filters['agf_name'] = array($this->options['name'], PDO::PARAM_STR);
		}

		return $this->_get_resultsv2('agf_agent_files', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
