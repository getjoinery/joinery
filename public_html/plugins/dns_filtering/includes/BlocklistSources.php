<?php
/**
 * BlocklistSources - the category -> URL list the DNS servers block from.
 *
 * The list is data, in plugins/dns_filtering/blocklist_sources.json: each
 * category key (a filter key a block can turn on) maps to the URLs whose
 * domains that category blocks, and skip_domains names entries every list
 * carries that are never blocked (localhost and the like). The
 * resolver_snapshot action hands it to the DNS servers, which download and
 * parse the lists themselves.
 *
 * @version 1.0
 */
class BlocklistSources {

	const FILE = 'plugins/dns_filtering/blocklist_sources.json';

	/**
	 * The parsed list: ['categories' => [key => [url, ...]], 'skip_domains' => [...]].
	 * Categories keep the file's order; every category holds at least one URL.
	 *
	 * @throws BlocklistSourcesException when the file is missing or malformed —
	 *   a DNS server handed a broken list would stop refreshing, so this fails
	 *   loudly rather than returning an empty one.
	 */
	public static function load(): array {
		$path = PathHelper::getIncludePath(self::FILE);
		$raw = is_file($path) ? file_get_contents($path) : false;
		if ($raw === false) {
			throw new BlocklistSourcesException(self::FILE . ' is missing.');
		}
		$data = json_decode($raw, true);
		if (!is_array($data) || !isset($data['categories']) || !is_array($data['categories'])) {
			throw new BlocklistSourcesException(self::FILE . ' has no categories object.');
		}

		$categories = array();
		foreach ($data['categories'] as $key => $urls) {
			if (!is_string($key) || !preg_match('/^[a-z0-9_]+$/', $key)) {
				throw new BlocklistSourcesException(self::FILE . ': category key ' . json_encode($key) . ' is not a filter key.');
			}
			if (!is_array($urls) || $urls === array()) {
				throw new BlocklistSourcesException(self::FILE . ": category $key lists no URLs.");
			}
			foreach ($urls as $url) {
				if (!is_string($url) || strpos($url, 'https://') !== 0) {
					throw new BlocklistSourcesException(self::FILE . ": category $key has a URL that is not https: " . json_encode($url));
				}
			}
			$categories[$key] = array_values($urls);
		}

		$skip = isset($data['skip_domains']) && is_array($data['skip_domains'])
			? array_values(array_map('strtolower', $data['skip_domains']))
			: array();

		return array('categories' => $categories, 'skip_domains' => $skip);
	}
}

class BlocklistSourcesException extends Exception {}
