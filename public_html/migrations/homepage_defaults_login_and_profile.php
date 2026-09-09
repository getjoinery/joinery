<?php
/**
 * The site root sends a visitor to sign in and a member to /profile.
 *
 * Most installs exist for mail and members; the built-in welcome page is a
 * parking page that says the install worked, and nobody wants it as the
 * permanent front door. The factory defaults for alternate_homepage and
 * alternate_loggedin_homepage are /login and /profile, and a settings.json
 * default only seeds a row that does not exist yet, so rows still blank from
 * the old factory default move here — the same wholesale flip
 * spam_learning_on_by_default made, for the same pre-launch reason.
 *
 * Blank stays blank when the active theme ships its own views/index.php: that
 * theme's homepage is a real page, and the root must keep rendering it.
 * The signed-in row moves regardless, because the theme homepage is the
 * public one; a member arriving at the root of a mail install belongs in
 * /profile either way, and a site that wants members on the theme homepage
 * clears the setting on the settings page.
 *
 * The cached copy of / is dropped so the redirect is served at once.
 *
 * Idempotent: re-running finds no blank rows.
 */
function homepage_defaults_login_and_profile() {
    $db = DbConnector::get_instance()->get_db_link();

    $theme_has_homepage = false;
    try {
        $theme_dir = PathHelper::getActiveThemeDirectory();
        $theme_has_homepage = $theme_dir !== null
            && file_exists(PathHelper::getIncludePath($theme_dir) . '/views/index.php');
    } catch (Exception $e) {
        // No resolvable theme: the built-in welcome page is what renders,
        // which is exactly the page this migration retires.
    }

    $moves = array('alternate_loggedin_homepage' => '/profile');
    if ($theme_has_homepage) {
        echo "  alternate_homepage: left blank, the active theme ships its own homepage\n";
    } else {
        $moves['alternate_homepage'] = '/login';
    }

    $moved = 0;
    foreach ($moves as $name => $value) {
        $q = $db->prepare(
            "UPDATE stg_settings SET stg_value = :value, stg_update_time = now()
              WHERE stg_name = :name AND (stg_value IS NULL OR stg_value = '')");
        $q->execute(array(':value' => $value, ':name' => $name));
        if ($q->rowCount() > 0) {
            $moved++;
            echo "  $name set to $value (was at the old blank factory default)\n";
        } else {
            echo "  $name: already set\n";
        }
    }

    if ($moved > 0) {
        require_once(PathHelper::getIncludePath('includes/StaticPageCache.php'));
        StaticPageCache::invalidateUrl('/');
    }
}
?>
