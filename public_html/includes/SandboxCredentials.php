<?php
/**
 * SandboxCredentials — a sandboxed plugin's API credential (door 1).
 *
 * Each active sandboxed plugin calls the API with a scoped machine key minted
 * for exactly what was approved (SandboxConsent::effective()) plus every verb
 * on its own models. The key belongs to the admin who approved it and acts as
 * them; its secret is kept sealed on the plugin's SandboxInstall row, because
 * core hands it to the plugin host with every call (SandboxHost).
 *
 *   mint()      on activation and on each approval: a fresh key, the old one
 *               deleted, so the scope always matches the current approval
 *   suspend()   on deactivation: the key is switched off
 *   revoke()    on uninstall: the key is deleted
 *   reprovision()  the sealed-secret reconciler's recipe for a secret that
 *               can no longer be opened (the database moved): mint again
 *
 * @version 1.1 - a lapse ends the approval (approver cleared), so restoring the admin leaves the plugin waiting
 *                rather than approved with its key off; reprovision() passes over a plugin waiting on approval
 */

class SandboxCredentials {

	/** The scope a plugin's key carries now. @return string[] */
	public static function scopeFor(string $plugin): array {
		$effective = SandboxConsent::effective($plugin);
		$entries = $effective['actions'];
		foreach ($effective['records'] as $model => $verbs) {
			foreach ($verbs as $verb) {
				$entries[] = $model . ':' . $verb;
			}
		}
		foreach (SandboxModels::classesFor($plugin) as $class) {
			foreach (ApiKey::RECORD_VERBS as $verb) {
				$entries[] = $class . ':' . $verb;
			}
		}
		return array_values(array_unique($entries));
	}

	/**
	 * Mint the plugin's key, replacing any it had. Needs an approval on
	 * record: the key acts as the admin who gave it.
	 *
	 * @throws SandboxManifestException with no approval, or nothing to scope
	 */
	public static function mint(string $plugin): ApiKey {
		$row = SandboxInstall::forPlugin($plugin);
		if ($row === null || !$row->get('sbi_consent_time')) {
			throw new SandboxManifestException("'$plugin' has not been approved by an admin, so it has no credential.");
		}
		$owner = SandboxConsent::approver($row);
		if ($owner === null) {
			throw new SandboxManifestException("The admin who approved '$plugin' is gone; another admin must approve it again.");
		}
		$entries = self::scopeFor($plugin);
		if (empty($entries)) {
			// Nothing to call: no key at all rather than one scoped to nothing.
			self::revoke($plugin);
			return new ApiKey(NULL);
		}

		$capabilities = array();
		$catalog = ApiLogicEndpoint::actionCatalog();
		foreach ($entries as $entry) {
			if (isset($catalog[$entry])) {
				$capabilities[$entry] = ApiLogicEndpoint::actionCapability($catalog[$entry]['meta']);
			}
		}

		$dblink = DbConnector::get_instance()->get_db_link();
		$own = !$dblink->inTransaction();
		if ($own) {
			$dblink->beginTransaction();
		}
		try {
			self::deleteKey($row);
			$secret = 'secret_' . bin2hex(random_bytes(24));
			$key = new ApiKey(NULL);
			$key->set('apk_usr_user_id', (int)$owner->key);
			$key->set('apk_name', substr('Sandboxed: ' . $plugin, 0, 32));
			$key->set('apk_public_key', 'public_' . bin2hex(random_bytes(12)));
			$key->set('apk_secret_key', ApiKey::GenerateKey($secret));
			$key->set('apk_type', ApiKey::TYPE_MACHINE);
			$key->set('apk_scope', implode(',', $entries));
			$key->set('apk_permission', ApiKey::permission_for_scope($entries, $capabilities));
			// Only from this machine: the host calls over loopback.
			$key->set('apk_ip_restriction', '127.0.0.1,::1');
			$key->set('apk_is_active', true);
			$key->save();
			$key->load();

			$row->set('sbi_apk_api_key_id', (int)$key->key);
			$row->set('sbi_api_secret', (new SecretBox())->seal(SandboxInstall::SECRET_LOCATOR, $secret));
			$row->save();
			if ($own) {
				$dblink->commit();
			}
		} catch (Throwable $e) {
			if ($own && $dblink->inTransaction()) {
				$dblink->rollBack();
			}
			throw $e;
		}
		return $key;
	}

	/** Switch the plugin's key off; its next activation mints a fresh one. */
	public static function suspend(string $plugin): void {
		$row = SandboxInstall::forPlugin($plugin);
		if ($row === null || !$row->get('sbi_apk_api_key_id')) {
			return;
		}
		$key = new ApiKey((int)$row->get('sbi_apk_api_key_id'), TRUE);
		if ($key->key && $key->get('apk_is_active')) {
			$key->set('apk_is_active', false);
			$key->save();
		}
	}

	/**
	 * The admin whose approvals these are can no longer give one (demoted
	 * below superadmin, disabled or deleted): each plugin they approved has
	 * its key switched off and its approval ended, and waits on an admin's
	 * approval, theirs included once they may give one again. Never
	 * re-minted as someone else: that would be an approval nobody gave.
	 * Nor revived when the admin is restored: that approval was ended.
	 */
	public static function lapseApprovalsOf(int $user_id): void {
		foreach (new MultiSandboxInstall(array('sbi_usr_user_id' => $user_id)) as $install) {
			self::suspend((string)$install->get('sbi_plugin'));
			$install->set('sbi_usr_user_id', null);
			$install->save();
		}
	}

	/** Delete the plugin's key and its record; uninstall. */
	public static function revoke(string $plugin): void {
		$row = SandboxInstall::forPlugin($plugin);
		if ($row === null) {
			return;
		}
		self::deleteKey($row);
		$row->set('sbi_apk_api_key_id', null);
		$row->set('sbi_api_secret', null);
		$row->save();
	}

	/** Remove the plugin's record entirely, approval included; uninstall. */
	public static function forget(string $plugin): void {
		self::revoke($plugin);
		$row = SandboxInstall::forPlugin($plugin);
		if ($row !== null) {
			$row->permanent_delete();
		}
	}

	/**
	 * The sealed-secret reconciler's recipe (settings.json sealed_secrets,
	 * kind regenerable): mint again for every active plugin whose key secret
	 * can no longer be opened. Nothing depends on the old secret but the host,
	 * which is handed the new one with its next call.
	 */
	public static function reprovision(): bool {
		$ok = true;
		foreach (array_keys(SandboxPlugins::active()) as $plugin) {
			if (SandboxInstall::credentialFor($plugin) !== null) {
				continue;
			}
			// Waiting on an admin's approval, not broken: its key comes with it.
			$row = SandboxInstall::forPlugin($plugin);
			if ($row === null || SandboxConsent::approver($row) === null) {
				continue;
			}
			try {
				self::mint($plugin);
			} catch (Throwable $e) {
				error_log('[SandboxCredentials] could not mint a new key for ' . $plugin . ': ' . $e->getMessage());
				$ok = false;
			}
		}
		return $ok;
	}

	private static function deleteKey(SandboxInstall $row): void {
		if (!$row->get('sbi_apk_api_key_id')) {
			return;
		}
		$old = new ApiKey((int)$row->get('sbi_apk_api_key_id'), TRUE);
		if ($old->key) {
			$old->permanent_delete();
		}
	}
}
