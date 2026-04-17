<?php
/**
 * Scan URL AJAX endpoint
 * Fetches a web page, extracts all external domains from HTML resource references,
 * and checks each one against the ScrollDaddy DNS filter.
 *
 * @version 1.0
 */

header('Content-Type: application/json');

require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/devices_class.php'));

$session  = SessionControl::get_instance();
$settings = Globalvars::get_instance();

if (!$session->get_user_id()) {
	echo json_encode(array('success' => false, 'message' => 'Not logged in'));
	exit;
}

$device_id = isset($_POST['device_id']) ? (int)$_POST['device_id'] : 0;
$raw_url   = isset($_POST['url'])       ? trim($_POST['url'])        : '';

if (!$device_id || $raw_url === '') {
	echo json_encode(array('success' => false, 'message' => 'Missing device_id or url'));
	exit;
}

// Prepend https:// if no protocol present
if (!preg_match('/^https?:\/\//i', $raw_url)) {
	$raw_url = 'https://' . $raw_url;
}

// Validate scheme
$scheme = strtolower(parse_url($raw_url, PHP_URL_SCHEME));
if (!in_array($scheme, array('http', 'https'))) {
	echo json_encode(array('success' => false, 'message' => 'Invalid URL scheme. Only http and https are supported.'));
	exit;
}

$host = parse_url($raw_url, PHP_URL_HOST);
if (!$host) {
	echo json_encode(array('success' => false, 'message' => 'Invalid URL: could not parse hostname.'));
	exit;
}

// SSRF protection: resolve hostname and reject private/loopback IPv4 ranges
$resolved_ip = gethostbyname($host);
if ($resolved_ip !== $host) {
	$long = ip2long($resolved_ip);
	if ($long !== false) {
		$private_ranges = array(
			array(0x7F000000, 0xFF000000), // 127.0.0.0/8 loopback
			array(0x0A000000, 0xFF000000), // 10.0.0.0/8
			array(0xAC100000, 0xFFF00000), // 172.16.0.0/12
			array(0xC0A80000, 0xFFFF0000), // 192.168.0.0/16
			array(0xA9FE0000, 0xFFFF0000), // 169.254.0.0/16 link-local
		);
		foreach ($private_ranges as $range) {
			if (($long & $range[1]) === $range[0]) {
				echo json_encode(array('success' => false, 'message' => 'URL resolves to a private or reserved address.'));
				exit;
			}
		}
	}
}

// Load device and verify ownership
try {
	$device = new SdDevice($device_id, TRUE);
	$device->authenticate_read(array(
		'current_user_id'         => $session->get_user_id(),
		'current_user_permission' => $session->get_permission(),
	));
} catch (Exception $e) {
	echo json_encode(array('success' => false, 'message' => 'Device not found or access denied.'));
	exit;
}

$resolver_uid = $device->get('sdd_resolver_uid');
if (!$resolver_uid) {
	echo json_encode(array('success' => false, 'message' => 'Device has not been activated yet.'));
	exit;
}

// Fetch the page
$ch = curl_init($raw_url);
curl_setopt_array($ch, array(
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_TIMEOUT        => 15,
	CURLOPT_FOLLOWLOCATION => true,
	CURLOPT_MAXREDIRS      => 5,
	CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
	CURLOPT_HTTPHEADER     => array('Accept: text/html,application/xhtml+xml'),
));
$html      = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);

if ($html === false || $http_code < 200 || $http_code >= 300) {
	echo json_encode(array('success' => false, 'message' => 'Could not fetch the page. The server may be unavailable or blocking automated requests.'));
	exit;
}

// Determine effective page domain after redirects
$page_host   = parse_url($final_url, PHP_URL_HOST) ?: $host;
$page_scheme = parse_url($final_url, PHP_URL_SCHEME) ?: 'https';

/**
 * Returns the registrable domain (last two labels, or three for known SLD TLDs).
 */
function get_registrable_domain($hostname) {
	$known_slds = array('co.uk','org.uk','me.uk','net.uk','ltd.uk','plc.uk','com.au','net.au','org.au','co.nz','net.nz','co.jp','or.jp','ne.jp','com.br','net.br','org.br');
	$parts = explode('.', strtolower($hostname));
	$n     = count($parts);
	if ($n < 2) return $hostname;
	$last2 = $parts[$n - 2] . '.' . $parts[$n - 1];
	foreach ($known_slds as $sld) {
		if ($last2 === $sld && $n >= 3) {
			return $parts[$n - 3] . '.' . $last2;
		}
	}
	return $last2;
}

$page_registrable = get_registrable_domain($page_host);

/**
 * Resolves a URL found in HTML to its hostname and adds it to $found_domains
 * if it is external to the scanned page.
 */
function add_url_to_domains($url, $page_scheme, $page_registrable, &$found_domains) {
	$url = trim($url);
	if ($url === '') return;
	// Protocol-relative
	if (strpos($url, '//') === 0) {
		$url = $page_scheme . ':' . $url;
	}
	// Must be absolute HTTP(S)
	if (!preg_match('/^https?:\/\//i', $url)) return;
	$url_host = parse_url($url, PHP_URL_HOST);
	if (!$url_host) return;
	$url_host = strtolower($url_host);
	// Skip same registrable domain as the scanned page (includes its subdomains)
	if (get_registrable_domain($url_host) === $page_registrable) return;
	$found_domains[$url_host] = true;
}

// Parse HTML
libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML($html);
libxml_clear_errors();

$found_domains = array();

// Standard single-attribute tags
$tag_attrs = array(
	'script' => array('src'),
	'link'   => array('href'),
	'img'    => array('src'),
	'iframe' => array('src'),
	'video'  => array('src'),
	'audio'  => array('src'),
	'form'   => array('action'),
);
foreach ($tag_attrs as $tag => $attrs) {
	foreach ($dom->getElementsByTagName($tag) as $el) {
		foreach ($attrs as $attr) {
			$val = $el->getAttribute($attr);
			if ($val) add_url_to_domains($val, $page_scheme, $page_registrable, $found_domains);
		}
	}
}

// img[srcset] — comma-separated entries; first whitespace token of each entry is the URL
foreach ($dom->getElementsByTagName('img') as $img) {
	$srcset = $img->getAttribute('srcset');
	if ($srcset) {
		foreach (explode(',', $srcset) as $entry) {
			$tokens = preg_split('/\s+/', trim($entry), 2);
			if (!empty($tokens[0])) add_url_to_domains($tokens[0], $page_scheme, $page_registrable, $found_domains);
		}
	}
}

// source[src] and source[srcset]
foreach ($dom->getElementsByTagName('source') as $source) {
	$src = $source->getAttribute('src');
	if ($src) add_url_to_domains($src, $page_scheme, $page_registrable, $found_domains);
	$srcset = $source->getAttribute('srcset');
	if ($srcset) {
		foreach (explode(',', $srcset) as $entry) {
			$tokens = preg_split('/\s+/', trim($entry), 2);
			if (!empty($tokens[0])) add_url_to_domains($tokens[0], $page_scheme, $page_registrable, $found_domains);
		}
	}
}

// Inline <style> blocks: CSS url()
foreach ($dom->getElementsByTagName('style') as $style) {
	$css = $style->textContent;
	preg_match_all('/url\(\s*[\'"]?([^\'"\)\s]+)[\'"]?\s*\)/i', $css, $matches);
	foreach ($matches[1] as $css_url) {
		add_url_to_domains($css_url, $page_scheme, $page_registrable, $found_domains);
	}
}

$all_domains  = array_keys($found_domains);
$domains_found = count($all_domains);

// Cap at 50
$cap    = 50;
$capped = ($domains_found > $cap);
if ($capped) {
	$all_domains = array_slice($all_domains, 0, $cap);
}
$domains_checked = count($all_domains);

if ($domains_checked === 0) {
	echo json_encode(array(
		'success'         => true,
		'mode'            => 'url_scan',
		'scanned_url'     => $final_url,
		'page_domain'     => $page_host,
		'domains_found'   => 0,
		'domains_checked' => 0,
		'capped'          => false,
		'truncated'       => false,
		'results'         => array(),
		'message'         => 'No external domains found on this page.',
	));
	exit;
}

// DNS server settings
$dns_url = $settings->get_setting('scrolldaddy_dns_internal_url');
$api_key = $settings->get_setting('scrolldaddy_dns_api_key');

if (!$dns_url) {
	echo json_encode(array('success' => false, 'message' => 'DNS server not configured.'));
	exit;
}

$dns_headers = array();
if ($api_key) {
	$dns_headers[] = 'X-API-Key: ' . $api_key;
}

// Category display names
$category_names = array(
	'ads_small'    => 'Ads (Light)',
	'ads_medium'   => 'Ads (Medium)',
	'ads'          => 'Ads (Strict)',
	'malware'      => 'Malware',
	'ip_malware'   => 'Malware + IP Threats',
	'ai_malware'   => 'Malware + Phishing',
	'typo'         => 'Phishing & Typosquatting',
	'porn'         => 'Adult Content',
	'porn_strict'  => 'Adult Content (Strict)',
	'gambling'     => 'Gambling',
	'social'       => 'Social Media',
	'fakenews'     => 'Disinformation',
	'cryptominers' => 'Cryptomining',
	'dating'       => 'Dating',
	'drugs'        => 'Drugs',
	'games'        => 'Gaming',
	'ddns'         => 'Dynamic DNS',
	'dnsvpn'       => 'DNS/VPN Bypass',
);

// Check domains concurrently in batches of 5, with 30s total wall-clock limit
$start_time = microtime(true);
$max_time   = 30;
$batch_size = 5;
$truncated  = false;
$results    = array();

foreach (array_chunk($all_domains, $batch_size) as $batch) {
	if (microtime(true) - $start_time > $max_time) {
		$truncated = true;
		break;
	}

	$multi   = curl_multi_init();
	$handles = array();

	foreach ($batch as $domain) {
		$test_url = rtrim($dns_url, '/') . '/test?' . http_build_query(array(
			'uid'    => $resolver_uid,
			'domain' => $domain,
		));
		$ch = curl_init($test_url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 10,
			CURLOPT_HTTPHEADER     => $dns_headers,
		));
		curl_multi_add_handle($multi, $ch);
		$handles[] = array('handle' => $ch, 'domain' => $domain);
	}

	$running = null;
	do {
		curl_multi_exec($multi, $running);
		if ($running) curl_multi_select($multi);
	} while ($running > 0);

	foreach ($handles as $item) {
		$response  = curl_multi_getcontent($item['handle']);
		$http_code = curl_getinfo($item['handle'], CURLINFO_HTTP_CODE);
		curl_multi_remove_handle($multi, $item['handle']);
		curl_close($item['handle']);

		$entry = array('domain' => $item['domain']);

		if ($response === false || $http_code < 200 || $http_code >= 300) {
			$entry['result'] = 'ERROR';
			$entry['detail'] = 'DNS server did not respond.';
		} else {
			$data = json_decode($response, true);
			if (!$data) {
				$entry['result'] = 'ERROR';
				$entry['detail'] = 'Invalid response from DNS server.';
			} else {
				$entry['result'] = $data['result'];
				$entry['reason'] = isset($data['reason']) ? $data['reason'] : '';

				$reason = $entry['reason'];
				if ($reason === 'category_blocklist' && isset($data['category'])) {
					$cat = $data['category'];
					$entry['category'] = $cat;
					$entry['detail']   = 'Matched category: ' . (isset($category_names[$cat]) ? $category_names[$cat] : $cat);
				} elseif ($reason === 'custom_block_rule' && isset($data['matched_rule'])) {
					$entry['detail'] = 'Matched custom block rule: ' . $data['matched_rule'];
				} elseif ($reason === 'custom_allow_rule' && isset($data['matched_rule'])) {
					$entry['detail'] = 'Matched custom allow rule: ' . $data['matched_rule'];
				} elseif ($reason === 'safesearch_rewrite') {
					$entry['detail'] = 'SafeSearch is enabled. Domain is rewritten to enforce safe results.';
				} elseif ($reason === 'safeyoutube_rewrite') {
					$entry['detail'] = 'SafeYouTube is enabled. Domain is rewritten to restricted mode.';
				} elseif ($reason === 'not_blocked') {
					$entry['detail'] = 'Not matched by any filter or rule. Queries are forwarded to upstream DNS.';
				} elseif ($reason === 'unknown_device') {
					$entry['detail'] = 'Device not found by DNS server. It may not have synced yet.';
				} elseif ($reason === 'inactive_device') {
					$entry['detail'] = 'Device is deactivated.';
				} elseif ($reason === 'profile_not_found') {
					$entry['detail'] = 'Profile configuration error. The assigned profile was not found.';
				}
			}
		}

		$results[] = $entry;
	}

	curl_multi_close($multi);
}

echo json_encode(array(
	'success'         => true,
	'mode'            => 'url_scan',
	'scanned_url'     => $final_url,
	'page_domain'     => $page_host,
	'domains_found'   => $domains_found,
	'domains_checked' => $domains_checked,
	'capped'          => $capped,
	'truncated'       => $truncated,
	'results'         => $results,
));
exit;
