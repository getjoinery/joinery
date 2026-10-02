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
