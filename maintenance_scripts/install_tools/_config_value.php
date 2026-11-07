<?php
/**
 * _config_value.php - Print one string literal from a site config file, read
 * the way PHP reads it.
 *
 * VERSION: 1.0
 *
 * Usage: php _config_value.php CONFIG NAME       (prints the value, or nothing)
 *
 * A config literal is written by var_export(), so a password with a quote or a
 * backslash is stored as \' or \\. A sed over the file reads those back wrong;
 * the tokenizer reads them right. Used by site_db_role.sh for the four
 * database literals and the postgres pair in db_admin.php. Prints nothing and
 * exits 0 when the file or the setting is absent, so a caller can test for an
 * empty answer.
 *
 * Validate with `php -l` only.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$config = $argv[1] ?? '';
$name = $argv[2] ?? '';
if ($config === '' || $name === '' || !is_readable($config)) {
    exit(0);
}
$src = file_get_contents($config);
if ($src === false) {
    exit(0);
}

$tokens = token_get_all($src);
$state = 0; // 0 looking for $this, 1 saw settings, 2 saw the key, 3 saw '='
foreach ($tokens as $t) {
    $text = is_array($t) ? $t[1] : $t;
    $id = is_array($t) ? $t[0] : null;
    if ($id === T_WHITESPACE || $id === T_COMMENT) {
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
        echo $value;
        exit(0);
    } elseif ($id !== T_VARIABLE && $text !== '[' && $text !== ']' && $text !== '->') {
        $state = 0;
    }
}
exit(0);
