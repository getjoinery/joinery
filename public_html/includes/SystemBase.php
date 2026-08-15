<?php
require_once('PathHelper.php');
require_once('SqlBuilder.php');
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));


interface CustomErrorPage {
	function display_error_page();
}

// An error message that is displayable and can be fixed by the user
interface DisplayableErrorMessage {}
interface DisplayableErrorMessageNoLog {}

// A displayable error message that cannot be fixed by the user
interface DisplayablePermanentErrorMessage {}
interface DisplayablePermanentErrorMessageNoLog {}

class SystemBaseException extends Exception {}

class SystemInvalidFormError extends SystemBaseException {}

class SystemBaseNoKeyError extends SystemBaseException {}

class SystemAuthenticationError extends SystemBaseException {}

class SystemDisplayableError extends SystemBaseException implements DisplayableErrorMessage {}

class SystemDisplayablePermanentError extends SystemBaseException implements DisplayablePermanentErrorMessage {}

class SystemDisplayableErrorNoLog extends SystemBaseException implements DisplayableErrorMessageNoLog {}

class SystemDisplayablePermanentErrorNoLog extends SystemBaseException implements DisplayablePermanentErrorMessageNoLog {}

abstract class SystemBase {
	
	public $key;
	protected $data;
	protected $loaded;
	protected $cached_references;

	static $constants = array();
	static $required = array();
	static $required_user = array();

	/**
	 * Every action name permanent_delete() knows how to execute. Anything outside
	 * this set is rejected — at rule-registration time by DeletionRule, and again
	 * at delete time by permanent_delete(). See docs/deletion_system.md.
	 */
	public static $valid_deletion_actions = array(
		'cascade', 'permanent_delete', 'null', 'set_value', 'prevent'
	);

	/**
	 * The universal "unreadable floor": fields that must never leave the server over
	 * ANY API surface (REST or AI). Two parts, both honored by is_unreadable_field():
	 *
	 *   1. CREDENTIAL_FIELD_PATTERN — credential-suffixed names are blocked
	 *      automatically, so a new *_password / *_secret / *_key / *_token /
	 *      *_hash column is protected the moment it is added, with no list edit.
	 *   2. $api_unreadable_fields — an explicit per-model list for genuine secrets whose
	 *      names do NOT match the pattern (e.g. usr_authhash, usr_remember_tokens).
	 *
	 * This is the single source of truth for "is this a secret." Per-surface
	 * trims that are about relevance/noise rather than secrecy (e.g. the AI's
	 * $ai_excluded_fields) layer on TOP of this floor, never replace it.
	 */
	const CREDENTIAL_FIELD_PATTERN = '/_(password|secret|key|token|hash)$/i';
	public static $api_unreadable_fields = array();

	/**
	 * The derived-key allowlist for the fail-closed API export. export_for_api()
	 * emits a non-column key an export_as_array() override injects ONLY if it is
	 * named here, so a computed key (e.g. display_name) reaches the API by explicit
	 * opt-in, never by accident. Default empty: an override that adds no entry here
	 * exposes none of its derived keys over the API. See export_for_api().
	 */
	public static $api_derived_fields = array();

	/**
	 * Layer 1 — resource exposure (REST CRUD). A model is a CRUD resource only if
	 * it opts in. Both default false: existing simply means nothing. Read and write
	 * are separate so a model can be read-only ($api_readable = true; $api_writable
	 * = false). The apiv1 dispatcher filters the discovered class list on these.
	 */
	public static $api_readable = false;
	public static $api_writable = false;

	/**
	 * Layer 2 — public read. When true, the model's rows are world-readable over the
	 * CRUD API: the REST read surface skips the per-record authenticate_read scope AND
	 * the §4.5 collection owner-filter (public catalog content — Events, Posts, Pages —
	 * is the same for everyone). When false (the default), reads are owner-or-staff and
	 * owner-column collections are owner-scoped. This is the single, queryable fact that
	 * says "this resource is public," used by both the row gate and the collection scope.
	 */
	public static $api_public_read = false;

	/**
	 * Layer 3 (write) — the unwritable floor, the exact mirror of $api_unreadable_fields.
	 * Privileged, non-credential columns that must never be set through a CRUD/AI write
	 * (e.g. usr_permission). Credentials are caught automatically by CREDENTIAL_FIELD_PATTERN
	 * via is_unwritable_field(), so they need not be listed here. Shared by the REST write
	 * boundary (dropped from input) and the AI write surface (stripped from $ai_writable_fields).
	 */
	public static $api_unwritable_fields = array();

	/**
	 * Sealed Vault (docs/sealed_vault.md) generic content hook. A consumer with
	 * fields sealed under the vault lists their column names here; add the
	 * four convention columns ({prefix}_content_sealed, {prefix}_sealed_key,
	 * {prefix}_sealed_owner_user_id, {prefix}_key_generation) and reading and
	 * writing both work with no crypto code of your own — set()/save() seals,
	 * get() decrypts.
	 *
	 * Models whose owner is indirect, or whose columns are content only on some
	 * rows, override sealedOwnerUserIdFor() / sealedFieldIsActive() rather than
	 * the decrypt hooks themselves. Every other model leaves this empty and pays
	 * nothing: get() only takes the decrypt path for a listed field name.
	 */
	public static $sealed_fields = array();

	/**
	 * Whether save() seals this model's $sealed_fields itself.
	 *
	 * On by default, because a model that declares sealed fields wants its
	 * content sealed and should not have to write a ceremony to get it. A
	 * consumer that already owns its own sealing path — one that seals blobs
	 * under the same DEK, or decides per row in code that predates this — sets
	 * this false and keeps calling sealColumns() directly.
	 *
	 * WHETHER a given row seals is a separate question and belongs to
	 * shouldSeal(): the same table holds a protected member's sealed rows and an
	 * unprotected member's plaintext ones.
	 */
	public static $seal_on_save = true;

	/**
	 * @var array<string,bool> sealed columns this instance was handed plaintext
	 * for, as a set. There is no general dirty tracking on SystemBase and this is
	 * not it — it exists only to tell caller-supplied plaintext apart from the
	 * ciphertext sitting in $this->data after a load, which is the one
	 * distinction the sealing path cannot make by looking at the value.
	 */
	protected $sealed_dirty = array();

	/**
	 * @var array{0:mixed,1:bool}|null memo of [row id, sealed?] as the DATABASE
	 * answers it — see rowIsSealedInDb(). The in-memory flag can be stale (an
	 * instance loaded before another process sealed its row), and trusting it in
	 * save() is how a stale instance overwrites a live key wrapping. Cleared on
	 * load() and refreshed when applySealOnSave() changes the answer itself.
	 */
	protected $db_seal_state = null;

	/**
	 * Set true only around a write the server itself initiated while serving a
	 * page view. See assert_not_get_mutation(). Prefer server_initiated_write(),
	 * which owns the flag and always resets it; setting it by hand is for the
	 * one handler whose guarded region is a whole function body.
	 */
	public static $allow_get_mutation = false;

	/**
	 * Persist something the SERVER decided to write while serving a page view.
	 *
	 * ═══════════════════════════════════════════════════════════════════════
	 *  DO NOT REACH FOR THIS TO MAKE A SAVE WORK.
	 *
	 *  If a save is being refused during a page view, the answer is almost
	 *  always that the thing doing the saving should be a POST — not that it
	 *  needs this. Using it to silence the refusal converts a caught bug into
	 *  a shipped one.
	 * ═══════════════════════════════════════════════════════════════════════
	 *
	 * A page view must not persist what a USER asked for. That rule exists
	 * because a misfiring submission guard otherwise saves a record simply
	 * because someone opened the edit page — and because a link is a GET, and a
	 * browser performs a GET whenever it is told to, including by another site,
	 * carrying the session cookie along. Anything a user triggers is therefore
	 * a POST button (AdminPage::action_button, or an `altlinks` entry
	 * describing a `post`), never a link.
	 *
	 * This is the exception for writes with no user behind them at all. The
	 * test is not "is this write deliberate?" — every write is deliberate. It
	 * is "would this still happen if nobody had asked for anything?" Four
	 * shapes qualify, and they are the only ones in the tree:
	 *
	 *   - Observation: an error or a request recorded, usage tracked. The page
	 *     would render identically without it.
	 *   - Reconciliation: a local row brought into line with a remote fact the
	 *     server just fetched anyway.
	 *   - Third-party redirect: a payment gateway or OAuth provider sending the
	 *     browser back, where persisting the result IS the request's purpose.
	 *   - Lazy processing: work a scheduled task would have done, done now
	 *     because someone happened to look.
	 *
	 * A user clicking something is NONE of these, however much it looks like a
	 * side effect from inside the function that performs it.
	 *
	 * The callers are enumerated in tests/unit/core_api_mechanical_test.php,
	 * which fails on a new one. That is deliberate: adding a caller should be a
	 * decision someone made on purpose, not a line that slipped in.
	 *
	 * @param callable $action
	 * @return mixed Whatever $action returns.
	 */
	public static function server_initiated_write(callable $action) {
		$previous = self::$allow_get_mutation;
		self::$allow_get_mutation = true;
		try {
			return $action();
		} finally {
			self::$allow_get_mutation = $previous;
		}
	}

	/**
	 * Targeted single-row UPDATE of exactly the given columns — never a full-row
	 * save(). For rows some other path writes behind the model's back (sealed
	 * content columns, adopted attachment bytes), where a full save() from a
	 * stale in-memory object would clobber what it did not read. Callers that
	 * only need to flip a few columns use this instead. Unknown column names are
	 * dropped rather than built into SQL. $columns maps column name => value
	 * (null allowed).
	 */
	public static function updateColumns(int $id, array $columns): void {
		if ($id <= 0 || empty($columns)) {
			return;
		}
		$sets = array();
		$params = array();
		foreach ($columns as $col => $value) {
			if (!array_key_exists($col, static::$field_specifications)) {
				continue; // never build SQL from an unknown column name
			}
			$sets[] = $col . ' = ?';
			$params[] = $value;
		}
		if (empty($sets)) {
			return;
		}
		$params[] = $id;
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			'UPDATE ' . static::$tablename . ' SET ' . implode(', ', $sets)
			. ' WHERE ' . static::$pkey_column . ' = ?'
		);
		// Typed binding: pdo_pgsql stringifies an untyped PHP false to '', which
		// PostgreSQL rejects for boolean columns (22P02).
		foreach (array_values($params) as $i => $value) {
			$type = PDO::PARAM_STR;
			if (is_bool($value))     { $type = PDO::PARAM_BOOL; }
			elseif ($value === null) { $type = PDO::PARAM_NULL; }
			elseif (is_int($value))  { $type = PDO::PARAM_INT; }
			$stmt->bindValue($i + 1, $value, $type);
		}
		$stmt->execute();
	}

	/**
	 * @param mixed $key Primary key of the row to represent. NULL (the default)
	 *                   means a new, unsaved record.
	 * @param bool $and_load Load the row from the database immediately.
	 */
	function __construct($key = NULL, $and_load = FALSE) {
		$this->key = $key;
		$this->data = new StdClass;
		$this->loaded = FALSE;
		$this->cached_references = array();
		
		if(!static::$prefix){
			throw new SystemBaseException('This object has no prefix.');
		}
		
		if(!static::$tablename){
			throw new SystemBaseException('This object has no table name.');
		}
		
		if(!static::$pkey_column){
			throw new SystemBaseException('This object has no primary key.');
		}

		if ($and_load) {
			$this->load();
		}
	}

	//THIS FUNCTION RETURNS ONLY ONE ROW AS AN OBJECT WHICH MATCHES THE COLUMN AND VALUE PROVIDED
	public static function GetByColumn($column, $value) {
		if(empty($column) || (empty($value) && $value !== 0)){
			throw new SystemBaseException('To load a row using GetByColumn, you must pass a column and value.');
		}

		if(!isset(static::$field_specifications[$column])){
			throw new SystemBaseException('That column '.$column.' does not exist in '.static::$tablename);
		}

		$field_type = static::$field_specifications[$column]['type'];
		if(str_contains($field_type, 'int')){
			$data = SingleRowFetch(static::$tablename, $column,
			$value, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		}
		else{
			$data = SingleRowFetch(static::$tablename, $column,
			$value, PDO::PARAM_STR, SINGLE_ROW_ALL_COLUMNS);
		}

		if ($data === NULL) {
			return NULL;
		}
		$classname = get_called_class();
		$pkey_column_name = $classname::$pkey_column;
		$pkey_column_value = $data->$pkey_column_name;

		$object = new $classname($pkey_column_value);
		$object->load_from_data($data, array_keys($classname::$field_specifications));
		return $object;
	}
	
	//STUB FUNCTION THAT MODELS CAN OPTIONALLY EXTEND
	static function CreateNew($data){
		return false; //WE RETURN FALSE IF WE DID NOT, IN FACT, CREATE A NEW SOMETHING
	}
	
	//BEGIN LINK FUNCTIONS
	
	//FETCH AN ENTRY BASED ON ITS LINK, OR SLUG
	//ARGUMENTS ARE THE LINK TO SEARCH AND WHETHER DELETED ITEMS SHOULD BE SEARCHED
	static function get_by_link($link, $search_deleted=false){
		$classname = get_called_class();
		$mclassname = 'Multi'.$classname;

		// No link means no match. Passing NULL through would drop the filter
		// entirely — Multi option keys are read with isset(), which is false for
		// NULL — leaving an unfiltered query that returns every row, of which
		// get(0) hands back an arbitrary one. A lookup for nothing would then
		// answer with somebody else's record.
		if ($link === null || $link === '') {
			return false;
		}

		if($search_deleted){
			$results = new $mclassname(array('link' => $link));
		}
		else{
			$results = new $mclassname(array('link' => $link, 'deleted'=>false));
		}
		$results->load();
	
		if($results->count()){	
			return $results->get(0);	
		}
		else{
			return false;
		}
	}
	
	//CREATES A URL OR SLUG BASED ON AN INPUT STRING
	function create_url($input_url) {
		//REQUIRE THAT THE OBJECT IS LOADED
		if(!$input_url){
			throw new SystemBaseException('You must pass a string to the create_url function.');
		}

		$classname = get_called_class();

		$tmp = strtolower(str_replace(' ', '-', $input_url));
		$tmp = preg_replace("/[^a-zA-Z0-9-]/", "", $tmp);
		$tmp = preg_replace('/-{2,}/', '-', $tmp);
		
		//NO DUPLICATES
		$safety = 0;
		$increment=1;
		$tmp_orig = $tmp;
		while($result = $classname::get_by_link($tmp, true)){
			$safety++;
			if($safety > 50){
				throw new SystemBaseException('Create_url is stuck in a loop. Check the presence of link Multi search.');
				exit;
			}
			if($result->key == $this->key){
				//IF WE FOUND THIS ONE, IT'S OKAY
				return $tmp;
			}
			else{
				$tmp = $tmp_orig . $increment;
				$increment++;
			}
		}
		return $tmp;
	}

	function get_url($format='short') {
		if(!isset(static::$url_namespace)){
			error_log('URL namespace is not set in object '.get_called_class().'. get_url() returning false.');
			return false;
		}
		
		if($format == 'full'){
			return LibraryFunctions::get_absolute_url('/'. static::$url_namespace .'/' . $this->get(static::$prefix .'_link'));
		}
		else{
			return '/'. static::$url_namespace .'/' . $this->get(static::$prefix .'_link');
		}
	}
	
	//END LINK FUNCTIONS
	
	function set($key, $value, $check_existance=TRUE) {
		if ($check_existance && !array_key_exists($key, static::$field_specifications)) {
			$display_value = is_array($value) || is_object($value) ? json_encode($value) : $value;
			error_log('EXCEPTION: Attempting to set the non-defined field ' . $key . ' of ' . get_class($this) . ' to ' . $display_value . '. Trace:' . print_r(debug_backtrace(FALSE), TRUE));
		}
		if (!empty(static::$sealed_fields) && in_array($key, static::$sealed_fields, true)) {
			$this->sealed_dirty[$key] = true;
		}
		$this->data->$key = $value;
	}
	
	
	function set_all_to_null(){
		foreach(array_keys(static::$field_specifications) as $field) {
			$this->set($field, NULL);
		}
	}
	

	function set_array($key, $value, $check_existance=TRUE) {
		$formatted_values = array();
		foreach ($value as $array_item) {
			if (is_string($array_item)) {
				$formatted_values[] = '"' . pg_escape_string($array_item) . '"';
			} else {
				$formatted_values[] = pg_escape_string($array_item);
			}
		}
		$this->set($key, '{' . implode(', ', $formatted_values) . '}', $check_existance);
	}

	function smart_get($key) {
		// Auto-detect timestamp fields from type
		if ($this->is_timestamp_field($key)) {
			$value = $this->get($key);
			if ($value) {
				return new DateTime($value);
			}
		}
		
		return $this->get($key);
	}

	/**
	 * Auto-detect if a field is a timestamp based on its type specification
	 * Optimized for performance with quick rejection of non-timestamp types
	 */
	protected function is_timestamp_field($field_name) {
		if (!isset(static::$field_specifications[$field_name])) {
			return false;
		}
		
		$type = strtolower(static::$field_specifications[$field_name]['type'] ?? '');
		
		// Quick rejection: if type starts with clearly non-timestamp types, return false immediately
		$first_char = $type[0] ?? '';
		if ($first_char === 'v' || $first_char === 'i' || $first_char === 'b' || 
			$first_char === 'n' || $first_char === 'f' || $first_char === 'c') {
			return false; // varchar, int*, bool*, numeric, float, char
		}
		
		// Additional optimization: check first two characters for "te" (text fields)
		if ($first_char === 't' && isset($type[1]) && $type[1] === 'e') {
			return false; // text, textarea - definitely not timestamps
		}
		
		// Final switch statement for complete type matching (no strpos() calls needed)
		switch ($type) {
			// Standard timestamp variants
			case 'timestamp':
			case 'timestamptz':
			case 'timestamp with time zone':
			case 'timestamp without time zone':
			
			// Timestamp with precision (0-6 fractional seconds)
			case 'timestamp(0)':
			case 'timestamp(1)':
			case 'timestamp(2)':
			case 'timestamp(3)':
			case 'timestamp(4)':
			case 'timestamp(5)':
			case 'timestamp(6)':
			
			// Timestamp with time zone and precision
			case 'timestamptz(0)':
			case 'timestamptz(1)':
			case 'timestamptz(2)':
			case 'timestamptz(3)':
			case 'timestamptz(4)':
			case 'timestamptz(5)':
			case 'timestamptz(6)':
			
			// Other date/time types
			case 'datetime':
			case 'date':
			case 'time':
			case 'time(0)':
			case 'time(1)':
			case 'time(2)':
			case 'time(3)':
			case 'time(4)':
			case 'time(5)':
			case 'time(6)':
				return true;
				
			default:
				// Fallback: check if type contains timestamp-related keywords (handles edge cases)
				if (strpos(strtolower($type), 'timestamp') !== false || 
					strpos(strtolower($type), 'datetime') !== false || 
					strpos(strtolower($type), 'date') === 0 || 
					strpos(strtolower($type), 'time') === 0) {
					return true;
				}
				return false;
		}
	}
	
	/**
	 * Auto-detect if a field is a JSON field based on its type specification
	 * Optimized for performance with quick rejection of non-JSON types
	 */
	protected function is_json_field($field_name) {
		if (!isset(static::$field_specifications[$field_name])) {
			return false;
		}
		
		$type = static::$field_specifications[$field_name]['type'] ?? '';
		
		// Optimized: Quick rejection based on first character
		$first_char = $type[0] ?? '';
		if ($first_char !== 'j') {
			return false; // Not json/jsonb - immediate rejection
		}
		
		// Only perform exact comparison if starts with 'j'
		return $type === 'json' || $type === 'jsonb';
	}
	
	function get($key) {
		$value = $this->data->$key ?? NULL;
		if ($value !== NULL && !empty(static::$sealed_fields) && in_array($key, static::$sealed_fields, true)) {
			// A column the caller just set holds their plaintext, not ciphertext:
			// hand it straight back. Without this, reading back what you wrote on a
			// sealed row hits the decrypt path, finds plaintext in a sealed column,
			// and throws — including from inside save()'s own validation pass.
			if (isset($this->sealed_dirty[$key])) {
				return $value;
			}
			return $this->decryptSealedField($key, $value);
		}
		return $value;
	}

	/**
	 * The boolean column recording that THIS ROW's $sealed_fields hold
	 * ciphertext. Sealing is per-row, not per-model — the same table holds
	 * sealed and unsealed rows side by side — so the flag lives on the row.
	 *
	 * Convention is {prefix}_content_sealed, which every sealed model but one
	 * follows; AiMessageAttachment overrides it with aia_sealed.
	 */
	public static function sealFlagColumn() {
		// $prefix is declared on concrete models, not here, so derive it
		// defensively rather than assuming — an undeclared static would fatal.
		if (!property_exists(static::class, 'prefix')) return '';
		return static::$prefix . '_content_sealed';
	}

	/**
	 * Is this row's content sealed right now?
	 *
	 * Reads the flag straight off $this->data rather than through get(), which
	 * would recurse into decryption. PDO hands back a Postgres boolean as 't'/'f'
	 * for some drivers and a real bool for others, and the string 'f' is truthy
	 * in PHP — hence the explicit comparison rather than a bare truth test.
	 *
	 * The sealing COLUMNS decide this, not $sealed_fields: a blob-only consumer
	 * (File) declares no sealed columns because no DB column of its holds
	 * ciphertext — the row's DEK exists to seal the file's bytes on disk. Such a
	 * row is still sealed, and its key wrapping still has to survive save().
	 */
	public function rowIsSealed() {
		$flag = static::sealFlagColumn();
		if ($flag === '' || !array_key_exists($flag, static::$field_specifications)) return false;
		return self::sealFlagIsSet($this->data->$flag ?? null);
	}

	/**
	 * Read a seal flag out of whatever form it arrives in.
	 *
	 * A boolean reaches this code as a real bool, as 't'/'f' from some PDO
	 * drivers, as '0'/'1', or as the literal string a field spec declared for its
	 * default. Every one of those false spellings except a bare `false` is
	 * TRUTHY in PHP, so a naive test reads an unsealed row as sealed — and a row
	 * wrongly believed sealed has its content columns skipped by save() and
	 * silently never written. Match the false spellings explicitly and treat
	 * everything else as sealed, which is the fail-safe direction for a read.
	 */
	protected static function sealFlagIsSet($value): bool {
		if ($value === null || $value === false || $value === 0) return false;
		if (is_string($value)) {
			return !in_array(strtolower($value), array('f', 'false', '0', 'no', ''), true);
		}
		return (bool)$value;
	}

	/**
	 * Is this instance's ROW sealed, as the DATABASE holds it right now?
	 *
	 * save() must never trust the in-memory flag for this question. sealColumns()
	 * writes the seal with a targeted UPDATE that never touches $this->data, so
	 * any instance loaded before its row was sealed — by a deferred ingest, by
	 * another request, by a direct sealColumns() call on a second instance —
	 * still reads unsealed while the row carries a live key wrapping. A save()
	 * that believes the stale flag writes plaintext and NULL metadata over a
	 * sealed row, and (on the seal-on-save path) mints a second DEK whose
	 * wrapping overwrites the one every other sealed column depends on.
	 *
	 * Falls back to the in-memory flag for a row that does not exist yet, or a
	 * model without the sealing columns. Memoized per row id; load() clears it.
	 */
	protected function rowIsSealedInDb(): bool {
		$flag    = static::sealFlagColumn();
		$key_col = static::sealedKeyColumn();
		if ($this->key === NULL || $flag === '' || $key_col === ''
				|| !array_key_exists($flag, static::$field_specifications)
				|| !array_key_exists($key_col, static::$field_specifications)) {
			return $this->rowIsSealed();
		}
		if ($this->db_seal_state !== null && $this->db_seal_state[0] === $this->key) {
			return $this->db_seal_state[1];
		}
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT ' . $flag . ', ' . $key_col . ' FROM ' . static::$tablename
			. ' WHERE ' . static::$pkey_column . ' = ?');
		$stmt->execute(array($this->key));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$sealed = is_array($row)
			&& self::sealFlagIsSet($row[$flag] ?? null)
			&& !empty($row[$key_col]);
		$this->db_seal_state = array($this->key, $sealed);
		return $sealed;
	}

	/** Columns save() must leave alone on a sealed row, as a lookup set. */
	protected function sealedColumnsToSkip() {
		// An INSERT skips nothing: there is no stored row whose sealed content or
		// wrapping a write-back could destroy — the caller's columns ARE the row.
		if ($this->key === NULL) return array();
		$flag = static::sealFlagColumn();
		if ($flag === '' || !array_key_exists($flag, static::$field_specifications)) return array();
		if (!$this->rowIsSealedInDb()) return array();
		// Per-field, through the same predicate the read path uses: a column that
		// is metadata on THIS row (sealedFieldIsActive() false) holds plaintext,
		// reads back verbatim, and stays writable like any ordinary column.
		$skip = array();
		$row = get_object_vars($this->data);
		if (static::$pkey_column && $this->key !== NULL) {
			$row[static::$pkey_column] = $this->key;
		}
		foreach (static::$sealed_fields as $sealed_field) {
			if (static::sealedFieldIsActive($sealed_field, $row)) {
				$skip[$sealed_field] = true;
			}
		}
		// The seal METADATA travels with the content, not with save(). sealColumns()
		// writes the key wrapping with a targeted UPDATE that never touches
		// $this->data — so an instance that was in memory when its row sealed
		// (a RecipeRun sealed at run start, then saved for a status change) still
		// holds NULL/0 for these columns, and letting save() write them back
		// destroys the wrapped DEK while the seal flag stays true: every byte the
		// row sealed becomes permanently unreadable, silently. Unseal paths use
		// their own targeted UPDATEs, so save() never legitimately writes these.
		//
		// The FLAG is one of them. A stale instance still holds false, and writing
		// that back would mark the row unsealed while its columns stay ciphertext —
		// after which every read hands the raw blobs back as though they were data,
		// and the next seal-on-save mints a second DEK over the live wrapping.
		foreach (array($flag, static::sealedKeyColumn(), static::sealedOwnerColumn(),
				static::sealedGenerationColumn()) as $meta_col) {
			if ($meta_col !== '' && array_key_exists($meta_col, static::$field_specifications)) {
				$skip[$meta_col] = true;
			}
		}
		return $skip;
	}

	/** The column holding this row's DEK, wrapped to the owner's vault public key. */
	public static function sealedKeyColumn() {
		if (!property_exists(static::class, 'prefix')) return '';
		return static::$prefix . '_sealed_key';
	}

	/** The column recording WHOSE vault the DEK was wrapped to, at seal time. */
	public static function sealedOwnerColumn() {
		if (!property_exists(static::class, 'prefix')) return '';
		return static::$prefix . '_sealed_owner_user_id';
	}

	/** The column recording the owner's key generation at seal time (rotation). */
	public static function sealedGenerationColumn() {
		if (!property_exists(static::class, 'prefix')) return '';
		return static::$prefix . '_key_generation';
	}

	/**
	 * The AD (additional data) string binding one sealed value to its row and
	 * column — the splice defense: a ciphertext moved to another row or another
	 * column fails to open. Sealer and opener must build the identical string.
	 *
	 * The default is "{prefix}:{id}:{field}". Models that predate this
	 * convention override it and keep their own literal (mail:, contact:) —
	 * changing one would strand every row already sealed under it.
	 */
	public static function sealAd(int $row_id, string $field): string {
		$prefix = property_exists(static::class, 'prefix') ? static::$prefix : 'row';
		return $prefix . ':' . $row_id . ':' . $field;
	}

	/**
	 * Does $field actually hold sealed content on THIS row? Default yes for
	 * every declared column. Overridden where a column is content on some rows
	 * and metadata on others — an inbound mail row's recipient is the routing
	 * alias (never sealed) while an outbound row's recipient is a real address
	 * list (sealed).
	 */
	protected static function sealedFieldIsActive(string $field, array $row): bool {
		return true;
	}

	/**
	 * Whose vault this row decrypts against. Default is the owner recorded on
	 * the row at seal time, which is immune to later membership changes.
	 * Overridden by models needing a fallback — a row sealed before the owner
	 * column existed resolves through a live lookup instead.
	 * Returns null when nothing resolves — the caller treats that as locked.
	 */
	protected static function sealedOwnerUserIdFor(array $row): ?int {
		$col = static::sealedOwnerColumn();
		$owner = ($col !== '') ? intval($row[$col] ?? 0) : 0;
		return $owner > 0 ? $owner : null;
	}

	/**
	 * True when a raw row's sealed columns hold ciphertext — the raw-row twin of
	 * rowIsSealed(). Both the flag and the wrapped key must be present: a row
	 * mid-ingest, between its INSERT and its sealColumns() UPDATE, has neither.
	 */
	protected static function rowArrayIsSealed(array $row): bool {
		$flag = static::sealFlagColumn();
		$key  = static::sealedKeyColumn();
		if ($flag === '' || $key === '') return false;
		if (!self::sealFlagIsSet($row[$flag] ?? null)) {
			return false;
		}
		return !empty($row[$key]);
	}

	/**
	 * Fail loudly when a model declares $sealed_fields but has none of the
	 * plumbing the generic path needs. Without this a misdeclared model would
	 * silently hand back ciphertext, which is the one outcome worse than an
	 * exception: it looks like data.
	 */
	protected static function assertSealingDeclared(string $field): void {
		foreach (array(static::sealFlagColumn(), static::sealedKeyColumn()) as $col) {
			if ($col === '' || !array_key_exists($col, static::$field_specifications)) {
				throw new RuntimeException(
					get_called_class() . ' declares "' . $field . '" in $sealed_fields but has no '
					. 'sealing columns — add {prefix}_content_sealed and {prefix}_sealed_key, or '
					. 'override the seal column accessors / the decrypt hooks.'
				);
			}
		}
	}

	/**
	 * Sealed Vault generic read hook (instance path - covers get() and
	 * anything built on it, e.g. export_as_array()).
	 *
	 * Delegates to the raw-row implementation over this row's stored values, so
	 * the two paths can never drift apart. $this->data holds ciphertext for the
	 * sealed columns (load() does no decryption), which is exactly what the
	 * static path expects.
	 */
	protected function decryptSealedField($field, $ciphertext) {
		$row = get_object_vars($this->data);
		$row[static::$pkey_column] = $this->key;
		return static::decryptSealedFieldStatic($field, $ciphertext, $row);
	}

	/**
	 * Sealed Vault generic read hook (raw-row path - for readers that fetch
	 * rows directly via SQL without instantiating a model, e.g.
	 * joinery_ai's ModelQueryExecutor).
	 *
	 * Returns the value untouched on a row that was never sealed, so a table
	 * holding sealed and plaintext rows side by side reads correctly either way.
	 * Throws VaultLockedException when the row IS sealed but the owner's unlock
	 * window is closed or no owner resolves — locked, not an error.
	 */
	public static function decryptSealedFieldStatic($field, $ciphertext, array $row) {
		static::assertSealingDeclared($field);
		if (!static::rowArrayIsSealed($row)) {
			return $ciphertext;
		}
		if (!static::sealedFieldIsActive($field, $row)) {
			return $ciphertext;
		}
		// A sealed row seals its columns as they are written, so one that holds
		// nothing yet is normal and reads back as-is. Anything else in a sealed
		// column MUST be a sealed blob: a populated plaintext value there is
		// corruption, and saying so beats handing it back as though it were data.
		if ($ciphertext === null || $ciphertext === '') {
			return $ciphertext;
		}
		if (!is_string($ciphertext) || strpos($ciphertext, 'v1.aead.') !== 0) {
			throw new RuntimeException(
				get_called_class() . '.' . $field . ' holds plaintext on a sealed row — '
				. 'something wrote it without sealColumns().'
			);
		}
		require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
		require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));

		$owner_id = static::sealedOwnerUserIdFor($row);
		if ($owner_id === null) {
			throw new VaultLockedException();
		}
		$secret = VaultUnlock::secretKey($owner_id);
		if ($secret === null) {
			throw new VaultLockedException();
		}
		$crypto = new VaultCrypto();
		$dek = $crypto->openItemDek((string)$row[static::sealedKeyColumn()], $secret);
		return $crypto->openField($ciphertext, $dek,
			static::sealAd(intval($row[static::$pkey_column] ?? 0), $field));
	}

	/**
	 * Seal content onto an existing row and persist it — the ONLY supported
	 * writer for a $sealed_fields column, and the write-side half of the Layer 0
	 * contract (specs/implemented/sealed_content_egress.md): save() skips sealed columns on a
	 * sealed row, sealColumns() owns them.
	 *
	 * Sealing needs only the owner's vault PUBLIC key, so any process can seal to
	 * a user at any time — no unlock window, no liveness problem. Only reading
	 * needs the in-window secret.
	 *
	 * The row must already exist: the AD binds each value to the primary key, so
	 * the id has to be real before anything is sealed. INSERT the row with its
	 * sealed columns empty, then call this.
	 *
	 * $reuse_dek re-seals under an existing row DEK (a draft saved repeatedly,
	 * whose already-sealed attachments hang off that key) and leaves the key
	 * wrapping columns untouched. Returns the DEK raw bytes so the caller can
	 * seal related blobs — attachments, raw messages — under the same key.
	 *
	 * An EMPTY $values is the blob-only shape and is fully supported: mint a DEK,
	 * record its wrapping, mark the row sealed, hand the key back. A model whose
	 * ciphertext lives entirely outside the database (Drive's Private files —
	 * the bytes on disk and their thumbnail variant) declares no $sealed_fields
	 * and calls this for the key management alone.
	 */
	public static function sealColumns($row_id, $vault, array $values, $reuse_dek = null) {
		require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
		$row_id = intval($row_id);
		if ($row_id <= 0) {
			throw new RuntimeException(get_called_class() . '::sealColumns() needs a persisted row id.');
		}
		foreach (array_keys($values) as $col) {
			if (!in_array($col, static::$sealed_fields, true)) {
				throw new RuntimeException(
					get_called_class() . '::sealColumns() refused "' . $col . '": not in $sealed_fields. '
					. 'Sealing a column nothing decrypts would write unreadable data.'
				);
			}
		}
		static::assertSealingDeclared(static::$sealed_fields[0] ?? '');

		$crypto = new VaultCrypto();
		$mint = ($reuse_dek === null);
		$dek  = $mint ? $crypto->newItemDek() : $reuse_dek;

		$sets = array();
		$params = array();
		foreach ($values as $col => $plaintext) {
			if ($plaintext !== null && !is_scalar($plaintext)) {
				throw new RuntimeException(
					get_called_class() . '::sealColumns() refused "' . $col . '": a '
					. gettype($plaintext) . ' cannot be sealed - encode it to a string first. '
					. '(A silent (string) cast would durably seal the literal "Array".)'
				);
			}
			$sets[] = $col . ' = ?';
			if ($plaintext === null || $plaintext === '') {
				// Clearing a sealed column stores the empty value BARE, never as an
				// AEAD blob of nothing: the read path returns null/'' verbatim on a
				// sealed row, and a blob here would break every IS NULL query while
				// reading back as ''. NULL stays NULL and '' stays '' — the same
				// distinction every plaintext model preserves.
				$params[] = $plaintext;
				continue;
			}
			$params[] = $crypto->sealField((string)$plaintext, $dek, static::sealAd($row_id, $col));
		}
		// Only a freshly-minted DEK writes the wrapping; a reused one leaves the
		// existing key/generation/owner in place so the old ciphertext still opens.
		if ($mint) {
			$wrap = static::sealWrappingAssignments($crypto, $vault, $dek);
			$sets = array_merge($sets, $wrap['sets']);
			$params = array_merge($params, $wrap['params']);
		}
		$sets[] = static::sealFlagColumn() . ' = true';
		$params[] = $row_id;

		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'UPDATE ' . static::$tablename . ' SET ' . implode(', ', $sets)
			. ' WHERE ' . static::$pkey_column . ' = ?');
		$stmt->execute($params);
		return $dek;
	}

	/**
	 * Record a key wrapping on a row whose ciphertext lives entirely OUTSIDE the
	 * database — the blob-only consumer shape (Drive's Private files: the bytes
	 * on disk and their thumbnail variant are the sealed things, no column here
	 * holds ciphertext).
	 *
	 * The difference from sealColumns() is which key gets wrapped. sealColumns()
	 * mints its own DEK because it must seal column values in the same statement;
	 * a blob consumer has to seal its bytes BEFORE the row exists (the container
	 * is what the row's blob is made from), so it mints the key first and hands
	 * it here to be wrapped. Pass null to mint one anyway.
	 *
	 * Like sealColumns(), this needs only the owner's PUBLIC key: sealing never
	 * waits for an unlock window.
	 *
	 * @param int|string $row_id a persisted row
	 * @param object     $vault  the owner's UserEncryptionVault
	 * @param string|null $dek   raw key bytes to wrap; null mints one
	 * @return string the raw DEK now wrapped onto the row
	 */
	public static function recordSealedKey($row_id, $vault, $dek = null) {
		require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
		$row_id = intval($row_id);
		if ($row_id <= 0) {
			throw new RuntimeException(get_called_class() . '::recordSealedKey() needs a persisted row id.');
		}
		foreach (array(static::sealFlagColumn(), static::sealedKeyColumn()) as $col) {
			if ($col === '' || !array_key_exists($col, static::$field_specifications)) {
				throw new RuntimeException(get_called_class()
					. '::recordSealedKey() needs {prefix}_content_sealed and {prefix}_sealed_key columns.');
			}
		}

		$crypto = new VaultCrypto();
		$dek = ($dek === null) ? $crypto->newItemDek() : $dek;

		$wrap = static::sealWrappingAssignments($crypto, $vault, $dek);
		$sets = $wrap['sets'];
		$params = $wrap['params'];
		$sets[] = static::sealFlagColumn() . ' = true';
		$params[] = $row_id;

		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'UPDATE ' . static::$tablename . ' SET ' . implode(', ', $sets)
			. ' WHERE ' . static::$pkey_column . ' = ?');
		$stmt->execute($params);
		return $dek;
	}

	/**
	 * The SET assignments that record a DEK's wrapping: the sealed key itself,
	 * plus the generation and owner a rotation sweep reads. One place knows this
	 * shape, so the two writers cannot drift.
	 */
	protected static function sealWrappingAssignments($crypto, $vault, $dek) {
		$sets = array();
		$params = array();

		$sets[] = static::sealedKeyColumn() . ' = ?';
		$params[] = $crypto->sealItemDek($dek, (string)$vault->get('uev_public_key'));

		$generation = static::sealedGenerationColumn();
		if ($generation !== '' && array_key_exists($generation, static::$field_specifications)) {
			$sets[] = $generation . ' = ?';
			$params[] = intval($vault->get('uev_key_generation'));
		}
		$owner = static::sealedOwnerColumn();
		if ($owner !== '' && array_key_exists($owner, static::$field_specifications)) {
			$sets[] = $owner . ' = ?';
			$params[] = intval($vault->get('uev_usr_user_id'));
		}
		return array('sets' => $sets, 'params' => $params);
	}

	/**
	 * Should THIS row's content be sealed? Per row, not per model: the same
	 * table holds a protected member's sealed rows and an unprotected member's
	 * plaintext ones side by side.
	 *
	 * The default is yes, which combined with save()'s behavior means "seal when
	 * this row's owner has an active vault" — exactly right for a consumer whose
	 * premise is that its content is private, and needing no declaration at all.
	 * A consumer whose policy is dynamic (a per-domain security level, a
	 * per-conversation setting) overrides this one method.
	 *
	 * $row is the row as it stands, plaintext included, keyed by column name.
	 */
	protected static function shouldSeal(array $row): bool {
		return true;
	}

	/**
	 * Whose vault a row being WRITTEN seals to.
	 *
	 * The read path resolves ownership from the owner column recorded at seal
	 * time, which a row being sealed for the first time does not have yet. So
	 * this asks the same hook first and falls back to the conventional
	 * {prefix}_usr_user_id column — which is what a consumer sets on the row
	 * anyway, and is why the write path needs no vault lookup of its own.
	 */
	protected static function sealOwnerForWrite(array $row): ?int {
		$owner = static::sealedOwnerUserIdFor($row);
		if ($owner !== null) {
			return $owner;
		}
		$col = property_exists(static::class, 'prefix') ? static::$prefix . '_usr_user_id' : '';
		if ($col !== '' && array_key_exists($col, static::$field_specifications)) {
			$candidate = intval($row[$col] ?? 0);
			if ($candidate > 0) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * Decide what save() must seal, BEFORE any SQL runs. Returns null when this
	 * save writes nothing sealed, in which case save() behaves exactly as it does
	 * for any other model.
	 *
	 * Everything that can fail happens here, so a save either seals or never
	 * touches the database: resolving the owner, finding their vault, and — on a
	 * row that is already sealed — recovering the existing DEK.
	 *
	 * That last step is why creating and updating are not symmetric, and the
	 * asymmetry is the crypto rather than the API. Sealing needs only the owner's
	 * PUBLIC key, so a brand-new row seals into a locked vault from any process.
	 * Reusing an existing row's DEK means UNWRAPPING it, which needs the secret,
	 * so a sealed-column update against a closed window throws
	 * VaultLockedException — the same thing get() does, and harmless in practice
	 * because editing content means having read it first.
	 *
	 * @return array{values:array<string,string>, vault:object, reuse_dek:?string}|null
	 */
	protected function planSealOnSave(): ?array {
		if (!static::$seal_on_save || empty(static::$sealed_fields) || empty($this->sealed_dirty)) {
			return null;
		}
		$row = get_object_vars($this->data);
		if (static::$pkey_column && $this->key !== NULL) {
			$row[static::$pkey_column] = $this->key;
		}

		$values = array();
		foreach (static::$sealed_fields as $field) {
			if (!isset($this->sealed_dirty[$field])) {
				continue;
			}
			// The same per-row predicate the read path checks FIRST. A column that
			// is metadata on this row must not be sealed: the read path returns it
			// verbatim, so sealing it would hand callers the raw blob as data.
			if (!static::sealedFieldIsActive($field, $row)) {
				continue;   // travels the ordinary column build as plaintext
			}
			$value = $this->data->$field ?? null;
			if ($value !== null && !is_scalar($value)) {
				throw new SystemBaseException(get_called_class() . '.' . $field
					. ' cannot be sealed as a ' . gettype($value)
					. ' - encode it to a string before set().');
			}
			$values[$field] = $value;
		}
		if (empty($values)) {
			return null;
		}

		if (!static::shouldSeal($row)) {
			return null;   // policy says plaintext; save() writes the columns normally
		}

		$owner_id = static::sealOwnerForWrite($row);
		if ($owner_id === null) {
			return null;   // nobody to seal to
		}

		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
		$vault = UserEncryptionVault::loadForUser($owner_id);
		if (!$vault || !$vault->key) {
			return null;   // no vault: this member's content is stored in the clear
		}

		// An already-sealed row keeps its DEK. Minting a fresh one would rewrite
		// the key wrapping and orphan every sealed column this save did not
		// rewrite — the partial-update trap consumers used to avoid by threading
		// the old DEK through by hand. Sealed-ness is the DATABASE's answer, not
		// this instance's: a stale instance that trusted its own flag here would
		// mint over a live wrapping (see rowIsSealedInDb()).
		$reuse_dek = null;
		if ($this->key !== NULL && $this->rowIsSealedInDb()) {
			$reuse_dek = $this->existingRowDek($owner_id);
		}

		// A FIRST-TIME seal of an existing row seals the whole row, not the dirty
		// subset. The row was created plaintext (its owner had no vault yet, or the
		// policy declined); sealing one edited column while the flag flips true
		// would leave every other populated sealed column as plaintext-on-a-sealed-
		// row — leaked at rest, and an exception on every later read.
		if ($this->key !== NULL && $reuse_dek === null) {
			foreach (static::$sealed_fields as $field) {
				if (array_key_exists($field, $values) || !static::sealedFieldIsActive($field, $row)) {
					continue;
				}
				$existing = $this->data->$field ?? null;
				if ($existing === null || $existing === '' || !is_scalar($existing)) {
					continue;   // nothing stored, or not this row's content
				}
				$values[$field] = $existing;
			}
		}

		return array('values' => $values, 'vault' => $vault, 'reuse_dek' => $reuse_dek);
	}

	/**
	 * The DEK a sealed row is already using, unwrapped under the owner's open
	 * window. Read from the database rather than from $this->data, because an
	 * instance can be in memory from before its row was sealed and still hold
	 * NULL where the wrapping now lives.
	 */
	protected function existingRowDek(int $owner_id): string {
		require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
		require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));

		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT ' . static::sealedKeyColumn() . ' FROM ' . static::$tablename
			. ' WHERE ' . static::$pkey_column . ' = ?');
		$stmt->execute(array($this->key));
		$sealed = (string)$stmt->fetchColumn();
		if ($sealed === '') {
			throw new RuntimeException(get_called_class() . ': row ' . $this->key
				. ' is flagged sealed but carries no wrapped key.');
		}
		$secret = VaultUnlock::secretKey($owner_id);
		if ($secret === null) {
			throw new VaultLockedException();
		}
		$crypto = new VaultCrypto();
		return $crypto->openItemDek($sealed, $secret);
	}

	/**
	 * Seal the planned columns onto the row save() has just written, and forget
	 * the plaintext this instance was holding.
	 *
	 * The row has to exist first: the AEAD binds each value to the primary key,
	 * so an insert is necessarily two statements. save() runs both inside one
	 * transaction, so a failure here leaves no half-written row behind.
	 */
	protected function applySealOnSave(array $plan): void {
		static::sealColumns($this->key, $plan['vault'], $plan['values'], $plan['reuse_dek']);
		foreach (array_keys($plan['values']) as $field) {
			unset($this->sealed_dirty[$field]);
			// $this->data still holds plaintext for these columns. Drop it: the
			// row is ciphertext now, and an instance left holding cleartext is
			// exactly what the Layer 0 contract exists to prevent.
			unset($this->data->$field);
		}
		$flag = static::sealFlagColumn();
		if ($flag !== '' && array_key_exists($flag, static::$field_specifications)) {
			$this->data->$flag = true;
		}
		// The database's answer just changed, and this instance made it change.
		$this->db_seal_state = array($this->key, true);
	}

	/**
	 * Re-seal one model's rows from a draining key generation to a new one —
	 * the generic half of every consumer's onReseal callback
	 * (VaultUnlock::modelReseal()).
	 *
	 * Only the per-row DEK's WRAPPING moves. The DEK itself and every byte of
	 * ciphertext it seals are untouched, which is what makes a rotation cheap
	 * regardless of how much content a member holds.
	 *
	 * Scoped to $old_generation exactly, because that is the only generation
	 * $old_secret_key can open — a row already on the new generation would fail
	 * to unwrap and read as a rotation failure. A model with no generation column
	 * cannot be scoped that way and is refused rather than half-rotated.
	 *
	 * Ownership cannot live entirely in the WHERE clause: sealedOwnerUserIdFor()
	 * is overridable and at least one override falls back to a live lookup for
	 * rows sealed before the owner column existed, which no SQL predicate
	 * expresses. So the select narrows to this owner OR an empty owner column,
	 * and every candidate row is confirmed through the hook before it is touched.
	 *
	 * Soft-deleted rows are re-sealed too. A deleted row is restorable, and one
	 * left on a retired generation would come back permanently unreadable.
	 *
	 * Attempts every row, counts failures, and returns the tally — the caller
	 * throws. That split exists so a consumer can compose several models (and its
	 * own bespoke material) into one fail-loud callback.
	 *
	 * @return array{attempted:int, failed:int}
	 */
	public static function resealRows(int $user_id, string $old_secret_key, int $old_generation,
			string $new_public_key, int $new_generation): array {
		require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));

		$flag      = static::sealFlagColumn();
		$key_col   = static::sealedKeyColumn();
		$gen_col   = static::sealedGenerationColumn();
		$owner_col = static::sealedOwnerColumn();
		$specs     = static::$field_specifications;

		foreach (array($flag, $key_col, $gen_col) as $required) {
			if ($required === '' || !array_key_exists($required, $specs)) {
				throw new RuntimeException(get_called_class() . '::resealRows() needs '
					. '{prefix}_content_sealed, {prefix}_sealed_key and {prefix}_key_generation columns.');
			}
		}

		$where  = array($flag . ' = true', $gen_col . ' = ?');
		$params = array($old_generation);
		if ($owner_col !== '' && array_key_exists($owner_col, $specs)) {
			$where[]  = '(' . $owner_col . ' = ? OR ' . $owner_col . ' IS NULL OR ' . $owner_col . ' = 0)';
			$params[] = $user_id;
		}

		$db = DbConnector::get_instance()->get_db_link();
		$select = $db->prepare('SELECT * FROM ' . static::$tablename . ' WHERE ' . implode(' AND ', $where));
		$select->execute($params);
		$rows = $select->fetchAll(PDO::FETCH_ASSOC);

		$update = $db->prepare('UPDATE ' . static::$tablename . ' SET ' . $key_col . ' = ?, '
			. $gen_col . ' = ? WHERE ' . static::$pkey_column . ' = ?');

		$crypto    = new VaultCrypto();
		$attempted = 0;
		$failed    = 0;
		foreach ($rows as $row) {
			$sealed = (string)($row[$key_col] ?? '');
			if ($sealed === '') {
				continue;   // a row mid-ingest, between its INSERT and its seal
			}
			if (static::sealedOwnerUserIdFor($row) !== $user_id) {
				continue;   // matched the loose owner predicate but is not this member's
			}
			$attempted++;
			$row_id = intval($row[static::$pkey_column] ?? 0);
			try {
				$dek = $crypto->openItemDek($sealed, $old_secret_key);
				$update->execute(array($crypto->sealItemDek($dek, $new_public_key), $new_generation, $row_id));
			} catch (Throwable $e) {
				$failed++;
				error_log('Vault reseal: failed for ' . static::$tablename . ' row ' . $row_id
					. ': ' . $e->getMessage());
			}
		}

		return array('attempted' => $attempted, 'failed' => $failed);
	}

	/**
	 * Opt-in JSON decoder for JSON-typed columns.
	 *
	 * SystemBase::load() stores JSON columns as raw strings and save() auto-encodes
	 * arrays/objects on the way back, so get() returns a string after load() but an
	 * array after set(). Callers that want a consistently-typed PHP value call this
	 * helper instead of get(). Returns decoded PHP for JSON strings, the value as-is
	 * if already decoded, null for null/empty, and the raw string if decode fails.
	 */
	function get_json_decoded($key) {
		$value = $this->data->$key ?? null;
		if ($value === null || $value === '') return $value;
		if (!is_string($value)) return $value;
		$decoded = json_decode($value, true);
		return $decoded !== null ? $decoded : $value;
	}


	//TAKES AN OBJECT TO SEARCH FOR AND A STRING OR AN ARRAY REPRESENTING NAMES OF FIELDS TO CHECK WITH CURRENT OBJECT
	//IT WILL RETURN A LIST OF DUPLICATES, SEPARATING FIELDS WITH 'AND' IN THE SQL
	//IF SEARCH_DELETED IS TRUE, IT WILL ALSO SEARCH ALL DELETED ITEMS
	public static function CheckForDuplicate($obj_to_check, $fields=NULL, $search_deleted=false) {
		if(!isset($fields) || $fields == '' || $fields == NULL){
			throw new SystemBaseException('You must pass some fields to check for duplicates.');
		}
		
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();  

		$sql = 'SELECT * from '.static::$tablename . ' WHERE ';

		$whereclauses = array();
		if(is_array($fields)){
			foreach ($fields as $field){
				$field_type = static::$field_specifications[$field]['type'];
				if(str_contains($field_type, 'int')){
					$whereclauses[] = $field . '='.$obj_to_check->get($field). ' ';
				}
				else if(str_contains($field_type, 'bool')){
					if($obj_to_check->get($field) === true){
						$whereclauses[] = $field . '= true ';
					}
					else if($obj_to_check->get($field) === false){
						$whereclauses[] = $field . '= false ';
					}
					else{
						$whereclauses[] = $field . ' IS NULL';
					}
					
				} 
				else{
					$whereclauses[] = $field . '=\''.$obj_to_check->get($field). '\' ';
				}				
				
			}
		}
		else{
			$field_type = static::$field_specifications[$fields]['type'];
			if(str_contains($field_type, 'int')){
				$whereclauses[] = $field . '='.$obj_to_check->get($field). ' ';
			}
			else if(str_contains($field_type, 'bool')){
				if($obj_to_check->get($field) === true){
					$whereclauses[] = $field . '= true ';
				}
				else if($obj_to_check->get($field) === false){
					$whereclauses[] = $field . '= false ';
				}
				else{
					$whereclauses[] = $field . ' IS NULL';
				}
				
			} 
			else{
				$whereclauses[] = $field . '=\''.$obj_to_check->get($field). '\' ';
			}
		}
		
		if(!$search_deleted){
			//SEE IF THERE IS A DELETED FIELD
			if(isset(static::$field_specifications[static::$prefix . '_delete_time'])){
				$whereclauses[] = static::$prefix . '_delete_time IS NULL ';
			}
			else if(isset(static::$field_specifications[static::$prefix . '_is_deleted'])){
				$whereclauses[] = '('.static::$prefix . '_is_deleted IS NULL OR '.static::$prefix . '_is_deleted = FALSE)';
			}			
		}

		$sql .= implode(' AND ', $whereclauses);

		try{
			$q = $dblink->prepare($sql);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}	
		

		$this_class = static::class;
		$multi_class_name = 'Multi'.$this_class;
		$pkey_column_name = $this_class::$pkey_column;

		$this_multi_array = new $multi_class_name();
		$numresults = 0;
		foreach($q->fetchAll() as $row) {
			$numresults++;
			$child = new $this_class($row->$pkey_column_name);
			$child->load_from_data($row, array_keys($this_class::$field_specifications));
			$this_multi_array->add($child);
		}
		
		if($numresults){
			return $this_multi_array;
		}
		else{
			return NULL;
		}		

	}		
	
	//TAKES A STRING OR AN ARRAY REPRESENTING NAMES OF FIELDS TO CHECK WITH CURRENT OBJECT
	//WILL RETURN THE NUMBER OF DUPLICATES FOUND, SEPARATING FIELDS WITH 'AND' IN THE SQL
	//IF SEARCH_DELETED IS TRUE, IT WILL ALSO SEARCH ALL DELETED ITEMS
	public function check_for_duplicate($fields=NULL, $search_deleted=false) {
		if(!isset($fields) || $fields == '' || $fields == NULL){
			throw new SystemBaseException('You must pass some fields to check for duplicates.');
		}
		
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();  
		


		$sql = 'SELECT count(*) as total from '.static::$tablename . ' WHERE ';
	
		$whereclauses = array();
		$param_string = ':param';
		$counter = 0;
		if(is_array($fields)){
			foreach ($fields as $field){
				$counter++;
				$whereclauses[] = $field . '='.$param_string .$counter. ' ';
			}
		}
		else{
			$whereclauses[] = $fields . '= :param1 ';
		}
		
		if(!$search_deleted){
			//SEE IF THERE IS A DELETED FIELD
			if(isset(static::$field_specifications[static::$prefix . '_delete_time'])){
				$whereclauses[] = static::$prefix . '_delete_time IS NULL ';
			}
			else if(isset(static::$field_specifications[static::$prefix . '_is_deleted'])){
				$whereclauses[] = '('.static::$prefix . '_is_deleted IS NULL OR '.static::$prefix . '_is_deleted = FALSE)';
			}			
		}

		$sql .= implode(' AND ', $whereclauses);

		if($this->key){
			$sql .= ' AND '.static::$pkey_column.' != :pkey';
		}

		try{
			$q = $dblink->prepare($sql);
			$counter = 0;
			if(is_array($fields)){
				foreach ($fields as $field){
					$field_type = static::$field_specifications[$field]['type'];
					$counter++;
					$param_name = $param_string . $counter;
					$value = $this->get($field);
					if(str_contains($field_type, 'int')){
						$q->bindValue($param_name, $value, PDO::PARAM_INT);
					}
					else if(str_contains($field_type, 'bool')){
						$q->bindValue($param_name, $value, PDO::PARAM_BOOL);
					}
					else{
						$q->bindValue($param_name, $value, PDO::PARAM_STR);
					}
				}
			}
			else{
				$field_type = static::$field_specifications[$fields]['type'];
				$value = $this->get($fields);
				if(str_contains($field_type, 'int')){
					$q->bindValue(':param1', $value, PDO::PARAM_INT);
				}
				else if(str_contains($field_type, 'bool')){
					$q->bindValue(':param1', $value, PDO::PARAM_BOOL);
				}
				else{
					$q->bindValue(':param1', $value, PDO::PARAM_STR);
				}
			}
			if($this->key){
				$q->bindValue(':pkey', $this->key, PDO::PARAM_INT);
			}
			
			
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}	
		
		$count = $q->fetch();
		return $count->total;
	}	

	/**
	 * Check unique constraints defined in field_specifications
	 * Returns array with constraint violation details or null if no violations
	 */
	protected function check_unique_constraints() {
		if ($this->key) {
			return null; // Only check on insert
		}
		
		if (!isset(static::$field_specifications)) {
			return null; // No field specifications defined
		}
		
		foreach (static::$field_specifications as $field => $spec) {
			// Check single field unique constraints
			if (isset($spec['unique']) && $spec['unique']) {
				if ($this->check_for_duplicate($field)) {
					return array(
						'field' => $field,
						'message' => "Duplicate value for {$field}"
					);
				}
			}
			
			// Check composite unique constraints
			if (isset($spec['unique_with'])) {
				$fields = array_merge(array($field), $spec['unique_with']);
				if ($this->check_for_duplicate($fields)) {
					$field_list = implode(', ', $fields);
					return array(
						'fields' => $fields,
						'message' => "Duplicate combination for {$field_list}"
					);
				}
			}
		}
		
		return null;
	}

	function hash() {
		if ($this->key) {
			return md5(get_class($this) . " " . $this->key);
		}
		throw new SystemBaseException('Cannot hash an element with no key.');
	}

	/**
	 * True if $field is part of the universal unreadable floor — either it matches
	 * CREDENTIAL_FIELD_PATTERN or it is listed in this model's $api_unreadable_fields. Shared
	 * by every API surface so "secret" means the same thing everywhere.
	 */
	public static function is_unreadable_field($field) {
		if (in_array($field, static::$api_unreadable_fields, true)) {
			return true;
		}
		return (bool) preg_match(self::CREDENTIAL_FIELD_PATTERN, $field);
	}

	/**
	 * Write-side mirror of is_unreadable_field(): true if $field must never be set
	 * through an API write — either it is a privileged column listed in this model's
	 * $api_unwritable_fields, or its name matches the shared credential pattern (a
	 * credential is neither readable nor writable). Honored by both the REST write
	 * boundary and the AI write surface, so "writable over an API" means one thing.
	 */
	public static function is_unwritable_field($field) {
		if (in_array($field, static::$api_unwritable_fields, true)) {
			return true;
		}
		return (bool) preg_match(self::CREDENTIAL_FIELD_PATTERN, $field);
	}

	/**
	 * The fail-closed API export: the projection of export_as_array() that may leave
	 * the server over an API surface. This is what every API boundary (REST CRUD,
	 * embeds) must use instead of export_as_array(), which returns the full row for
	 * internal/admin/webhook callers.
	 *
	 * Fail-closed (allowlist), mirroring the AI read surface's $field_specifications
	 * allowlist: a key is emitted ONLY if it is either
	 *   1. a declared column ($field_specifications) that survives the unreadable
	 *      floor (is_unreadable_field), or
	 *   2. a key on this model's $api_derived_fields allowlist (a computed key an
	 *      export_as_array() override deliberately exposes) — still subject to the
	 *      floor, so a credential-named derived key cannot be allowlisted into the open.
	 *
	 * Anything else an override injects (e.g. a computed token) is dropped by
	 * construction, so a derived secret cannot leak under a name the credential
	 * regex did not anticipate.
	 */
	function export_for_api() {
		$full = $this->export_as_array();
		$out = array();
		foreach (array_keys(static::$field_specifications) as $field) {
			if (array_key_exists($field, $full) && !static::is_unreadable_field($field)) {
				$out[$field] = $full[$field];
			}
		}
		foreach (static::$api_derived_fields as $field) {
			if (array_key_exists($field, $full) && !static::is_unreadable_field($field)) {
				$out[$field] = $full[$field];
			}
		}
		// API contract: timestamps leave the server as UTC 'Y-m-d H:i:s' strings,
		// never as serialized DateTime objects (export_as_array() upgrades
		// timestamp columns to DateTime for internal callers). Recursive so the
		// floor's format guarantee holds through derived embeds too.
		array_walk_recursive($out, function (&$value) {
			if ($value instanceof DateTime) {
				$value = $value->format('Y-m-d H:i:s');
			}
		});
		return $out;
	}

	/**
	 * The API shape of a row whose sealed content nobody present can open.
	 *
	 * Every readable plain column exports normally; a field the lock keeps
	 * closed exports as null, and the row carries content_locked: true so a
	 * client can show its locked placeholder. This is what lets a collection
	 * include the row honestly — a list that silently dropped locked rows would
	 * present itself as complete while omitting them. Derived fields are left
	 * out entirely: any of them may read sealed content on the way to its value.
	 */
	function export_for_api_locked() {
		$out = array();
		foreach (array_keys(static::$field_specifications) as $field) {
			if (static::is_unreadable_field($field)) {
				continue;
			}
			try {
				$value = $this->get($field);
			} catch (VaultLockedException $e) {
				$value = null;
			}
			// SQL function defaults like 'now()' are not dates on the wire.
			if ($value !== null && $this->is_timestamp_field($field)
					&& is_string($value) && preg_match('/^\w+\(\)$/', $value)) {
				$value = null;
			}
			$out[$field] = $value;
		}
		$out['key'] = $this->key;
		$out['content_locked'] = true;
		return $out;
	}

	function export_as_array() {
		$out_array = array();
		foreach(array_keys(static::$field_specifications) as $field) {
			$out_array[$field] = $this->get($field);
		}
		foreach(static::$field_specifications as $field_name => $spec) {
			if ($this->is_timestamp_field($field_name) && $this->get($field_name)) {
				$value = $this->get($field_name);
				// Skip SQL function defaults like 'now()' - these aren't parseable dates
				if (is_string($value) && preg_match('/^\w+\(\)$/', $value)) {
					$out_array[$field_name] = null;
					continue;
				}
				// Create DateTime object with UTC timezone (database values are in UTC)
				$out_array[$field_name] = new DateTime($value, new DateTimeZone('UTC'));
			}
		}
		$out_array['key'] = $this->key;
		return $out_array;
	}

	function get_without_prefix($key) {
		return $this->get(static::$prefix . '_' . $key);	
	}

	function get_array($key) {
		// Return a postgres array as a php array
		$value = $this->get($key);

		if ($value) {
			$formatted_values = explode(',', trim($value, '{}'));
			$output = array();
			foreach ($formatted_values as $formatted_value) {
				if (strpos($formatted_value, '"') === 0 &&
						strrpos($formatted_value, '"') === (strlen($formatted_value) - 1)) {
					$output[] = stripslashes(substr($formatted_value, 1, strlen($formatted_value) - 2));
				} else {
					$output[] = stripslashes($formatted_value);
				}
			}
			return $output;
		}
		return NULL;
	}

	function getString($key, $max_len=NULL, $ellipsis=TRUE) {
		if ($max_len !== NULL) {
			$length = strlen($this->get($key));
			$return_string = substr($this->get($key), 0, $max_len);
			if ($length > $max_len && $ellipsis) {
				$return_string .= '...';
			}
			return $return_string;
		}

		return $this->get($key);
	}

	function get_words_from_string($key, $max_chars=150, $max_words=75) {
		$words = preg_split('/\s+/', $this->get($key), $max_words + 1);
		if (count($words) == ($max_words + 1)) {
			unset($words[$max_words]);
		}

		$word_count = count($words);
		$cur_len = 0;
		for($i=0;$i<$word_count;$i++) {
			$cur_len += strlen($words[$i]) + 1;
			if ($cur_len > $max_chars) {
				return implode(' ', array_slice($words, 0, $i-1)) . '...';
			}
		}
		return implode(' ', $words);
	}

	function get_string_at_word_boundary($key, $max_chars=150, $ellipsis=TRUE) { 
		$rtn = $this->get($key);
		if (strlen($rtn) > $max_chars) { 
			$rtn = trim(substr($rtn, 0, $max_chars));
			$last_space = strrpos($rtn, " ");
			$last_nl = strrpos($rtn, "\n");
			$last_cr = strrpos($rtn, "\r");
			$rtn = trim(substr($rtn, 0, max($last_space, $last_nl, $last_cr)+1));
			$last_char = substr($rtn, strlen($rtn) - 1, strlen($rtn));
			if($ellipsis && ($last_char !== "." && $last_char !== "!" && $last_char !== "?")) {
				$rtn .= "...";
			}
		}
		return $rtn;
	}

	function add_flag($field, $flag) {
		$this->set($field, $this->get($field) | $flag);
	}

	function check_any_flag($field, $flag) {
		return $this->get($field) & $flag;
	}

	function check_flag($field, $flag) {
		return ($this->get($field) & $flag) === $flag;
	}

	function remove_flag($field, $flag) {
		$this->set($field, $this->get($field) & ~$flag);
	}

	function call_and_cache($method_name, $args=array()) { 
		if (!isset($this->cached_references[$method_name])) { 
			$this->cached_references[$method_name] = call_user_func_array(array('self', $method_name), $args);
		}
		return $this->cached_references[$method_name];
	}
	
	static function check_if_exists($key) {
		$data = SingleRowFetch(static::$tablename, static::$pkey_column,
			$key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($data === NULL) {
			return FALSE;
		}
		else{
			return TRUE;
		}
	}


	function load() {
		$this->loaded = TRUE;
		// Whatever the caller had set is gone; the sealed columns now hold the
		// row's ciphertext again.
		$this->sealed_dirty = array();
		$this->db_seal_state = null;
		if ($this->key === NULL) {
			throw new SystemBaseNoKeyError('Cannot load a '.static::$tablename.' object with no key.');
		}

		$this->data = SingleRowFetch(static::$tablename, static::$pkey_column,
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			error_log('This '.static::$tablename.' row ('.static::$pkey_column.'='.$this->key.') does not exist');
			return false;
			//throw new Exception('This '.static::$tablename.' row ('.static::$pkey_column.'='.$this->key.') does not exist');
		}		
		
	}

	function soft_delete(){
		self::assert_not_get_mutation('soft_delete');
		foreach(array_keys(get_class($this)::$field_specifications) as $field) {
			if($field == static::$prefix.'_delete_time'){
				$this->set(static::$prefix.'_delete_time', 'now()');
				$this->save();
				return true;				
			}
		}
		throw new Exception(
			'This '.static::$tablename.' column ('.static::$prefix.'_delete_time) does not exist');
	}
	
	function undelete(){
		foreach(array_keys(get_class($this)::$field_specifications) as $field) {
			if($field == static::$prefix.'_delete_time'){
				$this->set(static::$prefix.'_delete_time', NULL);
				$this->save();	
				return true;			
			}
		}
		throw new Exception(
			'This '.static::$tablename.' column ('.static::$prefix.'_delete_time) does not exist');
	}
	
	
	/**
	 * Map a database table name to its PHP model class name.
	 * Uses discover_model_classes() with static caching.
	 *
	 * @param string $table_name The database table name (e.g., 'sdd_devices')
	 * @return string|null The class name (e.g., 'SdDevice') or null if not found
	 */
	protected static function getModelClassForTable($table_name) {
		static $table_to_class_map = null;

		if ($table_to_class_map === null) {
			$table_to_class_map = [];
			$classes = LibraryFunctions::discover_model_classes([
				'require_tablename' => true,
				'require_field_specifications' => true,
				'include_plugins' => true,
			]);
			foreach ($classes as $class_name) {
				$table_to_class_map[$class_name::$tablename] = $class_name;
			}
		}

		return $table_to_class_map[$table_name] ?? null;
	}

	/**
	 * Perform a dry run of deletion to see what would be affected
	 * Returns structured array of all actions that would be taken
	 */
	public function permanent_delete_dry_run() {
		$db = DbConnector::get_instance()->get_db_link();
		$results = [
			'primary' => [
				'table' => static::$tablename,
				'key_column' => static::$pkey_column,
				'key' => $this->key,
				'action' => 'delete'
			],
			'dependencies' => [],
			'total_affected' => 1,  // Start with the primary record
			'can_delete' => true,
			'blocking_reasons' => []
		];

		// Get all deletion rules for this table from the database
		$sql = "SELECT * FROM del_deletion_rules
				WHERE del_source_table = ?
				ORDER BY del_id";
		$stmt = $db->prepare($sql);
		$stmt->execute([static::$tablename]);

		// Process each dependent relationship
		while ($rule = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$dep_table = $rule['del_target_table'];
			$dep_column = $rule['del_target_column'];

			// Check if records exist
			$count_sql = "SELECT COUNT(*) FROM {$dep_table} WHERE {$dep_column} = ?";
			$count_stmt = $db->prepare($count_sql);
			$count_stmt->execute([$this->key]);
			$count = $count_stmt->fetchColumn();

			if ($count > 0) {
				$dependency = [
					'table' => $dep_table,
					'column' => $dep_column,
					'count' => $count,
					'action' => $rule['del_action'],
					'action_value' => $rule['del_action_value'],
					'message' => $rule['del_message']
				];

				// Check if this would prevent deletion
				if ($rule['del_action'] === 'prevent') {
					$results['can_delete'] = false;
					$results['blocking_reasons'][] = $rule['del_message'] ??
						"Cannot delete: {$count} record(s) in {$dep_table} depend on this record";
					$dependency['blocks_deletion'] = true;
				} elseif ($rule['del_action'] === 'permanent_delete') {
					// Recursively count what model-level permanent_delete would affect
					$model_class = self::getModelClassForTable($dep_table);
					if ($model_class) {
						$dep_pkey = $model_class::$pkey_column;
						$select_sql = "SELECT {$dep_pkey} FROM {$dep_table} WHERE {$dep_column} = ?";
						$select_stmt = $db->prepare($select_sql);
						$select_stmt->execute([$this->key]);
						while ($row = $select_stmt->fetch(PDO::FETCH_ASSOC)) {
							$obj = new $model_class($row[$dep_pkey], true);
							$sub_result = $obj->permanent_delete_dry_run();
							$results['total_affected'] += $sub_result['total_affected'];
							if (!$sub_result['can_delete']) {
								$results['can_delete'] = false;
								$results['blocking_reasons'] = array_merge(
									$results['blocking_reasons'],
									$sub_result['blocking_reasons']
								);
							}
						}
					} else {
						$results['total_affected'] += $count;
					}
				} else {
					// Add to total affected count for all non-prevent actions
					$results['total_affected'] += $count;
				}

				// Surface a bad rule here rather than letting the preview look clean
				// and the real delete throw. permanent_delete() rejects these too.
				if (!in_array($rule['del_action'], self::$valid_deletion_actions, true)) {
					$results['can_delete'] = false;
					$results['blocking_reasons'][] =
						"Unknown deletion action '{$rule['del_action']}' for " .
						static::$tablename . " -> {$dep_table}.{$dep_column}";
					$dependency['blocks_deletion'] = true;
				}

				$results['dependencies'][] = $dependency;
			}
		}

		return $results;
	}

	/**
	 * Perform the actual permanent deletion
	 */
	public function permanent_delete($debug=false) {
		self::assert_not_get_mutation('permanent_delete');
		$db = DbConnector::get_instance()->get_db_link();

		$this_transaction = false;
		if(!$debug && !$db->inTransaction()){
			$db->beginTransaction();
			$this_transaction = true;
		}

		try {
			// Get all deletion rules for this table from the database
			// This is much more efficient than scanning information_schema
			$sql = "SELECT * FROM del_deletion_rules
					WHERE del_source_table = ?
					ORDER BY del_id";
			$stmt = $db->prepare($sql);
			$stmt->execute([static::$tablename]);

			// Process each dependent relationship
			while ($rule = $stmt->fetch(PDO::FETCH_ASSOC)) {
				$dep_table = $rule['del_target_table'];
				$dep_column = $rule['del_target_column'];

				// Check if records exist
				$count_sql = "SELECT COUNT(*) FROM {$dep_table} WHERE {$dep_column} = ?";
				$count_stmt = $db->prepare($count_sql);
				$count_stmt->execute([$this->key]);
				$count = $count_stmt->fetchColumn();

				if ($count > 0) {
					switch ($rule['del_action']) {
						case 'prevent':
							throw new SystemDisplayableError(
								"Cannot delete: $count records in {$dep_table} column {$dep_column} " .
								($rule['del_message'] ?? 'depend on this record')
							);

						case 'cascade':
							// Default action - delete dependent records
							if($debug){
								echo "DELETE FROM {$dep_table} WHERE {$dep_column} = {$this->key}<br>";
							} else {
								$del_sql = "DELETE FROM {$dep_table} WHERE {$dep_column} = ?";
								$del_stmt = $db->prepare($del_sql);
								$del_stmt->execute([$this->key]);
							}
							break;

						case 'null':
							if($debug){
								echo "UPDATE {$dep_table} SET {$dep_column} = NULL WHERE {$dep_column} = {$this->key}<br>";
							} else {
								$null_sql = "UPDATE {$dep_table} SET {$dep_column} = NULL WHERE {$dep_column} = ?";
								$null_stmt = $db->prepare($null_sql);
								$null_stmt->execute([$this->key]);
							}
							break;

						case 'set_value':
							$value = $rule['del_action_value'];
							if($debug){
								echo "UPDATE {$dep_table} SET {$dep_column} = {$value} WHERE {$dep_column} = {$this->key}<br>";
							} else {
								$set_sql = "UPDATE {$dep_table} SET {$dep_column} = ? WHERE {$dep_column} = ?";
								$set_stmt = $db->prepare($set_sql);
								$set_stmt->execute([$value, $this->key]);
							}
							break;

						case 'permanent_delete':
							// Load each dependent record as a model object and call its permanent_delete()
							// This enables custom cascade logic (e.g., SdDevice cleans up profiles/filters)
							$model_class = self::getModelClassForTable($dep_table);
							if ($model_class) {
								$dep_pkey = $model_class::$pkey_column;
								if($debug){
									echo "permanent_delete: Loading {$count} {$dep_table} records via {$model_class}<br>";
								} else {
									$select_sql = "SELECT {$dep_pkey} FROM {$dep_table} WHERE {$dep_column} = ?";
									$select_stmt = $db->prepare($select_sql);
									$select_stmt->execute([$this->key]);
									while ($row = $select_stmt->fetch(PDO::FETCH_ASSOC)) {
										$obj = new $model_class($row[$dep_pkey], true);
										$obj->permanent_delete($debug);
									}
								}
							} else {
								// Fallback to flat cascade if model class not found
								if($debug){
									echo "permanent_delete fallback: DELETE FROM {$dep_table} WHERE {$dep_column} = {$this->key}<br>";
								} else {
									$del_sql = "DELETE FROM {$dep_table} WHERE {$dep_column} = ?";
									$del_stmt = $db->prepare($del_sql);
									$del_stmt->execute([$this->key]);
								}
							}
							break;

						default:
							// An unrecognised action must never be a silent no-op: that
							// would leave the dependents untouched and delete the parent
							// anyway. A misspelled 'prevent' would then permit exactly the
							// deletion it was written to block.
							throw new SystemBaseException(
								"Unknown deletion action '{$rule['del_action']}' for " .
								static::$tablename . " -> {$dep_table}.{$dep_column}. " .
								"Valid actions: " . implode(', ', self::$valid_deletion_actions)
							);
					}
				}
			}

			// Delete the main record
			if($debug){
				echo "DELETE FROM " . static::$tablename . " WHERE " . static::$pkey_column . " = {$this->key}<br>";
			} else {
				$sql = "DELETE FROM " . static::$tablename . " WHERE " . static::$pkey_column . " = ?";
				$stmt = $db->prepare($sql);
				$stmt->execute([$this->key]);
			}

			if($this_transaction){
				$db->commit();
			}

			if(!$debug){
				$this->key = NULL;
			}

		} catch (Exception $e) {
			if($this_transaction){
				$db->rollback();
			}
			throw $e;
		}

		return true;
	}

	function safe_load_and_set($key, $value, $and_prepare=FALSE) { 
		DbConnector::BeginTransaction();
		try { 
			$this->load(TRUE);
			$this->set($key, $value);
			if ($and_prepare) { 
				$this->prepare();
			}
			$this->save();
		} catch(Exception $e) { 
			DbConnector::Rollback();
			throw $e;
		}
		DbConnector::Commit();
	}

	function load_from_data($data, $fields) {
		$this->loaded = TRUE;
		// Theoretically, we would love to check all of these "set" calls for field definition, however
		// the potential for massive slowdown is just too much, so we let it slide here and allow for the
		// database to return fields that we haven't defined (because they are obsolete or whatever) without
		// error.
		if (is_array($data)) {
			foreach($fields as $field) {
				$this->set($field, $data[$field], FALSE);
			}
		} else {
			foreach($fields as $field) {
				$this->set($field, $data->$field, FALSE);
			}
		}
		// $data is a raw database row, so its sealed columns are ciphertext —
		// the set() calls above marked them dirty as though they were plaintext.
		// (load_from_object() deliberately keeps its marks: it copies through
		// get(), so it really is holding plaintext to be re-sealed.)
		$this->sealed_dirty = array();
		$this->db_seal_state = null;
	}

	function load_from_object($other, $fields) {
		$this->loaded = TRUE;
		foreach($fields as $field) {
			$this->set($field, $other->get($field));
		}
	}

	// To prepare it is without error
	function prepare() {
		// Check unique constraints defined in field_specifications
		$duplicate = $this->check_unique_constraints();
		if ($duplicate) {
			// Use DisplayableUserException for user-friendly errors
			throw new DisplayableUserException($duplicate['message']);
		}
	}
	
	
	/**
	 * GET-is-read-only invariant. A GET request must never persist data — that is
	 * always either a misfired submission guard (see LibraryFunctions::isFormSubmission)
	 * or an intentional GET-action link that must opt in via self::$allow_get_mutation.
	 *
	 * Enforced at the single chokepoint every mutation passes through (save /
	 * soft_delete / permanent_delete), so it catches the whole bug class — including
	 * plugin, dynamic, and non-`if($input)` code a text lint can't see.
	 *
	 * CLI/cron/scheduled contexts (no REQUEST_METHOD) are exempt. Currently
	 * log-only everywhere while the GET-mutation worklist is burned down; once the
	 * logs are clean the dev (`debug` setting) throw below is re-enabled.
	 */
	private static function assert_not_get_mutation(string $op): void {
		if (PHP_SAPI === 'cli' || !isset($_SERVER['REQUEST_METHOD']))  return;
		if (self::$allow_get_mutation)                                 return;
		if ($_SERVER['REQUEST_METHOD'] !== 'GET')                      return;

		$msg = "GET-request mutation ({$op} on " . static::class . ') at '
		     . ($_SERVER['REQUEST_URI'] ?? '?') . ' — a GET must not persist data. '
		     . 'Guard the save with LibraryFunctions::isFormSubmission(), or, for an '
		     . 'intentional GET action, set SystemBase::$allow_get_mutation = true.';
		error_log('[GET_MUTATION] ' . $msg . "\n" . (new Exception())->getTraceAsString());

		// LOG-ONLY ROLLOUT: throw is intentionally disabled until the log-only
		// burndown is clean (spec rollout step 5). To flip dev to throw, uncomment:
		// if (Globalvars::get_instance()->get_setting('debug')) {
		//     throw new SystemBaseException($msg);
		// }
	}

	// And to save it to the database
	function save($debug=false) {
		self::assert_not_get_mutation('save');
		if ($this->data === NULL) {
			throw new SystemBaseException('This '.static::$tablename.' object has no data.');
		}
		
		// EXACT SAME BEHAVIOR AS CURRENT - just reading from field_specifications instead of separate arrays
		
		if ($this->key === NULL) {
			// SET INITIAL DEFAULT VALUES (exact current logic)
			foreach (static::$field_specifications as $field_name => $spec) {
				if (isset($spec['default'])) {
					if ($this->get($field_name) === NULL) {
						$this->set($field_name, $spec['default']);
					}
				}
			}
			
			// SET ZERO VARIABLES (exact current logic)
			foreach (static::$field_specifications as $field_name => $spec) {
				if (isset($spec['zero_on_create']) && $spec['zero_on_create'] === true) {
					if ($this->key === NULL && $this->get($field_name) === NULL) {
						$this->set($field_name, 0);
					}
				}
			}
		}

		// CHECK REQUIRED FIELDS (exact current logic, minus array support for Phase 1)
		foreach (static::$field_specifications as $field_name => $spec) {
			if (isset($spec['required']) && $spec['required'] === true) {
				if (is_null($this->get($field_name)) || $this->get($field_name) === '') {
					throw new SystemBaseException('Required field "' . $field_name . '" must be set.');
				}
			}
		}

		// CHECK DECLARED ENUMS: a field with 'allowed_values' accepts only members
		// of the set. NULL/empty passes — nullability and required-ness are their
		// own declarations. Loose comparison so int-backed enums match their
		// string form from the database.
		foreach (static::$field_specifications as $field_name => $spec) {
			if (!empty($spec['allowed_values']) && is_array($spec['allowed_values'])) {
				$enum_value = $this->get($field_name);
				if (!is_null($enum_value) && $enum_value !== '' && !in_array($enum_value, $spec['allowed_values'])) {
					throw new SystemBaseException('Field "' . $field_name . '" must be one of: '
						. implode(', ', $spec['allowed_values']) . ' — got "' . $enum_value . '".');
				}
			}
		}

		// CHECK VALIDATION RULES FROM field_specifications['validation']
		foreach (static::$field_specifications as $field_name => $spec) {
			if (isset($spec['validation']) && is_array($spec['validation'])) {
				$field_value = $this->get($field_name);
				$validation_rules = $spec['validation'];
				$custom_messages = $validation_rules['messages'] ?? array();

				foreach ($validation_rules as $rule_name => $rule_param) {
					// Skip 'messages' key
					if ($rule_name === 'messages') {
						continue;
					}

					$is_valid = true;
					$error_message = null;

					switch ($rule_name) {
						case 'required':
							if ($rule_param === true) {
								if (is_null($field_value) || $field_value === '') {
									$is_valid = false;
									$error_message = $custom_messages['required'] ?? "Field '$field_name' is required.";
								}
							}
							break;

						case 'email':
							if ($rule_param === true && !is_null($field_value) && $field_value !== '') {
								// Step 1: Format validation
								if (!filter_var($field_value, FILTER_VALIDATE_EMAIL)) {
									$is_valid = false;
									$error_message = $custom_messages['email'] ?? "Field '$field_name' must be a valid email address.";
								}
								// Step 2: DNS MX record check (fail-open) — skip when email_validation_mx_check is off.
								else {
									$_mx_settings = Globalvars::get_instance();
									if ((string)$_mx_settings->get_setting('email_validation_mx_check') !== '0') {
										require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));
										$domain = substr($field_value, strrpos($field_value, '@') + 1);
										if (!DnsResolver::domainAcceptsMail($domain)) {
											$is_valid = false;
											$error_message = $custom_messages['email_mx'] ?? "The email domain '$domain' does not appear to accept email.";
										}
									}
								}
							}
							break;

						case 'url':
							if ($rule_param === true && !is_null($field_value) && $field_value !== '') {
								if (!filter_var($field_value, FILTER_VALIDATE_URL)) {
									$is_valid = false;
									$error_message = $custom_messages['url'] ?? "Field '$field_name' must be a valid URL.";
								}
							}
							break;

						case 'minlength':
							if (is_numeric($rule_param) && !is_null($field_value) && $field_value !== '') {
								if (strlen($field_value) < $rule_param) {
									$is_valid = false;
									$error_message = $custom_messages['minlength'] ?? "Field '$field_name' must be at least $rule_param characters.";
								}
							}
							break;

						case 'maxlength':
							if (is_numeric($rule_param) && !is_null($field_value) && $field_value !== '') {
								if (strlen($field_value) > $rule_param) {
									$is_valid = false;
									$error_message = $custom_messages['maxlength'] ?? "Field '$field_name' must be no more than $rule_param characters.";
								}
							}
							break;

						case 'pattern':
							if (is_string($rule_param) && !is_null($field_value) && $field_value !== '') {
								if (!preg_match($rule_param, $field_value)) {
									$is_valid = false;
									$error_message = $custom_messages['pattern'] ?? "Field '$field_name' does not match the required format.";
								}
							}
							break;

						case 'numeric':
							if ($rule_param === true && !is_null($field_value) && $field_value !== '') {
								if (!is_numeric($field_value)) {
									$is_valid = false;
									$error_message = $custom_messages['numeric'] ?? "Field '$field_name' must be numeric.";
								}
							}
							break;
					}

					if (!$is_valid && $error_message) {
						throw new DisplayableUserException($error_message);
					}
				}
			}
		}

		//CHECK UNIQUE CONSTRAINTS (safety net)
		$duplicate = $this->check_unique_constraints();
		if ($duplicate) {
			throw new DisplayableUserException($duplicate['message']);
		}

		// A sealed row's $sealed_fields never travel through the ordinary column
		// build. save() rebuilds every column from get(), which DECRYPTS, so
		// letting them through would write plaintext straight back into the
		// sealed columns while the seal flag stayed true — the row both leaked
		// and unreadable, since every later read AEAD-opens plaintext and throws.
		//
		// Instead the sealing path owns them end to end. planSealOnSave() lifts
		// out whatever the caller set, does everything that can fail (owner,
		// vault, existing DEK) before any SQL runs, and applySealOnSave() writes
		// them as ciphertext once the row id is real.
		$seal_plan = $this->planSealOnSave();
		$skip_sealed = $this->sealedColumnsToSkip();
		if ($seal_plan !== null) {
			foreach (array_keys($seal_plan['values']) as $sealing_column) {
				$skip_sealed[$sealing_column] = true;
			}
		}

		$rowdata = array();
		foreach(array_keys(get_class($this)::$field_specifications) as $field) {
			if (isset($skip_sealed[$field])) continue;
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array(static::$pkey_column => $this->key);
			// Editing an existing record
		} else {
			$p_keys = NULL;
			// Creating a new record
			unset($rowdata[static::$pkey_column]);
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		// Build column metadata from field_specifications
		// Maps spec types to the same data_type/is_nullable strings that
		// information_schema.columns returns, so the binding code below
		// (copied verbatim from edit_table) works identically.
		$column_meta = array();
		foreach (static::$field_specifications as $col_name => $spec) {
			$spec_type = strtolower(preg_replace('/\(.*\)/', '', $spec['type'] ?? 'varchar'));
			switch ($spec_type) {
				case 'int4':
				case 'integer':
				case 'serial':
					$data_type = 'integer';
					break;
				case 'int2':
				case 'smallint':
					$data_type = 'smallint';
					break;
				case 'int8':
				case 'bigint':
				case 'bigserial':
					$data_type = 'bigint';
					break;
				case 'bool':
				case 'boolean':
					$data_type = 'boolean';
					break;
				case 'json':
					$data_type = 'json';
					break;
				case 'jsonb':
					$data_type = 'jsonb';
					break;
				default:
					$data_type = 'character varying';
					break;
			}
			$column_meta[$col_name]['data_type'] = $data_type;
			// Default to 'YES' (nullable) — PostgreSQL columns are nullable unless
			// explicitly declared NOT NULL. Only field_specifications with
			// 'is_nullable' => false map to 'NO'. This matches information_schema behavior.
			$column_meta[$col_name]['is_nullable'] = (isset($spec['is_nullable']) && $spec['is_nullable'] === false) ? 'NO' : 'YES';
		}

		// --- BEGIN: SQL generation and execution (from edit_table) ---

		if(count($rowdata) == 0){
			return FALSE;
		}

		if(is_null($p_keys)){
			$op = 'add';
			$sql = 'INSERT INTO ' . static::$tablename . ' ';

			$colphrase="";
			$valphrase="";
			foreach($rowdata as $column_name=>$column_val){
				if(is_array($column_val) || is_object($column_val) || !is_string($column_val) || $column_val !== "-NOUPDATE-"){
					$colphrase .= $column_name . ',';
						$valphrase .= ':' . $column_name . ',';

				}
			}

			$colphrase[strlen($colphrase)-1] = ' ';
			$valphrase[strlen($valphrase)-1] = ' ';

			$sql .= '(' . $colphrase . ') VALUES (' . $valphrase . ') ';
		}
		else{
			$op = 'edit';
			$sql = 'UPDATE ' . static::$tablename . ' SET ';

			foreach($rowdata as $column_name=>$column_val){
				if(is_array($column_val) || is_object($column_val) || !is_string($column_val) || $column_val !== "-NOUPDATE-"){
						$sql .= $column_name . '=:' . $column_name . ',';
				}
			}

			$sql[strlen($sql)-1] = ' ';

			//ADD WHERE CLAUSE
			$sql .= 'WHERE ';
			foreach($p_keys as $pname=>$pvalue){
				$sql .= $pname . '=:' . $pname . ' ';
				$sql .= ' AND ';
			}
			//REMOVE THE LAST ' AND '
			$sql = substr($sql, 0, strlen($sql)-5);
		}

		//BIND VALUES AND PREPARE STATEMENT
		$dbhelper->prepare_query($sql);

		foreach($rowdata as $column_name=>$column_val){
			if(is_array($column_val) || is_object($column_val) || !is_string($column_val) || $column_val !== "-NOUPDATE-"){
				if($column_meta[$column_name]['data_type'] == 'integer' || $column_meta[$column_name]['data_type'] == 'smallint' || $column_meta[$column_name]['data_type'] == 'bigint'){
					if($column_val === '') $column_val = NULL;
					$dbhelper->bind_value(":$column_name", $column_val, PDO::PARAM_INT);
				}
				else if($column_meta[$column_name]['data_type'] == 'boolean'){
					if($column_val===NULL){
						if($column_meta[$column_name]['is_nullable'] == 'YES') {
							$dbhelper->bind_value(":$column_name", NULL, PDO::PARAM_BOOL);
						} else {
							$dbhelper->bind_value(":$column_name", FALSE, PDO::PARAM_BOOL);
						}
					}
					else {
						// Normalize before binding. A bare truthiness test (the historical
						// bug) treated the non-empty string 'false' as TRUE, so any boolean
						// left to a string 'false' default was stored as true. Handle every
						// representation explicitly: native bools pass through; recognized
						// truthy strings (including the Postgres 't') map to true; everything
						// else (incl. 'false'/'f'/'0'/'no'/'off'/'') maps to false. The
						// explicit list is used over filter_var() because filter_var does not
						// recognize 't'/'f' and would silently flip 't' to false if the PDO
						// driver/config ever returned Postgres-style boolean strings.
						if (is_bool($column_val)) {
							$bool_val = $column_val;
						} else if (is_string($column_val)) {
							$bool_val = in_array(strtolower(trim($column_val)), ['t', 'true', '1', 'yes', 'on'], true);
						} else {
							$bool_val = (bool)$column_val;
						}
						$dbhelper->bind_value(":$column_name", $bool_val, PDO::PARAM_BOOL);
					}
				}
				else if($column_meta[$column_name]['data_type'] == 'json' || $column_meta[$column_name]['data_type'] == 'jsonb'){
					// JSON columns - auto-encode arrays/objects
					if (is_array($column_val) || is_object($column_val)) {
						$column_val = json_encode($column_val);
					}
					$dbhelper->bind_value(":$column_name", $column_val, PDO::PARAM_STR);
				}
				else{
					$dbhelper->bind_value(":$column_name", $column_val, PDO::PARAM_STR);
				}
			}
		}

		if($op == 'edit'){
			foreach($p_keys as $pname=>$pvalue){
				if($column_meta[$pname]['data_type'] == 'integer' || $column_meta[$pname]['data_type'] == 'smallint'){
					$dbhelper->bind_value(":$pname", $pvalue, PDO::PARAM_INT);
				}
				else{
					$dbhelper->bind_value(":$pname", $pvalue, PDO::PARAM_STR);
				}
			}
		}

		if($debug){
			$error_var_statement = '<pre>';
			$error_var_statement .= "Table: " . static::$tablename . "\n";
			foreach ($rowdata as $col=>$val){
				$error_var_statement .= "[$col]=>";
				if(is_null($val)) {
					$error_var_statement .= 'NULL';
				}
				else if($val === '') {
					$error_var_statement .= "''";
				}
				else if($val === FALSE) {
					$error_var_statement .= "FALSE";
				}
				else if($val === TRUE) {
					$error_var_statement .= "TRUE";
				}
				else  {
					$error_var_statement .= "$val";
				}
				$error_var_statement .= "\n";
			}
			if(is_null($p_keys)){
				$error_var_statement .= 'pkeys is null ' . "\n";
			}
			$error_var_statement .= 'Number of Keys: '. count($p_keys) . "\n";
			echo $error_var_statement;
			echo '</pre>';
		}

		// The row and its seal are one write. An insert is necessarily two
		// statements (the AEAD binds to the primary key, which does not exist
		// until the INSERT lands), so without this a failure between them would
		// leave a row whose content the caller believes was saved and sealed.
		$owns_seal_transaction = false;
		if ($seal_plan !== null && !$dblink->inTransaction()) {
			$dblink->beginTransaction();
			$owns_seal_transaction = true;
		}

		try {
			$dbhelper->execute_query();
		} catch (PDOException $e) {
			if ($owns_seal_transaction && $dblink->inTransaction()) {
				$dblink->rollBack();
			}
			// Add context about the operation
			$operation = $op == 'add' ? 'INSERT' : 'UPDATE';
			$context = "Database $operation failed on table '" . static::$tablename . "'";

			if ($op == 'edit' && $p_keys) {
				$context .= " for record: " . json_encode($p_keys);
			}

			$dbhelper->handle_query_error(
				new PDOException($context . " - " . $e->getMessage(), (int)$e->getCode(), $e)
			);
		}

		if($op == 'edit'){
			if($debug){
				exit;
			}
			$this->key = $p_keys[static::$pkey_column];
		}
		else{
			$seq = static::$tablename . '_' . static::$pkey_column . '_seq';

			if($debug){
				echo "Sequence: $seq\n";
				exit;
			}

			$this->key = $dblink->lastInsertId($seq);
		}

		if ($seal_plan !== null) {
			try {
				$this->applySealOnSave($seal_plan);
				if ($owns_seal_transaction) {
					$dblink->commit();
				}
			} catch (Throwable $e) {
				if ($owns_seal_transaction && $dblink->inTransaction()) {
					$dblink->rollBack();
					$this->key = ($op == 'edit') ? $p_keys[static::$pkey_column] : NULL;
				}
				throw $e;
			}
		}

		// --- END: SQL generation and execution ---

		// AUTO CACHE INVALIDATION - Simple approach
		// Only invalidate the model's own URL if it has one. Check for
		// $url_namespace (the thing get_url() actually needs), not
		// method_exists() — every SystemBase inherits get_url(), so the old
		// check let through models that legitimately have no public URL
		// (Component, Plugin, etc.) and triggered get_url()'s "namespace
		// not set" error_log on every save.
		if (class_exists('StaticPageCache') && isset(static::$url_namespace)) {
			$url = $this->get_url();
			if ($url) {
				require_once(PathHelper::getIncludePath('includes/StaticPageCache.php'));
				StaticPageCache::invalidateUrl($url);
			}
		}


	}

	/**
	 * Layer 2 — deny-by-default row scope. The base contract is owner-or-staff:
	 * a caller may touch this row only if they own it (the conventional
	 * {prefix}_usr_user_id column matches current_user_id) or they are staff
	 * (permission >= 5). Models with no owner column fall to staff-only — safe for
	 * tables with no per-user ownership. Overrides go both ways: a publicly
	 * readable model (posts, pages) overrides authenticate_read to a no-op, and a
	 * personal-credential model (Passkey) tightens it to owner-ONLY, no staff
	 * bypass. The contract is throw-to-deny.
	 */
	/**
	 * The platform ownership rule, shared by the record gates
	 * (authenticate_read / authenticate_write) and the File serving gate
	 * (File::is_viewable): a request is allowed when the session user owns this
	 * row (the conventional {prefix}_usr_user_id column matches) or is staff
	 * (permission >= 5). Models with no owner column fall to staff-only. One rule,
	 * one place — the record gate and the serving gate can never drift apart.
	 *
	 * @return bool true when allowed
	 */
	protected function is_owner_or_admin($user_id, $permission) {
		$owner_col = static::$prefix . '_usr_user_id';
		$owner_matches = array_key_exists($owner_col, static::$field_specifications)
			&& $this->get($owner_col) == $user_id;
		return $owner_matches || (int)$permission >= 5;
	}

	function authenticate_read($data) {
		if (!$this->is_owner_or_admin($data['current_user_id'], $data['current_user_permission'])) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to view this entry in ' . static::$tablename);
		}
	}

	function authenticate_write($data) {
		if (!$this->is_owner_or_admin($data['current_user_id'], $data['current_user_permission'])) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}

	/**
	 * One of this record's times, in the viewer's timezone, ready to print.
	 *
	 * Every time in the database is UTC, and every time on screen should be the
	 * viewer's — so the conversion is the same three arguments at every call
	 * site, and getting one of them wrong shows a plausible time that is simply
	 * the wrong one. This is the display-side twin of the conversion FormWriter
	 * already does on the way into a form.
	 *
	 * Returns FALSE for an empty field, so an optional date renders as nothing
	 * rather than as "now".
	 *
	 * LibraryFunctions::convert_time() remains for values that are not model
	 * fields, and for a timezone that is not the viewer's.
	 *
	 * @param string $field The field name, e.g. 'usr_create_time'.
	 * @param string $format Any date() format string.
	 * @return string|false
	 */
	public function get_local($field, $format = 'M j, Y g:i A T') {
		return LibraryFunctions::convert_time(
			$this->get($field),
			'UTC',
			SessionControl::get_instance()->get_timezone(),
			$format
		);
	}

	/**
	 * Refuse unless the signed-in user may change this row.
	 *
	 * Takes the session, like authenticate_tier() does, so callers never
	 * assemble the identity array themselves — hand-assembling it is how a
	 * permission level gets hardcoded into a check that should have read the
	 * session. Subclass overrides of authenticate_write() apply unchanged.
	 *
	 * @param SessionControl $session
	 * @throws SystemAuthenticationError
	 */
	public function assert_can_write($session) {
		$this->authenticate_write(self::session_identity($session));
	}

	/**
	 * Refuse unless the signed-in user may see this row.
	 *
	 * @param SessionControl $session
	 * @throws SystemAuthenticationError
	 */
	public function assert_can_read($session) {
		$this->authenticate_read(self::session_identity($session));
	}

	/**
	 * The identity array the authenticate_* methods take.
	 *
	 * @param SessionControl $session
	 * @return array
	 */
	protected static function session_identity($session) {
		return array(
			'current_user_id'         => $session->get_user_id(),
			'current_user_permission' => $session->get_permission(),
		);
	}

	/**
	 * Check whether the current user has sufficient tier access to view this entity.
	 *
	 * @param SessionControl $session  The current session
	 * @return array  [
	 *     'allowed'          => bool,
	 *     'reason'           => string|null ('no_tier'|'tier_too_low'|'not_logged_in'),
	 *     'required_level'   => int|null,
	 *     'user_level'       => int|null,
	 *     'required_tier'    => SubscriptionTier|null,
	 * ]
	 */
	public function authenticate_tier($session) {
		$prefix = static::$prefix;
		$min_level_field = $prefix . '_tier_min_level';

		// Check if this entity even has the tier field
		if (!array_key_exists($min_level_field, static::$field_specifications)) {
			return ['allowed' => true];
		}

		$min_level = $this->get($min_level_field);

		// No tier requirement — access granted
		if ($min_level === null || $min_level <= 0) {
			return ['allowed' => true];
		}

		// Admins always see gated content
		if ($session->get_permission() >= 5) {
			return ['allowed' => true];
		}

		// Check early access expiry — if the delay has elapsed, content is now public
		$delay_field = $prefix . '_tier_public_after_hours';
		if (array_key_exists($delay_field, static::$field_specifications)) {
			$delay_hours = $this->get($delay_field);
			if ($delay_hours > 0) {
				$publish_time = $this->_get_publish_time();
				if ($publish_time) {
					$public_at = LibraryFunctions::time_shift($publish_time, $delay_hours . ' hours');
					if ($public_at <= gmdate('Y-m-d H:i:s')) {
						return ['allowed' => true];
					}
				}
			}
		}

		require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));

		$user_id = $session->get_user_id();

		// Not logged in
		if (!$user_id) {
			return [
				'allowed' => false,
				'reason' => 'not_logged_in',
				'required_level' => $min_level,
				'user_level' => null,
				'required_tier' => SubscriptionTier::GetByColumn('sbt_tier_level', $min_level),
			];
		}

		// Check tier
		if (SubscriptionTier::UserHasMinimumTier($user_id, $min_level)) {
			return ['allowed' => true];
		}

		// Access denied — insufficient tier
		$user_tier = SubscriptionTier::GetUserTier($user_id);
		return [
			'allowed' => false,
			'reason' => 'tier_too_low',
			'required_level' => $min_level,
			'user_level' => $user_tier ? $user_tier->get('sbt_tier_level') : 0,
			'required_tier' => SubscriptionTier::GetByColumn('sbt_tier_level', $min_level),
		];
	}

	/**
	 * Get the publish time for this entity, used by early access calculation.
	 * Entities override this if their publish time field doesn't follow the
	 * standard {prefix}_published_time naming convention.
	 */
	protected function _get_publish_time() {
		$prefix = static::$prefix;

		// Try {prefix}_published_time (posts, pages)
		$published_field = $prefix . '_published_time';
		if (array_key_exists($published_field, static::$field_specifications)) {
			return $this->get($published_field);
		}

		// Fall back to {prefix}_create_time (page content, events, etc.)
		$create_field = $prefix . '_create_time';
		if (array_key_exists($create_field, static::$field_specifications)) {
			return $this->get($create_field);
		}

		return null;
	}

	function is_owner($session) { return FALSE; }

	function get_json() { 
		// build the json-ready PHP object (to be passed into json_encode) 
		$json = array();
		foreach (array_keys(static::$field_specifications) as $field) {
			if ($this->is_json_field($field)) { 
				// make sanitary for display
				$json[$field] = htmlspecialchars($this->get($field));
			}
		}
		return $json;
	}
	
	//TESTS FOR THIS CLASS
	static function test($debug=false, $verbose=false, $read_only=false){
		$current_class = get_called_class();
		$result = true;
		
		// Check if we should run only Multi tests
		if (defined('MULTI_TESTS_ONLY') && MULTI_TESTS_ONLY) {
			// Only run Multi tests
			if (!class_exists('MultiModelTester')) {
				require_once(PathHelper::getBasePath() . '/tests/models/MultiModelTester.php');
			}
			$multi_tester = new MultiModelTester($current_class);
			return $multi_tester->test($debug);
		}
		
		// Check if we should skip Multi tests
		$skip_multi = false;
		if (defined('SINGLE_TESTS_ONLY') && SINGLE_TESTS_ONLY) {
			$skip_multi = true;
		}
		
		// Run single model tests (unless we're in Multi-only mode)
		if (!defined('MULTI_TESTS_ONLY') || !MULTI_TESTS_ONLY) {
			// Load testing infrastructure on demand
			if (!class_exists('ModelTester')) {
				require_once(PathHelper::getBasePath() . '/tests/models/ModelTester.php');
			}
			
			$tester = new ModelTester($current_class);
			$result = $tester->test(null, $debug, $read_only);
		}
		
		// Multi tests only when explicitly requested AND not disabled
		if (!$skip_multi) {
			$run_multi = false;
			
			// Check multiple ways to enable Multi testing
			if (isset($_GET['test_multi']) && $_GET['test_multi']) {
				$run_multi = true;
			} else if (getenv('TEST_MULTI')) {
				$run_multi = true;
			} else if (defined('TEST_MULTI') && TEST_MULTI) {
				$run_multi = true;
			}
			
			if ($run_multi) {
				if (!class_exists('MultiModelTester')) {
					require_once(PathHelper::getBasePath() . '/tests/models/MultiModelTester.php');
				}
				$multi_tester = new MultiModelTester($current_class);
				$multi_result = $multi_tester->test($debug);
				$result = $result && $multi_result;
			}
		}
		
		return $result;
	}	

}

/**
 * Thrown when a Multi collection is asked to filter by an option key its
 * getMultiResults() does not implement. Distinct from SystemBaseException so
 * transport shells (the REST collection endpoint) can map it to a caller
 * error (400) rather than a server fault.
 */
class UnknownMultiOptionException extends SystemBaseException {}

/** Thrown when a sort names something that is not a column of the table being
 *  queried. A sort column cannot be a bound parameter — SQL has no placeholder
 *  for an identifier — so it is validated instead. */
class UnsortableColumnException extends SystemBaseException {}

abstract class SystemMultiBase implements IteratorAggregate, Countable {

	private $multi_data;
	protected $cached_references;
	protected static $default_options = array();

	/** Per-class cache for known_option_keys(); null = source unreadable. */
	private static $known_option_keys_cache = array();

	public $loaded;
	public $loadable;
	public $options;
	public $order_by;
	public $limit;
	public $offset;
	public $operation;
	public $write_lock;

	/**
	 * API collection owner-scope (§4.5). When set to [column, value] by the REST
	 * collection endpoint for a non-staff caller, _get_resultsv2() ANDs
	 * "column = value" onto every query — load AND count_all — so the result page
	 * and num_results both reflect only the caller's rows, with no count disclosure.
	 * Applied as a mandatory outer AND so an OR-operation Multi cannot escape it.
	 */
	public $api_owner_scope = null;

	function __construct($options=array(), $order_by=array(), $limit=NULL, $offset=NULL, $operation='AND', $write_lock=FALSE) {
		$this->multi_data = array();
		$this->loaded = FALSE;

		if (is_array($options)) {
			$this->options = array_merge(static::$default_options, $options);
		} 
		else{
			$this->options = static::$default_options;
		}

		if (is_array($order_by)) {
			$this->order_by = $order_by;
		} 
		else{
			$this->order_by = array();
		}

		
		
	/*
		if ($options !== NULL) {
			$this->options = array_merge(static::$default_options, $options);
		} else {
			$this->options = NULL;
		}
		*/

		//$this->order_by = $order_by;
		$this->limit = (int)$limit;
		$this->offset = (int)$offset;
		$this->operation = $operation;
		$this->write_lock = $write_lock;
		$this->cached_references = array();

		if ($options === NULL) {
			$this->loadable = FALSE;
		} else {
			$this->loadable = TRUE;
		}
	}

	/**
	 * The option keys this collection implements, derived from its source:
	 * every literal $this->options['key'] mention in the class file of this
	 * class and each ancestor below SystemMultiBase (any method — filter
	 * builders and helpers alike), plus the keys of $default_options. Derived
	 * rather than declared so the set can never drift from the code that does
	 * the filtering. Returns null if a source file cannot be read, in which
	 * case enforcement is skipped — introspection failure must never block a
	 * query.
	 */
	private function known_option_keys() {
		$class = get_class($this);
		if (!array_key_exists($class, self::$known_option_keys_cache)) {
			$keys = array_keys(static::$default_options);
			try {
				$seen_files = array();
				for ($rc = new ReflectionClass($class); $rc && $rc->getName() !== 'SystemMultiBase'; $rc = $rc->getParentClass()) {
					$file = $rc->getFileName();
					if (!$file || isset($seen_files[$file])) {
						continue;
					}
					$seen_files[$file] = true;
					$src = @file_get_contents($file);
					if ($src === false) {
						self::$known_option_keys_cache[$class] = null;
						return null;
					}
					// A pass-through class iterates its options generically and
					// maps every key to a column — there is no fixed vocabulary
					// to enforce, and a bogus key already fails loudly as a SQL
					// error. Skip enforcement for it.
					if (preg_match('/foreach\s*\(\s*\$this->options\b/', $src)) {
						self::$known_option_keys_cache[$class] = null;
						return null;
					}
					if (preg_match_all('/options\[\s*[\'"]([A-Za-z0-9_]+)[\'"]\s*\]/', $src, $m)) {
						$keys = array_merge($keys, $m[1]);
					}
				}
				$keys = array_values(array_unique($keys));
			} catch (\Throwable $e) {
				$keys = null;
			}
			self::$known_option_keys_cache[$class] = $keys;
		}
		return self::$known_option_keys_cache[$class];
	}

	/**
	 * The model this collection queries, but only when it queries that model's
	 * own table. A collection over a join or a view gets NULL: there,
	 * $field_specifications is not the authority on what the query's columns
	 * are, so core cannot infer filters from it. Same carve-out
	 * assert_sortable_column() applies to sorting.
	 *
	 * @param string $table The table the query targets.
	 * @return string|null Model class name.
	 */
	private function own_table_model($table) {
		$model = isset(static::$model_class) ? static::$model_class : null;
		if (!$model || !class_exists($model)) {
			return null;
		}
		if (!isset($model::$tablename) || $model::$tablename !== $table) {
			return null;
		}
		if (empty($model::$field_specifications) || empty($model::$prefix)) {
			return null;
		}
		return $model;
	}

	/**
	 * The soft-delete column of a collection's model, when it has one.
	 *
	 * @param string $table
	 * @return string|null
	 */
	private function delete_time_column($table) {
		$model = $this->own_table_model($table);
		if (!$model) {
			return null;
		}
		$column = $model::$prefix . '_delete_time';
		return isset($model::$field_specifications[$column]) ? $column : null;
	}

	/**
	 * Core's implementation of the `deleted` option: TRUE selects soft-deleted
	 * rows, FALSE selects live ones. Every model carrying a {prefix}_delete_time
	 * column gets it without writing it out.
	 *
	 * Omitting the option leaves the filter off, exactly as before — a
	 * collection that wants deleted rows excluded by default still says so
	 * itself.
	 *
	 * @param string $table
	 * @return array
	 */
	private function deleted_option_filter($table) {
		if (!is_array($this->options) || !isset($this->options['deleted'])) {
			return array();
		}
		$column = $this->delete_time_column($table);
		if (!$column) {
			return array();
		}
		return array($column => $this->options['deleted'] ? 'IS NOT NULL' : 'IS NULL');
	}

	/**
	 * Filters for option keys that name a declared column of the collection's
	 * model — either in full (`usr_email`) or without the table prefix
	 * (`email`). The value binds as a parameter typed from the field spec; a
	 * NULL value means "this column is empty", since `column = NULL` matches
	 * nothing in SQL and is never what a caller meant.
	 *
	 * Only keys the collection does not implement itself are considered, so a
	 * hand-written filter always wins over the inferred one. Collections that
	 * map every option to a column generically are skipped — they already do
	 * this.
	 *
	 * @param string $table
	 * @return array
	 */
	private function column_option_filters($table) {
		$specs = null;
		$out   = array();
		foreach ($this->column_option_columns($table, $specs) as $key => $column) {
			$value = $this->options[$key];
			if ($value === NULL) {
				$out[$column] = 'IS NULL';
			} else {
				$out[$column] = array($value, self::pdo_type_for_spec($specs[$column]));
			}
		}
		return $out;
	}

	/**
	 * option key => column name for every option this collection does not
	 * implement itself but which names one of its model's columns.
	 *
	 * @param string $table
	 * @param array|null $specs Receives the model's field specifications.
	 * @return array
	 */
	private function column_option_columns($table, &$specs = null) {
		if (!is_array($this->options) || $this->options === array()) {
			return array();
		}
		$known = $this->known_option_keys();
		if ($known === null) {
			return array();
		}
		$model = $this->own_table_model($table);
		if (!$model) {
			return array();
		}

		$specs  = $model::$field_specifications;
		$prefix = $model::$prefix;
		$out    = array();

		foreach (array_keys($this->options) as $key) {
			if (in_array($key, $known, true)) {
				continue;
			}
			if (isset($specs[$key])) {
				$out[$key] = $key;
			} elseif (isset($specs[$prefix . '_' . $key])) {
				$out[$key] = $prefix . '_' . $key;
			}
		}

		return $out;
	}

	/**
	 * The PDO bind type a declared column's value should carry.
	 *
	 * @param array $spec One entry of $field_specifications.
	 * @return int
	 */
	private static function pdo_type_for_spec($spec) {
		$type = strtolower(isset($spec['type']) ? $spec['type'] : '');
		if (strpos($type, 'bool') !== false) {
			return PDO::PARAM_BOOL;
		}
		if (strpos($type, 'int') !== false || strpos($type, 'serial') !== false) {
			return PDO::PARAM_INT;
		}
		return PDO::PARAM_STR;
	}

	/**
	 * Refuse a query whose options include a key this collection does not
	 * implement. Without this, an unknown key is silently dropped and the
	 * collection returns MORE rows than the caller asked for — which for an
	 * ownership filter is a data-exposure bug that reads as correct code.
	 *
	 * @param string $table The table the query targets, so the keys core itself
	 *                      answers for this model count as implemented.
	 */
	private function assert_options_known($table = null) {
		if (!is_array($this->options) || $this->options === array()) {
			return;
		}
		$known = $this->known_option_keys();
		if ($known === null) {
			return;
		}
		if ($table !== null) {
			// Keys core answers for this model, which no class has to write out.
			if ($this->delete_time_column($table)) {
				$known[] = 'deleted';
			}
			$known = array_merge($known, array_keys($this->column_option_columns($table)));
		}
		$unknown = array_diff(array_keys($this->options), $known);
		if ($unknown) {
			throw new UnknownMultiOptionException(get_class($this) . ' does not implement option'
				. (count($unknown) > 1 ? 's' : '') . " '" . implode("', '", $unknown)
				. "' — the filter would be silently ignored and the query would return unfiltered rows.");
		}
	}

	/**
	 * Validate one ORDER BY column. A sort column is an IDENTIFIER, and SQL has
	 * no bind placeholder for an identifier — it has to be interpolated, so the
	 * only safe form is to prove it is a column name before it goes near the
	 * query. Everything reaching a sort passes through here, not just the REST
	 * collection endpoint, so a future internal caller that forwards user input
	 * is covered by construction rather than by remembering to sanitize.
	 *
	 * Two gates, in order of strength:
	 *
	 *  1. It must be a bare identifier. This alone closes the injection: with no
	 *     spaces, parentheses, commas or quotes, an attacker cannot form the
	 *     expression a boolean oracle needs. An unrecognized identifier can only
	 *     ever be a nonexistent column, which is a SQL error, not a disclosure.
	 *  2. When the query is against the model's own table, it must be a declared
	 *     column. This turns a 500 into a named refusal and removes the
	 *     column-probing side channel that gate 1 would otherwise leave. Skipped
	 *     when a collection queries some other table (a join or a view), where
	 *     $field_specifications is not the authority on what is sortable.
	 */
	private function assert_sortable_column($column, $table) {
		$column = (string)$column;
		if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
			throw new UnsortableColumnException(
				'Sort column must be a plain column name.');
		}

		$model = isset(static::$model_class) ? static::$model_class : null;
		if ($model && class_exists($model)
			&& isset($model::$tablename) && $model::$tablename === $table
			&& !empty($model::$field_specifications)
			&& !array_key_exists($column, $model::$field_specifications)) {
			throw new UnsortableColumnException(
				"'" . $column . "' is not a sortable column of " . $table . '.');
		}

		return $column;
	}

	protected function _get_resultsv2($table, $filters = [], $sorts = [], $only_count = false, $debug = false) {
		$this->assert_options_known($table);

		// Filters core derives from the model itself. Union with `+` so a
		// filter the collection built by hand always wins over the inferred
		// one for the same column.
		$filters = $filters + $this->deleted_option_filter($table) + $this->column_option_filters($table);

		$where_clauses = [];
		$bind_params = [];
		$operation = $this->operation;

		// Extract prefix from table name (everything before first underscore)
		$prefix = substr($table, 0, strpos($table, '_'));

		foreach ($filters as $column => $condition) {
			if (is_array($condition)) {
				// If an array is passed, assume it contains [value, PDO type]
				$where_clauses[] = "$column = ?";
				$bind_params[] = [$condition[0], $condition[1]];
			} else {
				// Assume the caller is passing a raw SQL condition
				$where_clauses[] = "$column $condition";
			}
		}

		$inner_sql = !empty($where_clauses) ? implode(" $operation ", $where_clauses) : '';

		// API owner-scope: a mandatory outer AND that the per-class $operation (which may
		// be OR) cannot escape. Bound last, matching its position at the end of the SQL.
		if ($this->api_owner_scope !== null) {
			list($owner_col, $owner_val) = $this->api_owner_scope;
			$owner_clause = "$owner_col = ?";
			$inner_sql = ($inner_sql !== '') ? '(' . $inner_sql . ') AND ' . $owner_clause : $owner_clause;
			$bind_params[] = [$owner_val, PDO::PARAM_INT];
		}

		$where_sql = ($inner_sql !== '') ? 'WHERE ' . $inner_sql : '';

		// Handle order by with prefix inference
		$order_sql = '';
		if (!empty($sorts)) {
			$order_clauses = [];
			foreach ($sorts as $column => $direction) {
				// If prefix exists and column doesn't already have it, add it
				if ($prefix && strpos($column, $prefix . '_') !== 0) {
					$column = $prefix . '_' . $column;
				}
				$column = $this->assert_sortable_column($column, $table);
				$direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC'; // Ensure only ASC or DESC
				$order_clauses[] = "$column $direction";
			}
			$order_sql = 'ORDER BY ' . implode(', ', $order_clauses);
		}

		if ($only_count) {
			$sql = "SELECT COUNT(*) FROM $table $where_sql";
		} else {
			$limit_offset_sql = $this->generate_limit_and_offset(false);
			$sql = "SELECT * FROM $table $where_sql $order_sql $limit_offset_sql";
		}

		if ($debug) {
			echo "SQL Query: $sql<br>\n";
			echo "Inferred prefix: $prefix<br>\n";
		}

		// Prepare and execute query
		$q = DbConnector::GetPreparedStatement($sql);
		foreach ($bind_params as $index => $param) {
			$q->bindValue($index + 1, $param[0], $param[1]);
		}

		$q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);
		if($only_count){
			return $q->fetchColumn();
		}
		else{
			return $q;
		}
	}

	function get_sql_builder() {
		return new SQLBuilder(static::$table_name, static::$table_primary_key, $this->limit, $this->offset, $this->operation, $this->write_lock);
	}

	function set_write_lock($write_lock) {
		$this->write_lock = $write_lock;
	}

	function generate_limit_and_offset($include_write_lock=TRUE) {
		
		if(!is_numeric($this->limit) || !is_numeric($this->offset)){
			//IF THEY AREN'T INTEGERS FAIL BUT DON'T LOG THE FAILURE (SPAM)
			throw new SystemDisplayableError('Bad limit or offset');
		}
		
		$sql = '';

		if ($this->limit) {
			$sql .= " LIMIT {$this->limit}";
		}

		if ($this->offset) {
			$sql .= " OFFSET {$this->offset}";
		}

		if ($include_write_lock) {
			$sql .= $this->generate_write_lock_string();
		}

		return $sql;
	}

	function generate_write_lock_string() {
		if ($this->write_lock) {
			return ' FOR UPDATE';
		}
		return '';
	}

	function authenticate_read($data) {
		foreach ($this as $child) {
			$child->authenticate_read($data);
		}
	}

	/**
	 * Refuse unless the signed-in user may see every member of the collection.
	 *
	 * @param SessionControl $session
	 * @throws SystemAuthenticationError
	 */
	public function assert_can_read($session) {
		$this->assert_can_read($session);
	}

	function usort($callback) {
		usort($this->multi_data, $callback);
	}

	function load($debug = false) {
		// Make sure to clear out the existing array when we load in data
		$this->clear();
		if ($this->loadable) {
			$this->loaded = TRUE;
		} else {
			throw new SystemBaseException('This MultiBase was explicitly set unloaded with $options === NULL');
		}
		
		// Generic implementation for all Multi classes
		if (!isset(static::$model_class)) {
			throw new SystemBaseException("Multi class " . get_class($this) . " must define \$model_class property");
		}
		
		$childClassName = static::$model_class;
		
		// Verify the child class exists
		if (!class_exists($childClassName)) {
			throw new SystemBaseException("Model class {$childClassName} not found for " . get_class($this));
		}
		
		// Get the primary key column from the child class
		$pkey_column = $childClassName::$pkey_column;
		
		// Get results from the concrete implementation
		$q = $this->getMultiResults(false, $debug);
		
		// Load each row into a child object
		foreach($q->fetchAll() as $row) {
			// Create child object using the primary key value
			$child = new $childClassName($row->$pkey_column);
			
			// Load data into the child object
			$child->load_from_data($row, array_keys($childClassName::$field_specifications));
			
			// Add to collection
			$this->add($child);
		}
	}
	
	/**
	 * Generic count_all implementation for Multi classes
	 */
	function count_all($debug = false) {
		return $this->getMultiResults(TRUE, $debug);
	}
	
	/**
	 * Abstract method that must be implemented by concrete Multi classes
	 * This method handles the specific query building for each Multi class
	 */
	abstract protected function getMultiResults($only_count = false, $debug = false);

	// This is a very special function that takes the key of a specific timestamp column of a
	// table, and after loading all of the elements selected in this MultiBase, will set that
	// lock to $duration time in the future.  If you use the $write_lock feature of your load
	// and make sure to only load elements where the lock column is in the past, you are guaranteed
	// never to load the same element within the $duration time period.
	// Sample usage:  If you are setting up a system that is going to be used concurrently by
	// many users, you might want to set a lock time of 1 hour (the default) as your are loading elements.
	// This means that when a user loads a page, another user cannot load that same element for at
	// least one hour, which means users wont be accidentally doing the same work over and over.
	function load_and_lock($lock_key, $duration='1 hour') {
		DbConnector::BeginTransaction();
		$this->load();
		$future_time = new DateTime('now + ' . $duration);
		foreach ($this as $row) {
			$row->set($lock_key, $future_time->format(DATE_ATOM));
			$row->save();
		}
		DbConnector::Commit();
		return $this;
	}

	function clear() {
		$this->multi_data = array();
	}

	function contains($item) {
		return $this->contains_key($item->key);
	}

	function contains_key($key) {
		foreach($this as $existing_item) {
			if ($existing_item->key == $key) {
				return TRUE;
			}
		}
		return FALSE;
	}

	function add($value) {
		// A hand-populated collection is a loaded collection: adding a member
		// answers the "has this been filled in yet?" question that
		// autoload_if_needed() asks, so building one by hand never triggers a
		// query that would throw the hand-built members away.
		$this->loaded = TRUE;
		$this->multi_data[] = $value;
	}

	function get_by_key($key) {
		foreach($this as $existing_item) {
			if ($existing_item->key == $key) {
				return $existing_item;
			}
		}
		return NULL;
	}

	function get($location) {
		if (isset($this->multi_data[$location])) {
			return $this->multi_data[$location];
		} else {
			throw new SystemBaseException('Given location doesn\'t exist.');
		}
	}

	function is_valid($location) {
		return isset($this->multi_data[$location]);
	}

	function remove_by_key($key) {
		$array_iterator = $this->getIterator();
		foreach($array_iterator as $existing_item) {
			if ($existing_item->key == $key) {
				unset($this->multi_data[$array_iterator->key()]);
			}
		}
	}

	function remove($location) {
		unset($this->multi_data[$location]);
	}

	function re_index() {
		$this->multi_data = array_values($this->multi_data);
	}

	function count(): int {
		$this->autoload_if_needed();
		return count($this->multi_data);
	}

	function getIterator(): Traversable {
		$this->autoload_if_needed();
		return new ArrayIterator($this->multi_data);
	}

	/**
	 * Run the query the collection was constructed with, the first time anyone
	 * asks what is in it. Constructing a collection states the question;
	 * iterating or counting it is what asks for the answer, so no caller has to
	 * remember a separate load() step first.
	 *
	 * A collection built by hand (add()) or explicitly marked unloadable
	 * ($options === NULL) is left alone.
	 */
	private function autoload_if_needed() {
		if (!$this->loaded && $this->loadable) {
			$this->load();
		}
	}

	/**
	 * Reading a property a collection does not have is always a mistake, and
	 * the mistake it almost always is has a name: results live in a private
	 * array reached by iteration, so `$multi->results` reads as working code
	 * while the loop body never runs.
	 */
	public function __get($name) {
		throw new SystemBaseException(get_class($this) . " has no property '" . $name
			. "'. Collections are iterated directly: foreach (\$multi as \$item).");
	}

	function incremental_iterator($incremental_limit=200) {
		return new SystemMultiBaseIncremental(clone $this, $incremental_limit);
	}
}

class SystemMultiBaseIncremental implements Iterator {

	private $overall_position;
	private $incremental_position;
	private $multi_base;
	private $original_limit;
	private $original_offset;
	private $current_segment;

	function __construct($multi_base, $incremental_limit=200) {
		$this->overall_position = 0;
		$this->incremental_position = 0;

		$this->multi_base = $multi_base;
		$this->original_limit = $multi_base->limit;
		$this->original_offset = $multi_base->offset;

		if ($this->original_limit !== NULL) {
			$this->multi_base->limit = min($incremental_limit, $this->multi_base->limit);
		} else {
			$this->multi_base->limit = $incremental_limit;
		}
		$this->multi_base->load();

		$this->current_segment = NULL;
	}

	function rewind(): void {
	}

	function key(): mixed {
		return $this->overall_position;
	}

	function next(): void {
		$this->incremental_position++;
		$this->overall_position++;

		if ($this->incremental_position >= count($this->multi_base)) {
			$this->multi_base->offset = $this->original_offset + $this->overall_position;
			$this->incremental_position = 0;
			$this->multi_base->load();
		}
	}

	function current(): mixed {
		return $this->multi_base->get($this->incremental_position);
	}

	function valid(): bool {
		return ($this->original_limit === NULL || $this->overall_position < $this->original_limit) &&
			$this->multi_base->is_valid($this->incremental_position);
	}

	function has_any() {
		return $this->multi_base->is_valid(0);
	}
}

// Since SystemBase is the base of everything, it needs to be defined before
// we setup the default exception handler.  Thus in this case it is OK for us to
// require_once things not at the top of the file!
require_once('SessionControl.php');
require_once(PathHelper::getIncludePath('data/general_errors_class.php'));

if (!defined('SKIP_DEFAULT_EXCEPTION_HANDLER')) { 
    // Initialize new error handling system
    require_once('ErrorHandler.php');
    ErrorManager::getInstance()->register();
}

?>
