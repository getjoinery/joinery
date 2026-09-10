<?php
/**
 * The document text extractor — the short-lived, isolated subprocess that opens
 * hostile bytes so no web request has to.
 *
 * Spawned by DocumentText (never by hand) under the parser jail where one is
 * installed (specs/parser_jail.md), as one of:
 *
 *   extract_document_text.php <vendor_dir> document <path|-> <mime-hint> <max_chars>
 *   extract_document_text.php <vendor_dir> parser <handler_file> <Class> <options_json> [many]
 *
 * `document` reads one file (a path, or `-` for stdin — which is how sealed
 * content travels: decrypted bytes go down a pipe and never touch disk) and
 * answers with its text. `parser` loads one SandboxParserInterface class from
 * the named file and hands it the bytes on stdin; with `many`, stdin is a JSON
 * array of inputs and the answer is a JSON array of outputs in the same order,
 * null where one input failed. The vendor directory arrives as an argument
 * because this process must not read settings: under the jail it has no config
 * file and no database, and it needs neither to read a document.
 *
 * Parsing an untrusted file can pin CPU or exhaust RAM. Running it here, under
 * the launcher's deadline and a hard `memory_limit`, means a bomb kills only
 * THIS process: the parent reads the exit code (124 = deadline, 137 = kernel
 * kill) and marks the document unreadable without losing its own cleanup path.
 * Never fold this back into the caller with ini_set('memory_limit') — an
 * in-process memory fatal is uncatchable.
 *
 * The MIME argument is a HINT, consulted only when detection is inconclusive.
 * This process sniffs the bytes itself; what the caller was told by a sender is
 * never what a parser is handed.
 *
 * Contract: stdout is `category=<cat>`, a blank line, then the text. Exit 0 =
 * success (text possibly empty), 2 = bad usage, 3 = parse error, 4 =
 * unsupported type, 5 = secured/encrypted document. DocumentText owns both
 * sides of this contract; nothing else parses it.
 *
 * @version 1.1.0 - runs under the parser jail: the vendor directory is an
 *   argument, class resolution is core-only, the stale-staging sweep runs
 *   here at startup (the jail user's files are its own to remove), and the
 *   `parser` form runs a SandboxParserInterface class from a file path
 * @version 1.0.0
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	echo "CLI only\n";
	exit(2);
}

function extractor_usage(): void {
	fwrite(STDERR, "Usage: extract_document_text.php <vendor_dir> document <path|-> <mime> <max_chars>\n"
		. "       extract_document_text.php <vendor_dir> parser <handler_file> <Class> <options_json> [many]\n");
	exit(2);
}

if (!isset($argv[1], $argv[2])) {
	extractor_usage();
}

require_once(__DIR__ . '/../includes/PathHelper.php');

// No settings, no database, no theme chain, no plugin registry: this process
// resolves core classes from the filesystem and is handed everything else.
ClassAutoloader::restrictToCore();
PathHelper::setComposerVendorPath((string)$argv[1]);

// A killed child runs none of its own cleanup, so every extraction sweeps the
// staging a killed predecessor left behind. Done here rather than in the
// parent because /dev/shm is sticky: only the user that staged a file may
// remove it, and under the jail that user is this one.
DocumentText::sweepStaleStaged();

/** Everything on stdin, or exit 3. */
function extractor_stdin(): string {
	$bytes = stream_get_contents(STDIN);
	if ($bytes === false) {
		fwrite(STDERR, "extract_document_text: could not read stdin\n");
		exit(3);
	}
	return $bytes;
}

/** Report a refusal the way the parent expects it: header, reason on stderr, kind as exit code. */
function extractor_refuse(DocumentTextException $e): void {
	// The category header goes out even on a failure: knowing the file WAS a
	// PDF is what lets the caller say something better than "unreadable".
	echo 'category=' . (string)$e->category . "\n\n";
	fwrite(STDERR, $e->getMessage() . "\n");
	if ($e->kind === DocumentText::SKIPPED) exit(4);
	if ($e->kind === DocumentText::SECURED) exit(5);
	exit(3);
}

$form = (string)$argv[2];

// ---- document ---------------------------------------------------------------
if ($form === 'document') {
	if (!isset($argv[3], $argv[4])) {
		extractor_usage();
	}
	$path      = (string)$argv[3];
	$mime_hint = (string)$argv[4];
	$max_chars = isset($argv[5]) ? max(1, (int)$argv[5]) : DocumentText::DEFAULT_MAX_CHARS;

	if ($path === '-') {
		$bytes = extractor_stdin();
	} else {
		if (!is_file($path) || !is_readable($path)) {
			fwrite(STDERR, "extract_document_text: unreadable path: $path\n");
			exit(3);
		}
		$bytes = file_get_contents($path);
		if ($bytes === false) {
			fwrite(STDERR, "extract_document_text: could not read: $path\n");
			exit(3);
		}
	}

	if ($bytes === '') {
		echo "category=\n\n";
		exit(0);
	}

	try {
		$result = DocumentText::sandboxExtract($bytes, $mime_hint, $max_chars);
	} catch (DocumentTextException $e) {
		extractor_refuse($e);
	} catch (Throwable $e) {
		fwrite(STDERR, 'extract_document_text: ' . $e->getMessage() . "\n");
		exit(3);
	}

	echo 'category=' . $result['category'] . "\n\n";
	echo $result['text'];
	exit(0);
}

// ---- parser -------------------------------------------------------------------
if ($form === 'parser') {
	if (!isset($argv[3], $argv[4], $argv[5])) {
		extractor_usage();
	}
	$handler_file = (string)$argv[3];
	$class        = (string)$argv[4];
	$options      = json_decode((string)$argv[5], true);
	$many         = isset($argv[6]) && $argv[6] === 'many';
	if (!is_array($options)) $options = array();

	// The handler is code from this tree, named by the parent. Anything else
	// is refused before it is loaded: a file outside the tree is not ours.
	$root = realpath(PathHelper::getRootDir());
	$real = realpath($handler_file);
	if ($root === false || $real === false || strpos($real, $root . '/') !== 0 || !is_file($real)) {
		fwrite(STDERR, "extract_document_text: handler is not a file in the code tree: $handler_file\n");
		exit(2);
	}
	if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class)) {
		fwrite(STDERR, "extract_document_text: bad class name\n");
		exit(2);
	}
	require_once($real);
	if (!class_exists($class, false) || !is_subclass_of($class, 'SandboxParserInterface', true)) {
		fwrite(STDERR, "extract_document_text: $class is not a SandboxParserInterface declared in " . basename($real) . "\n");
		exit(2);
	}

	$bytes = extractor_stdin();

	if ($many) {
		$inputs = json_decode($bytes, true);
		if (!is_array($inputs)) {
			fwrite(STDERR, "extract_document_text: many: stdin is not a JSON array\n");
			exit(3);
		}
		$out = array();
		foreach ($inputs as $key => $input) {
			if (!is_string($input)) { $out[$key] = null; continue; }
			try {
				$out[$key] = $class::sandboxParse($input, $options);
			} catch (Throwable $e) {
				// One bad input is one null in the answer, never a lost batch.
				fwrite(STDERR, 'extract_document_text: ' . $key . ': ' . $e->getMessage() . "\n");
				$out[$key] = null;
			}
		}
		$json = json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			fwrite(STDERR, "extract_document_text: many: could not encode the answer\n");
			exit(3);
		}
		echo 'category=' . $class . "\n\n" . $json;
		exit(0);
	}

	try {
		$text = $class::sandboxParse($bytes, $options);
	} catch (DocumentTextException $e) {
		if ($e->category === null) $e->category = $class;
		extractor_refuse($e);
	} catch (Throwable $e) {
		echo 'category=' . $class . "\n\n";
		fwrite(STDERR, 'extract_document_text: ' . $e->getMessage() . "\n");
		exit(3);
	}
	echo 'category=' . $class . "\n\n";
	echo DocumentText::finish($text, 0);
	exit(0);
}

extractor_usage();
