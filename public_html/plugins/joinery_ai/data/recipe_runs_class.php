<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class RecipeRunException extends SystemBaseException {}

class RecipeRun extends SystemBase {

    public static $prefix = 'rcr';
    public static $tablename = 'rcr_recipe_runs';
    public static $pkey_column = 'rcr_run_id';

    protected static $foreign_key_actions = array(
        // 'rcp' prefix collides: convention would resolve to RelayCloudProvision, not Recipe
        'rcr_rcp_recipe_id' => array('action' => 'permanent_delete', 'source_class' => 'Recipe'),
    );

    const STATUS_PENDING    = 'pending';
    const STATUS_RUNNING    = 'running';
    const STATUS_SUCCESS    = 'success';
    const STATUS_FAILED     = 'failed';
    const STATUS_TIMEOUT    = 'timeout';
    const STATUS_SKIPPED    = 'skipped';
    const STATUS_INCOMPLETE = 'incomplete';
    const STATUS_CANCELLED  = 'cancelled';

    const TRIGGER_SCHEDULE = 'schedule';
    const TRIGGER_MANUAL   = 'manual';
    // A slice run inside the owner's open vault window, because the recipe's
    // job reads sealed content and so can never run from cron
    // (specs/in_window_deferred_work.md).
    const TRIGGER_WINDOW   = 'window';

    public static $field_specifications = array(
        'rcr_run_id'            => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
        'rcr_rcp_recipe_id'     => array('type'=>'int8', 'required'=>true),
        'rcr_started_time'      => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'rcr_completed_time'    => array('type'=>'timestamp(6)'),
        'rcr_status'            => array('type'=>'varchar(20)', 'default'=>'pending'),
        'rcr_trigger'           => array('type'=>'varchar(20)', 'default'=>'schedule'),
        'rcr_input_tokens'      => array('type'=>'int4', 'default'=>0),
        'rcr_output_tokens'     => array('type'=>'int4', 'default'=>0),
        'rcr_cost_estimate'     => array('type'=>'numeric(10,4)', 'default'=>0),
        'rcr_output'            => array('type'=>'text'),
        'rcr_error'             => array('type'=>'text'),
        // 'text', not jsonb: on a sealed run this holds an AEAD blob, which is
        // not valid JSON. writeContent() json_encodes before sealing and
        // toolCalls() json_decodes after opening, so every reader sees an array
        // either way.
        'rcr_tool_calls'        => array('type'=>'text'),
        'rcr_workspace_before'  => array('type'=>'text'),
        'rcr_workspace_after'   => array('type'=>'text'),
        'rcr_kill_requested'    => array('type'=>'bool', 'default'=>false),
        // What the PLATFORM says happened, as opposed to what the model or the
        // content said — a reaper verdict, an admin cancellation. Never sealed:
        // it is written by cron and by other people's admin sessions, neither of
        // which holds the owner's key, and it never quotes what the run read.
        'rcr_status_note'       => array('type'=>'varchar(120)'),
        // Sealed Vault columns (docs/sealed_vault.md). A run that read protected
        // material is protected material; see $sealed_fields below.
        'rcr_content_sealed'       => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
        'rcr_sealed_key'           => array('type'=>'text', 'is_nullable'=>true),
        'rcr_sealed_owner_user_id' => array('type'=>'int8', 'is_nullable'=>true),
        'rcr_key_generation'       => array('type'=>'int4', 'is_nullable'=>false, 'default'=>0),
        'rcr_create_time'       => array('type'=>'timestamp(6)', 'default'=>'now()'),
        'rcr_delete_time'       => array('type'=>'timestamp(6)'),
    );

    // rcr_tool_calls is plain 'text' so a sealed run can hold ciphertext; the
    // encode/decode happens either side of the seal instead.
    public static $json_vars = array();

    /**
     * Every column that can carry what the run READ, as opposed to what it did
     * (specs/implemented/sealed_content_egress.md § Layer 1).
     *
     * A run against a standard mailbox holds nothing protected here and stays
     * plaintext and fully searchable. A run against a sealed one holds subjects
     * lifted out of encrypted mail, model summaries of encrypted bodies, and —
     * in agent mode — whole tool outputs. Same model, same columns, different
     * answer per row, which is why the flag is on the row.
     *
     * The counts, timings, tokens, cost and status are NOT here: those describe
     * the run, not its source, and history has to render from them with the
     * vault locked.
     */
    public static $sealed_fields = array(
        'rcr_output', 'rcr_tool_calls', 'rcr_error',
        'rcr_workspace_before', 'rcr_workspace_after',
    );

    // A run seals at start (sealToOwner() mints the DEK before there is any
    // output) and re-seals each column under that same key as the run produces
    // it, so the sealing path is the run's, not save()'s.
    public static $seal_on_save = false;

    function authenticate_write($data) {
        if ($data['current_user_permission'] < 10) {
            throw new SystemAuthenticationError(
                'Joinery AI run rows are written by the runner; manual edits require permission level 10.');
        }
    }

    /** The row DEK for this run, held for the run's duration. Never persisted. */
    private $content_dek = null;

    /**
     * Plaintext of the content columns this instance has written, so the runner
     * can read back what it just wrote without a decrypt round-trip — and
     * without keeping plaintext in $this->data, where a later get() would find
     * it sitting in a column the database has as ciphertext.
     */
    private $content_plain = array();

    /**
     * Mark this run protected and mint its key, before a single byte of what it
     * reads is written anywhere. Called at run start when the recipe's source is
     * sealed — sealing needs only the owner's public key, so this cannot fail
     * for want of an unlock window, and the run stays writable even if the
     * window lapses halfway through.
     */
    public function sealToOwner(UserEncryptionVault $vault): void {
        $this->content_dek = static::sealColumns(intval($this->key), $vault, array());
        $this->data->rcr_content_sealed = true;
    }

    /**
     * The DEK for an already-sealed run, unwrapped in-window on first use and
     * cached. Returns null when the window is closed — the caller then declines
     * to write content rather than writing it in the clear.
     */
    private function contentDek(): ?string {
        if ($this->content_dek !== null) {
            return $this->content_dek;
        }
        require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
        require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
        $owner = (int)($this->data->rcr_sealed_owner_user_id ?? 0);
        $key   = (string)($this->data->rcr_sealed_key ?? '');
        if ($owner <= 0 || $key === '') {
            return null;
        }
        $secret = VaultUnlock::secretKey($owner);
        if ($secret === null) {
            return null;
        }
        $crypto = new VaultCrypto();
        $this->content_dek = $crypto->openItemDek($key, $secret);
        return $this->content_dek;
    }

    /**
     * Persist content columns, sealing them when this run is sealed.
     *
     * The ONLY correct way to write rcr_output / rcr_tool_calls / rcr_error /
     * the workspace pair. A plain save() cannot do it: on a sealed row it skips
     * those columns by design (docs/sealed_vault.md § Writing), so a value set
     * and saved would be silently dropped.
     *
     * On an unsealed run this is a plain UPDATE and nothing changes. On a sealed
     * one with no key available — the window lapsed, or something out-of-band
     * is writing — the content is DROPPED rather than stored in the clear. That
     * is the correct direction to fail: a run log missing a line is recoverable,
     * a decrypted subject sitting in a plaintext column is not.
     *
     * @param array $columns column name => plaintext value
     */
    public function writeContent(array $columns): void {
        if (intval($this->key) <= 0 || empty($columns)) {
            return;
        }
        $sealed = array();
        $plain  = array();
        foreach ($columns as $col => $value) {
            if (in_array($col, static::$sealed_fields, true)) {
                $sealed[$col] = $value;
            } else {
                $plain[$col] = $value;
            }
        }
        if (!empty($sealed)) {
            if ($this->rowIsSealed()) {
                $dek = $this->contentDek();
                if ($dek !== null) {
                    static::sealColumns(intval($this->key), null, $sealed, $dek);
                }
            } else {
                $plain = array_merge($plain, $sealed);
            }
        }
        if (!empty($plain)) {
            static::updateColumns(intval($this->key), $plain);
        }
        // On a sealed run the database now holds ciphertext, so $this->data must
        // NOT keep the plaintext — a later get() would find a plaintext value in
        // a sealed column and rightly call it corruption. The plaintext lives in
        // the read-back cache instead.
        $sealed_row = $this->rowIsSealed();
        foreach ($columns as $col => $value) {
            $this->content_plain[$col] = $value;
            $this->data->$col = ($sealed_row && isset($sealed[$col])) ? null : $value;
        }
    }

    /**
     * save() for the non-content columns, then writeContent() for whatever
     * content the caller set — so an existing `set(); save()` call site stays
     * correct on a sealed run instead of quietly losing its content.
     */
    public function saveContent() {
        $content = array();
        foreach (static::$sealed_fields as $col) {
            if (property_exists($this->data, $col) && $this->data->$col !== null) {
                $content[$col] = $this->data->$col;
            }
        }
        $result = $this->save();
        if (!empty($content)) {
            $this->writeContent($content);
        }
        return $result;
    }

    // Targeted UPDATE of exactly these columns — SystemBase::updateColumns(),
    // never a full-row save().

    /**
     * The tool-call trace as an array, whatever the storage shape. Returns []
     * for an empty column and for a sealed run the caller cannot open, so a
     * history page renders outside an unlock window instead of throwing.
     */
    public function toolCalls(): array {
        $raw = $this->contentOrNull('rcr_tool_calls');
        if (is_array($raw)) return $raw;
        if (!is_string($raw) || trim($raw) === '') return array();
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * One content column, or null when this run is sealed and the vault is
     * locked. Every display surface reads through this: run history has to
     * render out of window, showing what the run DID (counts, timings, status)
     * even when what it READ is unreadable.
     */
    public function contentOrNull(string $field) {
        if (array_key_exists($field, $this->content_plain)) {
            return $this->content_plain[$field];
        }
        try {
            return $this->get($field);
        } catch (Throwable $e) {
            return null;
        }
    }

}

class MultiRecipeRun extends SystemMultiBase {
    protected static $model_class = 'RecipeRun';

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['recipe_id'])) {
            $filters['rcr_rcp_recipe_id'] = [$this->options['recipe_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['status'])) {
            $filters['rcr_status'] = [$this->options['status'], PDO::PARAM_STR];
        }

        if (isset($this->options['active'])) {
            // Convenience: rows currently in flight
            $filters['rcr_status'] = "IN ('pending','running')";
        }

        if (isset($this->options['deleted'])) {
            $filters['rcr_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        } else {
            $filters['rcr_delete_time'] = "IS NULL";
        }

        return $this->_get_resultsv2('rcr_recipe_runs', $filters, $this->order_by, $only_count, $debug);
    }

}
