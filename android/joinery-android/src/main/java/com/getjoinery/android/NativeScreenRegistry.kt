package com.getjoinery.android

import androidx.compose.runtime.Composable

/** Everything a registered native screen needs from the shell. `onExit` returns
 *  to the previous screen, or is null when the screen sits at a navigation root
 *  with nowhere to go back to — a screen hides its back affordance then. */
class NativeScreenContext(
    val session: SessionController,
    val user: UserSummary,
    val web: WebSessionCoordinator,
    val onExit: (() -> Unit)? = null,
)

/**
 * The app-extensible native-screen table behind the navigation routing rule: a
 * `{type: "native", screen}` destination renders natively when the build knows
 * the screen name, else falls back to the entry's web URL.
 *
 * Layered modules (a future JoineryMail / DNS-filter module) register their
 * screen names at app launch; joinery-android's own screens ("settings")
 * resolve in the shell before this table is consulted. A registered screen owns
 * its own chrome and drives navigation-back through [NativeScreenContext.onExit].
 * Registration happens once from the app's init, so storage is a plain
 * synchronized map — the builders run with the composition.
 */
object NativeScreenRegistry {
    private val builders = LinkedHashMap<String, @Composable (NativeScreenContext) -> Unit>()
    private val lock = Any()

    /** Register (or replace) the builder for a screen name. */
    fun register(name: String, builder: @Composable (NativeScreenContext) -> Unit) {
        synchronized(lock) { builders[name] = builder }
    }

    /** Remove a screen name so it resolves to its web fallback again. Lets a
     *  build deterministically opt out of a native screen regardless of what
     *  earlier registrations ran in the same process (instrumented fallback
     *  test). No-op when the name was never registered. */
    fun unregister(name: String) {
        synchronized(lock) { builders.remove(name) }
    }

    /** Does this build know the screen name? */
    fun contains(name: String): Boolean = synchronized(lock) { builders.containsKey(name) }

    private fun builder(name: String): (@Composable (NativeScreenContext) -> Unit)? =
        synchronized(lock) { builders[name] }

    /** Render the named screen if registered; returns whether it was found. */
    @Composable
    fun Render(name: String, context: NativeScreenContext): Boolean {
        val builder = builder(name) ?: return false
        builder(context)
        return true
    }
}
