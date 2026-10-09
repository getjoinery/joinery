<?php
/** @joinery-test
 * name: storage_provider_catalogue
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * One provider list, one endpoint form, one presigner, one target form
 * (specs/storage_targets.md WP1):
 *
 *   - every list of providers is StorageProvider's: the backup target model's
 *     allowed values, and no page, installer or helper keeps a list or a
 *     Backblaze region pattern of its own
 *   - each provider's console sign-in, region rule and addressing style
 *   - endpoints are stored in one form, https://host unless a URL says otherwise
 *   - Amazon buckets are addressed as host labels by every request and link;
 *     the rest, and a bucket name that cannot be a host label, path style
 *   - the one presigner refuses a DELETE
 *   - BackupTarget::complete_credentials() fills what each provider decides,
 *     and says so when Backblaze names an address the rule does not know
 *   - BackupTargetForm::apply(): only the fields a provider asks for are read,
 *     the rest come from the catalogue; an unknown provider and a bad region
 *     are refused; a target has one key, with no second node key or per-run switch
 *
 * Run: php tests/backups/storage_provider_catalogue_test.php
 *
 * @version 1.1 - one key per target (specs/storage_targets.md WP5)
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

// ── One list ─────────────────────────────────────────────────────────────
section('Every list of providers is the catalogue');

$slugs = StorageProvider::slugs();
foreach (array('generic', 'b2', 's3', 'linode', 'r2', 'wasabi', 'digitalocean', 'hetzner') as $slug) {
	check(in_array($slug, $slugs, true), "$slug is in the catalogue");
}
check(BackupTarget::$field_specifications['bkt_provider']['allowed_values'] === $slugs,
	'a backup target allows exactly the catalogue\'s providers');
check(StorageProvider::label('hetzner') === 'Hetzner Object Storage', 'Hetzner has a label');

$root = rtrim(PathHelper::getIncludePath(''), '/');
$readers = array(
	'adm/admin_backups.php', 'adm/logic/admin_backups_logic.php', 'includes/setup_steps/backups.php',
	'includes/BackupTargetForm.php', 'includes/B2Client.php', 'includes/BucketCheck.php', 'data/backup_targets_class.php',
	'utils/install_backup_target.php', 'plugins/server_manager/views/admin/targets.php',
	'plugins/server_manager/views/admin/target_info.php', 'plugins/server_manager/includes/node_detail_tabs/backups.php',
	'plugins/server_manager/includes/RecoveryReadinessItems.php',
);
foreach ($readers as $rel) {
	$src = (string)@file_get_contents($root . '/' . $rel);
	check($src !== '', "$rel exists");
	check(!preg_match("/'linode'\\s*=>\\s*'Linode/", $src), "$rel keeps no provider label list of its own");
	check(!preg_match('/backblazeb2\\\\\\\\?\\.com/', $src), "$rel keeps no Backblaze host pattern of its own");
}
check(!file_exists($root . '/includes/ShelfPresigner.php'), 'there is no second presigner');
check(!file_exists($root . '/includes/TargetLister.php'), 'there is no second lister');

// ── Console, region rule, addressing ────────────────────────────────────
section('Each provider\'s console, region rule and addressing');

foreach ($slugs as $slug) {
	if ($slug === 'generic') {
		check(StorageProvider::console_url($slug) === '', 'generic has no one console');
	} else {
		check(strpos(StorageProvider::console_url($slug), 'https://') === 0, "$slug names its console sign-in");
	}
}
$regions = array(
	array('b2', 'https://s3.us-east-005.backblazeb2.com', 'us-east-005'),
	array('b2', 's3.eu-central-003.backblazeb2.com', 'eu-central-003'),
	array('s3', 's3.us-west-2.amazonaws.com', 'us-west-2'),
	array('s3', 's3-eu-west-1.amazonaws.com', 'eu-west-1'),
	array('wasabi', 's3.eu-central-1.wasabisys.com', 'eu-central-1'),
	array('digitalocean', 'nyc3.digitaloceanspaces.com', 'nyc3'),
	array('linode', 'https://us-iad-1.linodeobjects.com', 'us-iad-1'),
	array('hetzner', 'fsn1.your-objectstorage.com', 'fsn1'),
	array('b2', 'https://api.backblazeb2.com', ''),
	array('r2', 'abc.r2.cloudflarestorage.com', ''),
	array('generic', 'minio.example.com', ''),
);
foreach ($regions as $case) {
	check(StorageProvider::region_from_endpoint($case[0], $case[1]) === $case[2],
		$case[0] . ' reads "' . $case[2] . '" from ' . $case[1], StorageProvider::region_from_endpoint($case[0], $case[1]));
}
$loc = StorageProvider::b2_location('https://s3.us-west-004.backblazeb2.com');
check($loc === array('region' => 'us-west-004', 'endpoint' => 'https://s3.us-west-004.backblazeb2.com'), 'a Backblaze cluster the old pattern did not know is read', json_encode($loc));

check(StorageProvider::virtual_host('https://s3.us-east-1.amazonaws.com', 'my-backups'), 'an Amazon bucket is a host label');
check(!StorageProvider::virtual_host('https://s3.us-east-1.amazonaws.com', 'my.backups'), 'a dotted Amazon bucket stays path style');
check(!StorageProvider::virtual_host('https://s3.us-east-1.amazonaws.com', 'My-Backups'), 'a bucket with capitals stays path style');
check(!StorageProvider::virtual_host('https://us-east-1.linodeobjects.com', 'my-backups'), 'a Linode bucket is path style');

$aws = array('access_key' => 'AKIA', 'secret_key' => 's', 'region' => 'us-east-1', 'endpoint' => 's3.us-east-1.amazonaws.com');
$lin = array('access_key' => 'k', 'secret_key' => 's', 'region' => 'us-east-1', 'endpoint' => 'us-east-1.linodeobjects.com');
check(S3Signer::object_url($aws['endpoint'], 'my-backups', 'a/b c.enc') === 'https://my-backups.s3.us-east-1.amazonaws.com/a/b%20c.enc',
	'an Amazon object\'s address names the bucket in the host', S3Signer::object_url($aws['endpoint'], 'my-backups', 'a/b c.enc'));
check(S3Signer::object_url($lin['endpoint'], 'my-backups', 'a/b.enc') === 'https://us-east-1.linodeobjects.com/my-backups/a/b.enc',
	'a Linode object\'s address names the bucket in the path');
check(strpos(S3Signer::presign_get($aws, 'my-backups', '/a/b.enc'), 'https://my-backups.s3.us-east-1.amazonaws.com/a/b.enc?') === 0,
	'an Amazon link is signed for the bucket\'s host');
check(strpos(S3Signer::presign($lin, 'my-backups', 'a/b.enc', 'PUT'), 'https://us-east-1.linodeobjects.com/my-backups/a/b.enc?') === 0,
	'a Linode link is signed path style');
try {
	S3Signer::presign($aws, 'my-backups', 'a/b.enc', 'DELETE');
	check(false, 'the presigner refuses a DELETE');
} catch (S3SignerException $e) {
	check(true, 'the presigner refuses a DELETE');
}

// ── One endpoint form ───────────────────────────────────────────────────
section('Endpoints are stored in one form');

$forms = array(
	'us-east-1.linodeobjects.com'              => 'https://us-east-1.linodeobjects.com',
	'https://S3.US-EAST-005.BackblazeB2.com/'  => 'https://s3.us-east-005.backblazeb2.com',
	'https://s3.example.com/some/path'         => 'https://s3.example.com',
	'http://127.0.0.1:9000'                    => 'http://127.0.0.1:9000',
	''                                         => '',
);
foreach ($forms as $in => $want) {
	check(StorageProvider::normalise_endpoint($in) === $want, var_export($in, true) . ' is stored as ' . var_export($want, true),
		StorageProvider::normalise_endpoint($in));
}

// ── Filling what a provider decides ─────────────────────────────────────
section('complete_credentials() fills what each provider decides');

$c = BackupTarget::complete_credentials('s3', array('access_key' => 'a', 'secret_key' => 'b', 'region' => 'eu-west-1'));
check($c['creds']['endpoint'] === 'https://s3.eu-west-1.amazonaws.com', 'Amazon\'s endpoint comes from the region', $c['creds']['endpoint']);
$c = BackupTarget::complete_credentials('linode', array('access_key' => 'a', 'secret_key' => 'b', 'region' => 'us-iad-1'));
check($c['creds']['endpoint'] === 'https://us-iad-1.linodeobjects.com', 'Linode\'s endpoint comes from the cluster');
$c = BackupTarget::complete_credentials('r2', array('access_key' => 'a', 'secret_key' => 'b', 'endpoint' => 'acct.r2.cloudflarestorage.com'));
check($c['creds']['region'] === 'auto' && $c['creds']['endpoint'] === 'https://acct.r2.cloudflarestorage.com', 'R2 takes the endpoint and fixes the region');
$c = BackupTarget::complete_credentials('linode', array('region' => 'us-east-1', 'endpoint' => 'https://us-east-1.linodeobjects.com'));
check($c['creds']['endpoint'] === 'https://us-east-1.linodeobjects.com', 'a typed endpoint is kept');

BackupTarget::$b2_locator = function () { return 'https://s3.us-east-005.backblazeb2.com'; };
harness_defer(function () { BackupTarget::$b2_locator = null; });
$c = BackupTarget::complete_credentials('b2', array('access_key' => 'a', 'secret_key' => 'b'));
check($c['creds']['region'] === 'us-east-005' && $c['creds']['endpoint'] === 'https://s3.us-east-005.backblazeb2.com' && $c['note'] === '',
	'Backblaze is asked for its region and endpoint', json_encode($c));
BackupTarget::$b2_locator = function () { return 'https://storage.example.net'; };
$c = BackupTarget::complete_credentials('b2', array('access_key' => 'a', 'secret_key' => 'b'));
check($c['creds']['endpoint'] === '' && strpos($c['note'], 'storage.example.net') !== false,
	'an address Backblaze names that the rule does not know is said, not left blank in silence', $c['note']);

// ── The one target form ─────────────────────────────────────────────────
section('BackupTargetForm::apply() reads what the provider asks for');

BackupTarget::$b2_locator = function () { return 'https://s3.us-east-005.backblazeb2.com'; };
$post = function (array $extra) {
	return array_merge(array('bkt_name' => 'probe', 'bkt_bucket' => 'probe-bucket', 'bkt_path_prefix' => 'joinery-backups',
		'access_key' => 'AK', 'secret_key' => 'SK', 'bkt_enabled' => '1'), $extra);
};
$t = new BackupTarget(NULL);
$r = BackupTargetForm::apply($t, $post(array('bkt_provider' => 's3', 'region' => 'us-east-2', 'endpoint' => 'stale.backblazeb2.com')));
$creds = $t->get_credentials();
check($r['ok'] && $creds['endpoint'] === 'https://s3.us-east-2.amazonaws.com',
	'a hidden endpoint field is not read; Amazon\'s comes from the region', json_encode($creds));

$t = new BackupTarget(NULL);
$r = BackupTargetForm::apply($t, $post(array('bkt_provider' => 'b2', 'region' => 'typed', 'endpoint' => 'typed.example.com')));
$creds = $t->get_credentials();
check($r['ok'] && $creds['region'] === 'us-east-005' && $creds['endpoint'] === 'https://s3.us-east-005.backblazeb2.com',
	'Backblaze\'s region and endpoint are Backblaze\'s, whatever was posted', json_encode($creds));

$t = new BackupTarget(NULL);
$r = BackupTargetForm::apply($t, $post(array('bkt_provider' => 'hetzner', 'region' => 'fsn1')));
check($r['ok'] && $t->get_credentials()['endpoint'] === 'https://fsn1.your-objectstorage.com', 'a Hetzner target is one catalogue entry away');

$t = new BackupTarget(NULL);
$r = BackupTargetForm::apply($t, $post(array('bkt_provider' => 'dropbox')));
check(!$r['ok'] && $r['message'] === 'Choose a provider.', 'an unknown provider is refused');

$t = new BackupTarget(NULL);
$r = BackupTargetForm::apply($t, $post(array('bkt_provider' => 'wasabi', 'region' => 'https://s3.wasabisys.com')));
check(!$r['ok'] && stripos($r['message'], 'short name') !== false, 'an address in the region field is refused before the provider is asked', $r['message']);

$t = new BackupTarget(NULL);
$r = BackupTargetForm::apply($t, $post(array('bkt_provider' => 's3', 'region' => 'us-east-1',
	'node_access_key' => 'NK', 'node_secret_key' => 'NS', 'bkt_mint_run_keys' => '1')));
check($r['ok'] && !array_key_exists('bkt_node_credentials', BackupTarget::$field_specifications)
	&& !array_key_exists('bkt_mint_run_keys', BackupTarget::$field_specifications)
	&& strpos(json_encode($t->get('bkt_credentials')), 'NK') === false,
	'a target has one key: the form reads no second key and no per-run switch');

section('The provider select shows what each provider asks for');
$rules = StorageProvider::visibility_rules();
check($rules['b2'] === array('show' => array(), 'hide' => array('region', 'endpoint')), 'Backblaze hides both');
check($rules['s3'] === array('show' => array('region'), 'hide' => array('endpoint')), 'Amazon shows the region');
check($rules['r2'] === array('show' => array('endpoint'), 'hide' => array('region')), 'R2 shows the endpoint');
check($rules['generic'] === array('show' => array('region', 'endpoint'), 'hide' => array()), 'generic shows both');

harness_finish();
