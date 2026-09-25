<?php
/**
 * resolver_key_revoke - end the key one DNS server reads this site with.
 *
 * Called by the "DNS server access" panel on the plugin's settings page. The
 * server stops getting updates from this site and keeps filtering from its
 * cached copy until it is given a new key.
 *
 * Exposed as POST /api/v1/action/dns_filtering/resolver_key_revoke.
 *
 * @version 1.0
 */

function resolver_key_revoke_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	$slot = isset($input['slot']) ? trim((string)$input['slot']) : '';
	if (!isset(DnsResolverAccess::SLOTS[$slot])) {
		return LogicResult::error('Name the DNS server: primary or secondary.');
	}

	DnsResolverAccess::revokeKey($slot);
	return LogicResult::render(array('slot' => $slot, 'revoked' => true));
}

function resolver_key_revoke_logic_descriptor(): array {
	return array(
		'description'      => 'Revoke the key one DNS server reads this site\'s snapshot with.',
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
