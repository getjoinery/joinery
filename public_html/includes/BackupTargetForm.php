<?php
/**
 * BackupTargetForm — the one form that adds or edits a backup target, and the
 * one save path behind it.
 *
 * Three places draw it: the Backups page, the setup wizard's Backups step, and
 * on a management node the server_manager Backup Targets page. All three write
 * the same row (bkt_backup_targets), so they draw the same fields and save them
 * the same way. The provider list, which fields each provider asks for, and how
 * the rest is filled in come from StorageProvider's catalogue.
 *
 * A save reads the posted fields onto the target (apply()), checks the
 * provider's own fields before the provider is asked anything, fills what the
 * provider decides (BackupTarget::complete_credentials()), and proves an
 * enabled target with TargetTester before it is stored (save()). A disabled
 * target is stored untested; enabling it is a save, and that save tests it.
 *
 * Secrets are never drawn back into the form: a stored one is a locked field
 * with Reset (FormWriterV2Base::process_secretinput()). A stored secret belongs
 * to the provider it was saved under; changing the provider starts from none.
 *
 * Options:
 *   node_credentials  bool  also draw and save the write-only node key and the
 *                           per-run key switch (management node only)
 *   wizard            bool  the setup wizard's short form: no name, folder or
 *                           Enabled box; the target is named Backups and enabled
 *
 * @version 1.1 - WP2: the location is drawn read-only and refused once anything is stored there, and a
 *                target that is where new backups go, or that a node backs up to, is not switched off
 * @version 1.0 - specs/storage_targets.md WP1: one target form
 */

class BackupTargetForm {

	/** Providers whose keys can write without deleting, so a node key means something. */
	const NODE_KEY_PROVIDERS = array('b2', 's3');

	/**
	 * Draw the fields into a form the caller has opened. The caller adds its
	 * own hidden routing fields, the submit button and end_form().
	 *
	 * @param object            $fw     a FormWriter
	 * @param BackupTarget|null $target the target being edited (its posted values, after a refused save), or null
	 */
	public static function render($fw, ?BackupTarget $target, array $opts = array()): void {
		$node = !empty($opts['node_credentials']);
		$wizard = !empty($opts['wizard']);
		$editing = $target !== null && $target->key;

		try {
			$creds = $target ? ($target->get_credentials() ?: array()) : array();
		} catch (BackupTargetException $e) {
			$creds = array();
			echo '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage())
				. ' Re-enter the access key and the secret to replace them.</div>';
		}
		try {
			$node_creds = ($node && $target && $target->has_node_credentials()) ? $target->get_node_credentials() : array();
		} catch (BackupTargetException $e) {
			$node_creds = array();
		}
		$provider = $target ? StorageProvider::normalise($target->get('bkt_provider') ?: 'b2') : 'b2';

		// Whether a secret is stored is read from the saved row, never from this
		// request's copy: a refused save must not draw a typed key as saved.
		$saved = $editing ? new BackupTarget($target->key, TRUE) : null;
		$main_stored = false;
		$node_stored = false;
		if ($saved) {
			try {
				$main_stored = (string)(($saved->get_credentials())['secret_key'] ?? '') !== '';
			} catch (BackupTargetException $e) {
				$main_stored = false;
			}
			try {
				$node_stored = $saved->has_node_credentials() && (string)(($saved->get_node_credentials())['secret_key'] ?? '') !== '';
			} catch (BackupTargetException $e) {
				$node_stored = false;
			}
		}
		$saved_provider = $saved ? (string)$saved->get('bkt_provider') : null;

		if ($wizard) {
			$fw->hiddeninput('bkt_name', '', array('value' => 'Backups'));
			$fw->hiddeninput('bkt_enabled', '', array('value' => '1'));
		} else {
			$fw->textinput('bkt_name', 'Name', array('required' => true,
				'value' => $target ? (string)$target->get('bkt_name') : '', 'placeholder' => 'e.g. Backblaze backups'));
		}

		// Once anything is stored, the location is fixed (R1): drawn read-only,
		// with the reason. The key and the name stay editable.
		$locked = $saved ? $saved->location_refusal() : '';
		if ($locked !== '') {
			echo '<p class="text-muted small">' . htmlspecialchars($locked) . '</p>';
			$fw->hiddeninput('bkt_provider', '', array('value' => $provider));
		}
		$fw->dropinput($locked !== '' ? 'bkt_provider_shown' : 'bkt_provider', 'Provider', array(
			'options' => StorageProvider::options(),
			'value' => $provider,
			'disabled' => $locked !== '',
			'visibility_rules' => self::visibility_rules($node),
		));
		$fw->textinput('bkt_bucket', 'Bucket', array('required' => $wizard, 'readonly' => $locked !== '',
			'value' => $target ? (string)$target->get('bkt_bucket') : '',
			'helptext' => 'A private bucket used for nothing else.'));
		if (!$wizard) {
			$fw->textinput('bkt_path_prefix', 'Folder inside the bucket', array('readonly' => $locked !== '',
				'value' => $target ? ((string)$target->get('bkt_path_prefix') ?: 'joinery-backups') : 'joinery-backups',
				'helptext' => 'Backups are stored under this folder.'));
		}

		$fw->textinput('access_key', 'Access key ID', array('required' => $wizard, 'autocomplete' => 'off',
			'value' => (string)($creds['access_key'] ?? ''),
			'helptext' => 'A key for this bucket only, that can list, read, write and delete. '
				. 'Backblaze: listFiles, readFiles, writeFiles, deleteFiles'
				. ($node ? ' (add writeKeys, listKeys, deleteKeys to make a key for each run)' : '')
				. '. Amazon: s3:ListBucket, s3:GetObject, s3:PutObject, s3:DeleteObject.'));
		$fw->passwordinput('secret_key', 'Secret key', array('required' => $wizard && !$main_stored,
			'autocomplete' => 'new-password',
			'stored' => $main_stored && $saved_provider === $provider));
		$fw->textinput('region', 'Region', array('value' => (string)($creds['region'] ?? ''), 'readonly' => $locked !== '',
			'helptext' => 'The bucket\'s region, cluster or datacenter, such as us-east-1.'));
		$fw->textinput('endpoint', 'Endpoint', array('value' => (string)($creds['endpoint'] ?? ''), 'readonly' => $locked !== '',
			'helptext' => 'The service\'s S3 address, such as s3.example.com.'));

		if ($node) {
			echo '<div id="node_key_fields">';
			echo '<p class="fw-semibold text-muted mt-2 mb-1">Node key (write-only)'
				. (!empty($node_creds) ? ' <span class="badge bg-success">configured</span>' : '') . '</p>';
			$fw->textinput('node_access_key', 'Node access key ID', array('autocomplete' => 'off',
				'value' => (string)($node_creds['access_key'] ?? ''),
				'helptext' => 'Optional. A key for this bucket that can write and not delete '
					. '(Backblaze: writeFiles without deleteFiles; Amazon: s3:PutObject without s3:DeleteObject). '
					. 'Nodes are handed it for each run, so a compromised node cannot erase backups.'));
			$fw->passwordinput('node_secret_key', 'Node secret key', array(
				'autocomplete' => 'new-password',
				'stored' => $node_stored && $saved_provider === $provider,
				'helptext' => 'Without one, nodes are handed the main key during a run.'));
			echo '</div>';
			echo '<p id="node_key_none" class="text-muted small">This provider\'s keys cannot write without also deleting, '
				. 'so nodes are handed the main key during a run.</p>';
			$fw->checkboxinput('bkt_mint_run_keys', 'Make a key for each run', array(
				'checked' => (bool)($target ? $target->get('bkt_mint_run_keys') : false),
				'helptext' => 'Each backup run is handed a key made for it: pinned to that node\'s own folder in this bucket, '
					. 'write-only, and expiring with the run. Check first that the main key can make keys '
					. '(writeKeys, listKeys, deleteKeys): a key that cannot fails every run rather than falling back.',
			));
		}

		if (!$wizard) {
			$fw->checkboxinput('bkt_enabled', 'Enabled', array('checked' => $target ? (bool)$target->get('bkt_enabled') : true));
		}
	}

	/**
	 * The provider select's rules: each provider shows the region and endpoint
	 * it asks for; on a management node, the node key fields for a provider
	 * whose keys can write without deleting, and the per-run key for Backblaze.
	 */
	private static function visibility_rules(bool $node): array {
		$rules = StorageProvider::visibility_rules();
		if ($node) {
			foreach ($rules as $slug => $rule) {
				if (in_array($slug, self::NODE_KEY_PROVIDERS, true)) {
					$rule['show'][] = 'node_key_fields';
					$rule['hide'][] = 'node_key_none';
				} else {
					$rule['show'][] = 'node_key_none';
					$rule['hide'][] = 'node_key_fields';
				}
				if ($slug === 'b2') {
					$rule['show'][] = 'bkt_mint_run_keys';
				} else {
					$rule['hide'][] = 'bkt_mint_run_keys';
				}
				$rules[$slug] = $rule;
			}
		}
		return $rules;
	}

	/**
	 * Read posted fields onto a target. Nothing is stored.
	 *
	 * @return array{ok: bool, message: string, note: string} message says what
	 *   is wrong when not ok; note is a remark the save message should carry
	 */
	public static function apply(BackupTarget $target, array $input, array $opts = array()): array {
		$node = !empty($opts['node_credentials']);
		$refuse = function ($message) {
			return array('ok' => false, 'message' => $message, 'note' => '');
		};

		$old_provider = $target->key ? (string)$target->get('bkt_provider') : null;
		$was_enabled = $target->key ? (bool)$target->get('bkt_enabled') : false;
		$location_before = $target->key ? self::location($target) : null;
		// A locked location is drawn read-only and its select disabled, so the
		// provider may be absent from the post; it is then the stored one.
		$provider = trim((string)($input['bkt_provider'] ?? ($old_provider ?? '')));
		if (!StorageProvider::known($provider)) {
			return $refuse('Choose a provider.');
		}
		$same_provider = $old_provider === $provider;

		if (isset($input['bkt_name'])) {
			$target->set('bkt_name', trim((string)$input['bkt_name']));
		}
		$target->set('bkt_provider', $provider);
		$target->set('bkt_bucket', trim((string)($input['bkt_bucket'] ?? '')));
		if (array_key_exists('bkt_path_prefix', $input) || !$target->key) {
			$target->set('bkt_path_prefix', trim((string)($input['bkt_path_prefix'] ?? '')) ?: 'joinery-backups');
		}
		$target->set('bkt_enabled', !empty($input['bkt_enabled']));

		// What is stored now. A credential that cannot be decrypted has nothing
		// to keep, so the key and secret must both be entered again.
		$existing = array();
		if ($target->key) {
			try {
				$existing = $target->get_credentials() ?: array();
			} catch (BackupTargetException $e) {
				if (trim((string)($input['access_key'] ?? '')) === '' || (string)($input['secret_key'] ?? '') === '') {
					return $refuse('Not saved. ' . $e->getMessage() . ' Re-enter the access key and the secret to replace them.');
				}
			}
		}
		if (!$same_provider) {
			$existing = array();
		}

		$stored_secret = (string)($existing['secret_key'] ?? '');
		list($secret_what, $typed_secret) = FormWriterV2Base::process_secretinput($input, 'secret_key', $stored_secret !== '');
		$secret = ($secret_what === FormWriterV2Base::SECRET_SET) ? $typed_secret
			: (($secret_what === FormWriterV2Base::SECRET_CLEAR) ? '' : $stored_secret);
		$access = trim((string)($input['access_key'] ?? ($existing['access_key'] ?? '')));

		// Only what the provider asks for is read from the form; a hidden field
		// still posts whatever it last held. The rest is the provider's to decide.
		$asks = StorageProvider::asks($provider);
		$region = in_array('region', $asks, true) ? trim((string)($input['region'] ?? ($existing['region'] ?? ''))) : '';
		$endpoint = in_array('endpoint', $asks, true) ? trim((string)($input['endpoint'] ?? ($existing['endpoint'] ?? ''))) : '';
		$key_changed = $secret_what !== FormWriterV2Base::SECRET_KEEP || $access !== (string)($existing['access_key'] ?? '');
		if ($provider === 'b2' && !$key_changed) {
			// Backblaze's region and endpoint belong to the key: the same key keeps
			// the ones it was given, and a new key is asked for its own.
			$region = (string)($existing['region'] ?? '');
			$endpoint = (string)($existing['endpoint'] ?? '');
		}

		$note = '';
		$creds = $existing;
		$changed = !$target->key || !$same_provider || $key_changed
			|| $region !== (string)($existing['region'] ?? '')
			|| $endpoint !== (string)($existing['endpoint'] ?? '');
		if ($changed) {
			$completed = BackupTarget::complete_credentials($provider, array(
				'access_key' => $access,
				'secret_key' => $secret,
				'region'     => $region,
				'endpoint'   => $endpoint,
			));
			$note = $completed['note'];
			$creds = $completed['creds'];
		}
		// The provider's own fields are checked before anything is asked of the provider.
		$problem = BackupTarget::credential_problem($provider, $creds);
		if ($problem !== '') {
			return $refuse('Not saved. ' . $problem);
		}
		$target->set('bkt_credentials', $creds);

		if ($location_before !== null && self::location($target) !== $location_before) {
			$refusal = $target->location_refusal();
			if ($refusal !== '') {
				return $refuse('Not saved. ' . $refusal);
			}
		}
		if ($was_enabled && !$target->get('bkt_enabled')) {
			$refusal = $target->disable_refusal();
			if ($refusal !== '') {
				return $refuse('Not saved. ' . $refusal);
			}
		}

		if ($node) {
			self::apply_node_key($target, $input, $provider, $same_provider, $creds);
			// Only Backblaze can make a key per run; anywhere else the switch is
			// off whatever the box said.
			$target->set('bkt_mint_run_keys', $provider === 'b2' && !empty($input['bkt_mint_run_keys']));
		}
		return array('ok' => true, 'message' => '', 'note' => $note);
	}

	/**
	 * Where a target's objects are: provider, endpoint, region, bucket and
	 * folder, in their stored forms. Fixed once anything is stored there.
	 */
	private static function location(BackupTarget $target): array {
		try {
			$creds = $target->get_credentials() ?: array();
		} catch (BackupTargetException $e) {
			$creds = array();
		}
		return array(
			(string)$target->get('bkt_provider'),
			StorageProvider::normalise_endpoint($creds['endpoint'] ?? ''),
			strtolower(trim((string)($creds['region'] ?? ''))),
			(string)$target->get('bkt_bucket'),
			trim((string)$target->get('bkt_path_prefix'), '/'),
		);
	}

	/**
	 * The write-only node key. A stored one is a locked field like the main
	 * secret; Reset and blank removes it, and nodes are then handed the main
	 * key. A provider change, or a provider whose keys cannot write without
	 * deleting, leaves none behind: a stale one would fail the save's own
	 * connection test and be handed to nodes. The node key is for the same
	 * bucket, so it shares the main key's region and endpoint.
	 */
	private static function apply_node_key(BackupTarget $target, array $input, string $provider, bool $same_provider, array $creds): void {
		if (!$same_provider || !in_array($provider, self::NODE_KEY_PROVIDERS, true)) {
			$target->set('bkt_node_credentials', null);
			if (!in_array($provider, self::NODE_KEY_PROVIDERS, true)) {
				return;
			}
		}
		$existing = array();
		if ($same_provider && $target->key) {
			try {
				$existing = $target->get_node_credentials() ?: array();
			} catch (BackupTargetException $e) {
				$existing = array();
			}
		}
		$stored = (string)($existing['secret_key'] ?? '') !== '';
		list($what, $typed) = FormWriterV2Base::process_secretinput($input, 'node_secret_key', $stored);
		$access = trim((string)($input['node_access_key'] ?? ''));
		if ($what === FormWriterV2Base::SECRET_CLEAR) {
			$target->set('bkt_node_credentials', null);
			return;
		}
		if ($what === FormWriterV2Base::SECRET_KEEP) {
			if ($stored) {
				$existing['access_key'] = $access !== '' ? $access : (string)($existing['access_key'] ?? '');
				$existing['region'] = (string)($creds['region'] ?? '');
				$existing['endpoint'] = (string)($creds['endpoint'] ?? '');
				$target->set('bkt_node_credentials', $existing);
			}
			return;
		}
		if ($access !== '' && $typed !== '') {
			$target->set('bkt_node_credentials', array(
				'access_key' => $access,
				'secret_key' => $typed,
				'region'     => (string)($creds['region'] ?? ''),
				'endpoint'   => (string)($creds['endpoint'] ?? ''),
			));
		}
	}

	/**
	 * apply(), then prove an enabled target and store it.
	 *
	 * @return array{ok: bool, message: string} the sentence to show either way
	 */
	public static function save(BackupTarget $target, array $input, array $opts = array()): array {
		$applied = self::apply($target, $input, $opts);
		if (!$applied['ok']) {
			return array('ok' => false, 'message' => $applied['message']);
		}
		$note = $applied['note'] !== '' ? ' ' . $applied['note'] : '';
		try {
			$target->prepare();
		} catch (BackupTargetException $e) {
			return array('ok' => false, 'message' => 'Not saved. ' . $e->getMessage());
		}
		if ($target->get('bkt_enabled')) {
			$test = TargetTester::test($target);
			if (!$test['success']) {
				return array('ok' => false, 'message' => 'Not saved. ' . $test['message'] . $note);
			}
			$target->save();
			return array('ok' => true, 'message' => 'Target saved. ' . $test['message']);
		}
		$target->save();
		return array('ok' => true, 'message' => 'Target saved. It is disabled, so it was not tested; enabling it tests it.' . $note);
	}
}
