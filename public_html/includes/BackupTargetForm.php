<?php
/**
 * BackupTargetForm — the one form that adds or edits a backup target, and the
 * one save path behind it.
 *
 * Four places draw it: the Backups page, the setup wizard's Backups step, on a
 * management node the server_manager Backup Targets page, and the Cloud
 * Storage page for the file store (a target with bkt_purpose 'files'). All
 * write the same row (bkt_backup_targets), so they draw the same fields and
 * read them the same way. The provider list, which fields each provider asks for, and how
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
 *   wizard            bool  the setup wizard's short form: no name, folder or
 *                           Enabled box; the target is named Backups and enabled
 *   files             bool  the file store's form: no Enabled box (the page's
 *                           Pause says whether files move), the folder defaults
 *                           to this site's name; saved by CloudStorageLifecycle,
 *                           whose check proves the bucket private
 *
 * @version 1.3 - one key per target: the node key and the per-run key switch are gone, since a Managed node
 *                writes through the management node's broker and is handed no key (specs/storage_targets.md WP5)
 * @version 1.2 - the files option: the file store is a target row drawn and read by this form
 *                (specs/storage_targets.md WP6)
 * @version 1.1 - WP2: the location is drawn read-only and refused once anything is stored there, and a
 *                target that is where new backups go, or that a node backs up to, is not switched off
 * @version 1.0 - specs/storage_targets.md WP1: one target form
 */

class BackupTargetForm {

	/**
	 * Draw the fields into a form the caller has opened. The caller adds its
	 * own hidden routing fields, the submit button and end_form().
	 *
	 * @param object            $fw     a FormWriter
	 * @param BackupTarget|null $target the target being edited (its posted values, after a refused save), or null
	 */
	public static function render($fw, ?BackupTarget $target, array $opts = array()): void {
		$wizard = !empty($opts['wizard']);
		$files = !empty($opts['files']);
		$editing = $target !== null && $target->key;

		try {
			$creds = $target ? ($target->get_credentials() ?: array()) : array();
		} catch (BackupTargetException $e) {
			$creds = array();
			echo '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage())
				. ' Re-enter the access key and the secret to replace them.</div>';
		}
		$provider = $target ? StorageProvider::normalise($target->get('bkt_provider') ?: 'b2') : 'b2';

		// Whether a secret is stored is read from the saved row, never from this
		// request's copy: a refused save must not draw a typed key as saved.
		$saved = $editing ? new BackupTarget($target->key, TRUE) : null;
		$main_stored = false;
		if ($saved) {
			try {
				$main_stored = (string)(($saved->get_credentials())['secret_key'] ?? '') !== '';
			} catch (BackupTargetException $e) {
				$main_stored = false;
			}
		}
		$saved_provider = $saved ? (string)$saved->get('bkt_provider') : null;

		if ($wizard) {
			$fw->hiddeninput('bkt_name', '', array('value' => 'Backups'));
			$fw->hiddeninput('bkt_enabled', '', array('value' => '1'));
		} else {
			$fw->textinput('bkt_name', 'Name', array('required' => true,
				'value' => $target ? (string)$target->get('bkt_name') : ($files ? self::free_name('File store') : ''),
				'placeholder' => $files ? 'e.g. Backblaze files' : 'e.g. Backblaze backups'));
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
			'visibility_rules' => StorageProvider::visibility_rules(),
		));
		$fw->textinput('bkt_bucket', 'Bucket', array('required' => $wizard || $files, 'readonly' => $locked !== '',
			'value' => $target ? (string)$target->get('bkt_bucket') : '',
			'helptext' => $files
				? 'A private bucket used for nothing else; not a backup bucket. Save refuses a bucket anyone can read.'
				: 'A private bucket used for nothing else.'));
		if (!$wizard) {
			$default_folder = $files ? CloudFileStore::default_prefix() : BackupTarget::DEFAULT_PREFIX;
			$fw->textinput('bkt_path_prefix', 'Folder inside the bucket', array('readonly' => $locked !== '',
				'value' => $target ? ((string)$target->get('bkt_path_prefix') ?: $default_folder) : $default_folder,
				'helptext' => $files ? 'Offloaded files are stored under this folder.' : 'Backups are stored under this folder.'));
		}

		$fw->textinput('access_key', 'Access key ID', array('required' => $wizard || $files, 'autocomplete' => 'off',
			'value' => (string)($creds['access_key'] ?? ''),
			'helptext' => 'A key for this bucket only, that can list, read, write and delete. '
				. 'Backblaze: listFiles, readFiles, writeFiles, deleteFiles'
				. '. Amazon: s3:ListBucket, s3:GetObject, s3:PutObject, s3:DeleteObject.'));
		$fw->passwordinput('secret_key', 'Secret key', array('required' => ($wizard || $files) && !$main_stored,
			'autocomplete' => 'new-password',
			'stored' => $main_stored && $saved_provider === $provider));
		$fw->textinput('region', 'Region', array('value' => (string)($creds['region'] ?? ''), 'readonly' => $locked !== '',
			'helptext' => 'The bucket\'s region, cluster or datacenter, such as us-east-1.'));
		$fw->textinput('endpoint', 'Endpoint', array('value' => (string)($creds['endpoint'] ?? ''), 'readonly' => $locked !== '',
			'helptext' => 'The service\'s S3 address, such as s3.example.com.'));

		if (!$wizard && !$files) {
			$fw->checkboxinput('bkt_enabled', 'Enabled', array('checked' => $target ? (bool)$target->get('bkt_enabled') : true));
		}
	}

	/** $base, or "$base 2", "$base 3"… — the first no live target is called. Names are never shared. */
	private static function free_name(string $base): string {
		$taken = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT 1 FROM bkt_backup_targets WHERE lower(bkt_name) = lower(?) AND bkt_delete_time IS NULL');
		for ($n = 1; ; $n++) {
			$name = $n === 1 ? $base : $base . ' ' . $n;
			$taken->execute(array($name));
			if (!$taken->fetchColumn()) {
				return $name;
			}
		}
	}

	/**
	 * Read posted fields onto a target. Nothing is stored.
	 *
	 * @return array{ok: bool, message: string, note: string} message says what
	 *   is wrong when not ok; note is a remark the save message should carry
	 */
	public static function apply(BackupTarget $target, array $input, array $opts = array()): array {
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
			$target->set('bkt_path_prefix', trim((string)($input['bkt_path_prefix'] ?? ''))
				?: ($target->is_file_store() ? CloudFileStore::default_prefix() : BackupTarget::DEFAULT_PREFIX));
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
	 * apply(), then prove an enabled target and store it.
	 *
	 * @return array{ok: bool, message: string} the sentence to show either way
	 */
	public static function save(BackupTarget $target, array $input, array $opts = array()): array {
		if ($target->is_file_store()) {
			// A file store is proved private before it is stored, by its own page.
			return array('ok' => false, 'message' => 'Not saved. This is a file store; it is changed on the Cloud Storage page.');
		}
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
