<?php
/**
 * GmailFilterExportParser — opens a Gmail mailFilters.xml export inside the
 * parser jail (specs/parser_jail.md) and answers with each entry's property
 * pairs, for InboundEmailFilter::parseGmailExport() to map.
 *
 * The export is a file the owner uploaded, but it is XML and libxml2 opens
 * it, and the platform's rule is that no C parser opens outside bytes in the
 * pool. So the parse happens here, as the joinery-jail user, and the pool
 * receives JSON:
 *
 *   {"entries": [[["from", "dealnews"], ["label", "deals"], …], …], "error": null}
 *
 * Properties keep their document order as [name, value] pairs, because a
 * label can legitimately repeat and order is how the mapper resolves it.
 * The parse goes through DocumentText::xmlDoc(), the one XML door.
 *
 * @version 1.0
 */

class GmailFilterExportParser implements SandboxParserInterface {

	const ERROR_NOT_A_FEED = 'not a Gmail mailFilters.xml feed';
	const APPS_NS = 'http://schemas.google.com/apps/2006';

	public static function sandboxParse(string $bytes, array $options): string {
		try {
			$doc = DocumentText::xmlDoc($bytes);
		} catch (\Throwable $e) {
			return self::answer(null, 'the export declares XML entities, which no Gmail export does');
		}
		if ($doc === null || $doc->documentElement === null
				|| strtolower($doc->documentElement->localName) !== 'feed') {
			return self::answer(null, self::ERROR_NOT_A_FEED);
		}
		$entries = array();
		foreach ($doc->documentElement->childNodes as $entry) {
			if (!($entry instanceof DOMElement) || strtolower($entry->localName) !== 'entry') {
				continue;
			}
			$props = array();
			foreach ($entry->childNodes as $p) {
				if (!($p instanceof DOMElement) || $p->localName !== 'property'
						|| $p->namespaceURI !== self::APPS_NS) {
					continue;
				}
				// name/value are in no namespace
				$props[] = array((string)$p->getAttribute('name'), (string)$p->getAttribute('value'));
			}
			$entries[] = $props;
		}
		return self::answer($entries, null);
	}

	private static function answer(?array $entries, ?string $error): string {
		$json = json_encode(array('entries' => $entries, 'error' => $error),
			JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
		return $json === false ? '{"entries":null,"error":"could not encode the answer"}' : $json;
	}
}
?>
