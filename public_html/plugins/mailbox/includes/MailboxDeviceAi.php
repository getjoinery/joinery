<?php
/**
 * AI on end-to-end encrypted (Fortress) mail, the server's half
 * (specs/fortress_mail_device_ai.md § R3, R4).
 *
 * The member's browser opens their Fortress mail, asks a model they named for
 * a verdict, and seals the verdict under each message's own key. The server
 * never sees a message or a verdict. It does three things, all here:
 *   - pages the work: the member's Fortress messages a recipe has not judged
 *     yet, each with the sealed columns one judgement needs (entries());
 *   - stores a verdict: the sealed fields, the clear danger score and scan
 *     time the list sorts on, and the recipe's `done` row, in one transaction,
 *     so a `done` without a stored verdict cannot exist and a second tab's late
 *     post is a no-op (recordVerdict());
 *   - records `error` for a reply the model gave that failed validation, so
 *     the item is not offered again (recordError()). A call that never reached
 *     the model records nothing and the item comes back next time.
 *
 * Every entry point checks the same four things (authorize()): the recipe is
 * the caller's own; it is one of the jobs a device may run; the row is the
 * caller's; the row is sealed to the `mail` vault (`v1.edgeseal.mail.`) and
 * on a mailbox the recipe covers. Without the owner check one member could
 * mark another's message judged and hide it from that member's own scan.
 *
 * @version 1.5 - pendingCount(), deviceQueues(), lastJudgedOnDevice(): what the AI panel says
 *   about a device recipe in place of a server run's last-ran line and queue notice
 * @version 1.4 - reasoningEffort(): each recipe carries the reasoning control a server run
 *   would send, so a thinking model does not spend the whole budget thinking
 * @version 1.3 - siteModel(): the site's own model, offered with one click where a browser
 *   could call it (a private or tailnet host, no key)
 * @version 1.2 - a verdict replaces an `error` row (on demand) but never a `done`; the verdict
 *   write re-checks the domain's consent
 * @version 1.1 - recipesFor(), originTrust(), consentRefusal(); the queue honours the
 *   domain's AI consent and carries an inbound row's clear recipient
 * @version 1.0
 */

class MailboxDeviceAiException extends Exception {}

class MailboxDeviceAi {

	const PAGE_SIZE = 100;

	/** Output room for one verdict: the scan's longest (twelve red flags and a
	 *  summary) fits well inside it, with headroom for a model that reasons. */
	const MAX_TOKENS = 4096;

	/** The key prefix of a row the member's browser holds the key to. */
	const MAIL_KEY_PREFIX = 'v1.edgeseal.mail.';

	/** Columns one judgement needs, sealed, per entry. */
	const ENTRY_COLUMNS = array('iem_sender', 'iem_subject', 'iem_body_plain', 'iem_body_html',
		'iem_raw_headers', 'iem_attachment_manifest', 'iem_recipient');

	/**
	 * The site's own model, when a browser could call it (specs/fortress_mail_device_ai.md
	 * § R7): the joinery_ai local provider's address, on a private or tailnet
	 * host — never loopback, which is the server's own machine, not the
	 * person's — and only while no key is configured for it, since the
	 * platform's keys never reach a browser. The settings page and the panel
	 * offer it as one click; the click registers the origin like any other
	 * (step-up, CSP pin), so the person still decides where their mail goes.
	 * $operator says whether the caller runs this site, which changes what the
	 * offer says: a member is told the operator could see mail sent there.
	 *
	 * @return ?array{origin:string,path:string,model:string,host:string,operator:bool}
	 */
	public static function siteModel(bool $operator): ?array {
		if (!PluginHelper::isPluginActive('joinery_ai')) {
			return null;
		}
		$s = Globalvars::get_instance();
		return self::siteModelFrom((string)$s->get_setting('joinery_ai_local_base_url'),
			(string)$s->get_setting('joinery_ai_local_model'),
			(string)$s->get_setting('joinery_ai_local_api_key') !== '', $operator);
	}

	/**
	 * The request-level reasoning control a server run of $recipe would send
	 * (`OpenAiCompatibleProvider::applyReasoning()`): the recipe's thinking
	 * level, the site default where the recipe has none, 'off' sent as 'none'.
	 * A model that reasons by default (Ollama's qwen3 family) otherwise spends
	 * the whole output budget thinking and answers with nothing, which the
	 * drain would record as `error`; a host that does not know the field drops
	 * it. Null recipe: the site default alone (the Test button).
	 */
	public static function reasoningEffort(?Recipe $recipe): string {
		$level = AgentLoop::resolveThinkingLevel($recipe ? $recipe->get('rcp_thinking_level') : null,
			Globalvars::get_instance()->get_setting('joinery_ai_default_thinking_level'));
		return $level === 'off' ? 'none' : $level;
	}

	/** siteModel() from its inputs: the base URL, the comma list of models (the
	 *  first is the site's default), whether a key is configured. */
	public static function siteModelFrom(string $base_url, string $models, bool $has_key, bool $operator): ?array {
		$model = trim((string)explode(',', $models)[0]);
		if ($has_key || $model === '' || trim($base_url) === '') {
			return null;
		}
		$parts = parse_url(trim($base_url));
		if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
			return null;
		}
		$host = strtolower((string)$parts['host']);
		$loopback = ($host === 'localhost' || $host === '::1' || $host === '[::1]' || strpos($host, '127.') === 0);
		if ($loopback || !MailboxDeviceAiHost::hostIsPrivate($host)) {
			return null;
		}
		$hostport = $host . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
		try {
			$origin = MailboxDeviceAiHost::normalizeOrigin(strtolower((string)$parts['scheme']) . '://' . $hostport);
		} catch (MailboxDeviceAiHostException $e) {
			return null;
		}
		return array(
			'origin'   => $origin,
			'path'     => rtrim((string)($parts['path'] ?? ''), '/'),
			'model'    => $model,
			'host'     => $hostport,
			'operator' => $operator,
		);
	}

	/**
	 * The caller's own, device-capable recipe and the mailboxes it covers now.
	 *
	 * @return array{recipe: Recipe, job: PipelineJobInterface, alias_ids: int[]}
	 * @throws MailboxDeviceAiException
	 */
	public static function recipeFor(int $user_id, int $recipe_id): array {
		if ($user_id <= 0) {
			throw new MailboxDeviceAiException('Sign in required.');
		}
		if (!PluginHelper::isPluginActive('joinery_ai')) {
			throw new MailboxDeviceAiException('AI is not turned on for this site.');
		}
		$recipe = new Recipe($recipe_id, TRUE);
		if (!$recipe->key || $recipe->get('rcp_delete_time') || (int)$recipe->get('rcp_owner_user_id') !== $user_id) {
			throw new MailboxDeviceAiException('That is not one of your recipes.');
		}
		$job = PipelineJobRegistry::get((string)$recipe->get('rcp_pipeline_job'));
		if (!$job || !$job->deviceCapable()) {
			throw new MailboxDeviceAiException('That recipe does not run on your device.');
		}
		$alias_ids = array_map('intval', array_keys(
			MailboxAliasConfig::resolveBoundAliases(Recipe::decodeSourceConfig($recipe), $user_id)));
		return array('recipe' => $recipe, 'job' => $job, 'alias_ids' => $alias_ids);
	}

	/**
	 * The queue's WHERE: the same filters as EmailJobCandidates (live, not
	 * spam, not a draft, parsed, unread, lookback) on the caller's rows sealed
	 * to the mail vault on $alias_ids, minus items the recipe has logged.
	 * entries() pages it; pendingCount() counts it, so the panel's number is
	 * the drain's queue and nothing else.
	 */
	private static function queueWhere(int $user_id, Recipe $recipe, array $alias_ids, int $before_id, array &$params): string {
		$params[':owner'] = $user_id;
		$params[':aip_recipe_id'] = (int)$recipe->key;
		$params[':mail_key'] = self::MAIL_KEY_PREFIX . '%';
		$in = array();
		foreach (array_values($alias_ids) as $i => $id) {
			$in[] = ':alias_' . $i;
			$params[':alias_' . $i] = (int)$id;
		}
		$lookback = EmailJobCandidates::lookbackDays(Recipe::decodeSourceConfig($recipe));
		$where_time = '';
		if ($lookback > 0) {
			$where_time = ' AND iem_received_time >= :received_since';
			$params[':received_since'] = gmdate('Y-m-d H:i:s', time() - $lookback * 86400);
		}
		$where_cursor = '';
		if ($before_id > 0) {
			$where_cursor = ' AND iem_inbound_email_message_id < :before_id';
			$params[':before_id'] = $before_id;
		}
		return 'iem_iea_inbound_email_alias_id IN (' . implode(', ', $in) . ')
				  AND iem_sealed_owner_user_id = :owner
				  AND iem_sealed_key LIKE :mail_key
				  AND iem_delete_time IS NULL
				  AND iem_spam_verdict IS DISTINCT FROM \'spam\'
				  AND iem_direction IS DISTINCT FROM \'draft\'
				  AND iem_pending_parse IS NOT TRUE
				  AND iem_is_read = false' . $where_time . $where_cursor . '
				  AND ' . MultiAipRecipeItemLog::notExistsClause('iem_inbound_email_message_id::text');
	}

	/**
	 * How many of the caller's messages $recipe_id has yet to judge on their
	 * device: the drain's queue, counted, across every mailbox the recipe
	 * covers whose domain's consent allows the registered model. Zero when no
	 * model is registered, since nothing would judge them.
	 *
	 * @throws MailboxDeviceAiException
	 */
	public static function pendingCount(int $user_id, int $recipe_id): int {
		$r = self::recipeFor($user_id, $recipe_id);
		$alias_ids = array();
		foreach ($r['alias_ids'] as $id) {
			if (self::consentRefusal($user_id, (int)$id) === null) {
				$alias_ids[] = (int)$id;
			}
		}
		if (!$alias_ids) {
			return 0;
		}
		$params = array();
		$where = self::queueWhere($user_id, $r['recipe'], $alias_ids, 0, $params);
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT COUNT(*) FROM iem_inbound_email_messages WHERE ' . $where);
		$stmt->execute($params);
		return (int)$stmt->fetchColumn();
	}

	/**
	 * The device queues the AI panel lists beside the server's runs in flight
	 * (AiPanelService::jobs()): each of $user_id's device-capable recipes with
	 * messages waiting for their browser, as {recipe_id, name, pending}.
	 * Empty when the person has registered no model: nothing is waiting for a
	 * device that will not come.
	 *
	 * @return array<int, array{recipe_id:int, name:string, pending:int}>
	 */
	public static function deviceQueues(int $user_id): array {
		if ($user_id <= 0 || !PluginHelper::isPluginActive('joinery_ai')) {
			return array();
		}
		if (MailboxDeviceAiHost::originForUser($user_id) === null) {
			return array();
		}
		$job_ids = array();
		foreach (array_keys(PipelineJobRegistry::all()) as $job_id) {
			$job = PipelineJobRegistry::get((string)$job_id);
			if ($job && $job->deviceCapable()) {
				$job_ids[] = (string)$job_id;
			}
		}
		if (!$job_ids) {
			return array();
		}
		$out = array();
		foreach (new MultiRecipe(array('owner_user_id' => $user_id, 'deleted' => false)) as $recipe) {
			if (!in_array((string)$recipe->get('rcp_pipeline_job'), $job_ids, true) || !$recipe->get('rcp_enabled')) {
				continue;
			}
			try {
				$n = self::pendingCount($user_id, (int)$recipe->key);
			} catch (MailboxDeviceAiException $e) {
				continue;
			}
			if ($n > 0) {
				$out[] = array('recipe_id' => (int)$recipe->key, 'name' => (string)$recipe->get('rcp_name'), 'pending' => $n);
			}
		}
		return $out;
	}

	/**
	 * When $recipe_id last judged a message on the owner's device (UTC), or
	 * null: device log rows are the ones with no run id (D15).
	 */
	public static function lastJudgedOnDevice(int $recipe_id): ?string {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT MAX(aip_processed_time) FROM aip_recipe_item_log
			  WHERE aip_rcp_recipe_id = ? AND aip_rcr_recipe_run_id IS NULL');
		$stmt->execute(array($recipe_id));
		$t = $stmt->fetchColumn();
		return $t ? (string)$t : null;
	}

	/**
	 * The message row, when it is the caller's, sealed to their mail vault, and
	 * on one of $alias_ids.
	 *
	 * @throws MailboxDeviceAiException
	 */
	public static function rowFor(int $user_id, int $row_id, array $alias_ids): array {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT * FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?');
		$stmt->execute(array($row_id));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if (!$row || $row['iem_delete_time'] !== null
				|| intval($row['iem_sealed_owner_user_id'] ?? 0) !== $user_id
				|| strncmp((string)$row['iem_sealed_key'], self::MAIL_KEY_PREFIX, strlen(self::MAIL_KEY_PREFIX)) !== 0) {
			throw new MailboxDeviceAiException('That is not one of your end-to-end encrypted messages.');
		}
		if (!in_array(intval($row['iem_iea_inbound_email_alias_id']), $alias_ids, true)) {
			throw new MailboxDeviceAiException('That message is not on a mailbox this recipe covers.');
		}
		return $row;
	}

	/**
	 * How far the registered model at $origin carries mail, in the platform's
	 * endpoint trust classes: `local` for this computer or the owner's own
	 * network, `trusted` for the platform's own trusted provider's host (the
	 * Fireworks base URL), `cloud` for anywhere else.
	 */
	public static function originTrust(string $origin): string {
		$host = strtolower((string)parse_url($origin, PHP_URL_HOST));
		if ($host !== '' && MailboxDeviceAiHost::hostIsPrivate($host)) {
			return 'local';
		}
		$trusted = strtolower((string)parse_url((string)Globalvars::get_instance()->get_setting('joinery_ai_fireworks_base_url'), PHP_URL_HOST));
		if ($host !== '' && $host === $trusted) {
			return 'trusted';
		}
		return 'cloud';
	}

	/**
	 * Why the caller's mail on $alias_id may not go to their registered model,
	 * or null when it may. The mailbox's domain decides how far its mail may
	 * travel to be read (processingConsent(), specs/fortress_mail_device_ai.md
	 * § R5 B9): `local` keeps it on the owner's own machines, `trusted` adds the
	 * platform's trusted provider, `cloud` allows any.
	 */
	public static function consentRefusal(int $user_id, int $alias_id): ?string {
		$alias = new InboundEmailAlias($alias_id, TRUE);
		$address = $alias->key ? (string)$alias->get_full_address() : '';
		if ($address === '' || !MailboxAliasConfig::aiProcessingAllowed($address)) {
			return 'AI is turned off for this mailbox\'s domain.';
		}
		$origin = MailboxDeviceAiHost::originForUser($user_id);
		if ($origin === null) {
			return 'No model is set up yet. Choose where your model answers in Email settings.';
		}
		$consent = MailboxAliasConfig::aiProcessingConsent($address);
		$trust = self::originTrust($origin);
		$accepts = array(
			InboundEmailDomain::CONSENT_LOCAL   => array('local'),
			InboundEmailDomain::CONSENT_TRUSTED => array('local', 'trusted'),
			InboundEmailDomain::CONSENT_CLOUD   => array('local', 'trusted', 'cloud'),
		);
		if (!in_array($trust, $accepts[$consent] ?? array('local'), true)) {
			return $consent === InboundEmailDomain::CONSENT_LOCAL
				? 'This mailbox\'s domain keeps AI on your own machines, and ' . $origin . ' is not one of them. '
					. 'Use a model on this computer or your own network.'
				: 'This mailbox\'s domain allows AI only on your own machines or the site\'s trusted provider, and '
					. $origin . ' is neither.';
		}
		return null;
	}

	/**
	 * The device-capable recipes the caller owns that cover $address now, each
	 * with what the browser needs to judge with exactly the server's words
	 * (specs/fortress_mail_device_ai.md § R5): the full system prompt under a
	 * fresh nonce, the verdict descriptor, max_tokens, the minimum model tier,
	 * and whether the digest carries attachments. Plus our mail host (the one
	 * Authentication-Results stamp the digest trusts), the model reference the
	 * panel grades a model name with, and the consent verdict for the
	 * registered model.
	 *
	 * @throws MailboxDeviceAiException
	 */
	public static function recipesFor(int $user_id, string $address): array {
		if ($user_id <= 0) {
			throw new MailboxDeviceAiException('Sign in required.');
		}
		if (!PluginHelper::isPluginActive('joinery_ai')) {
			throw new MailboxDeviceAiException('AI is not turned on for this site.');
		}
		$address = strtolower(trim($address));
		$alias_id = MailboxAliasConfig::resolveActiveAliasId($address);
		if ($alias_id === null || !in_array($alias_id, InboundEmailMailboxGrant::alias_ids_for_user($user_id), true)) {
			throw new MailboxDeviceAiException('That is not one of your mailboxes.');
		}
		$tz = RecipeRunContext::resolveTimezone($user_id);
		$out = array();
		foreach (new MultiRecipe(array('rcp_owner_user_id' => $user_id, 'rcp_enabled' => true, 'deleted' => false)) as $recipe) {
			$job = PipelineJobRegistry::get((string)$recipe->get('rcp_pipeline_job'));
			if (!$job || !$job->deviceCapable()) {
				continue;
			}
			if (!isset(MailboxAliasConfig::resolveBoundAliases(Recipe::decodeSourceConfig($recipe), $user_id)[$alias_id])) {
				continue;
			}
			$nonce = bin2hex(random_bytes(4));
			$parts = PipelineRunner::systemText($recipe, $job, $tz, $nonce);
			$out[] = array(
				'recipe_id'          => (int)$recipe->key,
				'job_id'             => $job->id(),
				'label'              => (string)$recipe->get('rcp_name'),
				'system'             => $parts['untrusted'] !== '' ? $parts['text'] . "\n\n" . $parts['untrusted'] : $parts['text'],
				'nonce'              => $nonce,
				'verdict_descriptor' => $job->verdictDescriptor(),
				'max_tokens'         => self::MAX_TOKENS,
				'reasoning_effort'   => self::reasoningEffort($recipe),
				'min_tier'           => (string)($recipe->get('rcp_min_tier') ?: $job->minTier()),
				'attachments'        => method_exists($job, 'includesAttachmentDigest') ? $job->includesAttachmentDigest() : false,
			);
		}
		$reference = array('models' => array(), 'ladder' => array());
		$ref_path = PathHelper::getIncludePath('plugins/joinery_ai/ai_model_reference.json');
		$ref = is_file($ref_path) ? json_decode((string)file_get_contents($ref_path), true) : null;
		if (is_array($ref)) {
			foreach ((array)($ref['models'] ?? array()) as $m) {
				$reference['models'][] = array('match' => (string)($m['match'] ?? ''), 'tier' => (string)($m['tier'] ?? ''));
			}
			$reference['ladder'] = (array)($ref['ladder'] ?? array());
		}
		return array(
			'alias_id'        => $alias_id,
			'recipes'         => $out,
			'authserv_id'     => (string)Globalvars::get_instance()->get_setting('mailbox_mail_hostname'),
			'model_reference' => $reference,
			'consent_refusal' => self::consentRefusal($user_id, $alias_id),
		);
	}

	/**
	 * One page of the caller's Fortress messages $recipe_id has not judged,
	 * newest first. $alias_id narrows to one covered mailbox (0: all of them);
	 * $before_id is the cursor (0: from the newest). The filters are
	 * EmailJobCandidates' (live, not spam, not a draft, parsed, unread, inside
	 * the recipe's lookback), turned round to select ONLY rows sealed to the
	 * mail vault.
	 *
	 * @return array{entries: array, next_before_id: ?int}
	 * @throws MailboxDeviceAiException
	 */
	public static function entries(int $user_id, int $recipe_id, int $alias_id = 0, int $before_id = 0): array {
		$r = self::recipeFor($user_id, $recipe_id);
		$alias_ids = $r['alias_ids'];
		if ($alias_id > 0) {
			if (!in_array($alias_id, $alias_ids, true)) {
				throw new MailboxDeviceAiException('This recipe does not cover that mailbox.');
			}
			$alias_ids = array($alias_id);
		}
		// Mail goes only where its domain's consent lets it: a mailbox whose
		// domain keeps AI local pages no work for a model elsewhere.
		$allowed = array();
		$refusal = null;
		foreach ($alias_ids as $id) {
			$why = self::consentRefusal($user_id, (int)$id);
			if ($why === null) {
				$allowed[] = (int)$id;
			} elseif ($refusal === null) {
				$refusal = $why;
			}
		}
		if ($alias_id > 0 && $refusal !== null) {
			throw new MailboxDeviceAiException($refusal);
		}
		$alias_ids = $allowed;
		if (!$alias_ids) {
			if ($refusal !== null) {
				throw new MailboxDeviceAiException($refusal);
			}
			return array('entries' => array(), 'next_before_id' => null);
		}

		$params = array();
		$where = self::queueWhere($user_id, $r['recipe'], $alias_ids, $before_id, $params);
		$sql = 'SELECT iem_inbound_email_message_id, iem_sealed_key, iem_received_time, iem_dkim_result,
					iem_spf_result, iem_dmarc_result, iem_auth_source, ' . implode(', ', self::ENTRY_COLUMNS) . '
				FROM iem_inbound_email_messages
				WHERE ' . $where . '
				ORDER BY iem_inbound_email_message_id DESC
				LIMIT ' . (self::PAGE_SIZE + 1);
		$stmt = DbConnector::get_instance()->get_db_link()->prepare($sql);
		$stmt->execute($params);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		$more = count($rows) > self::PAGE_SIZE;
		$rows = array_slice($rows, 0, self::PAGE_SIZE);
		$entries = array();
		foreach ($rows as $row) {
			$entries[] = array(
				'id'            => (int)$row['iem_inbound_email_message_id'],
				'received_time' => $row['iem_received_time'],
				'dkim_result'   => $row['iem_dkim_result'],
				'spf_result'    => $row['iem_spf_result'],
				'dmarc_result'  => $row['iem_dmarc_result'],
				'auth_source'   => $row['iem_auth_source'],
				// An inbound row's recipient is the routing address, kept in the
				// clear; the digest falls back to it when no header block exists.
				'recipient'     => strncmp((string)$row['iem_recipient'], 'v1.edge.', 8) === 0 ? '' : (string)$row['iem_recipient'],
				'sealed'        => InboundEmailMessage::sealedForBrowser($row, self::ENTRY_COLUMNS),
			);
		}
		return array(
			'entries'        => $entries,
			'next_before_id' => ($more && $entries) ? $entries[count($entries) - 1]['id'] : null,
		);
	}

	/**
	 * Store one verdict the caller's browser sealed for $row_id, with the
	 * recipe's `done` row, in one transaction. $fields is column => `v1.edge.`
	 * ciphertext: exactly iem_ai_summary for the triage, exactly iem_ai_scan
	 * for the security scan (which also takes $danger_score 0–10, kept in the
	 * clear with the scan time, as a Private row keeps them).
	 *
	 * Returns false, writing nothing, when the recipe already judged the message
	 * (a second tab, or a retry, got there first). A message it once logged as
	 * `error` takes the verdict: on demand is how such a message is judged.
	 *
	 * @throws MailboxDeviceAiException
	 */
	public static function recordVerdict(int $user_id, int $recipe_id, int $row_id, array $fields, ?int $danger_score = null): bool {
		$r = self::recipeFor($user_id, $recipe_id);
		$row = self::rowFor($user_id, $row_id, $r['alias_ids']);
		// Where the verdict came from must be a model the domain allows; the
		// browser checks before it sends, and nothing is stored if it did not.
		$why = self::consentRefusal($user_id, (int)$row['iem_iea_inbound_email_alias_id']);
		if ($why !== null) {
			throw new MailboxDeviceAiException($why);
		}

		$is_scan = $r['job']->id() === 'email_security_scan';
		$expected = $is_scan ? 'iem_ai_scan' : 'iem_ai_summary';
		if (array_keys($fields) !== array($expected)) {
			throw new MailboxDeviceAiException('This recipe writes ' . $expected . ' and nothing else.');
		}
		if ($is_scan && ($danger_score === null || $danger_score < 0 || $danger_score > 10)) {
			throw new MailboxDeviceAiException('A security scan verdict needs its danger score, 0 to 10.');
		}

		$db = DbConnector::get_instance()->get_db_link();
		$own_tx = !$db->inTransaction();
		if ($own_tx) {
			$db->beginTransaction();
		}
		try {
			if (!self::insertLog($r['recipe'], $row_id, AipRecipeItemLog::STATUS_DONE)) {
				if ($own_tx) {
					$db->rollBack();
				}
				return false;
			}
			try {
				InboundEmailMessage::acceptBrowserSealedFields($row_id, $fields);
			} catch (RuntimeException $e) {
				throw new MailboxDeviceAiException($e->getMessage());
			}
			if ($is_scan) {
				$db->prepare('UPDATE iem_inbound_email_messages SET iem_ai_danger_score = ?, iem_ai_scan_time = ?
					WHERE iem_inbound_email_message_id = ?')
					->execute(array($danger_score, gmdate('Y-m-d H:i:s'), $row_id));
			}
			if ($own_tx) {
				$db->commit();
			}
		} catch (Throwable $e) {
			if ($own_tx && $db->inTransaction()) {
				$db->rollBack();
			}
			throw $e;
		}
		return true;
	}

	/**
	 * Record that the caller's model answered for $row_id but the answer failed
	 * validation after its retry, so the item is not offered again. A second
	 * record, or one after a verdict, changes nothing. Returns whether a row
	 * was written.
	 *
	 * @throws MailboxDeviceAiException
	 */
	public static function recordError(int $user_id, int $recipe_id, int $row_id): bool {
		$r = self::recipeFor($user_id, $recipe_id);
		self::rowFor($user_id, $row_id, $r['alias_ids']);
		return self::insertLog($r['recipe'], $row_id, AipRecipeItemLog::STATUS_ERROR);
	}

	/**
	 * The recipe's log row for $row_id. A new row is written; an existing one
	 * is left as it is — except that a `done` replaces an `error`: a message
	 * whose answer once failed validation can still be judged on demand, and
	 * its verdict then counts (specs/fortress_mail_device_ai.md § R6, Q3). A
	 * `done` is never overwritten. No run: a device run has none.
	 */
	private static function insertLog(Recipe $recipe, int $row_id, string $status): bool {
		$sql = 'INSERT INTO aip_recipe_item_log (aip_rcp_recipe_id, aip_item_key, aip_status, aip_processed_time)
			 VALUES (?, ?, ?, ?)
			 ON CONFLICT (aip_rcp_recipe_id, aip_item_key) '
			. ($status === AipRecipeItemLog::STATUS_DONE
				? 'DO UPDATE SET aip_status = EXCLUDED.aip_status, aip_processed_time = EXCLUDED.aip_processed_time
				   WHERE aip_recipe_item_log.aip_status = \'' . AipRecipeItemLog::STATUS_ERROR . '\''
				: 'DO NOTHING')
			. ' RETURNING aip_recipe_item_log_id';
		$stmt = DbConnector::get_instance()->get_db_link()->prepare($sql);
		$stmt->execute(array((int)$recipe->key, (string)$row_id, $status, gmdate('Y-m-d H:i:s')));
		return $stmt->fetchColumn() !== false;
	}
}
