<?php
/** @joinery-test
 * name: vault_registry
 * tier: db
 * env: dev-only
 * needs: []
 *
 * The two Sealed Vault registries: who consumes the vault (VaultConsumers) and
 * which scopes exist (VaultScopes). Both merge a core JSON file with plugin
 * declarations, and both must degrade rather than fatal on a broken one.
 *
 * The load-bearing property in VaultScopes is ISOLATION: a scope's PRF context
 * is derived from its name, so two scopes can never share one. Everything about
 * collisions and name validation below exists to protect that — a shared
 * context means an unlock for one scope opens the other.
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/VaultConsumers.php'));
require_once(PathHelper::getIncludePath('includes/VaultScopes.php'));

// ---------------------------------------------------------------------------
section('Consumer registry: core entries, merge and ordering');
// ---------------------------------------------------------------------------
VaultConsumers::resetForTests();
VaultConsumers::setPluginDeclarationsForTests(array());

$core = VaultConsumers::registered();
check(isset($core['drive_sealed']), 'the core registry ships the Drive consumer');
check(isset($core['direct_spool']), 'the core registry ships the Joinery Direct spool consumer');
check(isset($core['api_idempotency']), 'the core registry ships the API idempotency consumer');
check($core['drive_sealed']['reseals'] === true, 'Drive declares that it stores sealed content');
check($core['api_idempotency']['reseals'] === true, 'the idempotency store declares that it stores sealed content');
check($core['direct_spool']['reseals'] === true,
	'the spool declares the reseal obligation — held deliveries carry parts sealed straight to the vault keypair');
check($core['drive_sealed']['plugin'] === '', 'a core consumer records no owning plugin');
check(is_file($core['drive_sealed']['path']), "a core consumer's bootstrap path resolves to a real file");

VaultConsumers::resetForTests();
VaultConsumers::setPluginDeclarationsForTests(array(
	'zed'   => array('declaration' => array('order' => 5), 'active' => true, 'bootstrap' => 'includes/bootstrap.php'),
	'alpha' => array('declaration' => array('order' => 5), 'active' => true, 'bootstrap' => 'includes/bootstrap.php'),
	'later' => array('declaration' => array('order' => 90), 'active' => true, 'bootstrap' => 'includes/bootstrap.php'),
));
$names = array_keys(VaultConsumers::registered());
$alpha_at = array_search('alpha', $names, true);
$zed_at    = array_search('zed', $names, true);
$later_at  = array_search('later', $names, true);
$drive_at  = array_search('drive_sealed', $names, true);
check($alpha_at !== false && $zed_at !== false && $alpha_at < $zed_at,
	'consumers sharing an order sort by name, so load order never depends on plugin discovery order');
check($zed_at < $drive_at && $drive_at < $later_at,
	'a lower declared order loads first, and plugin and core entries interleave by order rather than by kind');

// ---------------------------------------------------------------------------
section('Consumer registry: malformed declarations degrade, they do not fatal');
// ---------------------------------------------------------------------------
VaultConsumers::resetForTests();
VaultConsumers::setPluginDeclarationsForTests(array(
	'nobootstrap' => array('declaration' => array('reseals' => true), 'active' => true),
	'bogus'       => array('declaration' => 42, 'active' => true),
	'loadpoint'   => array('declaration' => null, 'active' => true, 'bootstrap' => 'includes/bootstrap.php'),
));
$merged = VaultConsumers::registered();
check(isset($merged['nobootstrap']) && $merged['nobootstrap']['path'] === '',
	'a vaultConsumer with no top-level bootstrap key keeps its obligations with no load path');
check($merged['nobootstrap']['reseals'] === true,
	'so a resealer that lost its load point still refuses rotation instead of vanishing from the guard');
check(!isset($merged['bogus']), 'a consumer whose declaration is not an object is skipped');
check(isset($merged['loadpoint']), 'a plugin declaring only the top-level bootstrap key is a load point');
check($merged['loadpoint']['order'] === VaultConsumers::DEFAULT_ORDER,
	'at the default order, after every consumer that declared one');
check($merged['loadpoint']['reseals'] === false && $merged['loadpoint']['caches'] === false,
	'with no vault obligations');
check(isset($merged['drive_sealed']), 'a broken plugin declaration leaves the core entries intact');

// A plugin cannot take over a core consumer's name.
VaultConsumers::resetForTests();
VaultConsumers::setPluginDeclarationsForTests(array(
	'drive_sealed' => array('declaration' => array('reseals' => true), 'active' => true, 'bootstrap' => 'includes/hijack.php'),
));
$merged = VaultConsumers::registered();
check($merged['drive_sealed']['plugin'] === '', 'a plugin redeclaring a core consumer name is refused; core keeps the slot');

// ---------------------------------------------------------------------------
section('Consumer registry: a deactivated plugin leaves the runtime set');
// ---------------------------------------------------------------------------
VaultConsumers::resetForTests();
VaultConsumers::setPluginDeclarationsForTests(array(
	'sleeping' => array('declaration' => array('reseals' => true), 'active' => false, 'bootstrap' => 'includes/bootstrap.php'),
	'awake'    => array('declaration' => array('reseals' => true), 'active' => true, 'bootstrap' => 'includes/bootstrap.php'),
));
check(!isset(VaultConsumers::registered()['sleeping']), 'a deactivated plugin does not load and does not cap windows');
check(isset(VaultConsumers::registered()['awake']), 'an active plugin does');
check(isset(VaultConsumers::allDeclarations()['sleeping']),
	'its declaration is still readable, which is what lets the rotation guard see its stranded content');

// ---------------------------------------------------------------------------
section('Consumer registry: attribution and unmet obligations');
// ---------------------------------------------------------------------------
VaultConsumers::resetForTests();
VaultConsumers::setPluginDeclarationsForTests(array(
	'both'    => array('declaration' => array('reseals' => true, 'caches' => true), 'active' => true, 'bootstrap' => 'b.php'),
	'twice'   => array('declaration' => array('reseals' => true), 'active' => true, 'bootstrap' => 'b.php'),
	'nothing' => array('declaration' => array('reseals' => true, 'caches' => true), 'active' => true, 'bootstrap' => 'b.php'),
));

VaultConsumers::beginLoading('both');
VaultConsumers::noteRegistration(VaultConsumers::OBLIGATION_RESEAL);
VaultConsumers::noteRegistration(VaultConsumers::OBLIGATION_CACHES);
VaultConsumers::endLoading();

VaultConsumers::beginLoading('twice');
VaultConsumers::noteRegistration(VaultConsumers::OBLIGATION_RESEAL);
VaultConsumers::noteRegistration(VaultConsumers::OBLIGATION_RESEAL);
VaultConsumers::endLoading();

// Outside any loading context — a test wiring a callback directly.
VaultConsumers::noteRegistration(VaultConsumers::OBLIGATION_RESEAL);

$counts = VaultConsumers::registrationCounts();
check(($counts['both']['reseals'] ?? 0) === 1, 'a registration attributes to the consumer whose bootstrap was loading');
check(($counts['twice']['reseals'] ?? 0) === 2,
	'a consumer registering two reseal callbacks reads as two, not merely as "registered"');
check(!isset($counts['nothing']), 'a consumer that registered nothing has no attribution at all');

$unmet = VaultConsumers::unmetObligations();
check(!isset($unmet['both']), 'a consumer that met both obligations is not reported');
check(!isset($unmet['twice']), 'registering twice satisfies the obligation once');
check(isset($unmet['nothing']) && in_array('reseals', $unmet['nothing'], true)
	&& in_array('caches', $unmet['nothing'], true),
	'a consumer that declared both and registered neither is reported for both, by name');
check(!isset($unmet['drive_sealed']) || in_array('reseals', $unmet['drive_sealed'], true),
	'core consumers are checked on the same terms as plugins');

VaultConsumers::resetForTests();

// ---------------------------------------------------------------------------
section('Scope registry: core scopes and derived PRF contexts');
// ---------------------------------------------------------------------------
VaultScopes::resetForTests();
VaultScopes::setPluginDeclarationsForTests(array(
	'vault' => array('passwords' => array('custody' => 'client', 'label' => 'Password vault')),
));

check(VaultScopes::custodyFor('user') === 'server', "'user' is a server-custody scope");
check(VaultScopes::custodyFor('drive') === 'client', "'drive' is a client-custody scope");
check(VaultScopes::custodyFor('passwords') === 'client', 'a plugin-declared scope joins the registry');
check(VaultScopes::custodyFor('nonsense') === null, 'an undeclared scope has no custody');

// These three must reproduce EXACTLY, or every existing wrapping is stranded.
check(VaultScopes::prfContext('user') === 'vault-kek', "the 'user' context is grandfathered to vault-kek");
check(VaultScopes::prfContext('passwords') === 'vault-passwords-kek', 'passwords derives vault-passwords-kek');
check(VaultScopes::prfContext('drive') === 'vault-drive-kek', 'drive derives vault-drive-kek');
check(VaultScopes::prfContext('acme_notes') === 'vault-acme_notes-kek', 'a new scope derives its own context from its name');

$contexts = VaultScopes::prfContexts();
check(in_array('vault-kek', $contexts, true) && in_array('vault-drive-kek', $contexts, true)
	&& in_array('vault-passwords-kek', $contexts, true), 'every registered scope contributes an allowed PRF context');
check(count($contexts) === count(array_unique($contexts)),
	'no two registered scopes derive the same context — the isolation guarantee, stated as a check');

check(VaultScopes::labelFor('passwords') === 'Password vault', 'the recovery card title comes from the declared label');
check(VaultScopes::labelFor('undeclared') === 'Undeclared vault', 'an undeclared scope still renders a sane label');
check(in_array('drive', VaultScopes::clientScopes(), true) && !in_array('user', VaultScopes::clientScopes(), true),
	'clientScopes() is the set the browser-held-key actions serve');

// ---------------------------------------------------------------------------
section('Scope registry: server custody is not declarable by a plugin');
// ---------------------------------------------------------------------------
VaultScopes::resetForTests();
VaultScopes::setPluginDeclarationsForTests(array(
	'acme' => array('acme_private' => array('custody' => 'server', 'label' => 'Acme')),
));
check(!VaultScopes::isRegistered('acme_private'),
	'a plugin declaring server custody is refused — there is no enrollment or rotation path for a second one');
$server_scopes = array();
foreach (VaultScopes::all() as $scope => $declaration) {
	if ($declaration['custody'] === 'server') { $server_scopes[] = $scope; }
}
check($server_scopes === array('user'), "'user' remains the only server-custody scope in the merged set");

// ---------------------------------------------------------------------------
section('Scope registry: collisions are refused, never resolved by override');
// ---------------------------------------------------------------------------
VaultScopes::resetForTests();
VaultScopes::setPluginDeclarationsForTests(array(
	'impostor' => array('drive' => array('custody' => 'client', 'label' => 'Not Drive')),
));
check(VaultScopes::declaration('drive')['plugin'] === '',
	'a plugin redeclaring a core scope name is refused; core wins');

VaultScopes::resetForTests();
VaultScopes::setPluginDeclarationsForTests(array(
	'first'  => array('shared' => array('custody' => 'client', 'label' => 'First')),
	'second' => array('shared' => array('custody' => 'client', 'label' => 'Second')),
));
check(!VaultScopes::isRegistered('shared'),
	'two plugins claiming one scope name are BOTH refused — honoring either would let one unlock open the other');

// ---------------------------------------------------------------------------
section('Scope registry: names are validated before they reach a cache key');
// ---------------------------------------------------------------------------
VaultScopes::resetForTests();
VaultScopes::setPluginDeclarationsForTests(array(
	'badnames' => array(
		'Has Spaces'   => array('custody' => 'client'),
		'has-dash'     => array('custody' => 'client'),
		'has.dot'      => array('custody' => 'client'),
		''             => array('custody' => 'client'),
		'way_way_too_long_a_scope_name_to_be_usable' => array('custody' => 'client'),
		'fine_one'     => array('custody' => 'client'),
	),
));
foreach (array('Has Spaces', 'has-dash', 'has.dot', '', 'way_way_too_long_a_scope_name_to_be_usable') as $bad) {
	check(!VaultScopes::isRegistered($bad), 'the scope name "' . $bad . '" is refused');
}
check(VaultScopes::isRegistered('fine_one'), 'a well-formed name is accepted alongside the refused ones');
check(!VaultScopes::isRegistered('has_dash') && !VaultScopes::isRegistered('has_dot'),
	'a refused name is not silently sanitized into a different one — which is how two scopes would collide');

VaultScopes::resetForTests();
VaultConsumers::resetForTests();

harness_finish();
?>
