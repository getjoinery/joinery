<?php
/** @joinery-test
 * name: parser_surfaces
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * No C parser opens outside bytes in the pool (specs/parser_jail.md).
 *
 * libzip (ZipArchive), zlib (gzdecode, inflate_init), libxml2 (DOMDocument,
 * loadHTML, loadXML, simplexml_load_string) and PharData are named only in
 * the extraction subprocess's code — DocumentText's sandbox side, the
 * extractor script, and every SandboxParserInterface class — or in a file on
 * the list below, each with the reason its bytes are not a stranger's. A new
 * file naming one of them fails here until it is either a sandbox parser or
 * on the list with a reason.
 *
 * Also pinned: the launcher is the second core host installer, the extractor
 * never reads settings, and DocumentText names the launcher outside the tree.
 *
 * Run: php tests/security/parser_surfaces_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$root = PathHelper::getRootDir();
$site = PathHelper::getSiteRoot();

// ---------------------------------------------------------------------------
section('C parsers on outside bytes run only in the extraction subprocess');

$tokens = array('ZipArchive', 'PharData', 'DOMDocument', 'loadHTML', 'loadXML',
	'simplexml_load_string', 'simplexml_load_file', 'gzdecode', 'inflate_init', 'gzinflate', 'gzuncompress');

// Files that may name a parser in the pool, and why the bytes are not a stranger's.
$allowed = array(
	'includes/DocumentText.php'                          => 'the sandbox side lives in the same class as the parent side',
	'utils/extract_document_text.php'                    => 'the extraction subprocess itself',
	'includes/AbstractExtensionManager.php'              => 'a theme or plugin package the owner installed or we signed (S9)',
	'utils/upgrade.php'                                  => 'our own signed release archive',
	'plugins/server_manager/includes/publish_upgrade.php' => 'the archive we are building',
	'includes/EmailTemplate.php'                         => 'the deployment\'s own email templates',
	'includes/TargetLister.php'                          => 'a provider API response under our own credentials',
	'includes/dns/drivers/NamecheapDnsDriver.php'        => 'the DNS provider\'s API response under our own credentials',
	'plugins/server_manager/includes/domain_registrar/NamecheapRegistrar.php' => 'the registrar\'s API response under our own credentials',
	'plugins/mailbox/includes/import/ZipReader.php'      => 'an archive the owner uploaded to import, once, under their eye',
	'plugins/mailbox/includes/import/TarReader.php'      => 'an archive the owner uploaded to import, once, under their eye',
);

$dirs = array('includes', 'data', 'logic', 'utils', 'adm', 'views', 'api', 'ajax', 'plugins', 'theme');
$offenders = array();
$sandbox_parsers = array();
$seen_allowed = array();
foreach ($dirs as $dir) {
	$base = $root . '/' . $dir;
	if (!is_dir($base)) continue;
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $f) {
		if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') continue;
		$path = $f->getPathname();
		$rel = substr($path, strlen($root) + 1);
		// Tests, fixtures and bundled libraries are not the pool's request path.
		if (preg_match('#(^|/)(tests|fixtures|vendor|node_modules)/#', $rel)) continue;
		$src = @file_get_contents($path);
		if ($src === false) continue;
		if (preg_match('/\bimplements\b[^{]*\bSandboxParserInterface\b/', $src)) {
			$sandbox_parsers[] = $rel;
			continue;
		}
		$hits = array();
		foreach ($tokens as $t) {
			// Code, not prose: the token as a call or a type, on a line that is not a comment.
			if (preg_match('/^[^*\n]*(?<![A-Za-z0-9_$\\\\])' . preg_quote($t, '/') . '\s*(\(|::|;|,|\)|$)/m', $src)
					|| preg_match('/^[^*\n]*\bnew\s+\\\\?' . preg_quote($t, '/') . '\b/m', $src)
					|| preg_match('/^[^*\n]*->' . preg_quote($t, '/') . '\s*\(/m', $src)) {
				$hits[] = $t;
			}
		}
		if (!$hits) continue;
		if (isset($allowed[$rel])) {
			$seen_allowed[$rel] = true;
			continue;
		}
		$offenders[] = $rel . ' (' . implode(', ', $hits) . ')';
	}
}
check(count($offenders) === 0,
	'every file naming a C parser is a sandbox parser or on the list with a reason',
	$offenders ? implode('; ', $offenders) : count($sandbox_parsers) . ' sandbox parsers, ' . count($seen_allowed) . ' listed');
foreach ($allowed as $rel => $why) {
	if ($rel === 'includes/DocumentText.php' || $rel === 'utils/extract_document_text.php') continue;
	check(isset($seen_allowed[$rel]) || !is_file($root . '/' . $rel),
		'listed exception still names a parser (or is gone): ' . $rel);
}
foreach (array('plugins/mailbox/includes/DeliverabilityReportParser.php', 'plugins/mailbox/includes/MailboxHtmlSanitizer.php',
		'plugins/mailbox/includes/GmailFilterExportParser.php', 'plugins/joinery_ai/includes/FetchUrlReader.php',
		'plugins/dns_filtering/includes/ScanUrlPageResources.php', 'plugins/persona_browser/includes/FacebookFeedExtractor.php') as $rel) {
	check(in_array($rel, $sandbox_parsers, true), $rel . ' is a sandbox parser');
}

// ---------------------------------------------------------------------------
section('The one XML door');
$door_users = 0;
foreach ($sandbox_parsers as $rel) {
	$src = file_get_contents($root . '/' . $rel);
	check(!preg_match('/^[^*\n]*->loadXML\s*\(/m', $src), $rel . ' parses XML through DocumentText::xmlDoc(), never loadXML() itself');
	foreach (array('LIBXML_NOENT', 'LIBXML_DTDLOAD', 'LIBXML_PARSEHUGE') as $flag) {
		check(!preg_match('/^[^*\n]*\b' . $flag . '\b/m', $src), $rel . ' passes no ' . $flag);
	}
	if (preg_match('/^[^*\n]*->loadHTML\s*\(/m', $src)) {
		check(preg_match('/^[^*\n]*->loadHTML\s*\([^;]*LIBXML_NONET/ms', $src) === 1, $rel . ' loads HTML with LIBXML_NONET');
	}
}

// ---------------------------------------------------------------------------
section('The subprocess and its launcher');
$extract = file_get_contents($root . '/utils/extract_document_text.php');
check(strpos($extract, 'Globalvars') === false, 'the extractor never names Globalvars');
check(strpos($extract, 'ClassAutoloader::restrictToCore()') !== false, 'the extractor resolves core classes only');
check(strpos($extract, 'is_subclass_of($class, \'SandboxParserInterface\'') !== false,
	'the extractor runs only a SandboxParserInterface class');
check(strpos($extract, "strpos(\$real, \$root . '/') !== 0") !== false,
	'and only from a file inside the code tree');

$dt = file_get_contents($root . '/includes/DocumentText.php');
check(strpos($dt, "const JAIL_LAUNCHER = '/usr/local/sbin/joinery-jail'") !== false, 'the launcher lives outside the tree');
check(preg_match('/^[^*\n]*proc_open\s*\(/m', $dt) === 1, 'DocumentText spawns exactly one way');

$runner = @file_get_contents($site . '/maintenance_scripts/install_tools/_plugin_installers_start.sh');
$installer = $site . '/maintenance_scripts/install_tools/install_parser_jail.sh';
check($runner !== false && preg_match('/CORE_INSTALLERS="[^"]*install_parser_jail\.sh/', $runner) === 1,
	'the launcher installer is a core host installer, run at every root moment');
check(is_file($installer), 'install_parser_jail.sh ships beside the runner');
foreach (array('x86_64', 'aarch64') as $arch) {
	check(is_file($site . '/maintenance_scripts/install_tools/joinery_jail/bin/joinery-jail-' . $arch),
		'a prebuilt launcher for ' . $arch . ' ships in the tree');
}
$publish = file_get_contents($root . '/plugins/server_manager/includes/publish_upgrade.php');
check(strpos($publish, 'ParserJailPublisher::publish($full_site_dir') !== false
	&& strpos($publish, 'ParserJailPublisher::STATUS_FAILED') !== false,
	'a publish builds the launcher and refuses the release when it cannot');

harness_finish();
