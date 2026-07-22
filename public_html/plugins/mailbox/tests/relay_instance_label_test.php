<?php
/** @joinery-test
 * name: relay_instance_label
 * tier: safe
 * env: any
 * needs: []
 *
 * The provider-side instance label is the only thing naming a relay box in the
 * provider's dashboard. It must read as the relay it is (mail hostname) while
 * staying unique within the account, because a rebuild creates the replacement
 * while the predecessor is still running — a bare hostname would collide and
 * fail the create on every rotation.
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayCloudProvisioner.php'));

section('The label names the relay');
$label = RelayCloudProvisioner::instanceLabel('relay1.getjoinery.com', 2);
ok('carries the hostname, not a bare counter', $label === 'relay1-getjoinery-com-2', $label);
ok('a human can tell what the box is', strpos($label, 'relay1') === 0, $label);

section('Uniqueness survives a rotation');
// The old box still exists when the replacement is created, so the two labels
// must differ even though the relay identity is identical.
$old = RelayCloudProvisioner::instanceLabel('relay1.getjoinery.com', 2);
$new = RelayCloudProvisioner::instanceLabel('relay1.getjoinery.com', 3);
ok('consecutive rebuilds do not collide', $old !== $new, "$old vs $new");
ok('the run id is what distinguishes them', $new === 'relay1-getjoinery-com-3', $new);

section('Provider label rules are respected');
$long = RelayCloudProvisioner::instanceLabel(str_repeat('a', 90) . '.example.com', 41);
ok('never exceeds the provider cap', strlen($long) <= 64, strlen($long) . ' chars');
ok('the uniqueness suffix is kept whole', substr($long, -3) === '-41', $long);
ok('does not end on a separator', substr($long, -1) !== '-', $long);
foreach (array(
    'relay1.getjoinery.com'   => 'a plain hostname',
    'MX.Example.COM'          => 'mixed case',
    'mail_relay..example.com' => 'underscores and doubled dots',
    '--weird--.example.com'   => 'leading and trailing separators',
) as $host => $why) {
    $out = RelayCloudProvisioner::instanceLabel($host, 7);
    ok("safe characters only ($why)", preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $out) === 1, $out);
}

section('A missing hostname still yields a usable label');
$fallback = RelayCloudProvisioner::instanceLabel('', 5);
ok('falls back rather than emitting a bare suffix', $fallback === 'joinery-relay-5', $fallback);
ok('fallback still meets the 3-char minimum', strlen($fallback) >= 3, $fallback);
$dots = RelayCloudProvisioner::instanceLabel('...', 6);
ok('a hostname of only separators falls back too', $dots === 'joinery-relay-6', $dots);

harness_finish();
