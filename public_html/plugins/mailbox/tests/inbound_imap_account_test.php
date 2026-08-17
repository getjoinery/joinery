<?php
/** @joinery-test
 * name: inbound_imap_account
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Tests for InboundImapAccount: CRUD, encrypted-secret round-trips, the preset
 * catalog mapping, the UID cursor, and the enabled/due/provider_key filters.
 *
 * Run: php plugins/mailbox/tests/inbound_imap_account_test.php
 * (requires schema synced — iia_inbound_imap_accounts table + iea aliases).
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/SecretBox.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Token.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));

class InboundImapAccountTest {
	private $db;
	private $suffix;
	private $domain_id;
	private $alias_id;
	private $account_ids = array();

	function __construct() { $this->db = DbConnector::get_instance()->get_db_link(); }

	private function out($m) { echo (php_sapi_name() === 'cli' ? '' : '<br>') . $m . "\n"; }
	private function ok($c, $l) { return check((bool)$c, $l); }

	function run() {
		try {
			$this->setUp();
			$this->testPresets();
			$this->testPasswordRoundTrip();
			$this->testOAuthTokenRoundTrip();
			$this->testFilters();
			$this->testCursor();
		} catch (\Throwable $e) {
			check(false, 'uncaught exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		} finally {
			$this->tearDown();
		}
	}

	private function setUp() {
		$this->preClean();
		$this->suffix = substr(md5(uniqid('iia', true)), 0, 8);

		$domain = new InboundEmailDomain(NULL);
		$domain->set('ied_domain', 'imap-test-' . $this->suffix . '.example');
		$domain->set('ied_is_enabled', true);
		$domain->save();
		$this->domain_id = intval($domain->key);

		$a = new InboundEmailAlias(NULL);
		$a->set('iea_ied_inbound_email_domain_id', $this->domain_id);
		$a->set('iea_alias', 'box' . $this->suffix);
		$a->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
		$a->set('iea_is_enabled', true);
		$a->prepare(); $a->save();
		$this->alias_id = intval($a->key);

		$this->out('  fixtures ready (suffix ' . $this->suffix . ')');
	}

	private function preClean() {
		try {
			$dids = $this->db->query("SELECT ied_inbound_email_domain_id FROM ied_inbound_email_domains
				WHERE ied_domain LIKE 'imap-test-%'")->fetchAll(PDO::FETCH_COLUMN);
			if ($dids) {
				$in = implode(',', array_map('intval', $dids));
				$aids = $this->db->query("SELECT iea_inbound_email_alias_id FROM iea_inbound_email_aliases
					WHERE iea_ied_inbound_email_domain_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
				if ($aids) {
					$ain = implode(',', array_map('intval', $aids));
					$this->db->exec("DELETE FROM iia_inbound_imap_accounts WHERE iia_iea_inbound_email_alias_id IN ($ain)");
				}
				$this->db->exec("DELETE FROM iea_inbound_email_aliases WHERE iea_ied_inbound_email_domain_id IN ($in)");
				$this->db->exec("DELETE FROM ied_inbound_email_domains WHERE ied_inbound_email_domain_id IN ($in)");
			}
		} catch (\Throwable $e) {}
	}

	private function makeAccount($provider, $enabled = true): InboundImapAccount {
		$acct = new InboundImapAccount(NULL);
		$acct->set('iia_label', 'Test ' . $provider);
		$acct->set('iia_provider_key', $provider);
		$acct->set('iia_iea_inbound_email_alias_id', $this->alias_id);
		$acct->set('iia_username', 'user@example.test');
		$acct->set('iia_is_enabled', $enabled);
		// generic needs a host
		if ($provider === 'imap_generic') {
			$acct->set('iia_imap_host', 'imap.example.test');
		}
		$acct->prepare();
		$acct->save();
		$this->account_ids[] = intval($acct->key);
		return $acct;
	}

	private function testPresets() {
		section('Presets');
		$gmail = $this->makeAccount('imap_gmail');
		$this->ok($gmail->get('iia_auth_method') === 'password', 'Gmail defaults to the app password');
		$this->ok(!$gmail->isOAuth(), 'Gmail isOAuth() false until an OAuth sign-in happens');
		$this->ok($gmail->getOAuthProviderKey() === 'google', 'Gmail oauth provider key = google');
		$this->ok(InboundImapAccount::authMethodsFor('imap_gmail') === array('password', 'oauth2'),
			'Gmail supports app password first, OAuth besides');
		$this->ok(InboundImapAccount::authMethodsFor('imap_microsoft') === array('oauth2'),
			'Microsoft is OAuth only');
		$this->ok(InboundImapAccount::authMethodsFor('imap_yahoo') === array('password'),
			'Yahoo is password only');

		// The credential defines the method, and prepare() keeps a supported
		// stored method instead of resetting it to the preset default.
		$gmail->setOAuthToken(new OAuth2Token('A-' . $this->suffix, 'R-' . $this->suffix,
			gmdate('Y-m-d H:i:s', time() + 3600)));
		$this->ok($gmail->get('iia_auth_method') === 'oauth2', 'setOAuthToken stamps oauth2');
		$gmail->prepare();
		$this->ok($gmail->get('iia_auth_method') === 'oauth2', 'prepare() keeps the stored method');

		$ms = $this->makeAccount('imap_microsoft');
		$this->ok($ms->getOAuthProviderKey() === 'microsoft', 'Microsoft oauth provider key = microsoft');
		$this->ok($ms->get('iia_auth_method') === 'oauth2', 'Microsoft defaults to oauth2');

		$yahoo = $this->makeAccount('imap_yahoo');
		$this->ok($yahoo->get('iia_auth_method') === 'password', 'Yahoo preset → password auth');
		$this->ok(!$yahoo->isOAuth(), 'Yahoo isOAuth() false');

		// Preset host/port carried via preset catalog (editor uses these).
		$preset = $yahoo->getPreset();
		$this->ok($preset['host'] === 'imap.mail.yahoo.com' && intval($preset['port']) === 993,
			'Yahoo preset host/port correct');

		$bad = new InboundImapAccount(NULL);
		$bad->set('iia_provider_key', 'imap_nonexistent');
		$threw = false;
		try { $bad->prepare(); } catch (InboundImapAccountException $e) { $threw = true; }
		$this->ok($threw, 'Unknown provider rejected by prepare()');
	}

	private function testPasswordRoundTrip() {
		section('Password round-trip');
		$acct = $this->makeAccount('imap_fastmail');
		$secret = 'app-pw-' . $this->suffix . '-SECRET';
		$acct->setPassword($secret);
		$acct->save();

		// Reload from DB and decrypt.
		$reloaded = new InboundImapAccount($acct->key, TRUE);
		$this->ok($reloaded->getPassword() === $secret, 'Password round-trips through encrypt/decrypt');
		$this->ok($reloaded->hasPassword(), 'hasPassword() true after set');

		// The stored column is ciphertext, never plaintext.
		$raw = $this->db->query("SELECT iia_password_enc FROM iia_inbound_imap_accounts
			WHERE iia_inbound_imap_account_id = " . intval($acct->key))->fetchColumn();
		$this->ok(strpos((string)$raw, $secret) === false, 'Stored password column is not plaintext');
		$this->ok(SecretBox::looksEncrypted((string)$raw), 'Stored password column looks encrypted');

		// Clearing.
		$reloaded->setPassword('');
		$this->ok(!$reloaded->hasPassword(), 'setPassword("") clears the column');
	}

	private function testOAuthTokenRoundTrip() {
		section('OAuth token round-trip');
		$acct = $this->makeAccount('imap_gmail');
		$expires = gmdate('Y-m-d H:i:s', time() + 3600);
		$token = new OAuth2Token('ACCESS-' . $this->suffix, 'REFRESH-' . $this->suffix, $expires);
		$acct->setOAuthToken($token);
		$acct->save();

		$reloaded = new InboundImapAccount($acct->key, TRUE);
		$this->ok($reloaded->hasOAuthToken(), 'hasOAuthToken() true after set');
		$got = $reloaded->getOAuthToken();
		$this->ok($got !== null && $got->getAccessToken() === 'ACCESS-' . $this->suffix, 'Access token round-trips');
		$this->ok($got->getRefreshToken() === 'REFRESH-' . $this->suffix, 'Refresh token round-trips');
		$this->ok($got->getExpiresAt() === $expires, 'Expiry round-trips');

		$rawA = $this->db->query("SELECT iia_oauth_access_token_enc FROM iia_inbound_imap_accounts
			WHERE iia_inbound_imap_account_id = " . intval($acct->key))->fetchColumn();
		$this->ok(strpos((string)$rawA, 'ACCESS-' . $this->suffix) === false, 'Stored access token is not plaintext');
		$this->ok($reloaded->isConnectable(), 'OAuth account with token isConnectable()');
	}

	private function testFilters() {
		section('Filters');
		// Disabled account should be excluded by the enabled filter.
		$this->makeAccount('imap_generic', false);

		$enabled = new MultiInboundImapAccount(array('enabled' => true, 'alias_id' => $this->alias_id, 'deleted' => false));
		$this->ok($enabled->count_all() >= 1, 'enabled filter returns enabled accounts');

		$disabled = new MultiInboundImapAccount(array('enabled' => false, 'alias_id' => $this->alias_id, 'deleted' => false));
		$this->ok($disabled->count_all() >= 1, 'enabled=false filter returns disabled accounts');

		$gmail = new MultiInboundImapAccount(array('provider_key' => 'imap_gmail', 'alias_id' => $this->alias_id, 'deleted' => false));
		$this->ok($gmail->count_all() >= 1, 'provider_key filter works');

		// "due": a never-polled enabled account is always due.
		$due = new MultiInboundImapAccount(array('enabled' => true, 'due' => true, 'alias_id' => $this->alias_id, 'deleted' => false));
		$this->ok($due->count_all() >= 1, 'due filter includes never-polled accounts');
	}

	private function testCursor() {
		section('Cursor');
		$acct = $this->makeAccount('imap_generic');
		$acct->set('iia_uidvalidity', 123456);
		$acct->set('iia_last_seen_uid', 42);
		$acct->save();

		$reloaded = new InboundImapAccount($acct->key, TRUE);
		$this->ok(intval($reloaded->get('iia_uidvalidity')) === 123456, 'uidvalidity persists');
		$this->ok(intval($reloaded->get('iia_last_seen_uid')) === 42, 'last_seen_uid persists');

		// recordStatus stamps poll time + truncates status.
		$reloaded->recordStatus(str_repeat('x', 600));
		$after = new InboundImapAccount($acct->key, TRUE);
		$this->ok(strlen((string)$after->get('iia_last_status')) <= 500, 'last_status truncated to 500');
		$this->ok($after->get('iia_last_poll_time') !== null, 'recordStatus stamps last_poll_time');
	}

	private function tearDown() {
		try {
			if ($this->account_ids) {
				$in = implode(',', array_map('intval', $this->account_ids));
				$this->db->exec("DELETE FROM iia_inbound_imap_accounts WHERE iia_inbound_imap_account_id IN ($in)");
			}
			if ($this->alias_id) {
				$this->db->exec("DELETE FROM iea_inbound_email_aliases WHERE iea_inbound_email_alias_id = " . intval($this->alias_id));
			}
			if ($this->domain_id) {
				$this->db->exec("DELETE FROM ied_inbound_email_domains WHERE ied_inbound_email_domain_id = " . intval($this->domain_id));
			}
		} catch (\Throwable $e) {}
	}
}

$session = SessionControl::get_instance();
if (method_exists($session, 'set_test_permission')) { $session->set_test_permission(10); }
$test = new InboundImapAccountTest();
$test->run();
harness_finish();
