<?php
/** @joinery-test
 * name: agent_file_target_name
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * AgentFile::validate_target_filename - the editor writes a database row to
 * disk under this name in the project root, so the name is the whole
 * difference between a Markdown file and a front controller. Only a plain
 * basename ending in .md passes (specs/security_inventory.md S1).
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

function target_name_refused($name) {
	try {
		AgentFile::validate_target_filename($name);
		return false;
	} catch (AgentFileException $e) {
		return true;
	}
}

section('Accepted: plain Markdown names in the project root');
foreach (['CLAUDE.md', 'GEMINI.md', 'AGENTS.md', 'claude.md', 'agents-v2.md', 'notes_2026.md'] as $name) {
	check(!target_name_refused($name), "accepts $name");
}

section('Refused: anything the box could execute or read as configuration');
$refused = [
	'serve.php'          => 'the front controller',
	'index.php'          => 'a PHP file',
	'CLAUDE.md.php'      => 'a PHP file wearing an .md prefix',
	'CLAUDE.php.md'      => 'a second extension AddHandler would match',
	'a.b.md'             => 'a second dot',
	'.htaccess'          => 'Apache configuration',
	'.md'                => 'a bare extension',
	'CLAUDE.MD'          => 'an uppercase extension the check does not fold',
	'CLAUDE.markdown'    => 'a different Markdown extension',
	'CLAUDE'             => 'no extension',
	'CLAUDE.md '         => 'a trailing space',
	' CLAUDE.md'         => 'a leading space',
	"CLAUDE.md\n"        => 'a trailing newline',
	"CLAUDE.md\0.php"    => 'a NUL byte',
	'../CLAUDE.md'       => 'a parent traversal',
	'docs/CLAUDE.md'     => 'a subdirectory',
	'docs\\CLAUDE.md'    => 'a backslash path',
	'..md'               => 'a dotdot name',
	'-CLAUDE.md'         => 'a leading dash',
	''                   => 'an empty name',
];
foreach ($refused as $name => $why) {
	check(target_name_refused($name), 'refuses ' . json_encode($name) . " ($why)");
}
section('Non-string input');
check(target_name_refused(null), 'refuses null');
check(target_name_refused(['CLAUDE.md']), 'refuses an array');

harness_finish();
