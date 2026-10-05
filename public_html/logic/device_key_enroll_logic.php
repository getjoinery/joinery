<?php

/**
 * device_key_enroll — a signed-in app asks to be handed vault keys.
 *
 * A phone is already signed in: it holds the session key auth/login gave it.
 * What it lacks is the key to the content the server cannot read (Fortress
 * mail). That key lives in the owner's browser, so the browser has to hand it
 * over, sealed to a key only this phone holds — the device-link ceremony's
 * second half. The first half (minting a credential) the phone does not need,
 * and running it would give one phone two identities.
 *
 * So this opens a ceremony **bound** to the caller: the link row carries the
 * caller's user and key from the start, approval on /profile/devices/link mints
 * nothing and records the handed vaults on the SyncDevice row for this key, and
 * the poll (POST /api/v1/auth/device_link/{poll_token}) hands over the sealed
 * keys alone. Re-running after a rotation or a recovery-code use re-asserts the
 * same device key.
 *
 * App session key only: a browser has nothing to enroll, and a machine key is
 * not a person's device.
 *
 * @version 1.0 - specs/fortress_mobile_apps.md § R2
 */

function device_key_enroll_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}

	$api_key_id = (int)$session->get_api_key_id();
	$api_key = $api_key_id ? new ApiKey($api_key_id, TRUE) : null;
	if (!$api_key || !$api_key->key || $api_key->get('apk_type') !== ApiKey::TYPE_SESSION) {
		return LogicResult::error('Only a signed-in app can enroll a device key.');
	}

	if (!DeviceLink::linking_available($user_id)) {
		return LogicResult::error('There is nothing to hand this device yet: set up an end-to-end vault on a computer first.');
	}

	$settings = Globalvars::get_instance();
	$limit  = (int)($settings->get_setting('api_device_link_rate_limit_requests') ?: 600);
	$window = (int)($settings->get_setting('api_device_link_rate_limit_window') ?: 3600);
	$state = RequestLogger::rate_limit_state('api_device_link', $limit, $window, null, $user_id);
	if (!$state['allowed']) {
		return LogicResult::error('Too many device-link requests. Try again later.');
	}

	$device_name = trim((string)($input['device_name'] ?? ''));
	$platform    = strtolower(trim((string)($input['platform'] ?? '')));
	$pubkey      = trim((string)($input['device_pubkey'] ?? ''));

	if ($device_name === '') {
		return LogicResult::error('A device name is required.');
	}
	if (!in_array($platform, SyncDevice::platforms(), true)) {
		return LogicResult::error('Platform must be one of: ' . implode(', ', SyncDevice::platforms()) . '.');
	}
	$raw = base64_decode($pubkey, true);
	if ($raw === false || strlen($raw) !== 32) {
		return LogicResult::error('device_pubkey must be standard base64 of a 32-byte X25519 public key.');
	}

	$code = DeviceLink::generate_code();
	$poll_token = bin2hex(random_bytes(32));

	$link = new DeviceLink(NULL);
	$link->set('dlk_code_hash', DeviceLink::hash_code($code));
	$link->set('dlk_poll_token_hash', DeviceLink::hash_token($poll_token));
	$link->set('dlk_device_name', substr($device_name, 0, 64));
	$link->set('dlk_platform', $platform);
	$link->set('dlk_device_pubkey', $pubkey);
	$link->set('dlk_request_ip', substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45));
	$link->set('dlk_status', DeviceLink::STATUS_PENDING);
	$link->set('dlk_usr_user_id', $user_id);
	$link->set('dlk_apk_api_key_id', (int)$api_key->key);
	$link->set('dlk_bound', true);
	$existing = SyncDevice::for_api_key((int)$api_key->key);
	if ($existing) {
		$link->set('dlk_sde_sync_device_id', (int)$existing->key);
	}
	$link->set('dlk_expires_time', gmdate('Y-m-d H:i:s', time() + DeviceLink::TTL_SECONDS));
	$link->save();

	// The code is a bearer credential for the ten minutes it lives; never logged.
	RequestLogger::log('api_device_link', 'device_key_enroll', true, ['user_id' => $user_id, 'status_code' => 200]);

	$display_code = substr($code, 0, 4) . '-' . substr($code, 4);
	return LogicResult::render(array(
		'link_code'    => $display_code,
		'poll_token'   => $poll_token,
		'verify_url'   => LibraryFunctions::get_absolute_url('/profile/devices/link?code=' . urlencode($display_code)),
		'expires_time' => $link->get('dlk_expires_time'),
		'poll_after'   => 3,
		'device_id'    => $existing ? (int)$existing->key : null,
	));
}

function device_key_enroll_logic_descriptor(): array {
	return array(
		'description'      => 'Open a device-link ceremony bound to the calling app\'s own session key, so a browser can hand this device its vault keys sealed to device_pubkey. Approval mints no credential; the poll (GET /api/v1/auth/device_link/{poll_token}) returns {status, device_id, sealed_vault_keys}. App session key only.',
		'requires_session' => true,
		'mutates'          => true,
		'auth'             => array('requires_person_credential' => true, 'capability' => 'write'),
		'input'            => array(
			'device_pubkey' => array('type' => 'string', 'required' => true, 'max_length' => 64, 'label' => 'Device X25519 public key (standard base64, 32 bytes)'),
			'platform'      => array('type' => 'string', 'required' => true, 'max_length' => 16, 'label' => 'Platform (ios, android, macos, windows, linux)'),
			'device_name'   => array('type' => 'string', 'required' => true, 'max_length' => 64, 'label' => 'Device name'),
		),
	);
}
?>
