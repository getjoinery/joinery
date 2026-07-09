<?php
/** @joinery-test
 * name: scaffold_ai_agent
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Pins the AI-agent exposure contract emitted by the scaffold's edit-logic
 * templates (joinery_ai chat assistant, phase 1):
 *
 *   - the public edit action is agent-callable by default
 *     ('ai_agent' => 'confirm'), since a user editing their own record is the
 *     canonical callable action;
 *   - the admin edit action is NOT agent-callable by default — the descriptor
 *     carries only a commented opt-in line, so default-deny holds until a
 *     developer deliberately exposes it.
 *
 * Nothing is written to disk. Run:  php tests/scaffold/scaffold_ai_agent_test.php
 *
 * @version 1.1
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/scaffold/ScaffoldGenerator.php'));

section('Scaffold ai_agent exposure contract');

$gen = new ScaffoldGenerator([
    'entity'   => 'ScaffoldAiAgentProbe',
    'prefix'   => 'zqa',
    'plural'   => 'scaffold_ai_agent_probes',
    'surfaces' => ['public', 'admin'],
    'fields'   => [
        ['name' => 'title', 'type' => 'varchar(255)', 'required' => true],
    ],
]);
$files = $gen->files();

// Locate the public and admin edit-logic sources by their descriptor function.
$public_logic = '';
$admin_logic  = '';
foreach ($files as $rel => $src) {
    if (strpos($src, 'function admin_scaffold_ai_agent_probe_edit_logic_descriptor') !== false) {
        $admin_logic = $src;
    } elseif (strpos($src, 'function scaffold_ai_agent_probe_edit_logic_descriptor') !== false) {
        $public_logic = $src;
    }
}

ok('public edit logic was generated', $public_logic !== '');
ok('admin edit logic was generated',  $admin_logic !== '');

// Public: agent-callable, confirmed.
ok("public descriptor declares ai_agent => 'confirm'",
    preg_match("/'ai_agent'\s*=>\s*'confirm'/", $public_logic) === 1);

// Admin: only the commented opt-in — no active ai_agent key.
$admin_active = preg_match('/^\s*\'ai_agent\'\s*=>/m', $admin_logic) === 1;
ok('admin descriptor has NO active ai_agent key (default-deny)', !$admin_active);
ok('admin descriptor carries the commented opt-in line',
    strpos($admin_logic, "// 'ai_agent'") !== false);

harness_finish();
