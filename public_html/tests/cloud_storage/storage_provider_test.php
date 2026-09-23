<?php
/** @joinery-test
 * name: storage_provider
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * The provider picker on the cloud storage form
 * (specs/implemented/cloud_storage_provider_picker.md):
 *
 *   - the catalogue: the generic choice first, every provider says what it
 *     asks for, and an endpoint is recognised as its provider
 *   - complete(): a region provider names its endpoint from the region and
 *     refuses a missing region; Cloudflare R2 takes the endpoint and fixes
 *     the region; generic takes both and refuses a missing endpoint;
 *     Backblaze takes the endpoint and region from the key, and says so when
 *     the key is refused or names no endpoint; an unknown provider is generic
 *   - effective(): what a stored binding shows as
 *   - the declarations: the endpoint and region fields show for the providers
 *     that ask for them, and the rules ride on the picker even when a page
 *     draws the group one field at a time
 *
 * Run: php tests/cloud_storage/storage_provider_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/BucketCheck.php'));
require_once(PathHelper::getIncludePath('includes/SettingsFieldRenderer.php'));

// FormWriter starts a session for its CSRF token. Do it here, before the first
// check has written to stdout, or the start is refused.
if (session_status() === PHP_SESSION_NONE) {
	@session_start();
}

// ── The catalogue ────────────────────────────────────────────────────────
section('The catalogue');

$options = StorageProvider::options();
check(array_key_first($options) === StorageProvider::GENERIC, 'the generic choice comes first');
check($options['generic'] === 'Generic S3 compatible bucket', 'the generic choice says what it fits');
foreach (array('b2', 's3', 'r2', 'wasabi', 'digitalocean', 'linode') as $slug) {
	check(StorageProvider::known($slug), "$slug is a known provider");
}
check(!StorageProvider::known('dropbox'), 'an unknown slug is not known');
check(StorageProvider::normalise('dropbox') === 'generic', 'an unknown slug normalises to generic');
check(StorageProvider::normalise('') === 'generic', 'an empty slug normalises to generic');

check(StorageProvider::asks('generic') === array('endpoint', 'region'), 'generic asks for the endpoint and the region');
check(StorageProvider::asks('b2') === array(), 'Backblaze asks for neither');
check(StorageProvider::asks('s3') === array('region'), 'Amazon asks for the region');
check(StorageProvider::asks('r2') === array('endpoint'), 'Cloudflare R2 asks for the endpoint');
foreach (array('wasabi', 'digitalocean', 'linode') as $slug) {
	check(StorageProvider::asks($slug) === array('region'), "$slug asks for the region");
}

check(StorageProvider::endpoint_for('s3', 'us-east-1') === 's3.us-east-1.amazonaws.com', 'Amazon names its endpoint from the region');
check(StorageProvider::endpoint_for('wasabi', 'eu-central-1') === 's3.eu-central-1.wasabisys.com', 'Wasabi names its endpoint from the region');
check(StorageProvider::endpoint_for('digitalocean', 'nyc3') === 'nyc3.digitaloceanspaces.com', 'DigitalOcean names its endpoint from the datacenter');
check(StorageProvider::endpoint_for('linode', 'us-iad-1') === 'us-iad-1.linodeobjects.com', 'Linode names its endpoint from the cluster');
check(StorageProvider::endpoint_for('s3', '') === '', 'no region, no endpoint');
check(StorageProvider::endpoint_for('generic', 'us-east-1') === '', 'generic names no endpoint');

check(StorageProvider::detect('s3.us-west-002.backblazeb2.com') === 'b2', 'a Backblaze endpoint is recognised');
check(StorageProvider::detect('https://s3.us-west-002.backblazeb2.com') === 'b2', 'a Backblaze URL is recognised');
check(StorageProvider::detect('s3.amazonaws.com') === 's3', 'an Amazon endpoint is recognised');
check(StorageProvider::detect('abc123.r2.cloudflarestorage.com') === 'r2', 'an R2 endpoint is recognised');
check(StorageProvider::detect('s3.wasabisys.com') === 'wasabi', 'a Wasabi endpoint is recognised');
check(StorageProvider::detect('nyc3.digitaloceanspaces.com') === 'digitalocean', 'a Spaces endpoint is recognised');
check(StorageProvider::detect('us-east-1.linodeobjects.com') === 'linode', 'a Linode endpoint is recognised');
check(StorageProvider::detect('minio.example.com:9000') === 'generic', 'a MinIO endpoint is generic');
check(StorageProvider::detect('') === 'generic', 'no endpoint is generic');

$catalogue = StorageProvider::catalogue();
check(array_keys($catalogue) === array_keys($options), 'the catalogue for the page carries every provider');
check($catalogue['s3']['asks'] === array('region') && $catalogue['s3']['endpoint'] === 's3.{region}.amazonaws.com'
	&& $catalogue['r2']['region'] === 'auto' && $catalogue['r2']['endpoint_help'] !== '', 'the catalogue says what each asks and names');
check(json_encode($catalogue['b2']['example']) === '{}', 'an empty example is an object, not a list, for the script');

// ── complete() ───────────────────────────────────────────────────────────
section('complete(): what a save settles');

$base = array('access_key' => 'k', 'secret_key' => 's', 'endpoint' => '', 'region' => '');

$r = StorageProvider::complete(array('provider' => 's3', 'region' => 'us-west-2') + $base);
check($r['ok'], 'Amazon with a region passes');
check($r['opts']['endpoint'] === 's3.us-west-2.amazonaws.com' && $r['opts']['region'] === 'us-west-2', 'Amazon names the endpoint');
$r = StorageProvider::complete(array('provider' => 's3', 'region' => '', 'endpoint' => 'typed.anyway.com') + $base);
check(!$r['ok'], 'Amazon without a region is refused');
check($r['message'] === 'Region is required for Amazon S3.', 'the refusal names the region and the provider');
$r = StorageProvider::complete(array('provider' => 'digitalocean', 'region' => ' nyc3 ', 'endpoint' => 'ignored.example.com') + $base);
check($r['ok'] && $r['opts']['endpoint'] === 'nyc3.digitaloceanspaces.com' && $r['opts']['region'] === 'nyc3', 'a region provider ignores a typed endpoint and trims the region');

$r = StorageProvider::complete(array('provider' => 'r2', 'endpoint' => 'abc123.r2.cloudflarestorage.com', 'region' => 'typed') + $base);
check($r['ok'] && $r['opts']['endpoint'] === 'abc123.r2.cloudflarestorage.com' && $r['opts']['region'] === 'auto', 'R2 takes the endpoint and fixes the region');
$r = StorageProvider::complete(array('provider' => 'r2') + $base);
check(!$r['ok'] && $r['message'] === 'Endpoint is required for Cloudflare R2.', 'R2 without an endpoint is refused');

$r = StorageProvider::complete(array('provider' => 'generic', 'endpoint' => 'minio.example.com', 'region' => '') + $base);
check($r['ok'] && $r['opts']['endpoint'] === 'minio.example.com' && $r['opts']['region'] === '', 'generic takes the endpoint and an empty region');
$r = StorageProvider::complete(array('provider' => 'generic', 'endpoint' => 'minio.example.com', 'region' => 'eu') + $base);
check($r['ok'] && $r['opts']['region'] === 'eu', 'generic keeps a region it is given');
$r = StorageProvider::complete(array('provider' => 'generic') + $base);
check(!$r['ok'] && $r['message'] === 'Endpoint is required for Generic S3 compatible bucket.', 'generic without an endpoint is refused');
$r = StorageProvider::complete(array('provider' => 'dropbox', 'endpoint' => 'x.example.com') + $base);
check($r['ok'] && $r['opts']['provider'] === 'generic', 'an unknown provider is treated as generic');

BucketCheck::$test_hooks = array('b2_allowed' => function ($key_id, $app_key) {
	return array('capabilities' => array('listFiles'), 'bucketName' => 'files', 's3_endpoint' => 'https://s3.us-west-004.backblazeb2.com');
});
$r = StorageProvider::complete(array('provider' => 'b2', 'endpoint' => 'typed.example.com', 'region' => 'typed') + $base);
check($r['ok'] && $r['opts']['endpoint'] === 's3.us-west-004.backblazeb2.com', 'Backblaze takes the endpoint from the key');
check($r['opts']['region'] === 'us-west-004', 'Backblaze takes the region from the endpoint it names');
BucketCheck::$test_hooks = array('b2_allowed' => function () { return array('capabilities' => array(), 'bucketName' => ''); });
$r = StorageProvider::complete(array('provider' => 'b2') + $base);
check(!$r['ok'] && $r['message'] === 'Backblaze did not name an S3 endpoint for this key.', 'a key Backblaze names no endpoint for is refused');
BucketCheck::$test_hooks = array('b2_allowed' => function () { throw new Exception('B2 authorize failed (401): bad key'); });
$r = StorageProvider::complete(array('provider' => 'b2') + $base);
check(!$r['ok'] && strpos($r['message'], 'Backblaze refused the key (B2 authorize failed (401): bad key)') === 0, 'a refused key is refused and says so');
$r = StorageProvider::complete(array('provider' => 'b2', 'access_key' => '', 'secret_key' => ''));
check(!$r['ok'] && strpos($r['message'], 'the endpoint and region come from them') !== false, 'Backblaze without a key says the endpoint comes from it');
BucketCheck::$test_hooks = array();

// ── effective() ──────────────────────────────────────────────────────────
section('effective(): what a stored binding shows as');
check(StorageProvider::effective('wasabi', 's3.us-west-002.backblazeb2.com') === 'wasabi', 'a stored provider is what shows');
check(StorageProvider::effective('generic', 's3.us-west-002.backblazeb2.com') === 'b2', 'generic with a recognised endpoint shows as that provider');
check(StorageProvider::effective('generic', 'minio.example.com') === 'generic', 'generic with an unknown endpoint stays generic');
check(StorageProvider::effective('', '') === 'generic', 'nothing stored with no endpoint is generic');
check(StorageProvider::label('dropbox') === 'Generic S3 compatible bucket', 'the label of an unknown slug is the generic one');

// ── The declarations ─────────────────────────────────────────────────────
section('The declarations: the picker shows only what a provider asks for');

$render = function (array $only) {
	$form = new FormWriterV2HTML5('provider_probe');
	ob_start();
	$form->begin_form();
	SettingsFieldRenderer::renderGroup($form, 'cloud_storage', array(
		'source' => 'core', 'only' => $only,
		'values' => array('cloud_storage_provider' => 'generic', 'cloud_storage_endpoint' => '', 'cloud_storage_region' => ''),
	));
	echo $form->end_form();
	return ob_get_clean();
};
$rules_of = function ($html) {
	// The rules FormWriter's script carries for the picker.
	if (!preg_match('/const visibilityRules\w+ = (\{.*?\});\n/', $html, $m)) {
		return null;
	}
	return json_decode($m[1], true);
};

$html = $render(array('cloud_storage_provider'));
check(strpos($html, 'name="cloud_storage_provider"') !== false && strpos($html, '<select') !== false, 'the picker draws as a select');
check(preg_match('/<option[^>]*value="generic"[^>]*>Generic S3 compatible bucket</', $html) === 1
	&& strpos($html, 'value="b2"') !== false && strpos($html, 'value="r2"') !== false, 'the picker offers every provider, the generic one first');
$rules = $rules_of($html);
check(is_array($rules), 'the picker carries its show/hide rules when drawn on its own', is_array($rules) ? '' : substr($html, 0, 200));
if (is_array($rules)) {
	check(in_array('cloud_storage_endpoint', $rules['generic']['show']) && in_array('cloud_storage_region', $rules['generic']['show']), 'generic shows the endpoint and the region');
	check(in_array('cloud_storage_endpoint', $rules['b2']['hide']) && in_array('cloud_storage_region', $rules['b2']['hide']) && empty($rules['b2']['show']), 'Backblaze hides both');
	check(in_array('cloud_storage_region', $rules['s3']['show']) && in_array('cloud_storage_endpoint', $rules['s3']['hide']), 'Amazon shows the region and hides the endpoint');
	check(in_array('cloud_storage_endpoint', $rules['r2']['show']) && in_array('cloud_storage_region', $rules['r2']['hide']), 'R2 shows the endpoint and hides the region');
	foreach (array('wasabi', 'digitalocean', 'linode') as $slug) {
		check(in_array('cloud_storage_region', $rules[$slug]['show']) && in_array('cloud_storage_endpoint', $rules[$slug]['hide']), "$slug shows the region and hides the endpoint");
	}
	check(count($rules) === count(StorageProvider::options()), 'every provider has a rule');
}

$html = $render(array('cloud_storage_endpoint'));
check($rules_of($html) === null && strpos($html, 'name="cloud_storage_endpoint"') !== false, 'the endpoint drawn on its own carries no rules of its own');

harness_finish();
