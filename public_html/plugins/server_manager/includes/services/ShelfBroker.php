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
 *                base key. A get needs no run: the key is inside the tenant's
 *                prefix (the layout list answers), or inside a run's base key
 *                when one is named. Never a delete.
 *   list         a prefix inside the tenant's own; answers from inside it only.
 *   finish_run   closes the run; the ledger marks the named objects complete
 *                and cancels everything else signed for the run — an open
 *                multipart is aborted at the provider with the plane's
 *                credential before its row goes.
 *   status       the C2 fields.
 *
 * Reads and writes have different standing. A write needs a usable tenant:
 * active, date ahead, inside the allowance. A read (list, get) is allowed to
 * a suspended or released tenant for as long as its objects exist — the
 * retention promise is that the copies stay readable — and is refused only
 * once svt_pruned_time is set.
 *
 * Every write signed is a ledger row: one per tenant and key (tenant, run,
 * key, bytes, chain, signed time; completed time once the site says so). A
 * key signed again by a later run — chain-x/manifest.json on every
 * incremental — moves its row to that run, so the figure counts the object
 * once. A row completed by an earlier run stays completed, at the size that
 * run finished it, until the later run names it done: the object is on the
 * shelf whatever the later run does, so the figure keeps counting it, and a
 * cancel drops only rows never completed. A key signed again while its row
 * holds a multipart upload id has that upload aborted at the provider first;
 * no upload is orphaned by a retry. The figure on the tenant row is the sum
 * of completed rows. The prune pass reconciles the ledger against a listing.
 *
 * @version 1.2 - a taken-over row stays completed at its earlier size until
 *   the new run finishes it; a re-signed key aborts the upload its row held
 */
class ShelfBrokerException extends Exception {}

class ShelfBroker {

	/** How long a signed URL lives. Parts come in batches of this many. */
	const URL_LIFE_SECONDS = 3600;
	const PARTS_PER_BATCH  = 10;

	/** A run left open this long is treated as abandoned by the prune pass. */
	const STALE_RUN_HOURS = 36;

	const OPERATIONS = array('put', 'get', 'multipart_create', 'multipart_parts', 'multipart_complete');

	// ── The tenant's standing ─────────────────────────────────────────────────

	/** The one sentence saying why a tenant cannot read now, or '' while its objects exist. */
	public static function readRefusal(ServiceTenant $row): string {
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

	/** The plane's backup storage target and its credential, or a refusal that names the plane's fault. */
	private static function shelf(): array {
		$target = JoineryServices::shelfTarget();
		if ($target === null) {
			throw new ShelfBrokerException('The operator has no backup storage target configured; nothing can be signed.');
		}
		try {
			$creds = (array)$target->get_credentials();
		} catch (\Throwable $e) {
			throw new ShelfBrokerException('The operator\'s backup storage credential cannot be read; nothing can be signed.');
		}
		$bucket = trim((string)$target->get('bkt_bucket'));
		if ($bucket === '' || empty($creds['access_key']) || empty($creds['secret_key'])) {
			throw new ShelfBrokerException('The operator\'s backup storage target is incomplete; nothing can be signed.');
		}
		return array($target, $creds, $bucket);
	}

	/** {path_prefix}/{slug}/ — everything the tenant owns is under it. */
	public static function tenantPrefix(ServiceTenant $row, BackupTarget $target): string {
		return JoineryServices::shelfPathPrefix($target) . '/' . (string)$row->get('svt_slug') . '/';
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
		list($target, $creds, $bucket) = self::shelf();
		try {
			$segment = BackupProfile::path_segment($profile);
		} catch (\Throwable $e) {
			throw new ShelfBrokerException($e->getMessage());
		}
		$chain = self::cleanChain($chain);

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
		$run->set('svr_profile', $segment);
		$run->set('svr_chain', $chain);
		$run->set('svr_base_key', self::tenantPrefix($row, $target) . $segment . '/');
		$run->set('svr_declared_bytes', $declared);
		$run->set('svr_declared_names', json_encode($names));
		$run->set('svr_state', ShelfRun::STATE_OPEN);
		$run->save();

		return array(
			'run_id'    => (int)$run->key,
			'base_key'  => (string)$run->get('svr_base_key'),
			'bucket'    => $bucket,
			'allowance' => $allowance,
			'used'      => $used,
		);
	}

	// ── sign ──────────────────────────────────────────────────────────────────

	/**
	 * @param int   $run_id A write's open run. For a get, 0 names the key
	 *                      relative to the tenant's prefix; a run id names it
	 *                      relative to that run's base key, open or not.
	 * @param array $args   For multipart_parts: upload_id, first (1-based),
	 *                      count (≤ PARTS_PER_BATCH). For multipart_complete:
	 *                      upload_id.
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
			list($target, $creds, $bucket) = self::shelf();
			$key = ($run_id > 0 ? (string)self::runOf($row, $run_id)->get('svr_base_key') : self::tenantPrefix($row, $target)) . $name;
			return array('url' => ShelfPresigner::get($creds, $bucket, $key, $expires), 'key' => $key, 'expires_at' => $expires_at);
		}

		$why = self::refusal($row);
		if ($why !== '') {
			throw new ShelfBrokerException($why);
		}
		$run = self::openRun($row, $run_id);
		$key = (string)$run->get('svr_base_key') . $name;
		list($target, $creds, $bucket) = self::shelf();

		switch ($operation) {
			case 'put':
				self::ledgerSigned($row, $run, $key, (int)($args['bytes'] ?? 0), null);
				return array('url' => ShelfPresigner::put($creds, $bucket, $key, $expires), 'key' => $key, 'expires_at' => $expires_at);

			case 'multipart_create':
				self::ledgerSigned($row, $run, $key, (int)($args['bytes'] ?? 0), null);
				return array('url' => ShelfPresigner::multipartCreate($creds, $bucket, $key, $expires), 'key' => $key, 'expires_at' => $expires_at);

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
				self::ledgerUploadId($row, $run, $key, $upload_id);
				$urls = array();
				for ($n = $first; $n < $first + $count; $n++) {
					$urls[$n] = ShelfPresigner::multipartPart($creds, $bucket, $key, $upload_id, $n, $expires);
				}
				return array('urls' => $urls, 'key' => $key, 'expires_at' => $expires_at);

			case 'multipart_complete':
				$upload_id = self::uploadId($args);
				self::ledgerUploadId($row, $run, $key, $upload_id);
				return array('url' => ShelfPresigner::multipartComplete($creds, $bucket, $key, $upload_id, $expires), 'key' => $key, 'expires_at' => $expires_at);
		}
		throw new ShelfBrokerException('Unreachable.');
	}

	// ── list ──────────────────────────────────────────────────────────────────

	/**
	 * List inside the tenant's own prefix. $sub is relative to it ('' for all).
	 * Answers [{key, size, last_modified}] with keys relative to the tenant
	 * prefix, so the site sees the same layout it would in its own bucket.
	 */
	public static function listPrefix(ServiceTenant $row, string $sub = ''): array {
		$why = self::readRefusal($row);
		if ($why !== '') {
			throw new ShelfBrokerException($why);
		}
		list($target, $creds, $bucket) = self::shelf();
		$base = self::tenantPrefix($row, $target);
		$sub = ltrim(trim($sub), '/');
		if ($sub !== '' && self::cleanName(rtrim($sub, '/')) === '') {
			throw new ShelfBrokerException('That is not a prefix inside this site\'s backup storage.');
		}
		$objects = S3Signer::list($creds, $bucket, $base . $sub);
		$out = array();
		foreach ($objects as $object) {
			$key = (string)($object['key'] ?? '');
			if (strpos($key, $base) !== 0) {
				continue; // the listing is scoped, but the boundary is asserted, not assumed
			}
			$out[] = array(
				'key'           => substr($key, strlen($base)),
				'size'          => (int)($object['size'] ?? 0),
				'last_modified' => (string)($object['last_modified'] ?? ''),
			);
		}
		return array('prefix' => $base, 'objects' => $out);
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
		$run = self::openRun($row, $run_id);
		$now = gmdate('Y-m-d H:i:s');
		$marked = 0;
		foreach ($completed as $c) {
			$name = self::cleanName((string)($c['name'] ?? ''));
			if ($name === '') { continue; }
			$key = (string)$run->get('svr_base_key') . $name;
			$object = ShelfObject::forKey((int)$row->key, $key);
			if ($object === null || (int)$object->get('svo_svr_shelf_run_id') !== (int)$run->key) {
				continue; // never signed for this run: not the ledger's to complete
			}
			if (isset($c['bytes'])) {
				$object->set('svo_bytes', max(0, (int)$c['bytes']));
			}
			$object->set('svo_completed_time', $now);
			$object->set('svo_upload_id', null);
			$object->save();
			$marked++;
		}
		$cancelled = self::cancelUncompleted($run);
		$run->set('svr_state', ShelfRun::STATE_FINISHED);
		$run->set('svr_finish_time', $now);
		$run->save();
		self::refreshFigure($row);
		return array('run_id' => (int)$run->key, 'completed' => $marked, 'cancelled' => $cancelled,
			'figure' => (int)$row->get('svt_figure'));
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
		$dropped = self::cancelUncompleted($run);
		$run->set('svr_state', ShelfRun::STATE_ABORTED);
		$run->set('svr_finish_time', gmdate('Y-m-d H:i:s'));
		$run->set('svr_cause', $cause);
		$run->save();
		return $dropped;
	}

	/**
	 * Everything the run signed but did not finish is cancelled: a row never
	 * completed is dropped (its open upload aborted first); a row an earlier
	 * run completed keeps its place and its size, and only the upload this
	 * run opened on it is aborted. The number dropped.
	 */
	private static function cancelUncompleted(ShelfRun $run): int {
		$dropped = 0;
		$objects = new MultiShelfObject(array('run_id' => (int)$run->key, 'deleted' => false));
		foreach ($objects as $object) {
			if ($object->get('svo_completed_time') === null) {
				self::abortObject($object);
				$dropped++;
			} elseif (self::abortUpload($object)) {
				$object->set('svo_upload_id', null);
				$object->save();
			}
		}
		return $dropped;
	}

	/**
	 * One signed-but-uncompleted object is cancelled: an open multipart is
	 * aborted at the provider, then the row is dropped.
	 */
	public static function abortObject(ShelfObject $object): void {
		self::abortUpload($object);
		$object->permanent_delete();
	}

	/**
	 * The multipart upload a row holds, if any, is aborted at the provider
	 * with the plane's credential (a failure there is logged, not fatal — the
	 * provider expires it). True when there was one to abort.
	 */
	private static function abortUpload(ShelfObject $object): bool {
		$upload_id = trim((string)$object->get('svo_upload_id'));
		if ($upload_id === '') {
			return false;
		}
		try {
			list($target, $creds, $bucket) = self::shelf();
			S3Signer::abort_stream(array('creds' => $creds, 'bucket' => $bucket,
				'path' => '/' . ltrim((string)$object->get('svo_key'), '/'), 'upload_id' => $upload_id));
		} catch (\Throwable $e) {
			error_log('ShelfBroker: abort of ' . $object->get('svo_key') . ' failed: ' . $e->getMessage());
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

	private static function openRun(ServiceTenant $row, int $run_id): ShelfRun {
		$run = self::runOf($row, $run_id);
		if ((string)$run->get('svr_state') !== ShelfRun::STATE_OPEN) {
			throw new ShelfBrokerException('That run is already ' . $run->get('svr_state') . '.');
		}
		return $run;
	}

	/**
	 * One ledger row per tenant and key. A retry within the run refreshes it;
	 * a later run signing the same key (a chain's manifest, rewritten by
	 * every incremental) takes the row over: it is that run's to complete
	 * from here, and the object is counted once whichever run wrote it. A
	 * row already completed keeps its completed time and size — that object
	 * is in backup storage until the new one lands over it — and takes the new
	 * size when the run finishes it. An upload the row still holds is
	 * aborted at the provider before its id is let go.
	 */
	private static function ledgerSigned(ServiceTenant $row, ShelfRun $run, string $key, int $bytes, ?string $upload_id): void {
		$object = ShelfObject::forKey((int)$row->key, $key);
		if ($object === null) {
			$object = new ShelfObject(NULL);
			$object->set('svo_svt_service_tenant_id', (int)$row->key);
			$object->set('svo_key', $key);
		} else {
			self::abortUpload($object);
		}
		$object->set('svo_svr_shelf_run_id', (int)$run->key);
		if ($object->get('svo_bytes') === null || ($bytes > 0 && $object->get('svo_completed_time') === null)) {
			$object->set('svo_bytes', max(0, $bytes));
		}
		$object->set('svo_chain', (string)$run->get('svr_chain'));
		$object->set('svo_upload_id', $upload_id);
		$object->set('svo_signed_time', gmdate('Y-m-d H:i:s'));
		$object->save();
	}

	/** A part or complete URL names an upload this run signed for the key. */
	private static function ledgerUploadId(ServiceTenant $row, ShelfRun $run, string $key, string $upload_id): void {
		$object = ShelfObject::forKey((int)$row->key, $key);
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

	private static function cleanChain(string $chain): string {
		$chain = trim($chain);
		return preg_match('/^[A-Za-z0-9._-]{0,64}$/', $chain) ? $chain : '';
	}
}
