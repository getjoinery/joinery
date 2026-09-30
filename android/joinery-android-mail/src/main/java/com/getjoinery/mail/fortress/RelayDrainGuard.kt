package com.getjoinery.mail.fortress

import com.getjoinery.android.JoineryApiError
import com.getjoinery.android.vault.KeyValueStore
import com.getjoinery.android.vault.SecretStoreLockedException
import com.getjoinery.android.vault.VaultCrypto

/**
 * What keeps one relay-sealed message from stopping the phone (F4, R10–R12,
 * X4–X7): parsing holds the message several times over, so a large one could
 * exhaust the heap — and an OutOfMemoryError crashes the process, and the next
 * mailbox open would fetch the same message first and crash again.
 *
 *  - [capBytes]: the largest message this phone asks for (`max_bytes`) and
 *    opens: the browser's 25 MiB, or a sixteenth of the heap when smaller.
 *    Running out of memory halves it (remembered); after
 *    [CLEAN_DRAINS_TO_GROW] clean drains that parsed mail, one that parses a
 *    message over half the cap doubles it back toward that ceiling; a
 *    sign-out, a key wipe or a new key generation resets it.
 *  - An answer larger than the phone will hold asks smaller for the rest of
 *    that drain only ([requestCap]): the message may be healthy, the envelope
 *    just larger than estimated.
 *  - A message that cannot be opened here (crypto, parse, memory) is skipped:
 *    remembered by id and a hash of its key (a row a rotation re-keys is a new
 *    message) under the key generation it was skipped in, and sent as `skip`.
 *    Transient failures (offline, a server error, a rate limit, a bad answer,
 *    the key locking part way) skip nothing: the drain stops and tries later.
 *  - The message being parsed is written down first ([begin]); one still
 *    written down at the next drain was in flight when the process died. It
 *    is owed a retry: tried once more (the system may just have reclaimed
 *    memory), and skipped only if the process dies on it again. The retry is
 *    kept, whatever arrives in between, until that message is parsed.
 */
class RelayDrainGuard(private val prefs: KeyValueStore, private val heapBytes: Long) {

    enum class Failure {
        /** This message cannot be opened here: remember it. */
        SKIP,
        /** Out of memory: skip it and ask for smaller messages from now on. */
        SKIP_AND_SHRINK,
        /** Nothing wrong with the message: stop, try again later. */
        RETRY_LATER,
    }

    // MARK: The cap

    val ceiling: Long get() = capFor(heapBytes)

    val capBytes: Long
        get() = prefs.get(CAP)?.toLongOrNull()?.coerceIn(MIN_CAP, ceiling) ?: ceiling

    /** This drain's request size: the cap, halved for the drain by [askSmaller]. */
    var requestCap: Long = 0
        private set

    /** The most bytes an answer may be for a message of [requestCap]: base64
     *  (4/3), the JSON's escaped slashes and the seal's framing (5%), and 1 MiB
     *  for the rest of the envelope. */
    val maxResponseBytes: Long get() = responseLimitFor(requestCap.takeIf { it > 0 } ?: capBytes)

    /** Out of memory: ask for messages half this size from now on. */
    fun shrink() {
        prefs.put(CAP, maxOf(MIN_CAP, capBytes / 2).toString())
        prefs.put(CLEAN, null)
    }

    /** An answer too large to hold: ask smaller for the rest of this drain
     *  only. False when already at the floor. */
    fun askSmaller(): Boolean {
        if (requestCap <= MIN_CAP) return false
        requestCap = maxOf(MIN_CAP, requestCap / 2)
        return true
    }

    /**
     * A drain that met no memory trouble. Only one that parsed something
     * counts (an idle phone proves nothing, L2), and the cap grows only once
     * [CLEAN_DRAINS_TO_GROW] such drains have passed and this one parsed a
     * message larger than half the cap — evidence the phone copes near it.
     */
    fun cleanDrain(parsed: Int, largestParsedBytes: Long) {
        if (capBytes >= ceiling) { prefs.put(CLEAN, null); return }
        if (parsed <= 0) return
        val n = (prefs.get(CLEAN)?.toIntOrNull() ?: 0) + 1
        if (n >= CLEAN_DRAINS_TO_GROW && largestParsedBytes > capBytes / 2) {
            prefs.put(CAP, minOf(ceiling, capBytes * 2).toString())
            prefs.put(CLEAN, null)
        } else {
            prefs.put(CLEAN, minOf(n, CLEAN_DRAINS_TO_GROW).toString())
        }
    }

    // MARK: Skips

    private data class Entry(val id: Int, val keyHash: String)

    private fun entries(): List<Entry> = (prefs.get(SKIPPED) ?: "").split(',').mapNotNull {
        val p = it.split(':')
        if (p.size == 2) p[0].toIntOrNull()?.let { id -> Entry(id, p[1]) } else null
    }

    private fun store(list: List<Entry>) =
        prefs.put(SKIPPED, list.takeLast(MAX_REMEMBERED).joinToString(",") { "${it.id}:${it.keyHash}" })

    /** Ids skipped for the rest of this drain only (a returned message found
     *  in the remembered list, re-skipped without being opened). */
    private val drainSkips = LinkedHashSet<Int>()

    /** A drain begins: a new key generation starts clean, this drain asks at
     *  the cap, and a message the last process died on becomes the retry. */
    fun startDrain(keyGeneration: Int?) {
        if (keyGeneration != null && prefs.get(GENERATION) != keyGeneration.toString()) {
            clear()
            prefs.put(GENERATION, keyGeneration.toString())
        }
        drainSkips.clear()
        requestCap = capBytes
        recoverCrashed()
    }

    /** Whether this item, as fetched, was skipped before. A skip under
     *  another key hash is stale (the row was re-keyed) and is forgotten. */
    fun isSkipped(id: Int, sealedDek: String): Boolean {
        val hash = keyHash(sealedDek)
        val list = entries()
        val mine = list.filter { it.id == id }
        if (mine.isEmpty()) return false
        if (mine.any { it.keyHash == hash }) {
            drainSkips.add(id)
            return true
        }
        store(list.filter { it.id != id })
        return false
    }

    fun skip(id: Int, sealedDek: String) = skipKey(id, keyHash(sealedDek))

    private fun skipKey(id: Int, hash: String) {
        store(entries().filter { it.id != id } + Entry(id, hash))
        drainSkips.add(id)
    }

    /** The ids to send as `skip` (the server takes 100; the newest matter). */
    fun skipParam(): String = (entries().map { it.id } + drainSkips).distinct().takeLast(100).joinToString(",")

    // MARK: In flight, and the retries

    /** Messages the process died on once, each owed one more try. A retry is
     *  kept until that message's own parse ends or [clear] (L1): newer mail
     *  arriving first must not reset the count. One for a message another
     *  device parsed is harmless and ages out. */
    private fun retries(): List<String> = (prefs.get(RETRY) ?: "").split(',').filter { it.isNotEmpty() }

    private fun storeRetries(list: List<String>) =
        prefs.put(RETRY, list.takeLast(MAX_RETRIES).joinToString(",").ifEmpty { null })

    fun begin(id: Int, sealedDek: String) = prefs.put(IN_FLIGHT, "$id:${keyHash(sealedDek)}")

    /** Parsing finished, however it ended: nothing is in flight, and this
     *  message's retry, if it had one, is settled. */
    fun end() {
        val key = prefs.get(IN_FLIGHT)
        if (key != null) storeRetries(retries().filter { it != key })
        prefs.put(IN_FLIGHT, null)
    }

    /**
     * A message still in flight: the process died parsing it. The first time
     * it is owed a retry; if it already was, it is skipped (no shrink: a
     * process death does not say it was memory). Returns the id skipped, if any.
     */
    internal fun recoverCrashed(): Int? {
        val key = prefs.get(IN_FLIGHT) ?: return null
        prefs.put(IN_FLIGHT, null)
        val id = key.substringBefore(':').toIntOrNull() ?: return null
        val owed = retries()
        if (key in owed) {
            storeRetries(owed.filter { it != key })
            skipKey(id, key.substringAfter(':', ""))
            return id
        }
        storeRetries(owed + key)
        return null
    }

    /** Too large to open here, by the row's size or by the sealed text's. */
    fun tooLarge(sizeBytes: Long, sealedChars: Long): Boolean =
        sizeBytes > capBytes || sealedChars > responseLimitFor(capBytes)

    /** A key wipe, a sign-out, a new key generation: nothing remembered
     *  applies to the next key, and the cap starts at the ceiling again. */
    fun clear() {
        listOf(SKIPPED, IN_FLIGHT, RETRY, GENERATION, CAP, CLEAN).forEach { prefs.put(it, null) }
        drainSkips.clear()
        requestCap = capBytes
    }

    companion object {
        const val BROWSER_CAP = 25L * 1024 * 1024
        const val MIN_CAP = 256L * 1024
        const val CLEAN_DRAINS_TO_GROW = 5
        private const val SKIPPED = "relay.skipped"
        private const val IN_FLIGHT = "relay.in_flight"
        private const val RETRY = "relay.retry"
        private const val GENERATION = "relay.generation"
        private const val CAP = "relay.cap"
        private const val CLEAN = "relay.clean_drains"
        private const val MAX_REMEMBERED = 500
        private const val MAX_RETRIES = 50

        /** The browser's cap, or a sixteenth of the heap when smaller: parsing
         *  holds several copies, and the fetch itself several more (R10). */
        fun capFor(maxHeapBytes: Long): Long = maxOf(MIN_CAP, minOf(BROWSER_CAP, maxHeapBytes / 16))

        fun responseLimitFor(cap: Long): Long = (cap * 4 / 3 * 105 / 100) + 1024L * 1024

        /**
         * The progress line for a fortress_pending answer. With nothing handed
         * out, `remaining` is what still waits and `too_large` how many of
         * those exceed this phone's limit.
         */
        fun statusLine(remaining: Int, tooLarge: Int, handedOut: Boolean): String? {
            fun n(k: Int) = "$k new end-to-end message${if (k == 1) "" else "s"}"
            if (handedOut) return if (remaining > 0) "${n(remaining)} being opened on this phone." else null
            if (remaining <= 0) return null
            val big = tooLarge.coerceIn(0, remaining)
            val other = remaining - big
            val parts = ArrayList<String>()
            if (big > 0) parts.add("${n(big)} ${if (big == 1) "is" else "are"} too large to open on this phone; open ${if (big == 1) "it" else "them"} on a computer.")
            if (other > 0) parts.add("${n(other)} could not be opened on this phone. Another of your devices may open ${if (other == 1) "it" else "them"}.")
            return parts.joinToString(" ")
        }

        fun keyHash(sealedDek: String): String =
            VaultCrypto.toHex(VaultCrypto.sha256(sealedDek.toByteArray(Charsets.UTF_8))).take(12)

        /** What a failure while parsing one message means (R11, X7). */
        fun classify(t: Throwable): Failure = when (t) {
            is OutOfMemoryError -> Failure.SKIP_AND_SHRINK
            // The key locked part way: nothing about this message.
            is SecretStoreLockedException -> Failure.RETRY_LATER
            // The server, the network, a rate limit, a malformed answer, a
            // refused key, an answer too large to hold: nothing about it either.
            is JoineryApiError -> Failure.RETRY_LATER
            is java.io.IOException -> Failure.RETRY_LATER
            // Crypto that will not open, a parse that failed, anything else
            // the message itself caused.
            else -> Failure.SKIP
        }
    }
}
