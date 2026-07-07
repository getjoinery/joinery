package com.getjoinery.member

import android.content.Intent
import androidx.compose.ui.test.ExperimentalTestApi
import androidx.compose.ui.test.hasTestTag
import androidx.compose.ui.test.junit4.ComposeTestRule
import androidx.compose.ui.test.onAllNodesWithTag
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollToNode
import androidx.compose.ui.test.performTextInput
import androidx.test.core.app.ActivityScenario
import androidx.test.core.app.ApplicationProvider
import androidx.test.platform.app.InstrumentationRegistry

/**
 * Shared harness for the Android member gate: launches the member app against
 * a target deployment with instrumented-test intent extras, signs in through
 * the login UI, and drives the More list / dashboard navigation. Server-driven
 * values (target URL, creds, seeded names) arrive as instrumentation arguments
 * from `tests/functional/android/member_gate.sh`.
 */
object MemberGate {

    /** Instrumentation `-e` args carry non-secret values on the process command
     *  line. The fixture password is deliberately NOT passed this way (it would
     *  land in argv on three hosts); the gate script streams the creds file to
     *  the device over stdin and the tests read it here. An explicit `-e
     *  password` still overrides, so a single leg can be run by hand. */
    private const val DEVICE_CREDS = "/data/local/tmp/joinery_member_gate.creds"

    fun arg(name: String, default: String = ""): String =
        InstrumentationRegistry.getArguments().getString(name) ?: default

    private val deviceCreds: Map<String, String> by lazy {
        try {
            // /data/local/tmp is shell-owned; SELinux blocks a direct File read
            // from the app process. UiAutomation runs the read at shell
            // privilege, which is exactly the trust level that wrote the file.
            val pfd = InstrumentationRegistry.getInstrumentation().uiAutomation
                .executeShellCommand("cat $DEVICE_CREDS")
            android.os.ParcelFileDescriptor.AutoCloseInputStream(pfd)
                .bufferedReader().readLines().mapNotNull { line ->
                    val i = line.indexOf('=')
                    if (i > 0) line.substring(0, i).trim() to line.substring(i + 1).trim() else null
                }.toMap()
        } catch (e: Exception) {
            emptyMap()
        }
    }

    private fun credOrDevice(name: String): String =
        arg(name).ifEmpty { deviceCreds[name] ?: "" }

    val baseUrl: String get() = arg("base_url", "https://dev.getjoinery.com")
    val email: String get() = credOrDevice("email")
    val password: String get() = credOrDevice("password")
    val clientVersion: String get() = arg("client_version", "9.9.9")

    /** Launch MainActivity signed out (credentials wiped). [disableMember] skips
     *  the member module registration to exercise the web fallback. */
    fun launch(disableMember: Boolean = false): ActivityScenario<MainActivity> {
        val context = ApplicationProvider.getApplicationContext<android.content.Context>()
        val intent = Intent(context, MainActivity::class.java).apply {
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            putExtra("reset_auth", true)
            putExtra("base_url", baseUrl)
            putExtra("client_version", clientVersion)
            if (disableMember) putExtra("disable_member_module", true)
        }
        return ActivityScenario.launch(intent)
    }
}

/** Wait until at least one node with [tag] exists, then return it. */
@OptIn(ExperimentalTestApi::class)
fun ComposeTestRule.awaitTag(tag: String, timeoutMs: Long = 30_000) {
    waitUntil(timeoutMs) { onAllNodesWithTag(tag).fetchSemanticsNodes().isNotEmpty() }
}

fun ComposeTestRule.hasTag(tag: String): Boolean =
    onAllNodesWithTag(tag).fetchSemanticsNodes().isNotEmpty()

/** Sign in through the login screen and wait for the signed-in shell. The
 *  More tab is the one bottom-bar item every nav config renders, so it is the
 *  signed-in signal regardless of which entries the server pins as tabs. */
fun ComposeTestRule.signIn() {
    awaitTag("login_submit")
    onNodeWithTag("login_email").performTextInput(MemberGate.email)
    onNodeWithTag("login_password").performTextInput(MemberGate.password)
    onNodeWithTag("login_submit").performClick()
    awaitTag("nav_tab_more")
}

/** Open a navigation entry by slug (e.g. "core-profile", "mailbox") wherever
 *  the server's nav config put it: a pinned bottom tab when one exists,
 *  otherwise through the More list. A previously opened More entry stays
 *  pushed over the list, so system-back pops until the target row shows. */
fun ComposeTestRule.openNavEntry(slug: String) {
    awaitTag("nav_tab_more")
    if (hasTag("nav_tab_$slug")) {
        onNodeWithTag("nav_tab_$slug").performClick()
        return
    }
    onNodeWithTag("nav_tab_more").performClick()
    waitForIdle()
    var pops = 0
    while (!hasTag("more_$slug") && pops < 5) {
        androidx.test.espresso.Espresso.pressBack()
        waitForIdle()
        pops++
    }
    awaitTag("more_$slug")
    onNodeWithTag("more_$slug").performClick()
}

/** Scroll a lazy list to a descendant node carrying [tag] (off-screen nodes are
 *  not otherwise addressable). */
@OptIn(ExperimentalTestApi::class)
fun ComposeTestRule.scrollListTo(listTag: String, tag: String) {
    onNodeWithTag(listTag).performScrollToNode(hasTestTag(tag))
}
