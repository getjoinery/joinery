<?php
/**
 * InboundHandlerRegistry - the plugin handlers a mailbox alias can hand mail to.
 *
 * Plugins declare handlers in plugin.json:
 *
 *   "inboundHandlers": { "boards": { "class": "BoardsInboundHandler", "label": "Boards" } }
 *
 * A handler key owns at most one alias per domain, and the alias's local part is the
 * key itself (`boards@clubA.org`). The alias is an ordinary store-mode alias that also
 * names the handler (iea_handler); the router stores each message as it always does and
 * then hands the stored row to the handler (InboundEmailRouter::handOver). A handler
 * alias also accepts `base+tag` sub-addresses (InboundEmailAlias::resolveRecipient).
 *
 * Domains: a handler alias must not seal content (the plugin could not read it, and
 * putting decrypted text in a plugin's tables would break the promise of the level),
 * and on a relay-fronted deployment the relay must be new enough to accept a
 * `+tag` (RelayVersion::ACCEPTS_SUBADDRESSES). eligibleDomains() is the one place that
 * is decided.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));

class InboundHandlerRegistry {

	/** @var array<string,array>|null */
	private static $declared = null;

	/**
	 * Every handler declared by an active plugin: key => ['class','label','plugin'].
	 */
	public static function declared(): array {
		if (self::$declared !== null) {
			return self::$declared;
		}
		$out = array();
		try {
			foreach (PluginHelper::getActivePlugins() as $plugin_name => $plugin) {
				$handlers = $plugin->get('inboundHandlers', null);
				if (!is_array($handlers)) {
					continue;
				}
				foreach ($handlers as $key => $decl) {
					$key = (string)$key;
					if (!preg_match('/^[a-z0-9][a-z0-9_]{0,63}$/', $key) || !is_array($decl)
							|| trim((string)($decl['class'] ?? '')) === '') {
						error_log('[InboundHandlerRegistry] ignoring malformed handler declaration "' . $key . '" in ' . $plugin_name);
						continue;
					}
					$out[$key] = array(
						'class'  => trim((string)$decl['class']),
						'label'  => (string)($decl['label'] ?? $key),
						'plugin' => (string)$plugin_name,
					);
				}
			}
		} catch (\Throwable $e) {
			error_log('[InboundHandlerRegistry] reading plugin handlers failed: ' . $e->getMessage());
		}
		return self::$declared = $out;
	}

	/** Forget the cached declarations (a plugin was activated or deactivated). */
	public static function reset(): void {
		self::$declared = null;
	}

	public static function isDeclared(string $key): bool {
		return array_key_exists($key, self::declared());
	}

	/**
	 * The handler object for a key, or null when no active plugin declares it or its
	 * class does not resolve or does not implement InboundAddressHandler.
	 */
	public static function handlerFor(string $key): ?InboundAddressHandler {
		$decl = self::declared()[$key] ?? null;
		if ($decl === null || !class_exists($decl['class'])) {
			return null;
		}
		$handler = new $decl['class']();
		return ($handler instanceof InboundAddressHandler) ? $handler : null;
	}

	/**
	 * Why a new handler alias cannot go on this domain, or null when it can.
	 */
	public static function domainRefusal(InboundEmailDomain $domain): ?string {
		if (!$domain->key || !$domain->is_authoritative()) {
			return 'That domain does not receive mail here.';
		}
		if ($domain->seals_content()) {
			return 'That domain is set to Private, which seals its mail; a plugin cannot read it.';
		}
		if (mailbox_receive_mode() === 'relay') {
			$relay = MailboxRelay::active();
			$running = $relay ? $relay->provisionedVersion() : '';
			if (!RelayVersion::acceptsSubaddresses($running)) {
				return 'Email needs relay version ' . RelayVersion::ACCEPTS_SUBADDRESSES
					. ' or newer; this relay reports ' . ($running !== '' ? $running : 'no version') . '.';
			}
		}
		return null;
	}

	/**
	 * The domains where a new handler alias would not seal content, lowest id first.
	 *
	 * @return InboundEmailDomain[]
	 */
	public static function eligibleDomains(): array {
		$out = array();
		$domains = new MultiInboundEmailDomain(array('deleted' => false), array('ied_inbound_email_domain_id' => 'ASC'));
		foreach ($domains as $domain) {
			if (self::domainRefusal($domain) === null) {
				$out[] = $domain;
			}
		}
		return $out;
	}

	/** The first eligible domain in a stable order, or null when there is none. */
	public static function defaultDomain(): ?InboundEmailDomain {
		$eligible = self::eligibleDomains();
		return $eligible ? $eligible[0] : null;
	}

	/**
	 * The alias this handler has on a domain, or null. A domain is an
	 * InboundEmailDomain or its id.
	 *
	 * @param InboundEmailDomain|int $domain
	 */
	public static function aliasFor(string $key, $domain): ?InboundEmailAlias {
		$domain_id = ($domain instanceof InboundEmailDomain) ? intval($domain->key) : intval($domain);
		if ($domain_id <= 0) {
			return null;
		}
		$aliases = new MultiInboundEmailAlias(array('domain_id' => $domain_id, 'alias' => $key, 'deleted' => false));
		foreach ($aliases as $alias) {
			return $alias->handler_key() === $key ? $alias : null;
		}
		return null;
	}

	/**
	 * Create the handler's alias on a domain, or re-enable it. Idempotent. Never moves
	 * or deletes an alias: a domain a plugin stops using keeps its alias, so an address
	 * already given out there keeps working. $reenable false leaves a switched-off
	 * alias off (a member's use is not the operator's say to turn it back on).
	 *
	 * @param InboundEmailDomain|int $domain
	 * @return array{alias:?InboundEmailAlias, reason:?string} reason is set when refused
	 */
	public static function ensureAlias(string $key, $domain, bool $reenable = true): array {
		if (!self::isDeclared($key)) {
			return array('alias' => null, 'reason' => 'No active plugin declares the "' . $key . '" handler.');
		}
		if (!($domain instanceof InboundEmailDomain)) {
			$domain = new InboundEmailDomain(intval($domain), TRUE);
		}
		if (!$domain->key) {
			return array('alias' => null, 'reason' => 'That domain does not exist.');
		}
		$refusal = self::domainRefusal($domain);
		if ($refusal !== null) {
			return array('alias' => null, 'reason' => $refusal);
		}

		// Whatever live alias holds the local part decides.
		$aliases = new MultiInboundEmailAlias(array('domain_id' => intval($domain->key), 'alias' => $key, 'deleted' => false));
		foreach ($aliases as $existing) {
			if ($existing->handler_key() !== $key) {
				return array('alias' => null, 'reason' => 'The address ' . $key . '@' . $domain->get('ied_domain')
					. ' is already used by another mailbox.');
			}
			if ($existing->seals_content()) {
				return array('alias' => null, 'reason' => 'That alias is set to a sealing level; a plugin cannot read it.');
			}
			if (!$existing->get('iea_is_enabled')) {
				if (!$reenable) {
					return array('alias' => null, 'reason' => 'The address ' . $key . '@' . $domain->get('ied_domain') . ' is switched off.');
				}
				$existing->set('iea_is_enabled', true);
				$existing->save();
			}
			return array('alias' => $existing, 'reason' => null);
		}

		$alias = new InboundEmailAlias(null);
		$alias->set('iea_ied_inbound_email_domain_id', intval($domain->key));
		$alias->set('iea_alias', $key);
		$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
		$alias->set('iea_handler', $key);
		$alias->set('iea_description', self::declared()[$key]['label'] . ' (handled by the ' . self::declared()[$key]['plugin'] . ' plugin)');
		$alias->set('iea_is_enabled', true);
		$alias->save();
		return array('alias' => $alias, 'reason' => null);
	}
}
