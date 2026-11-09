<?php
/** @joinery-test
 * name: sudo_helper_search_path
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 * covers: [public_html/plugins/mail_apps/provisioning/**, public_html/plugins/office_editor/provisioning/**, public_html/plugins/mailbox/provisioning/**]
 */
/**
 * Every program the web user may run as root through sudo names its own
 * interpreter and search path (shell_command_audit D8).
 *
 * Stock sudo replaces the caller's PATH (secure_path), but that is a line in
 * /etc/sudoers, which nothing of ours writes or checks. A helper that started
 * `#!/usr/bin/env bash`, or called a tool by name without setting PATH, would
 * run whatever the web user put first on its path the day that line was gone.
 * So each helper begins `#!/bin/bash` and sets PATH before any command.
 *
 * The sudoers rules our installers write are found by search, and each is
 * mapped to the source of the helpers it names: a new rule fails here until
 * its helper is listed and checked.
 *
 * Run: php tests/run.php --only=tests/unit/sudo_helper_search_path_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$root = rtrim(PathHelper::getIncludePath(''), '/');
$repo = dirname($root);

// file that writes a sudoers rule => the helpers that rule lets the web user
// run, each as [source file, heredoc marker or '' for the whole file].
$known = array(
	'public_html/plugins/mail_apps/provisioning/install_mail_gateway.sh' => array(
		'joinery-mail-gateway' => array('public_html/plugins/mail_apps/provisioning/install_mail_gateway.sh', 'HELPERSCRIPT'),
	),
	'public_html/plugins/office_editor/provisioning/install_office_editor.sh' => array(
		'office-editor-ctl' => array('public_html/plugins/office_editor/provisioning/office-editor-ctl', ''),
	),
	'public_html/plugins/mailbox/provisioning/provision_relay_main.sh' => array(
		'joinery-mail-listener' => array('public_html/plugins/mailbox/provisioning/provision_relay_main.sh', 'LISTENERHELPER'),
		'joinery-dkim-remove'   => array('public_html/plugins/mailbox/provisioning/provision_relay_main.sh', 'DKIMREMOVEHELPER'),
	),
);

/** Every file under the tree that writes a sudoers rule granting root. */
function sudo_rule_writers(string $repo): array {
	$found = array();
	foreach (array('public_html', 'maintenance_scripts') as $top) {
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$repo/$top", FilesystemIterator::SKIP_DOTS));
		foreach ($it as $f) {
			$path = $f->getPathname();
			$rel = substr($path, strlen($repo) + 1);
			if (!preg_match('/\.(sh|php|go)$/', $path) && strpos($rel, '/provisioning/') === false) continue;
			if (preg_match('#(^|/)(tests|specs|vendor|node_modules)/#', $rel)) continue;
			$text = @file_get_contents($path);
			if ($text !== false && preg_match('/ALL=\(root\)\s+NOPASSWD:/', $text)) $found[] = $rel;
		}
	}
	sort($found);
	return $found;
}

/** The helper's source text: one heredoc body, or the whole file. */
function helper_source(string $repo, string $file, string $marker): ?string {
	$text = @file_get_contents("$repo/$file");
	if ($text === false) return null;
	if ($marker === '') return $text;
	if (!preg_match("/<<'" . preg_quote($marker, '/') . "'\n(.*?)\n" . preg_quote($marker, '/') . "\n/s", $text, $m)) return null;
	return $m[1] . "\n";
}

/** Null when the source starts #!/bin/bash and sets PATH before any command; else why not. */
function search_path_refusal(string $src): ?string {
	$lines = explode("\n", $src);
	if ($lines[0] !== '#!/bin/bash') return 'its first line is ' . $lines[0] . ', not #!/bin/bash';
	foreach (array_slice($lines, 1) as $line) {
		$t = trim($line);
		if ($t === '' || $t[0] === '#') continue;
		if (preg_match('#^PATH=/[^\s;]*;\s*export PATH$#', $t)) return null;
		return 'its first command is ' . $t . ', not PATH=...; export PATH';
	}
	return 'it never sets PATH';
}

section('The check itself');
check(search_path_refusal("#!/bin/bash\n# x\nPATH=/usr/sbin:/usr/bin; export PATH\nsystemctl status x\n") === null,
	'a helper that sets PATH first passes');
check(search_path_refusal("#!/usr/bin/env bash\nPATH=/usr/bin; export PATH\n") !== null,
	'#!/usr/bin/env bash is refused: env finds bash on the caller\'s path');
check(search_path_refusal("#!/bin/bash\nset -euo pipefail\nPATH=/usr/bin; export PATH\n") !== null,
	'a command before PATH is refused');
check(search_path_refusal("#!/bin/bash\n# only comments\n") !== null,
	'a helper that never sets PATH is refused');

section('Every sudoers rule we write is known here');
$writers = sudo_rule_writers($repo);
$expected = array_keys($known);
sort($expected);
check($writers === $expected, 'the files writing a root sudoers rule are exactly the listed ones',
	'found: ' . implode(', ', $writers));

section('Each helper those rules name sets its own interpreter and search path');
foreach ($known as $writer => $helpers) {
	$writer_text = (string)@file_get_contents("$repo/$writer");
	foreach ($helpers as $name => $src_spec) {
		check(strpos($writer_text, $name) !== false, "$writer names $name");
		$src = helper_source($repo, $src_spec[0], $src_spec[1]);
		if ($src === null) {
			check(false, "$name: its source is found", $src_spec[0] . ' ' . $src_spec[1]);
			continue;
		}
		$why = search_path_refusal($src);
		check($why === null, "$name starts #!/bin/bash and sets PATH before any command", (string)$why);
	}
}

harness_finish();
