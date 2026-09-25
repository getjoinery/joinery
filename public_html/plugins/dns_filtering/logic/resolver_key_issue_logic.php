<?php
/**
 * resolver_key_issue - mint the key one DNS server reads this site with.
 *
 * Called by the "DNS server access" panel on the plugin's settings page
 * (plugins/dns_filtering/includes/settings_actions.php). The key is scoped to
 * dns_filtering/resolver_snapshot, read-only, restricted to the server's IPv4
 * address, and owned by the admin issuing it (DnsResolverAccess). Issuing
 * again replaces the slot's key, so this is also how a key is rotated.
 *
 * The secret is in this answer and nowhere else: it is stored only as a hash,
 * and the panel shows it once. The answer also carries the server's
 * SCD_JOINERY_SITES entry for this site, ready to paste.
 *
 * Exposed as POST /api/v1/action/dns_filtering/resolver_key_issue.
 *
 * @version 1.1 - the key belongs to the issuing admin
 * @version 1.0
 */

function resolver_key_issue_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	$slot = isset($input['slot']) ? trim((string)$input['slot']) : '';
	if (!isset(DnsResolverAccess::SLOTS[$slot])) {
		return LogicResult::error('Name the DNS server: primary or secondary.');
	}

	$owner = new User(SessionControl::get_instance()->get_user_id(), TRUE);
	try {
		$issued = DnsResolverAccess::issueKey($slot, $owner);
	} catch (SystemDisplayableError $e) {
		return LogicResult::error($e->getMessage());
	}

	$key = $issued['api_key'];
	$base_url = rtrim(LibraryFunctions::get_absolute_url(''), '/');
	return LogicResult::render(array(
		'slot'          => $slot,
		'name'          => (string)$key->get('apk_name'),
		'public_key'    => (string)$key->get('apk_public_key'),
		'secret_key'    => $issued['secret_key'],
		'restricted_to' => (string)$key->get('apk_ip_restriction'),
		'sites_entry'   => $base_url . '|' . $key->get('apk_public_key') . '|' . $issued['secret_key'],
	));
}

function resolver_key_issue_logic_descriptor(): array {
	return array(
		'description'      => 'Issue (or replace) the key one DNS server reads this site\'s snapshot with. Shows its secret once.',
		'requires_session' => true,
		'mutates'          => true,
		'auth'             => array(
			'capability'               => 'write',
			'requires_browser_session' => true,
			'min_user_permission'      => 8,
		),
		'input'            => array(
			'slot' => array('type' => 'string', 'required' => true, 'label' => 'Which DNS server: primary or secondary'),
		),
	);
}

?>
