package com.getjoinery.aichat

import com.getjoinery.android.JsonValue
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** Model parsing over captured API envelopes: the native chat surface must
 *  read the joinery_ai chat action responses exactly as the server emits
 *  them. The fixtures dir holds the same files backing the iOS
 *  JoineryAIChatKit tests — parity by construction. */
class ChatParsingTest {

    private fun fixtureData(name: String): JsonValue {
        val stream = FixtureAnchor::class.java.classLoader!!
            .getResourceAsStream("fixtures/$name.json")
            ?: error("fixture not found: fixtures/$name.json")
        val envelope = JsonValue.parse(stream.readBytes().toString(Charsets.UTF_8))
        return envelope["data"] ?: error("fixture $name has no data")
    }

    @Test
    fun threadParsesConversationAndTurns() {
        val payload = ChatThreadPayload.from(fixtureData("chat_thread"))!!

        assertEquals(29, payload.conversation.id)
        assertEquals("Dovetail guide", payload.conversation.title)
        assertTrue(payload.conversation.pinned)
        assertEquals("accounts/fireworks/models/glm-5p2", payload.conversation.model)
        assertEquals("1,565 tokens used · ~$0.0051", payload.conversation.usageLabel)

        assertEquals(3, payload.messages.size)

        val user = payload.messages[0]
        assertEquals(ChatRole.USER, user.role)
        assertEquals(ChatStatus.COMPLETE, user.status)
        assertNull(user.pendingAction)

        val assistant = payload.messages[1]
        assertEquals(ChatRole.ASSISTANT, assistant.role)
        assertTrue(assistant.content.contains("Dovetail"))
        assertEquals("~$0.0051", assistant.costLabel)
        assertEquals(1, assistant.toolCalls.size)
        assertEquals("web_search", assistant.toolCalls.first().name)
        assertEquals(412, assistant.toolCalls.first().durationMs)
        assertFalse(assistant.toolCalls.first().isError)
    }

    @Test
    fun pendingActionParses() {
        val payload = ChatThreadPayload.from(fixtureData("chat_thread"))!!
        val proposal = payload.messages.last()
        assertEquals(ChatRole.ASSISTANT, proposal.role)
        val pending = proposal.pendingAction
        assertNotNull(pending)
        assertEquals("Create a note titled “Dovetail guide”.", pending!!.description)
    }

    @Test
    fun listParses() {
        val data = fixtureData("chat_list")
        val conversations = (data["conversations"]?.arrayValue ?: emptyList())
            .mapNotNull { ChatConversation.from(it) }
        assertEquals(3, conversations.size)
        assertEquals(29, conversations.first().id)
        assertTrue(conversations.first().pinned)
        assertFalse(conversations[1].pinned)
    }

    @Test
    fun sendResultParsesPollHandle() {
        val result = ChatSendResult.from(fixtureData("chat_send"))!!
        assertEquals(44, result.conversationId)
        assertEquals(86, result.messageId)
        assertTrue(result.isNew)
        assertEquals(ChatStatus.RUNNING, result.status)
        assertNull(result.assistantMessage)          // async path: no finished turn yet
        assertEquals(ChatRole.USER, result.userMessage?.role)
        assertEquals("Reply with exactly: SPAWN OK", result.userMessage?.content)
    }

    @Test
    fun pollCompleteParsesFinishedTurn() {
        val result = ChatPollResult.from(fixtureData("chat_poll_complete"))!!
        assertEquals(ChatStatus.COMPLETE, result.status)
        assertEquals("543 tokens used", result.usageLabel)
        val message = result.message
        assertNotNull(message)
        assertEquals(86, message!!.id)
        assertEquals("SPAWN OK", message.content)
        assertEquals(ChatStatus.COMPLETE, message.status)
    }

    @Test
    fun statusDefaultsToComplete() {
        assertEquals(ChatStatus.COMPLETE, ChatStatus.from(null))
        assertEquals(ChatStatus.COMPLETE, ChatStatus.from("bogus"))
        assertEquals(ChatStatus.RUNNING, ChatStatus.from("running"))
        assertEquals(ChatStatus.FAILED, ChatStatus.from("failed"))
    }

    @Test
    fun messageParsesAttachments() {
        val json = JsonValue.parse(
            """
            {"id": 500, "role": "user", "content": "See attached", "attachments": [
                {"file_id": 12, "name": "statement.pdf", "category": "pdf", "image_url": ""},
                {"file_id": 13, "name": "chart.png", "category": "image",
                 "image_url": "/uploads/chart.png?expires=1&sig=ab"}
            ]}
            """.trimIndent(),
        )
        val message = ChatMessage.from(json)!!
        assertEquals(2, message.attachments.size)
        assertEquals("pdf", message.attachments[0].category)
        assertFalse(message.attachments[0].isImage)          // no image_url → chip
        assertTrue(message.attachments[1].isImage)
        assertEquals("chart.png", message.attachments[1].name)
    }

    @Test
    fun seedFieldsCarryControlValues() {
        val controls = ChatControlValues(
            model = "m1", dataAccess = true, webSearch = false,
            thinkingLevel = "low", temperature = "0.7", topP = "", maxTokens = "",
            instructions = "Be brief.",
        )
        val seed = controls.seedFields()
        assertEquals("1", seed["data_access"])
        assertEquals("0", seed["web_search"])
        assertEquals("low", seed["thinking_level"])
        assertEquals("m1", seed["model"])
        assertEquals("0.7", seed["temperature"])
        assertEquals("Be brief.", seed["instructions"])
        assertFalse(seed.containsKey("top_p"))
        assertFalse(seed.containsKey("max_tokens"))
    }

    // MARK: Turn activity (specs/ai_chat_turn_activity.md)

    @Test
    fun pollResultParsesActivityExtras() {
        val json = JsonValue.parse(
            """{"status": "running", "partial_text": "",
                "activity": "Waiting for glm-5p2…", "running_seconds": 160}""",
        )
        val result = ChatPollResult.from(json)!!
        assertEquals("Waiting for glm-5p2…", result.activity)
        assertEquals(160, result.runningSeconds)
    }

    @Test
    fun pollResultToleratesMissingActivity() {
        // Older servers omit the fields — the running tick still parses.
        val json = JsonValue.parse("""{"status": "running", "partial_text": "Hi"}""")
        val result = ChatPollResult.from(json)!!
        assertEquals("", result.activity)
        assertNull(result.runningSeconds)
    }

    @Test
    fun runningMessageParsesActivityExtras() {
        val json = JsonValue.parse(
            """{"id": 7, "role": "assistant", "status": "running",
                "activity": "Running tool: web_search…", "running_seconds": 12}""",
        )
        val message = ChatMessage.from(json)!!
        assertEquals("Running tool: web_search…", message.activity)
        assertEquals(12, message.runningSeconds)
    }

    @Test
    fun elapsedFormatting() {
        assertEquals("5s", formatElapsed(5))
        assertEquals("2m 40s", formatElapsed(160))
        assertEquals("0s", formatElapsed(-3))
    }

    @Test
    fun inlineMarkdownFormats() {
        assertEquals("bold and code", inlineMarkdown("**bold** and `code`").text)
        assertEquals("a link here", inlineMarkdown("a [link](https://x.test) here").text)
        assertEquals("plain *unclosed", inlineMarkdown("plain *unclosed").text)
        assertEquals("(No reply was generated.)", inlineMarkdown("_(No reply was generated.)_").text)
    }
}

private class FixtureAnchor
