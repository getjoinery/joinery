<?php
/** @joinery-test
 * name: shell_call_sites
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 * covers: [public_html/includes/**, public_html/adm/**, public_html/plugins/**, public_html/utils/**, public_html/logic/**, public_html/data/**, public_html/views/**, public_html/ajax/**, public_html/api/**, public_html/migrations/**, public_html/serve.php, maintenance_scripts/dev_tools/list_shell_exec_sites.php]
 */
/**
 * A new shell command follows the rule in docs/shell_commands.md (WP3 of
 * specs/shell_command_audit.md).
 *
 * Every call to exec, shell_exec, system, passthru, proc_open, popen or
 * pcntl_exec in runtime PHP is classified by
 * maintenance_scripts/dev_tools/list_shell_exec_sites.php. A call is fine on
 * sight when its command is a literal, an argv array, or a string whose every
 * variable is escaped where it is used. The rest (VAR: a command built on an
 * earlier line, an interpolation, an escaped string handed to sh -c) were each
 * read in the audit and are counted here per file. A file whose count rises
 * fails: write the new call to the rule, or read it the way the audit did and
 * raise the count in the same change.
 *
 * Run: php tests/run.php --only=tests/unit/shell_call_sites_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(dirname(PathHelper::getBasePath()) . '/maintenance_scripts/dev_tools/list_shell_exec_sites.php');

// File => calls whose command is not checkable on sight, every one read in the
// audit (specs/shell_command_audit_results.md, 2026-11-07).
$read = array(
	'includes/AbstractExtensionManager.php'                         => 1,
	'includes/BackupRunner.php'                                     => 3,
	'includes/BackupVerifier.php'                                   => 1,
	'includes/BackupVerifyLauncher.php'                             => 1,
	'includes/ComposerValidator.php'                                => 3,
	'includes/DeploymentHelper.php'                                 => 9,
	'includes/DocumentText.php'                                     => 1,
	'includes/GoBinaryPublisher.php'                                => 2,
	'includes/ImageDecoder.php'                                     => 1,
	'includes/ReleaseCommit.php'                                    => 2,
	'includes/ReleaseVerifier.php'                                  => 8,
	'includes/TestDatabaseHelper.php'                               => 6,
	'logic/tests_run_logic.php'                                     => 1,
	'plugins/joinery_ai/includes/ChatWorkerSpawner.php'             => 1,
	'plugins/joinery_ai/includes/RecipeWorkerSpawner.php'           => 1,
	'plugins/mail_apps/includes/MailAppGateway.php'                 => 1,
	'plugins/mailbox/includes/InboundEmailSetupCheck.php'           => 1,
	'plugins/mailbox/utils/managed_domain_prepare.php'              => 1,
	'plugins/office_editor/includes/OfficeEditor.php'               => 1,
	'plugins/server_manager/includes/AgentDistPublisher.php'        => 1,
	'plugins/server_manager/includes/AgentLiveProofRun.php'         => 2,
	'plugins/server_manager/includes/InstallJobExecutor.php'        => 1,
	'plugins/server_manager/includes/NodeMonitorHealth.php'         => 2,
	'plugins/server_manager/includes/PublishTestGate.php'           => 1,
	'plugins/server_manager/includes/ReleaseStatementPublisher.php' => 1,
	'plugins/server_manager/includes/SupportBundlePublisher.php'    => 1,
	'plugins/server_manager/includes/publish_upgrade.php'           => 5,
	'plugins/server_manager/tasks/RunInstallJobs.php'               => 1,
	'plugins/server_manager/utils/write_commit_map.php'             => 2,
	'plugins/support_desk/includes/SupportDocIndex.php'             => 1,
	'utils/create_install_sql.php'                                  => 2,
	'utils/install_extension.php'                                   => 1,
	'utils/legacy_content_cleanup.php'                              => 1,
	'utils/root_request.php'                                        => 5,
	'utils/upgrade.php'                                             => 15,
);

/** The class the classifier gives the one call in $php, placed in a scratch tree. */
function scs_class_of(string $php): string {
	static $n = 0;
	$root = harness_scratch_dir('shell_call_sites') . '/t' . (++$n);
	@mkdir($root . '/includes', 0755, true);
	file_put_contents($root . '/includes/x.php', "<?php\n" . $php . "\n");
	$rows = shell_exec_sites($root);
	return count($rows) === 1 ? $rows[0][3] : 'rows=' . count($rows);
}

section('The classifier');
check(scs_class_of("exec('uptime');") === 'CONST', 'a literal command is CONST');
check(scs_class_of("exec('ls ' . escapeshellarg(\$dir));") === 'ESCAPED', 'a variable inside escapeshellarg() is ESCAPED');
check(scs_class_of("exec('kill ' . (int)\$pid);") === 'ESCAPED', 'an (int) cast is ESCAPED');
check(scs_class_of("exec('rm ' . implode(' ', array_map('escapeshellarg', \$paths)));") === 'ESCAPED',
	'array_map(\'escapeshellarg\', ...) is ESCAPED');
check(scs_class_of("proc_open(['git', 'log', \$ref], \$spec, \$pipes);") === 'ARGV', 'proc_open with an array is ARGV');
check(scs_class_of("exec('ls ' . \$dir);") === 'VAR', 'a bare variable in the command is VAR');
check(scs_class_of("exec(\"ls \$dir\");") === 'VAR', 'an interpolation is VAR');
check(scs_class_of("exec(\$cmd);") === 'VAR', 'a command built on an earlier line is VAR');
check(scs_class_of("exec('ls ' . escapeshellarg(\$a) . ' ' . \$b);") === 'VAR', 'one unescaped variable beside an escaped one is VAR');
check(scs_class_of("exec('bash -c ' . escapeshellarg(\$cmd));") === 'VAR', 'an escaped string handed to bash -c is VAR: it is parsed again');
check(scs_class_of("\$pdo->exec(\$sql);") === 'rows=0', 'a method named exec is not a shell call');

section('No call site outside the read ones');
$now = array();
foreach (shell_exec_sites(PathHelper::getBasePath()) as $r) {
	if ($r[3] === 'VAR') $now[$r[0]][] = $r[1] . ': ' . $r[4];
}
$grew = array();
foreach ($now as $file => $sites) {
	if (count($sites) > ($read[$file] ?? 0)) {
		$grew[] = $file . ' has ' . count($sites) . ' (read: ' . ($read[$file] ?? 0) . ') - ' . implode(' | ', $sites);
	}
}
check(count($grew) === 0, 'no file holds more unescaped shell commands than the audit read', implode("\n", $grew));

$shrank = array();
foreach ($read as $file => $count) {
	if (count($now[$file] ?? array()) < $count) $shrank[] = $file . ' ' . count($now[$file] ?? array()) . ' < ' . $count;
}
check(count($shrank) === 0, 'every count above is still true (lower one that fell, so the room is not reused)', implode(', ', $shrank));

harness_finish();
