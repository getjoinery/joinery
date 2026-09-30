package com.getjoinery.android.vault

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.setValue

/**
 * The secrets opened into memory after a biometric prompt, and the rule for
 * when they are dropped (specs/fortress_mobile_apps.md § R3): after the app has
 * been in the background for [lockAfterMillis], on sign-out, on a 401, on
 * unlink, and when the server says the key was retired. Dropped means zeroed.
 *
 * [epoch] bumps on every drop, so work that started while a key was held (a
 * drain, a draft save, an AI judgement) can check before it seals or posts
 * that nothing locked underneath it — the browser's lockEpoch. [version] is
 * Compose state, so screens re-render when keys come and go.
 */
class HeldKeys(private val clock: () -> Long = { System.currentTimeMillis() }) {
    private val held = LinkedHashMap<String, ByteArray>()
    private var backgroundedAt: Long? = null
    private val lockListeners = ArrayList<() -> Unit>()
    private val dropAllListeners = ArrayList<() -> Unit>()
    private val epochCounter = java.util.concurrent.atomic.AtomicInteger(0)

    var lockAfterMillis: Long = DEFAULT_LOCK_AFTER_MILLIS

    /** Bumped by every drop; compare before sealing or posting. */
    val epoch: Int get() = epochCounter.get()

    /** Compose-observable change counter. */
    var version by mutableIntStateOf(0)
        private set

    fun hold(name: String, bytes: ByteArray) {
        synchronized(held) {
            held.put(name, bytes.copyOf())?.fill(0)
        }
        bump()
    }

    /** A copy of the held secret (the caller zeroes it), or null. */
    fun get(name: String): ByteArray? = synchronized(held) { held[name]?.copyOf() }

    fun has(name: String): Boolean = synchronized(held) { held.containsKey(name) }

    fun drop(name: String) {
        val removed = synchronized(held) { held.remove(name) }
        if (removed != null) {
            removed.fill(0)
            epochCounter.incrementAndGet()
            bump()
            fireLock()
        }
    }

    fun dropAll() {
        val had = synchronized(held) {
            val any = held.isNotEmpty()
            held.values.forEach { it.fill(0) }
            held.clear()
            any
        }
        epochCounter.incrementAndGet()
        synchronized(dropAllListeners) { dropAllListeners.toList() }.forEach { try { it() } catch (_: Exception) {} }
        if (had) {
            bump()
            fireLock()
        }
    }

    /** Something about the stored keys changed (a key forgotten while it was
     *  not held): re-render whatever shows the key's state. */
    fun changed() = bump()

    /** Called when the app leaves the foreground. */
    fun onBackground() {
        backgroundedAt = clock()
    }

    /** Called when the app returns; drops everything if it was away too long.
     *  Returns true when it dropped. */
    fun onForeground(): Boolean {
        val since = backgroundedAt ?: return false
        backgroundedAt = null
        if (clock() - since >= lockAfterMillis) {
            dropAll()
            return true
        }
        return false
    }

    /** The timer in the background fired: drop now if still away. */
    fun backgroundTimerFired() {
        val since = backgroundedAt ?: return
        if (clock() - since >= lockAfterMillis) dropAll()
    }

    /** fn runs whenever everything is dropped (the lock rule, sign-out): the
     *  secret store shuts with it. */
    fun onDropAll(fn: () -> Unit) {
        synchronized(dropAllListeners) { dropAllListeners.add(fn) }
    }

    /** fn runs after a drop, so a screen can clear opened plaintext with it. */
    fun onLock(fn: () -> Unit) {
        synchronized(lockListeners) { lockListeners.add(fn) }
    }

    fun removeOnLock(fn: () -> Unit) {
        synchronized(lockListeners) { lockListeners.remove(fn) }
    }

    private fun fireLock() {
        val copy = synchronized(lockListeners) { lockListeners.toList() }
        copy.forEach { try { it() } catch (_: Exception) {} }
    }

    private fun bump() {
        try {
            version += 1
        } catch (_: Exception) {
            // Outside a snapshot-aware thread in a unit test: nothing observes.
        }
    }

    companion object {
        /** The browser idle lock's spirit: five minutes away and the key goes. */
        const val DEFAULT_LOCK_AFTER_MILLIS = 5 * 60 * 1000L
    }
}
