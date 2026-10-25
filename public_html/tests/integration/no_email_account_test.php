<?php
/** @joinery-test
 * name: no_email_account
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */
/**
 * An account with no email (spec optional_username 3.4, 3.5): what reaches a
 * person by email is "nothing sent", never a crash or someone else's data.
 *
 *   - A send to a blank recipient returns false and logs; a malformed address
 *     still throws.
 *   - The Stripe customer lookup never searches for a blank email (the SDK
 *     drops a null filter, so the search would list every customer and the
 *     first, someone else's, would be saved to this account).
 *   - Mailing-list provider sync has nothing to do.
 *   - The authenticator label and passkey name fall back to the username.
 *   - Checkout asks a signed-in buyer with no email to add one.
 *
 * Run: php tests/run.php test-db --filter=no_email_account
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/logic.php');
harness_boot();
harness_test_mode();

$handle = 'nea' . bin2hex(random_bytes(4));
$user = User::CreateNew(array('usr_first_name' => 'NoEmail', 'usr_username' => $handle));
harness_register_user($user);
$user = new User($user->key, TRUE);
check($user->get('usr_email') === NULL, 'the fixture has no email');

section('Sends to a blank recipient');

check(EmailSender::sendTemplate('no_such_template_' . $handle, $user->get('usr_email'), array()) === false,
	'sendTemplate to no address returns false before it looks for the template');
check(EmailSender::sendTemplate('no_such_template_' . $handle, '   ', array()) === false, 'nor for spaces');
check(EmailSender::sendTemplate('no_such_template_' . $handle, array(), array()) === false, 'nor for an empty list');
check(EmailSender::quickSend(NULL, 'Subject', 'Body') === false, 'quickSend to no address returns false');
$threw = '';
try {
	EmailSender::quickSend('not-an-address', 'Subject', 'Body');
} catch (\Throwable $e) {
	$threw = $e->getMessage();
}
check(strpos($threw, 'Invalid recipient') !== false, 'a malformed address still throws', $threw);

section('Stripe never searches for a blank email');

if (class_exists('StripeHelper')) {
	$calls = new ArrayObject();
	$fake = new class($calls) {
		public $customers;
		public function __construct($calls) {
			$this->customers = new class($calls) {
				private $calls;
				public function __construct($calls) { $this->calls = $calls; }
				public function all($params) {
					$this->calls[] = $params;
					return array('data' => array(array('id' => 'cus_SOMEONE_ELSE')));
				}
			};
		}
	};
	$helper = new StripeHelper();
	$prop = new ReflectionProperty('StripeHelper', 'stripe');
	$prop->setAccessible(true);
	$prop->setValue($helper, $fake);

	$found = $helper->get_customer($user, 'id');
	check($found === false, 'get_customer finds nobody for an account with no email', var_export($found, true));
	check(count($calls) === 0, 'and never asks Stripe', json_encode($calls->getArrayCopy()));
	check(!$helper->read_customer_id($user), 'so no customer id is saved to the account');
} else {
	harness_skip('Stripe never searches for a blank email', 'the store plugin is not active');
}

section('Mailing-list provider sync');

$list = new MailingList(NULL);
check($list->sync_subscribe($user->key) === true && $list->sync_unsubscribe($user->key) === true,
	'subscribe and unsubscribe sync have nothing to do for an account with no email');

section('Names on the person\'s devices');

$security_src = file_get_contents(PathHelper::getIncludePath('logic/security_logic.php'));
check(strpos($security_src, "setLabel((string)(\$user->get('usr_email') ?: \$user->get('usr_username')))") !== false,
	'the authenticator label falls back to the username');
$name = (new ReflectionMethod('PasskeyService', '_userName'));
$name->setAccessible(true);
check($name->invoke(new PasskeyService(), $user) === $handle, 'the passkey name is the username', (string)$name->invoke(new PasskeyService(), $user));

section('Checkout asks a signed-in buyer with no email to add one');

if (class_exists('StripeHelper')) {
	harness_set_setting_mem('products_active', '1');
	$_SESSION = array();
	SessionControl::get_instance()->store_session_variables($user);
	$res = harness_call_logic('plugins/store/logic/checkout_logic.php', 'checkout_logic', array(), 'GET');
	check($res->redirect === '/profile/account_edit',
		'checkout sends them to their account page to add one', var_export($res->redirect, true));
	check(strpos(serialize($_SESSION), 'Add an email address to your account to check out') !== false,
		'with a plain-text message saying why');
	$_SESSION = array();
}

harness_finish();
