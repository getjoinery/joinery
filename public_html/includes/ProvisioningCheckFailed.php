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
 * @version 1.2 - ProvisioningCheckRefused, the cannot-be-met-here grade
 * @version 1.1 - ProvisioningCheckPending, the converging-not-broken grade
 */

class ProvisioningCheckFailed extends Exception {
}

/**
 * A check that is unmet but CONVERGING: the machinery that fixes it is alive
 * and will do so on its next pass, so the state is expected and temporary —
 * a relay map waiting on the next reconcile tick, not a dead task.
 *
 * A subclass, deliberately: every existing catch of ProvisioningCheckFailed
 * still treats it as unmet, so nothing reads a pending state as healthy.
 * Surfaces that can render a middle grade (the Setup tab's relay card)
 * instanceof-check for it and show a warning instead of a red failure.
 */
class ProvisioningCheckPending extends ProvisioningCheckFailed {
}

/**
 * A check that is unmet and CANNOT BE MET on this site: the dependency does
 * not apply here (a host service a container site is never given), so the
 * provisioner's fix script would only refuse again. The message says why.
 *
 * Still unmet, like Pending; PluginProvisioning offers no fix command for it.
 */
class ProvisioningCheckRefused extends ProvisioningCheckFailed {
}
