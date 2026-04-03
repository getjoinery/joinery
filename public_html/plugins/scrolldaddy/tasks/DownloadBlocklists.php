<?php
/**
 * DownloadBlocklists - Scheduled Task
 *
 * Downloads domain blocklists from external sources (Hagezi, OISD, StevenBlack, etc.),
 * writes them to bld_blocklist_domains, and triggers a DNS server cache reload.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class DownloadBlocklists implements ScheduledTaskInterface {

	/**
	 * Category-to-source mapping.
	 * Keys must match filter keys used in cdf_ctldfilters (cdf_filter_pk column).
	 * Values are single URL strings or arrays of URLs (merged and deduplicated).
	 */
	const SOURCES = array(
		// Ads
		'ads_small'     => 'https://raw.githubusercontent.com/hagezi/dns-blocklists/main/domains/light.txt',
		'ads_medium'    => 'https://raw.githubusercontent.com/hagezi/dns-blocklists/main/domains/multi.txt',
		'ads'           => 'https://raw.githubusercontent.com/hagezi/dns-blocklists/main/domains/pro.txt',

		// Malware
		'malware'       => 'https://urlhaus.abuse.ch/downloads/hostfile/',
		'ip_malware'    => 'https://raw.githubusercontent.com/hagezi/dns-blocklists/main/domains/tif.txt',
		'ai_malware'    => array(
			'https://raw.githubusercontent.com/hagezi/dns-blocklists/main/domains/tif.txt',
			'https://phishing.army/download/phishing_army_blocklist_extended.txt',
		),

		// Phishing / typosquatting
		'typo'          => 'https://phishing.army/download/phishing_army_blocklist_extended.txt',

		// Adult content
		'porn'          => 'https://raw.githubusercontent.com/hagezi/dns-blocklists/main/domains/nsfw.txt',
		'porn_strict'   => 'https://nsfw.oisd.nl/domainswild',

		// Gambling
		'gambling'      => 'https://raw.githubusercontent.com/hagezi/dns-blocklists/main/domains/gambling.txt',

		// Social media
		'social'        => 'https://raw.githubusercontent.com/StevenBlack/hosts/master/alternates/social/hosts',

		// Disinformation
		'fakenews'      => 'https://raw.githubusercontent.com/StevenBlack/hosts/master/alternates/fakenews/hosts',

		// Cryptomining
		'cryptominers'  => 'https://zerodot1.gitlab.io/CoinBlockerLists/hosts_browser',

		// UT1 Toulouse categories
		'dating'        => 'https://raw.githubusercontent.com/olbat/ut1-blacklists/master/blacklists/dating/domains',
		'drugs'         => 'https://raw.githubusercontent.com/olbat/ut1-blacklists/master/blacklists/drugs/domains',
		'games'         => 'https://raw.githubusercontent.com/olbat/ut1-blacklists/master/blacklists/games/domains',

		// DNS/network bypass
		'ddns'          => 'https://raw.githubusercontent.com/hagezi/dns-blocklists/main/domains/dyndns.txt',
		'dnsvpn'        => 'https://raw.githubusercontent.com/hagezi/dns-blocklists/main/domains/doh.txt',
	);

	/** Domains to always skip regardless of source */
	const SKIP_DOMAINS = array(
		'localhost', '0.0.0.0', '127.0.0.1', 'broadcasthost', 'local',
		'ip6-localhost', 'ip6-loopback', 'ip6-localnet', 'ip6-mcastprefix',
		'ip6-allnodes', 'ip6-allrouters', 'ip6-allhosts',
	);

	public function run(array $config) {
		// Large lists can exhaust the default 128MB web limit
		ini_set('memory_limit', '512M');

		$categories_updated = 0;
		$total_domains = 0;
		$errors = array();

		$dbconnector = DbConnector::get_instance();
		$dblink = $dbconnector->get_db_link();

		// ── Prepare DB: drop index and truncate before writing ────────────────
		try {
			$dblink->exec('DROP INDEX IF EXISTS idx_bld_category_key');
			$dblink->exec('DROP INDEX IF EXISTS idx_bld_domain');
			$dblink->exec('TRUNCATE bld_blocklist_domains');
		} catch (Exception $e) {
			return array(
				'status'  => 'error',
				'message' => 'Failed to prepare table: ' . $e->getMessage(),
			);
		}

		// ── Process one category at a time to keep memory bounded ─────────────
		foreach (self::SOURCES as $category_key => $source) {
			$urls = is_array($source) ? $source : array($source);
			$seen = array();       // dedup within this category
			$domain_count = 0;
			$category_ok = false;

			foreach ($urls as $url) {
				$tmp_path = $this->fetch_to_tempfile($url);
				if ($tmp_path === false) {
					$errors[] = "Download failed for {$category_key}: {$url}";
					continue;
				}

				$fp = fopen($tmp_path, 'r');
				if ($fp === false) {
					unlink($tmp_path);
					$errors[] = "Could not open temp file for {$category_key}: {$url}";
					continue;
				}

				// Stream-parse: build batches and insert as we go
				$batch = array();
				while (($line = fgets($fp)) !== false) {
					$domain = $this->parse_line($line);
					if ($domain === null || isset($seen[$domain])) {
						continue;
					}
					$seen[$domain] = true;
					$batch[] = $domain;
					$domain_count++;

					if (count($batch) >= 5000) {
						$this->batch_insert($dblink, $category_key, $batch);
						$batch = array();
					}
				}
				fclose($fp);
				unlink($tmp_path);

				if (!empty($batch)) {
					$this->batch_insert($dblink, $category_key, $batch);
					$batch = array();
				}

				$category_ok = true;
			}

			if (!$category_ok) {
				continue;
			}

			if ($domain_count < 10) {
				$errors[] = "Suspiciously short list for {$category_key}: {$domain_count} domains (skipped)";
				// Already inserted — not worth deleting, will be overwritten next run
				continue;
			}

			unset($seen); // free memory before next category
			$categories_updated++;
			$total_domains += $domain_count;
		}

		if ($categories_updated === 0) {
			return array(
				'status'  => 'error',
				'message' => 'All downloads failed — table is now empty. Errors: ' . implode('; ', $errors),
			);
		}

		// ── Recreate index and update stats ───────────────────────────────────
		try {
			$dblink->exec('CREATE INDEX idx_bld_domain ON bld_blocklist_domains (bld_category_key, bld_domain)');
			$dblink->exec('ANALYZE bld_blocklist_domains');
		} catch (Exception $e) {
			$errors[] = 'Index/analyze failed: ' . $e->getMessage();
		}

		// ── Bump version so DNS server knows to reload ────────────────────────
		try {
			$q = $dblink->prepare("UPDATE stg_settings SET stg_value = NOW()::text WHERE stg_name = 'scrolldaddy_blocklist_version'");
			$q->execute();
		} catch (Exception $e) {
			$errors[] = 'Version bump failed: ' . $e->getMessage();
		}

		// ── Trigger DNS server reload ─────────────────────────────────────────
		$this->trigger_reload();

		$message = "Updated {$categories_updated} categories, {$total_domains} total domains";
		if (!empty($errors)) {
			$message .= '. Warnings: ' . implode('; ', $errors);
		}

		return array(
			'status'  => 'success',
			'message' => $message,
		);
	}

	/**
	 * Download a URL to a temporary file. Returns the file path on success, false on failure.
	 */
	private function fetch_to_tempfile($url) {
		$tmp_path = tempnam(sys_get_temp_dir(), 'bld_');
		if ($tmp_path === false) {
			return false;
		}

		$fp = fopen($tmp_path, 'w');
		if ($fp === false) {
			unlink($tmp_path);
			return false;
		}

		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_FILE           => $fp,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 5,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_USERAGENT      => 'ScrollDaddy-BlocklistDownloader/1.0',
			CURLOPT_SSL_VERIFYPEER => true,
		));

		$success = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		fclose($fp);

		if (!$success || $http_code < 200 || $http_code >= 300) {
			unlink($tmp_path);
			return false;
		}

		return $tmp_path;
	}

	/**
	 * Parse a single line from a blocklist file.
	 * Returns a valid lowercase domain string, or null if the line should be skipped.
	 */
	private function parse_line($line) {
		$line = trim($line);

		// Skip empty lines and comments
		if ($line === '' || $line[0] === '#' || $line[0] === '!') {
			return null;
		}

		// Strip trailing inline comments
		$pos = strpos($line, ' #');
		if ($pos !== false) {
			$line = trim(substr($line, 0, $pos));
		}

		// Hosts file format: "0.0.0.0 domain" or "127.0.0.1 domain"
		if (strpos($line, ' ') !== false || strpos($line, "\t") !== false) {
			$parts = preg_split('/\s+/', $line, 2);
			$line = isset($parts[1]) ? trim($parts[1]) : '';
		}

		if ($line === '') {
			return null;
		}

		$domain = strtolower($line);

		// Must contain a dot
		if (strpos($domain, '.') === false) {
			return null;
		}
		// No spaces allowed
		if (strpos($domain, ' ') !== false) {
			return null;
		}
		// Skip IP addresses
		if (preg_match('/^[\d.:]+$/', $domain)) {
			return null;
		}

		static $skip = null;
		if ($skip === null) {
			$skip = array_flip(self::SKIP_DOMAINS);
		}
		if (isset($skip[$domain])) {
			return null;
		}

		return $domain;
	}

	/**
	 * Insert a batch of domains for a category using a multi-row INSERT.
	 */
	private function batch_insert($dblink, $category_key, array $domains) {
		if (empty($domains)) {
			return;
		}
		$placeholders = array();
		$values = array();
		foreach ($domains as $domain) {
			$placeholders[] = '(?, ?)';
			$values[] = $category_key;
			$values[] = $domain;
		}
		$sql = 'INSERT INTO bld_blocklist_domains (bld_category_key, bld_domain) VALUES ' . implode(', ', $placeholders);
		$q = $dblink->prepare($sql);
		$q->execute($values);
	}

	/**
	 * POST to the DNS server's /reload endpoint.
	 * Silently ignores errors — the DNS server reloads on its own every 3600s.
	 */
	private function trigger_reload() {
		$settings = Globalvars::get_instance();
		$internal_url = $settings->get_setting('scrolldaddy_dns_internal_url');
		if (!$internal_url) {
			return;
		}
		$ch = curl_init(rtrim($internal_url, '/') . '/reload');
		curl_setopt_array($ch, array(
			CURLOPT_POST           => true,
			CURLOPT_TIMEOUT        => 5,
			CURLOPT_RETURNTRANSFER => true,
		));
		curl_exec($ch);
		curl_close($ch);
	}
}
