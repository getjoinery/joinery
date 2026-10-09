<?php
/**
 * ShelfBroker — the plane signs what a shelf tenant may do, and keeps the
 * ledger of what it signed (specs/services_phase2_platform.md §3, D3).
 *
 * No box ever holds a storage credential. For every object a site writes or
 * reads in backup storage it asks here for a presigned URL: one request, one key,
 * one operation, good for an hour, signed with the plane's own credential
 * for its backup storage target. The broker checks, then signs:
 *
 *   begin_run    the tenant is usable (active, date ahead), and the ledger's
 *                completed bytes plus the run's declared sizes fit inside the
 *                allowance; otherwise it refuses with the sentence the run
 *                records as its cause. Answers a run id and the run's base key.
 *   sign         a write (put | multipart_create | multipart_parts |
 *                multipart_complete) needs an open run and a key inside its
 *                base key, and is never signed for a key a finished run
 *                completed: that answers 'exists' with the recorded size and
 *                hash instead of a link. A get needs no run: the key is inside
 *                the tenant's prefix (the layout list answers), or inside a
 *                run's base key when one is named. Never a delete.
 *   list         a prefix inside the tenant's own; answers from inside it only.
 *   finish_run   closes the run; the ledger marks the named objects complete
 *                with the size and sha256 the writer reports, and cancels
 *                everything else signed for the run — an open multipart is
 *                aborted at the provider with the plane's credential before
 *                its row goes. The run's ledger file goes to backup storage.
 *   status       the C2 fields.
 *
 * A Managed node's run takes the same signing and finishing path
 * (NodeBroker): only how its owner is recognised differs — a token minted
 * when the node's agent claims the job, not an API key.
 *
 * What a link binds is the host, the key, the verb and the expiry. The size
 * declared at sign and the size and hash reported at finish are the writer's
 * word: the hash is what every later reader checks the bytes against, and the
 * customer reconcile compares sizes with a listing, but nothing here reads the
 * bytes back as they land.
 *
 * Reads and writes have different standing. A write needs a usable tenant:
 * active, date ahead, inside the allowance. A read (list, get) is allowed to
 * a suspended or released tenant for as long as its objects exist — the
 * retention promise is that the copies stay readable — and is refused only
 * once svt_pruned_time is set.
 *
 * Every write signed is a ledger row: one per space and key (owner's space,
 * run, key, bytes, chain, signed time; completed time and sha256 once the
 * writer says so). NOTHING IS WRITTEN TWICE: a key a finished run completed
 * with a recorded hash is never signed again, for any run, so a writer can
 * replace nothing it already wrote and the hash recorded here stays the hash
 * of what is there (specs/storage_targets.md §6). A key signed but never
 * completed — a failed run's — may be signed again by the run that retries
 * it, except a manifest or an envelope whose object landed: that name is
 * never written over, so a run that failed after its manifest went up is
 * followed by a new chain, not a retry; a key signed again while its row holds a multipart upload id has that
 * upload aborted at the provider first, so no upload is orphaned by a retry.
 * The figure on the tenant row is the sum of completed rows. The prune pass
 * reconciles the ledger against a listing.
 *
 * Every link is signed in a STORAGE SPACE, never against "the target in use
 * now" (specs/storage_targets.md §3). A run is taken in the tenant's active
 * space and everything signed for it stays there; a read names a run (its
 * space) or a space of the tenant's (the active one when it names none). A
 * chain whose objects are in a space the tenant has moved away from is not
 * extended: the site starts a new chain in the active space, and the old one
 * stays readable where it is until retention prunes it. A ledger row whose
 * object is gone is kept, with its time and cause.
 *
 * @version 1.7 - write-once: a key a finished run completed is never signed again (sign answers 'exists'); finish
 *                records each object's sha256 and writes the run's ledger file, {space base}ledger/{run id}.json
 *                (F4); the signing and finishing path is shared with Managed nodes (NodeBroker); a manifest or an
 *                envelope is never signed over one already in backup storage, recorded or not; a run that takes
 *                over an adopted row and aborts leaves it as it was; a ledger file that fails is retried daily
 *                (svr_ledger_tried_time); a run's finish and abort run one at a time (an advisory lock), and an abort
 *                leaves a closed run alone
 * @version 1.6 - verifiedRun(): the site reports a finished run's chain verified restorable through it; retention
 *                keeps the newest verified chain and everything newer (specs/storage_targets.md F1)
 * @version 1.5 - a draining space takes no write, even for a run opened before the move; one space that
 *                cannot be read no longer stops shelf_list listing the others
 * @version 1.4 - every link is signed in the run's or the read's storage space; a chain left in a draining
 *                space is not extended; an abort is made against the object's own space and a row is kept,
 *                marked pruned, once its object is gone; unpaid tenants read nothing
 * @version 1.3 - links are signed by S3Signer::presign(), the platform's one presigner
 * @version 1.2 - a taken-over row stays completed at its earlier size until
 *   the new run finishes it; a re-signed key aborts the upload its row held
 */
class ShelfBrokerException extends Exception {}

/**
 * A write asked for a key a finished run already completed. Not a failure of
 * the request's shape: the object is there, at the size and hash recorded.
 */
class ShelfBrokerExistsException extends ShelfBrokerException {
	public $bytes;
	public $sha256;
	public $key;
	public function __construct(string $key, int $bytes, string $sha256) {
		parent::__construct('That object is already in backup storage, and nothing there is written twice.');
		$this->key = $key;
		$this->bytes = $bytes;
		$this->sha256 = $sha256;
	}
	public function answer(): array {
		return array('exists' => true, 'key' => $this->key, 'bytes' => $this->bytes, 'sha256' => $this->sha256);
	}
}

class ShelfBroker {

	/** A space's folder for run ledger files: {space base}ledger/. No run's base key reaches it. */
	const LEDGER_DIR = 'ledger';

	/** How long a signed URL lives. Parts come in batches of this many. */
	const URL_LIFE_SECONDS = 3600;
	const PARTS_PER_BATCH  = 10;

	/** A run left open this long is treated as abandoned by the prune pass. */
	const STALE_RUN_HOURS = 36;

	const OPERATIONS = array('put', 'get', 'multipart_create', 'multipart_parts', 'multipart_complete');

	// ── The tenant's standing ─────────────────────────────────────────────────

	/** The one sentence saying why a tenant cannot read now, or '' while its objects exist. */
	public static function readRefusal(ServiceTenant $row): string {
		if (in_array((string)$row->get('svt_state'), array(ServiceTenant::STATE_UNPAID, ServiceTenant::STATE_PROVISIONING), true)
				&& $row->get('svt_revoked_time') === null && $row->get('svt_paid_until') === null) {
			return 'This site is not enrolled for the getjoinery backup storage, so there is nothing to read.';
		}
		if ($row->get('svt_pruned_time') !== null) {
			return 'The getjoinery backup storage copies of this site were pruned on '
				. substr((string)$row->get('svt_pruned_time'), 0, 10) . '; nothing remains to read.';
		}
		return '';
	}

	/** Backup storage row for this key, or a refusal. */
	public static function tenantFor(int $user_id, int $key_id): ServiceTenant {
		$row = ServiceTenant::forKey($key_id, ServiceTenant::SERVICE_SHELF);
		if ($row === null || (int)$row->get('svt_usr_user_id') !== $user_id) {
			throw new ShelfBrokerException('This site is not enrolled for backup storage.');
		}
		return $row;
	}

	/** The one sentence saying why a tenant cannot write now, or '' when it can. */
	public static function refusal(ServiceTenant $row): string {
		$state = (string)$row->get('svt_state');
		if ($state === ServiceTenant::STATE_RELEASED) {
			return 'The getjoinery backup storage is no longer in use for this site (released).';
		}
		if ($state === ServiceTenant::STATE_SUSPENDED) {
			return (string)$row->get('svt_notice') ?: JoineryServices::suspendedNotice(ServiceTenant::SERVICE_SHELF);
		}
		if (!$row->entitled()) {
			return $row->get('svt_paid_until') === null
				? 'This site is not entitled to the getjoinery backup storage: no paid-through date has been set.'
				: 'This site\'s paid-through date for the getjoinery backup storage has passed.';
		}
		if ($state !== ServiceTenant::STATE_ACTIVE) {
			return 'The getjoinery backup storage is not active for this site (' . $state . ').';
		}
		return '';
	}

	/** The tenant's active space: where its new backups go, or a refusal saying why there is none. */
	private static function writeSpace(ServiceTenant $row): StorageSpace {
		$space = StorageSpace::active_for(StorageSpace::OWNER_TENANT, (int)$row->key);
		if ($space === null) {
			throw new ShelfBrokerException('The operator has no backup storage target for this site yet; nothing can be signed.');
		}
		$target = $space->target();
		if ($target === null || !$target->get('bkt_enabled')) {
			throw new ShelfBrokerException('The operator\'s backup storage for this site is switched off; nothing new can be stored.');
		}
		return $space;
	}

	/**
	 * A space of this tenant to read from: the one named, else the active one.
	 * Draining spaces are read like any other; a retired one holds nothing.
	 */
	private static function readSpace(ServiceTenant $row, int $space_id): StorageSpace {
		$space = StorageSpace::owned(StorageSpace::OWNER_TENANT, (int)$row->key, $space_id);
		if ($space === null || $space->is_retired()) {
			throw new ShelfBrokerException($space_id > 0 ? 'Unknown storage space.'
				: 'This site has no backup storage to read.');
		}
		return $space;
	}

	/** [target, credential, bucket] of a space, or a refusal that names the plane's fault. */
	private static function reach(StorageSpace $space): array {
		try {
			return $space->reach();
		} catch (StorageSpaceException $e) {
			throw new ShelfBrokerException('The operator\'s backup storage cannot be reached: ' . $e->getMessage() . ' Nothing can be signed.');
		}
	}

	// ── begin_run ─────────────────────────────────────────────────────────────

	/**
	 * @param array $artifacts [{name, bytes}], names relative to the base key
	 *                         (chain-…/files-0000.tar.gz.enc).
	 */
	public static function beginRun(ServiceTenant $row, string $profile, string $chain, array $artifacts): array {
		$why = self::refusal($row);
		if ($why !== '') {
			throw new ShelfBrokerException($why);
		}
		$space = self::writeSpace($row);
		list($target, $creds, $bucket) = self::reach($space);
		try {
			$segment = BackupProfile::path_segment($profile);
		} catch (\Throwable $e) {
			throw new ShelfBrokerException($e->getMessage());
		}
		$chain = self::cleanChain($chain);
		if ($chain !== '' && ShelfObject::chainLivesElsewhere($space, $chain)) {
			throw new ShelfBrokerException('The chain ' . $chain . ' is stored where this site\'s backups are being moved away from; '
				. 'it is not extended. Start a new chain: the old one stays readable until retention prunes it.');
		}

		$declared = 0;
		$names = array();
		foreach ($artifacts as $a) {
			$name = self::cleanName((string)($a['name'] ?? ''));
			if ($name === '') {
				throw new ShelfBrokerException('An artifact name is not a key inside the run: ' . (string)($a['name'] ?? ''));
			}
			$bytes = (int)($a['bytes'] ?? 0);
			if ($bytes < 0) {
				throw new ShelfBrokerException('An artifact declares a negative size.');
			}
			$declared += $bytes;
			$names[] = $name;
		}

		$allowance = (int)$row->get('svt_allowance') ?: JoineryServices::allowance(ServiceTenant::SERVICE_SHELF);
		$used = ShelfObject::completedBytes((int)$row->key);
		if ($used + $declared > $allowance) {
			$sentence = 'This run would put backup storage over its allowance: '
				. JoineryServices::formatFigure(ServiceTenant::SERVICE_SHELF, $used) . ' stored plus '
				. JoineryServices::formatFigure(ServiceTenant::SERVICE_SHELF, $declared) . ' declared, of '
				. JoineryServices::formatFigure(ServiceTenant::SERVICE_SHELF, $allowance)
				. '. Local backups continue; the backup storage copy was not taken. Older chains are pruned by retention, '
				. 'or move to your own storage to lift the limit.';
			$row->set('svt_notice', $sentence);
			$row->save();
			throw new ShelfBrokerException($sentence);
		}
		if ((string)$row->get('svt_notice') !== '') {
			$row->set('svt_notice', null);
			$row->save();
		}

		$run = new ShelfRun(NULL);
		$run->set('svr_svt_service_tenant_id', (int)$row->key);
		$run->set('svr_sps_storage_space_id', (int)$space->key);
		$run->set('svr_profile', $segment);
		$run->set('svr_chain', $chain);
		$run->set('svr_base_key', $space->base() . $segment . '/');
		$run->set('svr_declared_bytes', $declared);
		$run->set('svr_declared_names', json_encode($names));
		$run->set('svr_state', ShelfRun::STATE_OPEN);
		$run->save();

		return array(
			'run_id'    => (int)$run->key,
			'space_id'  => (int)$space->key,
			'base_key'  => (string)$run->get('svr_base_key'),
			'bucket'    => $bucket,
			'allowance' => $allowance,
			'used'      => $used,
		);
	}

	// ── sign ──────────────────────────────────────────────────────────────────

	/**
	 * @param int   $run_id A write's open run. For a get, 0 names the key
	 *                      relative to a space's base key (args space_id, the
	 *                      active space when absent); a run id names it relative
	 *                      to that run's base key, open or not, in its space.
	 * @param array $args   For multipart_parts: upload_id, first (1-based),
	 *                      count (≤ PARTS_PER_BATCH). For multipart_complete:
	 *                      upload_id. For a get: space_id.
	 */
	public static function sign(ServiceTenant $row, int $run_id, string $name, string $operation, array $args = array()): array {
		if (!in_array($operation, self::OPERATIONS, true)) {
			throw new ShelfBrokerException('The backup storage broker does not sign "' . $operation . '".');
		}
		$name = self::cleanName($name);
		if ($name === '') {
			throw new ShelfBrokerException('That is not a key inside the run.');
		}
		$expires = self::URL_LIFE_SECONDS;
		$expires_at = gmdate('Y-m-d H:i:s', time() + $expires);

		if ($operation === 'get') {
			$why = self::readRefusal($row);
			if ($why !== '') {
				throw new ShelfBrokerException($why);
			}
			if ($run_id > 0) {
				$run = self::runOf($row, $run_id);
				$space = self::runSpace($run);
				$key = (string)$run->get('svr_base_key') . $name;
			} else {
				$space = self::readSpace($row, (int)($args['space_id'] ?? 0));
				$key = $space->base() . $name;
			}
			list($target, $creds, $bucket) = self::reach($space);
			return array('url' => S3Signer::presign($creds, $bucket, $key, 'GET', array(), $expires), 'key' => $key, 'expires_at' => $expires_at);
		}

		$why = self::refusal($row);
		if ($why !== '') {
			throw new ShelfBrokerException($why);
		}
		try {
			return self::signWrite(self::openRun($row, $run_id), $name, $operation, $args);
		} catch (ShelfBrokerExistsException $e) {
			return $e->answer();
		}
	}

	/**
	 * Sign one write of an open run, whoever owns it: the run's owner has
	 * already been recognised and its standing checked. The key is the run's
	 * base key plus $name; a key a finished run completed is refused with
	 * ShelfBrokerExistsException, which carries what is there.
	 *
	 * @param array $args as sign()'s
	 */
	public static function signWrite(ShelfRun $run, string $name, string $operation, array $args = array()): array {
		if (!in_array($operation, self::OPERATIONS, true) || $operation === 'get') {
			throw new ShelfBrokerException('The backup storage broker does not sign a "' . $operation . '" write.');
		}
		if ((string)$run->get('svr_state') !== ShelfRun::STATE_OPEN) {
			throw new ShelfBrokerException('That run is already ' . $run->get('svr_state') . '.');
		}
		$name = self::cleanName($name);
		if ($name === '') {
			throw new ShelfBrokerException('That is not a key inside the run.');
		}
		$expires = self::URL_LIFE_SECONDS;
		$expires_at = gmdate('Y-m-d H:i:s', time() + $expires);
		$key = (string)$run->get('svr_base_key') . $name;
		$space = self::runSpace($run);
		if (!$space->is_active()) {
			// Moved while the run was open: a space that is draining takes
			// nothing new. The run is started again, in the new space.
			throw new ShelfBrokerException('These backups were moved to another target while this run was open; '
				. 'nothing more is stored in the old place. Start the run again.');
		}
		$chain = self::chainOfName($name, (string)$run->get('svr_chain'));
		if ($chain !== '' && ShelfObject::chainLivesElsewhere($space, $chain)) {
			throw new ShelfBrokerException('The chain ' . $chain . ' is stored where these backups are being moved away from; '
				. 'it is not extended. Start a new chain: the old one stays readable until retention prunes it.');
		}
		list($target, $creds, $bucket) = self::reach($space);

		switch ($operation) {
			case 'put':
				self::ledgerSigned($run, $key, $chain, (int)($args['bytes'] ?? 0), null, $creds, $bucket);
				return array('url' => S3Signer::presign($creds, $bucket, $key, 'PUT', array(), $expires), 'key' => $key, 'expires_at' => $expires_at);

			case 'multipart_create':
				self::ledgerSigned($run, $key, $chain, (int)($args['bytes'] ?? 0), null, $creds, $bucket);
				return array('url' => S3Signer::presign($creds, $bucket, $key, 'POST', array('uploads' => ''), $expires), 'key' => $key, 'expires_at' => $expires_at);

			case 'multipart_parts':
				$upload_id = self::uploadId($args);
				$first = max(1, (int)($args['first'] ?? 1));
				$count = (int)($args['count'] ?? self::PARTS_PER_BATCH);
				if ($count < 1 || $count > self::PARTS_PER_BATCH) {
					throw new ShelfBrokerException('Parts are signed in batches of up to ' . self::PARTS_PER_BATCH . '.');
				}
				if ($first + $count - 1 > 10000) {
					throw new ShelfBrokerException('A multipart upload has at most 10,000 parts.');
				}
				self::ledgerUploadId($run, $key, $upload_id);
				$urls = array();
				for ($n = $first; $n < $first + $count; $n++) {
					$urls[$n] = S3Signer::presign($creds, $bucket, $key, 'PUT', array('partNumber' => (string)$n, 'uploadId' => $upload_id), $expires);
				}
				return array('urls' => $urls, 'key' => $key, 'expires_at' => $expires_at);

			case 'multipart_complete':
				$upload_id = self::uploadId($args);
				self::ledgerUploadId($run, $key, $upload_id);
				return array('url' => S3Signer::presign($creds, $bucket, $key, 'POST', array('uploadId' => $upload_id), $expires), 'key' => $key, 'expires_at' => $expires_at);
		}
		throw new ShelfBrokerException('Unreachable.');
	}

	/**
	 * The chain a name belongs to: its first segment when that is a chain's
	 * folder (chain-…/files-0003.tar.gz.enc), else the run's chain ('' for a
	 * name outside any chain: a standalone archive, an offloaded file).
	 */
	public static function chainOfName(string $name, string $run_chain = ''): string {
		$first = strtok($name, '/');
		if ($first !== false && $first !== $name && strpos($first, BackupChain::DIR_PREFIX) === 0) {
			return self::cleanChain($first);
		}
		return self::cleanChain($run_chain);
	}

	// ── list ──────────────────────────────────────────────────────────────────

	/**
	 * List inside the tenant's own spaces. $sub is relative to a space's base
	 * key ('' for all). Every live space is listed, the active one first, so
	 * backups kept where the tenant moved away from stay visible until they
	 * age out. Answers [{key, size, last_modified, space_id}] with keys
	 * relative to their space's base key, so the site sees the same layout it
	 * would in its own bucket; prefix is the active space's.
	 */
	public static function listPrefix(ServiceTenant $row, string $sub = ''): array {
		$why = self::readRefusal($row);
		if ($why !== '') {
			throw new ShelfBrokerException($why);
		}
		$sub = ltrim(trim($sub), '/');
		if ($sub !== '' && self::cleanName(rtrim($sub, '/')) === '') {
			throw new ShelfBrokerException('That is not a prefix inside this site\'s backup storage.');
		}
		$out = array();
		$spaces = array();
		$prefix = '';
		foreach (StorageSpace::of_owner(StorageSpace::OWNER_TENANT, (int)$row->key) as $space) {
			$base = $space->base();
			if ($space->is_active()) {
				$prefix = $base;
			}
			// One space that cannot be reached is said, and the others still list.
			try {
				list($target, $creds, $bucket) = $space->reach();
				$listing = S3Signer::list($creds, $bucket, $base . $sub);
			} catch (\Throwable $e) {
				$spaces[] = array('space_id' => (int)$space->key, 'state' => (string)$space->get('sps_state'),
					'target' => '', 'error' => 'This part of backup storage cannot be read right now.');
				error_log('ShelfBroker: listing ' . $space->describe() . ' failed: ' . $e->getMessage());
				continue;
			}
			$spaces[] = array('space_id' => (int)$space->key, 'state' => (string)$space->get('sps_state'),
				'target' => (string)$target->get('bkt_name'));
			foreach ($listing as $object) {
				$key = (string)($object['key'] ?? '');
				if (strpos($key, $base) !== 0) {
					continue; // the listing is scoped, but the boundary is asserted, not assumed
				}
				$out[] = array(
					'key'           => substr($key, strlen($base)),
					'size'          => (int)($object['size'] ?? 0),
					'last_modified' => (string)($object['last_modified'] ?? ''),
					'space_id'      => (int)$space->key,
				);
			}
		}
		return array('prefix' => $prefix, 'spaces' => $spaces, 'objects' => $out);
	}

	// ── finish_run ────────────────────────────────────────────────────────────

	/**
	 * @param array $completed [{name, bytes}] the objects the site finished,
	 *                         names relative to the base key; whatever else
	 *                         the run signed is cancelled here and now — an
	 *                         open multipart aborted at the provider, a row
	 *                         never completed dropped — so a finished run's
	 *                         ledger is exact.
	 */
	public static function finishRun(ServiceTenant $row, int $run_id, array $completed): array {
		$out = self::completeRun(self::openRun($row, $run_id), $completed);
		self::refreshFigure($row);
		return $out + array('figure' => (int)$row->get('svt_figure'));
	}

	/**
	 * Close an open run, whoever owns it. Each completed object is named
	 * relative to the run's base key with its bytes and sha256; one never
	 * signed for this run, or named without a well-formed hash, is not
	 * completed. Everything else the run signed is cancelled. The run's ledger
	 * file then goes to backup storage (writeLedgerFile).
	 *
	 * @param array $completed [{name, bytes, sha256}]
	 */
	public static function completeRun(ShelfRun $run, array $completed, string $chain = ''): array {
		// One finish at a time per run: a finish asked again while the first is
		// still working waits for it, then finds the run finished.
		$db = DbConnector::get_instance()->get_db_link();
		$db->prepare('SELECT pg_advisory_lock(:k1, :k2)')->execute(array(':k1' => self::FINISH_LOCK_SPACE, ':k2' => (int)$run->key));
		try {
			$run = new ShelfRun((int)$run->key, TRUE);
			if ((string)$run->get('svr_state') !== ShelfRun::STATE_OPEN) {
				throw new ShelfBrokerException('That run is already ' . $run->get('svr_state') . '.');
			}
			return self::completeLockedRun($run, $completed, $chain);
		} finally {
			$db->prepare('SELECT pg_advisory_unlock(:k1, :k2)')->execute(array(':k1' => self::FINISH_LOCK_SPACE, ':k2' => (int)$run->key));
		}
	}

	/** The advisory lock space a run's finish is serialised in. */
	const FINISH_LOCK_SPACE = 7301;

	private static function completeLockedRun(ShelfRun $run, array $completed, string $chain): array {
		$now = gmdate('Y-m-d H:i:s');
		$marked = 0;
		$unhashed = 0;
		foreach ($completed as $c) {
			$name = self::cleanName((string)($c['name'] ?? ''));
			if ($name === '') { continue; }
			$sha256 = strtolower(trim((string)($c['sha256'] ?? '')));
			if (!preg_match('/^[0-9a-f]{64}$/', $sha256)) {
				$unhashed++;
				continue; // a completion vouches for bytes only with their hash
			}
			$key = (string)$run->get('svr_base_key') . $name;
			$object = ShelfObject::forKey((int)$run->get('svr_sps_storage_space_id'), $key);
			if ($object === null || (int)$object->get('svo_svr_shelf_run_id') !== (int)$run->key
					|| (string)$object->get('svo_sha256') !== '') {
				continue; // never signed for this run, or completed with its hash already: not this run's to complete
			}
			$object->set('svo_bytes', max(0, (int)($c['bytes'] ?? 0)));
			$object->set('svo_sha256', $sha256);
			$object->set('svo_completed_time', $now);
			$object->set('svo_upload_id', null);
			$object->save();
			$marked++;
		}
		$cancelled = self::cancelUncompleted($run);
		if ($chain !== '') {
			$run->set('svr_chain', self::cleanChain($chain));
		}
		$run->set('svr_state', ShelfRun::STATE_FINISHED);
		$run->set('svr_finish_time', $now);
		$run->save();
		self::writeLedgerFile($run);
		return array('run_id' => (int)$run->key, 'completed' => $marked, 'cancelled' => $cancelled, 'unhashed' => $unhashed);
	}

	/** Where a run's ledger file goes: {space base}ledger/{run id}.json, outside every run's base key. */
	public static function ledgerKey(ShelfRun $run): string {
		return self::runSpace($run)->base() . self::LEDGER_DIR . '/' . (int)$run->key . '.json';
	}

	/**
	 * The run's ledger file (specs/storage_targets.md F4): every object it
	 * completed, with key, bytes and sha256, and the run's time, kind,
	 * profile and chain. Written with the plane's credential to a key no run
	 * can be signed for, so what vouches for a run's bytes is in the bucket as
	 * well as in this database. True when written; a failure is logged and
	 * svr_ledger_time stays empty, and writeMissingLedgerFiles() tries again.
	 */
	public static function writeLedgerFile(ShelfRun $run): bool {
		try {
			$space = self::runSpace($run);
			list($target, $creds, $bucket) = $space->reach();
			$objects = array();
			foreach (new MultiShelfObject(array('run_id' => (int)$run->key, 'completed' => true, 'deleted' => false),
					array('svo_key' => 'ASC')) as $o) {
				$objects[] = array('key' => (string)$o->get('svo_key'), 'bytes' => (int)$o->get('svo_bytes'),
					'sha256' => (string)$o->get('svo_sha256'));
			}
			$body = json_encode(array(
				'version'  => 1,
				'run_id'   => (int)$run->key,
				'kind'     => (string)$run->get('svr_kind'),
				'owner'    => $space->owner_name(),
				'profile'  => (string)$run->get('svr_profile'),
				'chain'    => (string)$run->get('svr_chain'),
				'started'  => (string)$run->get('svr_create_time'),
				'finished' => (string)$run->get('svr_finish_time'),
				'objects'  => $objects,
			), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
			$tmp = tempnam(sys_get_temp_dir(), 'jy_ledger_');
			try {
				file_put_contents($tmp, $body);
				$resp = S3Signer::put_file($creds, $bucket, '/' . self::ledgerKey($run), $tmp, 'application/json');
			} finally {
				@unlink($tmp);
			}
			$status = (int)($resp['status'] ?? 0);
			if ($status < 200 || $status >= 300) {
				throw new ShelfBrokerException('HTTP ' . $status . ' ' . S3Signer::extract_error($resp['body'] ?? ''));
			}
		} catch (\Throwable $e) {
			error_log('ShelfBroker: the ledger file of run ' . (int)$run->key . ' could not be written: ' . $e->getMessage());
			$run->set('svr_ledger_problem', mb_substr($e->getMessage(), 0, 1000));
			$run->set('svr_ledger_tried_time', gmdate('Y-m-d H:i:s'));
			$run->save();
			return false;
		}
		$run->set('svr_ledger_time', gmdate('Y-m-d H:i:s'));
		$run->set('svr_ledger_problem', null);
		$run->save();
		return true;
	}

	/**
	 * Write the ledger file of every finished run that has none yet and is due
	 * a try: one that failed is tried again a day later, so a run whose space
	 * cannot be reached never holds back newer ones. Answers what was written,
	 * and how many runs still have none with the newest reason, for the pass
	 * to report.
	 *
	 * @return array{written:int, stuck:int, problem:string}
	 */
	public static function writeMissingLedgerFiles(int $limit = 50): array {
		$written = 0;
		$runs = new MultiShelfRun(array('state' => ShelfRun::STATE_FINISHED, 'ledger_written' => false, 'ledger_due' => true,
			'deleted' => false), array('svr_shelf_run_id' => 'ASC'), $limit);
		foreach ($runs as $run) {
			if (self::writeLedgerFile($run)) {
				$written++;
			}
		}
		$stuck = new MultiShelfRun(array('state' => ShelfRun::STATE_FINISHED, 'ledger_written' => false, 'ledger_failed' => true,
			'deleted' => false), array('svr_update_time' => 'DESC'));
		$problem = '';
		foreach ($stuck as $run) {
			$problem = (string)$run->get('svr_ledger_problem');
			break;
		}
		return array('written' => $written, 'stuck' => count($stuck), 'problem' => $problem);
	}

	/**
	 * The site opened and read its chain through this finished run, and it
	 * passed. Stamped on the run, never on an open or aborted one: retention
	 * keeps the customer's newest verified chain and everything newer.
	 */
	public static function verifiedRun(ServiceTenant $row, int $run_id): array {
		$run = new ShelfRun($run_id, TRUE);
		if (!$run->key || (int)$run->get('svr_svt_service_tenant_id') !== (int)$row->key || $run->get('svr_delete_time')) {
			throw new ShelfBrokerException('No such run.');
		}
		if ((string)$run->get('svr_state') !== ShelfRun::STATE_FINISHED) {
			throw new ShelfBrokerException('Only a finished run can be verified.');
		}
		$now = gmdate('Y-m-d H:i:s');
		$run->set('svr_verified_time', $now);
		$run->save();
		return array('run_id' => (int)$run->key, 'chain' => (string)$run->get('svr_chain'), 'verified_time' => $now);
	}

	/** The tenant's figure is the ledger's completed bytes. */
	public static function refreshFigure(ServiceTenant $row): int {
		$bytes = ShelfObject::completedBytes((int)$row->key);
		$row->set('svt_figure', $bytes);
		$row->set('svt_figure_time', gmdate('Y-m-d H:i:s'));
		$row->save();
		return $bytes;
	}

	// ── The plane's own side: abort and prune ─────────────────────────────────

	/**
	 * Abort a run the site never finished: every object signed for it that
	 * was never completed is dropped from the ledger, and an open multipart
	 * upload is aborted at the provider with the plane's credential.
	 */
	public static function abortRun(ShelfRun $run, string $cause): int {
		// Under the finish lock: an abort never lands in the middle of a
		// finish, and a run the finish closed is left as it was.
		$db = DbConnector::get_instance()->get_db_link();
		$db->prepare('SELECT pg_advisory_lock(:k1, :k2)')->execute(array(':k1' => self::FINISH_LOCK_SPACE, ':k2' => (int)$run->key));
		try {
			$run = new ShelfRun((int)$run->key, TRUE);
			if ((string)$run->get('svr_state') !== ShelfRun::STATE_OPEN) {
				return 0;
			}
			$dropped = self::cancelUncompleted($run);
			$run->set('svr_state', ShelfRun::STATE_ABORTED);
			$run->set('svr_finish_time', gmdate('Y-m-d H:i:s'));
			$run->set('svr_cause', $cause);
			$run->save();
			return $dropped;
		} finally {
			$db->prepare('SELECT pg_advisory_unlock(:k1, :k2)')->execute(array(':k1' => self::FINISH_LOCK_SPACE, ':k2' => (int)$run->key));
		}
	}

	/**
	 * Everything the run signed but did not finish is cancelled: a row never
	 * completed is marked pruned (its open upload aborted first); a row an
	 * earlier run completed keeps its place and its size, and only the upload
	 * this run opened on it is aborted. The number cancelled.
	 */
	private static function cancelUncompleted(ShelfRun $run): int {
		$dropped = 0;
		$objects = new MultiShelfObject(array('run_id' => (int)$run->key, 'pruned' => false, 'deleted' => false));
		foreach ($objects as $object) {
			if ($object->get('svo_completed_time') === null) {
				if (self::abortObject($object, ShelfObject::PRUNED_ABORT)) {
					$dropped++;
				}
			} elseif (self::abortUpload($object)) {
				$object->set('svo_upload_id', null);
				$object->save();
			}
		}
		return $dropped;
	}

	/**
	 * One object that is not (or no longer) in backup storage leaves the live
	 * count: an open multipart is aborted at the provider, then the row is
	 * marked pruned with the cause, and kept. When the abort could not be
	 * made, the row keeps its upload id and stays live, so the next pass
	 * tries again; false then.
	 */
	public static function abortObject(ShelfObject $object, string $cause, ?string $now = null): bool {
		if (!self::abortUpload($object, true)) {
			return false;
		}
		$object->markPruned($cause, $now);
		return true;
	}

	/**
	 * The multipart upload a row holds, if any, is aborted at the provider
	 * with the plane's credential for the row's own space. True when there
	 * was one and it is gone. A failure is logged; with $strict it answers
	 * false (the row keeps its id for a later pass), otherwise true, since
	 * the provider expires an abandoned upload on its own.
	 */
	private static function abortUpload(ShelfObject $object, bool $strict = false): bool {
		$upload_id = trim((string)$object->get('svo_upload_id'));
		if ($upload_id === '') {
			return $strict;   // nothing open: an object may be marked gone; nothing was aborted
		}
		try {
			$space = new StorageSpace((int)$object->get('svo_sps_storage_space_id'), TRUE);
			if (!$space->key) {
				throw new ShelfBrokerException('the row records no storage space');
			}
			list($target, $creds, $bucket) = $space->reach();
			$resp = S3Signer::abort_upload($creds, $bucket, '/' . ltrim((string)$object->get('svo_key'), '/'), $upload_id);
			$status = (int)($resp['status'] ?? 0);
			if (($status < 200 || $status >= 300) && $status !== 404) {
				throw new ShelfBrokerException('HTTP ' . $status);
			}
		} catch (\Throwable $e) {
			error_log('ShelfBroker: abort of ' . $object->get('svo_key') . ' failed: ' . $e->getMessage());
			return !$strict;
		}
		return true;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/** A run of this tenant, whatever its state. */
	private static function runOf(ServiceTenant $row, int $run_id): ShelfRun {
		if ($run_id <= 0) {
			throw new ShelfBrokerException('No run id.');
		}
		$run = new ShelfRun($run_id, TRUE);
		if (!$run->key || (int)$run->get('svr_svt_service_tenant_id') !== (int)$row->key || $run->get('svr_delete_time')) {
			throw new ShelfBrokerException('Unknown run.');
		}
		return $run;
	}

	/** The space a run was taken in, or a refusal. */
	private static function runSpace(ShelfRun $run): StorageSpace {
		try {
			return $run->space();
		} catch (ShelfRunException $e) {
			throw new ShelfBrokerException($e->getMessage());
		}
	}

	private static function openRun(ServiceTenant $row, int $run_id): ShelfRun {
		$run = self::runOf($row, $run_id);
		if ((string)$run->get('svr_state') !== ShelfRun::STATE_OPEN) {
			throw new ShelfBrokerException('That run is already ' . $run->get('svr_state') . '.');
		}
		return $run;
	}

	/**
	 * One live ledger row per space and key, and the rule that nothing in
	 * backup storage is written twice:
	 *
	 *   - a key a finished run completed with its hash is never signed again
	 *     (ShelfBrokerExistsException, carrying what is there);
	 *   - a name that vouches for others — a chain manifest, an epoch envelope —
	 *     is never signed over an object already there: one the reconcile
	 *     adopted (no hash) is refused, and one with no row at all (written
	 *     before runs went through the broker) is looked for in the bucket
	 *     first. No run legitimately rewrites either: a run writes its own
	 *     manifest name, and a re-sealed envelope its own name;
	 *   - any other row is this run's to complete from here: one never
	 *     completed (a failed run's, so its retry is never refused), or one
	 *     the reconcile adopted — that one stays completed at its adopted size
	 *     until this run finishes it, so an abort leaves it as it was.
	 *
	 * An upload the row still holds is aborted at the provider before its id is
	 * let go.
	 */
	private static function ledgerSigned(ShelfRun $run, string $key, string $chain, int $bytes, ?string $upload_id, array $creds, string $bucket): void {
		$object = ShelfObject::forKey((int)$run->get('svr_sps_storage_space_id'), $key);
		if ($object !== null && $object->get('svo_completed_time') !== null && (string)$object->get('svo_sha256') !== '') {
			throw new ShelfBrokerExistsException($key, (int)$object->get('svo_bytes'), (string)$object->get('svo_sha256'));
		}
		if (self::vouches(basename($key))) {
			if ($object !== null && $object->get('svo_completed_time') !== null) {
				throw new ShelfBrokerExistsException($key, (int)$object->get('svo_bytes'), '');
			}
			if ($object === null) {
				$there = self::headObject($creds, $bucket, $key);
				if ($there !== null) {
					throw new ShelfBrokerExistsException($key, $there, '');
				}
			}
		}
		if ($object === null) {
			$object = new ShelfObject(NULL);
			$object->set('svo_sps_storage_space_id', (int)$run->get('svr_sps_storage_space_id'));
			$object->set('svo_key', $key);
		} else {
			self::abortUpload($object);
		}
		if ((int)$run->get('svr_svt_service_tenant_id')) {
			$object->set('svo_svt_service_tenant_id', (int)$run->get('svr_svt_service_tenant_id'));
		}
		$object->set('svo_svr_shelf_run_id', (int)$run->key);
		if ($object->get('svo_completed_time') === null) {
			$object->set('svo_bytes', max(0, $bytes));
		}
		$object->set('svo_chain', $chain);
		$object->set('svo_upload_id', $upload_id);
		$object->set('svo_signed_time', gmdate('Y-m-d H:i:s'));
		$object->save();
	}

	/** A name other objects are checked against: a chain manifest, or an epoch envelope. */
	public static function vouches(string $name): bool {
		return BackupChain::is_manifest_name($name) || BackupObjects::is_envelope_name($name);
	}

	/**
	 * The size of the object at $key when backup storage holds one, else null.
	 * A provider that does not answer is a refusal: signing over an object
	 * nobody could look for is the one thing this check exists to prevent.
	 */
	private static function headObject(array $creds, string $bucket, string $key): ?int {
		try {
			$resp = S3Signer::head($creds, $bucket, '/' . ltrim($key, '/'));
		} catch (\Throwable $e) {
			throw new ShelfBrokerException('Backup storage could not say whether ' . basename($key) . ' is already there: ' . $e->getMessage());
		}
		$status = (int)($resp['status'] ?? 0);
		if ($status === 404) {
			return null;
		}
		if ($status >= 200 && $status < 300) {
			return (int)($resp['headers']['content-length'] ?? 0);
		}
		throw new ShelfBrokerException('Backup storage could not say whether ' . basename($key) . ' is already there (HTTP ' . $status . ').');
	}

	/** A part or complete URL names an upload this run signed for the key. */
	private static function ledgerUploadId(ShelfRun $run, string $key, string $upload_id): void {
		$object = ShelfObject::forKey((int)$run->get('svr_sps_storage_space_id'), $key);
		if ($object === null || (int)$object->get('svo_svr_shelf_run_id') !== (int)$run->key) {
			throw new ShelfBrokerException('No open upload was signed for that key.');
		}
		if (trim((string)$object->get('svo_upload_id')) !== $upload_id) {
			$object->set('svo_upload_id', $upload_id);
			$object->save();
		}
	}

	private static function uploadId(array $args): string {
		$upload_id = trim((string)($args['upload_id'] ?? ''));
		if ($upload_id === '' || strlen($upload_id) > 255 || preg_match('/[\s"<>]/', $upload_id)) {
			throw new ShelfBrokerException('The multipart upload id is missing or malformed.');
		}
		return $upload_id;
	}

	/**
	 * A name relative to the run's base key: no leading slash, no empty or
	 * dot segments, nothing that could climb out. '' when it is not one.
	 */
	public static function cleanName(string $name): string {
		$name = trim($name);
		if ($name === '' || strlen($name) > 900 || $name[0] === '/' || strpos($name, '\\') !== false
				|| preg_match('/[\x00-\x1f\x7f]/', $name)) {
			return '';
		}
		foreach (explode('/', $name) as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				return '';
			}
		}
		return $name;
	}

	public static function cleanChain(string $chain): string {
		$chain = trim($chain);
		return preg_match('/^[A-Za-z0-9._-]{0,64}$/', $chain) ? $chain : '';
	}
}
