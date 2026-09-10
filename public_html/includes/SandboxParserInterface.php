<?php
/**
 * SandboxParserInterface — a parser that runs inside the extraction subprocess.
 *
 * Every place the platform hands a stranger's bytes to a C parser (libxml2,
 * libzip, zlib, the PDF parser) does it behind DocumentText's subprocess,
 * under the parser jail where one is installed (specs/parser_jail.md). The
 * document formats are DocumentText's own business; anything else — a
 * deliverability report, received HTML for a list snippet, a page the AI
 * fetched — is a class implementing this interface, handed to
 * DocumentText::parseWith(). The parent side spawns and reads a string; the
 * class's sandboxParse() is the only code that opens the bytes.
 *
 * What a sandboxParse() implementation may rely on, and nothing more: the
 * bytes it was given, the options array (JSON-safe scalars the caller chose),
 * core classes under includes/ (DocumentText::xmlDoc() is the one XML door),
 * and its own file. It runs with no settings, no database, no network, no
 * other process, as a user that owns nothing — so it must not name a plugin
 * class, read a setting, or reach for a model. Throw DocumentTextException to
 * refuse with a kind; any other throw is reported as a failed parse.
 *
 * @version 1.0.0
 */
interface SandboxParserInterface {

	/**
	 * Parse one input and return the string the caller reads back: text, a
	 * fragment, or a JSON document for structured results.
	 *
	 * @param string $bytes   the input, exactly as the caller supplied it
	 * @param array  $options the caller's options, JSON round-tripped
	 * @throws DocumentTextException to refuse with a kind
	 */
	public static function sandboxParse(string $bytes, array $options): string;
}
