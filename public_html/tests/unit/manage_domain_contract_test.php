<?php
/** @joinery-test
 * name: manage_domain_contract
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 * covers: [maintenance_scripts/sysadmin_tools/manage_domain.sh, maintenance_scripts/sysadmin_tools/reconcile_site.sh, maintenance_scripts/sysadmin_tools/virtualhost_update_script.sh, maintenance_scripts/install_tools/_vhost_install.sh, maintenance_scripts/install_tools/_vhost_render.sh, maintenance_scripts/install_tools/default_proxy_vhost.conf, maintenance_scripts/install_tools/default_virtualhost.conf]
 */
/**
 * A domain set after the install is set the way the install sets one (B7).
 *
 * manage_domain.sh once wrote its own proxy vhost from a heredoc, beside the
 * template's, ran certbot's Apache installer over it and skipped the
 * certificate for a name behind Cloudflare; the restore's bare-metal step and
 * virtualhost_update_script.sh wrote renders the converger never recorded, so
 * every later converge read the new domain as an operator's edit and stopped
 * keeping the file current. Every path that changes a site's domain now goes
 * through _vhost_install.sh (the converge's render, recorded) and the
 * certificate through setup_ssl.sh and the retry timer. These pins hold that.
 *
 * Run: php tests/unit/manage_domain_contract_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$scripts = dirname(__DIR__, 3) . '/maintenance_scripts';
$md        = (string)@file_get_contents($scripts . '/sysadmin_tools/manage_domain.sh');
$reconcile = (string)@file_get_contents($scripts . '/sysadmin_tools/reconcile_site.sh');
$vus       = (string)@file_get_contents($scripts . '/sysadmin_tools/virtualhost_update_script.sh');
$install   = (string)@file_get_contents($scripts . '/install_tools/_vhost_install.sh');
$render    = (string)@file_get_contents($scripts . '/install_tools/render_vhost.sh');

section('The scripts parse');
foreach (array('sysadmin_tools/manage_domain.sh', 'sysadmin_tools/reconcile_site.sh',
		'sysadmin_tools/virtualhost_update_script.sh', 'install_tools/_vhost_install.sh') as $rel) {
	$out = array();
	exec('bash -n ' . escapeshellarg($scripts . '/' . $rel) . ' 2>&1', $out, $rc);
	check($rc === 0, "$rel parses", implode(' ', $out));
}

section('manage_domain.sh sets a domain as the install does');
check(strpos($md, '. "$TOOLS_DIR/_vhost_install.sh"') !== false, 'it renders through _vhost_install.sh');
check(strpos($md, '<VirtualHost') === false, 'it carries no vhost of its own');
check(strpos($md, 'certbot --apache') === false && strpos($md, 'certbot ') === false,
	'it never runs certbot itself: setup_ssl.sh issues, and never edits a vhost');
check(strpos($md, 'SSL handled by Cloudflare') === false && strpos($md, 'is_cloudflare_ip') === false,
	'a name behind Cloudflare is not skipped');
check(strpos($md, '"${SCRIPT_DIR}/setup_ssl.sh" "$domain"') !== false, 'the certificate comes from setup_ssl.sh');
check(strpos($md, '--setup-ssl "$(setup_ssl_candidates') !== false && strpos($md, '"$old_domain" --disarm') !== false,
	'with none issued the retry timer waits for the new name, and the old name\'s timer stops');
check(strpos($md, 'default_proxy_vhost.conf') !== false && strpos($md, 'default_virtualhost.conf') !== false,
	'both modes use the installer\'s templates');
check(strpos($md, 'reconcile_site.sh') !== false && strpos($md, '--skip-web-config --skip-ssl') !== false,
	'the site\'s own webDir follows, through reconcile_site.sh');
check(strpos($md, 'render_vhost.sh') !== false && strpos($md, 'assert_baremetal_vhost_is_ours') !== false,
	'a bare-metal vhost carrying an operator\'s edits is refused, not overwritten');
check(strpos($md, 'retire_legacy_proxy') !== false && strpos($md, '-proxy.conf" "${sitename}-proxy-le-ssl.conf"') !== false,
	'an earlier version\'s <site>-proxy.conf is taken out of Apache and kept');
check(strpos($md, "hostname -I") !== false && strpos($md, 'install_site_vhost "$sitename" "$kind" "$address"') !== false
	&& strpos($md, 'settle_site_identity "$sitename" "$kind" localhost') === false,
	'clear leaves the site at the machine\'s address, as an install with no domain does, never localhost by default');
check(strpos($md, 'assert_container_vhost_is_ours') !== false,
	'a container\'s proxy vhost edited since this script wrote it is refused, not overwritten');
check(strpos($md, 'host_files_heal_renewal_confs') !== false,
	'retiring the earlier version\'s certbot vhost heals the lineage\'s renewal config');
check(strpos($md, 'remove-ssl)') === false, 'there is no remove-ssl: a certificate belongs to its vhost\'s name');
check(strpos($md, 'site_engine ps') !== false && strpos($md, 'site_engine port') !== false && strpos($md, 'site_engine exec') !== false,
	'a container is asked through the site engine helper');

section('One render, recorded, on every path that changes a domain');
check(preg_match('/^vhost_install\(\) \{/m', $install) === 1, '_vhost_install.sh defines vhost_install');
check(strpos($install, '. "${BASH_SOURCE[0]%/*}/_vhost_render.sh"') !== false, 'it renders with _vhost_render.sh, the converge\'s render');
check(strpos($install, '/etc/apache2/sites-available/.${sitename}.conf.rendered') !== false
	&& strpos($render, 'STATE="/etc/apache2/sites-available/.${SITENAME}.conf.rendered"') !== false,
	'it records the render where render_vhost.sh reads its last one');
check(strpos($install, 'apache2ctl -t') !== false && strpos($install, 'cp -p "${previous}" "${conf}"') !== false,
	'a render Apache cannot parse is refused and the previous file put back');
check(strpos($install, 'names no ServerName ${domain}; nothing was changed') !== false,
	'a render that does not name the domain (empty, cut short) is refused before it touches the vhost');
check(strpos($install, 'mint_placeholder_cert') !== false, 'the placeholder certificate is minted for the domain');
check(strpos($reconcile, 'vhost_install') !== false && strpos($reconcile, 'virtualhost_update_script.sh"') === false,
	'a bare-metal restore renders through vhost_install');
check(strpos($vus, 'vhost_install') !== false && strpos($vus, 'service apache2 restart') === false,
	'virtualhost_update_script.sh renders through vhost_install and reloads, never restarts');

section('The render itself');
$tmp = harness_scratch_dir('manage_domain_contract');
$probe = $tmp . '/probe.sh';
file_put_contents($probe, '. ' . escapeshellarg($scripts . '/install_tools/_vhost_install.sh') . "\n"
	. 'PORT=8123 vhost_render ' . escapeshellarg($scripts . '/install_tools/default_proxy_vhost.conf')
	. ' /var/www/html/mdsite md2.example.org' . "\n");
$proxy = (string)shell_exec('bash ' . escapeshellarg($probe) . ' 2>&1');
check(strpos($proxy, 'ServerName md2.example.org') !== false, 'the proxy render names the domain');
check(preg_match('#ProxyPass\s+/\s+http://127\.0\.0\.1:8123/#', $proxy) === 1, 'and forwards to the container\'s port');
check(strpos($proxy, 'RequestHeader append X-Forwarded-For') !== false, 'and appends to X-Forwarded-For');
check(strpos($proxy, '{{') === false, 'with no placeholder left unfilled');

harness_finish();
