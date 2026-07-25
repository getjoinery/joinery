<?php
/** @joinery-test
 * name: plugin_settings_tab
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Plugin Settings tab — the admin page where plugin-owned settings are
 * administered (specs/plugin_settings_tab.md).
 *
 * The invariants worth guarding:
 *
 *   A. Discovery. Only an ACTIVE plugin that ships settings_form.php gets a
 *      section, and the tab itself disappears when nothing is behind it.
 *
 *   B. One tab list. Four pages render the settings tab strip; they must all
 *      render the SAME set, which only holds while they share one definition.
 *
 *   C. The include contract. Per-section forms are only safe because no plugin
 *      form opens a form, closes one, or adds a submit button — the page owns
 *      all three. Break that and the page emits nested forms.
 *
 *   D. Write scope. A save writes ONLY the submitting plugin's declared
 *      settings: not a core row, not a sibling plugin's row, and not a row of a
 *      plugin that named itself but ships no form.
 *
 *   E. The vault gate survives the move. All four vault-gated setting names are
 *      mailbox's, and mailbox's fields live on this page, so this logic is now
 *      the only path that can change them.
 *
 * Run: php tests/integration/plugin_settings_tab_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/VaultGatedSettings.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_settings_plugins_logic.php'));

$db = DbConnector::get_instance()->get_db_link();

/**
 * Snapshot a settings row and defer a full restore — value, update time and
 * acting user, so a test that writes leaves no trace in the audit columns
 * either. Runs on a crash exit too (harness_defer contract).
 */
$protect = function ($name) use ($db) {
	$read = $db->prepare("SELECT stg_value, stg_update_time, stg_usr_user_id FROM stg_settings WHERE stg_name = ?");
	$read->execute(array($name));
	$row = $read->fetch(PDO::FETCH_ASSOC);
	if (!$row) {
		harness_defer(function () use ($db, $name) {
			$db->prepare("DELETE FROM stg_settings WHERE stg_name = ?")->execute(array($name));
		});
		return null;
	}
	harness_defer(function () use ($db, $name, $row) {
		$db->prepare("UPDATE stg_settings SET stg_value = ?, stg_update_time = ?, stg_usr_user_id = ? WHERE stg_name = ?")
		   ->execute(array($row['stg_value'], $row['stg_update_time'], $row['stg_usr_user_id'], $name));
	});
	return $row;
};

$value_of = function ($name) use ($db) {
	$read = $db->prepare("SELECT stg_value FROM stg_settings WHERE stg_name = ?");
	$read->execute(array($name));
	$v = $read->fetchColumn();
	return $v === false ? null : $v;
};

$stamp_of = function ($name) use ($db) {
	$read = $db->prepare("SELECT stg_update_time FROM stg_settings WHERE stg_name = ?");
	$read->execute(array($name));
	$v = $read->fetchColumn();
	return $v === false ? null : $v;
};


// =========================================================================
section('A. Discovery — active plugins that ship a settings form');
// =========================================================================

$forms = PluginHelper::getSettingsForms();

check(!empty($forms), 'at least one active plugin ships a settings form', count($forms) . ' found');

$all_exist = true;
$all_active = true;
foreach ($forms as $plugin => $path) {
	if (!file_exists($path))                      $all_exist = false;
	if (!PluginHelper::isPluginActive($plugin))   $all_active = false;
}
check($all_exist, 'every discovered path is a real file');
check($all_active, 'every discovered plugin is active');

$names = array_keys($forms);
$sorted = $names;
sort($sorted);
check($names === $sorted, 'sections come back in a stable (sorted) order', implode(', ', $names));

// An inactive plugin must not contribute a section even though its file is on
// disk. Assert against the filesystem rather than a fixture: every plugin dir
// carrying settings_form.php is either active-and-listed or inactive-and-absent.
$leaked = array();
foreach (glob(PathHelper::getIncludePath('plugins') . '/*/settings_form.php') as $path) {
	$plugin = basename(dirname($path));
	$is_active = PluginHelper::isPluginActive($plugin);
	if (!$is_active && isset($forms[$plugin])) {
		$leaked[] = $plugin;
	}
}
check(empty($leaked), 'no inactive plugin contributes a section', implode(', ', $leaked));


// =========================================================================
section('B. One tab list, shared by every settings tab page');
// =========================================================================

$strip = AdminPage::settings_tab_menu('Plugin Settings');

check(strpos($strip, 'General Settings') !== false, 'the strip offers General Settings');
check(strpos($strip, 'Email Settings') !== false,   'the strip offers Email Settings');
check(strpos($strip, '/admin/admin_settings_plugins') === false
	|| strpos($strip, 'Plugin Settings') !== false,
	'the strip offers Plugin Settings when a plugin form exists');
check(!empty($forms) === (strpos($strip, 'Plugin Settings') !== false),
	'the Plugin Settings tab appears exactly when a plugin form exists');

// The current tab is marked and does not link to itself.
check(strpos($strip, 'active') !== false, 'the calling page\'s tab is marked active');
check(strpos($strip, 'href="/admin/admin_settings_plugins"') === false,
	'the current tab does not link back to itself');

// Payment Settings is store-conditional, exactly as before the move.
check(PluginHelper::isPluginActive('store') === (strpos($strip, 'Payment Settings') !== false),
	'Payment Settings appears exactly when the store plugin is active');

// Drift guard: every settings tab page must go through the shared helper. A page
// that rebuilds its own list is how the sets diverge.
$tab_pages = array(
	'adm/admin_settings.php',
	'adm/admin_settings_email.php',
	'adm/admin_settings_plugins.php',
	'plugins/store/admin/admin_settings_payments.php',
);
foreach ($tab_pages as $rel) {
	$src = file_get_contents(PathHelper::getIncludePath($rel));
	check(strpos($src, 'settings_tab_menu(') !== false, "$rel calls the shared tab helper");
	check(strpos($src, '$tab_menus') === false, "$rel does not build its own tab list");
}


// =========================================================================
section('C. The include contract per-section forms depend on');
// =========================================================================

foreach ($forms as $plugin => $path) {
	$src = file_get_contents($path);
	check(strpos($src, 'begin_form') === false && strpos($src, '<form') === false,
		"$plugin's form does not open a form (nested forms are invalid HTML)");
	check(strpos($src, 'end_form') === false && strpos($src, '</form') === false,
		"$plugin's form does not close the page's form");
	check(strpos($src, 'submitbutton') === false,
		"$plugin's form adds no submit button of its own");

	// The write scope is the manifest, so a rendered field the manifest does not
	// declare would render, accept typing, and silently fail to save. Catch that
	// at the source rather than in a bug report.
	$declared = array();
	foreach (PluginHelper::getInstance($plugin)->getDeclaredSettings() as $declaration) {
		if (is_array($declaration) && !empty($declaration['name'])) {
			$declared[(string)$declaration['name']] = true;
		}
	}
	preg_match_all('/\$formwriter->\w+\(\s*[\'"]([A-Za-z0-9_]+)[\'"]/', $src, $matches);
	$undeclared = array();
	foreach (array_unique($matches[1]) as $field) {
		if (!isset($declared[$field])) $undeclared[] = $field;
	}
	check(empty($undeclared),
		"every field $plugin renders is declared in its plugin.json",
		implode(', ', $undeclared));
}


// =========================================================================
section('D. General Settings no longer carries plugin fields');
// =========================================================================

$general_src = file_get_contents(PathHelper::getIncludePath('adm/admin_settings.php'));
check(strpos($general_src, 'settings_form.php') === false,
	'the General page includes no plugin settings form');
check(strpos($general_src, 'plugin-settings') === false,
	'the General page carries no plugin-settings heading');


// =========================================================================
section('E. A save writes only the submitting plugin\'s declared settings');
// =========================================================================

// Act as a superadmin with no vault, so the vault gate is inert for this
// section and any refusal here would be a scoping bug rather than a gate.
$no_vault_uid = (int)$db->query(
	"SELECT usr_user_id FROM usr_users u
	  WHERE usr_permission >= 8 AND usr_delete_time IS NULL
	    AND NOT EXISTS (SELECT 1 FROM uev_user_encryption_vaults
	                     WHERE uev_usr_user_id = u.usr_user_id AND uev_scope = 'user')
	  ORDER BY usr_user_id LIMIT 1"
)->fetchColumn();

if (!$no_vault_uid) {
	harness_skip('no vault-less admin account to act as');
} else {

	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_SESSION = array();
	$_SESSION['usr_user_id'] = $no_vault_uid;
	$_SESSION['loggedin']    = true;
	$_SESSION['permission']  = 10;

	// The submitting plugin's own row — must change.
	$own = 'dns_filtering_dns_server_ip';
	// A sibling active plugin's row — must not.
	$sibling = 'joinery_ai_local_timeout_seconds';
	// A core row — must not.
	$core = 'request_log_retention_days';

	$protect($own);
	$protect($sibling);
	$protect($core);

	$sibling_before = $value_of($sibling);
	$core_before    = $value_of($core);
	$probe          = '203.0.113.' . random_int(10, 250);

	$result = admin_settings_plugins_logic(array(
		'plugin_settings_target' => 'dns_filtering',
		$own                     => $probe,
		$sibling                 => '999',
		$core                    => '4321',
	));

	check($result->redirect === '/admin/admin_settings_plugins',
		'a valid save redirects back to the tab');
	check($value_of($own) === $probe, 'the submitting plugin\'s declared setting is written');
	check($value_of($sibling) === $sibling_before,
		'a sibling plugin\'s setting in the same POST is ignored', 'was ' . var_export($sibling_before, true));
	check($value_of($core) === $core_before,
		'a core setting in the same POST is ignored', 'was ' . var_export($core_before, true));

	// Re-submitting the same value must not re-stamp the audit column.
	$stamp_before = $stamp_of($own);
	admin_settings_plugins_logic(array(
		'plugin_settings_target' => 'dns_filtering',
		$own                     => $probe,
	));
	check($stamp_of($own) === $stamp_before,
		'an unchanged value is not a write (stg_update_time survives)');

	// A save that names no plugin, an unknown plugin, or a plugin with no form
	// is refused outright — nothing is written.
	$before = $value_of($own);

	$missing = admin_settings_plugins_logic(array($own => '198.51.100.7'));
	check($missing->error !== null, 'a save naming no plugin is refused');

	$unknown = admin_settings_plugins_logic(array(
		'plugin_settings_target' => 'no_such_plugin',
		$own                     => '198.51.100.7',
	));
	check($unknown->error !== null, 'a save naming an unknown plugin is refused');

	// An active plugin that ships NO settings form is not addressable either.
	$formless = null;
	foreach (PluginHelper::getActivePlugins() as $plugin_name => $plugin_obj) {
		if (!isset($forms[$plugin_name])) { $formless = $plugin_name; break; }
	}
	if ($formless !== null) {
		$no_form = admin_settings_plugins_logic(array(
			'plugin_settings_target' => $formless,
			$own                     => '198.51.100.7',
		));
		check($no_form->error !== null,
			"an active plugin with no settings form is not addressable ($formless)");
	}

	check($value_of($own) === $before, 'no refused save wrote anything');
}


// =========================================================================
section('F. The vault gate came across with the mailbox fields');
// =========================================================================

// The gated names are plugin-declared. If mailbox stops declaring them the gate
// silently empties, so assert the list is live before trusting the behaviour.
check(VaultGatedSettings::isGated('mailbox_forwarding_smtp_host'),
	'mailbox_forwarding_smtp_host is still a declared vault-gated setting');
check(isset($forms['mailbox']),
	'the mailbox fields are on this page, making this logic their only write path');

// A vault-holding account with no open unlock window must be refused. In CLI
// there is no session id, so VaultUnlock::isOpen() is false by construction.
$vault_uid = (int)$db->query(
	"SELECT uev_usr_user_id FROM uev_user_encryption_vaults
	  WHERE uev_scope = 'user' ORDER BY uev_usr_user_id LIMIT 1"
)->fetchColumn();

if (!$vault_uid) {
	harness_skip('no account with a user-scope vault to act as');
} elseif (!isset($forms['mailbox'])) {
	harness_skip('mailbox plugin inactive, so its gated settings are not on the page');
} else {
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_SESSION = array();
	$_SESSION['usr_user_id'] = $vault_uid;
	$_SESSION['loggedin']    = true;
	$_SESSION['permission']  = 10;

	check(UserEncryptionVault::loadForUser($vault_uid) !== null,
		'the acting account holds a vault');
	check(VaultUnlock::isOpen($vault_uid) === false,
		'the acting account holds no open unlock window');

	$gated   = 'mailbox_forwarding_smtp_host';
	$ungated = 'mailbox_log_retention_days';
	$protect($gated);
	$protect($ungated);

	$gated_before   = $value_of($gated);
	$ungated_before = $value_of($ungated);
	$ungated_probe  = (string)random_int(30, 400);

	admin_settings_plugins_logic(array(
		'plugin_settings_target' => 'mailbox',
		$gated                   => 'relay.attacker.example',
		$ungated                 => $ungated_probe,
	));

	check($value_of($gated) === $gated_before,
		'a gated change is refused without an open unlock window');
	check($value_of($ungated) === $ungated_probe,
		'the rest of the section still saves', 'was ' . var_export($ungated_before, true));
}

harness_finish();
