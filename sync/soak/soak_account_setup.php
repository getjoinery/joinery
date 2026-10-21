<?php
/**
 * One-off: give the drivetest box what a Drive sync rig needs to exist at all.
 *
 * Run inside the site container. Written against the platform's own model
 * classes rather than as INSERTs, so password hashing, defaults and the
 * group/tier wiring are whatever the platform says they are — a hand-built row
 * that differs from what the code expects fails later and somewhere else.
 *
 * Creates, idempotently:
 *   - an ordinary member account for the rig to sign in as
 *   - a subscription tier group, and the tier that hangs off it
 *   - the membership joining the two
 *   - with SOAK_DRIVE_VAULT_PUBLIC_KEY set, the account's Drive vault: the
 *     encrypted lane's (specs/drive_sync_soak.md § The encrypted lane, E1)
 *
 * The tier is the part that matters. drive_upload_init refuses every upload
 * when drive_storage_bytes or drive_max_file_bytes is 0, and both default to 0
 * with no tier — so a fresh install with Drive switched on accepts a device link
 * and then fails every upload with a quota message about a quota nobody set.
 */

require_once('/var/www/html/drivetest/public_html/includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/groups_class.php'));
require_once(PathHelper::getIncludePath('data/group_members_class.php'));
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));

$account  = getenv('SOAK_ACCOUNT');
$password = getenv('SOAK_PASSWORD');
if (!$account || !$password) {
	fwrite(STDERR, "SOAK_ACCOUNT and SOAK_PASSWORD must be set\n");
	exit(2);
}

$dblink = DbConnector::get_instance()->get_db_link();

// ---------------------------------------------------------------------------
// The account
// ---------------------------------------------------------------------------

$q = $dblink->prepare("SELECT usr_user_id FROM usr_users WHERE usr_email = ? AND usr_delete_time IS NULL");
$q->execute(array($account));
$user_id = (int)$q->fetchColumn();

if ($user_id) {
	echo "account already exists (user $user_id)\n";
	$user = new User($user_id, TRUE);
} else {
	$user = new User(NULL);
	$user->set('usr_email', $account);
	$user->set('usr_first_name', 'Soak');
	$user->set('usr_last_name', 'Rig');
	$user->set('usr_permission', 1);
	$user->save();
	$user->load();
	$user_id = (int)$user->key;
	echo "created account (user $user_id)\n";
}

// usr_password holds an Argon2id HASH, not a password. Setting the plaintext
// stores something no verify() will ever match, and the symptom is a 401 that
// looks exactly like a wrong password.
$user->set('usr_password', User::GeneratePassword($password));

// activation_required_login is on, and an unactivated account is refused at the
// API door the same way it is at the web one. There is no inbox to click a link
// in, so the rig's account is activated here.
$user->email_verify_user(TRUE, TRUE);
$user->load();
echo "  activated: " . ($user->get('usr_is_activated') ? 'yes' : 'no') . "\n";

// A signed-in browser session is sent to /terms-accept until the person has
// accepted the terms, API calls included. The encrypted lane approves its
// devices as a browser session, and nobody is at that page to tick the box.
if (empty($user->get('usr_terms_accepted_time'))) {
	$user->set('usr_terms_accepted_time', gmdate('Y-m-d H:i:s'));
	$user->save();
	$user->load();
}
echo "  terms accepted: " . ($user->get('usr_terms_accepted_time') ? 'yes' : 'no') . "\n";

// ---------------------------------------------------------------------------
// The tier, and the group it hangs off
// ---------------------------------------------------------------------------

$q = $dblink->prepare("SELECT grp_group_id FROM grp_groups WHERE grp_name = ? AND grp_delete_time IS NULL");
$q->execute(array('Soak Rig'));
$group_id = (int)$q->fetchColumn();

if (!$group_id) {
	$group = new Group(NULL);
	$group->set('grp_name', 'Soak Rig');
	$group->set('grp_description', 'Storage limits for the Drive sync soak rig. Not a customer-facing plan.');
	$group->set('grp_category', 'subscription_tier');
	$group->save();
	$group->load();
	$group_id = (int)$group->key;
	echo "created group $group_id\n";
} else {
	echo "group already exists ($group_id)\n";
}

// 4 GB of storage and 64 MB per file: comfortably above what the personas write
// (the largest is photoshop at ~2 MB) and well inside the box's 14 GB free.
//
// Versioning depth has to be effectively unlimited, not merely generous. The
// no-loss oracle holds the server to a promise: once it has taken a content it
// still has it. A finite depth makes that promise false by design — the server
// prunes, correctly, and the oracle reports the pruning as the server losing
// data. A six-cycle campaign at depth 50 raised 19 such alarms, every one of
// them the messy-human persona overwriting the same file more than fifty times.
$features = array(
	'drive_storage_bytes'    => 4 * 1024 * 1024 * 1024,
	'drive_max_file_bytes'   => 64 * 1024 * 1024,
	'drive_versioning_depth' => 100000,
	'drive_share_links'      => true,
);

$q = $dblink->prepare("SELECT sbt_subscription_tier_id FROM sbt_subscription_tiers WHERE sbt_grp_group_id = ? AND sbt_delete_time IS NULL");
$q->execute(array($group_id));
$tier_id = (int)$q->fetchColumn();

if ($tier_id) {
	$tier = new SubscriptionTier($tier_id, TRUE);
	echo "tier already exists ($tier_id)\n";
} else {
	$tier = new SubscriptionTier(NULL);
	$tier->set('sbt_grp_group_id', $group_id);
	echo "creating tier\n";
}
$tier->set('sbt_name', 'Soak Rig');
$tier->set('sbt_display_name', 'Soak Rig');
$tier->set('sbt_tier_level', 1);
$tier->set('sbt_description', 'Storage limits for the Drive sync soak rig.');
$tier->set('sbt_features', json_encode($features));
$tier->save();
$tier->load();
echo "tier " . (int)$tier->key . " features: " . $tier->get('sbt_features') . "\n";

// ---------------------------------------------------------------------------
// Membership
// ---------------------------------------------------------------------------

$q = $dblink->prepare("SELECT grm_group_member_id FROM grm_group_members WHERE grm_grp_group_id = ? AND grm_foreign_key_id = ?");
$q->execute(array($group_id, $user_id));
if ($q->fetchColumn()) {
	echo "already a member\n";
} else {
	$ins = $dblink->prepare("INSERT INTO grm_group_members (grm_grp_group_id, grm_foreign_key_id) VALUES (?, ?)");
	$ins->execute(array($group_id, $user_id));
	echo "joined the tier group\n";
}

// ---------------------------------------------------------------------------
// The Drive vault, for the encrypted lane
// ---------------------------------------------------------------------------

// Only the public half: the rig derives the keypair from the account's own
// password (jd-soak vault-key) and the secret half never leaves it. A Drive
// vault and nothing above it, the shape the vault suites use: a real account's
// opens through a root, and a root needs a passkey. With no passkey and no TOTP
// the account holds no second factor, so approving a device asks for no
// step-up, which is what lets the rig approve its own devices.
$vault_public_key = trim((string)getenv('SOAK_DRIVE_VAULT_PUBLIC_KEY'));
if ($vault_public_key !== '') {
	$q = $dblink->prepare("SELECT uev_user_encryption_vault_id FROM uev_user_encryption_vaults WHERE uev_usr_user_id = ? AND uev_scope = 'drive'");
	$q->execute(array($user_id));
	$vault_id = (int)$q->fetchColumn();
	if ($vault_id) {
		$vault = new UserEncryptionVault($vault_id, TRUE);
		if ((string)$vault->get('uev_public_key') !== $vault_public_key) {
			fwrite(STDERR, "FAIL: the account already has a Drive vault under another key\n");
			exit(1);
		}
		echo "Drive vault already exists ($vault_id)\n";
	} else {
		$vault = new UserEncryptionVault(NULL);
		$vault->set('uev_usr_user_id', $user_id);
		$vault->set('uev_scope', 'drive');
		$vault->set('uev_custody', 'client');
		$vault->set('uev_public_key', $vault_public_key);
		$vault->set('uev_salt', base64_encode(random_bytes(16)));
		$vault->set('uev_key_generation', 1);
		$vault->save();
		echo "created Drive vault (" . (int)$vault->key . ")\n";
	}
}

// ---------------------------------------------------------------------------
// Prove it resolves the way the upload path will read it
// ---------------------------------------------------------------------------

SubscriptionTier::clearUserCache($user_id);
foreach (array('drive_storage_bytes', 'drive_max_file_bytes', 'drive_versioning_depth') as $key) {
	$value = SubscriptionTier::getUserFeature($user_id, $key, 0);
	echo "  $key = $value\n";
	if ((int)$value <= 0) {
		fwrite(STDERR, "FAIL: $key still resolves to 0 — Drive uploads would be refused\n");
		exit(1);
	}
}
echo "OK\n";
