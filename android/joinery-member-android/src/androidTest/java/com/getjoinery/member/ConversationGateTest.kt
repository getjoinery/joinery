package com.getjoinery.member

import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createEmptyComposeRule
import androidx.compose.ui.test.onAllNodesWithTag
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.test.swipeLeft
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

/**
 * Conversation round-trip: open the seeded 1:1 thread, send a timestamped
 * reply, and confirm it renders. The gate script verifies the row landed in
 * `msg_messages` server-side. Requires `conversation_other_name` and
 * `conversation_reply_text` instrumentation arguments (from the peer/thread
 * seeded by `phase3_conversation_fixtures.php`).
 */
@RunWith(AndroidJUnit4::class)
class ConversationGateTest {

    @get:Rule
    val compose = createEmptyComposeRule()

    @Test
    fun sendReplyInSeededThread() {
        val otherName = MemberGate.arg("conversation_other_name")
        val replyText = MemberGate.arg("conversation_reply_text")

        MemberGate.launch()
        compose.signIn()

        compose.openNavEntry("core-profile")
        compose.awaitTag("member_profile_dashboard")
        compose.scrollListTo("member_profile_dashboard", "member_profile_tile_conversations")
        compose.onNodeWithTag("member_profile_tile_conversations").performClick()

        compose.awaitTag("member_conversations_list")
        // Open the seeded peer's thread by its display name.
        compose.waitUntil(30_000) {
            compose.onAllNodesWithText(otherName).fetchSemanticsNodes().isNotEmpty()
        }
        compose.onAllNodesWithText(otherName).onFirst().performClick()

        compose.awaitTag("member_conversation_composer")
        compose.onNodeWithTag("member_conversation_composer").performTextInput(replyText)
        compose.onNodeWithTag("member_conversation_send").performClick()

        // The sent bubble renders locally once the send returns.
        compose.waitUntil(30_000) {
            compose.onAllNodesWithText(replyText).fetchSemanticsNodes().isNotEmpty()
        }
        compose.onNodeWithText(replyText).assertExists()
    }

    /** Swipe-to-delete must arm a confirmation, never delete outright (F2). This
     *  drives the confirm dialog and then Cancels — proving the gate without
     *  destroying the seeded thread the round-trip leg reuses. */
    @Test
    fun swipeDeleteRequiresConfirmation() {
        MemberGate.launch()
        compose.signIn()

        compose.openNavEntry("core-profile")
        compose.awaitTag("member_profile_dashboard")
        compose.scrollListTo("member_profile_dashboard", "member_profile_tile_conversations")
        compose.onNodeWithTag("member_profile_tile_conversations").performClick()

        compose.awaitTag("member_conversation_row")
        // Swipe the first inbox row toward delete.
        compose.onAllNodesWithTag("member_conversation_row").onFirst().performTouchInput { swipeLeft() }

        // The destructive action waits behind a confirmation, matching the
        // thread view — nothing is deleted yet.
        compose.awaitTag("member_conversation_delete_confirm")
        compose.onNodeWithTag("member_conversation_delete_confirm").assertIsDisplayed()
        // Cancel keeps the seeded thread intact for other legs.
        compose.onNodeWithText("Cancel").performClick()
        compose.awaitTag("member_conversations_list")
    }
}
