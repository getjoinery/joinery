<?php
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

echo "Scaffold ai_agent exposure contract\n\n";

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

check('public edit logic was generated', $public_logic !== '');
check('admin edit logic was generated',  $admin_logic !== '');

// Public: agent-callable, confirmed.
check("public descriptor declares ai_agent => 'confirm'",
    preg_match("/'ai_agent'\s*=>\s*'confirm'/", $public_logic) === 1);

// Admin: only the commented opt-in — no active ai_agent key.
$admin_active = preg_match('/^\s*\'ai_agent\'\s*=>/m', $admin_logic) === 1;
check('admin descriptor has NO active ai_agent key (default-deny)', !$admin_active);
check('admin descriptor carries the commented opt-in line',
    strpos($admin_logic, "// 'ai_agent'") !== false);

echo "\n$tests tests, $failures failures\n";
exit($failures === 0 ? 0 : 1);
