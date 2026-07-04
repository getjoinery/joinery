<?php
/**
 * CLI text extractor for Joinery AI file attachments — the short-lived, isolated
 * subprocess the attachment encoder spawns to parse hostile uploaded bytes.
 *
 * Invoked as:
 *   timeout <secs> php -d memory_limit=256M extract_text.php <path> <mime> [max_chars]
 *
 * Parsing untrusted files (a malformed or bomb PDF/HTML) can pin CPU or exhaust
 * RAM. Running it here, under `timeout` and a hard `memory_limit`, means a bomb
 * kills only THIS process: the parent reads the exit code (124 = timed out by
 * `timeout`, 137 = OOM/SIGKILL) and marks the attachment un-extractable without
 * losing its own cleanup path. Never fold this back into the worker with
 * ini_set('memory_limit') — an in-process memory fatal is uncatchable. See the
 * file-upload spec, Security §4.
 *
 * Contract: extracted text is written to stdout; exit 0 = success (possibly empty
 * text), 2 = bad usage, 3 = parse error, 4 = unsupported type. Bootstraps only
 * PathHelper + Globalvars (needed to locate the Composer autoload for the PDF
 * parser); it opens no DB writes and reads only the already-stored bytes at $path.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(2);
}

if ($argc < 3) {
    fwrite(STDERR, "Usage: php extract_text.php <path> <mime> [max_chars]\n");
    exit(2);
}

$path      = (string)$argv[1];
$mime      = strtolower(trim((string)$argv[2]));
$max_chars = isset($argv[3]) ? max(1, (int)$argv[3]) : 50000;

$semi = strpos($mime, ';');
if ($semi !== false) $mime = trim(substr($mime, 0, $semi));

if (!is_file($path) || !is_readable($path)) {
    fwrite(STDERR, "extract_text: unreadable path: $path\n");
    exit(3);
}

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));

/** Normalize + cap the extracted text: collapse runs of blank lines and trim to
 *  the character ceiling so a huge document can't blow the token budget. */
function ai_extract_finish(string $text, int $max_chars): void {
    $text = str_replace("\r\n", "\n", $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    $text = trim($text);
    if (function_exists('mb_substr')) {
        if (mb_strlen($text) > $max_chars) {
            $text = mb_substr($text, 0, $max_chars) . "\n\n[... attachment text truncated ...]";
        }
    } elseif (strlen($text) > $max_chars) {
        $text = substr($text, 0, $max_chars) . "\n\n[... attachment text truncated ...]";
    }
    echo $text;
    exit(0);
}

// --- PDF: pure-PHP smalot/pdfparser, so a malformed file is at worst a PHP
//     exception/DoS (bounded by timeout+memory), never a native-library RCE.
if ($mime === 'application/pdf') {
    require_once(PathHelper::getComposerAutoloadPath());
    try {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($path);
        ai_extract_finish((string)$pdf->getText(), $max_chars);
    } catch (Throwable $e) {
        fwrite(STDERR, 'extract_text pdf: ' . $e->getMessage() . "\n");
        exit(3);
    }
}

// --- HTML: DOMDocument with LIBXML_NONET (no network, entities left default-off)
//     — drop <script>/<style>/<head> and take visible text. Never strip_tags(),
//     which keeps CSS/JS bodies as noise (spec Security §3).
if ($mime === 'text/html') {
    $html = file_get_contents($path);
    if ($html === false) exit(3);
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    // Force UTF-8 interpretation without a network/DTD fetch.
    $loaded = $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$loaded) exit(3);
    foreach (['script', 'style', 'head', 'noscript'] as $tag) {
        $nodes = $doc->getElementsByTagName($tag);
        // Remove back-to-front; the live NodeList shrinks as we detach.
        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $n = $nodes->item($i);
            if ($n && $n->parentNode) $n->parentNode->removeChild($n);
        }
    }
    $body = $doc->getElementsByTagName('body')->item(0);
    $text = $body ? $body->textContent : $doc->textContent;
    ai_extract_finish((string)$text, $max_chars);
}

// --- Plaintext family: the extracted text IS the file content. Already
//     size-capped at ingress; read directly (no parser surface) and cap length.
if (in_array($mime, ['text/plain', 'text/markdown', 'text/csv', 'application/json',
        'text/x-markdown', 'application/csv'], true)) {
    $bytes = file_get_contents($path);
    if ($bytes === false) exit(3);
    if (!mb_check_encoding($bytes, 'UTF-8')) {
        $bytes = @mb_convert_encoding($bytes, 'UTF-8', 'UTF-8');
    }
    ai_extract_finish((string)$bytes, $max_chars);
}

fwrite(STDERR, "extract_text: unsupported mime: $mime\n");
exit(4);
