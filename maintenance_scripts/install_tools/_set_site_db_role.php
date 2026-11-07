<?php
/**
 * _set_site_db_role.php - Point a site config at the site's own database role,
 * changing nothing else in it (mail_access_hardening WP4).
 *
 * VERSION: 1.1 - the temp file is born closed and takes the config's mode (reviewer2 B7)
 * VERSION: 1.0
 *
 * Usage (internal, called by site_db_role.sh):
 *
 *   JOINERY_CFG_DB_ROLE=joinery_site JOINERY_CFG_DB_PASSWORD=... php _set_site_db_role.php CONFIG
 *
 * Writes the four literals dbusername, dbpassword, dbusername_test and
 * dbpassword_test over their current values and leaves every other byte, the
 * secret_box_key included, as it was. The values arrive in the environment,
 * never argv (readable through ps), and are emitted with var_export(), which
 * produces a correct PHP string literal for any string at all.
 *
 * Prints "same" (nothing to do) or "updated"; exit 1 with a reason on stderr
 * when the file cannot be read or written or lacks one of the four settings.
 *
 * Validate with `php -l` only.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$config = $argv[1] ?? '';
$role = getenv('JOINERY_CFG_DB_ROLE');
$password = getenv('JOINERY_CFG_DB_PASSWORD');
if ($config === '' || $role === false || $role === '' || $password === false || $password === '') {
    fwrite(STDERR, "usage: JOINERY_CFG_DB_ROLE=... JOINERY_CFG_DB_PASSWORD=... php _set_site_db_role.php CONFIG\n");
    exit(1);
}
if (!preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role)) {
    fwrite(STDERR, "the role name must be lower-case letters, digits and underscores\n");
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
        } elseif ($state === 1 && $id === T_CONSTANT_ENCAPSED_STRING && trim($text, "'\"") === $name) {
            $state = 2;
        } elseif ($state === 2 && $text === '=') {
            $state = 3;
        } elseif ($state === 3 && $id === T_CONSTANT_ENCAPSED_STRING) {
            $value = substr($text, 1, -1);
            if ($text[0] === "'") {
                $value = str_replace(["\\'", '\\\\'], ["'", '\\'], $value);
            } else {
                $value = stripcslashes($value);
            }
            return [$offset, strlen($text), $value];
        } elseif ($id !== T_VARIABLE && $text !== '[' && $text !== ']' && $text !== '->') {
            $state = 0;
        }
        $offset += strlen($text);
    }
    return null;
};

$wanted = [
    'dbusername'      => $role,
    'dbpassword'      => $password,
    'dbusername_test' => $role,
    'dbpassword_test' => $password,
];

$changed = false;
foreach ($wanted as $name => $value) {
    $found = $literal($src, $name);
    if ($found === null) {
        fwrite(STDERR, "{$config} has no {$name} setting\n");
        exit(1);
    }
    if ($found[2] === $value) {
        continue;
    }
    $src = substr($src, 0, $found[0]) . var_export($value, true) . substr($src, $found[0] + $found[1]);
    $changed = true;
}

if (!$changed) {
    echo "same\n";
    exit(0);
}

// Write beside and rename, so a reader never sees a half-written config. The
// temp file is born closed and takes the config's own mode before the rename,
// so the file carrying both passwords is never world-readable for an instant;
// the caller puts the owner back.
$mode = @fileperms($config);
$old_umask = umask(077);
$tmp = $config . '.tmp.' . getmypid();
$written = file_put_contents($tmp, $src) !== false;
umask($old_umask);
if ($written && $mode !== false) {
    @chmod($tmp, $mode & 0777);
}
if (!$written || !rename($tmp, $config)) {
    @unlink($tmp);
    fwrite(STDERR, "cannot write {$config}\n");
    exit(1);
}
echo "updated\n";
