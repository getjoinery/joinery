package com.getjoinery.member

import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createEmptyComposeRule
import androidx.compose.ui.test.onAllNodesWithTag
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performImeAction
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.test.performTextReplacement
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.test.printToLog
import androidx.compose.ui.test.swipeDown
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import androidx.test.uiautomator.By
import androidx.test.uiautomator.UiDevice
import androidx.test.uiautomator.Until
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

/**
 * End-to-end (Fortress) mail on the phone (specs/fortress_mobile_apps.md),
 * driven leg by leg by tests/functional/android/fortress_gate.sh against a
 * walk fixture made with `walk_fixture.php create <purpose> --fortress`. The
 * runner does the server's side between legs and answers every biometric
 * prompt with the emulator's finger; enrollment codes reach it through
 * logcat (tag FmaGate). Every leg after the first keeps the app's sign-in:
 * the key a phone holds is bound to the session key it enrolled with.
 */
@RunWith(AndroidJUnit4::class)
class FortressGateTest {

    @get:Rule
    val compose = createEmptyComposeRule()

    private val device: UiDevice get() = UiDevice.getInstance(InstrumentationRegistry.getInstrumentation())

    private fun shell(cmd: String): String {
        val pfd = InstrumentationRegistry.getInstrumentation().uiAutomation.executeShellCommand(cmd)
        return android.os.ParcelFileDescriptor.AutoCloseInputStream(pfd).bufferedReader().readText()
    }

    private fun arg(name: String): String =
        MemberGate.arg(name).ifEmpty { throw AssertionError("the runner did not pass -e $name") }

    private fun has(text: String) =
        compose.onAllNodesWithText(text, substring = true, useUnmergedTree = true).fetchSemanticsNodes().isNotEmpty()

    private fun hasInnerTag(tag: String) =
        compose.onAllNodesWithTag(tag, useUnmergedTree = true).fetchSemanticsNodes().isNotEmpty()

    private fun dumpScreen() {
        try { compose.onRoot(useUnmergedTree = true).printToLog("FmaGate") } catch (_: Throwable) {}
    }

    private fun <T> onFailureDump(block: () -> T): T = try {
        block()
    } catch (e: Throwable) {
        dumpScreen()
        throw e
    }

    private fun awaitText(text: String, timeoutMs: Long = 60_000) = onFailureDump {
        compose.waitUntil(timeoutMs) { has(text) }
    }

    private fun awaitInnerTag(tag: String, timeoutMs: Long = 30_000) = onFailureDump {
        compose.waitUntil(timeoutMs) { hasInnerTag(tag) }
    }

    /** Pull the list to refresh until [done], for work the phone does on a refresh. */
    private fun refreshUntil(what: String, timeoutMs: Long = 180_000, done: () -> Boolean) {
        val until = System.currentTimeMillis() + timeoutMs
        while (!done()) {
            if (System.currentTimeMillis() > until) { dumpScreen(); throw AssertionError("never saw: $what") }
            if (hasInnerTag("mail_list")) {
                try { compose.onNodeWithTag("mail_list").performTouchInput { swipeDown() } } catch (_: Throwable) {}
            }
            compose.waitForIdle()
            val step = System.currentTimeMillis() + 12_000
            while (System.currentTimeMillis() < step && !done()) Thread.sleep(500)
        }
    }

    private fun openMailbox(keepAuth: Boolean) {
        MemberGate.launch(keepAuth = keepAuth)
        onFailureDump {
            if (keepAuth) compose.awaitTag("nav_tab_more", 60_000) else compose.signIn()
            compose.openNavEntry("mailbox")
            compose.awaitTag("mail_list", 60_000)
        }
    }

    /** Unlock with the finger when the key is locked (a new process). */
    private fun unlockIfLocked() {
        compose.waitUntil(20_000) { hasInnerTag("mail_fortress_unlock") || !hasInnerTag("mail_fortress_banner") }
        if (hasInnerTag("mail_fortress_unlock")) {
            compose.onNodeWithTag("mail_fortress_unlock").performClick()
            onFailureDump { compose.waitUntil(60_000) { !hasInnerTag("mail_fortress_banner") } }
        }
    }

    private fun enroll() {
        compose.onNodeWithTag("mail_fortress_enroll").performClick()
        compose.awaitTag("mail_fortress_enroll_code", 90_000)
        val code = compose.onNodeWithTag("mail_fortress_enroll_code").fetchSemanticsNode()
            .config[androidx.compose.ui.semantics.SemanticsProperties.Text].joinToString("") { it.text }
        android.util.Log.i("FmaGate", "enroll_code=$code")
        onFailureDump {
            compose.waitUntil(120_000) { compose.onAllNodesWithTag("mail_fortress_enroll_sheet").fetchSemanticsNodes().isEmpty() }
        }
        if (hasInnerTag("mail_fortress_enroll_error")) { dumpScreen(); throw AssertionError("enrollment failed") }
    }

    private fun openMessage(subject: String) {
        compose.onAllNodesWithText(subject, substring = true).onFirst().performClick()
        compose.awaitTag("mail_thread_subject")
    }

    private fun backToList() {
        compose.onNodeWithTag("mail_thread_back").performClick()
        compose.awaitTag("mail_list")
    }

    private fun awaitComposeClosed() = onFailureDump {
        compose.waitUntil(90_000) { compose.onAllNodesWithTag("mail_compose_to").fetchSemanticsNodes().isEmpty() }
    }

    // MARK: 1. Enroll, read the list, parse a relay-sealed arrival

    @Test
    fun enroll_and_list() {
        openMailbox(keepAuth = false)
        compose.awaitTag("mail_fortress_enroll")
        awaitInnerTag("mail_row_fortress_note")
        enroll()
        awaitText(arg("mail_subject"))
        refreshUntil("the relay-sealed arrival, parsed here") { has(arg("relay_subject")) }
        // Phone-side search finds a body word.
        // The index builds in the background once the key opens; ask again
        // until it holds the message.
        compose.onNodeWithTag("mail_search").performTextInput(arg("search_word"))
        val until = System.currentTimeMillis() + 120_000
        while (true) {
            compose.onNodeWithTag("mail_search").performImeAction()
            try {
                compose.waitUntil(10_000) { has(arg("search_subject")) }
                break
            } catch (e: Throwable) {
                if (System.currentTimeMillis() > until) { dumpScreen(); throw AssertionError("search on the phone never found it") }
            }
        }
        compose.onNodeWithContentDescription("Clear search").performClick()
    }

    // MARK: 2. Read, open an attachment (and see it swept), reply, forward

    private fun stagedFiles(): String =
        shell("run-as com.getjoinery.member find cache/mail_open -type f").trim()

    @Test
    fun read_reply_forward() {
        openMailbox(keepAuth = true)
        unlockIfLocked()
        awaitText(arg("mail_subject"))
        openMessage(arg("mail_subject"))
        val pdf = arg("pdf_name")
        awaitText(pdf, 30_000)

        // The viewer: the opened PDF is staged privately, and gone once back.
        compose.onAllNodesWithText(pdf).onFirst().performClick()
        onFailureDump { compose.waitUntil(30_000) { stagedFiles().contains(".pdf") } }
        // The chooser (another app's window) is in front; back until the app is.
        Thread.sleep(2_000)
        var backs = 0
        while (device.currentPackageName != "com.getjoinery.member" && backs < 4) {
            device.pressBack(); Thread.sleep(1_500); backs++
        }
        compose.awaitTag("mail_thread_subject")
        onFailureDump { compose.waitUntil(20_000) { stagedFiles().isEmpty() } }

        // Forward first (the thread's newest message is the one forwarded):
        // the parts, opened here, go with it.
        compose.onNodeWithTag("mail_forward").performClick()
        compose.awaitTag("mail_compose_to")
        compose.onNodeWithTag("mail_compose_to").performTextInput(arg("forward_to"))
        compose.onNodeWithTag("mail_compose_send").performClick()
        awaitComposeClosed()

        // Reply: the quote the phone opened goes as source_open.
        compose.awaitTag("mail_reply")
        compose.onNodeWithTag("mail_reply").performClick()
        compose.awaitTag("mail_compose_body")
        compose.onNodeWithTag("mail_compose_body").performTextInput(arg("reply_text"))
        compose.onNodeWithTag("mail_compose_send").performClick()
        awaitComposeClosed()
        backToList()
    }

    // MARK: 3. A draft with a saved attachment, reopened and sent

    @Test
    fun draft_with_attachment() {
        val subject = arg("draft_subject")
        val file = arg("attach_name")
        openMailbox(keepAuth = true)
        unlockIfLocked()
        compose.onNodeWithTag("mail_new_message").performClick()
        compose.awaitTag("mail_compose_to")
        compose.onNodeWithTag("mail_compose_to").performTextInput("someone@example.test")
        compose.onNodeWithTag("mail_compose_subject").performTextInput(subject)
        compose.onNodeWithTag("mail_compose_body").performTextInput("A draft with a file, from the gate.")

        // The system picker.
        compose.onNodeWithTag("mail_compose_attach").performClick()
        compose.onAllNodesWithText("Files").onFirst().performClick()
        if (!device.wait(Until.hasObject(By.text(file)), 8_000)) {
            device.findObject(By.desc("Show roots"))?.click()
            device.wait(Until.hasObject(By.text("Downloads")), 5_000)
            device.findObject(By.text("Downloads"))?.click()
            if (!device.wait(Until.hasObject(By.text(file)), 10_000)) throw AssertionError("the picker never showed $file")
        }
        device.findObject(By.text(file)).click()
        awaitInnerTag("mail_compose_attachment", 20_000)

        // Close: the sheet saves the draft (text, then the part) on the way out.
        compose.onNodeWithTag("mail_compose_cancel").performClick()
        awaitComposeClosed()

        // Drafts: reopen it; the saved part is there; send.
        compose.onNodeWithTag("mail_view_menu").performClick()
        compose.onNodeWithTag("mail_view_drafts").performClick()
        refreshUntil("the draft in Drafts", 60_000) { has(subject) }
        compose.onAllNodesWithText(subject, substring = true).onFirst().performClick()
        awaitInnerTag("mail_compose_saved_attachment", 60_000)
        compose.onNodeWithTag("mail_compose_send").performClick()
        awaitComposeClosed()
        android.util.Log.i("FmaGate", "draft_sent")
    }

    // MARK: 4. Mail rules on relay-sealed mail, and AI on the owner's model

    @Test
    fun rules_and_ai() {
        openMailbox(keepAuth = true)
        unlockIfLocked()

        // Where the model is: the origin is the account's; path and model are the phone's.
        compose.onNodeWithTag("mail_view_menu").performClick()
        compose.onNodeWithTag("mail_menu_ai").performClick()
        onFailureDump { compose.waitUntil(30_000) { hasInnerTag("mail_ai_model") || hasInnerTag("mail_ai_no_origin") } }
        if (hasInnerTag("mail_ai_no_origin")) { dumpScreen(); throw AssertionError("no model origin registered") }
        compose.onNodeWithTag("mail_ai_path").performTextReplacement("/v1")
        compose.onNodeWithTag("mail_ai_model").performTextInput(arg("ai_model"))
        compose.onNodeWithTag("mail_ai_test").performClick()
        awaitText("Reachable", 60_000)
        compose.onNodeWithTag("mail_ai_save").performClick()
        awaitText("Saved.", 60_000)
        device.pressBack()
        compose.waitUntil(10_000) { !hasInnerTag("mail_ai_sheet") }

        refreshUntil("the two rule messages, parsed here") { has(arg("star_subject")) && has(arg("forward_subject")) }
        refreshUntil("a summary from the stand-in model", 240_000) { has(arg("ai_summary")) }
    }

    // MARK: 5. A retired key (B6): wiped, enrolled again, new mail read

    @Test
    fun retired_key_reenroll() {
        openMailbox(keepAuth = true)
        // The probe says this phone no longer holds the mail key: it forgets
        // it and offers Enroll, with no unlock in between.
        refreshUntil("the enroll banner after the key was retired", 90_000) { hasInnerTag("mail_fortress_enroll") }
        enroll()
        refreshUntil("mail sealed to the new key, read here") { has(arg("after_subject")) }
    }

    // MARK: 6. The new-mail check

    /** The scheduled worker's own code, run now (the runner checks the job is
     *  scheduled and that the notification names the mailbox). */
    @Test
    fun polling_worker() {
        val context = androidx.test.core.app.ApplicationProvider.getApplicationContext<android.content.Context>()
        val worker = androidx.work.testing.TestListenableWorkerBuilder<com.getjoinery.mail.notify.MailPollWorker>(context).build()
        val result = kotlinx.coroutines.runBlocking { worker.doWork() }
        if (result != androidx.work.ListenableWorker.Result.success()) throw AssertionError("the worker answered $result")
    }
}
