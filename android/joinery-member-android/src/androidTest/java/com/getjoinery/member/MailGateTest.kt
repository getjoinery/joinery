package com.getjoinery.member

import androidx.compose.ui.test.junit4.createEmptyComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextInput
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

/**
 * The absorbed mail-gate legs on the native mail screens (the still-open item
 * from the mail conversion): read + reply, and the folder picker filing a
 * dedicated seeded thread. The gate script seeds the messages (localhost SMTP)
 * and verifies the reply arrival in `iem_inbound_email_messages` and the
 * folder membership in `ilm_inbound_label_members`. The picker leg uses its
 * OWN seeded message — the reply leg retitles its thread to "Re:" and breaks
 * an exact-subject lookup.
 */
@RunWith(AndroidJUnit4::class)
class MailGateTest {

    @get:Rule
    val compose = createEmptyComposeRule()

    @Test
    fun readAndReply() {
        val subject = MemberGate.arg("mail_subject")
        val replyText = MemberGate.arg("mail_reply_text")

        MemberGate.launch()
        compose.signIn()
        compose.openMoreEntry("mailbox")
        compose.awaitTag("mail_list")

        openMessageBySubject(subject)
        compose.awaitTag("mail_thread_subject")
        compose.onNodeWithTag("mail_reply").performClick()

        compose.awaitTag("mail_compose_body")
        compose.onNodeWithTag("mail_compose_body").performTextInput(replyText)
        compose.onNodeWithTag("mail_compose_send").performClick()

        // Back on the thread once the send returns.
        compose.awaitTag("mail_thread_subject")
    }

    @Test
    fun folderPickerFilesThread() {
        val subject = MemberGate.arg("mail_picker_subject")
        val folderName = MemberGate.arg("mail_folder_name")

        MemberGate.launch()
        compose.signIn()
        compose.openMoreEntry("mailbox")
        compose.awaitTag("mail_list")

        openMessageBySubject(subject)
        compose.awaitTag("mail_thread_subject")
        compose.onNodeWithTag("mail_folders").performClick()

        compose.awaitTag("mail_folder_new")
        compose.onNodeWithTag("mail_folder_new").performTextInput(folderName)
        compose.onNodeWithTag("mail_folder_create").performClick()
    }

    private fun openMessageBySubject(subject: String) {
        compose.waitUntil(30_000) {
            compose.onAllNodesWithText(subject).fetchSemanticsNodes().isNotEmpty()
        }
        compose.onAllNodesWithText(subject).onFirst().performClick()
    }
}
