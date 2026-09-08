<?php
/**
 * domain_setup_check — what the setup wizard would find out about a domain,
 * answered before any server exists.
 *
 * A reader planning an install types their domain into a page and gets back
 * the facts that decide how their install differs from the fresh-domain
 * walkthrough: who registered it, where its DNS is answered and whether the
 * wizard can write there, whether the name already serves a website, whether
 * mail is already delivered somewhere, and whether it publishes a sender
 * policy. The page uses those to show the reader the steps that apply to
 * them and nothing else.
 *
 * The facts come from the same code the wizard's Email and Secure connection
 * screens run — live nameservers matched against the DNS driver roster, the
 * apex records the relocation guard reads — plus one RDAP request for the
 * registrar. Nothing is stored. The domain is not logged beyond the request
 * log that the per-address throttle counts.
 *
 * Guest-reachable and browser-credential only: the page is public, the
 * reader has no account, and the CSRF-bound browser credential keeps callers
 * to same-origin page JS. Each answer costs a handful of resolver queries and
 * one outbound HTTPS request, so a per-address bucket caps it.
 *
 * @version 1.0
 */

const DOMAIN_SETUP_CHECK_LIMIT  = 40;    // lookups per address...
const DOMAIN_SETUP_CHECK_WINDOW = 600;   // ...per ten minutes

function domain_setup_check_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));

	$domain = DnsRecord::normalizeName((string)($input['domain'] ?? ''));
	$domain = preg_replace('#^https?://#', '', $domain);
	$domain = preg_replace('#/.*$#', '', $domain);
	$domain = preg_replace('#^www\.#', '', $domain);

	if ($domain === '') {
		return LogicResult::error('Type the domain you own, like smithfamily.com.');
	}
	if (!domain_setup_check_is_name($domain)) {
		return LogicResult::error('That does not look like a domain name. Type it like smithfamily.com — no http://, no spaces.');
	}

	if (!RequestLogger::check_rate_limit('domain_setup_check', DOMAIN_SETUP_CHECK_LIMIT, DOMAIN_SETUP_CHECK_WINDOW)) {
		return LogicResult::error('Too many lookups from your address. Wait a few minutes and try again.');
	}
	RequestLogger::log('domain_setup_check', 'lookup');

	$live_ns = DnsPublishBox::liveNameservers($domain);
	$apex = domain_setup_check_apex($domain);
	$registrar = RdapLookup::registrar($domain);

	return LogicResult::render(domain_setup_check_classify($domain, $live_ns, $apex, $registrar));
}

/**
 * The classification, separated from the lookups so it is testable.
 *
 * @param string[]    $live_ns   The domain's NS records (empty: nothing answers).
 * @param DnsRecord[] $apex      What the apex answers: A, AAAA, MX, TXT.
 * @param array|null  $registrar RdapLookup::registrar(), or null.
 */
function domain_setup_check_classify(string $domain, array $live_ns, array $apex, ?array $registrar): array {
	require_once(PathHelper::getIncludePath('includes/dns/DnsDriverRegistry.php'));

	$host_key = (string)DnsDriverRegistry::identifyHost($live_ns);
	$host_class = $host_key !== '' ? DnsDriverRegistry::get($host_key) : null;
	$host = array(
		'key'       => $host_key,
		'label'     => $host_class !== null ? $host_class::getLabel() : '',
		'mode'      => 'unknown',
		'gate_note' => '',
	);
	if ($host_class !== null) {
		$gate = $host_class::apiGateNote();
		$host['mode'] = ($gate === '') ? 'writable' : 'gated';
		$host['gate_note'] = $gate;
	}

	$addresses = array();
	$mx = array();
	$spf = false;
	foreach ($apex as $record) {
		if ($record->type === DnsRecord::TYPE_A || $record->type === DnsRecord::TYPE_AAAA) {
			$addresses[] = $record->value;
		} elseif ($record->type === DnsRecord::TYPE_MX) {
			$mx[] = DnsRecord::normalizeName($record->value);
		} elseif ($record->type === DnsRecord::TYPE_TXT && stripos(trim($record->value), 'v=spf1') === 0) {
			$spf = true;
		}
	}
	$website = !empty($addresses);
	$mail = !empty($mx);

	// The registrar and the DNS host are the same company more often than
	// not, and the page says so when they are, or names both when they differ.
	$registrar_display = $registrar['display'] ?? '';
	$same_company = ($registrar_display !== '' && $host['label'] !== ''
		&& domain_setup_check_same_company($registrar_display, $host['label']));

	// Cloudflare registers domains only on its own nameservers, so the
	// quick start's "move the nameservers to Linode" step is impossible there.
	$nameservers_locked = ($host_key === 'cloudflare' && stripos($registrar_display, 'cloudflare') !== false);

	// Which quick start applies, decided once here so the page and the tests
	// agree. The automatic writer is used wherever a driver can write:
	//   linode        fresh domain at a host the wizard cannot write to: move
	//                 the nameservers to Linode and everything is automatic
	//   linode_token  DNS already at Linode: the deploy-form token writes the
	//                 address record at boot and is kept for the mail records
	//   auto          any other writable host: DNS stays, the wizard writes the
	//                 address record and the mail records with that host's key
	//   gated         an existing domain at Namecheap or GoDaddy: the wizard
	//                 writes if the account qualifies, else prints the records
	//   manual        an existing domain at a host with no driver: the wizard
	//                 prints the records
	$fresh = !($website || $mail || $spf);
	if ($host_key === 'linode') {
		$path = 'linode_token';
	} elseif ($host['mode'] === 'writable') {
		$path = 'auto';
	} elseif ($fresh && !$nameservers_locked) {
		$path = 'linode';
	} elseif ($host['mode'] === 'gated') {
		$path = 'gated';
	} else {
		$path = 'manual';
	}

	return array(
		'domain'      => $domain,
		'path'        => $path,
		'registrar'   => $registrar === null ? null : array(
			'name'    => $registrar['name'],
			'display' => $registrar_display,
			'iana_id' => $registrar['iana_id'],
		),
		'dns_host'    => $host,
		'nameservers' => array_values($live_ns),
		'registrar_is_dns_host' => $same_company,
		'nameservers_locked'    => $nameservers_locked,
		'website'     => array('present' => $website, 'addresses' => $addresses),
		'mail'        => array('present' => $mail, 'targets' => $mx),
		'spf'         => $spf,
		// Fresh: nothing at the apex that the quick start's nameserver move
		// would take away. Existing: something is there, and the DNS stays put.
		'situation'   => $fresh ? 'fresh' : 'existing',
	);
}

/** A registrable name: labels of letters, digits and hyphens, at least two. */
function domain_setup_check_is_name(string $domain): bool {
	if (strlen($domain) > 253 || strpos($domain, '.') === false) {
		return false;
	}
	foreach (explode('.', $domain) as $label) {
		if ($label === '' || strlen($label) > 63 || !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label)) {
			return false;
		}
	}
	return true;
}

/** The apex records the classification reads: A, AAAA, MX, TXT. */
function domain_setup_check_apex(string $domain): array {
	$out = array();
	foreach (array(DNS_A => 'ip', DNS_AAAA => 'ipv6', DNS_MX => 'target', DNS_TXT => 'txt') as $type => $field) {
		$rows = @dns_get_record($domain, $type);
		foreach (is_array($rows) ? $rows : array() as $row) {
			$value = trim((string)($row[$field] ?? ''));
			// A null MX ("0 .") is the published way of saying "no mail here";
			// it carries no value and means nothing to this classification.
			if ($value === '' || ($type === DNS_MX && rtrim($value, '.') === '')) {
				continue;
			}
			$record_type = array(DNS_A => DnsRecord::TYPE_A, DNS_AAAA => DnsRecord::TYPE_AAAA,
				DNS_MX => DnsRecord::TYPE_MX, DNS_TXT => DnsRecord::TYPE_TXT)[$type];
			$out[] = new DnsRecord($record_type, $domain, $value);
		}
	}
	return $out;
}

/** "GoDaddy" and "GoDaddy DNS" are one company; so are "Namecheap" and "Namecheap". */
function domain_setup_check_same_company(string $a, string $b): bool {
	$norm = function (string $s): string {
		$s = strtolower($s);
		$s = preg_replace('/\b(dns|registrar|domains)\b/', '', $s);
		return preg_replace('/[^a-z0-9]/', '', $s);
	};
	$a = $norm($a);
	$b = $norm($b);
	return $a !== '' && $b !== '' && (strpos($a, $b) !== false || strpos($b, $a) !== false);
}

function domain_setup_check_logic_descriptor(): array {
	return array(
		'description'      => 'What the setup wizard would find about a domain: registrar, DNS host and whether it is writable, whether a website or mail already exists there.',
		'requires_session' => true,
		'mutates'          => false,
		'auth'             => array(
			'allow_guest'              => true,
			'requires_browser_session' => true,
		),
		'input'            => array(
			'domain' => array('type' => 'string', 'required' => true, 'max_length' => 253, 'label' => 'Domain'),
		),
	);
}
