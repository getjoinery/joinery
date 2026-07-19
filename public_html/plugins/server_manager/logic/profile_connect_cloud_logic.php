<?php
/**
 * Logic for the Connect page (/profile/server_manager/connect_cloud).
 *
 * Where a customer-cloud hosting buyer links their cloud provider account so
 * the pipeline can create their server. Requires only a signed-in session —
 * the page shows the signed-in user's own provisions and account link, nothing
 * else. Doubles as the re-connect page when a grant is revoked or refresh
 * fails (the provision parks at pending_connect and a fresh grant resumes it).
 *
 * action=connect starts the platform OAuth2 consent flow (purpose
 * customer_cloud, scope linodes:read_write — the minimum that can create and
 * manage instances; no account/billing access is requested).
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function profile_connect_cloud_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_account_class.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provision_class.php'));
	require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Client.php'));
	require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ProviderRegistry.php'));

	$self_url = '/profile/server_manager/connect_cloud';

	$session = SessionControl::get_instance();
	$user_id = $session->get_user_id();
	if (!$user_id) {
		return LogicResult::redirect('/login?return=' . urlencode($self_url));
	}
	$settings = Globalvars::get_instance();

	$provider_class = OAuth2ProviderRegistry::get('linode');
	$provider_configured = $provider_class !== null && $provider_class::isConfigured();

	if (($input['action'] ?? '') === 'connect') {
		if (!$provider_configured) {
			return LogicResult::redirect($self_url . '?error=unconfigured');
		}
		try {
			$client = new OAuth2Client();
			$consent_url = $client->beginConsent(
				'linode',
				array('linodes:read_write'),
				'customer_cloud',
				array('user_id' => intval($user_id), 'provider' => 'linode'),
				$self_url
			);
			return LogicResult::redirect($consent_url);
		} catch (Throwable $e) {
			error_log('Connect cloud: beginConsent failed for user ' . intval($user_id)
				. ' (' . get_class($e) . ': ' . $e->getMessage() . ') at '
				. $e->getFile() . ':' . $e->getLine());
			return LogicResult::redirect($self_url . '?error=start_failed');
		}
	}

	$account = CustomerCloudAccount::get_for_user($user_id, 'linode');

	$provisions = new MultiCustomerCloudProvision(
		array('user_id' => $user_id, 'deleted' => false),
		array('cvp_id' => 'DESC')
	);
	$provisions->load();

	// Any provision mid-pipeline? Drives the connected message and the
	// page's auto-refresh.
	$in_progress = false;
	foreach ($provisions as $provision) {
		if (in_array($provision->get('cvp_status'), array('ready', 'booting', 'installing'), true)) {
			$in_progress = true;
			break;
		}
	}

	$message = '';
	if (!empty($input['connected'])) {
		$message = $in_progress
			? 'Account connected. Your server will be created within about 15 minutes — you will get an email when your site is ready.'
			: 'Your Linode account is connected.';
	} elseif (($input['oauth'] ?? '') === 'cancelled') {
		$message = 'The connection was cancelled. Your site cannot be set up until you connect your account.';
	} elseif (($input['error'] ?? '') === 'unconfigured') {
		$message = 'Connections are not available right now. Please contact support.';
	} elseif (($input['error'] ?? '') === 'start_failed') {
		$message = 'Could not start the connection. Please try again or contact support.';
	}

	return LogicResult::render(array(
		'session'             => $session,
		'settings'            => $settings,
		'account'             => $account,
		'account_connected'   => $account !== null && $account->get('cca_status') === 'active',
		'provisions'          => $provisions,
		'provider_configured' => $provider_configured,
		'referral_url'        => trim((string)$settings->get_setting('server_manager_linode_referral_url')),
		'message'             => $message,
		'in_progress'         => $in_progress,
	));
}
?>
