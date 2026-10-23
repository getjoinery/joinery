<?php
/**
 * _set_site_config_password.php - Put this run's database password into a site
 * config that already exists, changing nothing else in it.
 *
 * VERSION: 1.0
 *
 * Usage (internal, called by _site_init.sh):
 *
 *   JOINERY_CFG_PASSWORD=... php _set_site_config_password.php CONFIG
 *
 * _site_init.sh never rewrites an existing Globalvars_site.php, because it
 * carries the site's secret_box_key. But the database password in it can be
 * stale: a first run that stopped part way wrote the file with the password it
 * was given, and a second run with the right password then built the database,
 * the admin login and the PHP dependencies against a config the site could not
 * connect with. The caller has already proved this run's password reaches the
 * database; this writes it over the dbpassword literal and leaves every other
 * byte, the secret_box_key included, as it was.
 *
 * Only a config whose dbusername is postgres is touched: this run's password
 * is the postgres account's, and any other user's password is not ours to set.
 *
 * Prints "same" (nothing to do), "updated", or "skipped: REASON"; exit 1 with
 * a reason on stderr when the file cannot be read or written.
 *
 * The password arrives in the environment, never argv (readable through ps).
 * Validate with `php -l` only.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$config = $argv[1] ?? '';
$password = getenv('JOINERY_CFG_PASSWORD');
if ($config === '' || $password === false || $password === '') {
    fwrite(STDERR, "usage: JOINERY_CFG_PASSWORD=... php _set_site_config_password.php CONFIG\n");
    exit(1);
}

$src = file_get_contents($config);
if ($src === false) {
    fwrite(STDERR, "cannot read {$config}\n");
    exit(1);
}

/** The string literal assigned to $this->settings[NAME], as tokens see it: [offset, length, value] or null. */
$literal = static function (string $src, string $name): ?array {
    $tokens = token_get_all($src);
    $offset = 0;
    $state = 0; // 0 looking for $this, 1 saw settings, 2 saw the key, 3 saw '='
    foreach ($tokens as $t) {
        $text = is_array($t) ? $t[1] : $t;
        $id = is_array($t) ? $t[0] : null;
        if ($id === T_WHITESPACE || $id === T_COMMENT) {
            $offset += strlen($text);
            continue;
        }
        if ($id === T_STRING && $text === 'settings') {
            $state = 1;
        } elseif ($state === 1 && $id === T_CONSTANT_ENCAPSED_STRING) {
            $state = (stripcslashes(substr($text, 1, -1)) === $name) ? 2 : 0;
        } elseif ($state === 2 && $text === '=') {
            $state = 3;
        } elseif ($state === 3 && $id === T_CONSTANT_ENCAPSED_STRING) {
            // Single-quoted literals only, which is what the writer emits.
            if ($text[0] !== "'") {
                return null;
            }
            $value = str_replace(array("\\\\", "\\'"), array("\\", "'"), substr($text, 1, -1));
            return array($offset, strlen($text), $value);
        } elseif ($state > 0 && !in_array($text, array(']', '['), true)) {
            $state = ($id === T_STRING && $text === 'settings') ? 1 : 0;
        }
        $offset += strlen($text);
    }
    return null;
};

$user = $literal($src, 'dbusername');
if ($user === null || $user[2] !== 'postgres') {
    echo "skipped: the database user is not postgres\n";
    exit(0);
}
$current = $literal($src, 'dbpassword');
if ($current === null) {
    echo "skipped: no dbpassword line\n";
    exit(0);
}
if ($current[2] === $password) {
    echo "same\n";
    exit(0);
}

$out = substr($src, 0, $current[0]) . var_export($password, true) . substr($src, $current[0] + $current[1]);
$tmp = $config . '.tmp' . getmypid();
$old = umask(027);
$ok = file_put_contents($tmp, $out, LOCK_EX) !== false;
umask($old);
if (!$ok) {
    @unlink($tmp);
    fwrite(STDERR, "cannot write {$config}\n");
    exit(1);
}
$stat = stat($config);
@chmod($tmp, $stat['mode'] & 0777);
@chown($tmp, $stat['uid']);
@chgrp($tmp, $stat['gid']);
if (!rename($tmp, $config)) {
    @unlink($tmp);
    fwrite(STDERR, "cannot replace {$config}\n");
    exit(1);
}
echo "updated\n";
exit(0);
