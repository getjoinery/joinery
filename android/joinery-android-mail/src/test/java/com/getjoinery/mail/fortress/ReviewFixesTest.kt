package com.getjoinery.mail.fortress

import com.getjoinery.android.JsonValue
import com.getjoinery.android.vault.DeviceKeys
import com.getjoinery.android.vault.HeldKeys
import com.getjoinery.android.vault.MemoryKeyValueStore
import com.getjoinery.android.vault.MemorySecretStore
import com.getjoinery.mail.ai.AiJudge
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class ReviewFixesTest {
    // F4, R10–R12

    private val heap = 512L * 1024 * 1024

    @Test
    fun relayCapIsTheBrowsersOrASixteenthOfTheHeap() {
        assertEquals(RelayDrainGuard.BROWSER_CAP, RelayDrainGuard.capFor(heap))
        assertEquals(96L * 1024 * 1024 / 16, RelayDrainGuard.capFor(96L * 1024 * 1024))
    }

    @Test
    fun anOutOfMemoryHalvesTheCapAndItStaysHalved() {
        val prefs = MemoryKeyValueStore()
        val g = RelayDrainGuard(prefs, heap)
        g.shrink()
        assertEquals(RelayDrainGuard.BROWSER_CAP / 2, RelayDrainGuard(prefs, heap).capBytes)
        repeat(30) { g.shrink() }
        assertEquals(RelayDrainGuard.MIN_CAP, g.capBytes)
    }

    // X4, L2: the cap comes back, but only on evidence.

    @Test
    fun theCapGrowsBackOnlyAfterParsingNearIt() {
        val prefs = MemoryKeyValueStore()
        val g = RelayDrainGuard(prefs, heap)
        g.shrink(); g.shrink()
        val quarter = RelayDrainGuard.BROWSER_CAP / 4
        assertEquals(quarter, g.capBytes)
        // An idle phone proves nothing: empty drains never grow it.
        repeat(100) { g.cleanDrain(parsed = 0, largestParsedBytes = 0) }
        assertEquals(quarter, g.capBytes)
        // Drains of small mail count, but growing waits for a message over half the cap.
        repeat(20) { g.cleanDrain(parsed = 3, largestParsedBytes = 10_000) }
        assertEquals(quarter, g.capBytes)
        g.cleanDrain(parsed = 1, largestParsedBytes = quarter / 2 + 1)
        assertEquals(RelayDrainGuard.BROWSER_CAP / 2, g.capBytes)
        // Memory trouble starts the count over.
        repeat(RelayDrainGuard.CLEAN_DRAINS_TO_GROW - 1) { g.cleanDrain(1, RelayDrainGuard.BROWSER_CAP) }
        g.shrink()
        g.cleanDrain(1, RelayDrainGuard.BROWSER_CAP)
        assertEquals(quarter, g.capBytes)
        repeat(50) { g.cleanDrain(1, RelayDrainGuard.BROWSER_CAP) }
        assertEquals("never past the ceiling", g.ceiling, g.capBytes)
        g.shrink()
        g.clear()
        assertEquals(g.ceiling, g.capBytes)
        g.shrink()
        g.startDrain(9) // a new key generation
        assertEquals(g.ceiling, g.capBytes)
    }

    // X5: an answer larger than estimated asks smaller for this drain only.

    @Test
    fun theResponseLimitCoversEscapedSlashesAndAskingSmallerIsNotRemembered() {
        val prefs = MemoryKeyValueStore()
        val g = RelayDrainGuard(prefs, heap)
        g.startDrain(1)
        val cap = g.capBytes
        // A message right at the cap, its base64 grown 1.6% by '\/' escapes, fits.
        assertTrue(cap * 4 / 3 * 1016 / 1000 + 64 * 1024 <= g.maxResponseBytes)
        assertTrue(g.askSmaller())
        assertEquals(cap / 2, g.requestCap)
        assertEquals(cap, g.capBytes)
        assertEquals("not remembered", cap, RelayDrainGuard(prefs, heap).capBytes)
        g.startDrain(1)
        assertEquals(cap, g.requestCap)
    }

    @Test
    fun onlyTheMessagesOwnFailuresAreRemembered() {
        fun c(t: Throwable) = RelayDrainGuard.classify(t)
        assertEquals(RelayDrainGuard.Failure.SKIP_AND_SHRINK, c(OutOfMemoryError()))
        assertEquals(RelayDrainGuard.Failure.RETRY_LATER, c(com.getjoinery.android.vault.SecretStoreLockedException()))
        assertEquals(RelayDrainGuard.Failure.SKIP, c(com.getjoinery.android.vault.VaultCryptoException("will not open")))
        assertEquals(RelayDrainGuard.Failure.SKIP, c(IllegalStateException("parse")))
        assertEquals(RelayDrainGuard.Failure.RETRY_LATER, c(com.getjoinery.android.JoineryApiError.Server("ActionError", "deploy", 503)))
        assertEquals(RelayDrainGuard.Failure.RETRY_LATER, c(com.getjoinery.android.JoineryApiError.RateLimited("slow down")))
        assertEquals(RelayDrainGuard.Failure.RETRY_LATER, c(com.getjoinery.android.JoineryApiError.Malformed))
        assertEquals(RelayDrainGuard.Failure.RETRY_LATER, c(com.getjoinery.android.JoineryApiError.Network(java.io.IOException())))
        assertEquals(RelayDrainGuard.Failure.RETRY_LATER, c(com.getjoinery.android.JoineryApiError.Authentication("x", 401)))
    }

    @Test
    fun aSkipIsRememberedForThatKeyOnly() {
        val prefs = MemoryKeyValueStore()
        val g = RelayDrainGuard(prefs, heap)
        g.startDrain(3)
        g.skip(41, "v1.edgeseal.mail.OLD")
        val again = RelayDrainGuard(prefs, heap)
        again.startDrain(3)
        assertEquals("41", again.skipParam())
        assertTrue(again.isSkipped(41, "v1.edgeseal.mail.OLD"))
        // Re-keyed by a rotation: a new message as far as the skip goes.
        assertFalse(again.isSkipped(41, "v1.edgeseal.mail.NEW"))
        assertFalse(again.isSkipped(41, "v1.edgeseal.mail.OLD"))
    }

    @Test
    fun aNewKeyGenerationStartsClean() {
        val prefs = MemoryKeyValueStore()
        val g = RelayDrainGuard(prefs, heap)
        g.startDrain(3)
        g.skip(41, "k")
        g.startDrain(4)
        assertEquals("", g.skipParam())
        g.skip(42, "k")
        g.clear()
        assertEquals("", RelayDrainGuard(prefs, heap).skipParam())
    }

    // X6: a process death gives the message one more try, whatever happens in between.

    @Test
    fun anLmkKillThenOfflineThenOnlineRetriesTheMessage() {
        val prefs = MemoryKeyValueStore()
        // Launch 1: parsing 77 when the system kills the process.
        RelayDrainGuard(prefs, heap).apply { startDrain(1); begin(77, "k") }
        // Launch 2: offline; the drain never reaches a message.
        RelayDrainGuard(prefs, heap).apply { startDrain(1) }
        // Launch 3: online; 77 comes first and is tried, not skipped.
        val g = RelayDrainGuard(prefs, heap)
        g.startDrain(1)
        assertFalse(g.isSkipped(77, "k"))
        assertEquals("", g.skipParam())
        g.begin(77, "k"); g.end() // parsed
        // Nothing left to trip over later.
        RelayDrainGuard(prefs, heap).apply { startDrain(1); assertFalse(isSkipped(77, "k")) }
    }

    @Test
    fun dyingOnTheSameMessageTwiceSkipsItWithoutShrinking() {
        val prefs = MemoryKeyValueStore()
        RelayDrainGuard(prefs, heap).apply { startDrain(1); begin(77, "k") }
        RelayDrainGuard(prefs, heap).apply { startDrain(1); begin(77, "k") } // the retry dies too
        val g = RelayDrainGuard(prefs, heap)
        g.startDrain(1)
        assertTrue(g.isSkipped(77, "k"))
        assertEquals("a process death does not say it was memory", g.ceiling, g.capBytes)
    }

    /** L1: newer mail arriving first (newest-first ordering) does not reset
     *  the count: M still gets exactly one retry, then a skip. */
    @Test
    fun newerArrivalsBetweenLaunchesDoNotResetTheRetry() {
        val prefs = MemoryKeyValueStore()
        // Launch 1: the process dies parsing M (77).
        RelayDrainGuard(prefs, heap).apply { startDrain(1); begin(77, "m") }
        // Launch 2: newer mail (78) comes first and parses; then M, the retry, dies again.
        RelayDrainGuard(prefs, heap).apply {
            startDrain(1)
            assertFalse(isSkipped(77, "m"))
            begin(78, "n"); end()
            begin(77, "m")
        }
        // Launch 3: more newer mail (79) comes first; M is skipped, not retried again.
        val g = RelayDrainGuard(prefs, heap)
        g.startDrain(1)
        g.begin(79, "o"); g.end()
        assertTrue(g.isSkipped(77, "m"))
        assertEquals("the second death is not memory", g.ceiling, g.capBytes)
        // Newer mail was never touched by it.
        assertFalse(g.isSkipped(78, "n"))
        assertFalse(g.isSkipped(79, "o"))
    }

    @Test
    fun aRetryThatParsesIsSettled() {
        val prefs = MemoryKeyValueStore()
        RelayDrainGuard(prefs, heap).apply { startDrain(1); begin(77, "m") }
        RelayDrainGuard(prefs, heap).apply { startDrain(1); begin(78, "n"); end(); begin(77, "m"); end() }
        // M dies much later: a first death again, so owed a retry, not skipped.
        RelayDrainGuard(prefs, heap).apply { startDrain(1); begin(77, "m") }
        val g = RelayDrainGuard(prefs, heap)
        g.startDrain(1)
        assertFalse(g.isSkipped(77, "m"))
    }

    // X7: the key locking part way skips nothing.

    @Test
    fun aLockMidItemIsRetriedLater() {
        assertEquals(RelayDrainGuard.Failure.RETRY_LATER, RelayDrainGuard.classify(com.getjoinery.android.vault.SecretStoreLockedException()))
        assertEquals(RelayDrainGuard.Failure.RETRY_LATER, RelayDrainGuard.classify(com.getjoinery.android.JoineryApiError.TooLarge(1)))
    }

    @Test
    fun whatWaitsIsSaidTruly() {
        assertEquals("2 new end-to-end messages being opened on this phone.", RelayDrainGuard.statusLine(2, 0, handedOut = true))
        assertNull(RelayDrainGuard.statusLine(0, 0, handedOut = false))
        assertEquals("3 new end-to-end messages are too large to open on this phone; open them on a computer.",
            RelayDrainGuard.statusLine(3, 3, handedOut = false))
        assertEquals("1 new end-to-end message is too large to open on this phone; open it on a computer. " +
            "2 new end-to-end messages could not be opened on this phone. Another of your devices may open them.",
            RelayDrainGuard.statusLine(3, 1, handedOut = false))
    }

    @Test
    fun tooLargeBySizeOrBySealedText() {
        val g = RelayDrainGuard(MemoryKeyValueStore(), 16L * 1024 * 1024) // cap 1 MiB
        assertTrue(g.tooLarge(g.capBytes + 1, 10))
        assertTrue(g.tooLarge(10, g.maxResponseBytes + 1))
        assertFalse(g.tooLarge(g.capBytes, g.capBytes))
    }

    // F5: a redirect from the model is an error, never a second request.

    @Test
    fun aRedirectStopsTheJudgement() = runBlocking {
        val calls = ArrayList<String>()
        val judge = AiJudge(
            open = { mapOf("iem_subject" to "s", "iem_body_plain" to "b") },
            keyOf = { ByteArray(32) },
            transport = { url, _, _ -> calls.add(url); 307 to "" },
            post = { _, _ -> JsonValue.Null },
            epoch = { 0 },
        )
        val entry = JsonValue.parse("""{"id":5,"sealed":{"key":5,"sealed_dek":"x","sealed_ad_prefix":"mail:"}}""")
        val recipe = JsonValue.parse("""{"recipe_id":1,"job_id":"email_triage","system":"s","nonce":"n","max_tokens":10}""")
        val out = judge.judge(entry, recipe, AiJudge.Endpoint("http://10.0.0.2:11434/v1/chat/completions", "", "m"))
        assertTrue("$out", out is AiJudge.Outcome.Stop)
        assertEquals(307, (out as AiJudge.Outcome.Stop).http)
        assertEquals(1, calls.size)
    }

    // F24

    @Test
    fun closingTheOpenerRemovesItsLockListener() {
        val held = HeldKeys()
        val keys = DeviceKeys(MemorySecretStore(), MemoryKeyValueStore(), held)
        var fired = 0
        held.onLock { fired++ }
        val openers = (1..5).map { FortressOpener(keys) { ByteArray(0) } }
        openers.forEach { it.close() }
        held.hold("x", byteArrayOf(1))
        held.dropAll()
        assertEquals("only the listener still registered runs", 1, fired)
    }

    @Test
    fun u180eSplitsWordsAsPhpsPcre2Does() {
        // PHP 8 / PCRE2 10.42 on the server: preg_split('/\s+/u', "a\u{180E}b")
        // gives ["a","b"] (checked 2026-09-30), so the port keeps U+180E in \s.
        val rule = JsonValue.parse("""{"id":1,"match":{"has_words":"alpha᠎beta"}}""")
        val msg = FilterMatch.message("x@y", "box@z", "s", "alpha then beta", "", 10, false)
        assertTrue(FilterMatch.matches(rule, msg))
    }
}
