<?php
/**
 * Thrown by a plugin provisioning check method to signal an expected,
 * caught dependency failure — the resource the plugin needs is missing or
 * not working. PluginProvisioning maps this to the `unmet` result state.
 *
 * Any OTHER Throwable from a check method maps to `error` — a broken check,
 * not a missing dependency. So a check method must catch the real
 * acquisition exception (a PDOException, an SMTP error) and rethrow it as
 * ProvisioningCheckFailed with a clean, human-readable message.
 *
 * @version 1.0
 */

class ProvisioningCheckFailed extends Exception {
}
