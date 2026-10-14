<?php
/** @joinery-test
 * name: sandbox_module
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * SandboxModule: what a sandboxed plugin's WebAssembly module exports and
 * imports, read from its bytes (specs/plugin_tiers.md WP8).
 *
 * What is pinned here:
 *  - exports and imports are read from hand-built modules;
 *  - a module missing an export its manifest needs (a form, a signal, a
 *    schedule) is refused, naming the export and why; one of pages and data
 *    alone needs none;
 *  - an import the host does not provide is refused, naming it; Extism's
 *    runtime, the three doors and WASI are fine;
 *  - bytes that are not a module, or are cut short, are refused, never thrown
 *    past the caller as anything else.
 *
 * Run: php tests/run.php safe --filter=sandbox_module
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/sandbox.php');
harness_boot();

/** A module importing ($module, $name) functions, exporting $exports. */
$with_imports = function (array $imports, array $exports): string {
	$section = function (int $id, string $content): string {
		return chr($id) . chr(strlen($content)) . $content;
	};
	$import = chr(count($imports));
	foreach ($imports as list($module, $name)) {
		$import .= chr(strlen($module)) . $module . chr(strlen($name)) . $name . "\0\0";
	}
	$n = count($exports);
	$export = chr($n);
	$code = chr($n);
	foreach (array_values($exports) as $i => $name) {
		$export .= chr(strlen($name)) . $name . "\0" . chr(count($imports) + $i);
		$code .= "\x04\x00\x41\x00\x0b";
	}
	return "\0asm\x01\0\0\0" . $section(1, "\x01\x60\x00\x01\x7f") . $section(2, $import)
		. $section(3, chr($n) . str_repeat("\0", $n)) . $section(7, $export) . $section(10, $code);
};
$manifest = function (array $extra): SandboxManifest {
	return SandboxManifest::fromArray(array_merge(array('name' => 'modtest', 'title' => 'T', 'version' => '1.0.0', 'platform' => 1), $extra));
};

section('Reading a module');
$m = new SandboxModule(sandbox_minimal_wasm(array('on_ui', 'on_signal')));
check($m->exports() === array('on_ui', 'on_signal'), 'Its exports are read', json_encode($m->exports()));
$m = new SandboxModule($with_imports(array(array('extism:host/user', 'joinery_api'), array('wasi_snapshot_preview1', 'fd_write')), array('on_ui')));
check($m->imports() === array(array('module' => 'extism:host/user', 'name' => 'joinery_api', 'kind' => 0),
	array('module' => 'wasi_snapshot_preview1', 'name' => 'fd_write', 'kind' => 0)), 'and its imports', json_encode($m->imports()));

section('What the manifest needs of it');
$forms = $manifest(array('admin_pages' => array(array('slug' => 'p', 'title' => 'P', 'sections' => array(
	array('type' => 'form', 'handler' => 'go', 'fields' => array(array('name' => 'x', 'label' => 'X', 'type' => 'text'))))))));
$problems = (new SandboxModule(sandbox_minimal_wasm(array('on_signal'))))->problems($forms);
check(count($problems) === 1 && strpos($problems[0], 'on_ui') !== false && strpos($problems[0], 'forms') !== false,
	'A form needs on_ui, and says why', implode(' | ', $problems));
$signals = $manifest(array('signals' => array('account.signup'), 'schedules' => array(array('name' => 'd', 'frequency' => 'hourly'))));
$problems = (new SandboxModule(sandbox_minimal_wasm(array('on_ui'))))->problems($signals);
check(count($problems) === 2, 'Signals need on_signal and schedules on_schedule', implode(' | ', $problems));
check((new SandboxModule(sandbox_minimal_wasm(array())))->problems($manifest(array())) === array(),
	'A plugin of pages and data alone needs no export');

section('What it may ask of the host');
$fine = $with_imports(array(array('extism:host/env', 'alloc'), array('extism:host/user', 'joinery_http'),
	array('extism:host/user', 'joinery_log'), array('wasi_snapshot_preview1', 'random_get')), array('on_ui'));
check((new SandboxModule($fine))->problems($manifest(array())) === array(), 'Extism, the doors and WASI are provided');
$problems = (new SandboxModule($with_imports(array(array('extism:host/user', 'joinery_shell'), array('env', 'syscall')), array())))
	->problems($manifest(array()));
check(count($problems) === 2 && strpos(implode(' ', $problems), 'joinery_shell') !== false && strpos(implode(' ', $problems), 'env syscall') !== false,
	'Anything else is refused, named', implode(' | ', $problems));

section('Not a module');
foreach (array('not wasm' => 'hello world', 'version 2' => "\0asm\x02\0\0\0", 'cut short' => substr(sandbox_minimal_wasm(), 0, 20),
		'empty' => '') as $label => $bytes) {
	$refused = false;
	try {
		new SandboxModule($bytes);
	} catch (SandboxModuleException $e) {
		$refused = true;
	} catch (Throwable $e) {
		$refused = get_class($e);
	}
	check($refused === true, "Bytes that are $label are refused as such", is_string($refused) ? $refused : '');
}

harness_finish();
