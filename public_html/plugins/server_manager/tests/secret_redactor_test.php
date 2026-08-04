<?php
/** @joinery-test
 * name: secret_redactor
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * SmSecretRedactor — the display-time guard that masks credential values in
 * job commands and output before they reach a permission-10 admin's screen.
 *
 * The property under test: every secret credential value is masked, the
 * surrounding structure and non-secret fields (public key, region, bucket)
 * survive, and non-credential text passes through untouched.
 *
 * Run: php plugins/server_manager/tests/secret_redactor_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/SmSecretRedactor.php'));

$SECRET = 'K0038f1aVerySecretValue999';
$MASK   = SmSecretRedactor::MASK;

// ---------------------------------------------------------------------------
section('var_export credential block (the JobCommandBuilder shape)');
// ---------------------------------------------------------------------------

$cmd = "php -- upload <<'EOF'\n"
	. "\$creds = array (\n"
	. "  'access_key' => 'AKIA_PUBLIC_ID',\n"
	. "  'secret_key' => '{$SECRET}',\n"
	. "  'region' => 'us-west-002',\n"
	. "  'endpoint' => 'https://s3.us-west-002.backblazeb2.com',\n"
	. ");\n"
	. "\$bucket = 'joinery-backups';\nEOF";

$out = SmSecretRedactor::redact($cmd);
check(strpos($out, $SECRET) === false, 'secret_key value is masked');
check(strpos($out, 'AKIA_PUBLIC_ID') === false, 'access_key value is masked');
check(strpos($out, $MASK) !== false, 'mask token is present');
check(strpos($out, 'us-west-002') !== false, 'region (non-secret) survives');
check(strpos($out, 's3.us-west-002.backblazeb2.com') !== false, 'endpoint (non-secret) survives');
check(strpos($out, "\$bucket = 'joinery-backups'") !== false, 'bucket name survives');

// ---------------------------------------------------------------------------
section('JSON credential shape (colon separator)');
// ---------------------------------------------------------------------------

$json = '{"access_key":"PUB123","secret_key":"' . $SECRET . '","region":"eu"}';
$out  = SmSecretRedactor::redact($json);
check(strpos($out, $SECRET) === false, 'JSON secret_key value masked');
check(strpos($out, 'PUB123') === false, 'JSON access_key value masked');
check(strpos($out, '"region":"eu"') !== false, 'JSON region survives');

// ---------------------------------------------------------------------------
section('node-API header shape ("secret-key: value")');
// ---------------------------------------------------------------------------

$hdr = "curl -H 'public-key: pub_abc123' -H 'secret-key: {$SECRET}' https://node/api";
$out = SmSecretRedactor::redact($hdr);
check(strpos($out, $SECRET) === false, 'secret-key header value masked');
check(strpos($out, 'pub_abc123') !== false, 'public-key header value survives');

// ---------------------------------------------------------------------------
section('shell env-var shape (a hand-typed console command)');
// ---------------------------------------------------------------------------

// The console records commands verbatim, and the credential shape a person
// types at a shell is an env-var prefix — a form neither of the structured
// patterns above would catch.
$env = "PGPASSWORD={$SECRET} psql -U postgres -c 'select 1'";
$out = SmSecretRedactor::redact($env);
check(strpos($out, $SECRET) === false, 'PGPASSWORD value masked');
check(strpos($out, 'psql -U postgres') !== false, 'the rest of the command survives');

$out = SmSecretRedactor::redact("AWS_SECRET_ACCESS_KEY=\"{$SECRET}\" aws s3 ls");
check(strpos($out, $SECRET) === false, 'a quoted env-var value is masked too');

$out = SmSecretRedactor::redact("GITHUB_TOKEN={$SECRET}");
check(strpos($out, $SECRET) === false, 'a token env var is masked');

// Ordinary flags share the word "key" and must stay readable — a redactor that
// eats the command is as unhelpful as one that leaks it.
check(SmSecretRedactor::redact('ssh -i ~/.ssh/id_ed25519 --key=/etc/ssl/site.pem')
	=== 'ssh -i ~/.ssh/id_ed25519 --key=/etc/ssl/site.pem',
	'lowercase flags carrying paths are left alone');
check(SmSecretRedactor::redact('PATH=/usr/local/bin:$PATH make release')
	=== 'PATH=/usr/local/bin:$PATH make release',
	'a non-credential env var is left alone');

// ---------------------------------------------------------------------------
section('non-credential text and edge cases pass through');
// ---------------------------------------------------------------------------

$plain = "UPLOAD_OK /backups/site.tar.gz 10485760 bytes\nLOCAL_DELETE_OK";
check(SmSecretRedactor::redact($plain) === $plain, 'clean output unchanged');
check(SmSecretRedactor::redact('') === '', 'empty string unchanged');
check(SmSecretRedactor::redact('public_key => not-a-secret') === 'public_key => not-a-secret',
	'public_key is never masked');

harness_finish();
