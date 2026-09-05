<?php
/** @joinery-test
 * name: relay_sealer_publish
 * tier: safe
 * env: any
 * needs: []
 * timeout: 60
 *
 * A relay has no compiler. provision_relay.sh 2.9 installs a PREBUILT sealer
 * from provisioning/bin/relay-sealer-<uname -m> and refuses to proceed without
 * one, and RelaySealerPublisher is what produces those binaries at publish
 * time. Three ways that arrangement can fail QUIETLY, each covered here:
 *
 *   1. THE NAMES CAN DRIFT APART. The publisher writes the names and the shell
 *      script reads them, in two languages, with no shared constant between
 *      them. If either side moved to Go's amd64/arm64 spelling, every publish
 *      would still succeed and every relay would refuse the binary it was
 *      handed. So the script's own resolution line is read here and matched
 *      against what the publisher emits.
 *
 *   2. A BINARY CAN BE WRONG WITHOUT BEING BROKEN. A GOARCH mix-up produces a
 *      perfectly valid ELF under the other architecture's name — a wrong answer
 *      shaped like a right one, which surfaces as an undeliverable message on a
 *      relay hours later rather than as a build error.
 *
 *   3. A PUBLISH CAN SHIP AN EMPTY bin/. The box that cuts a release owns the
 *      source it ships; a missing toolchain there must refuse the release, not
 *      quietly produce a plugin whose provisioning script cannot run.
 *
 * Nothing here compiles anything: the build is exercised only through its
 * failure paths, and the real binaries (when this tree has them) are only read.
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelaySealerPublisher.php'));

$site = dirname(PathHelper::getIncludePath('VERSION'));           // …/public_html
$site = dirname($site);                                            // …/joinerytest
$provisioning = PathHelper::getIncludePath('plugins/mailbox/provisioning');

// ---------------------------------------------------------------------------
section('The publisher and the shell script agree on the file names');

$arches = array_keys(RelaySealerPublisher::ARCHES);
ok('both architectures are named', count($arches) === 2, implode(', ', $arches));
ok('names are uname -m spellings, not GOARCH',
    $arches === array('x86_64', 'aarch64'), implode(', ', $arches));
ok('the GOARCH values are the Go spellings',
    array_values(RelaySealerPublisher::ARCHES) === array('amd64', 'arm64'),
    implode(', ', array_values(RelaySealerPublisher::ARCHES)));

// The other half of the contract, read out of the script that consumes it.
$script = (string)@file_get_contents($provisioning . '/provision_relay.sh');
ok('provision_relay.sh is readable', $script !== '');
ok('the script selects on uname -m',
    strpos($script, 'SEALER_MACHINE="$(uname -m)"') !== false);
ok('the script looks in bin/ beside itself, by machine name',
    strpos($script, '${SCRIPT_DIR}/bin/relay-sealer-${SEALER_MACHINE}') !== false);
ok('the script refuses to continue without one',
    strpos($script, 'no prebuilt relay-sealer binary was delivered') !== false);
ok('the script does not compile the sealer any more',
    strpos($script, 'go build') === false);

// ---------------------------------------------------------------------------
section('A file is only usable if it is this architecture, big enough, and ELF');

$tmp = sys_get_temp_dir() . '/joinery_sealer_test_' . getmypid();
@mkdir($tmp, 0755, true);

/** Return the refusal reason, or 'accepted'. */
$usable = function ($path, $machine) {
    try {
        RelaySealerPublisher::assertUsable($path, $machine);
        return 'accepted';
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
};

// An ELF header for one architecture, padded past the size floor. Only the
// first 20 bytes are read, so the padding does not have to be a real program.
$elf = function ($machine_byte) {
    $h = "\x7f" . 'ELF' . "\x02\x01\x01" . str_repeat("\x00", 9)
       . "\x02\x00" . chr($machine_byte) . "\x00";
    return $h . str_repeat("\x00", RelaySealerPublisher::MIN_BYTES);
};

file_put_contents($tmp . '/text', str_repeat("package main\n", 50000));
ok('a source file named like a binary is refused',
    $usable($tmp . '/text', 'x86_64') === 'not an ELF executable',
    $usable($tmp . '/text', 'x86_64'));

file_put_contents($tmp . '/tiny', $elf(0x3e));
file_put_contents($tmp . '/tiny', substr(file_get_contents($tmp . '/tiny'), 0, 4096));
ok('a truncated download is refused',
    strpos($usable($tmp . '/tiny', 'x86_64'), 'implausibly small') === 0,
    $usable($tmp . '/tiny', 'x86_64'));

file_put_contents($tmp . '/amd', $elf(0x3e));
file_put_contents($tmp . '/arm', $elf(0xb7));
ok('an x86_64 binary under the x86_64 name is accepted',
    $usable($tmp . '/amd', 'x86_64') === 'accepted', $usable($tmp . '/amd', 'x86_64'));
ok('an aarch64 binary under the aarch64 name is accepted',
    $usable($tmp . '/arm', 'aarch64') === 'accepted', $usable($tmp . '/arm', 'aarch64'));

// The mix-up that a size check and a magic check both wave through.
ok('an aarch64 binary under the x86_64 name is REFUSED',
    strpos($usable($tmp . '/arm', 'x86_64'), 'wrong architecture') !== false,
    $usable($tmp . '/arm', 'x86_64'));
ok('an x86_64 binary under the aarch64 name is REFUSED',
    strpos($usable($tmp . '/amd', 'aarch64'), 'wrong architecture') !== false,
    $usable($tmp . '/amd', 'aarch64'));

ok('a missing file is not silently usable',
    $usable($tmp . '/nothing-here', 'x86_64') !== 'accepted');

// ---------------------------------------------------------------------------
section('binariesPresent names the first thing wrong');

$bindir = $tmp . '/bin';
@mkdir($bindir, 0755, true);
ok('an empty bin/ is reported, not accepted',
    RelaySealerPublisher::binariesPresent($bindir) !== null,
    (string)RelaySealerPublisher::binariesPresent($bindir));

copy($tmp . '/amd', $bindir . '/relay-sealer-x86_64');
ok('one architecture present is still incomplete',
    strpos((string)RelaySealerPublisher::binariesPresent($bindir), 'aarch64') !== false,
    (string)RelaySealerPublisher::binariesPresent($bindir));

copy($tmp . '/arm', $bindir . '/relay-sealer-aarch64');
ok('both present and plausible reports nothing wrong',
    RelaySealerPublisher::binariesPresent($bindir) === null,
    (string)RelaySealerPublisher::binariesPresent($bindir));

// A corrupted binary must not pass just because the file exists.
file_put_contents($bindir . '/relay-sealer-aarch64', 'oops');
ok('a corrupted binary is caught by presence checking too',
    RelaySealerPublisher::binariesPresent($bindir) !== null,
    (string)RelaySealerPublisher::binariesPresent($bindir));

// ---------------------------------------------------------------------------
section('The source stamp reacts to code and ignores tests');

$src = $tmp . '/src';
@mkdir($src, 0755, true);
file_put_contents($src . '/main.go', "package main\nfunc main() {}\n");
file_put_contents($src . '/go.mod', "module relay-sealer\n");
$base = RelaySealerPublisher::sourceHash($src);

file_put_contents($src . '/seal_test.go', "package main\n// a test\n");
ok('adding a _test.go file does not move the stamp',
    RelaySealerPublisher::sourceHash($src) === $base);

file_put_contents($src . '/seal_test.go', "package main\n// a different test\n");
ok('editing a _test.go file does not move the stamp',
    RelaySealerPublisher::sourceHash($src) === $base);

file_put_contents($src . '/seal.go', "package main\nvar x = 1\n");
$after_code = RelaySealerPublisher::sourceHash($src);
ok('adding real code DOES move the stamp', $after_code !== $base);

file_put_contents($src . '/go.mod', "module relay-sealer\ngo 1.22\n");
ok('changing go.mod moves the stamp',
    RelaySealerPublisher::sourceHash($src) !== $after_code);

ok('the stamp is a sha256', preg_match('/^[0-9a-f]{64}$/', $base) === 1, $base);
ok('an unstamped bin/ reads as null',
    RelaySealerPublisher::readStamp($tmp . '/no-such-dir') === null);

// ---------------------------------------------------------------------------
section('A publish that cannot build refuses rather than shipping an empty bin/');

// A staged site tree: sealer source present, no binaries, and no Go.
$fake_site = $tmp . '/site';
$fake_src = $fake_site . '/' . RelaySealerPublisher::SOURCE_SUBDIR;
$fake_bin = $fake_site . '/' . RelaySealerPublisher::BIN_SUBDIR;
@mkdir($fake_src, 0755, true);
file_put_contents($fake_src . '/main.go', "package main\nfunc main() {}\n");

$saved_locator = RelaySealerPublisher::$go_locator;
RelaySealerPublisher::$go_locator = function () { return null; };
$r = RelaySealerPublisher::publish($fake_site, null);
RelaySealerPublisher::$go_locator = $saved_locator;

ok('a build owed with no toolchain is a FAILURE',
    $r['status'] === RelaySealerPublisher::STATUS_FAILED, $r['status']);
ok('  and the message names the toolchain',
    strpos($r['message'], 'Go toolchain not found') !== false, $r['message']);
ok('  and no empty bin/ is left behind for the tar to ship',
    !is_dir($fake_bin), $fake_bin);
ok('  and no staging directory is left behind',
    !is_dir($fake_bin . '.staging'), $fake_bin . '.staging');

// The status publish_upgrade.php refuses on has to be exactly this one.
ok('STATUS_FAILED is the only refusing status',
    RelaySealerPublisher::STATUS_FAILED === 'failed');
$publisher_call = (string)@file_get_contents(
    PathHelper::getIncludePath('plugins/server_manager/includes/publish_upgrade.php'));
ok('publish_upgrade.php refuses the release on it',
    strpos($publisher_call, "RelaySealerPublisher::STATUS_FAILED") !== false
    && strpos($publisher_call, 'RelaySealerPublisher::publish($full_site_dir') !== false);
ok('the sealer is built before the plugin archives are made',
    strpos($publisher_call, 'RelaySealerPublisher::publish')
        < strpos($publisher_call, 'Creating individual plugin archives'));

// A box with no mailbox plugin at all publishes fine.
$empty_site = $tmp . '/empty-site';
@mkdir($empty_site, 0755, true);
$r2 = RelaySealerPublisher::publish($empty_site, null);
ok('no sealer source is not a failure',
    $r2['status'] === RelaySealerPublisher::STATUS_ABSENT, $r2['status']);

// ---------------------------------------------------------------------------
section('The delivery path carries bin/');

// A relay is born from the support bundle (specs/relay_without_a_shell.md): the
// bundle carries the script and both binaries, and a run refuses to start when
// the deployment has no bundle to copy.
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/SupportBundlePublisher.php'));
$declared = SupportBundlePublisher::declaredContents();
ok('the support bundle carries provision_relay.sh',
    in_array('public_html/plugins/mailbox/provisioning/provision_relay.sh', $declared, true));
foreach ($arches as $machine) {
    ok('  and bin/relay-sealer-' . $machine . ', by its uname -m name',
        in_array('public_html/plugins/mailbox/provisioning/bin/relay-sealer-' . $machine, $declared, true));
}
// The relay has no compiler, so it has no use for the sealer's Go source, and a
// mail machine that carries source is one somebody will eventually build on.
ok('  and does NOT ship the sealer Go source to the relay',
    count(preg_grep('#provisioning/relay-sealer/#', $declared)) === 0);
$run_model = (string)@file_get_contents(
    PathHelper::getIncludePath('plugins/mailbox/data/relay_cloud_provision_class.php'));
ok('a run refuses to start when this deployment carries no bundle',
    strpos($run_model, 'carries no support bundle') !== false);

// ---------------------------------------------------------------------------
section('This tree, as it stands');

// Informational rather than required: a working copy that has never published
// legitimately has no binaries yet. When it does have them, they must be right.
$real_bin = $provisioning . '/bin';
if (is_dir($real_bin) && glob($real_bin . '/relay-sealer-*')) {
    ok('the binaries in this tree are usable',
        RelaySealerPublisher::binariesPresent($real_bin) === null,
        (string)RelaySealerPublisher::binariesPresent($real_bin));
    ok('  and carry the stamp of the source beside them',
        RelaySealerPublisher::readStamp($real_bin)
            === RelaySealerPublisher::sourceHash($provisioning . '/relay-sealer'));
} else {
    ok('no binaries built in this working copy yet (a publish makes them)', true);
}

// ---------------------------------------------------------------------------
foreach (glob($tmp . '/*') ?: array() as $f) {
    if (is_dir($f)) { continue; }
    @unlink($f);
}
exec('rm -rf ' . escapeshellarg($tmp));

harness_finish();
