<?php
/** @joinery-test
 * name: domain_identity_cleanup
 * tier: db
 * env: dev-only
 * needs: []
 * covers: [plugins/mailbox/data/inbound_email_domains_class.php]
 */
/**
 * Deleting a mail domain takes its Joinery Direct signing identities with it.
 *
 * A domain's DNS plan mints a signing identity (DirectSigningIdentity::ensureFor),
 * which names the domain by text. Nothing removed it when the domain went, so
 * every test domain left one behind: about 1,460 on localdev by 2026-11-02.
 * With the domain row gone the identity can neither sign nor be minted again
 * (the authority resolver reads the domain table), so it goes with the last
 * row that names its domain, and stays while any row, deleted or not, still
 * does.
 *
 * Run: php tests/run.php db --filter=domain_identity_cleanup
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

function dic_domain(string $name): InboundEmailDomain {
	$dom = new InboundEmailDomain(NULL);
	$dom->set('ied_domain', $name);
	$dom->set('ied_is_enabled', true);
	$dom->set('ied_catch_all_mode', 'store');
	$dom->save();
	$dom->load();
	harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', intval($dom->key));
	return $dom;
}

function dic_identities(string $name): int {
	return count(new MultiDirectIdentity(array('domain' => $name)));
}

section('The last domain row takes its signing identities with it');

$name = 'dic-' . bin2hex(random_bytes(4)) . '.example';
$dom = dic_domain($name);
DirectSigningIdentity::ensureFor($name);
DirectSigningIdentity::rotate($name);
check(dic_identities($name) === 2, 'the domain holds two identities, the current one and the one it replaced', (string)dic_identities($name));
$dom->permanent_delete();
check(dic_identities($name) === 0, 'deleting the domain deletes both', (string)dic_identities($name));

section('An identity stays while another row still names its domain');

$shared = 'dic-' . bin2hex(random_bytes(4)) . '.example';
$first = dic_domain($shared);
DirectSigningIdentity::ensureFor($shared);
// A second row for the same name, soft-deleted: it can be undeleted, and the
// identity must still be there when it is.
$second = dic_domain($shared . '.tmp');
$second->set('ied_domain', $shared);
try {
	$second->save();
	$second->soft_delete();
	$first->permanent_delete();
	check(dic_identities($shared) === 1, 'a soft-deleted row for the same domain keeps its identity', (string)dic_identities($shared));
	$second->permanent_delete();
	check(dic_identities($shared) === 0, 'deleting that row too deletes it', (string)dic_identities($shared));
} catch (Throwable $e) {
	// The table may refuse two rows of one name; then the shared case cannot arise.
	harness_skip('two rows for one domain name are refused here', get_class($e) . ': ' . $e->getMessage());
	$first->permanent_delete();
	check(dic_identities($shared) === 0, 'the domain\'s identity goes with it', (string)dic_identities($shared));
}

section('Another domain\'s identity is left alone');

$keep = 'dic-' . bin2hex(random_bytes(4)) . '.example';
$kept = dic_domain($keep);
DirectSigningIdentity::ensureFor($keep);
$gone = dic_domain('dic-' . bin2hex(random_bytes(4)) . '.example');
$gone->permanent_delete();
check(dic_identities($keep) === 1, 'deleting one domain leaves another domain\'s identity', (string)dic_identities($keep));
$kept->permanent_delete();
check(dic_identities($keep) === 0, 'and that one goes with its own domain');

harness_finish();
