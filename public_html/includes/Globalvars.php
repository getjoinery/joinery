<?php
require_once('PathHelper.php');

class Globalvars {
	private static $instance_map = array();
	private $settings;
	/** What the config file set, before any value read from the database joined $settings. */
	private $config_file_settings = array();
	/** True while a sealed secret setting is being opened (no re-entry). */
	private static $opening = false;
	/** True while a declared default is being looked up (no re-entry). */
	private static $resolving_default = false;
	/**
	 * Every stg_settings row as the table holds it, name => stored value (a
	 * sealed value still sealed), or null when not read yet. Only a process
	 * that reads the table whole fills it (see $whole_table).
	 */
	private static $stored = null;
	/**
	 * Whether the first setting asked of the database reads the whole table
	 * in one statement. A web request does: it reads dozens of settings and
	 * lives for milliseconds. A command-line process asks for each setting as
	 * it needs it, because a worker or an installer runs long enough to need
	 * a value another process wrote after it started.
	 */
	private static $whole_table = (PHP_SAPI !== 'cli');

	private function __construct() {
		require_once(dirname(__DIR__, 2).'/config/Globalvars_site.php');
		if (!empty($this->settings['webDir']) &&
			(str_starts_with($this->settings['webDir'], 'http://') || str_starts_with($this->settings['webDir'], 'https://'))) {
			$this->settings['webDir'] = preg_replace('#^https?://#', '', $this->settings['webDir']);
		}
		$this->config_file_settings = (array)$this->settings;
	}

	public static function get_instance(){
		$root_dir = PathHelper::getRootDir();
		// Check to see if we have a global vars instance for the particular root directory
		// this is being read from.
		if (!array_key_exists($root_dir, self::$instance_map)) {
			// If not, create the new one and add it to the array
			self::$instance_map[$root_dir] = new self;
		}
		return self::$instance_map[$root_dir];
	}

	/**
	 * Drop a memoized value so the next get_setting() reads the table again.
	 * For a process that writes a setting and then reads it back — an installer
	 * trying one provider after another — where a value cached before the write
	 * would otherwise answer for the rest of the process.
	 */
	public function forget_setting(string $setting): void {
		unset($this->settings[$setting]);
		self::$stored = null;
	}

	/**
	 * Drop the request's copy of the settings table, so the next setting asked
	 * of the database reads the table again. Called wherever the table may no
	 * longer be what was read: a statement that writes to it or a rollback
	 * (GuardedPdo), and a switch between the live and test databases
	 * (DbConnector). Values already handed out and remembered by name are not
	 * touched; forget_setting() drops one of those.
	 */
	public static function forget_stored_settings(): void {
		self::$stored = null;
	}

	/** Whether a copy of the settings table is held (forget_stored_settings() has something to drop). */
	public static function holds_stored_settings(): bool {
		return self::$stored !== null;
	}

	/**
	 * Choose how this process reads settings from the database: the whole
	 * table in one statement (true, what a web request does) or one statement
	 * per setting (false, the command line). For a command-line process that
	 * stands in for a web request: the request profiler, a test.
	 */
	public static function read_whole_table(bool $whole_table): void {
		self::$whole_table = $whole_table;
		self::$stored = null;
	}

	/**
	 * A value from the site's config file alone, or $default when the file does
	 * not set it. Never asks the database, so it is safe while the database
	 * link itself is being opened.
	 */
	public function config_file_setting(string $setting, $default = null) {
		return array_key_exists($setting, $this->config_file_settings) ? $this->config_file_settings[$setting] : $default;
	}

	/**
	 * The declared default of a number setting (settings.json or a plugin's
	 * manifest), or null when the setting is not a declared number or its
	 * default is blank. Reading the declarations can read settings, so a
	 * nested call answers null rather than recurse.
	 */
	private static function declaredNumberDefault(string $setting): ?string {
		if (self::$resolving_default || !class_exists('SettingsDeclarations')) {
			return null;
		}
		self::$resolving_default = true;
		try {
			$declaration = SettingsDeclarations::get($setting);
		} catch (\Throwable $e) {
			return null;
		} finally {
			self::$resolving_default = false;
		}
		if ($declaration === null || ($declaration['type'] ?? '') !== 'number') {
			return null;
		}
		$default = trim((string)($declaration['default'] ?? ''));
		return $default === '' ? null : $default;
	}

	public function get_setting($setting, $calculated_values=true, $fail_silently=false){
		$found = 0;
		if(isset($this->settings[$setting])){
			if($this->settings[$setting] || $this->settings[$setting] === 0 || $this->settings[$setting] === '0'){
				//FOUND A SETTING THAT IS NOT BLANK
				$found = 1;
				return $this->settings[$setting];
			}
			else{
				//FOUND A SETTING THAT IS BLANK
				$found = 1;
			}
		}
		
		// Anchored to this directory: a bare relative require executed inside a
		// method resolves against whichever file is executing at the time — during
		// a class-constant expression in data/ that is the data class, and there is
		// no data/DbConnector.php.
		require_once(__DIR__ . '/DbConnector.php');
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		// What the table holds for this setting, or null when it holds nothing.
		$stored_value = null;
		try{
			if (self::$whole_table) {
				if (self::$stored === null) {
					$stored = array();
					$q = $dblink->query('SELECT stg_name, stg_value FROM stg_settings');
					foreach ($q->fetchAll(PDO::FETCH_NUM) as $row) {
						$stored[$row[0]] = $row[1];
					}
					self::$stored = $stored;
				}
				$stored_value = self::$stored[$setting] ?? null;
			} else {
				$q = $dblink->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = :stg_name');
				$q->bindValue(':stg_name', $setting);
				$q->execute();
				$q->setFetchMode(PDO::FETCH_OBJ);
				$result = $q->fetch();
				$stored_value = isset($result->stg_value) ? $result->stg_value : null;
			}
		}
		catch(PDOException $e) {
			$dbhelper->handle_query_error($e);
		}

		if($stored_value !== null){
			$found = 1;
			$setting_value = $stored_value;
			// A declared secret is stored sealed (Setting::sealForStorage()):
			// it is read as what it holds, and one that can no longer be
			// opened reads as no value. Only a value shaped like a SecretBox
			// blob is looked at, and never while one is being opened.
			if (is_string($setting_value) && strncmp($setting_value, 'v1.', 3) === 0
					&& SecretBox::looksEncrypted($setting_value)) {
				// Read while another is being opened: answered as stored, never
				// kept, so it is opened properly on its next read.
				if (self::$opening) {
					return $setting_value;
				}
				self::$opening = true;
				try {
					$setting_value = Setting::openStored((string)$setting, $setting_value);
				} finally {
					self::$opening = false;
				}
				if ($setting_value === null) {
					return NULL;
				}
			}
			if($setting_value || $setting_value === 0 || $setting_value === '0'){
				//FOUND A SETTING THAT IS NOT BLANK
				$this->settings[$setting] = $setting_value;
				return $setting_value;
			}
			else{
				//FOUND A SETTING THAT IS BLANK
			}
		}
		else{
			//SKIP AHEAD, DIDN'T FIND ANYTHING IN THE DATABASE
		}
		
		if($calculated_values){
			//SPECIAL CASES OF DEFAULT CALCULATED SETTINGS.  USE THESE VALUES IF THEY ARE NOT SET IN THE CONFIG FILE OR DB
			if($setting == 'siteDir'){
				return $this->get_setting('baseDir') . $this->get_setting('site_template'). '/public_html';  
			}
			else if($setting == 'upload_dir'){
				return $this->get_setting('baseDir') . $this->get_setting('site_template'). '/uploads';  
			}
			else if($setting == 'upload_web_dir'){
				return 'uploads'; 
			}
			else if($setting == 'static_files_dir'){
				return $this->get_setting('baseDir') . $this->get_setting('site_template'). '/static_files'; 
			}
		}	
		
		// A declared number setting holds a number: blank or missing reads as
		// its declared default ('Blank means 14'), never as the 0 that (int)''
		// makes of it. Not memoized, so a later write is read at once.
		$declared_default = self::declaredNumberDefault((string)$setting);
		if ($declared_default !== null) {
			return $declared_default;
		}

		if(!$found){
			if(!$fail_silently){
				// Log this for monitoring/debugging
				error_log("Settings: Returning empty default for missing setting '{$setting}'");
			}
			// Return empty string (no caching)
			return '';
		}
		else{
			return NULL;
		}
	
	}
}

?>
