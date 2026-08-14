<?php
/**
 * The document text extractor — the short-lived, isolated subprocess that opens
 * hostile bytes so no web request has to.
 *
 * Invoked by DocumentText::extractPath() / ::extractBytes() as:
 *   timeout <secs> php -d memory_limit=256M extract_document_text.php <path|-> <mime-hint> <max_chars>
 *
 * A path of `-` means the document arrives on stdin, which is how sealed
 * content is read: decrypted bytes go down a pipe and never touch disk.
 *
 * Parsing an untrusted file can pin CPU or exhaust RAM. Running it here, under
 * `timeout` and a hard `memory_limit`, means a bomb kills only THIS process:
 * the parent reads the exit code (124 = timed out, 137 = OOM/SIGKILL) and marks
 * the document unreadable without losing its own cleanup path. Never fold this
 * back into the caller with ini_set('memory_limit') — an in-process memory
 * fatal is uncatchable.
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
 * @version 1.0.0
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	echo "CLI only\n";
	exit(2);
}

if (!isset($argv[1], $argv[2])) {
	fwrite(STDERR, "Usage: php extract_document_text.php <path|-> <mime> [max_chars]\n");
	exit(2);
}

$path      = (string)$argv[1];
$mime_hint = (string)$argv[2];
$max_chars = isset($argv[3]) ? max(1, (int)$argv[3]) : 50000;

require_once(__DIR__ . '/../includes/PathHelper.php');

if ($path === '-') {
	$bytes = stream_get_contents(STDIN);
	if ($bytes === false) {
		fwrite(STDERR, "extract_document_text: could not read stdin\n");
		exit(3);
	}
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
	// The category header goes out even on a failure: knowing the file WAS a
	// PDF is what lets the caller say something better than "unreadable".
	echo 'category=' . (string)$e->category . "\n\n";
	fwrite(STDERR, $e->getMessage() . "\n");
	if ($e->kind === DocumentText::SKIPPED) exit(4);
	if ($e->kind === DocumentText::SECURED) exit(5);
	exit(3);
} catch (Throwable $e) {
	fwrite(STDERR, 'extract_document_text: ' . $e->getMessage() . "\n");
	exit(3);
}

echo 'category=' . $result['category'] . "\n\n";
echo $result['text'];
exit(0);
