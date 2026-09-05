<?php
/** @joinery-test
 * name: relay_first_boot
 * tier: safe
 * env: any
 * needs: []
 * timeout: 60
 *
 * The user-data a relay is born from, and the provider call that carries it
 * (specs/relay_without_a_shell.md § Birth, § Provider support). Three things
 * that would fail on a box nobody can log into rather than here:
 *
 *   1. The renderer and the template must agree on every placeholder. A field
 *      the template declares and the renderer does not fill boots a relay that
 *      refuses to run ("PLANE was not rendered"); a field the renderer fills
 *      and the template lacks silently drops a value.
 *   2. A value carrying a shell metacharacter would rewrite the script. Refused,
 *      never escaped.
 *   3. The Linode driver must put the script where the Metadata service reads
 *      it (base64 in metadata.user_data) on BOTH create and rebuild, and the
 *      StackScript fallback in its own fields — and must never demand a root
 *      password the platform has no business holding.
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayFirstBoot.php'));
require_once(PathHelper::getIncludePath('includes/cloud_compute/LinodeComputeDriver.php'));

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

// ---------------------------------------------------------------------------
section('The renderer and the template declare the same fields');

$template = (string)file_get_contents(RelayFirstBoot::templatePath());
check($template !== '', 'the template is readable');
preg_match_all('/__([A-Z0-9_]+)__/', $template, $m);
$in_template = array_values(array_unique($m[1]));
sort($in_template);
$declared = RelayFirstBoot::FIELDS;
sort($declared);
check($in_template === $declared, 'every placeholder in the template is a declared field, and vice versa',
	'template: ' . implode(',', $in_template) . ' / declared: ' . implode(',', $declared));
foreach (RelayFirstBoot::FIELDS as $f) {
	check(strpos($template, $f . '="${' . $f . ':-__' . $f . '__}"') !== false,
		$f . ' falls back to its UDF environment variable', 'a StackScript region supplies fields as env');
}

// ---------------------------------------------------------------------------
section('Rendering');

$fields = array(
	'plane' => 'https://plane.example', 'run_id' => '17', 'run_token' => str_repeat('ab', 32),
	'bundle_sha256' => str_repeat('0f', 32), 'mail_hostname' => 'mx.example.test',
	'client_public_key' => base64_encode(str_repeat("\x01", 32)),
);
$rendered = RelayFirstBoot::render($fields);
check(strpos($rendered, '__') === false || !preg_match('/__[A-Z_]+__/', $rendered), 'a rendered script has no placeholder left');
check(strpos($rendered, 'PLANE="${PLANE:-https://plane.example}"') !== false, 'the plane URL is baked in');
check(strpos($rendered, 'AUTHSERV_ID="${AUTHSERV_ID:-mx.example.test}"') !== false, 'authserv-id defaults to the mail hostname');
check(strpos($rendered, 'SKELETON_ONLY="${SKELETON_ONLY:-0}"') !== false, 'skeleton_only defaults to 0');
check(strpos($rendered, 'OPERATOR_PUBLIC_KEY="${OPERATOR_PUBLIC_KEY:-}"') !== false, 'an absent optional field renders empty');
check(strpos($rendered, '--keep-sshd') === false || strpos($rendered, 'KEEP_SSHD=0') !== false,
	'the rendered user-data never carries --keep-sshd as a default');
exec('bash -n ' . escapeshellarg(tempnam(sys_get_temp_dir(), 'fb')) . ' 2>&1', $ignored, $ignored_code);
$tmp = tempnam(sys_get_temp_dir(), 'joinery_first_boot_');
file_put_contents($tmp, $rendered);
exec('bash -n ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
@unlink($tmp);
check($code === 0, 'the rendered script parses as bash', implode(' ', $out));

$threw = false;
try { RelayFirstBoot::render(array('plane' => 'https://x', 'run_id' => '1', 'run_token' => 't', 'bundle_sha256' => 'h')); }
catch (InvalidArgumentException $e) { $threw = true; }
check($threw, 'a missing required field refuses to render');
$threw = false;
try { RelayFirstBoot::render($fields + array('authserv_id' => 'x"; rm -rf / #')); }
catch (InvalidArgumentException $e) { $threw = true; }
check($threw, 'a value with a shell metacharacter is refused, not escaped');

$ss = RelayFirstBoot::stackScript();
check(strpos($ss, '# <UDF name="PLANE"') !== false && strpos($ss, '# <UDF name="RUN_TOKEN"') !== false,
	'the StackScript form declares its fields as UDFs');
check(substr_count($ss, '#!/usr/bin/env bash') === 1, 'the StackScript has exactly one shebang');
check(strpos($ss, '__PLANE__') !== false, 'the StackScript keeps the placeholders for the UDF defaults to fall through');

// ---------------------------------------------------------------------------
section('The Linode driver carries the user-data and the StackScript fallback');

$history = array();
$mock = new MockHandler(array(
	new Response(200, array(), json_encode(array('id' => 1, 'status' => 'provisioning', 'ipv4' => array('203.0.113.9'), 'label' => 'r'))),
	new Response(200, array(), json_encode(array('id' => 1, 'status' => 'rebuilding', 'ipv4' => array('203.0.113.9'), 'label' => 'r'))),
	new Response(200, array(), json_encode(array('id' => 'us-east', 'capabilities' => array('Linodes', 'Metadata')))),
	new Response(200, array(), json_encode(array('id' => 'eu-west', 'capabilities' => array('Linodes')))),
	new Response(200, array(), json_encode(array('data' => array()))),
	new Response(200, array(), json_encode(array('id' => 4242, 'label' => 'joinery-relay-first-boot'))),
	new Response(200, array(), json_encode(array('id' => 1, 'status' => 'provisioning', 'ipv4' => array('203.0.113.9'), 'label' => 'r'))),
));
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
$driver = new LinodeComputeDriver('test-token', new Client(array('handler' => $stack, 'base_uri' => 'https://api.linode.test/v4/')));

$driver->createInstance(array('label' => 'r', 'region' => 'us-east', 'type' => 'g6-nanode-1', 'image' => 'linode/ubuntu24.04',
	'user_data' => $rendered));
$create = json_decode((string)$history[0]['request']->getBody(), true);
check(isset($create['metadata']['user_data']) && base64_decode($create['metadata']['user_data'], true) === $rendered,
	'create carries the script as base64 metadata.user_data');
check(!empty($create['root_pass']) && strlen($create['root_pass']) > 20, 'the driver mints the root password the API insists on');
check(!isset($create['authorized_keys']), 'no SSH key is installed when none is given');

$driver->rebuildInstance('1', array('image' => 'linode/ubuntu24.04', 'user_data' => $rendered));
$rebuild = json_decode((string)$history[1]['request']->getBody(), true);
check(isset($rebuild['metadata']['user_data']) && base64_decode($rebuild['metadata']['user_data'], true) === $rendered,
	'rebuild carries fresh user-data the same way — an update is a re-image with new user-data');
check((string)$history[1]['request']->getUri()->getPath() === '/v4/linode/instances/1/rebuild', 'rebuild hits the rebuild endpoint');

check($driver->regionSupportsMetadata('us-east') === true, 'a region listing Metadata supports user-data');
check($driver->regionSupportsMetadata('eu-west') === false, 'a region without Metadata takes the StackScript fallback');

$id = $driver->ensureStackScript('joinery-relay-first-boot', $ss, array('linode/ubuntu24.04'));
check($id === '4242', 'a missing StackScript is created and its id returned', $id);
$created_ss = json_decode((string)$history[5]['request']->getBody(), true);
check(($created_ss['script'] ?? '') === $ss && empty($created_ss['is_public']), 'the StackScript is private and carries the template');

$driver->createInstance(array('label' => 'r', 'region' => 'eu-west', 'type' => 'g6-nanode-1', 'image' => 'linode/ubuntu24.04',
	'stackscript_id' => $id, 'stackscript_data' => array('PLANE' => 'https://plane.example', 'RUN_ID' => '17')));
$create2 = json_decode((string)$history[6]['request']->getBody(), true);
check(($create2['stackscript_id'] ?? 0) === 4242 && ($create2['stackscript_data']['RUN_ID'] ?? '') === '17',
	'the fallback create carries the StackScript id and its UDF values');
check(!isset($create2['metadata']), 'no user_data rides beside a StackScript');

harness_finish();
