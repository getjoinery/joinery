<?php
/** @joinery-test
 * name: joinery_ai_owner_scope
 * tier: safe
 * env: dev-only
 * needs: []
 */
/**
 * Owner-scoped reads — member vs. admin containment in ModelQueryExecutor.
 *
 * Drives query_model through a member context (ownerScopedReads() = true) and
 * an admin context (false) against real data, asserting:
 *   - a member reading an owner-scoped model (users, scoped on its pk) sees
 *     only their own row, while an admin sees more than one;
 *   - a member reading an ownerless catalog model (products) is unfiltered —
 *     same rows an admin sees;
 *   - a member reading a model with unresolvable ownership (conversations) is
 *     refused, while an admin reads it.
 *
 * Read-only: no rows are written. Run:
 *   php tests/integration/joinery_ai_owner_scope_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ToolContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelQueryExecutor.php'));

/** Minimal ToolContext stub. ownerScopedReads() and actingUserId() are the only
 *  knobs the read fence consults; allowedModels() returns every readable model
 *  so the executor's own scope logic (not the allowlist) is what's under test. */
class StubReadContext implements ToolContext {
    private $uid; private $scoped;
    public function __construct(int $uid, bool $scoped) { $this->uid = $uid; $this->scoped = $scoped; }
    public function actingUserId(): int { return $this->uid; }
    public function ownerTimezone(): string { return 'UTC'; }
    public function untrustedNonce(): string { return 'testnonce'; }
    public function allowedModels(): array { return array_keys(ModelRegistry::all()); }
    public function allowedActions(): array { return []; }
    public function queuesWrites(): bool { return false; }
    public function enqueueProposedAction(array $tool_use): array {
        throw new LogicException('This fixture context does not queue writes.');
    }
    public function ownerScopedReads(): bool { return $this->scoped; }
    public function shouldContinue(): ?array { return null; }
    public function shouldAbort(): bool { return false; }
    public function beginToolCall(array $entry): void {}
    public function finishToolCall(array $entry): void {}
    public function appendToolCall(array $entry): void {}
    public function emitText(string $delta): void {}
    public function noteActivity(string $label): void {}
}

/** Find the registered model class whose table matches a prefix table name. */
function class_for_table(string $table): ?string {
    foreach (array_keys(ModelRegistry::all()) as $class) {
        if (isset($class::$tablename) && $class::$tablename === $table) return $class;
    }
    return null;
}

$db = DbConnector::get_instance()->get_db_link();

// Need two distinct users to tell "own row only" from "all rows" apart.
$uids = $db->query("SELECT usr_user_id FROM usr_users ORDER BY usr_user_id LIMIT 2")
           ->fetchAll(PDO::FETCH_COLUMN);
if (count($uids) < 2) {
    harness_skip('user scoping', 'need at least two users in usr_users to test scoping');
    harness_finish();
}
$member_uid = (int)$uids[0];
$other_uid  = (int)$uids[1];

$User = class_for_table('usr_users');
$Product = class_for_table('pro_products');
$Conversation = class_for_table('cnv_conversations');

echo "Owner-scoped reads — member vs admin\n";
echo "member_uid=$member_uid other_uid=$other_uid\n\n";

// --- 1. Owner-scoped model (users): member sees only their own row ----------
section('users (owner-scoped on the pk)');
$member = new StubReadContext($member_uid, true);
$admin  = new StubReadContext($member_uid, false);

$rows = ModelQueryExecutor::query($User, [], [], 50, ['usr_user_id'], $member);
$only_self = true;
foreach ($rows as $r) { if ((int)$r['usr_user_id'] !== $member_uid) $only_self = false; }
ok('member sees only their own user row', count($rows) === 1 && $only_self);

$admin_rows = ModelQueryExecutor::query($User, [], [], 50, ['usr_user_id'], $admin);
ok('admin sees more than one user', count($admin_rows) > 1);

// A member cannot read another user's row even by filtering for it explicitly.
$forced = ModelQueryExecutor::query($User, ['usr_user_id' => $other_uid], [], 50, ['usr_user_id'], $member);
ok('member cannot fetch another user by explicit filter', count($forced) === 0);

// --- 2. Ownerless catalog (products): member is unfiltered ------------------
if ($Product !== null) {
    section('products (ownerless catalog, $ai_owner_field = false)');
    $m = ModelQueryExecutor::query($Product, [], [], 200, [$Product::$prefix . '_product_id'], new StubReadContext($member_uid, true));
    $a = ModelQueryExecutor::query($Product, [], [], 200, [$Product::$prefix . '_product_id'], new StubReadContext($member_uid, false));
    ok('member reads the same catalog rows as an admin', count($m) === count($a));
} else {
    echo "\n(skipping products — class not registered)\n";
}

// --- 3. Unresolvable ownership (conversations): hidden from members ---------
if ($Conversation !== null) {
    section('conversations (ownership via join — hidden from members)');
    $threw = false;
    try {
        ModelQueryExecutor::query($Conversation, [], [], 10, null, new StubReadContext($member_uid, true));
    } catch (InvalidArgumentException $e) { $threw = true; }
    ok('member read of a hidden model is refused', $threw);

    $admin_ok = true;
    try {
        ModelQueryExecutor::query($Conversation, [], [], 10, null, new StubReadContext($member_uid, false));
    } catch (Throwable $e) { $admin_ok = false; }
    ok('admin read of the same model succeeds', $admin_ok);
} else {
    echo "\n(skipping conversations — class not registered)\n";
}

harness_finish();
