<?php
/**
 * Regression fixture for the scaffold generator hardening
 * (specs/implemented/scaffold_generator_hardening.md).
 *
 * Pins the five shakedown bugs so none can silently return, plus the behaviours
 * the hardening added. A synthetic entity exercises every bug at once; the
 * transactional database roundtrip proves the table actually stands up. Nothing
 * is written to disk and nothing persists in the database (the roundtrip runs
 * inside a transaction that is always rolled back), so this is safe to re-run.
 *
 *   bug 1 — `time` column accepted (was rejected by the type validator)
 *   bug 2 — soft-delete column resolves to `timestamp(6)` (not `timestamp with time zone`)
 *   bug 3 — PK emits `int8` + `'serial'=>true` and round-trips via the canonical sequence
 *   bug 4 — PK is named `{prefix}_{singular}_id` (not `{prefix}_id`)
 *   item 2 — `serial`/`bigserial` declared on a field is rejected
 *   item 4 — non-standard owner_field emits a flagged owner-check; standard/absent emits none
 *   item 5b — every column type the platform's own data classes use is accepted
 *
 * Run:  php tests/scaffold/scaffold_hardening_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/scaffold/ScaffoldGenerator.php'));

$tests = 0; $failures = 0;
function check($label, $cond) {
    global $tests, $failures; $tests++;
    echo ($cond ? "  PASS: " : "  FAIL: ") . "$label\n";
    if (!$cond) { $failures++; }
}

/**
 * A manifest that trips every shakedown bug at once: a `time` column, a soft
 * delete, a serial-shaped PK to round-trip, a {prefix}_{singular}_id name, and a
 * non-standard owner column. A distinctive prefix/entity avoids colliding with
 * any real table or loaded class.
 */
function probe_manifest(array $overrides = []): array {
    return array_merge([
        'entity'      => 'ScaffoldHardeningProbe',
        'prefix'      => 'zqx',
        'plural'      => 'scaffold_hardening_probes',
        'surfaces'    => ['data'],
        'owner_field' => 'zqx_account_id',           // non-standard → flagged auth
        'delete'      => ['strategy' => 'soft'],
        'fields'      => [
            ['name' => 'account_id', 'type' => 'int8',          'required' => true],
            ['name' => 'title',      'type' => 'varchar(255)',  'required' => true],
            ['name' => 'notes',      'type' => 'text'],
            ['name' => 'started_at', 'type' => 'time'],          // bug 1
            ['name' => 'event_at',   'type' => 'timestamp(6)'],
            ['name' => 'on_date',    'type' => 'date'],
            ['name' => 'amount',     'type' => 'numeric(10,2)'],
            ['name' => 'is_active',  'type' => 'bool'],
            ['name' => 'payload',    'type' => 'jsonb'],
        ],
    ], $overrides);
}

echo "Scaffold generator hardening — regression fixture\n\n";

// ── Generated output (no DB needed) ──────────────────────────────────────────
$gen   = new ScaffoldGenerator(probe_manifest());
$files = $gen->files();
$data  = $files['data/scaffold_hardening_probe_class.php'] ?? '';

check('data class source was produced', $data !== '');

// bug 4 — primary key name follows {prefix}_{singular}_id
check('bug 4: PK named zqx_scaffold_hardening_probe_id',
    strpos($data, "'zqx_scaffold_hardening_probe_id' =>") !== false);

// bug 3 — PK shape is int8 + serial=>true (NOT bigserial)
check('bug 3: PK emits int8 + serial=>true',
    preg_match("/'zqx_scaffold_hardening_probe_id'\s*=>\s*array\('type'=>'int8'.*'serial'=>true/", $data) === 1);
check('bug 3: PK is not bigserial',
    stripos($data, 'bigserial') === false);

// bug 1 — time column survived generation
check('bug 1: time column present',
    strpos($data, "'zqx_started_at' => array('type'=>'time'") !== false);

// bug 2 — soft-delete column resolves to timestamp(6)
check('bug 2: delete column is timestamp(6)',
    strpos($data, "'zqx_delete_time' => array('type'=>'timestamp(6)'") !== false);
check('bug 2: delete column is not timestamp with time zone',
    stripos($data, 'timestamp with time zone') === false);

// item 4 — non-standard owner_field emits a flagged, working owner-check
check('item 4: non-standard owner emits authenticate_read()',
    strpos($data, 'function authenticate_read') !== false);
check('item 4: non-standard owner emits authenticate_write()',
    strpos($data, 'function authenticate_write') !== false);
check('item 4: owner-check is flagged with a TODO',
    strpos($data, 'TODO: confirm this row-scope rule is correct') !== false);
check('item 4: owner-check references the declared owner column',
    strpos($data, "\$this->get('zqx_account_id')") !== false);

// item 4 — standard owner_field emits NO custom auth
$std = (new ScaffoldGenerator(probe_manifest(['owner_field' => 'zqx_usr_user_id'])))
    ->files()['data/scaffold_hardening_probe_class.php'] ?? '';
check('item 4: standard owner_field emits no authenticate_read()',
    strpos($std, 'function authenticate_read') === false);

// item 4 — omitted owner_field emits NO custom auth
$none_manifest = probe_manifest();
unset($none_manifest['owner_field']);
$none = (new ScaffoldGenerator($none_manifest))
    ->files()['data/scaffold_hardening_probe_class.php'] ?? '';
check('item 4: omitted owner_field emits no authenticate_read()',
    strpos($none, 'function authenticate_read') === false);

// ── Validation rules ─────────────────────────────────────────────────────────

// item 2 — serial / bigserial cannot be declared on a field
foreach (['bigserial', 'serial', 'smallserial', 'serial8'] as $serial_type) {
    $m = probe_manifest();
    $m['fields'][] = ['name' => 'counter', 'type' => $serial_type];
    $errs = (new ScaffoldGenerator($m))->validate();
    $hit = false;
    foreach ($errs as $e) { if (strpos($e, 'serial types are managed') !== false) { $hit = true; } }
    check("item 2: '$serial_type' field is rejected with the serial message", $hit);
}

// item 5b — every column type the platform's own data classes use is accepted.
// These are the distinct types in data/*.php; the single-source-of-truth fix
// (delegation to DatabaseUpdater::acceptedColumnTypeRegex) must accept them all.
$platform_types = [
    'timestamp(6)', 'timestamp', 'int4', 'int8', 'int2', 'integer',
    'varchar(255)', 'varchar(64)', 'varchar(2)', 'text', 'bool',
    'jsonb', 'json', 'date', 'time',
    'numeric(10,2)', 'numeric', 'decimal(4,3)',
];
foreach ($platform_types as $i => $t) {
    $m = probe_manifest([
        'entity' => 'ScaffoldTypeProbe', 'prefix' => 'zqy', 'plural' => 'scaffold_type_probes',
        'owner_field' => null,
        'fields' => [['name' => 'col_' . $i, 'type' => $t]],
    ]);
    $errs = (new ScaffoldGenerator($m))->validate();
    $type_err = false;
    foreach ($errs as $e) {
        if (strpos($e, 'not a supported column type') !== false
            || strpos($e, 'serial types are managed') !== false) { $type_err = true; }
    }
    check("item 5b: platform type '$t' is accepted", !$type_err);
}

// ── Database roundtrip (the core acceptance gate) ────────────────────────────
$rt = $gen->verifyDatabaseRoundtrip();
if (!$rt['ran']) {
    echo "  SKIP: database roundtrip (" . $rt['skipped_reason'] . ")\n";
} else {
    check('bug 3: data class round-trips through the database (insert + canonical-sequence PK + read-back)',
        empty($rt['failures']));
    if (!empty($rt['failures'])) {
        foreach ($rt['failures'] as $f) { echo "        - $f\n"; }
    }
}

echo "\n" . ($tests - $failures) . "/" . $tests . " passed\n";
exit($failures ? 1 : 0);
