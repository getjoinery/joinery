<?php
/**
 * _write_site_config.php - Fill default_Globalvars_site.php for one site.
 *
 * VERSION: 1.0
 *
 * Usage (internal, called by _site_init.sh):
 *
 *   JOINERY_CFG_SITENAME=NAME JOINERY_CFG_DOMAIN=DOMAIN JOINERY_CFG_DEPLOY_ENV=docker|baremetal \
 *   JOINERY_CFG_PASSWORD=... JOINERY_CFG_SECRET_BOX_KEY=... \
 *       php _write_site_config.php TEMPLATE OUTPUT
 *
 * Every value arrives in the environment. argv is readable by every account
 * on the machine through ps for as long as the process runs, and this file
 * writes the database password and the secret-at-rest key.
 *
 * The two secrets are emitted with var_export(), which produces a correct PHP
 * string literal for any string at all — quotes, backslashes, dollar signs,
 * spaces, line breaks. That is the whole reason this is PHP and not sed: a
 * sed replacement needs its own escaping for / & \ and newlines, and what it
 * produces still has to parse as PHP, so a password had to avoid the union of
 * both alphabets' special characters. A PHP writer has one alphabet, its own.
 *
 * The site name, domain and deployment environment are plain substitutions:
 * _site_init.sh derives or validates them, and none is a free-form value.
 *
 * Exit 0 on success, 1 with a reason on stderr otherwise. Never overwrites an
 * existing output file — that guard belongs to the caller, and this refuses
 * as a second line of defence, since the output carries this deployment's
 * secret_box_key and rewriting it would orphan every secret encrypted at rest.
 *
 * Validate with `php -l` only.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$template = $argv[1] ?? '';
$output   = $argv[2] ?? '';

if ($template === '' || $output === '') {
    fwrite(STDERR, "usage: php _write_site_config.php TEMPLATE OUTPUT (values in JOINERY_CFG_* environment variables)\n");
    exit(1);
}

if (file_exists($output)) {
    fwrite(STDERR, "refusing to overwrite {$output}: it carries this site's secret_box_key\n");
    exit(1);
}

$env = static function (string $name): string {
    $value = getenv($name);
    return $value === false ? '' : $value;
};

$sitename   = $env('JOINERY_CFG_SITENAME');
$domain     = $env('JOINERY_CFG_DOMAIN');
$deploy_env = $env('JOINERY_CFG_DEPLOY_ENV');
$password   = $env('JOINERY_CFG_PASSWORD');
$secret_key = $env('JOINERY_CFG_SECRET_BOX_KEY');

foreach (['JOINERY_CFG_SITENAME' => $sitename, 'JOINERY_CFG_DOMAIN' => $domain,
          'JOINERY_CFG_DEPLOY_ENV' => $deploy_env, 'JOINERY_CFG_PASSWORD' => $password,
          'JOINERY_CFG_SECRET_BOX_KEY' => $secret_key] as $name => $value) {
    if ($value === '') {
        fwrite(STDERR, "{$name} is empty\n");
        exit(1);
    }
}

// The plain substitutions are the ones a PHP literal cannot be hurt by: the
// site name is [a-z0-9] by construction, the environment is one of two words,
// and a domain that could break a single-quoted literal would never have
// resolved. Still, refuse rather than write a config that will not parse.
foreach (['site name' => $sitename, 'domain' => $domain, 'deployment environment' => $deploy_env] as $label => $value) {
    if (preg_match("/['\\\\\\r\\n]/", $value)) {
        fwrite(STDERR, "the {$label} contains a quote, backslash or line break\n");
        exit(1);
    }
}

$src = file_get_contents($template);
if ($src === false) {
    fwrite(STDERR, "cannot read template {$template}\n");
    exit(1);
}

$password_line = "\$this->settings['dbpassword'] = '';";
if (strpos($src, $password_line) === false) {
    fwrite(STDERR, "template has no dbpassword line to fill\n");
    exit(1);
}
if (strpos($src, "'{{SECRET_BOX_KEY}}'") === false) {
    fwrite(STDERR, "template has no secret_box_key placeholder to fill\n");
    exit(1);
}

$out = str_replace(
    ['{{SITE_NAME}}', '{{DOMAIN_NAME}}', '{{DEPLOYMENT_ENVIRONMENT}}', "'{{SECRET_BOX_KEY}}'", $password_line],
    [$sitename, $domain, $deploy_env, var_export($secret_key, true),
     "\$this->settings['dbpassword'] = " . var_export($password, true) . ';'],
    $src
);

if (file_put_contents($output, $out, LOCK_EX) === false) {
    fwrite(STDERR, "cannot write {$output}\n");
    exit(1);
}

exit(0);
