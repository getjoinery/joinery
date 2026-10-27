<?php
/**
 * ServicesShelfBroker — a self-hosted site's side of getjoinery's backup
 * storage (specs/easy_signup_completion.md WP4; services_phase2_platform.md §3).
 *
 * A `managed` backup target holds no credential. What stands in for one is
 * this object: BackupTarget::get_credentials() answers it, and S3Signer takes
 * it wherever a key would go. Every request is one call to getjoinery over the
 * site's connected key (ServicesClient) for a signed link, then the request on
 * that link, exactly as a Managed node writes through its management node's
 * broker (BackupBroker) — the signing, write-once and run bookkeeping are that
 * class's; only how a call travels differs, and that this one also reads.
 *
 *   begin()        opens a run at getjoinery: the run id, the storage space and
 *                  the run's base key. Asked by the backup engine before
 *                  anything is built, so a refusal (over the allowance, an
 *                  unpaid account) is the run's recorded cause.
 *   link()         a write inside the run's base key (BackupBroker), or a read
 *                  of any key under this site's base, which needs no run.
 *   list_objects() what this site's storage holds under a prefix.
 *   finish()       every object the run wrote, with its size and sha256.
 *   abort()        a finish naming nothing: getjoinery cancels what it signed.
 *
 * Never a delete. Retention of these backups is getjoinery's: a site that
 * could erase its own offsite copies would lose them to the first intruder.
 *
 * @version 1.2 - the re-review: a listing of the offloaded-file store (site/objects/) is the active space's only,
 *                so what a run decides is already held is what its own space holds; a read of a key in
 *                another space finds that space by listing the key's folder once; listing_complete() says
 *                whether every space answered
 * @version 1.1 - the WP4 review: a write is made only inside a run this broker opened, and a delete is refused
 *                before anything is asked; a listing covers every storage space getjoinery keeps the site's
 *                backups in and a read signs in the space its key was listed in; read_link() carries the life
 *                asked for; a moved storage place is written onto the target and the next run uses it; finish
 *                names the chain
 * @version 1.0
 */

class ServicesShelfBroker extends BackupBroker implements S3LinkReader {

	/** A call that could not reach getjoinery is asked again, this many times in all. */
	const ATTEMPTS = 3;

	/**
	 * Tests only: what every getjoinery backup storage call goes to instead of a
	 * ServicesClient (the same shelf* methods) — this broker's, the Test
	 * button's and a verify's report.
	 */
	public static $client_for_tests = null;

	/** @var BackupTarget */
	private $target;
	private $client;
	/** key => storage space id, from the listings so far: a read signs where the key was found */
	private $space_of = array();
	/** folder => true for every folder already listed to find where its keys are */
	private $located = array();
	/** Whether every storage space answered the last listing. */
	private $listing_complete = true;

	public function __construct(BackupTarget $target, $client = null) {
		$this->target = $target;
		$this->client = $client ?? self::$client_for_tests ?? new ServicesClient();
		$this->url = '';
		$this->token = '';
		$this->run_id = 0;
	}

	/** Where this site's backups sit in its storage: {prefix}/{slug}/, as the backup engine names every key. */
	public function base(): string {
		return BackupTarget::normalise_prefix((string)$this->target->get('bkt_path_prefix')) . '/' . BackupRunner::slug_for($this->target) . '/';
	}

	/** The run opens on first use: the engine asks for its id before anything is written. */
	public function run_id() {
		$this->begin();
		return $this->run_id;
	}

	/** Whether a run has been opened at getjoinery yet. Nothing a page renders opens one. */
	public function begun(): bool {
		return $this->begun !== null;
	}

	// ------------------------------------------------------------- S3LinkSource

	public function link(string $method, string $path, array $query, int $bytes) {
		$method = strtoupper($method);
		if ($method === 'GET' && !$query) {
			return $this->read_link($path, 0);
		}
		if ($method === 'DELETE') {
			throw new S3SignerException('getjoinery backup storage signs no delete: getjoinery prunes it by its own retention.');
		}
		if ($method === 'GET' || $method === 'HEAD') {
			throw new S3SignerException('getjoinery backup storage is read by listing and by signed downloads; it answers no '
				. strtolower($method) . ' of its own.');
		}
		// Only a backup run writes, inside the run it opened: anything else
		// reaching here would open a run at getjoinery as a side effect.
		if (!$this->begun()) {
			throw new S3SignerException('Nothing is written to getjoinery backup storage outside a backup run.');
		}
		return parent::link($method, $path, $query, $bytes);
	}

	// ------------------------------------------------------------- S3LinkReader

	public function read_link(string $path, int $expires): string {
		$key = ltrim((string)$path, '/');
		$args = array('name' => $this->read_name($path), 'operation' => 'get', 'run_id' => 0);
		// A key no listing has placed yet (a restore's offloaded file, a
		// rehearsal's sample) is found by listing its folder once, in every
		// space: a run taken before a move reads where it was written.
		if (!isset($this->space_of[$key])) {
			$folder = (string)substr(dirname($key) . '/', strlen($this->base()));
			if (!isset($this->located[$folder])) {
				$this->located[$folder] = true;
				try {
					$this->listing($folder, false);
				} catch (\Throwable $e) {
					// Unplaced: signed in the active space, as getjoinery answers by default.
				}
			}
		}
		if (isset($this->space_of[$key])) {
			$args['space_id'] = $this->space_of[$key];
		}
		if ($expires > 0) {
			$args['expires'] = $expires;
		}
		$data = $this->call('sign', $args);
		if (empty($data['url'])) {
			throw new S3SignerException('getjoinery signed no link to read ' . $this->read_name($path) . '.');
		}
		return (string)$data['url'];
	}

	public function list_objects(string $prefix, int $max, &$truncated): array {
		$truncated = false;
		$base = $this->base();
		$prefix = ltrim($prefix, '/');
		if (strpos($prefix, $base) !== 0 && $prefix . '/' !== $base) {
			throw new S3SignerException('That is outside this site\'s part of getjoinery backup storage.');
		}
		// The offloaded-file store is read to decide what a run need not send
		// again, and a chain stays in one space: so that listing is the active
		// space's alone. Everything else (a chain's folder, the whole site)
		// lists every space getjoinery keeps this site's backups in, so runs
		// taken before a move stay readable until they age out.
		$rel = (string)substr($prefix, strlen($base));
		$objects_dir = BackupProfile::path_segment(BackupProfile::SITE) . '/' . BackupObjects::DIR;
		$active_only = ($rel === $objects_dir || strpos($rel, $objects_dir . '/') === 0);
		$out = array();
		foreach ($this->listing($rel, $active_only) as $key => $object) {
			$out[] = $object;
			if ($max > 0 && count($out) >= $max) {
				$truncated = true;
				break;
			}
		}
		return $out;
	}

	/** Whether every storage space answered the last listing: one that could not be read hides what it holds. */
	public function listing_complete(): bool {
		return $this->listing_complete;
	}

	/**
	 * One listing at getjoinery, keyed as this site names them: key =>
	 * ['key', 'size', 'last_modified']. Each key remembers the space it was
	 * found in, and a read signs there.
	 */
	private function listing(string $rel, bool $active_only): array {
		$base = $this->base();
		$data = $this->call('list', array('prefix' => $rel));
		$active = array();
		$this->listing_complete = true;
		foreach ((array)($data['spaces'] ?? array()) as $space) {
			if ((string)($space['error'] ?? '') !== '') {
				$this->listing_complete = false;
			}
			if ((string)($space['state'] ?? '') === 'active') {
				$active[(int)($space['space_id'] ?? 0)] = true;
			}
		}
		$out = array();
		foreach ((array)($data['objects'] ?? array()) as $object) {
			$sid = (int)($object['space_id'] ?? 0);
			if ($active_only && !isset($active[$sid])) {
				continue;
			}
			$key = $base . ltrim((string)($object['key'] ?? ''), '/');
			if (isset($out[$key])) {
				continue; // the active space answers first
			}
			$this->space_of[$key] = $sid;
			$out[$key] = array('key' => $key,
				'size' => (int)($object['size'] ?? 0), 'last_modified' => (string)($object['last_modified'] ?? ''));
		}
		return $out;
	}

	// ------------------------------------------------------------ the run's end

	public function abort($cause) {
		if ($this->begun === null) {
			return;
		}
		try {
			$this->call('finish', array('completed' => array()));
		} catch (\Throwable $e) {
			error_log('ServicesShelfBroker: could not tell getjoinery run ' . $this->run_id . ' failed: ' . $e->getMessage());
		}
	}

	// ------------------------------------------------------------------ helpers

	/**
	 * Record the place a run's base key names ({prefix}/{folder}/site/) on the
	 * target and in backup_service_folder. False when the key is not that
	 * shape, which leaves the record as it was.
	 */
	private function adopt_place(string $base_key): bool {
		$segment = BackupProfile::path_segment(BackupProfile::SITE) . '/';
		if (substr($base_key, -strlen($segment)) !== $segment) {
			return false;
		}
		$parts = explode('/', trim(substr($base_key, 0, -strlen($segment)), '/'));
		$folder = (string)array_pop($parts);
		try {
			BackupRunner::validate_slug($folder);
		} catch (\Throwable $e) {
			return false;
		}
		$this->target->set('bkt_path_prefix', implode('/', $parts));
		$this->target->save();
		Setting::put('backup_service_folder', $folder);
		Globalvars::get_instance()->forget_setting('backup_service_folder');
		return true;
	}

	/** A read's name, relative to this site's base. */
	private function read_name($path) {
		$key = ltrim((string)$path, '/');
		$base = $this->base();
		if (strpos($key, $base) !== 0 || strlen($key) === strlen($base)) {
			throw new S3SignerException('That key is outside this site\'s part of getjoinery backup storage.');
		}
		return substr($key, strlen($base));
	}

	/**
	 * One call to getjoinery over the connected key. A refusal is thrown with
	 * getjoinery's sentence; a call that could not reach it is asked again. Every
	 * call is safe to repeat, as BackupBroker's are.
	 */
	protected function call($action, array $body) {
		$last = '';
		for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
			try {
				return $this->ask($action, $body);
			} catch (ServiceClientException $e) {
				$last = $e->getMessage();
				if (stripos($last, 'could not reach') === false) {
					throw new S3SignerException($last);
				}
				if ($attempt < self::ATTEMPTS && self::$client_for_tests === null) {
					sleep(2 * $attempt);
				}
			}
		}
		throw new S3SignerException($last);
	}

	private function ask($action, array $body) {
		switch ($action) {
			case 'begin':
				$data = $this->client->shelfBeginRun(BackupProfile::SITE, '', array());
				$base_key = (string)($data['base_key'] ?? '');
				$expected = $this->base() . BackupProfile::path_segment(BackupProfile::SITE) . '/';
				if ($base_key !== $expected) {
					// getjoinery keeps this site's backups somewhere other than the
					// target row says, so every key this run names would be refused.
					// The new place is written here and the next run uses it.
					$this->run_id = (int)($data['run_id'] ?? 0);
					$this->begun = array();
					$this->abort('the storage place moved');
					$this->begun = null;
					$moved = $this->adopt_place($base_key);
					throw new S3SignerException('getjoinery moved where it keeps this site\'s backups'
						. ($moved ? ', and this site now has the new place on record: the next backup goes there.'
							: '. Choose getjoinery backup storage again on the Backups page to pick up where it is now.'));
				}
				$this->run_id = (int)($data['run_id'] ?? 0);
				return array('space_id' => (int)($data['space_id'] ?? 0), 'base_key' => $base_key,
					'bucket' => (string)($data['bucket'] ?? ''), 'target_name' => (string)$this->target->get('bkt_name'));
			case 'sign':
				$run_id = array_key_exists('run_id', $body) ? (int)$body['run_id'] : $this->run_id;
				$name = (string)$body['name'];
				$operation = (string)$body['operation'];
				unset($body['run_id'], $body['name'], $body['operation']);
				return $this->client->shelfSign($run_id, $name, $operation, $body);
			case 'list':
				return $this->client->shelfList((string)($body['prefix'] ?? ''));
			case 'finish':
				return $this->client->shelfFinishRun($this->run_id, (array)($body['completed'] ?? array()), (string)($body['chain'] ?? ''));
		}
		throw new S3SignerException('getjoinery backup storage has no "' . $action . '" call.');
	}
}
