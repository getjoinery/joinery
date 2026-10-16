<?php
/**
 * make_site_profile.php - the system call filter every site container runs
 * under, generated from Docker's default (specs/multi_tenant_host_hardening.md
 * D2), never edited by hand.
 *
 * @version 1.0
 *
 *   php make_site_profile.php           writes joinery_site_seccomp.json beside it, and
 *                                       joinery_site_seccomp.source: its hash and the
 *                                       hash of the file it was made from, which the
 *                                       host's installer checks (a host has no PHP)
 *   php make_site_profile.php --check   exit 1 unless both are what this makes
 *
 * The input is site_seccomp.json, Docker's own default.json copied unchanged
 * from moby/profiles, refused unless its hash is the one site_seccomp.source
 * records. The output is that profile with these changes and no others:
 *
 *   - 32-bit x86 is no longer a subarchitecture of x86_64: a 32-bit program
 *     dies at its first system call (runc leaves libseccomp's bad-arch action
 *     at kill). On i386 libseccomp writes every socket rule as socketcall with
 *     no family argument (it is in memory, where a filter cannot read it), so
 *     a socket allow and a socket refusal became one rule with two actions
 *     and runc refused the profile ("file exists"; reviewer2, traced on
 *     libseccomp 2.5.5, confirmed on se1's runc 1.4 and libseccomp 2.6). It
 *     also closes socketcall, which on i386 opens any domain past the rule
 *     below. x32 stays: it uses the native socket call.
 *   - socket: a site may open AF_UNIX, AF_INET and AF_INET6 sockets, and
 *     AF_NETLINK only for NETLINK_ROUTE (address lookups read interfaces
 *     through it). Any other netlink protocol answers EPROTONOSUPPORT and any
 *     other domain EAFNOSUPPORT, never EPERM: PAM's audit hook (cron, runuser,
 *     su) reads "not supported" as "this kernel keeps no audit" and carries
 *     on, and reads "permission denied" as a failure ("System error", se1).
 *     Each refusal compares exact values, a domain alone or a netlink
 *     protocol with its domain, so none overlaps an allow.
 *   - Refused outright, whatever capability a process holds: ptrace,
 *     process_vm_readv, process_vm_writev, kcmp, userfaultfd, the io_uring
 *     calls, bpf, perf_event_open, keyctl, add_key, request_key, unshare,
 *     setns, mount, umount2, pivot_root, open_by_handle_at, init_module,
 *     finit_module, kexec_load, kexec_file_load. Docker's default allows
 *     ptrace and process_vm_* to any process on a 4.8 kernel or newer, and
 *     the rest only with a capability no site has; named here, the list is
 *     ours to read.
 *   - personality: persona 0, and the query (0xffffffff), which reads the
 *     current persona and changes nothing; Docker's other three go.
 *
 * Every rule keeps Docker's order, with the socket rules added last.
 */

const REFUSED = [
	'ptrace', 'process_vm_readv', 'process_vm_writev', 'kcmp', 'userfaultfd',
	'io_uring_setup', 'io_uring_enter', 'io_uring_register', 'bpf', 'perf_event_open',
	'keyctl', 'add_key', 'request_key', 'unshare', 'setns', 'mount', 'umount2',
	'pivot_root', 'open_by_handle_at', 'init_module', 'finit_module', 'kexec_load',
	'kexec_file_load', 'socketcall',
];
const ALLOWED_DOMAINS = [1, 2, 10];  // AF_UNIX, AF_INET, AF_INET6
const AF_NETLINK = 16;
const ALLOWED_NETLINK = [0];         // NETLINK_ROUTE
const DOMAIN_CEILING = 64;           // AF_MAX is below it; every domain from here up is one rule
const NETLINK_CEILING = 32;          // MAX_LINKS; every protocol from here up is one rule
const EAFNOSUPPORT = 97;
const EPROTONOSUPPORT = 93;
const PERSONAS = [0, 4294967295];    // PER_LINUX, and the query

function fail(string $why): void {
	fwrite(STDERR, "make_site_profile: {$why}\n");
	exit(1);
}

/** Docker's default, checked against its record; its hash beside it. */
function docker_default(string $dir): array {
	$src = $dir . '/site_seccomp.json';
	$record = (string)@file_get_contents($dir . '/site_seccomp.source');
	if (!preg_match('/^SHA256="([0-9a-f]{64})"$/m', $record, $m)) {
		fail('site_seccomp.source records no SHA256');
	}
	$raw = @file_get_contents($src);
	if ($raw === false || hash('sha256', $raw) !== $m[1]) {
		fail('site_seccomp.json is not the file site_seccomp.source records; nothing was written');
	}
	$profile = json_decode($raw, true);
	if (!is_array($profile) || !isset($profile['syscalls']) || !is_array($profile['syscalls'])) {
		fail('site_seccomp.json is not a seccomp profile');
	}
	return [$profile, $m[1]];
}

/** The record the host's installer checks the profile against. */
function record_text(string $from_sha, string $profile_text): string {
	return "# Written by make_site_profile.php (specs/multi_tenant_host_hardening.md D2); do not edit.\n"
		. "# joinery_site_seccomp.json is the profile it made from site_seccomp.json. install_site_engine.sh\n"
		. "# installs it only when both hashes match: a host has no PHP to make it again.\n"
		. 'FROM_SHA256="' . $from_sha . "\"\n"
		. 'SHA256="' . hash('sha256', $profile_text) . "\"\n";
}

function arg(int $index, int $value, string $op): array {
	return ['index' => $index, 'value' => $value, 'op' => $op];
}

/** The socket rules: the allowed domains and protocols, then an errno for each refused one. */
function socket_rules(): array {
	$rules = [];
	foreach (ALLOWED_DOMAINS as $d) {
		$rules[] = ['names' => ['socket'], 'action' => 'SCMP_ACT_ALLOW', 'args' => [arg(0, $d, 'SCMP_CMP_EQ')]];
	}
	foreach (ALLOWED_NETLINK as $p) {
		$rules[] = ['names' => ['socket'], 'action' => 'SCMP_ACT_ALLOW',
			'args' => [arg(0, AF_NETLINK, 'SCMP_CMP_EQ'), arg(2, $p, 'SCMP_CMP_EQ')]];
	}
	for ($p = 0; $p < NETLINK_CEILING; $p++) {
		if (!in_array($p, ALLOWED_NETLINK, true)) {
			$rules[] = ['names' => ['socket'], 'action' => 'SCMP_ACT_ERRNO', 'errnoRet' => EPROTONOSUPPORT,
				'args' => [arg(0, AF_NETLINK, 'SCMP_CMP_EQ'), arg(2, $p, 'SCMP_CMP_EQ')]];
		}
	}
	$rules[] = ['names' => ['socket'], 'action' => 'SCMP_ACT_ERRNO', 'errnoRet' => EPROTONOSUPPORT,
		'args' => [arg(0, AF_NETLINK, 'SCMP_CMP_EQ'), arg(2, NETLINK_CEILING, 'SCMP_CMP_GE')]];
	for ($d = 0; $d < DOMAIN_CEILING; $d++) {
		if (!in_array($d, ALLOWED_DOMAINS, true) && $d !== AF_NETLINK) {
			$rules[] = ['names' => ['socket'], 'action' => 'SCMP_ACT_ERRNO', 'errnoRet' => EAFNOSUPPORT,
				'args' => [arg(0, $d, 'SCMP_CMP_EQ')]];
		}
	}
	$rules[] = ['names' => ['socket'], 'action' => 'SCMP_ACT_ERRNO', 'errnoRet' => EAFNOSUPPORT,
		'args' => [arg(0, DOMAIN_CEILING, 'SCMP_CMP_GE')]];
	return $rules;
}

/** Docker's arch map with 32-bit x86 taken out of x86_64's subarchitectures. */
function arch_map(array $map): array {
	foreach ($map as $i => $entry) {
		if (($entry['architecture'] ?? '') === 'SCMP_ARCH_X86_64') {
			$map[$i]['subArchitectures'] = array_values(array_filter($entry['subArchitectures'] ?? [],
				function ($a) { return $a !== 'SCMP_ARCH_X86'; }));
		}
	}
	return $map;
}

function make(array $profile): array {
	$out = [];
	foreach ($profile['syscalls'] as $rule) {
		$names = $rule['names'] ?? [];
		// Docker's own socket rules: replaced whole by ours.
		if ($names === ['socket']) {
			continue;
		}
		// personality: persona 0 and the query only.
		if ($names === ['personality']) {
			$args = $rule['args'] ?? [];
			if (count($args) !== 1 || !in_array($args[0]['value'] ?? null, PERSONAS, true) || ($args[0]['op'] ?? '') !== 'SCMP_CMP_EQ') {
				continue;
			}
		}
		$kept = array_values(array_filter($names, function ($n) { return !in_array($n, REFUSED, true); }));
		if (!$kept) {
			continue;
		}
		$rule['names'] = $kept;
		$out[] = $rule;
	}
	$profile['syscalls'] = array_merge($out, socket_rules());
	$profile['archMap'] = arch_map($profile['archMap'] ?? []);
	return $profile;
}

$dir = __DIR__;
$dest = $dir . '/joinery_site_seccomp.json';
$record = $dir . '/joinery_site_seccomp.source';
[$docker, $from_sha] = docker_default($dir);
$text = json_encode(make($docker), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
$record_text = record_text($from_sha, $text);
if (($argv[1] ?? '') === '--check') {
	if ((string)@file_get_contents($dest) !== $text) {
		fail('joinery_site_seccomp.json is not what this script makes from site_seccomp.json; run it again');
	}
	if ((string)@file_get_contents($record) !== $record_text) {
		fail('joinery_site_seccomp.source does not record the profile this script makes; run it again');
	}
	echo "joinery_site_seccomp.json is current\n";
	exit(0);
}
if (($argv[1] ?? '') !== '') {
	fail('usage: php make_site_profile.php [--check]');
}
if (file_put_contents($dest, $text) === false || file_put_contents($record, $record_text) === false) {
	fail("could not write {$dest} and its record");
}
echo "wrote joinery_site_seccomp.json and joinery_site_seccomp.source\n";
