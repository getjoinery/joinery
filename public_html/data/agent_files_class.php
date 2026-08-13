<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));
require_once(PathHelper::getIncludePath('data/content_versions_class.php'));

class AgentFileException extends SystemBaseException {}

/**
 * Thrown by write_to_disk() when a target file on disk has been edited since
 * it was last written from the database — overwriting would discard those
 * edits. Carries the list of drifted filenames so callers can report them.
 */
class AgentFileDriftException extends AgentFileException {
	protected $drifted_files;

	public function __construct($message, array $drifted_files = array()) {
		parent::__construct($message);
		$this->drifted_files = $drifted_files;
	}

	public function get_drifted_files() {
		return $this->drifted_files;
	}
}

class AgentFile extends SystemBase {
	public static $prefix = 'agf';
	public static $tablename = 'agf_agent_files';
	public static $pkey_column = 'agf_agent_file_id';

	public static $ai_readable    = true;
	public static $ai_description = 'Agent instruction files (CLAUDE.md, GEMINI.md, etc.) managed in the database; written to disk on demand.';

	const CUSTOMER_BASELINE_NAME = 'Customer baseline';
	const TEMPLATE_PATH = 'maintenance_scripts/install_tools/default_agents_template.md';

	protected static $foreign_key_actions = array(
		// Self-referential (a candidate row points at the file it would replace),
		// so it doesn't fit the {prefix}_{target_prefix}_..._id convention.
		// permanent_delete so a candidate's own dependents (however unlikely a
		// chain is) delete through their rules rather than a flat SQL delete.
		'agf_candidate_for' => array('action' => 'permanent_delete', 'source_table' => 'agf_agent_files'),
	);

	public static $field_specifications = array(
		'agf_agent_file_id'           => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'agf_name'                    => array('type'=>'varchar(255)', 'required'=>true),
		'agf_target_filenames'        => array('type'=>'jsonb'),
		'agf_content'                 => array('type'=>'text'),
		'agf_last_written_time'       => array('type'=>'timestamp(6)'),
		'agf_last_written_hash'       => array('type'=>'varchar(64)'),
		'agf_template_baseline_hash'  => array('type'=>'varchar(64)'),
		'agf_candidate_for'           => array('type'=>'int4'),
		'agf_create_time'             => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'agf_delete_time'             => array('type'=>'timestamp(6)'),
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

		if ($needs_version && $this->key) {
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

	/**
	 * Returns the target filenames whose on-disk copy no longer matches the
	 * hash recorded at the last write — i.e. they were edited out of band.
	 * Empty if the row has never been written (nothing on disk to protect).
	 */
	public function get_drifted_targets() {
		$stored_hash = $this->get('agf_last_written_hash');
		if (!$stored_hash) {
			return array();
		}
		$project_root = self::get_project_root();
		$drifted = array();
		foreach ($this->get_target_filenames_array() as $filename) {
			try {
				self::validate_target_filename($filename);
			} catch (\Throwable $e) {
				continue;
			}
			$full_path = $project_root . '/' . $filename;
			if (file_exists($full_path) && hash_file('sha256', $full_path) !== $stored_hash) {
				$drifted[] = $filename;
			}
		}
		return $drifted;
	}

	public function write_to_disk($force = false) {
		if ($this->get('agf_delete_time')) {
			throw new AgentFileException('Cannot write to disk: this agent file is deleted.');
		}

		$targets = $this->get_target_filenames_array();
		if (empty($targets)) {
			throw new AgentFileException('Cannot write to disk: no target filenames configured.');
		}

		// Drift guard: a target whose on-disk copy no longer matches what we
		// last wrote has been edited out of band. Unless forced, refuse and
		// let the caller decide — the admin UI prompts the user, the upgrade
		// regenerate step skips the row and warns.
		$drifted = $this->get_drifted_targets();
		if (!empty($drifted) && !$force) {
			throw new AgentFileDriftException(
				'On-disk edits to ' . implode(', ', $drifted) . ' would be overwritten.',
				$drifted
			);
		}

		$content      = (string)$this->get('agf_content');
		$project_root = self::get_project_root();
		$drifted_set  = array_flip($drifted);

		foreach ($targets as $filename) {
			self::validate_target_filename($filename);
			$full_path = $project_root . '/' . $filename;
			$tmp_path  = $full_path . '.tmp.' . getmypid();

			// Forced overwrite of a drifted file: keep the current on-disk
			// copy alongside it as <filename>.old so the edits aren't lost.
			if ($force && isset($drifted_set[$filename]) && file_exists($full_path)) {
				@copy($full_path, $full_path . '.old');
				@chmod($full_path . '.old', 0666);
			}

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

	// ------------------------------------------------------------------
	// Template upgrade machinery (see specs/agent_files_upgrade_strategy.md)
	// ------------------------------------------------------------------

	/**
	 * Normalize content for hashing: trim trailing whitespace on each line
	 * and force `\n` endings. Keeps `\r\n` vs `\n` content from hashing
	 * differently when the bytes are semantically identical.
	 */
	public static function normalize_content($content) {
		$content = (string)$content;
		// Collapse \r\n and lone \r to \n
		$content = str_replace("\r\n", "\n", $content);
		$content = str_replace("\r", "\n", $content);
		// Trim trailing whitespace per line
		$lines = explode("\n", $content);
		foreach ($lines as $i => $line) {
			$lines[$i] = rtrim($line, " \t");
		}
		return implode("\n", $lines);
	}

	/** SHA-256 of normalized content. */
	public static function hash_content($content) {
		return hash('sha256', self::normalize_content($content));
	}

	/** True iff content hash matches baseline hash (i.e. unedited). */
	public function is_unmodified_from_baseline() {
		$baseline = $this->get('agf_template_baseline_hash');
		if (!$baseline) {
			return false; // legacy / unknown state — treat as edited
		}
		return self::hash_content($this->get('agf_content')) === $baseline;
	}

	/** Pending candidate for this active row, or null. */
	public function current_candidate() {
		if (!$this->key) {
			return null;
		}
		$results = new MultiAgentFile(array(
			'deleted' => false,
			'candidate_for' => (int)$this->key,
		));
		$results->load();
		if (count($results)) {
			return $results->get(0);
		}
		return null;
	}

	/**
	 * Promote a candidate row: move target filenames from this active row
	 * to the candidate, archive the previously-active row, write the new
	 * active row to disk.
	 *
	 * Calling row must be the active row (i.e. has targets). The candidate
	 * row must be this row's current_candidate(). Throws otherwise.
	 */
	public function switch_to_candidate() {
		$candidate = $this->current_candidate();
		if (!$candidate) {
			throw new AgentFileException('No candidate to switch to.');
		}

		$old_targets = $this->get_target_filenames_array();

		// Move targets onto the candidate; clear them on the previously-active row.
		$candidate->set('agf_target_filenames', json_encode(array_values($old_targets)));
		$candidate->set('agf_candidate_for', null);
		$candidate->save();

		// Archive the previously-active row.
		$archive_name = $this->get('agf_name');
		if (strpos($archive_name, 'Archived — ') !== 0) {
			$archive_name = 'Archived — ' . $archive_name;
		}
		$this->set('agf_name', $archive_name);
		$this->set('agf_target_filenames', json_encode(array()));
		$this->save();

		// The candidate already has the on-disk hash logic for its targets via write_to_disk(),
		// which detects drift against the disk's old content (written by the prev active row).
		// We force here because the on-disk file is intentionally being replaced by the new
		// content the admin chose to switch to.
		$candidate->write_to_disk(true);

		return $candidate;
	}

	/**
	 * Load the template file from disk and return [content, sha256_hash].
	 * Returns [null, null] if the template file is missing or unreadable.
	 *
	 * The template lives at maintenance_scripts/install_tools/, which is
	 * outside public_html — anchor to site root, not project root.
	 */
	public static function load_shipped_template() {
		$path = PathHelper::getSiteRoot() . '/' . self::TEMPLATE_PATH;
		if (!is_readable($path)) {
			return array(null, null);
		}
		$content = @file_get_contents($path);
		if ($content === false) {
			return array(null, null);
		}
		return array($content, self::hash_content($content));
	}
}

class MultiAgentFile extends SystemMultiBase {
	protected static $model_class = 'AgentFile';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();


		if (isset($this->options['written'])) {
			$filters['agf_last_written_time'] = $this->options['written'] ? 'IS NOT NULL' : 'IS NULL';
		}

		if (isset($this->options['name'])) {
			$filters['agf_name'] = array($this->options['name'], PDO::PARAM_STR);
		}

		if (isset($this->options['candidate_for'])) {
			$filters['agf_candidate_for'] = array((int)$this->options['candidate_for'], PDO::PARAM_INT);
		}

		if (isset($this->options['candidates_only'])) {
			$filters['agf_candidate_for'] = $this->options['candidates_only'] ? 'IS NOT NULL' : 'IS NULL';
		}

		return $this->_get_resultsv2('agf_agent_files', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
