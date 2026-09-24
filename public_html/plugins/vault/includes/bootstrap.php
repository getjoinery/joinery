<?php
/**
 * Vault (password manager) plugin bootstrap — the plugin's declared load point
 * (the top-level `bootstrap` key in plugin.json), loaded once per request by
 * PluginBootstraps whenever the plugin is active.
 *
 * The store DEK is sealed to the `passwords` client-custody vault key and lives
 * in vlk_vault_keyring, outside any $sealed_fields model. So a rotation of that
 * key re-seals it through a browser hook, not a model walk: this registers the
 * hook's script for the page running the rotation, and satisfies the plugin's
 * `client_reseals` obligation for the scope.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));

VaultUnlock::clientReseal('passwords', array(), array('plugins/vault/assets/js/vault-reseal.js'));
?>
